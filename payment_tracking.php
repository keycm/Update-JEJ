<?php
// payment_tracking.php

require_once 'config.php';
require_once __DIR__ . '/config/mail.php';

// Role Access
requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER', 'CASHIER']);

// Manager Permission Access
if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'fin_process');
}


$alert_msg = "";
$alert_type = "";

// --- CHECK DATABASE STRUCTURE ---
$has_res_id_col = false;
$colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'reservation_id'");
if($colCheck && $colCheck->num_rows > 0) {
    $has_res_id_col = true;
}

// --- CHECK OPTIONAL PAYMENT VERIFICATION COLUMNS ---
$has_payment_status_col = false;
$has_proof_image_col = false;
$has_verified_by_col = false;
$has_verified_at_col = false;

$colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'payment_status'");
if($colCheck && $colCheck->num_rows > 0) $has_payment_status_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'proof_image'");
if($colCheck && $colCheck->num_rows > 0) $has_proof_image_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'verified_by'");
if($colCheck && $colCheck->num_rows > 0) $has_verified_by_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'verified_at'");
if($colCheck && $colCheck->num_rows > 0) $has_verified_at_col = true;

// --- HANDLE RECORDING PAYMENT (DP or Amortization) ---
if(isset($_POST['record_payment'])){
    $res_id = (int)$_POST['res_id'];
    $amt = floatval($_POST['amount']);
    $date = $_POST['trans_date'];
    $payment_type = $_POST['payment_type']; // 'DP' or 'AMORTIZATION'
    $remarks = htmlspecialchars($_POST['remarks']);
    $admin_id = $_SESSION['user_id'];
    // New payment: OR number will be auto-generated and locked later after verification.
    
    $or_num = generateORNumber($conn);
    
    // Upload proof of payment, if provided
    $proof_image = null;
    if(isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK){
        $allowed_ext = ['jpg','jpeg','png','pdf'];
        $file_name = $_FILES['proof_image']['name'];
        $file_tmp = $_FILES['proof_image']['tmp_name'];
        $file_size = $_FILES['proof_image']['size'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if(!in_array($ext, $allowed_ext)){
            $alert_msg = "Invalid proof file. Allowed files: JPG, PNG, or PDF only.";
            $alert_type = "error";
        } elseif($file_size > 5 * 1024 * 1024){
            $alert_msg = "Proof file is too large. Maximum size is 5MB.";
            $alert_type = "error";
        } else {
            if(!is_dir('uploads/payment_proofs')){
                mkdir('uploads/payment_proofs', 0777, true);
            }
            $new_file_name = 'proof_' . $res_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destination = 'uploads/payment_proofs/' . $new_file_name;
            if(move_uploaded_file($file_tmp, $destination)){
                $proof_image = $destination;
            }
        }
    }
    
    // THIS TEXT IS CRUCIAL: It must match what statement_of_account/payment tracking is looking for
    if ($payment_type == 'RESERVATION_FEE') {
        $type_text = 'Reservation Fee';
    } elseif ($payment_type == 'DP') {
        $type_text = 'Down Payment';
    } elseif ($payment_type == 'CASH_BALANCE') {
        $type_text = 'Spot Cash Balance';
    } else {
        $type_text = 'Monthly Amortization';
    }

    // ONE-ROW PAYMENT RULE:
    // Do not split one full/advance payment into many monthly transaction rows.
    // One actual receipt/deposit = one transaction = one Verify action.
    // SOA will allocate the verified total to the oldest unpaid/partial months for display.
    if ($payment_type == 'RESERVATION_FEE') {
        $desc = "Reservation Fee for Res#$res_id" . (!empty($remarks) ? " - $remarks" : "");
    } elseif ($payment_type == 'AMORTIZATION' && $amt > 0) {
        $desc = "Monthly Amortization for Res#$res_id";
        if (!empty($remarks)) {
            $desc .= " - " . $remarks;
        } else {
            $desc .= " - payment/advance allocation";
        }
    } else {
        $desc = "$type_text for Res#$res_id" . (!empty($remarks) ? " - $remarks" : "");
    }

    if(empty($alert_msg) && $amt > 0){
        try {
            $check_stmt = $conn->prepare("SELECT id FROM transactions WHERE or_number = ?");
            $check_stmt->bind_param("s", $or_num);
            $check_stmt->execute();
            
            if($check_stmt->get_result()->num_rows > 0) {
                $alert_msg = "Error: The OR / Reference Number '<b>$or_num</b>' is already in use.";
                $alert_type = "error";
            } else {
                // Default status for new payments
                $payment_status = 'PENDING';

if ($has_res_id_col && $has_payment_status_col && $has_proof_image_col) {

    $stmt = $conn->prepare("
        INSERT INTO transactions
        (
            reservation_id,
            type,
            amount,
            transaction_date,
            description,
            or_number,
            user_id,
            payment_status,
            proof_image
        )
        VALUES
        (
            ?, 'INCOME', ?, ?, ?, ?, ?, ?, ?
        )
    ");

    if(!$stmt){
        die("Insert Prepare Error: " . $conn->error);
    }

    $stmt->bind_param(
        "idsssiss",
        $res_id,
        $amt,
        $date,
        $desc,
        $or_num,
        $admin_id,
        $payment_status,
        $proof_image
    );

}
elseif ($has_res_id_col && $has_payment_status_col) {

    $stmt = $conn->prepare("
        INSERT INTO transactions
        (
            reservation_id,
            type,
            amount,
            transaction_date,
            description,
            or_number,
            user_id,
            payment_status
        )
        VALUES
        (
            ?, 'INCOME', ?, ?, ?, ?, ?, ?
        )
    ");

    if(!$stmt){
        die("Insert Prepare Error: " . $conn->error);
    }

    $stmt->bind_param(
        "idsssis",
        $res_id,
        $amt,
        $date,
        $desc,
        $or_num,
        $admin_id,
        $payment_status
    );

}
                
                if($stmt->execute()) {
                    $alert_msg = $has_payment_status_col
                        ? "Payment of ₱" . number_format($amt, 2) . " recorded as <b>PENDING VERIFICATION</b>. It will be counted only after cashier/admin verification."
                        : "Payment of ₱" . number_format($amt, 2) . " successfully recorded!";
                    $alert_type = "success";

                    // --- NEW: NOTIFY BUYER OF PAYMENT UPDATE ---
                    $r_user = $conn->query("SELECT user_id FROM reservations WHERE id = $res_id")->fetch_assoc();
                    if ($r_user && isset($r_user['user_id'])) {
                        $b_uid = $r_user['user_id'];
                        $notif_title = "Payment Received & Tracked";
                        $notif_msg = "Your $type_text of ₱" . number_format($amt, 2) . " was recorded and is now pending office verification.";
                        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
                        $notif_stmt->bind_param("iss", $b_uid, $notif_title, $notif_msg);
                        $notif_stmt->execute();
                    }
                    // -------------------------------------------
                } else {
                    $alert_msg = "Failed to record payment. Please try again.";
                    $alert_type = "error";
                }
            }
        } catch (Exception $e) {
            $alert_msg = "Database Error: " . $e->getMessage();
            $alert_type = "error";
        }
    } else {
        $alert_msg = "Amount must be greater than zero.";
        $alert_type = "error";
    }
}

// --- HANDLE SEND REMINDER EMAIL & NOTIFICATION ---
if(isset($_POST['send_reminder'])){
    $res_id = (int)$_POST['res_id'];
    $amount_due = floatval($_POST['amount_due']);
    $due_date = trim($_POST['due_date'] ?? '');
    $reminder_type = trim($_POST['reminder_type'] ?? 'Payment');

    // Prevent accidental repeated reminder spam for the same reservation.
    $reminder_session_key = 'last_reminder_' . $res_id;
    if(
        isset($_SESSION[$reminder_session_key]) &&
        (time() - (int)$_SESSION[$reminder_session_key]) < 300
    ){
        $alert_msg = "Please wait 5 minutes before sending another reminder to this buyer.";
        $alert_type = "error";
    } elseif(
        !defined('SMTP_USER') || !defined('SMTP_PASS') ||
        trim((string)SMTP_USER) === '' || trim((string)SMTP_PASS) === ''
    ){
        // Keep SMTP details hidden from the page.
        $alert_msg = "Email reminder cannot be sent because SMTP is not configured.";
        $alert_type = "error";
    } else {
        $stmtRes = $conn->prepare("\n            SELECT r.*, u.email, u.fullname, l.block_no, l.lot_no\n            FROM reservations r\n            JOIN users u ON r.user_id = u.id\n            JOIN lots l ON r.lot_id = l.id\n            WHERE r.id = ?\n            LIMIT 1\n        ");

        if(!$stmtRes){
            $alert_msg = "Unable to prepare reminder request.";
            $alert_type = "error";
        } else {
            $stmtRes->bind_param("i", $res_id);
            $stmtRes->execute();
            $resData = $stmtRes->get_result()->fetch_assoc();

            if(!$resData || $amount_due <= 0){
                $alert_msg = "Reminder not sent. Please check the buyer and amount due.";
                $alert_type = "error";
            } else {
                $formatted_amount = number_format($amount_due, 2);
                $formatted_date = $due_date !== '' ? date('F j, Y', strtotime($due_date)) : 'the scheduled due date';

                require_once __DIR__ . '/PHPMailer/Exception.php';
                require_once __DIR__ . '/PHPMailer/PHPMailer.php';
                require_once __DIR__ . '/PHPMailer/SMTP.php';

                $mail = new PHPMailer\PHPMailer\PHPMailer(true);

                try {
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USER;
                    $mail->Password   = SMTP_PASS;
                    $mail->SMTPSecure = SMTP_SECURE;
                    $mail->Port       = SMTP_PORT;
                    $mail->CharSet    = 'UTF-8';
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        ]
                    ];

                    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                    $mail->addAddress($resData['email'], $resData['fullname']);
                    $mail->isHTML(true);
                    $mail->Subject = "Payment Due Reminder | JEJ Top Priority Corporation";

                    $buyerName = htmlspecialchars($resData['fullname'], ENT_QUOTES, 'UTF-8');
                    $blockNo = htmlspecialchars($resData['block_no'], ENT_QUOTES, 'UTF-8');
                    $lotNo = htmlspecialchars($resData['lot_no'], ENT_QUOTES, 'UTF-8');
                    $safeReminderType = htmlspecialchars($reminder_type, ENT_QUOTES, 'UTF-8');

                    $mail->Body = "
                    <div style='font-family: Arial, sans-serif; color: #334155; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
                        <div style='background-color: #e11d48; padding: 20px; text-align: center; color: white;'>
                            <h2 style='margin: 0; font-size: 20px; letter-spacing: 0.5px;'>Payment Reminder</h2>
                        </div>
                        <div style='padding: 30px; background-color: #ffffff;'>
                            <p style='font-size: 15px;'>Hello <b>{$buyerName}</b>,</p>
                            <p style='font-size: 15px; line-height: 1.5;'>This is an official payment reminder for your property at <b>Block {$blockNo} Lot {$lotNo}</b>.</p>
                            <p style='font-size: 15px; line-height: 1.5;'>Please settle your <b>{$safeReminderType}</b> based on the schedule below:</p>

                            <div style='background-color: #fff1f2; border-left: 4px solid #e11d48; padding: 20px; margin: 25px 0; border-radius: 0 8px 8px 0;'>
                                <h3 style='margin: 0 0 15px 0; color: #be123c; font-size: 16px;'>Payment Details</h3>
                                <table style='width: 100%; font-size: 14px;'>
                                    <tr>
                                        <td style='padding-bottom: 8px; color: #475569;'>Amount Due:</td>
                                        <td style='padding-bottom: 8px; font-weight: bold; color: #0f172a; font-size: 16px;'>₱{$formatted_amount}</td>
                                    </tr>
                                    <tr>
                                        <td style='color: #475569;'>Due Date:</td>
                                        <td style='font-weight: bold; color: #e11d48;'>{$formatted_date}</td>
                                    </tr>
                                </table>
                            </div>

                            <p style='font-size: 14px; color: #64748b; line-height: 1.5;'>Failure to settle this amount by the deadline may result in penalties or forfeiture.</p>
                            <p style='font-size: 14px; color: #64748b; line-height: 1.5;'>You may log in to your Buyer Portal to view your updated Statement of Account and payment history.</p>
                            <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 25px 0;'>
                            <p style='font-size: 14px; margin: 0;'>Thank you,<br><strong style='color: #2e7d32;'>JEJ Top Priority Corporation Team</strong></p>
                        </div>
                    </div>";

                    $mail->send();
                    $_SESSION[$reminder_session_key] = time();

                    $alert_msg = "Reminder email sent and buyer notified successfully!";
                    $alert_type = "success";

                    // In-app notification for buyer.
                    $b_uid = (int)$resData['user_id'];
                    $notif_title = "Action Required: Payment Reminder";
                    $notif_msg = "Reminder: Your $reminder_type payment amounting to ₱$formatted_amount is due on $formatted_date. Please settle your account to avoid penalties and maintain your account in good standing.";
                    $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
                    if($notif_stmt){
                        $notif_stmt->bind_param("iss", $b_uid, $notif_title, $notif_msg);
                        $notif_stmt->execute();
                    }
                } catch (Exception $e) {
                    // Do not expose SMTP host, username, password, or PHPMailer debug details in the UI.
                    $alert_msg = "Failed to send reminder email. Please check SMTP settings or internet connection.";
                    $alert_type = "error";
                }
            }
        }
    }
}

// --- FETCH & PROCESS APPROVED RESERVATIONS ---

if ($_SESSION['role'] === 'BUYER') {

    $stmt = $conn->prepare("
        SELECT 
            r.*, 
            u.fullname, 
            u.email as user_email, 
            l.block_no, 
            l.lot_no, 
            l.total_price,
            l.area,
            l.price_per_sqm,
            l.location,
            l.classification
        FROM reservations r
        JOIN users u ON r.user_id = u.id
        JOIN lots l ON r.lot_id = l.id
        WHERE r.status = 'APPROVED'
        AND r.user_id = ?
        ORDER BY r.reservation_date DESC
    ");

    $stmt->bind_param(
        "i",
        $_SESSION['user_id']
    );

    $stmt->execute();

    $res = $stmt->get_result();

} else {

    $query = "
        SELECT 
            r.*, 
            u.fullname, 
            u.email as user_email, 
            l.block_no, 
            l.lot_no, 
            l.total_price,
            l.area,
            l.price_per_sqm,
            l.location,
            l.classification
        FROM reservations r
        JOIN users u ON r.user_id = u.id
        JOIN lots l ON r.lot_id = l.id
        WHERE r.status = 'APPROVED'
        ORDER BY r.reservation_date DESC
    ";

    $res = $conn->query($query);
}

$approved_reservations = [];
$overdue_count = 0;
$due_soon_count = 0;

if($res && $res->num_rows > 0) {
    while($row = $res->fetch_assoc()){
        
        // =========================================================
        // UPDATED PRICING MATRIX PAYMENT COMPUTATION
        // Spot Cash       = cash_front/cash_inner × area, no DP.
        // Installment     = installment_front/installment_inner × area, 20% DP.
        // Straight Payment = cash price + straight_additional_per_sqm, 20% DP.
        // Reservation fee remains a separate payment record.
        // =========================================================
        $payment_option_for_calc = $row['payment_scheme'] ?? $row['payment_option'] ?? $row['payment_terms'] ?? $row['payment_type'] ?? 'SPOT_CASH';
        $term_months_for_calc = isset($row['installment_months']) && (int)$row['installment_months'] > 0 ? (int)$row['installment_months'] : 36;
        $reservation_fee_default = 5000.00;
        $reservation_fee_amount = (isset($row['reservation_fee']) && (float)$row['reservation_fee'] > 0)
            ? (float)$row['reservation_fee']
            : $reservation_fee_default;

        $priceCalc = jej_compute_payment_pricing($conn, $row, $payment_option_for_calc, $term_months_for_calc, $reservation_fee_amount);

        $is_spot_cash = ((int)$priceCalc['is_spot_cash'] === 1);
        $is_installment = !$is_spot_cash;
        $discount_percent = 0.00;
        $discount_amount = 0.00;
        $tcp_after_discount = (float)$priceCalc['final_tcp'];
        $additional_amount = (float)$priceCalc['additional_amount'];
        $additional_per_sqm = (float)$priceCalc['additional_per_sqm'];
        $payment_option_label = $priceCalc['payment_label'];

        // Reservation fee is paid only when a Reservation Fee transaction is VERIFIED.
        $desc_like_reservation_fee = "%Reservation Fee%Res#{$row['id']}%";
        $reservation_fee_query = $conn->prepare("
            SELECT SUM(amount) as total_paid
            FROM transactions
            WHERE type='INCOME'
            AND (payment_status = 'VERIFIED' OR payment_status IS NULL)
            AND description LIKE ?
        ");
        if (!$reservation_fee_query) {
            die("Reservation Fee Query Error: " . $conn->error);
        }
        $reservation_fee_query->bind_param("s", $desc_like_reservation_fee);
        $reservation_fee_query->execute();
        $reservation_fee_paid = (float)($reservation_fee_query->get_result()->fetch_assoc()['total_paid'] ?? 0);
        $reservation_fee_remaining = max($reservation_fee_amount - $reservation_fee_paid, 0);

        $net_tcp_after_reservation_fee = $tcp_after_discount;
        $dp_total_required = $is_spot_cash ? 0.00 : (float)$priceCalc['required_dp'];

        $row['payment_type_clean'] = $is_spot_cash ? 'SPOT_CASH' : 'INSTALLMENT';
        $row['payment_code'] = $priceCalc['payment_code'];
        $row['discount_percent'] = $discount_percent;
        $row['discount_amount'] = $discount_amount;
        $row['additional_amount'] = $additional_amount;
        $row['additional_per_sqm'] = $additional_per_sqm;
        $row['payment_option_label'] = $payment_option_label;
        $row['tcp_after_discount'] = $tcp_after_discount;
        $row['reservation_fee_paid'] = $reservation_fee_paid;
        $row['reservation_fee_remaining'] = $reservation_fee_remaining;
        $row['reservation_fee_amount'] = $reservation_fee_amount;
        $row['net_tcp_after_reservation_fee'] = $net_tcp_after_reservation_fee;
        
        // Use safer wildcards to tally the tracking screen amounts
       $desc_like_dp = "%Down Payment%Res#{$row['id']}%";

$dp_query = $conn->prepare("
    SELECT SUM(amount) as total_paid 
    FROM transactions 
    WHERE type='INCOME' 
    AND (payment_status = 'VERIFIED' OR payment_status IS NULL)
    AND description LIKE ?
");

if (!$dp_query) {
    die("DP Query Error: " . $conn->error);
}

$dp_query->bind_param("s", $desc_like_dp);
$dp_query->execute();
$dp_paid_amount = $dp_query->get_result()->fetch_assoc()['total_paid'] ?? 0;
$desc_like_amort = "%Amortization%Res#{$row['id']}%";

$amort_query = $conn->prepare("
    SELECT SUM(amount) as total_paid 
    FROM transactions 
    WHERE type='INCOME' 
    AND (payment_status = 'VERIFIED' OR payment_status IS NULL)
    AND description LIKE ?
");

if (!$amort_query) {
    die("Amortization Query Error: " . $conn->error);
}

$amort_query->bind_param("s", $desc_like_amort);
$amort_query->execute();
$amort_paid_amount = $amort_query->get_result()->fetch_assoc()['total_paid'] ?? 0;

        // Spot Cash Balance payments are saved with description: Spot Cash Balance for Res#ID.
        // This prevents undefined variable warnings and keeps spot-cash payments separate from DP/amortization.
        $cash_balance_paid_amount = 0;
        if($is_spot_cash){
            $desc_like_cash = "%Spot Cash Balance%Res#{$row['id']}%";

            $cash_query = $conn->prepare("
                SELECT SUM(amount) as total_paid
                FROM transactions
                WHERE type='INCOME'
                AND (payment_status = 'VERIFIED' OR payment_status IS NULL)
                AND description LIKE ?
            ");

            if (!$cash_query) {
                die("Spot Cash Query Error: " . $conn->error);
            }

            $cash_query->bind_param("s", $desc_like_cash);
            $cash_query->execute();
            $cash_balance_paid_amount = $cash_query->get_result()->fetch_assoc()['total_paid'] ?? 0;
        }
        
        // Pending payments are displayed separately and are not yet included in the paid totals.
        $pending_like = "%Res#{$row['id']}%";
        if($has_payment_status_col){
            $pending_query = $conn->prepare("
                SELECT SUM(amount) as pending_total
                FROM transactions
                WHERE type='INCOME'
                AND payment_status='PENDING'
                AND description LIKE ?
            ");

            if (!$pending_query) {
                die("Pending Query Error: " . $conn->error);
            }

            $pending_query->bind_param("s", $pending_like);
            $pending_query->execute();
            $row['pending_payment_amount'] = $pending_query->get_result()->fetch_assoc()['pending_total'] ?? 0;
        } else {
            $row['pending_payment_amount'] = 0;
        }
        
        $dp_remaining = $is_spot_cash ? 0 : ($dp_total_required - $dp_paid_amount);
        
        $row['dp_total_required'] = $dp_total_required;
        $row['dp_paid_amount'] = $dp_paid_amount;
        $row['amort_paid_amount'] = $amort_paid_amount;
        $row['cash_balance_paid_amount'] = $cash_balance_paid_amount;
        $row['dp_remaining'] = $dp_remaining > 0 ? $dp_remaining : 0;
        $row['is_dp_fully_paid'] = ($dp_remaining <= 0);

        // Overall land payment status. Reservation Fee is separate and does not reduce TCP balance here.
        $row['balance_to_amortize'] = $is_spot_cash ? $tcp_after_discount : max($tcp_after_discount - (float)$dp_total_required, 0);
        $row['total_paid_amount'] = $is_spot_cash
            ? (float)$cash_balance_paid_amount
            : ((float)$dp_paid_amount + (float)$amort_paid_amount);
        $row['overall_outstanding_balance'] = max($tcp_after_discount - $row['total_paid_amount'], 0);
        $row['is_account_fully_paid'] = ($row['overall_outstanding_balance'] <= 0.01 && (float)$row['total_price'] > 0);
        $row['is_reservation_fee_fully_paid'] = ($reservation_fee_remaining <= 0.01);
        $row['is_amortization_fully_paid'] = ((float)$amort_paid_amount >= $row['balance_to_amortize'] && $row['balance_to_amortize'] > 0);

        // Get installment months
$term_months = isset($row['installment_months']) && (int)$row['installment_months'] > 0
    ? (int)$row['installment_months']
    : 36;

// Compute balance using Final Contract Price; reservation fee is separate
$balance_to_amortize = $row['balance_to_amortize'];

// Use saved monthly payment if available
if(isset($row['monthly_payment']) && (float)$row['monthly_payment'] > 0){
    $row['monthly_payment'] = (float)$row['monthly_payment'];
} else {
    $row['monthly_payment'] = $balance_to_amortize / $term_months;
}

        // Deadlines (DP)
        if (!empty($row['reservation_date'])) {
            $res_time = strtotime($row['reservation_date']);
            $deadline_timestamp = strtotime('+20 days', $res_time);
            $row['dp_deadline_date'] = date('Y-m-d', $deadline_timestamp);
            $row['deadline_formatted'] = date('M d, Y g:i A', $deadline_timestamp);
            
            if (!$row['is_dp_fully_paid']) {
                $days_left = ($deadline_timestamp - time()) / (60 * 60 * 24);
                $row['is_overdue'] = ($days_left < 0);
                $row['is_due_soon'] = ($days_left >= 0 && $days_left <= 3);
                if($row['is_overdue']) $overdue_count++;
                if($row['is_due_soon']) $due_soon_count++;
            } else {
                $row['is_overdue'] = false; $row['is_due_soon'] = false;
            }
        } else {
            $row['dp_deadline_date'] = date('Y-m-d');
            $row['deadline_formatted'] = "Date Error";
            $row['is_overdue'] = false; $row['is_due_soon'] = false;
        }

        $approved_reservations[] = $row;
    }
}

$agent_options = [];
foreach ($approved_reservations as $ar) {
    if (!empty($ar['agent_name'])) {
        $agent_options[$ar['agent_name']] = $ar['agent_name'];
    }
}
ksort($agent_options);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Tracking | JEJ Financials</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #2e7d32; --primary-light: #e8f5e9; --dark: #1b5e20; 
            --gray-light: #f1f8e9; --gray-border: #c8e6c9; --text-muted: #607d8b; --shadow-lg: 0 10px 25px rgba(46,125,50,.16); 
        }
        body { background-color: #fafcf9; display: flex; min-height: 100vh; overflow-x: hidden; font-family: 'Inter', sans-serif; color: #37474f; margin: 0; }
        .sidebar { width: 260px; background: #ffffff; border-right: 1px solid #c8e6c9; display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08); }
        .brand-box { padding: 25px; border-bottom: 1px solid #c8e6c9; display: flex; align-items: center; gap: 12px; }
        .sidebar-menu { padding: 20px 15px; flex: 1; overflow-y: auto; }
        .menu-link { display: flex; align-items: center; gap: 12px; padding: 12px 18px; color: #455a64; text-decoration: none; font-weight: 500; font-size: 14px; border-radius: 10px; margin-bottom: 6px; transition: all 0.2s ease; }
        .menu-link:hover { background: #f1f8e9; color: #2e7d32; }
        .menu-link.active { background: #e8f5e9; color: #2e7d32; font-weight: 600; border-left: 4px solid #2e7d32; }
        .menu-link i { width: 20px; text-align: center; font-size: 16px; opacity: 0.8; }
        
        .main-panel { margin-left: 260px; flex: 1; padding: 0; width: calc(100% - 260px); display: flex; flex-direction: column; }
        .top-header { display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 20px 40px; border-bottom: 1px solid #c8e6c9; box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08); z-index: 50; }
        .header-title h1 { font-size: 22px; font-weight: 800; color: #1b5e20; margin: 0 0 4px 0;}
        .header-title p { color: #607d8b; font-size: 13px; margin: 0; }
        
        .content-area { padding: 35px 40px; flex: 1; }
        .alert-banner { padding: 16px 20px; border-radius: 10px; margin-bottom: 25px; font-weight: 500; font-size: 14px; box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08); display: flex; align-items: center; gap: 12px;}
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .urgent-banner { background: #fef2f2; border-left: 5px solid #ef4444; padding: 20px; border-radius: 8px; margin-bottom: 30px; display: flex; align-items: flex-start; gap: 15px; }

        .table-container { background: white; border-radius: 16px; border: 1px solid #c8e6c9; box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08); overflow: hidden; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 16px 20px; font-size: 12px; font-weight: 600; color: #607d8b; text-transform: uppercase; background: #f1f8e9; border-bottom: 1px solid #c8e6c9;}
        td { padding: 16px 20px; border-bottom: 1px solid #c8e6c9; color: #37474f; font-size: 13px; vertical-align: top; }
        
        .badge { padding: 5px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; display: inline-block;}
        .badge-full { background: #d1fae5; color: #059669; border: 1px solid #a7f3d0; } 
        .badge-partial { background: #dbeafe; color: #2563eb; border: 1px solid #bfdbfe; }
        .badge-none { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; } 

        .btn-action { padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; transition: 0.2s; border: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; width: 100%; box-sizing: border-box;}
        .btn-record { background: #10b981; color: white; } .btn-record:hover { background: #059669; }
        .btn-remind { background: #ef4444; color: white; } .btn-remind:hover { background: #dc2626; }
        .btn-billing { background: #3b82f6; color: white; } .btn-billing:hover { background: #2563eb; }
        .btn-edit-terms { background: #f59e0b; color: white; } .btn-edit-terms:hover { background: #d97706; }

        .modal { display: none; position: fixed; z-index: 9999; inset: 0; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(3px); padding: 30px; overflow-y: auto; align-items: center; justify-content: center;}
        .modal-content { width: 100%; max-width: 450px; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .modal-header { padding: 20px 25px; border-bottom: 1px solid #c8e6c9; display: flex; justify-content: space-between; align-items: center; background: #f1f8e9; }
        .close-btn { background: none; border: none; font-size: 20px; color: #90a4ae; cursor: pointer; }
        .close-btn:hover { color: #ef4444; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #455a64; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 14px; outline: none; box-sizing: border-box;}

        .menu-dropdown{
    margin-bottom:6px;
}

.dropdown-toggle{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    cursor:pointer;
}

.finance-main-link{
    display:flex;
    align-items:center;
    gap:12px;
    flex:1;
    color:inherit;
    text-decoration:none;
}

.submenu-toggle-btn{
    border:none;
    background:none;
    cursor:pointer;
    width:28px;
    height:28px;
    border-radius:6px;
    color:#2e7d32;
}

.submenu-toggle-btn:hover{
    background:#dff2e1;
}

.submenu{
    display:none !important;
    padding-left:18px;
    margin-top:6px;
}

.submenu.show{
    display:block !important;
}

.submenu-link{
    font-size:13px;
    margin-bottom:6px;
}

.submenu-link.active{
    background:#e8f5e9;
    color:#2e7d32;
    font-weight:700;
    border-left:4px solid #2e7d32;
}
        
    

        /* LANDSCAPE RECORD PAYMENT MODAL */
        .payment-modal-content{
            max-width: 820px !important;
            width: 92vw !important;
            max-height: 88vh;
            overflow: hidden;
        }

        .payment-modal-body{
            padding: 22px 25px;
            max-height: calc(88vh - 78px);
            overflow-y: auto;
        }

        .payment-form-grid{
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 18px;
            align-items: start;
        }

        .payment-form-grid .full-row{
            grid-column: 1 / -1;
        }

        .payment-note{
            background:#fffbeb;
            border:1px solid #fde68a;
            color:#92400e;
            padding:12px;
            border-radius:8px;
            font-size:12px;
            line-height:1.5;
            height:100%;
        }

        .payment-modal-actions{
            grid-column:1 / -1;
            display:flex;
            justify-content:flex-end;
            gap:10px;
            margin-top: 8px;
            padding-top: 16px;
            border-top: 1px solid #c8e6c9;
        }

        .btn-cancel-modal{
            background:#f1f5f9;
            border:1px solid #cbd5e1;
            padding:10px 20px;
            border-radius:8px;
            cursor:pointer;
        }

        .btn-save-modal{
            background:#10b981;
            color:white;
            border:none;
            padding:10px 24px;
            border-radius:8px;
            font-weight:600;
            cursor:pointer;
        }

        @media(max-width: 768px){
            .payment-modal-content{
                max-width: 96vw !important;
            }
            .payment-form-grid{
                grid-template-columns: 1fr;
            }
        }


        /* ANALYTICS CARDS */
        .analytics-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
            gap:18px;
            margin-bottom:30px;
        }

        .analytics-card{
            background:#ffffff;
            border:1px solid #c8e6c9;
            border-radius:16px;
            padding:18px;
            display:flex;
            align-items:center;
            gap:14px;
            box-shadow:0 2px 8px rgba(46,125,50,0.08);
        }

        .analytics-card small{
            display:block;
            color:#607d8b;
            font-size:12px;
            margin-bottom:5px;
            font-weight:700;
            text-transform:uppercase;
        }

        .analytics-card h3{
            margin:0;
            font-size:20px;
            color:#1b5e20;
            font-weight:800;
        }

        .analytics-icon{
            width:50px;
            height:50px;
            min-width:50px;
            border-radius:14px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:20px;
            color:white;
        }


        .analytics-icon.sales{
    background:linear-gradient(135deg,#10b981,#059669);
}

.analytics-icon.collected{
    background:linear-gradient(135deg,#3b82f6,#2563eb);
}

.analytics-icon.pending{
    background:linear-gradient(135deg,#f59e0b,#d97706);
}

.analytics-icon.balance{
    background:linear-gradient(135deg,#ef4444,#dc2626);
}

.analytics-icon.sold{
    background:linear-gradient(135deg,#8b5cf6,#7c3aed);
}

.analytics-icon.available{
    background:linear-gradient(135deg,#14b8a6,#0f766e);
}

.mini-summary-grid{
    display:grid;
    grid-template-columns:repeat(3, minmax(180px, 1fr));
    gap:16px;
    margin-bottom:24px;
}

.mini-card{
    background:white;
    border:1px solid #c8e6c9;
    border-radius:14px;
    padding:16px 18px;
    display:flex;
    align-items:center;
    gap:14px;
    box-shadow:0 2px 8px rgba(46,125,50,.08);
}

.mini-card i{
    width:42px;
    height:42px;
    border-radius:12px;
    background:#e8f5e9;
    color:#2e7d32;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
}

.mini-card small{
    display:block;
    font-size:12px;
    color:#607d8b;
    font-weight:700;
    text-transform:uppercase;
}

.mini-card strong{
    font-size:22px;
    color:#1b5e20;
}

.mini-card.danger i{
    background:#fee2e2;
    color:#dc2626;
}

.mini-card.warning i{
    background:#fef3c7;
    color:#d97706;
}

/* SEARCH FILTERS */
.tracking-filters{
    display:grid;
    grid-template-columns:2fr 1fr 1fr 1fr auto;
    gap:12px;
    padding:18px;
    border-bottom:1px solid #c8e6c9;
    background:#ffffff;
}

.filter-group{
    position:relative;
}

.search-group i{
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:#90a4ae;
    font-size:13px;
}

.filter-group input,
.filter-group select{
    width:100%;
    height:44px;
    border:1px solid #d7e7d4;
    border-radius:10px;
    padding:0 14px;
    font-size:13px;
    background:#fff;
    outline:none;
    transition:.2s ease;
    box-sizing:border-box;
}

.search-group input{
    padding-left:38px;
}

.filter-group input:focus,
.filter-group select:focus{
    border-color:#2e7d32;
    box-shadow:0 0 0 3px rgba(46,125,50,.12);
}

.filter-reset-btn{
    height:44px;
    border:1px solid #c8e6c9;
    background:#f1f8e9;
    color:#2e7d32;
    border-radius:10px;
    padding:0 16px;
    font-weight:700;
    cursor:pointer;
}

.filter-reset-btn:hover{
    background:#e8f5e9;
}

.no-filter-result{
    text-align:center;
    padding:30px;
    color:#94a3b8;
    font-weight:600;
    display:none;
}

@media(max-width:991px){
    .tracking-filters{
        grid-template-columns:1fr;
    }
}
    

/* AUTO FIT + COLLAPSIBLE SIDEBAR */
.sidebar,
.main-panel{
    transition: all .25s ease;
}

.top-header-left{
    display:flex;
    align-items:center;
    gap:12px;
    min-width:0;
}

.sidebar-toggle{
    width:42px;
    height:42px;
    border:none;
    border-radius:10px;
    background:#e8f5e9;
    color:#2e7d32;
    font-size:18px;
    cursor:pointer;
    flex-shrink:0;
}

.sidebar-toggle:hover{ background:#c8e6c9; }

.sidebar .menu-text{ display:inline; }

body.sidebar-collapsed .sidebar{ width:78px; }
body.sidebar-collapsed .main-panel{
    margin-left:78px;
    width:calc(100% - 78px);
}
body.sidebar-collapsed .brand-box{
    justify-content:center;
    padding:25px 10px;
}
body.sidebar-collapsed .brand-box div,
body.sidebar-collapsed .sidebar-menu small,
body.sidebar-collapsed .menu-text,
body.sidebar-collapsed .finance-main-link span,
body.sidebar-collapsed .submenu,
body.sidebar-collapsed .submenu-toggle-btn{
    display:none !important;
}
body.sidebar-collapsed .menu-link{
    justify-content:center;
    padding:14px 10px;
    gap:0;
    border-left:0 !important;
}
body.sidebar-collapsed .menu-link i{
    width:22px;
    font-size:18px;
}
body.sidebar-collapsed .finance-main-link{
    justify-content:center;
    gap:0;
}

/* PAYMENT TRACKING AUTO FIT */
.content-area{
    max-width:100%;
    box-sizing:border-box;
}
.table-container{
    width:100%;
    overflow-x:auto;
}
.table-container table{
    width:100%;
    min-width:0;
    table-layout:fixed;
}
.table-container th,
.table-container td{
    padding:14px 16px;
    word-break:break-word;
    overflow-wrap:anywhere;
}
.table-container td:nth-child(3){
    font-size:12px;
}
.table-container td:nth-child(5) .btn-action{
    white-space:normal;
    line-height:1.25;
}
.mini-summary-grid{
    grid-template-columns:repeat(auto-fit,minmax(180px,1fr)) !important;
}

@media(max-width:1200px){
    .content-area{ padding:24px; }
    .table-container table{ min-width:0; }
}

@media(max-width:768px){
    .sidebar{ left:-260px; }
    .main-panel,
    body.sidebar-collapsed .main-panel{
        margin-left:0;
        width:100%;
    }
    body.sidebar-open .sidebar{
        left:0;
        z-index:101;
    }
    body.sidebar-open::after{
        content:"";
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.35);
        z-index:90;
    }
    .top-header{
        padding:15px 18px;
        gap:12px;
    }
    .header-title h1{ font-size:18px; }
    .header-title p{ display:none; }
    .content-area{ padding:18px; }
    .tracking-filters{ grid-template-columns:1fr !important; }

    .table-container{
        background:transparent;
        border:0;
        box-shadow:none;
        overflow:visible;
    }
    .table-container table,
    .table-container thead,
    .table-container tbody,
    .table-container th,
    .table-container td,
    .table-container tr{
        display:block;
        width:100%;
        min-width:0;
        box-sizing:border-box;
    }
    .table-container thead{ display:none; }
    .table-container tr.buyer-row{
        background:#fff;
        border:1px solid #c8e6c9;
        border-radius:16px;
        margin-bottom:16px;
        overflow:hidden;
        box-shadow:0 1px 2px rgba(46,125,50,.08);
    }
    .table-container tr.buyer-row td{
        border-bottom:1px solid #e2e8f0;
        padding:14px 16px;
    }
    .table-container tr.buyer-row td:last-child{ border-bottom:0; }
    .table-container tr.buyer-row td::before{
        content:attr(data-label);
        display:block;
        font-size:11px;
        font-weight:800;
        color:#607d8b;
        text-transform:uppercase;
        margin-bottom:7px;
        letter-spacing:.4px;
    }
    #noFilterResult{ display:none; }
}


/* FINAL SIDEBAR + TABLE FIX */
html, body{
    max-width:100%;
    overflow-x:hidden;
}
.sidebar{
    left:0;
    transition:width .25s ease,left .25s ease;
}
.main-panel{
    min-width:0;
    transition:margin-left .25s ease,width .25s ease;
}
.top-header{
    flex-wrap:wrap;
}
.header-title{
    min-width:0;
}
.header-title h1,
.header-title p{
    overflow:hidden;
    text-overflow:ellipsis;
}
.table-container{
    max-width:100%;
}
.table-container table{
    max-width:100%;
}
.table-container th:nth-child(1){width:20% !important;}
.table-container th:nth-child(2){width:15% !important;}
.table-container th:nth-child(3){width:30% !important;}
.table-container th:nth-child(4){width:15% !important;}
.table-container th:nth-child(5){width:20% !important;}
body.sidebar-collapsed .dropdown-toggle{
    padding:14px 10px;
}
body.sidebar-collapsed .dropdown-toggle .finance-main-link{
    justify-content:center;
}
body.sidebar-collapsed .submenu.show{
    display:none !important;
}
@media(max-width:768px){
    body.sidebar-open{ overflow:hidden; }
    .sidebar{ width:260px; }
    body.sidebar-collapsed .sidebar{ width:260px; }
    body.sidebar-collapsed .brand-box div,
    body.sidebar-collapsed .sidebar-menu small,
    body.sidebar-collapsed .menu-text,
    body.sidebar-collapsed .finance-main-link span{
        display:inline !important;
    }
    body.sidebar-collapsed .submenu-toggle-btn{
        display:flex !important;
    }
    body.sidebar-collapsed .menu-link{
        justify-content:flex-start;
        padding:12px 18px;
        gap:12px;
    }
    body.sidebar-collapsed .finance-main-link{
        justify-content:flex-start;
        gap:12px;
    }
    .top-header-left{ width:100%; }
    .table-container table{ table-layout:auto; }
    .table-container tr:not(.buyer-row):not(#noFilterResult){
        display:table-row;
    }
    #noFilterResult td{
        display:block;
    }
}



/* =========================================================
   FINAL AUTO-FIT + COLLAPSIBLE ICON SIDEBAR FIX
   Desktop: hamburger collapses sidebar to icon-only.
   Mobile: hamburger opens sidebar drawer.
   ========================================================= */
html, body{
    width:100%;
    max-width:100%;
    overflow-x:hidden !important;
}

body{
    min-width:0;
}

.sidebar{
    left:0;
    top:0;
    width:260px;
    min-width:260px;
    max-width:260px;
    transition:width .25s ease, min-width .25s ease, max-width .25s ease, left .25s ease;
    overflow:hidden;
}

.main-panel{
    margin-left:260px;
    width:calc(100% - 260px);
    min-width:0;
    transition:margin-left .25s ease, width .25s ease;
}

.content-area,
.table-container,
.mini-summary-grid,
.tracking-filters{
    max-width:100%;
    box-sizing:border-box;
}

.top-header{
    min-width:0;
    flex-wrap:wrap;
}

.top-header-left{
    display:flex;
    align-items:center;
    gap:12px;
    min-width:0;
}

.header-title{
    min-width:0;
}

.header-title h1,
.header-title p{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.sidebar-toggle{
    width:44px;
    height:44px;
    border:none;
    border-radius:12px;
    background:#e8f5e9;
    color:#2e7d32;
    font-size:18px;
    cursor:pointer;
    flex-shrink:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    transition:.2s ease;
}

.sidebar-toggle:hover{
    background:#c8e6c9;
    transform:translateY(-1px);
}

/* Desktop collapsed sidebar: icon only */
body.sidebar-collapsed .sidebar{
    width:82px;
    min-width:82px;
    max-width:82px;
}

body.sidebar-collapsed .main-panel{
    margin-left:82px;
    width:calc(100% - 82px);
}

body.sidebar-collapsed .brand-box{
    justify-content:center;
    padding:22px 8px;
}

body.sidebar-collapsed .brand-box img{
    height:42px !important;
    max-width:52px;
    object-fit:contain;
}

body.sidebar-collapsed .brand-box div,
body.sidebar-collapsed .sidebar-menu small,
body.sidebar-collapsed .menu-text,
body.sidebar-collapsed .finance-main-link span,
body.sidebar-collapsed .submenu,
body.sidebar-collapsed .submenu-toggle-btn{
    display:none !important;
}

body.sidebar-collapsed .sidebar-menu{
    padding:18px 10px;
}

body.sidebar-collapsed .menu-link,
body.sidebar-collapsed .dropdown-toggle{
    width:52px;
    height:52px;
    padding:0 !important;
    margin:0 auto 10px auto;
    display:flex;
    align-items:center;
    justify-content:center;
    border-left:0 !important;
    gap:0 !important;
    border-radius:14px;
}

body.sidebar-collapsed .finance-main-link{
    display:flex;
    justify-content:center;
    align-items:center;
    flex:0 0 auto;
    gap:0;
}

body.sidebar-collapsed .menu-link i,
body.sidebar-collapsed .finance-main-link i{
    width:auto;
    min-width:0;
    font-size:18px;
    margin:0;
}

/* Better auto-fit table on desktop/tablet */
.table-container{
    width:100%;
    overflow-x:auto;
}

.table-container table{
    width:100%;
    min-width:980px;
    table-layout:fixed;
}

.table-container th,
.table-container td{
    word-break:break-word;
    overflow-wrap:anywhere;
}

.table-container td:nth-child(3){
    font-size:12px;
}

.table-container td:nth-child(5) .btn-action{
    white-space:normal;
    line-height:1.25;
}

.mini-summary-grid{
    display:grid !important;
    grid-template-columns:repeat(auto-fit,minmax(190px,1fr)) !important;
}

.tracking-filters{
    grid-template-columns:minmax(260px,2fr) minmax(160px,1fr) minmax(160px,1fr) minmax(160px,1fr) auto;
}

@media(max-width:1200px){
    .content-area{ padding:24px; }
    .tracking-filters{
        grid-template-columns:1fr 1fr;
    }
    .filter-reset-btn{
        grid-column:auto;
    }
}

@media(max-width:768px){
    .sidebar{
        left:-280px;
        width:260px;
        min-width:260px;
        max-width:260px;
        z-index:1000;
        box-shadow:12px 0 30px rgba(0,0,0,.18);
    }

    .main-panel,
    body.sidebar-collapsed .main-panel{
        margin-left:0;
        width:100%;
    }

    body.sidebar-collapsed .sidebar{
        left:-280px;
        width:260px;
        min-width:260px;
        max-width:260px;
    }

    body.sidebar-open .sidebar{
        left:0;
    }

    body.sidebar-open::after{
        content:"";
        position:fixed;
        inset:0;
        background:rgba(15,23,42,.45);
        z-index:999;
    }

    body.sidebar-open .sidebar{
        z-index:1001;
    }

    body.sidebar-open{
        overflow:hidden;
    }

    /* Mobile always shows full menu inside drawer */
    body.sidebar-collapsed .brand-box{
        justify-content:flex-start;
        padding:25px;
    }

    body.sidebar-collapsed .brand-box div,
    body.sidebar-collapsed .sidebar-menu small,
    body.sidebar-collapsed .menu-text,
    body.sidebar-collapsed .finance-main-link span{
        display:inline !important;
    }

    body.sidebar-collapsed .submenu-toggle-btn{
        display:flex !important;
    }

    body.sidebar-collapsed .sidebar-menu{
        padding:20px 15px;
    }

    body.sidebar-collapsed .menu-link,
    body.sidebar-collapsed .dropdown-toggle{
        width:auto;
        height:auto;
        margin-bottom:6px;
        justify-content:flex-start;
        padding:12px 18px !important;
        gap:12px !important;
        border-radius:10px;
    }

    body.sidebar-collapsed .finance-main-link{
        justify-content:flex-start;
        gap:12px;
        flex:1;
    }

    body.sidebar-collapsed .menu-link i,
    body.sidebar-collapsed .finance-main-link i{
        width:20px;
        font-size:16px;
    }

    .top-header{
        padding:15px 18px;
        gap:12px;
    }

    .top-header-left{
        width:100%;
    }

    .header-title h1{
        font-size:18px;
    }

    .header-title p{
        display:none;
    }

    .content-area{
        padding:18px;
    }

    .tracking-filters{
        grid-template-columns:1fr !important;
        padding:14px;
    }

    .filter-reset-btn{
        width:100%;
    }

    /* Mobile card layout for payment rows */
    .table-container{
        background:transparent;
        border:0;
        box-shadow:none;
        overflow:visible;
    }

    .table-container table,
    .table-container thead,
    .table-container tbody,
    .table-container th,
    .table-container td,
    .table-container tr{
        display:block;
        width:100%;
        min-width:0 !important;
        box-sizing:border-box;
    }

    .table-container thead{
        display:none;
    }

    .table-container tr.buyer-row{
        background:#fff;
        border:1px solid #c8e6c9;
        border-radius:16px;
        margin-bottom:16px;
        overflow:hidden;
        box-shadow:0 1px 2px rgba(46,125,50,.08);
    }

    .table-container tr.buyer-row td{
        border-bottom:1px solid #e2e8f0;
        padding:14px 16px;
    }

    .table-container tr.buyer-row td:last-child{
        border-bottom:0;
    }

    .table-container tr.buyer-row td::before{
        content:attr(data-label);
        display:block;
        font-size:11px;
        font-weight:800;
        color:#607d8b;
        text-transform:uppercase;
        margin-bottom:7px;
        letter-spacing:.4px;
    }

    #noFilterResult td{
        display:block;
    }
}



/* CONSISTENT PROFILE DROPDOWN - copied from verify_payments.php / reservation.php */
.header-right{
    margin-left:auto;
    display:flex;
    align-items:center;
    justify-content:flex-end;
    flex:0 0 auto;
}

.profile-dropdown{
    position:relative !important;
    cursor:pointer;
    flex:0 0 auto !important;
    width:auto !important;
}

.profile-trigger{
    width:auto !important;
    min-width:0 !important;
    height:auto !important;
    min-height:52px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:flex-start !important;
    gap:12px !important;
    padding:6px 12px !important;
    border-radius:10px !important;
    border:1px solid transparent !important;
    background:transparent !important;
    box-shadow:none !important;
    line-height:1.2 !important;
}

.profile-trigger:hover{
    background:var(--gray-light) !important;
    border-color:var(--gray-border) !important;
}

.profile-avatar{
    width:40px !important;
    height:40px !important;
    min-width:40px !important;
    border-radius:50% !important;
    background:var(--primary) !important;
    color:#fff !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-weight:800 !important;
    font-size:16px !important;
}

.profile-info{
    display:block !important;
    min-width:0 !important;
}

.profile-info strong{
    display:block !important;
    font-size:13px !important;
    color:var(--dark) !important;
    line-height:1.2 !important;
    font-weight:800 !important;
    white-space:nowrap !important;
}

.profile-info small{
    display:block !important;
    font-size:11px !important;
    color:var(--text-muted) !important;
    font-weight:500 !important;
    white-space:nowrap !important;
}

.dropdown-menu{
    display:none;
    position:absolute !important;
    right:0 !important;
    top:110% !important;
    min-width:220px !important;
    background:#fff !important;
    border:1px solid var(--gray-border) !important;
    border-radius:12px !important;
    box-shadow:var(--shadow-lg) !important;
    overflow:hidden !important;
    z-index:9999 !important;
}

.profile-dropdown:hover .dropdown-menu{
    display:block !important;
}

.dropdown-header{
    padding:15px !important;
    border-bottom:1px solid var(--gray-border) !important;
    background:var(--gray-light) !important;
}

.dropdown-header strong{
    display:block !important;
    font-size:13px !important;
    color:var(--dark) !important;
    font-weight:800 !important;
}

.dropdown-header small,
.dropdown-header span{
    font-size:11px !important;
    color:var(--text-muted) !important;
}

.dropdown-item{
    padding:12px 16px !important;
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    color:#455a64 !important;
    text-decoration:none !important;
    font-size:13px !important;
    font-weight:500 !important;
    border-left:3px solid transparent !important;
}

.dropdown-item:hover{
    background:var(--primary-light) !important;
    color:var(--primary) !important;
    border-left-color:var(--primary) !important;
}

.dropdown-item.text-danger{
    color:#d84315 !important;
}

.dropdown-item.text-danger:hover{
    background:#fbe9e7 !important;
    color:#bf360c !important;
    border-left-color:#d84315 !important;
}

@media(max-width:768px){
    .top-header{align-items:flex-start;}
    .header-right{margin-left:0;width:100%;justify-content:flex-start;}
    .profile-trigger{padding:6px 10px !important;min-height:48px !important;}
    .dropdown-menu{left:0 !important;right:auto !important;}
}

</style>
</head>
<body>

<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$financePages = ['financial.php','payment_tracking.php','daily_reconciliation.php','verify_payments.php','transaction_history.php','aging_due_report.php','contract_status.php','manual_buyer_entry.php','pricing_matrix.php'];
$isFinancePage = in_array($currentPage, $financePages);
?>

<div class="sidebar">
    <div class="brand-box">
        <img src="assets/logo1.png" style="height: 38px; width: auto; border-radius: 8px;">
        <div style="line-height: 1.1;">
            <span style="font-size: 16px; font-weight: 800; color: #2e7d32; display: block;">JEJ Top Priority Corporation</span>
            <span style="font-size: 11px; color: #607d8b; font-weight: 500;">Management Portal</span>
        </div>
    </div>

    <div class="sidebar-menu">

        

        <?php if($_SESSION['role'] !== 'CASHIER'): ?>
            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-bottom: 12px;">MAIN MENU</small>
            <a href="admin.php?view=dashboard" class="menu-link">
                <i class="fa-solid fa-chart-pie"></i> <span class="menu-text">Dashboard</span>
            </a>

            <a href="reservation.php" class="menu-link">
                <i class="fa-solid fa-file-signature"></i> <span class="menu-text">Reservations</span>
            </a>

            <a href="master_list.php" class="menu-link">
                <i class="fa-solid fa-map-location-dot"></i> <span class="menu-text">Master List / Map</span>
            </a>

            <a href="admin.php?view=inventory" class="menu-link">
                <i class="fa-solid fa-plus-circle"></i> <span class="menu-text">Add Property</span>
            </a>
        <?php endif; ?>

        <div class="menu-dropdown">

            <div class="menu-link dropdown-toggle <?= $isFinancePage ? 'active' : '' ?>">
                <a href="financial.php" class="finance-main-link">
                    <i class="fa-solid fa-coins"></i>
                    <span>Financials</span>
                </a>

                <button type="button"
                        class="submenu-toggle-btn"
                        onclick="toggleFinanceMenu(event)"
                        title="Show/Hide Financial Menu">
                    <i class="fa-solid <?= $isFinancePage ? 'fa-chevron-up' : 'fa-chevron-down' ?>" id="financeArrow"></i>
                </button>
            </div>

            <div id="financeSubMenu" class="submenu <?= $isFinancePage ? 'show' : '' ?>">

                <a href="verify_payments.php"
                   class="menu-link submenu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-circle-check"></i>
                    <span class="menu-text">Verify Payments</span>
                </a>

                <a href="payment_tracking.php"
                   class="menu-link submenu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    <span class="menu-text">Payment Tracking</span>
                </a>

                <a href="transaction_history.php"
                   class="menu-link submenu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-list-ul"></i>
                    <span class="menu-text">Ledger List</span>
                </a>

                <a href="daily_reconciliation.php"
                   class="menu-link submenu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-scale-balanced"></i>
                    <span class="menu-text">Daily Reconciliation</span>
                </a>

                <a href="aging_due_report.php" class="menu-link submenu-link <?= $currentPage == 'aging_due_report.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-clock"></i> <span class="menu-text">Aging / Due Report</span>
                </a>

                <a href="contract_status.php"
                    class="menu-link submenu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-file-signature"></i>
                        <span class="menu-text">Contract Status</span>
                </a>

                <a href="manual_buyer_entry.php"
        class="menu-link submenu-link <?= $currentPage == 'manual_buyer_entry.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-user-plus"></i>
                <span class="menu-text">Manual Buyer Entry</span>
        </a>

                <a href="pricing_matrix.php"
                   class="menu-link submenu-link <?= $currentPage == 'pricing_matrix.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-table-list"></i>
                    <span class="menu-text">Pricing Matrix</span>
                </a>
            </div>
        </div>

        <?php if($_SESSION['role'] !== 'CASHIER'): ?>
            

            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px;">SYSTEM</small>

            <a href="index.php" class="menu-link" target="_blank">
                <i class="fa-solid fa-globe"></i> <span class="menu-text">View Website</span>
            </a>
        <?php endif; ?>

    </div>
</div>

    <div class="main-panel">
        <div class="top-header">
            <div class="top-header-left">
                <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="header-title">
                    <h1>Payment Tracking</h1>
                    <p>Track buyer payment terms, record down payments, and manage amortizations.</p>
                </div>
            </div>

            <div class="header-right">
                <?php include 'includes/profile_dropdown.php'; ?>
            </div>
        </div>

        <div class="content-area">
            <!-- MINI SUMMARY CARDS -->
            <div class="mini-summary-grid">
                <div class="mini-card">
                    <i class="fa-solid fa-users"></i>
                    <div>
                        <small>Total Buyers</small>
                        <strong><?= count($approved_reservations) ?></strong>
                    </div>
                </div>

                <div class="mini-card danger">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <small>Overdue</small>
                        <strong><?= $overdue_count ?></strong>
                    </div>
                </div>

                <div class="mini-card warning">
                    <i class="fa-solid fa-clock"></i>
                    <div>
                        <small>Due Soon</small>
                        <strong><?= $due_soon_count ?></strong>
                    </div>
                </div>
            </div>

<?php if($alert_msg): ?>
                <div class="alert-banner <?= $alert_type=='success' ? 'alert-success' : 'alert-error' ?>">
                    <i class="fa-solid <?= $alert_type=='success'?'fa-check-circle':'fa-exclamation-circle' ?>"></i> <?= $alert_msg ?>
                </div>
            <?php endif; ?>

            <div class="table-container">

                <!-- SEARCH & FILTERS -->
                <div class="tracking-filters">
                    <div class="filter-group search-group">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchBuyer" placeholder="Search buyer name, account no., lot no., agent...">
                    </div>

                    <div class="filter-group">
                        <select id="statusFilter">
                            <option value="">All Buyer Status</option>
                            <option value="Fully Paid">Fully Paid</option>
                            <option value="Partial">Partial</option>
                            <option value="Overdue">Overdue</option>
                            <option value="No Payment">No Payment</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <select id="paymentFilter">
                            <option value="">All Payment Status</option>
                            <option value="Paid">Paid</option>
                            <option value="Pending">Pending</option>
                            <option value="Unpaid">Unpaid</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <select id="agentFilter">
                            <option value="">All Agents</option>
                            <?php foreach($agent_options as $agent): ?>
                                <option value="<?= htmlspecialchars($agent) ?>"><?= htmlspecialchars($agent) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="button" class="filter-reset-btn" onclick="resetTrackingFilters()">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </button>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 22%;">Buyer Info</th>
                            <th style="width: 18%;">Property</th>
                            <th style="width: 25%;">Financials & Tracking</th>
                            <th style="width: 15%;">Payment Status</th>
                            <th style="width: 20%;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($approved_reservations)): ?>
                            <?php foreach($approved_reservations as $row): 
                                $balance = $row['total_price'] - $row['dp_paid_amount'];

                                $buyer_status = 'No Payment';
                                if (!empty($row['is_account_fully_paid'])) {
                                    $buyer_status = 'Fully Paid';
                                } elseif (!empty($row['is_overdue'])) {
                                    $buyer_status = 'Overdue';
                                } elseif ((float)$row['dp_paid_amount'] > 0 || (float)$row['amort_paid_amount'] > 0) {
                                    $buyer_status = 'Partial';
                                }

                                $payment_filter_status = 'Unpaid';
                                if (!empty($row['pending_payment_amount']) && (float)$row['pending_payment_amount'] > 0) {
                                    $payment_filter_status = 'Pending';
                                } elseif (!empty($row['is_dp_fully_paid']) || (float)$row['dp_paid_amount'] > 0) {
                                    $payment_filter_status = 'Paid';
                                }

                                $agent_name = $row['agent_name'] ?? '';
                                $account_number = $row['account_number'] ?? '';
                                $search_text = trim(
                                    ($row['fullname'] ?? '') . ' ' .
                                    ($row['user_email'] ?? '') . ' ' .
                                    ($account_number ?? '') . ' ' .
                                    'Block ' . ($row['block_no'] ?? '') . ' Lot ' . ($row['lot_no'] ?? '') . ' ' .
                                    ($agent_name ?? '') . ' ' .
                                    $buyer_status . ' ' .
                                    $payment_filter_status
                                );
                            ?>
                            <tr class="buyer-row"
                                data-search="<?= htmlspecialchars(strtolower($search_text)) ?>"
                                data-status="<?= htmlspecialchars($buyer_status) ?>"
                                data-payment="<?= htmlspecialchars($payment_filter_status) ?>"
                                data-agent="<?= htmlspecialchars($agent_name) ?>">
                                <td data-label="Buyer Info">
                                    <strong style="color: #1e293b; font-size: 14px;"><?= htmlspecialchars($row['fullname']) ?></strong><br>
                                    <span style="font-size: 12px; color: #64748b;"><i class="fa-regular fa-envelope"></i> <?= htmlspecialchars($row['email'] ?? $row['user_email']) ?></span><br>
                                    <?php if(!empty($agent_name)): ?>
                                        <span style="font-size: 12px; color: #64748b;"><i class="fa-solid fa-user-tie"></i> Agent: <?= htmlspecialchars($agent_name) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Property">
                                    <strong style="color: #2e7d32; font-size: 13px;">Block <?= htmlspecialchars($row['block_no']) ?> Lot <?= htmlspecialchars($row['lot_no']) ?></strong>
                                    <?php if(!empty($account_number)): ?>
                                        <br><span style="font-size:11px; color:#2563eb; font-weight:700;"><i class="fa-solid fa-hashtag"></i> <?= htmlspecialchars($account_number) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Financials & Tracking">
                                    <div style="font-size: 12px; margin-bottom: 4px; color: #475569;">Cash TCP / Base TCP: <strong>₱<?= number_format($row['total_price'], 2) ?></strong></div>
                                    <div style="font-size: 12px; margin-bottom: 4px; color: #dc2626; font-weight:800;">
                                        Pricing Option:
                                        <strong><?= htmlspecialchars($row['payment_option_label'] ?? '') ?></strong>
                                    </div>
                                    <div style="font-size: 12px; margin-bottom: 4px; color: #475569;">
                                        Final Contract Price: <strong>₱<?= number_format($row['tcp_after_discount'], 2) ?></strong>
                                    </div>

                                    <div style="font-size: 12px; margin-bottom: 4px; color: <?= !empty($row['is_reservation_fee_fully_paid']) ? '#059669' : '#b45309' ?>; font-weight: 800;">
                                        <i class="fa-solid <?= !empty($row['is_reservation_fee_fully_paid']) ? 'fa-circle-check' : 'fa-clock' ?>"></i>
                                        Reservation Fee <?= !empty($row['is_reservation_fee_fully_paid']) ? 'Paid' : 'Due' ?>:
                                        ₱<?= number_format(!empty($row['is_reservation_fee_fully_paid']) ? $row['reservation_fee_paid'] : $row['reservation_fee_amount'], 2) ?>
                                    </div>

                                    <?php if($row['payment_type_clean'] === 'SPOT_CASH'): ?>
                                        <div style="font-size: 12px; margin-bottom: 4px; color: #0f766e; font-weight:800;">
                                            Spot Cash Balance Due: <strong>₱<?= number_format($row['balance_to_amortize'], 2) ?></strong>
                                        </div>

                                        <div style="font-size: 12px; margin-bottom: 4px; color: #059669; font-weight:800;">
                                            Spot Cash Paid: <strong>₱<?= number_format($row['cash_balance_paid_amount'], 2) ?></strong>
                                        </div>

                                        <?php if(!empty($row['pending_payment_amount']) && $row['pending_payment_amount'] > 0): ?>
                                            <div style="
                                            background:#fff7ed;
                                            border:1px solid #fdba74;
                                            color:#c2410c;
                                            padding:6px 10px;
                                            border-radius:6px;
                                            margin-top:6px;
                                            font-size:11px;
                                            font-weight:700;
                                            ">
                                            WAITING FOR CASHIER VERIFICATION
                                            </div>

                                            <div style="font-size: 12px; margin-bottom: 4px; color: #f59e0b; font-weight: 800;">
                                                <i class="fa-solid fa-clock"></i> Pending Verification: ₱<?= number_format($row['pending_payment_amount'], 2) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div style="font-size: 12px; margin-top: 6px; padding-top: 6px; border-top: 1px dashed #cbd5e1;">
                                            Overall Outstanding Balance:
                                            <strong style="color:<?= $row['overall_outstanding_balance'] <= 0 ? '#059669' : '#e11d48' ?>;">
                                                ₱<?= number_format($row['overall_outstanding_balance'], 2) ?>
                                            </strong>
                                        </div>

                                        <?php if(!empty($row['is_account_fully_paid'])): ?>
                                            <div style="color:#059669;font-weight:800;margin-top:3px; font-size: 12px;">
                                                <i class="fa-solid fa-circle-check"></i> Total Contract Price Fully Paid
                                            </div>
                                        <?php else: ?>
                                            <div style="color:#e11d48;font-weight:800;margin-top:3px; font-size: 12px;">
                                                <i class="fa-solid fa-clock"></i> Awaiting Spot Cash Balance
                                            </div>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <div style="font-size: 12px; margin-bottom: 4px; color: #334155;">DP Target (20% of Final TCP): <strong>₱<?= number_format($row['dp_total_required'], 2) ?></strong></div>
                                        <div style="font-size: 12px; margin-bottom: 4px; color: #334155;">Balance to Amortize: <strong>₱<?= number_format($row['balance_to_amortize'], 2) ?></strong></div>
                                        <div style="font-size: 12px; margin-bottom: 4px; color: #059669; font-weight: 700;">DP Paid: ₱<?= number_format($row['dp_paid_amount'], 2) ?></div>

                                        <?php if(!empty($row['pending_payment_amount']) && $row['pending_payment_amount'] > 0): ?>
                                            <div style="
                                            background:#fff7ed;
                                            border:1px solid #fdba74;
                                            color:#c2410c;
                                            padding:6px 10px;
                                            border-radius:6px;
                                            margin-top:6px;
                                            font-size:11px;
                                            font-weight:700;
                                            ">
                                            WAITING FOR CASHIER VERIFICATION
                                            </div>

                                            <div style="font-size: 12px; margin-bottom: 4px; color: #f59e0b; font-weight: 800;">
                                                <i class="fa-solid fa-clock"></i> Pending Verification: ₱<?= number_format($row['pending_payment_amount'], 2) ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if(!$row['is_dp_fully_paid']): ?>
                                            <div style="font-size: 12px; margin-bottom: 4px; color: #e11d48; font-weight: 700;">DP Due: ₱<?= number_format($row['dp_remaining'], 2) ?></div>
                                        <?php else: ?>
                                            <div style="font-size: 11px; color: #64748b; margin-top: 6px; padding-top: 6px; border-top: 1px dashed #cbd5e1;">
                                                <div>
                                                    Amortization Paid:
                                                    <strong style="color: #3b82f6;">₱<?= number_format($row['amort_paid_amount'], 2) ?></strong>
                                                </div>

                                                <?php if(!empty($row['is_amortization_fully_paid'])): ?>
                                                    <div style="color:#059669;font-weight:800;margin-top:3px;">
                                                        <i class="fa-solid fa-check-circle"></i> Amortization Fully Paid
                                                    </div>
                                                <?php else: ?>
                                                    <div style="color:#2563eb;font-weight:700;margin-top:3px;">
                                                        <i class="fa-solid fa-clock"></i> Ongoing Installment Payments
                                                    </div>
                                                <?php endif; ?>

                                                <div style="margin-top:5px;">
                                                    Overall Outstanding Balance:
                                                    <strong style="color:<?= $row['overall_outstanding_balance'] <= 0 ? '#059669' : '#e11d48' ?>;">
                                                        ₱<?= number_format($row['overall_outstanding_balance'], 2) ?>
                                                    </strong>
                                                </div>

                                                <?php if(!empty($row['is_account_fully_paid'])): ?>
                                                    <div style="color:#059669;font-weight:800;margin-top:3px;">
                                                        <i class="fa-solid fa-circle-check"></i> Account Fully Settled
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Payment Status">
                                    <?php if($row['payment_type_clean'] === 'SPOT_CASH'): ?>
                                        <?php if(!empty($row['is_account_fully_paid'])): ?>
                                            <span class="badge badge-full"><i class="fa-solid fa-check"></i> TCP Fully Paid</span>
                                        <?php elseif(!empty($row['pending_payment_amount']) && (float)$row['pending_payment_amount'] > 0): ?>
                                            <span class="badge badge-partial" style="background: #fff7ed; color: #c2410c; border-color: #fdba74;"><i class="fa-solid fa-clock"></i> TCP Verifying</span>
                                        <?php elseif((float)$row['cash_balance_paid_amount'] > 0): ?>
                                            <span class="badge badge-partial"><i class="fa-solid fa-spinner"></i> Partial TCP</span>
                                        <?php else: ?>
                                            <span class="badge badge-none"><i class="fa-solid fa-xmark"></i> TCP Unpaid</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if(!empty($row['is_account_fully_paid'])): ?>
                                            <span class="badge badge-full"><i class="fa-solid fa-check"></i> Fully Paid</span>
                                        <?php elseif($row['is_dp_fully_paid']): ?>
                                            <span class="badge badge-full"><i class="fa-solid fa-check"></i> DP Fully Settled</span>
                                        <?php elseif(isset($row['dp_status']) && $row['dp_status'] == 'VERIFYING'): ?>
                                            <span class="badge badge-partial" style="background: #f0f9ff; color: #0369a1; border-color: #bae6fd;"><i class="fa-solid fa-arrows-rotate fa-spin"></i> Verifying Online DP</span>
                                        <?php elseif($row['dp_paid_amount'] > 0): ?>
                                            <span class="badge badge-partial"><i class="fa-solid fa-spinner"></i> Partial DP</span>
                                        <?php else: ?>
                                            <span class="badge badge-none"><i class="fa-solid fa-xmark"></i> No Payment</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Action">
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        
                                        <button type="button" class="btn-action btn-billing" data-id="<?= (int)$row['id'] ?>" data-name="<?= htmlspecialchars($row['fullname']) ?>" onclick="showBillingModal(this)">
                                            <i class="fa-solid fa-file-invoice"></i> Show Billing
                                        </button>

                                        <button type="button" class="btn-action btn-record"
                                                data-id="<?= (int)$row['id'] ?>"
                                                data-rfee="<?= number_format((float)$row['reservation_fee_remaining'], 2, '.', '') ?>"
                                                data-dp="<?= number_format((float)$row['dp_remaining'], 2, '.', '') ?>"
                                                data-amort="<?= number_format((float)$row['monthly_payment'], 2, '.', '') ?>"
                                                data-fullbalance="<?= number_format((float)$row['overall_outstanding_balance'], 2, '.', '') ?>"
                                                data-name="<?= htmlspecialchars($row['fullname']) ?>"
                                                data-paid="<?= $row['is_dp_fully_paid'] ? '1' : '0' ?>"
                                                data-mode="<?= htmlspecialchars($row['payment_type_clean']) ?>"
                                                onclick="openPaymentModal(this)">
                                            <i class="fa-solid fa-cash-register"></i> Record Payment
                                        </button>
                                        <a href="verify_payments.php" class="btn-action" 
                                            style="background:#0f172a; color:white;">
                                            <i class="fa-solid fa-circle-check"></i>
                                            Verify Payments
                                            </a>

                                        <button type="button" class="btn-action btn-remind" 
                                                data-id="<?= (int)$row['id'] ?>" 
                                                data-name="<?= htmlspecialchars($row['fullname']) ?>"
                                                data-dpremain="<?= $row['dp_remaining'] ?>"
                                                data-amort="<?= $row['monthly_payment'] ?>"
                                                data-dpdate="<?= $row['dp_deadline_date'] ?>"
                                                data-paid="<?= $row['is_dp_fully_paid'] ? '1' : '0' ?>"
                                                onclick="openReminderModal(this)">
                                            <i class="fa-solid fa-paper-plane"></i> Send Reminder
                                        </button>
                                        
                                        <a href="payment_terms.php?res_id=<?= $row['id'] ?>" class="btn-action btn-edit-terms">
                                            <i class="fa-solid fa-pen-to-square"></i> Edit Terms
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center; padding: 40px; color: #94a3b8;">No approved reservations found.</td></tr>
                        <?php endif; ?>
                        <tr id="noFilterResult">
                            <td colspan="5" class="no-filter-result">No matching payment records found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="paymentModal" class="modal">
            <div class="modal-content payment-modal-content">
                <div class="modal-header">
                    <h2><i class="fa-solid fa-cash-register" style="color: #10b981; margin-right: 5px;"></i> Record Payment</h2>
                    <button type="button" class="close-btn" onclick="closePaymentModal()"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <form method="POST" enctype="multipart/form-data" class="payment-modal-body">
                    <input type="hidden" name="res_id" id="modal_res_id">

                    <div class="payment-form-grid">

                        <div class="full-row" style="background:#f1f8e9; border:1px solid #c8e6c9; padding:12px; border-radius:8px;">
                            <span style="font-size:12px; color:#2e7d32; font-weight:700; display:block; margin-bottom:4px;">Recording payment for:</span>
                            <strong id="modal_buyer_name" style="color:#1b5e20; font-size:15px;"></strong>
                        </div>

                        <div class="form-group">
                            <label>Payment Category Type</label>
                            <select name="payment_type" id="modal_pay_type" class="form-control" required>
                                <option value="RESERVATION_FEE">Reservation Fee</option>
                                <option value="DP">Down Payment</option>
                                <option value="AMORTIZATION">Monthly Amortization</option>
                                <option value="CASH_BALANCE">Spot Cash Balance</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Payment Amount (₱)</label>
                            <input type="number" step="0.01" name="amount" id="modal_amount" class="form-control" required>
                            <div id="amountQuickActions" style="display:flex; gap:8px; margin-top:8px; flex-wrap:wrap;">
                                <button type="button" onclick="useMonthlyDueAmount()" style="border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:7px;padding:6px 10px;font-size:11px;font-weight:800;cursor:pointer;">
                                    Monthly Due
                                </button>
                                <button type="button" onclick="useFullOutstandingAmount()" style="border:1px solid #bbf7d0;background:#f0fdf4;color:#047857;border-radius:7px;padding:6px 10px;font-size:11px;font-weight:800;cursor:pointer;">
                                    Full Outstanding
                                </button>
                            </div>
                            <small id="amountHelperText" style="display:block; margin-top:6px; color:#64748b; font-weight:700; font-size:11px; line-height:1.35;"></small>
                        </div>

                        <div class="form-group">
                            <label>Date Received</label>
                            <input type="date" name="trans_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="form-group">
                            <label>OR / Reference Number</label>
                            <input type="text" class="form-control" value="Auto-generated after saving" readonly>
                        </div>

                        <div class="form-group">
                            <label>Remarks / Payment Method</label>
                            <input type="text" name="remarks" class="form-control" placeholder="E.g., Cash received, GCash, Bank transfer...">
                        </div>

                        <div class="form-group">
                            <label>Proof of Payment / Receipt Image</label>
                            <input type="file" name="proof_image" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                            <small style="display:block; margin-top:6px; color:#64748b;">Accepted: JPG, PNG, PDF. Maximum 5MB.</small>
                        </div>

                        <div class="payment-note full-row">
                            <strong><i class="fa-solid fa-triangle-exclamation"></i> Verification Required:</strong><br>
                            This payment will be saved as <b>Pending Verification</b>. One receipt/deposit creates only <b>one</b> verification item. If the buyer pays full or advance, enter the full amount once; after verification, SOA will automatically mark the covered months as paid.
                        </div>

                        <div class="payment-modal-actions">
                            <button type="button" onclick="closePaymentModal()" class="btn-cancel-modal">Cancel</button>
                            <button type="submit" name="record_payment" class="btn-save-modal"><i class="fa-solid fa-save"></i> Save Payment</button>
                        </div>

                    </div>
                </form>
            </div>
        </div>

        <div id="reminderModal" class="modal">
            <div class="modal-content">
                <div class="modal-header" style="background: #fef2f2; border-bottom: 1px solid #fecaca;">
                    <h2 style="color: #be123c;"><i class="fa-solid fa-envelope" style="color: #ef4444; margin-right: 5px;"></i> Send Payment Reminder</h2>
                    <button type="button" class="close-btn" onclick="closeReminderModal()"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <form method="POST" style="padding: 25px;">
                    <input type="hidden" name="res_id" id="remind_res_id">

                    <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                        <span style="font-size: 12px; color: #b91c1c; font-weight: 700; display: block; margin-bottom: 4px;">Sending reminder to:</span>
                        <strong id="remind_buyer_name" style="color: #991b1b; font-size: 15px;"></strong>
                    </div>

                    <div class="form-group">
                        <label>Reminder For</label>
                        <select name="reminder_type" id="remind_type" class="form-control" required>
                            <option value="Down Payment">Down Payment</option>
                            <option value="Monthly Amortization">Monthly Amortization</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Amount to Settle (₱)</label>
                        <input type="number" step="0.01" name="amount_due" id="remind_amount" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" id="remind_date" class="form-control" required>
                    </div>

                    <div style="margin-top: 25px; text-align: right; border-top: 1px solid #fecaca; padding-top: 15px;">
                        <button type="button" onclick="closeReminderModal()" style="background:#f1f5f9; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; cursor: pointer; margin-right: 10px; color:#475569;">Cancel</button>
                        <button type="submit" name="send_reminder" style="background:#ef4444; color:white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer;"><i class="fa-solid fa-paper-plane"></i> Send Email</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="billingModal" class="modal" style="background:rgba(15, 23, 42, 0.6); backdrop-filter: blur(3px);">
            <div class="modal-content" style="max-width:900px; width:96vw; max-height:88vh; overflow:auto; padding:0;">
                <div class="modal-header">
                    <h2 style="color: #1e293b;">Statement of Account & Amortization</h2>
                    <button type="button" class="close-btn" onclick="closeBillingModal()"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div id="billingContent" style="padding: 20px;">
                    <div style="text-align:center; color:#64748b; padding: 40px;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px; display: block;"></i> Loading statement...</div>
                </div>
            </div>
        </div>

        <script>
        let modalReservationFeeDue = 0;
        let modalMonthlyDue = 0;
        let modalFullOutstanding = 0;
        let modalDpDue = 0;
        let modalMode = 'INSTALLMENT';

        function money2(value){
            return Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
        }

        function updateAmountHelperText(){
            const helper = document.getElementById('amountHelperText');
            const payType = document.getElementById('modal_pay_type');
            const quick = document.getElementById('amountQuickActions');
            if(!helper || !payType) return;

            if(payType.value === 'RESERVATION_FEE'){
                helper.innerHTML = 'Reservation fee due: ₱' + money2(modalReservationFeeDue) + '. This will post to Financial Dashboard only after verification.';
                if(quick) quick.style.display = 'flex';
                return;
            }

            if(modalMode === 'SPOT_CASH'){
                helper.innerHTML = 'Spot cash payment uses the full outstanding balance. One payment = one verification.';
                if(quick) quick.style.display = 'none';
                return;
            }

            if(quick) quick.style.display = 'flex';

            if(payType.value === 'DP'){
                helper.innerHTML = 'DP due: ₱' + money2(modalDpDue) + '. If buyer pays more, enter the actual amount received.';
            }else{
                helper.innerHTML = 'Monthly due: ₱' + money2(modalMonthlyDue) + ' | Full outstanding: ₱' + money2(modalFullOutstanding) + '. Full/advance payment will stay as one pending record and needs only one Verify.';
            }
        }

        function useMonthlyDueAmount(){
            const payType = document.getElementById('modal_pay_type');
            const amount = document.getElementById('modal_amount');
            if(!payType || !amount) return;
            if(payType.value === 'RESERVATION_FEE'){
                amount.value = modalReservationFeeDue.toFixed(2);
                return;
            }
            if(modalMode === 'SPOT_CASH'){
                amount.value = modalFullOutstanding.toFixed(2);
                return;
            }
            amount.value = (payType.value === 'DP' ? modalDpDue : modalMonthlyDue).toFixed(2);
        }

        function useFullOutstandingAmount(){
            const payType = document.getElementById('modal_pay_type');
            const amount = document.getElementById('modal_amount');
            if(!payType || !amount) return;
            if(modalMode !== 'SPOT_CASH'){
                payType.value = 'AMORTIZATION';
            }
            amount.value = modalFullOutstanding.toFixed(2);
            updateAmountHelperText();
        }

        function openPaymentModal(btn) {
            const payType = document.getElementById('modal_pay_type');
            const amount = document.getElementById('modal_amount');

            document.getElementById('modal_res_id').value = btn.dataset.id;
            document.getElementById('modal_buyer_name').innerText = btn.dataset.name;

            modalMode = btn.dataset.mode || 'INSTALLMENT';
            modalReservationFeeDue = parseFloat(btn.dataset.rfee || '0') || 0;
            modalDpDue = parseFloat(btn.dataset.dp || '0') || 0;
            modalMonthlyDue = parseFloat(btn.dataset.amort || '0') || 0;
            modalFullOutstanding = parseFloat(btn.dataset.fullbalance || '0') || 0;

            function setAmountFromType(){
                if(payType.value === 'RESERVATION_FEE'){
                    amount.value = modalReservationFeeDue.toFixed(2);
                    updateAmountHelperText();
                    return;
                }

                if(modalMode === 'SPOT_CASH'){
                    payType.value = 'CASH_BALANCE';
                    amount.value = modalFullOutstanding.toFixed(2);
                    updateAmountHelperText();
                    return;
                }

                if(payType.value === 'DP'){
                    amount.value = modalDpDue.toFixed(2);
                }else{
                    amount.value = modalMonthlyDue.toFixed(2);
                }
                updateAmountHelperText();
            }

            if(modalReservationFeeDue > 0.01){
                payType.value = 'RESERVATION_FEE';
                amount.value = modalReservationFeeDue.toFixed(2);
            }else if(modalMode === 'SPOT_CASH'){
                payType.value = 'CASH_BALANCE';
                amount.value = modalFullOutstanding.toFixed(2);
            }else if(btn.dataset.paid == '1'){
                payType.value = 'AMORTIZATION';
                amount.value = modalMonthlyDue.toFixed(2);
            }else{
                payType.value = 'DP';
                amount.value = modalDpDue.toFixed(2);
            }

            Array.from(payType.options).forEach(opt => {
                if(opt.value === 'RESERVATION_FEE'){
                    opt.disabled = false;
                }else if(modalMode === 'SPOT_CASH'){
                    opt.disabled = (opt.value !== 'CASH_BALANCE');
                }else{
                    opt.disabled = (opt.value === 'CASH_BALANCE');
                }
            });

            payType.onchange = setAmountFromType;
            updateAmountHelperText();
            document.getElementById('paymentModal').style.display = 'flex';
        }
        function closePaymentModal() { document.getElementById('paymentModal').style.display = 'none'; }

        function openReminderModal(btn) {
            document.getElementById('remind_res_id').value = btn.dataset.id;
            document.getElementById('remind_buyer_name').innerText = btn.dataset.name;
            
            if(btn.dataset.paid == '1') {
                document.getElementById('remind_type').value = 'Monthly Amortization';
                document.getElementById('remind_amount').value = btn.dataset.amort;
                document.getElementById('remind_date').value = ''; 
            } else {
                document.getElementById('remind_type').value = 'Down Payment';
                document.getElementById('remind_amount').value = btn.dataset.dpremain;
                document.getElementById('remind_date').value = btn.dataset.dpdate;
            }
            
            document.getElementById('reminderModal').style.display = 'flex';
        }
        function closeReminderModal() { document.getElementById('reminderModal').style.display = 'none'; }

        function showBillingModal(btn) {
            var modal = document.getElementById('billingModal');
            var content = document.getElementById('billingContent');
            modal.style.display = 'flex';
            content.innerHTML = '<div style="text-align:center; color:#64748b; padding: 40px;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 10px; display: block;"></i> Loading statement for ' + btn.dataset.name + '...</div>';

            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'statement_of_account.php?res_id=' + encodeURIComponent(btn.dataset.id), true);
            xhr.onload = function() {
                if (xhr.status === 200) { content.innerHTML = xhr.responseText; } 
                else { content.innerHTML = '<div style="color:#e11d48; text-align:center; padding: 30px;">Failed to load billing info.</div>'; }
            };
            xhr.send();
        }
        function closeBillingModal() { document.getElementById('billingModal').style.display = 'none'; }

        function printStatement() {
            var billing = document.getElementById('billingContent');

            if (!billing) {
                alert('Statement content not found.');
                return;
            }

            /*
                IMPORTANT FIX:
                The billing statement is loaded through AJAX from statement_of_account.php.
                When we open a new print window, relative image paths like assets/logo.png
                can break. This converts every image source to a full URL before printing.
            */
            var printClone = billing.cloneNode(true);
            var baseUrl = window.location.href.substring(0, window.location.href.lastIndexOf('/') + 1);
            var fallbackLogo = baseUrl + 'assets/logo1.png';

            printClone.querySelectorAll('img').forEach(function(img){
                var src = img.getAttribute('src') || '';

                if (!src || src === '#' || src.toLowerCase() === 'null') {
                    img.setAttribute('src', fallbackLogo);
                    return;
                }

                if (src.indexOf('data:image') === 0 || src.indexOf('http://') === 0 || src.indexOf('https://') === 0) {
                    img.setAttribute('src', src);
                } else {
                    img.setAttribute('src', new URL(src, baseUrl).href);
                }

                img.setAttribute('crossorigin', 'anonymous');
                img.style.display = 'block';
                img.style.objectFit = 'contain';
            });

            var printContents = printClone.innerHTML;
            var printWindow = window.open('', '', 'width=1200,height=900');

            printWindow.document.open();
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Statement of Account</title>
                    <base href="${baseUrl}">
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

                    <style>
                        @page {
                            size: A4 portrait;
                            margin: 6mm;
                        }

                        html, body {
                            margin: 0;
                            padding: 0;
                            background: #fff;
                            font-family: Arial, Helvetica, sans-serif;
                            -webkit-print-color-adjust: exact !important;
                            print-color-adjust: exact !important;
                        }

                        body {
                            padding: 6mm;
                        }

                        * {
                            box-sizing: border-box;
                            -webkit-print-color-adjust: exact !important;
                            print-color-adjust: exact !important;
                        }

                        #billingContent,
                        #soaStatement {
                            width: 100% !important;
                            max-width: 100% !important;
                            margin: 0 auto !important;
                            background: white !important;
                        }

                        button,
                        .no-print,
                        .no-print-button {
                            display: none !important;
                        }

                        table {
                            width: 100% !important;
                            border-collapse: collapse !important;
                        }

                        img,
                        .soa-logo,
                        .statement-logo {
                            display: block !important;
                            max-width: 100% !important;
                            height: auto !important;
                            object-fit: contain !important;
                        }

                        .soa-logo,
                        .statement-logo {
                            width: 90px !important;
                            max-height: 90px !important;
                        }

                        .amortization-two-column th,
                        .amortization-two-column td {
                            font-size: 7px !important;
                            padding: 2px 3px !important;
                            line-height: 1.05 !important;
                            white-space: nowrap !important;
                        }
                    



</style>
                </head>

                <body>
                    <div id="billingContent">
                        ${printContents}
                    </div>

                    <script>
                        window.onload = function(){
                            var images = Array.prototype.slice.call(document.images);
                            var waitImages = images.map(function(img){
                                return new Promise(function(resolve){
                                    if (img.complete) {
                                        resolve();
                                    } else {
                                        img.onload = resolve;
                                        img.onerror = resolve;
                                    }
                                });
                            });

                            Promise.all(waitImages).then(function(){
                                setTimeout(function(){
                                    window.print();
                                    window.close();
                                }, 500);
                            });
                        };
                    <\/script>

                </body>
                </html>
            `);

            printWindow.document.close();
        }
        // Make the print button inside loaded statement use the clean print window,
        // not the browser modal/screen capture.
        document.addEventListener('click', function(e) {
            var printBtn = e.target.closest('button');
            if (printBtn && printBtn.textContent.toLowerCase().includes('print statement')) {
                e.preventDefault();
                e.stopPropagation();
                printStatement();
            }
        }, true);


        window.addEventListener('click', function(e) {
            if (e.target === document.getElementById('billingModal')) closeBillingModal();
            if (e.target === document.getElementById('paymentModal')) closePaymentModal();
            if (e.target === document.getElementById('reminderModal')) closeReminderModal();
        });
        </script>
    </div>
    <script>
function toggleFinanceMenu(event){
    event.preventDefault();
    event.stopPropagation();

    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');

    submenu.classList.toggle('show');

    arrow.classList.toggle('fa-chevron-down');
    arrow.classList.toggle('fa-chevron-up');
}
</script>
<script>
function applyTrackingFilters(){
    const searchValue = (document.getElementById('searchBuyer')?.value || '').toLowerCase().trim();
    const statusValue = document.getElementById('statusFilter')?.value || '';
    const paymentValue = document.getElementById('paymentFilter')?.value || '';
    const agentValue = document.getElementById('agentFilter')?.value || '';

    let visibleCount = 0;

    document.querySelectorAll('.buyer-row').forEach(row => {
        const rowSearch = row.dataset.search || row.innerText.toLowerCase();
        const rowStatus = row.dataset.status || '';
        const rowPayment = row.dataset.payment || '';
        const rowAgent = row.dataset.agent || '';

        const matchSearch = !searchValue || rowSearch.includes(searchValue);
        const matchStatus = !statusValue || rowStatus === statusValue;
        const matchPayment = !paymentValue || rowPayment === paymentValue;
        const matchAgent = !agentValue || rowAgent === agentValue;

        if(matchSearch && matchStatus && matchPayment && matchAgent){
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const noResult = document.getElementById('noFilterResult');
    if(noResult){
        noResult.style.display = visibleCount === 0 ? 'table-row' : 'none';
    }
}

function resetTrackingFilters(){
    document.getElementById('searchBuyer').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('paymentFilter').value = '';
    document.getElementById('agentFilter').value = '';
    applyTrackingFilters();
}

['searchBuyer','statusFilter','paymentFilter','agentFilter'].forEach(id => {
    const el = document.getElementById(id);
    if(el){
        el.addEventListener(id === 'searchBuyer' ? 'keyup' : 'change', applyTrackingFilters);
    }
});
</script>

<script>
function toggleSidebar(){
    const isMobile = window.matchMedia('(max-width: 768px)').matches;

    if(isMobile){
        document.body.classList.toggle('sidebar-open');
        document.body.classList.remove('sidebar-collapsed');
        return;
    }

    document.body.classList.toggle('sidebar-collapsed');
    document.body.classList.remove('sidebar-open');

    try{
        localStorage.setItem('jejSidebarCollapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
    }catch(e){}
}

(function initSidebarState(){
    try{
        if(window.innerWidth > 768 && localStorage.getItem('jejSidebarCollapsed') === '1'){
            document.body.classList.add('sidebar-collapsed');
        }
    }catch(e){}
})();

document.addEventListener('click', function(e){
    if(!document.body.classList.contains('sidebar-open')) return;

    const sidebar = document.querySelector('.sidebar');
    const toggle = document.querySelector('.sidebar-toggle');

    if(sidebar && !sidebar.contains(e.target) && toggle && !toggle.contains(e.target)){
        document.body.classList.remove('sidebar-open');
    }
});

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        document.body.classList.remove('sidebar-open');
    }
});

window.addEventListener('resize', function(){
    if(window.innerWidth > 768){
        document.body.classList.remove('sidebar-open');
        try{
            if(localStorage.getItem('jejSidebarCollapsed') === '1'){
                document.body.classList.add('sidebar-collapsed');
            }
        }catch(e){}
    }else{
        document.body.classList.remove('sidebar-collapsed');
    }
});
</script>
</body>
</html>