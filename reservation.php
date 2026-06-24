<?php
// reservation.php

require_once 'config.php';

// CASHIER BLOCK
if ($_SESSION['role'] === 'CASHIER') {
    header("Location: financial.php");
    exit();
}

// Role Access
requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER']);

// Manager Permission Access
if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'res_full');
}


// --- CHECK CONTRACT COLUMNS ---
$has_contract_file_col = false;
$has_contract_uploaded_at_col = false;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'contract_file'");
if($colCheck && $colCheck->num_rows > 0) $has_contract_file_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'contract_uploaded_at'");
if($colCheck && $colCheck->num_rows > 0) $has_contract_uploaded_at_col = true;

// --- CHECK ACCOUNT NUMBER COLUMN ---
$has_account_number_col = false;
$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'account_number'");
if($colCheck && $colCheck->num_rows > 0) $has_account_number_col = true;

// --- CHECK ID / KYC VERIFICATION COLUMNS ---
$has_id_verification_status_col = false;
$has_verified_by_col = false;
$has_verified_at_col = false;
$has_live_selfie_col = false;
$has_buyer_latitude_col = false;
$has_buyer_longitude_col = false;
$has_location_mode_col = false;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'id_verification_status'");
if($colCheck && $colCheck->num_rows > 0) $has_id_verification_status_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'verified_by'");
if($colCheck && $colCheck->num_rows > 0) $has_verified_by_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'verified_at'");
if($colCheck && $colCheck->num_rows > 0) $has_verified_at_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'live_selfie'");
if($colCheck && $colCheck->num_rows > 0) $has_live_selfie_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'buyer_latitude'");
if($colCheck && $colCheck->num_rows > 0) $has_buyer_latitude_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'buyer_longitude'");
if($colCheck && $colCheck->num_rows > 0) $has_buyer_longitude_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'location_mode'");
if($colCheck && $colCheck->num_rows > 0) $has_location_mode_col = true;

// --- CHECK CANCELLATION AUDIT COLUMNS ---
$has_cancelled_by_col = false;
$has_cancelled_at_col = false;
$has_cancellation_reason_col = false;
$has_cancellation_status_col = false;
$has_cancellation_requested_at_col = false;
$has_cancellation_action_by_col = false;
$has_cancellation_action_at_col = false;
$has_cancellation_admin_note_col = false;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'cancelled_by'");
if($colCheck && $colCheck->num_rows > 0) $has_cancelled_by_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'cancelled_at'");
if($colCheck && $colCheck->num_rows > 0) $has_cancelled_at_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'cancellation_reason'");
if($colCheck && $colCheck->num_rows > 0) $has_cancellation_reason_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'cancellation_status'");
if($colCheck && $colCheck->num_rows > 0) $has_cancellation_status_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'cancellation_requested_at'");
if($colCheck && $colCheck->num_rows > 0) $has_cancellation_requested_at_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'cancellation_action_by'");
if($colCheck && $colCheck->num_rows > 0) $has_cancellation_action_by_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'cancellation_action_at'");
if($colCheck && $colCheck->num_rows > 0) $has_cancellation_action_at_col = true;

$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'cancellation_admin_note'");
if($colCheck && $colCheck->num_rows > 0) $has_cancellation_admin_note_col = true;

function reservationCleanText($value, int $limit = 255): string {
    $text = trim((string)$value);
    $text = strip_tags($text);
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    if(function_exists('mb_substr')){
        return mb_substr($text, 0, $limit, 'UTF-8');
    }
    return substr($text, 0, $limit);
}

function reservationNotifyUser(mysqli $conn, int $userId, string $title, string $message): void {
    if($userId <= 0) return;
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
    if($stmt){
        $stmt->bind_param("iss", $userId, $title, $message);
        $stmt->execute();
        $stmt->close();
    }
}

function reservationReleaseLotIfSafe(mysqli $conn, int $lotId): void {
    if($lotId <= 0) return;

    $active = $conn->prepare("
        SELECT COUNT(*) AS active_count
        FROM reservations
        WHERE lot_id = ?
          AND UPPER(status) IN ('PENDING','APPROVED')
    ");
    if(!$active) return;

    $active->bind_param("i", $lotId);
    $active->execute();
    $activeCount = (int)($active->get_result()->fetch_assoc()['active_count'] ?? 0);
    $active->close();

    if($activeCount === 0){
        $update = $conn->prepare("
            UPDATE lots
            SET status = 'AVAILABLE'
            WHERE id = ?
              AND UPPER(status) IN ('RESERVED','PENDING','HOLD','ON HOLD')
        ");
        if($update){
            $update->bind_param("i", $lotId);
            $update->execute();
            $update->close();
        }
    }
}

// Auto-generate missing account numbers for already approved reservations.
// Format: JEJ-YYYY-0001 based on reservation ID.
if($has_account_number_col){
    $conn->query("UPDATE reservations SET account_number = CONCAT('JEJ-', YEAR(COALESCE(reservation_date, NOW())), '-', LPAD(id, 4, '0')) WHERE status='APPROVED' AND (account_number IS NULL OR account_number='')");
}

// --- MANUAL ID / KYC VERIFICATION ACTION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_id_verification'])) {
    $res_id = intval($_POST['res_id']);
    $new_status = strtoupper(trim($_POST['id_verification_status'] ?? 'PENDING REVIEW'));

    $allowed_id_statuses = [
        'PENDING REVIEW',
        'OCR MATCHED',
        'NAME MISMATCH',
        'REJECTED',
        'MANUALLY VERIFIED'
    ];

    if(!$has_id_verification_status_col){
        header("Location: reservation.php?msg=id_column_missing");
        exit();
    }

    if(!in_array($new_status, $allowed_id_statuses, true)){
        $new_status = 'PENDING REVIEW';
    }

    $status_check_stmt = $conn->prepare("SELECT status FROM reservations WHERE id = ? LIMIT 1");
    if($status_check_stmt){
        $status_check_stmt->bind_param("i", $res_id);
        $status_check_stmt->execute();
        $status_row = $status_check_stmt->get_result()->fetch_assoc();
        $status_check_stmt->close();

        if(strtoupper(trim($status_row['status'] ?? '')) === 'CANCELLED'){
            header("Location: reservation.php?status=CANCELLED&msg=cancelled_no_action");
            exit();
        }
    }

    if($has_verified_by_col && $has_verified_at_col){
        $admin_id = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare("
            UPDATE reservations
            SET id_verification_status = ?,
                verified_by = ?,
                verified_at = NOW()
            WHERE id = ?
        " );
        $stmt->bind_param("sii", $new_status, $admin_id, $res_id);
    } elseif($has_verified_at_col){
        $stmt = $conn->prepare("
            UPDATE reservations
            SET id_verification_status = ?,
                verified_at = NOW()
            WHERE id = ?
        " );
        $stmt->bind_param("si", $new_status, $res_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE reservations
            SET id_verification_status = ?
            WHERE id = ?
        " );
        $stmt->bind_param("si", $new_status, $res_id);
    }

    if($stmt && $stmt->execute()){
        $r = $conn->query("SELECT user_id FROM reservations WHERE id = $res_id")->fetch_assoc();
        if ($r) {
            $notif_title = 'ID Verification Updated';
            $notif_msg = 'Your ID verification status is now: ' . $new_status . '.';
            $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
            if($notif_stmt){
                $notif_stmt->bind_param("iss", $r['user_id'], $notif_title, $notif_msg);
                $notif_stmt->execute();
            }
        }

        header("Location: reservation.php?msg=id_verified");
        exit();
    }

    header("Location: reservation.php?msg=id_update_failed");
    exit();
}

// --- UPLOAD SIGNED CONTRACT ACTION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_contract'])) {
    $res_id = intval($_POST['res_id']);

    if(!$has_contract_file_col){
        header("Location: reservation.php?msg=contract_column_missing");
        exit();
    }

    if(!isset($_FILES['contract_file']) || $_FILES['contract_file']['error'] !== UPLOAD_ERR_OK){
        header("Location: reservation.php?msg=contract_upload_failed");
        exit();
    }

    $allowed_ext = ['pdf','jpg','jpeg','png'];
    $file_name = $_FILES['contract_file']['name'];
    $file_tmp = $_FILES['contract_file']['tmp_name'];
    $file_size = $_FILES['contract_file']['size'];
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    if(!in_array($ext, $allowed_ext)){
        header("Location: reservation.php?msg=contract_invalid");
        exit();
    }

    if($file_size > 10 * 1024 * 1024){
        header("Location: reservation.php?msg=contract_large");
        exit();
    }

    $r = $conn->query("SELECT r.user_id, r.status, u.fullname, l.block_no, l.lot_no FROM reservations r JOIN users u ON r.user_id = u.id JOIN lots l ON r.lot_id = l.id WHERE r.id = $res_id")->fetch_assoc();
    if(!$r){
        header("Location: reservation.php?msg=contract_not_found");
        exit();
    }

    // Recommended flow: allow contract upload only after APPROVED reservation.
    if($r['status'] !== 'APPROVED'){
        header("Location: reservation.php?msg=contract_not_approved");
        exit();
    }

    if(!is_dir(__DIR__ . '/storage/uploads/contracts')){
        mkdir(__DIR__ . '/storage/uploads/contracts', 0777, true);
    }

    $safe_name = 'contract_res_' . $res_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destination = 'storage/uploads/contracts/' . $safe_name;

    if(move_uploaded_file($file_tmp, $destination)){
        if($has_contract_uploaded_at_col){
            $stmt = $conn->prepare("UPDATE reservations SET contract_file = ?, contract_uploaded_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $destination, $res_id);
        } else {
            $stmt = $conn->prepare("UPDATE reservations SET contract_file = ? WHERE id = ?");
            $stmt->bind_param("si", $destination, $res_id);
        }
        $stmt->execute();

        // Notify buyer that signed contract is now available.
        $notif_title = "Signed Contract Available";
        $notif_msg = "Your signed contract for Block {$r['block_no']} Lot {$r['lot_no']} is now available for download in your dashboard.";
        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
        if($notif_stmt){
            $notif_stmt->bind_param("iss", $r['user_id'], $notif_title, $notif_msg);
            $notif_stmt->execute();
        }

        header("Location: reservation.php?msg=contract_uploaded");
        exit();
    }

    header("Location: reservation.php?msg=contract_upload_failed");
    exit();
}

// --- EDITABLE DP APPROVAL ACTION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['approve_with_dp'])) {
    $res_id = intval($_POST['res_id']);
    $lot_id = intval($_POST['lot_id']);
    $required_dp = 0;

    // Compute Required DP from the same real-estate flow used in buyer dashboard:
    // Original TCP - cash discount - reservation fee = Net TCP, then 20% DP.
    $dpCalcRes = $conn->query("
        SELECT r.*, l.total_price, l.area, l.price_per_sqm, l.location, l.classification
        FROM reservations r
        JOIN lots l ON r.lot_id = l.id
        WHERE r.id = $res_id
        LIMIT 1
    ");

    $approve_payment_label = 'Required Down Payment';
    $approve_payment_amount = 0;
    $approve_is_spot_cash = false;
    $approve_final_tcp = 0.00;
    $approve_monthly_payment = 0.00;
    $approve_payment_type_db = 'INSTALLMENT';

    if($dpCalcRes && $dpCalcRes->num_rows > 0){
        $dpCalcRow = $dpCalcRes->fetch_assoc();

        $current_res_status = strtoupper(trim($dpCalcRow['status'] ?? 'PENDING'));
        if($current_res_status === 'CANCELLED'){
            header("Location: reservation.php?status=CANCELLED&msg=cancelled_no_action");
            exit();
        }
        if($current_res_status !== 'PENDING'){
            header("Location: reservation.php?msg=not_pending");
            exit();
        }

        $dpCalc = computeRequiredDPFromReservation($dpCalcRow);

        // Database required_dp remains 0 for Spot Cash because there is no DP.
        // The modal/payment instruction still shows the full Spot Cash balance to pay.
        $required_dp = (float)$dpCalc['required_dp'];
        $approve_is_spot_cash = ((int)($dpCalc['is_spot_cash'] ?? 0) === 1);
        $approve_payment_label = $approve_is_spot_cash ? 'Spot Cash Balance' : 'Required Down Payment';
        $approve_payment_amount = $approve_is_spot_cash
            ? (float)$dpCalc['net_tcp_after_reservation']
            : (float)$required_dp;
        $approve_final_tcp = (float)($dpCalc['tcp_after_discount'] ?? 0);
        $approve_monthly_payment = (float)($dpCalc['monthly_payment'] ?? 0);
        $approve_payment_type_db = $approve_is_spot_cash ? 'CASH' : 'INSTALLMENT';
    } else {
        $required_dp = floatval($_POST['required_dp'] ?? 0);
        $approve_payment_amount = $required_dp;
    }

    // Modern KYC flow: do not approve until ID has been manually verified.
    if($has_id_verification_status_col){
        $kyc = $conn->query("SELECT id_verification_status, verified_by, verified_at FROM reservations WHERE id = $res_id")->fetch_assoc();
        $kyc_status = strtoupper(trim($kyc['id_verification_status'] ?? 'PENDING REVIEW'));
        $kyc_saved_ok = ($kyc_status === 'MANUALLY VERIFIED');
        if($has_verified_at_col && empty($kyc['verified_at'] ?? null)) $kyc_saved_ok = false;
        if($has_verified_by_col && empty($kyc['verified_by'] ?? null)) $kyc_saved_ok = false;
        if(!$kyc_saved_ok){
            header("Location: reservation.php?msg=id_not_verified");
            exit();
        }
    }
    
    // Generate one unique account number per approved reservation/lot.
    // Same buyer can have multiple account numbers if they bought multiple lots.
    $account_number = 'JEJ-' . date('Y') . '-' . str_pad($res_id, 4, '0', STR_PAD_LEFT);

    // Update to Approved and lock the current pricing computation on the reservation.
    if($has_account_number_col){
        $stmt = $conn->prepare("UPDATE reservations SET status = 'APPROVED', payment_type = ?, required_dp = ?, monthly_payment = ?, tcp_after_discount = ?, net_tcp_after_reservation = ?, account_number = IF(account_number IS NULL OR account_number = '', ?, account_number) WHERE id = ?");
        $stmt->bind_param("sddddsi", $approve_payment_type_db, $required_dp, $approve_monthly_payment, $approve_final_tcp, $approve_final_tcp, $account_number, $res_id);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("UPDATE reservations SET status = 'APPROVED', payment_type = ?, required_dp = ?, monthly_payment = ?, tcp_after_discount = ?, net_tcp_after_reservation = ? WHERE id = ?");
        $stmt->bind_param("sddddi", $approve_payment_type_db, $required_dp, $approve_monthly_payment, $approve_final_tcp, $approve_final_tcp, $res_id);
        $stmt->execute();
    }
    
    // Notify Buyer
    $r = $conn->query("SELECT user_id FROM reservations WHERE id = $res_id")->fetch_assoc();
    if ($r) {
        $uid = $r['user_id'];
        if($approve_is_spot_cash){
            $msg = "Your reservation has been approved. Your Spot Cash Balance is ₱" . number_format($approve_payment_amount, 2) . ". Please settle it within 20 days to secure your property.";
        } else {
            $msg = "Your reservation has been approved. Your Required Down Payment is ₱" . number_format($approve_payment_amount, 2) . ". Please settle it online to secure your property.";
        }
        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'Reservation Approved', ?)");
        $notif_stmt->bind_param("is", $uid, $msg);
        $notif_stmt->execute();
    }
    
    header("Location: reservation.php?msg=approved");
    exit();
}

// --- VERIFY DOWN PAYMENT ACTION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_dp'])) {
    $res_id = intval($_POST['res_id']);
    
    // Update the DP status to PAID
    $conn->query("UPDATE reservations SET dp_status = 'PAID' WHERE id = $res_id");
    
    // Notify the buyer
    $r = $conn->query("SELECT user_id FROM reservations WHERE id = $res_id")->fetch_assoc();
    if ($r) {
        $uid = $r['user_id'];
        $notif_title = "Down Payment Verified";
        $notif_msg = "Your online GCash/Maya down payment has been successfully verified by our admin. Thank you!";
        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
        $notif_stmt->bind_param("iss", $uid, $notif_title, $notif_msg);
        $notif_stmt->execute();
    }
    
    header("Location: reservation.php?msg=dp_verified");
    exit();
}

// --- REJECT RESERVATION ACTION (Does not delete, just updates status) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reject_res'])) {
    $res_id = intval($_POST['res_id']);
    
    $conn->query("UPDATE reservations SET status = 'REJECTED' WHERE id = $res_id");
    
    header("Location: reservation.php?msg=rejected");
    exit();
}

// --- RESTORE RESERVATION ACTION (From Rejected back to Pending) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['restore_res'])) {
    $res_id = intval($_POST['res_id']);

    $restoreStatusRow = $conn->query("SELECT status FROM reservations WHERE id = $res_id LIMIT 1")->fetch_assoc();
    if(strtoupper(trim($restoreStatusRow['status'] ?? '')) !== 'REJECTED'){
        header("Location: reservation.php?msg=restore_rejected_only");
        exit();
    }

    $conn->query("UPDATE reservations SET status = 'PENDING' WHERE id = $res_id");
    
    header("Location: reservation.php?msg=restored");
    exit();
}

// --- DELETE RESERVATION ACTION (Permanently deletes and frees the lot to inventory) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_res'])) {
    $res_id = intval($_POST['res_id']);

    $deleteStatusRow = $conn->query("SELECT status FROM reservations WHERE id = $res_id LIMIT 1")->fetch_assoc();
    if(strtoupper(trim($deleteStatusRow['status'] ?? '')) === 'CANCELLED'){
        header("Location: reservation.php?status=CANCELLED&msg=cancelled_no_delete");
        exit();
    }
    if(strtoupper(trim($deleteStatusRow['status'] ?? '')) !== 'REJECTED'){
        header("Location: reservation.php?msg=delete_rejected_only");
        exit();
    }

    // Fetch the lot ID to return it to the inventory
    $r = $conn->query("SELECT lot_id FROM reservations WHERE id = $res_id")->fetch_assoc();
    if ($r) {
        $lot_id = $r['lot_id'];
        // Repost in inventory
        $conn->query("UPDATE lots SET status='AVAILABLE', fullname=NULL, buyer_contact=NULL, email=NULL, address=NULL WHERE id='$lot_id'");
    }
    
    // Delete the reservation completely
    $conn->query("DELETE FROM reservations WHERE id = $res_id");
    
    header("Location: reservation.php?msg=deleted");
    exit();
}

// --- ACCEPT BUYER CANCELLATION REQUEST ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accept_cancellation'])) {
    $res_id = intval($_POST['res_id'] ?? 0);
    $admin_id = (int)($_SESSION['user_id'] ?? 0);
    $admin_note = reservationCleanText($_POST['cancellation_admin_note'] ?? 'Cancellation accepted by admin.');

    if(!$has_cancellation_status_col || !$has_cancellation_requested_at_col || !$has_cancellation_reason_col){
        header("Location: reservation.php?status=CANCELLATION_REQUESTS&msg=cancellation_columns_missing");
        exit();
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("
            SELECT r.id, r.user_id, r.lot_id, r.status, r.cancellation_status, r.cancellation_reason,
                   l.block_no, l.lot_no, l.location
            FROM reservations r
            JOIN lots l ON l.id = r.lot_id
            WHERE r.id = ?
            LIMIT 1
            FOR UPDATE
        ");
        if(!$stmt) throw new Exception('Unable to load cancellation request.');

        $stmt->bind_param("i", $res_id);
        $stmt->execute();
        $requestRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if(!$requestRow) throw new Exception('Reservation not found.');
        $requestStatus = strtoupper(trim($requestRow['status'] ?? ''));
        if(!in_array($requestStatus, ['PENDING', 'APPROVED'], true)) {
            throw new Exception('Only pending or approved reservations can be accepted as cancellation requests.');
        }
        if(strtoupper(trim($requestRow['cancellation_status'] ?? '')) !== 'PENDING') {
            throw new Exception('This reservation has no pending cancellation request.');
        }

        $setParts = ["status = 'CANCELLED'", "cancellation_status = 'ACCEPTED'"];
        $types = '';
        $values = [];

        if($has_cancelled_by_col){
            $setParts[] = "cancelled_by = ?";
            $types .= 'i';
            $values[] = (int)$requestRow['user_id'];
        }
        if($has_cancelled_at_col){
            $setParts[] = "cancelled_at = NOW()";
        }
        if($has_cancellation_action_by_col){
            $setParts[] = "cancellation_action_by = ?";
            $types .= 'i';
            $values[] = $admin_id;
        }
        if($has_cancellation_action_at_col){
            $setParts[] = "cancellation_action_at = NOW()";
        }
        if($has_cancellation_admin_note_col){
            $setParts[] = "cancellation_admin_note = ?";
            $types .= 's';
            $values[] = $admin_note;
        }

        $types .= 'i';
        $values[] = $res_id;

        $update = $conn->prepare("UPDATE reservations SET " . implode(', ', $setParts) . " WHERE id = ?");
        if(!$update) throw new Exception('Unable to accept cancellation request.');

        $update->bind_param($types, ...$values);
        $update->execute();
        if($update->affected_rows < 1) throw new Exception('Cancellation request was not updated.');
        $update->close();

        reservationReleaseLotIfSafe($conn, (int)$requestRow['lot_id']);

        $buyerMsg = "Your cancellation request for Block {$requestRow['block_no']} Lot {$requestRow['lot_no']} at {$requestRow['location']} has been accepted. The reservation is now cancelled.";
        reservationNotifyUser($conn, (int)$requestRow['user_id'], 'Cancellation Accepted', $buyerMsg);

        if(function_exists('add_audit_log')){
            add_audit_log($conn, $admin_id, 'Accepted Cancellation Request', 'Reservation #' . $res_id . ' cancellation accepted. Note: ' . $admin_note, 'reservations', $res_id);
        }

        $conn->commit();
        header("Location: reservation.php?status=CANCELLED&msg=cancellation_accepted");
        exit();
    } catch (Throwable $e) {
        $conn->rollback();
        header("Location: reservation.php?status=CANCELLATION_REQUESTS&msg=cancellation_action_failed");
        exit();
    }
}

// --- REJECT BUYER CANCELLATION REQUEST ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reject_cancellation'])) {
    $res_id = intval($_POST['res_id'] ?? 0);
    $admin_id = (int)($_SESSION['user_id'] ?? 0);
    $admin_note = reservationCleanText($_POST['cancellation_admin_note'] ?? 'Cancellation request rejected by admin.');

    if(!$has_cancellation_status_col){
        header("Location: reservation.php?status=CANCELLATION_REQUESTS&msg=cancellation_columns_missing");
        exit();
    }

    $stmt = $conn->prepare("
        SELECT r.user_id, r.status, r.cancellation_status, l.block_no, l.lot_no, l.location
        FROM reservations r
        JOIN lots l ON l.id = r.lot_id
        WHERE r.id = ?
        LIMIT 1
    ");
    if(!$stmt){
        header("Location: reservation.php?status=CANCELLATION_REQUESTS&msg=cancellation_action_failed");
        exit();
    }
    $stmt->bind_param("i", $res_id);
    $stmt->execute();
    $requestRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$requestRow){
        header("Location: reservation.php?status=CANCELLATION_REQUESTS&msg=cancellation_action_failed");
        exit();
    }

    $requestStatus = strtoupper(trim($requestRow['status'] ?? ''));
    if(!in_array($requestStatus, ['PENDING', 'APPROVED'], true) || strtoupper(trim($requestRow['cancellation_status'] ?? '')) !== 'PENDING'){
        header("Location: reservation.php?status=CANCELLATION_REQUESTS&msg=cancellation_action_failed");
        exit();
    }

    $setParts = ["cancellation_status = 'REJECTED'"];
    $types = '';
    $values = [];
    if($has_cancellation_action_by_col){
        $setParts[] = "cancellation_action_by = ?";
        $types .= 'i';
        $values[] = $admin_id;
    }
    if($has_cancellation_action_at_col){
        $setParts[] = "cancellation_action_at = NOW()";
    }
    if($has_cancellation_admin_note_col){
        $setParts[] = "cancellation_admin_note = ?";
        $types .= 's';
        $values[] = $admin_note;
    }
    $types .= 'i';
    $values[] = $res_id;

    $update = $conn->prepare("UPDATE reservations SET " . implode(', ', $setParts) . " WHERE id = ?");
    if($update){
        $update->bind_param($types, ...$values);
        $update->execute();
        $update->close();

        $buyerMsg = "Your cancellation request for Block {$requestRow['block_no']} Lot {$requestRow['lot_no']} at {$requestRow['location']} was not approved. Admin note: {$admin_note}";
        reservationNotifyUser($conn, (int)$requestRow['user_id'], 'Cancellation Request Declined', $buyerMsg);

        if(function_exists('add_audit_log')){
            add_audit_log($conn, $admin_id, 'Rejected Cancellation Request', 'Reservation #' . $res_id . ' cancellation request rejected. Note: ' . $admin_note, 'reservations', $res_id);
        }

        $returnStatus = ($requestStatus === 'PENDING') ? 'PENDING' : 'APPROVED';
        header("Location: reservation.php?status={$returnStatus}&msg=cancellation_rejected");
        exit();
    }

    header("Location: reservation.php?status=CANCELLATION_REQUESTS&msg=cancellation_action_failed");
    exit();
}

// Notification Check
$unread_count = 0;
if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $notif_stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    if ($notif_stmt) {
        $notif_stmt->bind_param("i", $uid);
        $notif_stmt->execute();
        $notif_stmt->bind_result($unread_count);
        $notif_stmt->fetch();
        $notif_stmt->close();
    }
}

$status_filter = strtoupper(trim($_GET['status'] ?? 'ACTION_NEEDED'));
$allowed_status_filters = ['ACTION_NEEDED', 'ALL', 'PENDING', 'APPROVED', 'REJECTED', 'CANCELLED', 'CANCELLATION_REQUESTS', 'HISTORY'];
if(!in_array($status_filter, $allowed_status_filters, true)){
    $status_filter = 'ACTION_NEEDED';
}

$where_sql = "1";
$contract_missing_sql = $has_contract_file_col ? "(r.contract_file IS NULL OR TRIM(r.contract_file) = '')" : "1";
$id_needs_review_sql = $has_id_verification_status_col ? "UPPER(COALESCE(r.id_verification_status, 'PENDING REVIEW')) <> 'MANUALLY VERIFIED'" : "1";
$pending_cancellation_sql = $has_cancellation_status_col ? "(UPPER(COALESCE(r.cancellation_status, '')) = 'PENDING' AND UPPER(r.status) IN ('PENDING', 'APPROVED'))" : "0";
if($status_filter === 'ACTION_NEEDED'){
    $where_sql = "(
        $pending_cancellation_sql
        OR UPPER(r.status) IN ('PENDING', 'APPROVED')
    )";
} elseif($status_filter === 'CANCELLATION_REQUESTS'){
    $where_sql = $has_cancellation_status_col ? "UPPER(COALESCE(r.cancellation_status, '')) = 'PENDING'" : "0";
} elseif($status_filter === 'HISTORY'){
    $where_sql = "(
        UPPER(r.status) IN ('CANCELLED', 'REJECTED')
        OR UPPER(r.status) = 'APPROVED'
    )";
} elseif($status_filter != 'ALL'){
    $safe_status_filter = $conn->real_escape_string($status_filter);
    $where_sql = "UPPER(r.status) = '$safe_status_filter'";
}

$alert_msg = "";
$alert_type = "";
if(isset($_GET['msg'])){
    if($_GET['msg'] == 'approved') { $alert_msg = "Reservation approved with updated Down Payment Requirements!"; $alert_type = "success"; }
    if($_GET['msg'] == 'rejected') { $alert_msg = "Reservation rejected. It has been moved to the Rejected tab."; $alert_type = "error"; }
    if($_GET['msg'] == 'dp_verified') { $alert_msg = "Down Payment Receipt Verified and Marked as Paid!"; $alert_type = "success"; }
    if($_GET['msg'] == 'deleted') { $alert_msg = "Reservation deleted and lot successfully returned to available inventory."; $alert_type = "success"; }
    if($_GET['msg'] == 'restored') { $alert_msg = "Reservation successfully restored to Pending status."; $alert_type = "success"; }
    if($_GET['msg'] == 'contract_uploaded') { $alert_msg = "Signed contract successfully uploaded. Buyer can now download it from the buyer dashboard."; $alert_type = "success"; }
    if($_GET['msg'] == 'contract_column_missing') { $alert_msg = "Contract upload column is missing. Please run the contract SQL update first."; $alert_type = "error"; }
    if($_GET['msg'] == 'contract_upload_failed') { $alert_msg = "Contract upload failed. Please try again."; $alert_type = "error"; }
    if($_GET['msg'] == 'contract_invalid') { $alert_msg = "Invalid contract file. Please upload PDF, JPG, JPEG, or PNG only."; $alert_type = "error"; }
    if($_GET['msg'] == 'contract_large') { $alert_msg = "Contract file is too large. Maximum file size is 10MB."; $alert_type = "error"; }
    if($_GET['msg'] == 'contract_not_approved') { $alert_msg = "Contract can only be uploaded after reservation is approved."; $alert_type = "error"; }
    if($_GET['msg'] == 'contract_not_found') { $alert_msg = "Reservation not found for contract upload."; $alert_type = "error"; }
    if($_GET['msg'] == 'account_column_missing') { $alert_msg = "Account number column is missing. Please run the account number SQL update first."; $alert_type = "error"; }
    if($_GET['msg'] == 'id_verified') { $alert_msg = "ID verification status updated successfully."; $alert_type = "success"; }
    if($_GET['msg'] == 'id_update_failed') { $alert_msg = "Failed to update ID verification status. Please try again."; $alert_type = "error"; }
    if($_GET['msg'] == 'id_column_missing') { $alert_msg = "ID verification columns are missing. Please run the ID verification SQL update first."; $alert_type = "error"; }
    if($_GET['msg'] == 'id_not_verified') { $alert_msg = "Reservation cannot be approved yet. Please set ID Verification to MANUALLY VERIFIED first."; $alert_type = "error"; }
    if($_GET['msg'] == 'cancelled_no_action') { $alert_msg = "This reservation is cancelled. Admin actions are locked for audit only."; $alert_type = "error"; }
    if($_GET['msg'] == 'not_pending') { $alert_msg = "This action is allowed only for pending reservations."; $alert_type = "error"; }
    if($_GET['msg'] == 'cancelled_no_delete') { $alert_msg = "Cancelled buyer records are kept for audit and cannot be deleted here."; $alert_type = "error"; }
    if($_GET['msg'] == 'restore_rejected_only') { $alert_msg = "Only rejected reservations can be restored to pending."; $alert_type = "error"; }
    if($_GET['msg'] == 'delete_rejected_only') { $alert_msg = "Only rejected reservations can be permanently deleted from this page."; $alert_type = "error"; }
    if($_GET['msg'] == 'cancellation_columns_missing') { $alert_msg = "Cancellation request columns are missing. Please run the database SQL update first."; $alert_type = "error"; }
    if($_GET['msg'] == 'cancellation_accepted') { $alert_msg = "Cancellation request accepted. Reservation moved to Cancelled and the lot was returned to AVAILABLE if safe."; $alert_type = "success"; }
    if($_GET['msg'] == 'cancellation_rejected') { $alert_msg = "Cancellation request rejected. Reservation remains approved."; $alert_type = "success"; }
    if($_GET['msg'] == 'cancellation_action_failed') { $alert_msg = "Unable to process cancellation request. Please refresh and try again."; $alert_type = "error"; }
}


function getReservationFileValue($row, $keys) {
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }
    return '';
}

function reservationDocCandidatePaths($file) {
    $file = trim((string)$file);
    if ($file === '') return [];

    $file = str_replace('\\', '/', $file);
    $file = ltrim($file, '/');

    while (strpos($file, 'uploads/uploads/') === 0) {
        $file = substr($file, strlen('uploads/'));
    }

    $base = basename($file);

    $candidates = [];

    // Existing records may already contain a relative path.
    if ($file !== $base) {
        $candidates[] = $file;
    }

    // Current JEJ local storage structure:
    // C:/xampp/htdocs/JEJ_V_6.4/storage/uploads/...
    $folders = [
        'storage/uploads/payment_proofs',
        'storage/uploads/reservation_proofs',
        'storage/uploads/valid_ids',
        'storage/uploads/selfies',
        'storage/uploads/contracts',
        'storage/uploads/DOCS',
        'storage/uploads/receipts',
        'storage/uploads/profiles',
        'storage/uploads'
    ];

    foreach ($folders as $folder) {
        $candidates[] = $folder . '/' . $base;
    }

    // Backward compatibility for old public uploads records.
    $oldFolders = [
        'uploads/payment_proofs',
        'uploads/reservation_proofs',
        'uploads/valid_ids',
        'uploads/selfies',
        'uploads/contracts',
        'uploads/DOCS',
        'uploads'
    ];

    foreach ($oldFolders as $folder) {
        $candidates[] = $folder . '/' . $base;
    }

    return array_values(array_unique($candidates));
}

function reservationDocSrc($file) {
    $file = trim((string)$file);
    if ($file === '') return '';

    // External URL or base64 image can be used directly.
    if (
        preg_match('/^https?:\/\//i', $file) ||
        preg_match('/^data:image\//i', $file)
    ) {
        return $file;
    }

    foreach (reservationDocCandidatePaths($file) as $path) {
        if (file_exists(__DIR__ . '/' . $path)) {
            return $path;
        }
    }

    // Fallback to current storage location so missing-file tooltip is accurate.
    $base = basename(str_replace('\\', '/', $file));
    return 'storage/uploads/' . $base;
}

function reservationDocExists($src) {
    $src = trim((string)$src);
    if ($src === '') return false;

    if (
        preg_match('/^https?:\/\//i', $src) ||
        preg_match('/^data:image\//i', $src)
    ) {
        return true;
    }

    foreach (reservationDocCandidatePaths($src) as $path) {
        if (file_exists(__DIR__ . '/' . $path)) {
            return true;
        }
    }

    return false;
}


function reservationNumberValue($row, $keys, $default = 0) {
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '' && $row[$key] !== null) {
            return (float)$row[$key];
        }
    }
    return (float)$default;
}


function reservationTableHasColumn($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEscaped = $conn->real_escape_string($column);
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$columnEscaped'");
    return ($check && $check->num_rows > 0);
}

function getReservationVerifiedPaymentTotal($conn, $reservation_id) {
    $reservation_id = (int)$reservation_id;
    if($reservation_id <= 0) return 0.00;

    $tableCheck = $conn->query("SHOW TABLES LIKE 'transactions'");
    if(!$tableCheck || $tableCheck->num_rows == 0) return 0.00;

    $amountCol = null;
    foreach(['amount', 'amount_paid', 'payment_amount', 'total_amount'] as $col){
        if(reservationTableHasColumn($conn, 'transactions', $col)){
            $amountCol = $col;
            break;
        }
    }
    if(!$amountCol) return 0.00;

    $reservationCol = null;
    foreach(['reservation_id', 'res_id', 'reservation'] as $col){
        if(reservationTableHasColumn($conn, 'transactions', $col)){
            $reservationCol = $col;
            break;
        }
    }
    if(!$reservationCol) return 0.00;

    // Count VERIFIED payments only. Pending/rejected/cancelled/void records are intentionally excluded.
    $statusColUsed = null;
    foreach(['payment_status', 'verification_status', 'status'] as $statusCol){
        if(reservationTableHasColumn($conn, 'transactions', $statusCol)){
            $statusColUsed = $statusCol;
            break;
        }
    }
    if(!$statusColUsed) return 0.00;

    $typeCondition = reservationTableHasColumn($conn, 'transactions', 'type')
        ? " AND UPPER(`type`) = 'INCOME'"
        : "";

    $sql = "SELECT COALESCE(SUM(`$amountCol`),0) AS paid_total
            FROM transactions
            WHERE `$reservationCol` = $reservation_id
            $typeCondition
            AND UPPER(`$statusColUsed`) = 'VERIFIED'";
    $result = $conn->query($sql);
    if(!$result) return 0.00;

    $row = $result->fetch_assoc();
    return (float)($row['paid_total'] ?? 0);
}

function getReservationVerifiedPaymentByKeyword($conn, $reservation_id, $keyword) {
    $reservation_id = (int)$reservation_id;
    $keyword = trim((string)$keyword);
    if($reservation_id <= 0 || $keyword === '') return 0.00;

    if(!reservationTableHasColumn($conn, 'transactions', 'reservation_id') ||
       !reservationTableHasColumn($conn, 'transactions', 'amount') ||
       !reservationTableHasColumn($conn, 'transactions', 'description')) {
        return 0.00;
    }

    // Count VERIFIED payments only. Do not count pending, rejected, cancelled, or voided proof uploads.
    $statusColUsed = null;
    foreach(['payment_status', 'verification_status', 'status'] as $statusCol){
        if(reservationTableHasColumn($conn, 'transactions', $statusCol)){
            $statusColUsed = $statusCol;
            break;
        }
    }
    if(!$statusColUsed) return 0.00;

    $typeCondition = reservationTableHasColumn($conn, 'transactions', 'type')
        ? "AND UPPER(type) = 'INCOME'"
        : "";

    $like = '%' . $keyword . '%Res#' . $reservation_id . '%';
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount),0) AS paid_total
        FROM transactions
        WHERE reservation_id = ?
        $typeCondition
        AND UPPER(`$statusColUsed`) = 'VERIFIED'
        AND description LIKE ?
    ");
    if(!$stmt) return 0.00;
    $stmt->bind_param('is', $reservation_id, $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (float)($row['paid_total'] ?? 0);
}

function reservationPaymentType($row) {
    // Accept all possible field names from lot_details.php / manual buyer entry.
    foreach (['payment_type', 'payment_category', 'payment_terms', 'terms_type', 'payment_option', 'payment_method'] as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return strtoupper(trim((string)$row[$key]));
        }
    }
    return '';
}

function computeRequiredDPFromReservation($row) {
    global $conn;

    $reservation_fee = reservationNumberValue($row, ['reservation_fee', 'reservation_payment', 'reservation_amount', 'reservation_fee_paid'], 5000);
    $months = (int)reservationNumberValue($row, ['installment_months'], 36);
    if ($months < 12) $months = 12;
    if ($months > 36) $months = 36;

    $payment_option = $row['payment_scheme'] ?? $row['payment_option'] ?? $row['payment_terms'] ?? $row['payment_type'] ?? 'SPOT_CASH';
    $calc = jej_compute_payment_pricing($conn, $row, $payment_option, $months, $reservation_fee);

    // If this is an old row without pricing_matrix columns in the SELECT, fall back to saved reservation figures.
    $saved_final_tcp = reservationNumberValue($row, [
        'tcp_after_discount',
        'discounted_total_price',
        'discounted_tcp',
        'total_after_discount',
        'net_tcp_after_reservation'
    ], 0);
    if ($saved_final_tcp > 0 && empty($row['classification']) && empty($row['location'])) {
        $calc['final_tcp'] = $saved_final_tcp;
        $calc['tcp_after_discount'] = $saved_final_tcp;
        $calc['net_tcp_after_reservation'] = $saved_final_tcp;
        $calc['required_dp'] = ($calc['is_spot_cash'] ? 0.00 : round($saved_final_tcp * 0.20, 2));
        $calc['balance_to_finance'] = $calc['is_spot_cash'] ? $saved_final_tcp : max(0, $saved_final_tcp - $calc['required_dp']);
    }

    $approval_label = $calc['is_spot_cash'] ? 'Full Spot Cash Balance' : 'Required DP 20%';
    $approval_amount = $calc['is_spot_cash'] ? (float)$calc['final_tcp'] : (float)$calc['required_dp'];
    $formula_text = $calc['is_spot_cash']
        ? 'Spot Cash: cash price per sqm × lot area. No DP. Reservation fee is recorded separately.'
        : 'Installment/Straight Payment: updated price per sqm × lot area, then 20% DP. Reservation fee is recorded separately.';

    return [
        'original_tcp' => (float)$calc['cash_tcp'],
        'payment_type' => $payment_option,
        'payment_code' => $calc['payment_code'],
        'payment_label' => $calc['payment_label'],
        'discount_percent' => 0.00,
        'discount_amount' => 0.00,
        'additional_per_sqm' => (float)$calc['additional_per_sqm'],
        'additional_amount' => (float)$calc['additional_amount'],
        'reservation_fee' => (float)$calc['reservation_fee'],
        'tcp_after_discount' => (float)$calc['final_tcp'],
        'net_tcp_after_reservation' => (float)$calc['net_tcp_after_reservation'],
        'required_dp' => (float)$calc['required_dp'],
        'balance_to_finance' => (float)$calc['balance_to_finance'],
        'monthly_payment' => (float)$calc['monthly_payment'],
        'approval_label' => $approval_label,
        'approval_amount' => $approval_amount,
        'formula_text' => $formula_text,
        'is_spot_cash' => (int)$calc['is_spot_cash'],
        'is_installment' => (int)$calc['is_installment'],
        'is_straight_payment' => (int)$calc['is_straight_payment'],
        'selected_price_per_sqm' => (float)$calc['selected_price_per_sqm'],
        'cash_price_per_sqm' => (float)$calc['cash_price_per_sqm'],
        'installment_price_per_sqm' => (float)$calc['installment_price_per_sqm'],
        'straight_price_per_sqm' => (float)$calc['straight_price_per_sqm'],
    ];
}

function reservationBuildStatusHistory($row, $id_status, $payment_complete_for_row, $contract_uploaded, $is_cancelled_row, $cancelled_at_text, $cancelled_by_label, $cancellation_reason_text) {
    $history = [];

    if(!empty($row['reservation_date'])){
        $history[] = [
            'label' => 'Reservation submitted',
            'date' => date('M d, Y', strtotime($row['reservation_date'])),
            'note' => 'Buyer submitted the reservation request and uploaded required documents.'
        ];
    }

    $history[] = [
        'label' => 'ID status: ' . ucwords(strtolower($id_status)),
        'date' => !empty($row['verified_at']) ? date('M d, Y', strtotime($row['verified_at'])) : 'Pending',
        'note' => ($id_status === 'MANUALLY VERIFIED') ? 'ID/selfie verification was saved by admin.' : 'ID/selfie verification is not yet fully approved.'
    ];

    if(strtoupper(trim($row['status'] ?? '')) === 'APPROVED'){
        $history[] = [
            'label' => 'Reservation approved',
            'date' => !empty($row['approved_at']) ? date('M d, Y', strtotime($row['approved_at'])) : 'Approved',
            'note' => 'Account number and payment terms are active.'
        ];
    }

    $history[] = [
        'label' => $payment_complete_for_row ? 'Payment verified / completed' : 'Payment verification pending',
        'date' => $payment_complete_for_row ? 'Verified' : 'Pending',
        'note' => $payment_complete_for_row ? 'Computed using verified transaction records only.' : 'Pending, rejected, cancelled, and void payment proofs are not counted.'
    ];

    if($contract_uploaded){
        $history[] = [
            'label' => 'Signed contract uploaded',
            'date' => !empty($row['contract_uploaded_at']) ? date('M d, Y', strtotime($row['contract_uploaded_at'])) : 'Uploaded',
            'note' => 'Buyer can view/download the signed contract.'
        ];
    } else {
        $history[] = [
            'label' => 'Contract awaiting upload',
            'date' => 'Pending',
            'note' => 'Upload is available only after approval.'
        ];
    }

    if($is_cancelled_row){
        $history[] = [
            'label' => 'Reservation cancelled',
            'date' => $cancelled_at_text ?: 'Cancelled',
            'note' => 'Cancelled by: ' . $cancelled_by_label . '. Reason: ' . $cancellation_reason_text
        ];
    }

    return $history;
}

function reservationJsonForAttribute($value) {
    return htmlspecialchars(
        json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        ENT_QUOTES,
        'UTF-8'
    );
}

// --- OPTIONAL PROJECT NAME SUPPORT ---
// If your database has projects table + lots.project_id, show project name above location.
// If not available, the page will still work and only show location.
$project_select_sql = "'' AS project_name";
$project_join_sql = "";

$projectsTableCheck = $conn->query("SHOW TABLES LIKE 'projects'");
if($projectsTableCheck && $projectsTableCheck->num_rows > 0 && reservationTableHasColumn($conn, 'lots', 'project_id')){
    $projectNameCol = '';
    foreach(['project_name', 'name', 'title'] as $possibleProjectCol){
        if(reservationTableHasColumn($conn, 'projects', $possibleProjectCol)){
            $projectNameCol = $possibleProjectCol;
            break;
        }
    }

    if($projectNameCol !== ''){
        $project_select_sql = "p.`$projectNameCol` AS project_name";
        $project_join_sql = " LEFT JOIN projects p ON l.project_id = p.id ";
    }
}

// Fetch Reservations
$query = "SELECT r.*, u.fullname, u.email as user_email,
                 l.block_no, l.lot_no, l.total_price, l.area, l.price_per_sqm, l.location, l.classification,
                 $project_select_sql
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          JOIN lots l ON r.lot_id = l.id
          $project_join_sql
          WHERE $where_sql
          ORDER BY r.reservation_date DESC";

$res = $conn->query($query);
$displayReservationRows = [];
if($res){
    while($displayRow = $res->fetch_assoc()){
        if($status_filter === 'ACTION_NEEDED' || $status_filter === 'HISTORY'){
            $displayStatus = strtoupper(trim($displayRow['status'] ?? 'PENDING'));
            $displayCancellationStatus = strtoupper(trim($displayRow['cancellation_status'] ?? ''));
            $displayHasPendingCancellation = (in_array($displayStatus, ['PENDING', 'APPROVED'], true) && $displayCancellationStatus === 'PENDING');
            $displayContractUploaded = !empty($displayRow['contract_file']);
            $displayIdStatus = strtoupper(trim($displayRow['id_verification_status'] ?? 'PENDING REVIEW'));

            $displayCalc = computeRequiredDPFromReservation($displayRow);
            $displayVerifiedTotal = getReservationVerifiedPaymentTotal($conn, (int)$displayRow['id']);
            $displayReservationFee = getReservationVerifiedPaymentByKeyword($conn, (int)$displayRow['id'], 'Reservation Fee');
            $displayLandVerified = max($displayVerifiedTotal - $displayReservationFee, 0);
            $displayIsFullyPaid = ($displayLandVerified + 0.01 >= (float)($displayCalc['tcp_after_discount'] ?? 0));

            $displayNeedsAction = (
                $displayHasPendingCancellation
                || $displayStatus === 'PENDING'
                || (
                    $displayStatus === 'APPROVED'
                    && !$displayHasPendingCancellation
                    && (
                        $displayIdStatus !== 'MANUALLY VERIFIED'
                        || !$displayContractUploaded
                        || !$displayIsFullyPaid
                    )
                )
            );
            $displayIsHistory = (
                in_array($displayStatus, ['CANCELLED', 'REJECTED'], true)
                || ($displayStatus === 'APPROVED' && $displayIsFullyPaid && $displayContractUploaded)
            );

            if($status_filter === 'ACTION_NEEDED' && !$displayNeedsAction){
                continue;
            }
            if($status_filter === 'HISTORY' && !$displayIsHistory){
                continue;
            }
        }
        $displayReservationRows[] = $displayRow;
    }
}

// Workload summary cards are intentionally computed from ALL reservations, not only the current status tab.
$summary_query = "SELECT r.*, u.fullname, u.email as user_email,
                 l.block_no, l.lot_no, l.total_price, l.area, l.price_per_sqm, l.location, l.classification,
                 $project_select_sql
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          JOIN lots l ON r.lot_id = l.id
          $project_join_sql
          WHERE 1
          ORDER BY r.reservation_date DESC";
$summary_res = $conn->query($summary_query);
$reservationSummary = [
    'action_needed' => 0,
    'history' => 0,
    'pending' => 0,
    'approved' => 0,
    'cancelled' => 0,
    'cancellation_requests' => 0,
    'awaiting_contract' => 0,
    'fully_paid' => 0,
    'high_risk' => 0,
    'all' => 0,
    'rejected' => 0
];
if($summary_res){
    while($summaryRow = $summary_res->fetch_assoc()){
        $reservationSummary['all']++;
        $summaryStatus = strtoupper(trim($summaryRow['status'] ?? 'PENDING'));
        if($summaryStatus === 'PENDING') $reservationSummary['pending']++;
        if($summaryStatus === 'APPROVED') $reservationSummary['approved']++;
        if($summaryStatus === 'CANCELLED') $reservationSummary['cancelled']++;
        if($summaryStatus === 'REJECTED') $reservationSummary['rejected']++;

        $summaryCancellationStatus = strtoupper(trim($summaryRow['cancellation_status'] ?? ''));
        $summaryHasPendingCancellation = (in_array($summaryStatus, ['PENDING', 'APPROVED'], true) && $summaryCancellationStatus === 'PENDING');
        if($summaryHasPendingCancellation){
            $reservationSummary['cancellation_requests']++;
        }

        $summaryContractUploaded = !empty($summaryRow['contract_file']);
        if($summaryStatus === 'APPROVED' && !$summaryHasPendingCancellation && !$summaryContractUploaded){
            $reservationSummary['awaiting_contract']++;
        }

        $summaryCalc = computeRequiredDPFromReservation($summaryRow);
        $summaryVerifiedTotal = getReservationVerifiedPaymentTotal($conn, (int)$summaryRow['id']);
        $summaryReservationFee = getReservationVerifiedPaymentByKeyword($conn, (int)$summaryRow['id'], 'Reservation Fee');
        $summaryLandVerified = max($summaryVerifiedTotal - $summaryReservationFee, 0);
        $summaryIsFullyPaid = ($summaryLandVerified + 0.01 >= (float)($summaryCalc['tcp_after_discount'] ?? 0));
        if($summaryStatus === 'APPROVED' && $summaryIsFullyPaid){
            $reservationSummary['fully_paid']++;
        }

        $summaryIdStatus = strtoupper(trim($summaryRow['id_verification_status'] ?? 'PENDING REVIEW'));
        $summaryNeedsAction = (
            $summaryHasPendingCancellation
            || $summaryStatus === 'PENDING'
            || (
                $summaryStatus === 'APPROVED'
                && !$summaryHasPendingCancellation
                && (
                    $summaryIdStatus !== 'MANUALLY VERIFIED'
                    || !$summaryContractUploaded
                    || !$summaryIsFullyPaid
                )
            )
        );
        if($summaryNeedsAction){
            $reservationSummary['action_needed']++;
        }
        if(in_array($summaryStatus, ['CANCELLED', 'REJECTED'], true) || ($summaryStatus === 'APPROVED' && $summaryIsFullyPaid && $summaryContractUploaded)){
            $reservationSummary['history']++;
        }

        if($summaryStatus === 'CANCELLED' || $summaryHasPendingCancellation){
            continue;
        }

        $summaryReservationTs = !empty($summaryRow['reservation_date']) ? strtotime($summaryRow['reservation_date']) : time();
        $summaryDaysPending = max(0, (int)floor((time() - $summaryReservationTs) / 86400));
        $summaryIsSpotCash = ((int)($summaryCalc['is_spot_cash'] ?? 0) === 1);
        $summaryOutstanding = max((float)($summaryCalc['tcp_after_discount'] ?? 0) - $summaryLandVerified, 0);
        $summaryOverdue = ($summaryIsSpotCash && $summaryStatus === 'APPROVED' && $summaryOutstanding > 0.01 && (20 - $summaryDaysPending) < 0);
        if($summaryIdStatus === 'NAME MISMATCH' || $summaryIdStatus === 'REJECTED' || $summaryOverdue){
            $reservationSummary['high_risk']++;
        }
    }
}


$currentPage = basename($_SERVER['PHP_SELF']);
$financePages = [
    'financial.php',
    'verify_payments.php',
    'payment_tracking.php',
    'transaction_history.php',
    'daily_reconciliation.php',
    'aging_due_report.php',
    'contract_status.php',
    'pricing_matrix.php'
];
$isFinancePage = in_array($currentPage, $financePages, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reservations | JEJ Admin</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #2e7d32; 
            --primary-light: #e8f5e9; 
            --dark: #1b5e20; 
            --gray-light: #f1f8e9; 
            --gray-border: #c8e6c9; 
            --text-muted: #607d8b; 
            
            --shadow-sm: 0 1px 2px 0 rgba(46, 125, 50, 0.08);
            --shadow-md: 0 4px 6px -1px rgba(46, 125, 50, 0.1), 0 2px 4px -1px rgba(46, 125, 50, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(46, 125, 50, 0.15), 0 4px 6px -2px rgba(46, 125, 50, 0.05);
        }

        body { background-color: #fafcf9; display: flex; min-height: 100vh; overflow-x: hidden; font-family: 'Inter', sans-serif; color: #37474f; margin: 0; }
        .sidebar { width: 260px; background: #ffffff; border-right: 1px solid var(--gray-border); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; box-shadow: var(--shadow-sm); }
        .brand-box { padding: 25px; border-bottom: 1px solid var(--gray-border); display: flex; align-items: center; gap: 12px; }
        .sidebar-menu { padding: 20px 15px; flex: 1; overflow-y: auto; }
        .menu-link { display: flex; align-items: center; gap: 12px; padding: 12px 18px; color: #455a64; text-decoration: none; font-weight: 500; font-size: 14px; border-radius: 10px; margin-bottom: 6px; transition: all 0.2s ease; }
        .menu-link:hover { background: var(--gray-light); color: var(--primary); }
        .menu-link.active { background: var(--primary-light); color: var(--primary); font-weight: 600; border-left: 4px solid var(--primary); }
        .menu-link i { width: 20px; text-align: center; font-size: 16px; opacity: 0.8; }
        
        .main-panel { margin-left: 260px; flex: 1; padding: 0; width: calc(100% - 260px); display: flex; flex-direction: column; }
        .top-header { display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 20px 40px; border-bottom: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); z-index: 50; }
        .header-title h1 { font-size: 22px; font-weight: 800; color: var(--dark); margin: 0 0 4px 0; letter-spacing: -0.5px;}
        .header-title p { color: var(--text-muted); font-size: 13px; margin: 0; }
        .header-actions { display: flex; align-items: center; gap: 14px; }
        .admin-notification-bell {
            position: relative;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            background: #f8fafc;
            border: 1px solid var(--gray-border);
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }
        .admin-notification-bell:hover {
            background: var(--primary);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(46, 125, 50, 0.18);
        }
        .admin-notification-bell i { font-size: 17px; }
        .admin-notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 19px;
            height: 19px;
            padding: 0 5px;
            border-radius: 999px;
            background: #ef4444;
            color: #fff;
            border: 2px solid #fff;
            font-size: 10px;
            font-weight: 800;
            line-height: 15px;
            text-align: center;
        }
        
        .content-area { padding: 35px 40px; flex: 1; }

        .table-container { background: white; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; background: var(--gray-light); border-bottom: 1px solid var(--gray-border); letter-spacing: 0.5px;}
        td { padding: 16px 24px; border-bottom: 1px solid var(--gray-border); color: #37474f; font-size: 14px; vertical-align: top; }
        tr:hover td { background: #fdfdfd; }
        tr:last-child td { border-bottom: none; }

        .tabs { display: flex; gap: 12px; margin-bottom: 25px; flex-wrap: wrap;}
        .tab-link { padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; color: #455a64; background: white; border: 1px solid var(--gray-border); transition: 0.2s; box-shadow: var(--shadow-sm);}
        .tab-link.active { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 4px 10px rgba(46, 125, 50, 0.2); }
        .tab-link.needs-attention:not(.active) { border-color:#fbbf24; background:#fffbeb; color:#92400e; }
        
        .status-badge { padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; letter-spacing: 0.3px; display: inline-block;}
        .status-PENDING { background: #fff3e0; color: #e65100; } 
        .status-APPROVED { background: #e8f5e9; color: #2e7d32; } 
        .status-REJECTED { background: #ffebee; color: #c62828; } 
        .status-CANCELLED { background: #f1f5f9; color: #475569; } 

        .id-status-badge { padding: 5px 9px; border-radius: 999px; font-size: 10px; font-weight: 800; display: inline-flex; align-items: center; gap: 4px; margin-top: 6px; text-transform: uppercase; }
        .id-PENDING-REVIEW { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }
        .id-OCR-MATCHED { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
        .id-NAME-MISMATCH { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
        .id-REJECTED { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .id-MANUALLY-VERIFIED { background:#dcfce7; color:#166534; border:1px solid #86efac; }
        .btn-id-verify { background:#0f766e; } .btn-id-verify:hover { background:#115e59; transform: translateY(-1px); }
        .btn-disabled { background:#94a3b8 !important; cursor:not-allowed !important; opacity:.75; }

        .btn-doc { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; background: #f8fafc; color: #334155 !important; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; width: 100%; cursor: pointer; transition: 0.2s; box-sizing: border-box; justify-content: center;}
        .btn-doc:hover { background: #e0f2fe; color: #0284c7; border-color: #bae6fd; }

        .action-forms { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .btn-action { padding: 8px 12px; border:none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; color: white; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s; box-shadow: var(--shadow-sm);}
        .btn-view-full { background:#e0f2fe !important; color:#0369a1 !important; border:1px solid #bae6fd !important; box-shadow:none; }
        .btn-view-full:hover { background:#0369a1 !important; color:#ffffff !important; border-color:#0369a1 !important; transform:none; }
        
        .btn-approve { background: #10b981; } .btn-approve:hover { background: #059669; transform: translateY(-1px); } 
        .btn-reject { background: #ef4444; } .btn-reject:hover { background: #dc2626; transform: translateY(-1px); } 
        .btn-receipt { background: #64748b; color: white; text-decoration:none; } .btn-terms { background: #3b82f6; color: white; text-decoration:none; } 
        .btn-verify-dp { background: #0284c7; }
        .btn-contract { background: #7c3aed; color: white; text-decoration:none; } .btn-contract:hover { background:#6d28d9; transform: translateY(-1px); }
        .btn-contract-view { background: #0f766e; color: white; text-decoration:none; } .btn-contract-view:hover { background:#115e59; transform: translateY(-1px); } 
        .btn-cancel-request { background:#f59e0b; color:white; } .btn-cancel-request:hover { background:#d97706; transform: translateY(-1px); }
        .account-number-box { margin-top: 10px; display: inline-flex; align-items: center; gap: 6px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 6px 10px; border-radius: 8px; font-size: 12px; font-weight: 800; }

        /* Modals */
        .doc-modal { display: none; position: fixed; z-index: 2000; inset: 0; background-color: rgba(0,0,0,0.85); backdrop-filter: blur(3px); align-items: center; justify-content: center; }
        .doc-modal img { max-width: 90%; max-height: 90vh; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); object-fit: contain; }
        .doc-close { position: absolute; top: 20px; right: 30px; color: white; font-size: 40px; cursor: pointer; transition: 0.2s; }
        .doc-close:hover { color: #d84315; transform: scale(1.1); }
        .verify-modal-content { display: flex; flex-direction: column; align-items: center; gap: 15px; }
        .verify-card { background: white; padding: 25px; border-radius: 12px; width: 100%; max-width: 400px; text-align: center; }

        .menu-dropdown { margin-bottom: 6px; }

        .dropdown-toggle{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            cursor:default;
        }

        .finance-main-link{
            display:flex;
            align-items:center;
            gap:12px;
            color:inherit;
            text-decoration:none;
            flex:1;
            min-width:0;
        }

        .finance-main-link:hover{
            color:var(--primary);
            text-decoration:none;
        }

        .submenu-toggle-btn{
            width:24px;
            height:24px;
            border:0;
            background:transparent;
            color:inherit;
            display:flex;
            align-items:center;
            justify-content:center;
            cursor:pointer;
            padding:0;
            margin-left:auto;
            border-radius:6px;
        }

        .submenu-toggle-btn:hover{
            background:rgba(46,125,50,.08);
        }

        .dropdown-arrow{
            font-size:13px;
            transition:transform .25s ease;
        }

        .submenu{
            display:none;
            padding-left:20px;
            margin-top:4px;
            margin-bottom:8px;
        }

        .submenu.show{
            display:block;
        }

        .submenu .submenu-link{
            font-size:13px;
            padding:10px 14px;
            margin-bottom:4px;
            border-left:2px solid #e5e7eb;
            border-radius:8px;
        }

        .submenu .submenu-link.active{
            background:var(--primary-light);
            color:var(--primary);
            font-weight:700;
            border-left:4px solid var(--primary);
        }


        /* RESERVATION CRM IMPROVEMENTS */
        .payment-badge{
            display:inline-flex;
            align-items:center;
            gap:5px;
            padding:5px 9px;
            border-radius:999px;
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            margin-top:8px;
        }
        .payment-spot{ background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; }
        .payment-installment{ background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
        .finance-mini{
            margin-top:10px;
            background:#f8fafc;
            border:1px solid #e2e8f0;
            border-radius:10px;
            padding:9px 10px;
            font-size:11px;
            line-height:1.6;
        }
        .finance-mini-row{
            display:flex;
            justify-content:space-between;
            gap:10px;
            color:#475569;
        }
        .finance-mini-row strong{ color:#0f172a; }
        .balance-due-box{
            margin-top:8px;
            background:#fff7ed;
            color:#c2410c;
            border:1px solid #fed7aa;
            border-radius:8px;
            padding:7px 9px;
            font-size:11px;
            font-weight:800;
        }
        .contract-badge{
            display:inline-flex;
            align-items:center;
            gap:4px;
            padding:5px 8px;
            border-radius:999px;
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            margin-top:6px;
        }
        .contract-uploaded{ background:#ede9fe; color:#6d28d9; border:1px solid #ddd6fe; }
        .contract-missing{ background:#f8fafc; color:#64748b; border:1px solid #e2e8f0; }
        .progress-mini{
            margin-top:16px;
            display:grid;
            gap:5px;
            font-size:11px;
            color:#64748b;
        }
        .progress-mini div{
            display:flex;
            align-items:center;
            gap:6px;
            white-space:nowrap;
        }
        .progress-mini .done{ color:#059669; font-weight:700; }
        .progress-mini .wait{ color:#d97706; font-weight:700; }
        .progress-mini .cancelled-audit-note,
        .progress-mini .cancellation-request-note{
            display:block;
            white-space:normal;
            margin-top:8px;
            width:100%;
        }
        .audit-meta-row{
            display:grid;
            grid-template-columns:58px minmax(0, 1fr);
            align-items:start;
            gap:8px;
            padding:2px 0;
            white-space:normal;
            width:100%;
            min-width:0;
        }
        .audit-meta-row span{
            color:#64748b;
            font-size:10px;
            font-weight:900;
            text-transform:uppercase;
            letter-spacing:.35px;
        }
        .audit-meta-row strong{
            color:#0f172a;
            font-size:11px;
            font-weight:900;
            text-align:right;
            min-width:0;
            overflow-wrap:anywhere;
            word-break:break-word;
        }
        .audit-short-note{
            margin-top:6px;
            padding-top:6px;
            border-top:1px dashed #dbe3ea;
            color:#475569;
            font-size:11px;
            font-weight:800;
            line-height:1.35;
        }
        .action-group-title{
            font-size:10px;
            color:#94a3b8;
            font-weight:800;
            text-transform:uppercase;
            width:100%;
            margin-top:3px;
        }


        /* PROFESSIONAL STATUS BADGES + FILTER BAR */
        .reservation-filter-bar{
            display:flex;
            flex-wrap:wrap;
            align-items:center;
            gap:12px;
            background:#fff;
            border:1px solid var(--gray-border);
            border-radius:14px;
            padding:14px;
            margin-bottom:18px;
            box-shadow:var(--shadow-sm);
            overflow:hidden;
        }
        .reservation-filter-bar input{
            flex:2 1 280px;
            min-width:240px;
        }
        .reservation-filter-bar select{
            flex:1 1 155px;
            min-width:145px;
        }
        .reservation-filter-bar input,
        .reservation-filter-bar select{
            height:42px;
            width:100%;
            padding:9px 12px;
            border:1px solid #cbd5e1;
            border-radius:9px;
            font-size:13px;
            box-sizing:border-box;
            outline:none;
            background:#fff;
        }
        .reservation-filter-bar input:focus,
        .reservation-filter-bar select:focus{
            border-color:var(--primary);
            box-shadow:0 0 0 3px rgba(46,125,50,.10);
        }
        .filter-reset-btn{
            flex:0 0 112px;
            height:42px;
            min-width:112px;
            padding:0 14px;
            border:1px solid var(--gray-border);
            background:var(--primary-light);
            color:var(--primary);
            border-radius:10px;
            font-weight:800;
            font-size:13px;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            white-space:nowrap;
            box-sizing:border-box;
        }
        .filter-reset-btn:hover{ background:#dff2e1; }
        .status-stack{ display:flex; flex-direction:column; gap:6px; align-items:flex-start; }
        .badge-pill{
            display:inline-flex;
            align-items:center;
            gap:5px;
            padding:6px 10px;
            border-radius:999px;
            font-size:10px;
            font-weight:800;
            line-height:1.1;
            text-transform:uppercase;
            border:1px solid transparent;
            white-space:nowrap;
        }
        .badge-pending{ background:#fff7ed; color:#c2410c; border-color:#fed7aa; }
        .badge-approved{ background:#dcfce7; color:#166534; border-color:#86efac; }
        .badge-rejected{ background:#fee2e2; color:#991b1b; border-color:#fecaca; }
        .badge-archived{ background:#f1f5f9; color:#475569; border-color:#cbd5e1; }
        .badge-cancelled{ background:#f1f5f9; color:#475569; border-color:#cbd5e1; }
        .badge-info{ background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
        .badge-warning{ background:#fef3c7; color:#92400e; border-color:#fde68a; }
        .badge-danger{ background:#fee2e2; color:#b91c1c; border-color:#fecaca; }
        .badge-purple{ background:#ede9fe; color:#6d28d9; border-color:#ddd6fe; }
        .badge-muted{ background:#f8fafc; color:#64748b; border-color:#e2e8f0; }
        .row-needs-action td{ background:#fffdf7; }

        /* Full-height left status accent for reservation cards */
        .reservation-table .reservation-row{
            position:relative;
            isolation:isolate;
            background-clip:padding-box;
        }

        .reservation-table .reservation-row::before{
            content:"";
            position:absolute;
            top:0;
            left:0;
            bottom:0;
            width:5px;
            border-radius:18px 0 0 18px;
            background:transparent;
            z-index:1;
            pointer-events:none;
        }

        .reservation-table .reservation-row.row-needs-action::before{
            background:#f59e0b;
        }

        .reservation-table .reservation-row.row-complete::before{
            background:#10b981;
        }

        .reservation-table .reservation-row.row-cancelled::before{
            background:#94a3b8;
        }

        .reservation-table .reservation-row.row-cancellation-request::before{
            background:#f59e0b;
        }

        .cancelled-audit-note{
            margin-top:10px;
            padding:10px 12px;
            border-radius:10px;
            background:#f8fafc;
            border:1px solid #cbd5e1;
            color:#475569;
            font-size:11px;
            font-weight:800;
            line-height:1.45;
        }

        .cancellation-request-note{
            margin-top:10px;
            padding:10px 12px;
            border-radius:10px;
            background:#fff7ed;
            border:1px solid #fed7aa;
            color:#9a3412;
            font-size:11px;
            font-weight:800;
            line-height:1.45;
        }

        .btn-audit-only{
            background:#64748b;
            cursor:not-allowed;
            opacity:.85;
        }

        .reservation-table .reservation-row > td{
            position:relative;
            z-index:2;
        }

        .age-badge,
        .deadline-badge,
        .risk-badge{
            display:inline-flex;
            align-items:center;
            gap:5px;
            padding:4px 8px;
            border-radius:999px;
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            border:1px solid transparent;
            margin:3px 4px 3px 0;
            white-space:nowrap;
        }
        .age-new{ background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
        .age-normal{ background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
        .age-warning{ background:#fff7ed; color:#c2410c; border-color:#fed7aa; }
        .deadline-ok{ background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
        .deadline-warning{ background:#fef3c7; color:#92400e; border-color:#fde68a; }
        .deadline-overdue{ background:#fee2e2; color:#991b1b; border-color:#fecaca; }
        .risk-low{ background:#dcfce7; color:#166534; border-color:#86efac; }
        .risk-medium{ background:#fef3c7; color:#92400e; border-color:#fde68a; }
        .risk-high{ background:#fee2e2; color:#991b1b; border-color:#fecaca; }
        .progress-percent-wrap{
            margin-top:8px;
            padding-top:8px;
            border-top:1px dashed #dbe3ea;
            width:100%;
        }
        .progress-percent-label{
            display:flex;
            justify-content:space-between;
            align-items:center;
            font-size:10px;
            font-weight:800;
            color:#64748b;
            margin-bottom:5px;
            text-transform:uppercase;
        }
        .progress-track{
            width:100%;
            height:7px;
            background:#e5e7eb;
            border-radius:999px;
            overflow:hidden;
        }
        .progress-fill{
            height:100%;
            background:linear-gradient(90deg,#22c55e,#16a34a);
            border-radius:999px;
        }
        .account-complete-badge{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:7px 10px;
            border-radius:999px;
            background:#dcfce7;
            color:#166534;
            border:1px solid #86efac;
            font-size:10px;
            font-weight:900;
            text-transform:uppercase;
            margin-top:6px;
        }


        .project-name-line{
            margin-top:4px;
            color:#0f766e;
            font-size:12px;
            font-weight:900;
            text-transform:uppercase;
            display:flex;
            align-items:center;
            gap:6px;
            letter-spacing:.2px;
        }
        .property-location-line{
            margin-top:3px;
            font-size:12px;
            color:#64748b;
            display:flex;
            align-items:center;
            gap:6px;
            line-height:1.35;
        }
        .balance-paid-clean{
            background:#dcfce7 !important;
            color:#166534 !important;
            border-color:#86efac !important;
        }
        .last-updated-note{
            font-size:10px;
            color:#94a3b8;
            margin-top:6px;
            font-weight:700;
        }


        /* AUTO FIT + COLLAPSIBLE SIDEBAR + RESERVATION CARD TABLE */
        .sidebar,
        .main-panel{
            transition: all .25s ease;
        }

        .top-header-left{
            display:flex;
            align-items:center;
            gap:14px;
            min-width:0;
        }

        .sidebar-toggle{
            width:42px;
            height:42px;
            border:0;
            border-radius:12px;
            background:var(--primary-light);
            color:var(--primary);
            font-size:18px;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            flex-shrink:0;
        }

        .sidebar-toggle:hover{ background:#d9f0dc; }

        body.sidebar-collapsed .sidebar{
            width:78px;
        }

        body.sidebar-collapsed .main-panel{
            margin-left:78px;
            width:calc(100% - 78px);
        }

        body.sidebar-collapsed .brand-box{
            justify-content:center;
            padding:22px 8px;
        }

        body.sidebar-collapsed .brand-box div,
        body.sidebar-collapsed .sidebar-menu small,
        body.sidebar-collapsed .finance-main-link span,
        body.sidebar-collapsed .submenu,
        body.sidebar-collapsed .submenu-toggle-btn{
            display:none !important;
        }

        body.sidebar-collapsed .menu-link{
            justify-content:center;
            padding:14px 8px;
            gap:0;
            font-size:0;
            border-left:0 !important;
        }

        body.sidebar-collapsed .menu-link i{
            width:22px;
            font-size:18px;
            margin:0;
        }

        body.sidebar-collapsed .finance-main-link{
            justify-content:center;
            gap:0;
        }

        .reservation-table-container{
            background:transparent;
            border:0;
            box-shadow:none;
            overflow:visible;
        }

        .reservation-table{
            width:100%;
            display:block;
            border-collapse:separate;
        }

        .reservation-table thead{
            display:none;
        }

        .reservation-table tbody{
            display:block;
        }

        .reservation-table .reservation-row{
            display:grid;
            grid-template-columns:minmax(230px,1.05fr) minmax(220px,.95fr) minmax(205px,.85fr) minmax(260px,1.05fr) minmax(280px,1.1fr);
            gap:14px;
            align-items:start;
            background:#ffffff;
            border:1px solid var(--gray-border);
            border-radius:18px;
            box-shadow:var(--shadow-sm);
            margin-bottom:18px;
            padding:16px 16px 16px 20px;
            overflow:hidden;
        }

        .reservation-table .reservation-row:hover{
            box-shadow:var(--shadow-md);
        }

        .reservation-table .reservation-row.row-compact-history{
            grid-template-columns:minmax(240px,1fr) minmax(220px,.9fr) minmax(230px,.85fr) minmax(250px,.9fr);
            gap:14px;
            padding-top:14px;
            padding-bottom:14px;
        }

        .reservation-table .reservation-row.row-compact-history td[data-label="Submitted Documents"]{
            display:none;
        }

        .reservation-table .reservation-row.row-compact-history .finance-mini,
        .reservation-table .reservation-row.row-compact-history .progress-mini{
            display:none;
        }

        .reservation-table .reservation-row.row-compact-history .balance-due-box,
        .reservation-table .reservation-row.row-compact-history .account-number-box{
            margin-top:8px;
            padding:8px 10px;
            line-height:1.25;
        }

        .reservation-table .reservation-row.row-compact-history .status-stack{
            gap:5px;
        }

        .reservation-table .reservation-row.row-compact-history td::before{
            margin-bottom:9px;
            padding-bottom:7px;
        }

        .reservation-table .reservation-row.row-compact-history .action-forms{
            gap:7px;
        }

        .reservation-table .reservation-row td{
            display:block;
            padding:0;
            border:0;
            background:transparent !important;
            min-width:0;
            word-break:break-word;
            overflow-wrap:anywhere;
        }

        .reservation-table .reservation-row td::before{
            content:attr(data-label);
            display:block;
            margin-bottom:12px;
            padding-bottom:8px;
            border-bottom:1px solid #e2e8f0;
            color:var(--text-muted);
            font-size:11px;
            font-weight:900;
            letter-spacing:.6px;
            text-transform:uppercase;
        }

        .reservation-table .reservation-row td > *{
            max-width:100% !important;
        }

        .reservation-table .reservation-row td:nth-child(3) > div{
            max-width:100% !important;
        }

        .reservation-table .reservation-row td:nth-child(3) form{
            max-width:100% !important;
        }

        .btn-doc,
        .btn-action{
            white-space:normal;
            text-align:center;
        }

        .action-forms{
            display:grid;
            grid-template-columns:1fr;
            gap:10px;
            align-items:stretch;
            width:100%;
            min-width:0;
            overflow:visible;
        }

        .action-forms form,
        .action-forms a,
        .action-forms button{
            width:100%;
            max-width:100%;
            min-width:0;
            justify-content:center;
            box-sizing:border-box;
        }

        .action-forms .action-group-title{
            width:100%;
            margin-top:2px;
        }

        .action-forms .cancellation-request-note,
        .action-forms .cancelled-audit-note{
            width:100%;
            max-width:100%;
            margin-top:0;
            box-sizing:border-box;
            overflow-wrap:anywhere;
        }

        .finance-mini-row{
            align-items:flex-start;
        }

        .finance-mini-row strong{
            text-align:right;
            overflow-wrap:anywhere;
        }

        .status-stack{
            width:100%;
        }

        .badge-pill,
        .payment-badge,
        .account-number-box,
        .account-complete-badge{
            max-width:100%;
            white-space:normal;
        }

        /* Reservation card UI cleanup: keep actions inside the card and readable. */
        .reservation-table .reservation-row td[data-label="Actions"]{
            min-width:0;
            overflow:visible;
        }

        .reservation-table .reservation-row td[data-label="Actions"] .action-forms{
            display:grid !important;
            grid-template-columns:1fr;
            gap:8px;
            align-items:stretch;
        }

        .reservation-table .reservation-row td[data-label="Actions"] .btn-action,
        .reservation-table .reservation-row td[data-label="Actions"] .btn-doc,
        .reservation-table .reservation-row td[data-label="Actions"] form{
            width:100% !important;
            max-width:100% !important;
            min-height:36px;
        }

        .reservation-table .reservation-row td[data-label="Actions"] .btn-action,
        .reservation-table .reservation-row td[data-label="Actions"] .btn-doc{
            padding:7px 10px;
            font-size:11.5px;
        }

        .reservation-table .reservation-row td[data-label="Actions"] .cancellation-request-note{
            padding:9px 10px;
            line-height:1.35;
            border-radius:10px;
        }

        .reservation-table .reservation-row td[data-label="Status"] .cancelled-audit-note,
        .reservation-table .reservation-row td[data-label="Status"] .cancellation-request-note{
            max-width:100%;
            box-sizing:border-box;
            overflow:hidden;
        }

        .reservation-table .reservation-row td[data-label="Actions"] .btn-view-full{
            background:#e0f2fe !important;
            color:#0369a1 !important;
            border:1px solid #bae6fd !important;
            opacity:1 !important;
        }

        .reservation-table .reservation-row td[data-label="Actions"] .btn-view-full:hover{
            background:#0369a1 !important;
            color:#ffffff !important;
        }

        @media(max-width:1500px){
            .reservation-table .reservation-row{
                grid-template-columns:minmax(260px, 1fr) minmax(260px, 1fr);
            }

            .reservation-table .reservation-row td[data-label="Status"],
            .reservation-table .reservation-row td[data-label="Actions"]{
                min-width:0;
            }
        }

        @media(max-width:768px){
            .sidebar{
                left:-260px;
            }

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

            .header-title h1{
                font-size:18px;
            }

            .header-title p{
                display:none;
            }

            .content-area{
                padding:18px;
            }

            .reservation-table .reservation-row{
                grid-template-columns:1fr;
                padding:14px;
            }
        }


        @media(max-width:900px){
            .reservation-filter-bar{
                gap:10px;
            }
            .reservation-filter-bar input,
            .reservation-filter-bar select,
            .filter-reset-btn{
                flex:1 1 100%;
                min-width:0;
            }
        }


        .summary-grid{
            display:grid;
            grid-template-columns:repeat(4, minmax(170px, 1fr));
            gap:14px;
            margin-bottom:20px;
        }
        .summary-card{
            background:#fff;
            border:1px solid var(--gray-border);
            border-radius:14px;
            box-shadow:var(--shadow-sm);
            padding:14px 16px;
            display:flex;
            align-items:center;
            gap:12px;
            min-height:76px;
        }
        .summary-icon{
            width:42px;
            height:42px;
            border-radius:12px;
            background:var(--primary-light);
            color:var(--primary);
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:18px;
            flex-shrink:0;
        }
        .summary-card.warning .summary-icon{ background:#fff7ed; color:#c2410c; }
        .summary-card.danger .summary-icon{ background:#fee2e2; color:#b91c1c; }
        .summary-card.muted .summary-icon{ background:#f1f5f9; color:#475569; }
        .summary-label{ font-size:10px; font-weight:900; color:#64748b; text-transform:uppercase; letter-spacing:.5px; line-height:1.2; }
        .summary-value{ font-size:22px; font-weight:900; color:#0f172a; line-height:1.1; margin-top:3px; }
        .tab-count{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:20px;
            height:20px;
            padding:0 6px;
            margin-left:7px;
            border-radius:999px;
            background:#fef3c7;
            color:#92400e;
            font-size:11px;
            font-weight:900;
        }
        .tab-link.active .tab-count{ background:rgba(255,255,255,.22); color:white; }
        .cancellation-meta{
            margin-top:8px;
            padding:9px 10px;
            border:1px solid #cbd5e1;
            border-radius:10px;
            background:#f8fafc;
            color:#475569;
            font-size:11px;
            font-weight:700;
            line-height:1.55;
        }
        .full-details-modal-card{
            background:#fff;
            border-radius:18px;
            width:min(980px, 94vw);
            max-height:88vh;
            overflow:auto;
            padding:0;
            box-shadow:0 25px 60px rgba(0,0,0,.35);
        }
        .full-details-header{
            position:sticky;
            top:0;
            z-index:2;
            background:#111827;
            color:white;
            padding:18px 22px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }
        .full-details-body{ padding:22px; display:grid; gap:16px; }
        .details-grid{ display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px; }
        .details-box{ border:1px solid #e2e8f0; border-radius:14px; padding:14px; background:#f8fafc; }
        .details-box h4{ margin:0 0 10px; color:#0f172a; font-size:13px; text-transform:uppercase; letter-spacing:.5px; }
        .details-row{ display:flex; justify-content:space-between; gap:12px; padding:5px 0; font-size:12px; color:#475569; border-bottom:1px dashed #e2e8f0; }
        .details-row:last-child{ border-bottom:0; }
        .details-row strong{ color:#0f172a; text-align:right; }
        .history-list{ display:grid; gap:10px; }
        .history-item{ border-left:4px solid var(--primary); background:white; border-radius:10px; padding:10px 12px; box-shadow:var(--shadow-sm); }
        .history-item strong{ display:block; color:#0f172a; font-size:12px; }
        .history-item span{ display:block; color:#64748b; font-size:11px; margin-top:2px; }
        .history-item small{ display:block; color:#475569; font-size:11px; margin-top:5px; line-height:1.45; }
        @media(max-width:1200px){ .summary-grid{ grid-template-columns:repeat(3, minmax(150px,1fr)); } }
        @media(max-width:768px){ .summary-grid{ grid-template-columns:1fr; } .details-grid{ grid-template-columns:1fr; } }

        /* PROFILE DROPDOWN - copied from admin.php style */
        .profile-dropdown {
            position: relative;
            cursor: pointer;
            flex-shrink: 0;
        }

        .profile-trigger {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 12px;
            border-radius: 10px;
            transition: background 0.2s, border-color 0.2s;
            border: 1px solid transparent;
        }

        .profile-trigger:hover {
            background: var(--gray-light);
            border-color: var(--gray-border);
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
            box-shadow: 0 2px 4px rgba(46, 125, 50, 0.2);
        }

        .profile-info strong {
            display: block;
            font-size: 13px;
            color: var(--dark);
            line-height: 1.2;
        }

        .profile-info small {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 110%;
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-border);
            min-width: 220px;
            z-index: 1000;
            overflow: hidden;
            transform-origin: top right;
            animation: dropAnim 0.2s ease-out forwards;
        }

        @keyframes dropAnim {
            0% { opacity: 0; transform: scale(0.95) translateY(-10px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }

        .profile-dropdown:hover .dropdown-menu {
            display: block;
        }

        .dropdown-header {
            padding: 15px;
            border-bottom: 1px solid var(--gray-border);
            background: var(--gray-light);
        }

        .dropdown-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #455a64;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.2s, color 0.2s, border-left-color 0.2s;
            border-left: 3px solid transparent;
        }

        .dropdown-item:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .dropdown-item.text-danger {
            color: #d84315;
        }

        .dropdown-item.text-danger:hover {
            background: #fbe9e7;
            color: #bf360c;
            border-left-color: #d84315;
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="brand-box">
            <img src="assets/logo1.png" style="height: 38px; width: auto; border-radius: 8px;">
            <div style="line-height: 1.1;">
                <span style="font-size: 16px; font-weight: 800; color: var(--primary); display: block;">JEJ Top Priority Corporation</span>
                <span style="font-size: 11px; color: var(--text-muted); font-weight: 500;">Management Portal</span>
            </div>
        </div>
        
        <div class="sidebar-menu">
           <!-- NORMAL ADMIN / MANAGER MENU -->
        <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-bottom: 12px; letter-spacing: 0.5px;">MAIN MENU</small>
        <a href="admin.php" class="menu-link">
            <i class="fa-solid fa-chart-pie"></i>
            Dashboard
        </a>

        <a href="reservation.php" class="menu-link active">
    <i class="fa-solid fa-file-signature"></i>
    Reservations
</a>

        <a href="master_list.php" class="menu-link">
            <i class="fa-solid fa-map-location-dot"></i>
            Master List / Map
        </a>

        <a href="admin.php?view=inventory" class="menu-link">
            <i class="fa-solid fa-plus-circle">
            </i> Add Property
        </a>
<!-- FINANCIAL MENU -->
<div class="menu-dropdown">
    <div class="menu-link dropdown-toggle <?= $isFinancePage ? 'active' : '' ?>">
        <a href="financial.php" class="finance-main-link">
            <i class="fa-solid fa-coins"></i>
            <span>Financials</span>
        </a>

        <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)" title="Show/Hide Financial Menu">
            <i class="fa-solid <?= $isFinancePage ? 'fa-chevron-up' : 'fa-chevron-down' ?> dropdown-arrow" id="financeArrow"></i>
        </button>
    </div>

    <div id="financeSubMenu" class="submenu <?= $isFinancePage ? 'show' : '' ?>">
        <a href="verify_payments.php" class="menu-link submenu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-circle-check"></i> Verify Payments
        </a>

        <a href="payment_tracking.php" class="menu-link submenu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i> Payment Tracking
        </a>

        <a href="transaction_history.php" class="menu-link submenu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-list-ul"></i> Ledger List
        </a>

        <a href="daily_reconciliation.php" class="menu-link submenu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-scale-balanced"></i> Daily Reconciliation
        </a>

        <a href="aging_due_report.php" class="menu-link submenu-link <?= $currentPage == 'aging_due_report.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock"></i> Aging / Due Report
        </a>

        <a href="contract_status.php" class="menu-link submenu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-signature"></i> Contract Status
        </a>

        <a href="manual_buyer_entry.php"
        class="menu-link submenu-link <?= $currentPage == 'manual_buyer_entry.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-user-plus"></i>
                Manual Buyer Entry
        </a>

                <a href="pricing_matrix.php"
                   class="menu-link submenu-link <?= $currentPage == 'pricing_matrix.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-table-list"></i>
                    <span class="menu-text">Pricing Matrix</span>
                </a>
    </div>
</div>
<small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">CUSTOMERS</small>

            <a href="buyers.php" class="menu-link <?= ($currentPage == 'buyers.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i>
                <span class="menu-text">Buyers</span>
            </a>
 <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">MANAGEMENT</small>
            <a href="agent_tracking.php" class="menu-link"><i class="fa-solid fa-user-tie"></i>Agent Tracking</a>
            <a href="inquiries.php" class="menu-link"><i class="fa-solid fa-envelope-open-text"></i> Inquiries</a>
            <a href="accounts.php" class="menu-link"><i class="fa-solid fa-users-gear"></i> Accounts</a>
            <a href="delete_history.php" class="menu-link"><i class="fa-solid fa-trash-can"></i> Delete History</a>
            
            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">SYSTEM</small>
            <a href="index.php" class="menu-link" target="_blank"><i class="fa-solid fa-globe"></i> View Website</a>

        </div>
    </div>

    <div class="main-panel">
        <div class="top-header">
            <div class="top-header-left">
                <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <div class="header-title">
                    <h1>Reservation Management</h1>
                    <p>Review reservations, verify documents, and process down payments.</p>
                </div>
            </div>

            <div class="header-actions">
                <?php include 'includes/profile_dropdown.php'; ?>
            </div>
        </div>

        <div class="content-area">

            <?php if($alert_msg): ?>
                <div style="padding: 16px 20px; border-radius: 10px; margin-bottom: 25px; font-weight: 500; font-size: 14px; background: <?= $alert_type=='success' ? '#e8f5e9' : '#fbe9e7' ?>; color: <?= $alert_type=='success' ? '#2e7d32' : '#d84315' ?>; border: 1px solid <?= $alert_type=='success' ? '#c8e6c9' : '#ffccbc' ?>; box-shadow: var(--shadow-sm);">
                    <i class="fa-solid <?= $alert_type=='success'?'fa-check-circle':'fa-exclamation-circle' ?>" style="margin-right: 10px;"></i>
                    <?= $alert_msg ?>
                </div>
            <?php endif; ?>

            <div class="summary-grid">
                <div class="summary-card warning">
                    <div class="summary-icon"><i class="fa-solid fa-list-check"></i></div>
                    <div><div class="summary-label">Action Needed</div><div class="summary-value"><?= (int)$reservationSummary['action_needed'] ?></div></div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-icon"><i class="fa-solid fa-file-circle-question"></i></div>
                    <div><div class="summary-label">Cancellation Requests</div><div class="summary-value"><?= (int)$reservationSummary['cancellation_requests'] ?></div></div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-icon"><i class="fa-solid fa-file-circle-exclamation"></i></div>
                    <div><div class="summary-label">Awaiting Contract</div><div class="summary-value"><?= (int)$reservationSummary['awaiting_contract'] ?></div></div>
                </div>
                <div class="summary-card danger">
                    <div class="summary-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div><div class="summary-label">High Risk / Overdue</div><div class="summary-value"><?= (int)$reservationSummary['high_risk'] ?></div></div>
                </div>
            </div>

            <div class="tabs">
                <a href="reservation.php?status=ACTION_NEEDED" class="tab-link <?= $status_filter=='ACTION_NEEDED'?'active':'' ?> <?= ((int)$reservationSummary['action_needed'] > 0) ? 'needs-attention' : '' ?>">Action Needed <span class="tab-count"><?= (int)$reservationSummary['action_needed'] ?></span></a>
                <a href="reservation.php?status=PENDING" class="tab-link <?= $status_filter=='PENDING'?'active':'' ?>">Pending Review <span class="tab-count"><?= (int)$reservationSummary['pending'] ?></span></a>
                <a href="reservation.php?status=CANCELLATION_REQUESTS" class="tab-link <?= $status_filter=='CANCELLATION_REQUESTS'?'active':'' ?> <?= ((int)$reservationSummary['cancellation_requests'] > 0) ? 'needs-attention' : '' ?>">Cancellation <span class="tab-count"><?= (int)$reservationSummary['cancellation_requests'] ?></span></a>
                <a href="reservation.php?status=APPROVED" class="tab-link <?= $status_filter=='APPROVED'?'active':'' ?>">Approved <span class="tab-count"><?= (int)$reservationSummary['approved'] ?></span></a>
                <a href="reservation.php?status=HISTORY" class="tab-link <?= in_array($status_filter, ['HISTORY','CANCELLED','REJECTED'], true)?'active':'' ?>">History <span class="tab-count"><?= (int)$reservationSummary['history'] ?></span></a>
                <a href="reservation.php?status=ALL" class="tab-link <?= $status_filter=='ALL'?'active':'' ?>">All Records <span class="tab-count"><?= (int)$reservationSummary['all'] ?></span></a>
            </div>

            <div class="reservation-filter-bar">
                <input type="text" id="reservationSearch" placeholder="Search buyer, account no., lot no., agent, location..." onkeyup="filterReservations()">

                <select id="paymentFilter" onchange="filterReservations()">
                    <option value="ALL">All Payment Types</option>
                    <option value="SPOT">Spot Cash</option>
                    <option value="INSTALLMENT">Installment</option>
                </select>

                <select id="idFilter" onchange="filterReservations()">
                    <option value="ALL">All ID Status</option>
                    <option value="VERIFIED">ID Verified</option>
                    <option value="PENDING">ID Pending</option>
                    <option value="MISMATCH">Name Mismatch</option>
                    <option value="REJECTED">ID Rejected</option>
                </select>

                <select id="contractFilter" onchange="filterReservations()">
                    <option value="ALL">All Contract Status</option>
                    <option value="UPLOADED">Contract Uploaded</option>
                    <option value="MISSING">Awaiting Contract</option>
                </select>

                <select id="balanceFilter" onchange="filterReservations()">
                    <option value="ALL">All Balance Status</option>
                    <option value="PAID">Fully Paid / No Balance</option>
                    <option value="OUTSTANDING">With Outstanding Balance</option>
                </select>

                <select id="workflowFilter" onchange="filterReservations()">
                    <option value="ALL">All Workflow Filters</option>
                    <option value="AWAITING_ID">Awaiting ID Verification</option>
                    <option value="AWAITING_PAYMENT">Awaiting Payment Verification</option>
                    <option value="AWAITING_CONTRACT">Awaiting Contract</option>
                    <option value="COMPLETED">Completed</option>
                    <option value="CANCELLATION_REQUEST">Cancellation Request</option>
                    <option value="CANCELLED_BY_BUYER">Cancelled by Buyer</option>
                </select>

                <select id="sortFilter" onchange="filterReservations()">
                    <option value="NEWEST">Sort: Newest</option>
                    <option value="OLDEST">Sort: Oldest</option>
                    <option value="TCP_HIGH">Highest TCP</option>
                    <option value="TCP_LOW">Lowest TCP</option>
                    <option value="UNPAID_FIRST">Unpaid First</option>
                </select>

                <button type="button" class="filter-reset-btn" onclick="resetReservationFilters()"><i class="fa-solid fa-rotate-left"></i> Reset</button>
            </div>

            <div class="table-container reservation-table-container">
                <table class="reservation-table">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Buyer Information</th>
                            <th style="width: 20%;">Property & Financials</th>
                            <th style="width: 20%;">Submitted Documents</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 25%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($displayReservationRows)): ?>
                            <?php foreach($displayReservationRows as $row): 
                                $dp_status = isset($row['dp_status']) ? $row['dp_status'] : 'UNPAID';
                                $id_status = strtoupper(trim($row['id_verification_status'] ?? 'PENDING REVIEW'));
                                $id_status_class = 'id-' . str_replace(' ', '-', $id_status);
                                $selfie_file = getReservationFileValue($row, [
                                    'live_selfie',
                                    'selfie_with_id',
                                    'selfie_id',
                                    'selfie_file',
                                    'selfie',
                                    'selfie_path'
                                ]);

                                $valid_id_file = getReservationFileValue($row, [
                                    'valid_id_file',
                                    'valid_id',
                                    'valid_id_path',
                                    'id_file'
                                ]);

                                $payment_proof = getReservationFileValue($row, [
                                    'payment_proof',
                                    'dp_proof',
                                    'proof',
                                    'reservation_proof',
                                    'payment_receipt'
                                ]);

                                $payment_proof_src = reservationDocSrc($payment_proof);
                                $valid_id_src = reservationDocSrc($valid_id_file);
                                $selfie_src = reservationDocSrc($selfie_file);

                                // Clean reservation flow control:
                                // 1) Buyer submits docs.
                                // 2) Admin must verify ID first.
                                // 3) Approve/Reject buttons appear only after ID is manually verified and saved.
                                $payment_proof_exists = !empty($payment_proof_src) && reservationDocExists($payment_proof_src);
                                $valid_id_exists = !empty($valid_id_src) && reservationDocExists($valid_id_src);
                                $selfie_exists = !empty($selfie_src) && reservationDocExists($selfie_src);

                                $id_verified_for_flow = ($id_status === 'MANUALLY VERIFIED');
                                if ($has_verified_at_col && empty($row['verified_at'])) {
                                    $id_verified_for_flow = false;
                                }
                                if ($has_verified_by_col && empty($row['verified_by'])) {
                                    $id_verified_for_flow = false;
                                }
                                $docs_ready_for_id_review = ($payment_proof_exists && $valid_id_exists && $selfie_exists);

                                $buyer_lat = $row['buyer_latitude'] ?? $row['latitude'] ?? '';
                                $buyer_lng = $row['buyer_longitude'] ?? $row['longitude'] ?? '';
                                $location_mode = $row['location_mode'] ?? '';

                                $dp_calc = computeRequiredDPFromReservation($row);
                                $computed_required_dp = (float)$dp_calc['required_dp'];
                                $is_spot_cash_row = ((int)($dp_calc['is_spot_cash'] ?? 0) === 1);
                                $payment_badge_text = $dp_calc['payment_label'] ?? ($is_spot_cash_row ? 'Cash / Spot Cash' : 'Installment / Straight Payment');
                                $payment_badge_class = $is_spot_cash_row ? 'payment-spot' : 'payment-installment';
                                $balance_label = $is_spot_cash_row ? 'Spot Cash Balance' : 'Balance to Finance';
                                $balance_amount = $is_spot_cash_row
                                    ? (float)$dp_calc['net_tcp_after_reservation']
                                    : max((float)$dp_calc['net_tcp_after_reservation'] - (float)$dp_calc['required_dp'], 0);

                                // Get actual verified payments from transactions so status matches Payment Tracking/SOA.
                                $verified_payment_total = getReservationVerifiedPaymentTotal($conn, (int)$row['id']);
                                $reservation_fee_paid_amount = getReservationVerifiedPaymentByKeyword($conn, (int)$row['id'], 'Reservation Fee');
                                $land_verified_payment_total = max($verified_payment_total - $reservation_fee_paid_amount, 0);
                                $land_balance_outstanding = max((float)$dp_calc['tcp_after_discount'] - $land_verified_payment_total, 0);
                                $reservation_fee_status_text = ($reservation_fee_paid_amount + 0.01 >= (float)($dp_calc['reservation_fee'] ?? 5000)) ? 'PAID' : 'DUE';
                                $spot_cash_paid_amount = 0.00;
                                $spot_cash_outstanding = $balance_amount;
                                if($is_spot_cash_row){
                                    $spot_cash_paid_amount = min($land_verified_payment_total, $balance_amount);
                                    $spot_cash_outstanding = max($balance_amount - $spot_cash_paid_amount, 0);
                                }
                                $contract_uploaded = !empty($row['contract_file']);
                                $contract_status_text = $contract_uploaded ? 'Contract Uploaded' : 'Awaiting Contract';
                                $reservation_date_display = !empty($row['reservation_date'])
                                    ? date('M d, Y', strtotime($row['reservation_date']))
                                    : 'N/A';

                                // Reservation age and Spot Cash 20-day deadline indicator.
                                $reservation_ts = !empty($row['reservation_date']) ? strtotime($row['reservation_date']) : time();
                                $days_pending = max(0, (int)floor((time() - $reservation_ts) / 86400));
                                $age_class = ($days_pending <= 3) ? 'age-new' : (($days_pending <= 14) ? 'age-normal' : 'age-warning');

                                $spot_cash_days_left = 20 - $days_pending;
                                $deadline_class = 'deadline-ok';
                                $deadline_text = '';
                                if($is_spot_cash_row && !in_array(strtoupper(trim($row['status'] ?? 'PENDING')), ['REJECTED','CANCELLED'], true) && $spot_cash_outstanding > 0.01){
                                    if($spot_cash_days_left < 0){
                                        $deadline_class = 'deadline-overdue';
                                        $deadline_text = 'Overdue ' . abs($spot_cash_days_left) . ' day' . (abs($spot_cash_days_left) == 1 ? '' : 's');
                                    } elseif($spot_cash_days_left <= 5){
                                        $deadline_class = 'deadline-warning';
                                        $deadline_text = 'Due in ' . $spot_cash_days_left . ' day' . ($spot_cash_days_left == 1 ? '' : 's');
                                    } else {
                                        $deadline_text = $spot_cash_days_left . ' days left';
                                    }
                                }

                                // Professional reservation status badges.
                                $main_status_text = strtoupper(trim($row['status'] ?? 'PENDING'));
                                $cancellation_status_text = strtoupper(trim($row['cancellation_status'] ?? ''));
                                $has_pending_cancellation_request = (in_array($main_status_text, ['PENDING', 'APPROVED'], true) && $cancellation_status_text === 'PENDING');
                                $is_cancelled_row = ($main_status_text === 'CANCELLED');
                                $cancellation_requested_at_text = '';
                                if($has_pending_cancellation_request && !empty($row['cancellation_requested_at'])){
                                    $cancellation_requested_at_text = date('M d, Y', strtotime($row['cancellation_requested_at']));
                                }
                                $cancelled_at_text = '';
                                $cancelled_by_label = 'Buyer';
                                $cancellation_reason_text = 'No reason provided';
                                if($is_cancelled_row || $has_pending_cancellation_request){
                                    $cancelled_at_raw = $row['cancelled_at'] ?? $row['updated_at'] ?? $row['reservation_date'] ?? '';
                                    if(!empty($cancelled_at_raw)){
                                        $cancelled_at_text = date('M d, Y', strtotime($cancelled_at_raw));
                                    }

                                    $cancelled_by_raw = trim((string)($row['cancelled_by'] ?? ''));
                                    if($cancelled_by_raw !== '' && (int)$cancelled_by_raw !== (int)($row['user_id'] ?? 0)){
                                        $cancelled_by_label = 'Admin/User #' . (int)$cancelled_by_raw;
                                    }

                                    $reason_raw = trim((string)($row['cancellation_reason'] ?? ''));
                                    if($reason_raw !== ''){
                                        $cancellation_reason_text = $reason_raw;
                                    }
                                }
                                $main_status_icon = 'fa-clock';
                                $main_status_class = 'badge-pending';
                                if($main_status_text === 'APPROVED'){
                                    $main_status_icon = 'fa-circle-check';
                                    $main_status_class = 'badge-approved';
                                    if($has_pending_cancellation_request){
                                        $main_status_icon = 'fa-file-circle-question';
                                        $main_status_class = 'badge-warning';
                                    }
                                } elseif($main_status_text === 'REJECTED'){
                                    $main_status_icon = 'fa-circle-xmark';
                                    $main_status_class = 'badge-rejected';
                                } elseif($main_status_text === 'CANCELLED'){
                                    $main_status_icon = 'fa-ban';
                                    $main_status_class = 'badge-cancelled';
                                } elseif($main_status_text === 'ARCHIVED'){
                                    $main_status_icon = 'fa-box-archive';
                                    $main_status_class = 'badge-archived';
                                }

                                $dp_verified_complete = (!$is_spot_cash_row && ($land_verified_payment_total + 0.01 >= (float)($dp_calc['required_dp'] ?? 0)));
                                if($is_spot_cash_row){
                                    if($spot_cash_outstanding <= 0.01){
                                        $payment_status_text = 'TCP Fully Paid';
                                        $payment_status_class = 'badge-approved';
                                        $payment_status_icon = 'fa-circle-check';
                                    } elseif($dp_status === 'VERIFYING'){
                                        $payment_status_text = 'Payment Verifying';
                                        $payment_status_class = 'badge-info';
                                        $payment_status_icon = 'fa-arrows-rotate';
                                    } else {
                                        $payment_status_text = 'Spot Cash Due';
                                        $payment_status_class = 'badge-warning';
                                        $payment_status_icon = 'fa-clock';
                                    }
                                } else {
                                    if($dp_verified_complete){
                                        $payment_status_text = 'DP Verified';
                                        $payment_status_class = 'badge-approved';
                                        $payment_status_icon = 'fa-circle-check';
                                    } elseif($dp_status === 'VERIFYING'){
                                        $payment_status_text = 'Payment Verifying';
                                        $payment_status_class = 'badge-info';
                                        $payment_status_icon = 'fa-arrows-rotate';
                                    } else {
                                        $payment_status_text = 'DP Due';
                                        $payment_status_class = 'badge-warning';
                                        $payment_status_icon = 'fa-clock';
                                    }
                                }

                                if($is_cancelled_row){
                                    $payment_status_text = 'No Payment Action';
                                    $payment_status_class = 'badge-muted';
                                    $payment_status_icon = 'fa-circle-info';
                                } elseif($has_pending_cancellation_request){
                                    $payment_status_text = 'Paused for Cancellation Review';
                                    $payment_status_class = 'badge-warning';
                                    $payment_status_icon = 'fa-hourglass-half';
                                }

                                $contract_badge_class = $contract_uploaded ? 'badge-purple' : 'badge-muted';
                                $contract_badge_icon = $contract_uploaded ? 'fa-file-circle-check' : 'fa-file-circle-exclamation';
                                $payment_complete_for_row = (!$is_cancelled_row && !$has_pending_cancellation_request) && ($is_spot_cash_row
                                    ? ($spot_cash_outstanding <= 0.01)
                                    : $dp_verified_complete);
                                $row_outstanding_amount = $is_spot_cash_row
                                    ? (float)$spot_cash_outstanding
                                    : (float)$land_balance_outstanding;
                                $balance_filter_value = ($is_cancelled_row || $has_pending_cancellation_request) ? 'PAID' : (($row_outstanding_amount <= 0.01) ? 'PAID' : 'OUTSTANDING');

                                // Last updated note for quick audit visibility.
                                $last_updated_source = '';
                                $last_updated_value = '';
                                if(!empty($row['contract_uploaded_at'])){
                                    $last_updated_source = 'Contract';
                                    $last_updated_value = date('M d, Y', strtotime($row['contract_uploaded_at']));
                                } elseif(!empty($row['verified_at'])){
                                    $last_updated_source = 'ID';
                                    $last_updated_value = date('M d, Y', strtotime($row['verified_at']));
                                } elseif(!empty($row['reservation_date'])){
                                    $last_updated_source = 'Reserved';
                                    $last_updated_value = date('M d, Y', strtotime($row['reservation_date']));
                                }

                                // Completion score: Reservation + ID + Payment + Contract.
                                $completed_steps = 1;
                                if($id_status === 'MANUALLY VERIFIED') $completed_steps++;
                                if($payment_complete_for_row) $completed_steps++;
                                if($contract_uploaded) $completed_steps++;
                                if($is_cancelled_row) $completed_steps = 1;
                                $completion_percent = (int)round(($completed_steps / 4) * 100);

                                $is_account_completed = (
                                    strtoupper(trim($row['status'] ?? '')) === 'APPROVED'
                                    && $id_status === 'MANUALLY VERIFIED'
                                    && $payment_complete_for_row
                                    && $contract_uploaded
                                );

                                // Buyer risk indicator for admin attention.
                                $risk_text = 'Low Risk';
                                $risk_class = 'risk-low';
                                $risk_icon = 'fa-shield-heart';

                                if($is_cancelled_row){
                                    $risk_text = 'Cancelled';
                                    $risk_class = 'risk-medium';
                                    $risk_icon = 'fa-ban';
                                } elseif($has_pending_cancellation_request){
                                    $risk_text = 'Cancellation Review';
                                    $risk_class = 'risk-medium';
                                    $risk_icon = 'fa-file-circle-question';
                                } elseif(
                                    $id_status === 'NAME MISMATCH'
                                    || $id_status === 'REJECTED'
                                    || ($is_spot_cash_row && $spot_cash_outstanding > 0.01 && $spot_cash_days_left < 0)
                                ){
                                    $risk_text = 'High Risk';
                                    $risk_class = 'risk-high';
                                    $risk_icon = 'fa-triangle-exclamation';
                                } elseif(
                                    strtoupper(trim($row['status'] ?? '')) === 'PENDING'
                                    || $id_status !== 'MANUALLY VERIFIED'
                                    || !$payment_complete_for_row
                                    || !$contract_uploaded
                                    || $days_pending >= 15
                                ){
                                    $risk_text = 'Medium Risk';
                                    $risk_class = 'risk-medium';
                                    $risk_icon = 'fa-circle-exclamation';
                                }

                                $needs_action = (!$is_cancelled_row && !$has_pending_cancellation_request && ($row['status'] === 'PENDING' || $id_status !== 'MANUALLY VERIFIED' || !$contract_uploaded || !$payment_complete_for_row));
                                $row_class = $is_cancelled_row ? 'row-cancelled' : ($has_pending_cancellation_request ? 'row-cancellation-request' : ((!$needs_action && $row['status'] === 'APPROVED') ? 'row-complete' : ($needs_action ? 'row-needs-action' : '')));
                                $is_compact_history_row = (!$has_pending_cancellation_request && ($is_cancelled_row || $main_status_text === 'REJECTED' || $is_account_completed));
                                if($is_compact_history_row){
                                    $row_class .= ' row-compact-history';
                                }

                                $workflow_tags = [];
                                if(!$is_cancelled_row && !$has_pending_cancellation_request && $id_status !== 'MANUALLY VERIFIED') $workflow_tags[] = 'AWAITING_ID';
                                if(!$is_cancelled_row && !$has_pending_cancellation_request && strtoupper(trim($row['status'] ?? '')) === 'APPROVED' && !$payment_complete_for_row) $workflow_tags[] = 'AWAITING_PAYMENT';
                                if(!$is_cancelled_row && !$has_pending_cancellation_request && strtoupper(trim($row['status'] ?? '')) === 'APPROVED' && !$contract_uploaded) $workflow_tags[] = 'AWAITING_CONTRACT';
                                if(!$has_pending_cancellation_request && $is_account_completed) $workflow_tags[] = 'COMPLETED';
                                if($is_cancelled_row) $workflow_tags[] = 'CANCELLED_BY_BUYER';
                                if($has_pending_cancellation_request) $workflow_tags[] = 'CANCELLATION_REQUEST';
                                $workflow_filter_value = implode('|', $workflow_tags);

                                $status_history = reservationBuildStatusHistory($row, $id_status, $payment_complete_for_row, $contract_uploaded, $is_cancelled_row, $cancelled_at_text, $cancelled_by_label, $cancellation_reason_text);
                                $full_details_payload = [
                                    'reservation_id' => (int)$row['id'],
                                    'buyer' => [
                                        'name' => $row['fullname'] ?? '',
                                        'email' => $row['email'] ?? $row['user_email'] ?? '',
                                        'contact' => $row['contact_number'] ?? 'N/A',
                                        'address' => $row['buyer_address'] ?? $row['address'] ?? 'N/A',
                                        'agent' => $row['agent_name'] ?? 'N/A'
                                    ],
                                    'property' => [
                                        'location' => $row['location'] ?? '',
                                        'block' => $row['block_no'] ?? '',
                                        'lot' => $row['lot_no'] ?? '',
                                        'area' => $row['area'] ?? '',
                                        'classification' => $row['classification'] ?? ''
                                    ],
                                    'financial' => [
                                        'payment_option' => $payment_badge_text,
                                        'final_tcp' => '₱' . number_format((float)$dp_calc['tcp_after_discount'], 2),
                                        'reservation_fee' => '₱' . number_format((float)$dp_calc['reservation_fee'], 2) . ' ' . $reservation_fee_status_text,
                                        'verified_payment_total' => '₱' . number_format($land_verified_payment_total, 2),
                                        'outstanding_balance' => ($is_cancelled_row || $has_pending_cancellation_request) ? 'Paused for cancellation review' : '₱' . number_format($row_outstanding_amount, 2)
                                    ],
                                    'documents' => [
                                        'fee_proof' => $payment_proof_exists ? 'Available' : 'Missing',
                                        'valid_id' => $valid_id_exists ? 'Available' : 'Missing',
                                        'live_selfie' => $selfie_exists ? 'Available' : 'Missing',
                                        'contract' => $contract_uploaded ? 'Uploaded' : 'Awaiting Contract'
                                    ],
                                    'status' => [
                                        'reservation' => $has_pending_cancellation_request ? 'CANCELLATION REQUESTED' : $main_status_text,
                                        'id' => $id_status,
                                        'payment' => $payment_status_text,
                                        'contract' => $contract_status_text,
                                        'cancelled_by' => $is_cancelled_row ? $cancelled_by_label : '',
                                        'cancelled_at' => $is_cancelled_row ? $cancelled_at_text : '',
                                        'cancellation_requested_at' => $has_pending_cancellation_request ? $cancellation_requested_at_text : '',
                                        'cancellation_reason' => ($is_cancelled_row || $has_pending_cancellation_request) ? $cancellation_reason_text : ''
                                    ],
                                    'history' => $status_history
                                ];
                                $full_details_json = reservationJsonForAttribute($full_details_payload);
                            ?>
                            <tr class="reservation-row <?= $row_class ?>"
                                data-search="<?= htmlspecialchars(strtolower(($row['fullname'] ?? '') . ' ' . ($row['account_number'] ?? '') . ' block ' . ($row['block_no'] ?? '') . ' lot ' . ($row['lot_no'] ?? '') . ' ' . ($row['agent_name'] ?? '') . ' ' . ($row['project_name'] ?? '') . ' ' . ($row['location'] ?? '') . ' ' . ($row['email'] ?? $row['user_email'] ?? ''))) ?>"
                                data-payment="<?= $is_spot_cash_row ? 'SPOT' : 'INSTALLMENT' ?>"
                                data-idstatus="<?= htmlspecialchars($id_status) ?>"
                                data-contract="<?= $contract_uploaded ? 'UPLOADED' : 'MISSING' ?>"
                                data-balance="<?= htmlspecialchars($balance_filter_value) ?>"
                                data-workflow="<?= htmlspecialchars($workflow_filter_value) ?>"
                                data-sort-date="<?= (int)$reservation_ts ?>"
                                data-sort-tcp="<?= htmlspecialchars((float)$row['total_price']) ?>"
                                data-sort-balance="<?= htmlspecialchars((float)$row_outstanding_amount) ?>"
                                style="<?= ($dp_status == 'VERIFYING') ? 'background-color: #f0f9ff;' : '' ?>">
                                <td data-label="Buyer Information">
                                    <div style="font-weight: 700; color: #263238; margin-bottom: 8px; font-size: 14px;">
                                        <?= htmlspecialchars($row['fullname']) ?>
                                    </div>
                                    <div style="font-size:12px; color:#64748b; margin-bottom: 4px; display:flex; gap:8px;">
                                        <i class="fa-solid fa-calendar-day" style="color:#90a4ae; margin-top:2px;"></i>
                                        <span>Reserved: <?= htmlspecialchars($reservation_date_display) ?></span>
                                    </div>
                                    <div style="margin-bottom:6px;">
                                        <?php if($has_pending_cancellation_request): ?>
                                            <span class="age-badge age-warning">
                                                <i class="fa-solid fa-file-circle-question"></i>
                                                Cancellation Requested<?= $cancellation_requested_at_text !== '' ? ': ' . htmlspecialchars($cancellation_requested_at_text) : '' ?>
                                            </span>
                                            <div class="cancellation-meta" style="border-color:#fed7aa;background:#fff7ed;color:#9a3412;">
                                                <div><strong>Requested by:</strong> Buyer</div>
                                                <div><strong>Date:</strong> <?= htmlspecialchars($cancellation_requested_at_text ?: 'Not recorded') ?></div>
                                                <div><strong>Reason:</strong> <?= htmlspecialchars($cancellation_reason_text) ?></div>
                                            </div>
                                        <?php elseif($is_cancelled_row): ?>
                                            <span class="age-badge age-normal">
                                                <i class="fa-solid fa-ban"></i>
                                                Cancelled<?= $cancelled_at_text !== '' ? ': ' . htmlspecialchars($cancelled_at_text) : '' ?>
                                            </span>
                                            <div class="cancellation-meta">
                                                <div><strong>Cancelled by:</strong> <?= htmlspecialchars($cancelled_by_label) ?></div>
                                                <div><strong>Date:</strong> <?= htmlspecialchars($cancelled_at_text ?: 'Not recorded') ?></div>
                                                <div><strong>Reason:</strong> <?= htmlspecialchars($cancellation_reason_text) ?></div>
                                            </div>
                                        <?php else: ?>
                                            <span class="age-badge <?= $age_class ?>">
                                                <i class="fa-solid fa-hourglass-half"></i>
                                                <?= $days_pending ?> day<?= $days_pending == 1 ? '' : 's' ?> active
                                            </span>
                                            <?php if($deadline_text !== ''): ?>
                                                <span class="deadline-badge <?= $deadline_class ?>">
                                                    <i class="fa-solid <?= $deadline_class === 'deadline-overdue' ? 'fa-triangle-exclamation' : 'fa-stopwatch' ?>"></i>
                                                    <?= htmlspecialchars($deadline_text) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:12px; color:#546e7a; margin-bottom: 4px; display: flex; gap: 8px;">
                                        <i class="fa-solid fa-phone" style="color:#90a4ae; margin-top: 2px;"></i> 
                                        <span><?= htmlspecialchars(isset($row['contact_number']) && !empty($row['contact_number']) ? $row['contact_number'] : 'N/A') ?></span>
                                    </div>
                                    <div style="font-size:12px; color:#546e7a; margin-bottom: 4px; display: flex; gap: 8px;">
                                        <i class="fa-solid fa-envelope" style="color:#90a4ae; margin-top: 2px;"></i> 
                                        <span><?= htmlspecialchars($row['email'] ?? $row['user_email']) ?></span>
                                    </div>
                                    
                                    <div style="font-size:12px; color:#546e7a; margin-bottom: 4px; display: flex; gap: 8px;">
                                        <i class="fa-solid fa-house" style="color:#90a4ae; margin-top: 2px;"></i> 
                                        <span><?= htmlspecialchars(isset($row['buyer_address']) && !empty($row['buyer_address']) ? $row['buyer_address'] : 'Address Not Provided') ?></span>
                                    </div>

                                    <?php if(!empty($buyer_lat) && !empty($buyer_lng)): ?>
                                        <div style="font-size:12px; color:#0f766e; margin-bottom: 4px; display: flex; gap: 8px;">
                                            <i class="fa-solid fa-location-crosshairs" style="color:#0f766e; margin-top: 2px;"></i>
                                            <span>
                                                GPS: <?= htmlspecialchars($buyer_lat) ?>, <?= htmlspecialchars($buyer_lng) ?>
                                                <a href="https://www.google.com/maps?q=<?= urlencode($buyer_lat . ',' . $buyer_lng) ?>" target="_blank" style="color:#0284c7; font-weight:700; margin-left:4px;">Open Map</a>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if(!empty($location_mode)): ?>
                                        <div style="font-size:11px; color:#64748b; margin-bottom: 4px;">
                                            <i class="fa-solid fa-globe"></i> Location Mode: <strong><?= htmlspecialchars($location_mode) ?></strong>
                                        </div>
                                    <?php endif; ?>

                                    <div style="font-size:12px; color:#546e7a; margin-bottom: 4px; display: flex; gap: 8px; background: #f8fafc; padding: 4px 6px; border-radius: 4px; border: 1px solid #e2e8f0; display: inline-flex; margin-top: 4px;">
                                        <i class="fa-solid fa-user-tie" style="color:var(--primary); margin-top: 2px;"></i> 
                                        <strong>Agent:</strong> 
                                        <span>
                                            <?= htmlspecialchars(isset($row['agent_name']) && !empty($row['agent_name']) ? $row['agent_name'] : 'None') ?>
                                        </span>
                                    </div>
                                </td>

                                <td data-label="Property & Financials">
                                    <div style="font-weight: 700; color: var(--primary); font-size:15px;">
                                        Block <?= htmlspecialchars($row['block_no']) ?>, Lot <?= htmlspecialchars($row['lot_no']) ?>
                                    </div>

                                    <?php if(!empty($row['project_name'])): ?>
                                        <div class="project-name-line">
                                            <i class="fa-solid fa-building"></i>
                                            <span><?= htmlspecialchars($row['project_name']) ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="property-location-line">
                                        <i class="fa-solid fa-location-dot" style="color:#f59e0b;"></i>
                                        <span><?= htmlspecialchars(!empty($row['location']) ? $row['location'] : 'Location Not Set') ?></span>
                                    </div>

                                    <div style="font-weight: 700; font-size: 13px; margin-top: 8px; color: #263238;">TCP: ₱<?= number_format($row['total_price']) ?></div>
                                    <span class="payment-badge <?= $payment_badge_class ?>">
                                        <i class="fa-solid <?= $is_spot_cash_row ? 'fa-money-bill-wave' : 'fa-calendar-check' ?>"></i>
                                        <?= htmlspecialchars($payment_badge_text) ?>
                                    </span>
                                    <div class="finance-mini">
                                        <div class="finance-mini-row"><span>Price Difference Amount</span><strong style="color:#059669;">+ ₱<?= number_format($dp_calc['additional_amount'] ?? 0, 2) ?></strong></div>
                                        <div class="finance-mini-row"><span>Final Contract Price</span><strong>₱<?= number_format($dp_calc['tcp_after_discount'], 2) ?></strong></div>
                                        <div class="finance-mini-row"><span>Reservation Fee</span><strong style="color:<?= ($reservation_fee_status_text === 'PAID') ? '#059669' : '#b45309' ?>;">₱<?= number_format($dp_calc['reservation_fee'], 2) ?> <?= $reservation_fee_status_text === 'PAID' ? '✓' : 'DUE' ?></strong></div>
                                        <?php if($is_spot_cash_row): ?>
                                            <div class="finance-mini-row"><span>Required DP</span><strong>₱0.00</strong></div>
                                        <?php else: ?>
                                            <div class="finance-mini-row"><span>Required DP</span><strong>₱<?= number_format($dp_calc['required_dp'], 2) ?></strong></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="balance-due-box <?= ($row_outstanding_amount <= 0.01 || $is_cancelled_row) ? 'balance-paid-clean' : '' ?>">
                                        <?php if($is_cancelled_row): ?>
                                            Cancelled — no further payment action required
                                        <?php elseif($row_outstanding_amount <= 0.01): ?>
                                            Fully Paid — No Outstanding Balance
                                        <?php elseif($is_spot_cash_row): ?>
                                            Spot Cash Outstanding: ₱<?= number_format($spot_cash_outstanding, 2) ?>
                                        <?php else: ?>
                                            <?= htmlspecialchars($balance_label) ?>: ₱<?= number_format($balance_amount, 2) ?>
                                        <?php endif; ?>
                                    </div>

                                    <?php if(!$is_account_completed && !$is_cancelled_row): ?>
                                        <div style="margin-top:8px;">
                                            <span class="risk-badge <?= $risk_class ?>">
                                                <i class="fa-solid <?= $risk_icon ?>"></i>
                                                <?= htmlspecialchars($risk_text) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if($has_account_number_col): ?>
                                        <div class="account-number-box">
                                            <i class="fa-solid fa-hashtag"></i>
                                            Account No: <?= htmlspecialchars(!empty($row['account_number']) ? $row['account_number'] : ($is_cancelled_row ? 'Cancelled before generation' : 'Not generated')) ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="margin-top:8px; font-size:11px; color:#ef4444; font-weight:700;">
                                            Run account number SQL update.
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td data-label="Submitted Documents">
                                    <div style="display: flex; flex-direction: column; gap: 6px; max-width: 150px;">
                                        <?php if(!empty($payment_proof_src) && reservationDocExists($payment_proof_src)): ?>
                                            <button class="btn-doc" onclick='showDoc(<?= json_encode($payment_proof_src) ?>)' title="View Proof of Payment">
                                                <i class="fa-solid fa-receipt" style="color: #0284c7;"></i> Fee Proof
                                            </button>
                                        <?php elseif(!empty($payment_proof)): ?>
                                            <button class="btn-doc" disabled title="File not found: <?= htmlspecialchars($payment_proof_src) ?>">
                                                <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i> Fee Proof Missing
                                            </button>
                                        <?php endif; ?>

                                        <?php if(!empty($valid_id_src) && reservationDocExists($valid_id_src)): ?>
                                            <button class="btn-doc" onclick='showDoc(<?= json_encode($valid_id_src) ?>)' title="View Valid ID">
                                                <i class="fa-solid fa-id-card" style="color: #d97706;"></i> Valid ID
                                            </button>
                                        <?php elseif(!empty($valid_id_file)): ?>
                                            <button class="btn-doc" disabled title="File not found: <?= htmlspecialchars($valid_id_src) ?>">
                                                <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i> Valid ID Missing
                                            </button>
                                        <?php endif; ?>

                                        <?php if(!empty($selfie_src) && reservationDocExists($selfie_src)): ?>
                                            <button class="btn-doc" onclick='showDoc(<?= json_encode($selfie_src) ?>)' title="View Live Selfie">
                                                <i class="fa-solid fa-camera-retro" style="color:#16a34a;"></i>
                                                Live Selfie
                                            </button>
                                        <?php elseif(!empty($selfie_file)): ?>
                                            <button class="btn-doc" disabled title="File not found: <?= htmlspecialchars($selfie_src) ?>">
                                                <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i>
                                                Selfie File Missing
                                            </button>
                                        <?php else: ?>
                                            <button class="btn-doc" disabled title="No selfie uploaded">
                                                <i class="fa-solid fa-camera-slash" style="color:#94a3b8;"></i>
                                                No Selfie
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if(empty($payment_proof) && empty($valid_id_file) && empty($selfie_file)): ?>
                                            <span style="font-size: 11px; color: #94a3b8; font-style: italic;">No Documents</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if($has_id_verification_status_col): ?>
                                        <?php if($has_pending_cancellation_request): ?>
                                            <div class="cancellation-request-note">
                                                <i class="fa-solid fa-lock"></i>
                                                ID update is paused because the buyer requested cancellation.
                                            </div>
                                        <?php elseif($is_cancelled_row): ?>
                                            <div class="cancelled-audit-note">
                                                <i class="fa-solid fa-lock"></i>
                                                ID update is locked because this reservation was cancelled by the buyer.
                                            </div>
                                        <?php else: ?>
                                            <form action="reservation.php" method="POST" style="margin-top:10px; max-width:170px;" onsubmit="return confirm('Update ID verification status for this buyer?')">
                                                <input type="hidden" name="res_id" value="<?= (int)$row['id'] ?>">
                                                <select name="id_verification_status" style="width:100%; padding:7px; border:1px solid #cbd5e1; border-radius:6px; font-size:11px; margin-bottom:6px;">
                                                    <?php foreach(['PENDING REVIEW','OCR MATCHED','NAME MISMATCH','REJECTED','MANUALLY VERIFIED'] as $opt): ?>
                                                        <option value="<?= $opt ?>" <?= $id_status === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" name="update_id_verification" class="btn-action btn-id-verify" style="width:100%; justify-content:center;">
                                                    <i class="fa-solid fa-shield-halved"></i> Save ID Status
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div style="margin-top:10px; font-size:11px; color:#ef4444; font-weight:700;">Run ID verification SQL update.</div>
                                    <?php endif; ?>
                                </td>

                                <td data-label="Status">
                                    <div class="status-stack">
                                        <span class="badge-pill <?= $main_status_class ?>">
                                            <i class="fa-solid <?= $main_status_icon ?>"></i>
                                            <?= htmlspecialchars($has_pending_cancellation_request ? 'Cancellation Requested' : ($main_status_text === 'PENDING' ? 'Pending Review' : ($main_status_text === 'CANCELLED' ? 'Cancelled' : ucfirst(strtolower($main_status_text))))) ?>
                                        </span>

                                        <?php if($has_pending_cancellation_request): ?>
                                            <span class="badge-pill badge-warning">
                                                <i class="fa-solid fa-hourglass-half"></i>
                                                Awaiting Admin Decision
                                            </span>
                                        <?php elseif($is_cancelled_row): ?>
                                            <span class="badge-pill badge-muted">
                                                <i class="fa-solid fa-user-slash"></i>
                                                Cancelled by Buyer
                                            </span>
                                        <?php endif; ?>

                                        <?php if(!$is_cancelled_row && !$has_pending_cancellation_request && $has_id_verification_status_col): ?>
                                            <span class="badge-pill <?= $id_status === 'MANUALLY VERIFIED' ? 'badge-approved' : ($id_status === 'NAME MISMATCH' || $id_status === 'REJECTED' ? 'badge-danger' : 'badge-warning') ?>">
                                                <i class="fa-solid <?= $id_status === 'MANUALLY VERIFIED' ? 'fa-id-card-clip' : ($id_status === 'REJECTED' || $id_status === 'NAME MISMATCH' ? 'fa-triangle-exclamation' : 'fa-clock') ?>"></i>
                                                <?= htmlspecialchars($id_status === 'MANUALLY VERIFIED' ? 'ID Verified' : ucwords(strtolower($id_status))) ?>
                                            </span>
                                        <?php endif; ?>

                                        <span class="badge-pill <?= $payment_status_class ?>">
                                            <i class="fa-solid <?= $payment_status_icon ?>"></i>
                                            <?= htmlspecialchars($payment_status_text) ?>
                                        </span>

                                        <?php if(!$is_cancelled_row && !$has_pending_cancellation_request): ?>
                                            <span class="badge-pill <?= $contract_badge_class ?>">
                                                <i class="fa-solid <?= $contract_badge_icon ?>"></i>
                                                <?= htmlspecialchars($contract_status_text) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if($is_account_completed): ?>
                                            <span class="account-complete-badge">
                                                <i class="fa-solid fa-award"></i>
                                                Account Completed
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="progress-mini">
                                        <?php if($has_pending_cancellation_request): ?>
                                            <div class="wait"><i class="fa-solid fa-file-circle-question"></i> Cancellation Requested</div>
                                            <div class="cancellation-request-note">
                                                <div class="audit-meta-row"><span>Date</span><strong><?= htmlspecialchars($cancellation_requested_at_text ?: 'Not recorded') ?></strong></div>
                                                <div class="audit-meta-row"><span>Reason</span><strong><?= htmlspecialchars($cancellation_reason_text) ?></strong></div>
                                                <div class="audit-short-note">Awaiting admin decision.</div>
                                            </div>
                                        <?php elseif($is_cancelled_row): ?>
                                            <div class="wait"><i class="fa-solid fa-ban"></i> Cancelled by Buyer</div>
                                            <div class="cancelled-audit-note">
                                                <div class="audit-meta-row"><span>By</span><strong><?= htmlspecialchars($cancelled_by_label) ?></strong></div>
                                                <div class="audit-meta-row"><span>Date</span><strong><?= htmlspecialchars($cancelled_at_text ?: 'Not recorded') ?></strong></div>
                                                <div class="audit-meta-row"><span>Reason</span><strong><?= htmlspecialchars($cancellation_reason_text) ?></strong></div>
                                                <div class="audit-short-note">Audit only. No further action required.</div>
                                            </div>
                                        <?php else: ?>
                                            <div class="done"><i class="fa-solid fa-check-circle"></i> Reservation</div>
                                            <div class="<?= $id_status === 'MANUALLY VERIFIED' ? 'done' : 'wait' ?>"><i class="fa-solid <?= $id_status === 'MANUALLY VERIFIED' ? 'fa-check-circle' : 'fa-clock' ?>"></i> ID Verification</div>
                                            <div class="<?= $payment_complete_for_row ? 'done' : 'wait' ?>"><i class="fa-solid <?= $payment_complete_for_row ? 'fa-check-circle' : 'fa-clock' ?>"></i> Payment</div>
                                            <div class="<?= $contract_uploaded ? 'done' : 'wait' ?>"><i class="fa-solid <?= $contract_uploaded ? 'fa-check-circle' : 'fa-clock' ?>"></i> Contract</div>

                                            <div class="progress-percent-wrap">
                                                <div class="progress-percent-label">
                                                    <span>Completion</span>
                                                    <span><?= $completion_percent ?>%</span>
                                                </div>
                                                <div class="progress-track">
                                                    <div class="progress-fill" style="width:<?= $completion_percent ?>%;"></div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td data-label="Actions">
                                    <div class="action-forms">
                                        <button type="button" class="btn-action btn-doc btn-view-full" onclick='showFullReservationModal(<?= $full_details_json ?>)'>
                                            <i class="fa-solid fa-eye"></i> View Full Reservation
                                        </button>
                                        
                                        <?php if($has_pending_cancellation_request): ?>
                                            <div class="action-group-title">Cancellation Request</div>
                                            <div class="cancellation-request-note" style="width:100%;">
                                                <div class="audit-meta-row"><span>Date</span><strong><?= htmlspecialchars($cancellation_requested_at_text ?: 'Not recorded') ?></strong></div>
                                                <div class="audit-meta-row"><span>Reason</span><strong><?= htmlspecialchars($cancellation_reason_text) ?></strong></div>
                                            </div>
                                            <form action="reservation.php" method="POST" style="margin:0;" onsubmit="return confirm('Accept this cancellation request? The reservation will be moved to Cancelled and the lot may return to available inventory.')">
                                                <input type="hidden" name="res_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="cancellation_admin_note" value="Cancellation accepted by admin.">
                                                <button type="submit" name="accept_cancellation" class="btn-action btn-approve">
                                                    <i class="fa-solid fa-check"></i> Accept Cancellation
                                                </button>
                                            </form>
                                            <form action="reservation.php" method="POST" style="margin:0;" onsubmit="return confirm('Reject this cancellation request? The reservation will remain in its current active status.')">
                                                <input type="hidden" name="res_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="cancellation_admin_note" value="Cancellation request rejected by admin.">
                                                <button type="submit" name="reject_cancellation" class="btn-action btn-reject">
                                                    <i class="fa-solid fa-xmark"></i> Reject Cancellation
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if(!$has_pending_cancellation_request && $dp_status == 'VERIFYING'): ?>
                                            <button class="btn-action btn-verify-dp" onclick="showVerifyModal('uploads/<?= htmlspecialchars($row['dp_proof'] ?? '') ?>', <?= $row['id'] ?>)">
                                                <i class="fa-solid fa-qrcode"></i> Verify QR Payment
                                            </button>
                                        <?php endif; ?>

                                        <?php if(!$has_pending_cancellation_request && $row['status'] == 'APPROVED'): ?>
                                            <div class="action-group-title">View</div>
                                            <a href="receipt.php?id=<?= $row['id'] ?>" target="_blank" class="btn-action btn-receipt">
                                                <i class="fa-solid fa-print"></i> Receipt
                                            </a>
                                            <a href="payment_terms.php?res_id=<?= $row['id'] ?>" class="btn-action btn-terms">
                                                <i class="fa-solid fa-calculator"></i> Terms
                                            </a>
                                            <div class="action-group-title">Manage</div>
                                            <?php if(!empty($row['contract_file'])): ?>
                                                <a href="<?= htmlspecialchars($row['contract_file']) ?>" target="_blank" class="btn-action btn-contract-view">
                                                    <i class="fa-solid fa-download"></i> View/Download Contract
                                                </a>
                                                <button type="button" class="btn-action btn-contract" onclick="showContractModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['fullname'], ENT_QUOTES) ?>', 'replace')">
                                                    <i class="fa-solid fa-file-pen"></i> Replace / Update Contract
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn-action btn-contract" onclick="showContractModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['fullname'], ENT_QUOTES) ?>', 'upload')">
                                                    <i class="fa-solid fa-file-contract"></i> Upload Contract
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if(!$has_pending_cancellation_request && $row['status'] == 'PENDING'): ?>
                                            <?php if(!$id_verified_for_flow): ?>
                                                <button type="button" class="btn-action btn-disabled" title="Save ID Status as MANUALLY VERIFIED first" disabled>
                                                    <i class="fa-solid fa-id-card-clip"></i> Verify ID First
                                                </button>
                                                <small style="display:block;color:#b45309;font-weight:700;font-size:11px;line-height:1.35;margin-top:4px;">
                                                    <?= $docs_ready_for_id_review ? 'Review the ID, then click Save ID Status.' : 'Buyer documents must be complete before approval.' ?>
                                                </small>
                                            <?php else: ?>
                                                <?php
                                                    // IMPORTANT: Do not place raw JSON inside a double-quoted HTML attribute.
                                                    // It breaks the onclick attribute and makes the Approve button do nothing.
                                                    $dp_calc_json = htmlspecialchars(
                                                        json_encode($dp_calc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    );
                                                ?>
                                                <button
                                                    type="button"
                                                    class="btn-action btn-approve"
                                                    onclick='showApproveModal(<?= (int)$row['id'] ?>, <?= (int)$row['lot_id'] ?>, <?= json_encode($computed_required_dp) ?>, <?= $dp_calc_json ?>)'
                                                    title="Approve">
                                                    <i class="fa-solid fa-check"></i> Approve
                                                </button>

                                                <form action="reservation.php" method="POST" style="margin: 0;" onsubmit="return confirm('Reject this reservation? It will be moved to the Rejected tab without posting back to inventory.')">
                                                    <input type="hidden" name="res_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" name="reject_res" class="btn-action btn-reject" title="Reject"><i class="fa-solid fa-xmark"></i> Reject</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if($row['status'] == 'CANCELLED'): ?>
                                            <div class="action-group-title">Cancelled Record</div>
                                            <button type="button" class="btn-action btn-audit-only" disabled>
                                                <i class="fa-solid fa-lock"></i> Audit Only
                                            </button>
                                            <small style="display:block;color:#64748b;font-weight:700;font-size:11px;line-height:1.35;margin-top:4px;">
                                                This reservation was cancelled by the buyer. Approval, ID verification, payment, and contract actions are disabled.
                                            </small>
                                        <?php endif; ?>

                                        <?php if($row['status'] == 'REJECTED'): ?>
                                            <form action="reservation.php" method="POST" style="margin: 0;" onsubmit="return confirm('Restore this reservation back to Pending?')">
                                                <input type="hidden" name="res_id" value="<?= $row['id'] ?>">
                                                <button type="submit" name="restore_res" class="btn-action btn-approve" title="Restore"><i class="fa-solid fa-rotate-left"></i> Restore</button>
                                            </form>
                                            
                                            <form action="reservation.php" method="POST" style="margin: 0;" onsubmit="return confirm('Permanently delete this reservation and return the lot to the available inventory?')">
                                                <input type="hidden" name="res_id" value="<?= $row['id'] ?>">
                                                <button type="submit" name="delete_res" class="btn-action btn-reject" style="background:#334155;" title="Delete"><i class="fa-solid fa-trash-can"></i> Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center; padding: 50px; color: var(--text-muted);">
                                <i class="fa-solid fa-folder-open" style="font-size: 34px; margin-bottom: 15px; display: block; color: #cfd8dc;"></i>
                                <span style="font-weight: 500;">No reservations found matching this filter.</span>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
        </div>
    </div>

    <div id="docModal" class="doc-modal" onclick="closeDoc()">
        <span class="doc-close">&times;</span>
        <img id="docImage" src="">
    </div>

    <div id="verifyModal" class="doc-modal">
        <span class="doc-close" onclick="closeVerify()">&times;</span>
        <div class="verify-modal-content" onclick="event.stopPropagation()">
            <img id="verifyImage" src="" style="max-height: 65vh; border: 3px solid white;">
            
            <form action="reservation.php" method="POST" class="verify-card">
                <input type="hidden" name="res_id" id="verifyResId">
                <h3 style="margin: 0 0 5px 0; color:#1e293b; font-family:'Inter', sans-serif;">Verify QR Down Payment</h3>
                <p style="font-size: 13px; color:#64748b; margin-bottom: 20px;">Review the receipt above carefully. Once verified, the buyer will be notified that their down payment is fully paid.</p>
                
                <button type="submit" name="verify_dp" class="btn-action btn-approve" style="width: 100%; padding: 14px; font-size: 15px; justify-content: center;">
                    <i class="fa-solid fa-check-double"></i> Mark DP as PAID
                </button>
            </form>
        </div>
    </div>

    <div id="approveModal" class="doc-modal">
        <span class="doc-close" onclick="closeApprove()">&times;</span>
        <div class="verify-modal-content" onclick="event.stopPropagation()">
            <form action="reservation.php" method="POST" class="verify-card">
                <input type="hidden" name="res_id" id="approveResId">
                <input type="hidden" name="lot_id" id="approveLotId">
                
                <h3 style="margin: 0 0 5px 0; color:#1e293b; font-family:'Inter', sans-serif;">Approve Reservation</h3>
                <p style="font-size: 13px; color:#64748b; margin-bottom: 20px;">The required down payment is auto-computed from the approved buyer computation. Reservation fee is recorded separately in Payment Tracking.</p>

                <div id="approveDpBreakdown" style="text-align:left; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; margin-bottom:15px; font-size:12px; color:#475569; line-height:1.7;"></div>
                
                <div style="text-align: left; margin-bottom: 20px;">
                    <label id="approveAmountLabel" style="font-size: 13px; font-weight: 600; color: #455a64; display: block; margin-bottom: 5px;">Required Down Payment (₱)</label>
                    <input type="number" step="0.01" name="required_dp" id="approveDpAmount" readonly required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 16px; font-weight: 800; color: var(--primary); background:#f8fafc;">
                    <small id="approveFormulaText" style="display:block; margin-top:6px; color:#64748b;">Formula: Final Contract Price × 20%. Reservation fee is recorded separately.</small>
                </div>

                <button type="submit" name="approve_with_dp" class="btn-action btn-approve" style="width: 100%; padding: 14px; font-size: 15px; justify-content: center;">
                    <i class="fa-solid fa-check-double"></i> Confirm & Approve
                </button>
            </form>
        </div>
    </div>


    <div id="contractModal" class="doc-modal">
        <span class="doc-close" onclick="closeContractModal()">&times;</span>
        <div class="verify-modal-content" onclick="event.stopPropagation()">
            <form action="reservation.php" method="POST" enctype="multipart/form-data" class="verify-card">
                <input type="hidden" name="res_id" id="contractResId">
                <h3 id="contractModalTitle" style="margin: 0 0 5px 0; color:#1e293b; font-family:'Inter', sans-serif;">
                    <i class="fa-solid fa-file-contract" style="color:#7c3aed;"></i> Upload Signed Contract
                </h3>
                <p id="contractBuyerName" style="font-size: 13px; color:#64748b; margin-bottom: 20px;"></p>

                <div style="text-align:left; margin-bottom:15px;">
                    <label style="font-size:13px; font-weight:600; color:#455a64; display:block; margin-bottom:6px;">Signed Contract File</label>
                    <input type="file" name="contract_file" accept=".pdf,.jpg,.jpeg,.png" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                    <small style="display:block; margin-top:6px; color:#64748b;">Accepted files: PDF, JPG, JPEG, PNG. Maximum 10MB.</small>
                </div>

                <div id="contractFlowText" style="background:#f5f3ff; border:1px solid #ddd6fe; color:#5b21b6; padding:12px; border-radius:8px; font-size:12px; line-height:1.5; margin-bottom:18px; text-align:left;">
                    <strong>Flow:</strong> Upload the signed contract here after the reservation is approved. The buyer will be notified and can download it from the buyer dashboard.
                </div>

                <button type="submit" name="upload_contract" class="btn-action btn-contract" style="width:100%; padding:14px; font-size:15px; justify-content:center;">
                    <i class="fa-solid fa-upload"></i> <span id="contractSubmitLabel">Upload Contract</span>
                </button>
            </form>
        </div>
    </div>



    <div id="fullReservationModal" class="doc-modal">
        <span class="doc-close" onclick="closeFullReservationModal()">&times;</span>
        <div class="full-details-modal-card" onclick="event.stopPropagation()">
            <div class="full-details-header">
                <div>
                    <h3 id="fullReservationTitle" style="margin:0;font-size:18px;">Reservation Details</h3>
                    <small id="fullReservationSubtitle" style="color:#cbd5e1;font-weight:700;"></small>
                </div>
                <button type="button" class="btn-action btn-audit-only" onclick="closeFullReservationModal()" style="width:auto;">
                    <i class="fa-solid fa-xmark"></i> Close
                </button>
            </div>
            <div class="full-details-body">
                <div class="details-grid">
                    <div class="details-box"><h4>Buyer Info</h4><div id="fullBuyerDetails"></div></div>
                    <div class="details-box"><h4>Property Info</h4><div id="fullPropertyDetails"></div></div>
                    <div class="details-box"><h4>Payment Computation</h4><div id="fullFinancialDetails"></div></div>
                    <div class="details-box"><h4>Documents / Status</h4><div id="fullDocumentDetails"></div></div>
                </div>
                <div class="details-box">
                    <h4>Status History / Activity</h4>
                    <div id="fullHistoryDetails" class="history-list"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showDoc(src) {
            if(!src){
                alert('No file path found.');
                return;
            }

            if(src.toLowerCase().endsWith('.pdf')){
                window.open(src, '_blank');
                return;
            }

            const img = document.getElementById('docImage');
            img.onerror = function(){
                alert('File cannot be loaded. Check if this file exists: ' + src);
                window.open(src, '_blank');
                closeDoc();
            };

            img.onload = function(){
                img.onerror = null;
            };

            img.src = src;
            document.getElementById('docModal').style.display = 'flex';
        }
        function closeDoc() {
            const img = document.getElementById('docImage');
            img.src = '';
            document.getElementById('docModal').style.display = 'none';
        }

        function showVerifyModal(src, resId) {
            document.getElementById('verifyImage').src = src;
            document.getElementById('verifyResId').value = resId;
            document.getElementById('verifyModal').style.display = 'flex';
        }
        function closeVerify() {
            document.getElementById('verifyModal').style.display = 'none';
        }

        function formatPeso(amount){
            return '₱' + new Intl.NumberFormat('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(Number(amount || 0));
        }

        function showApproveModal(resId, lotId, defaultDpAmount, breakdown) {
            document.getElementById('approveResId').value = resId;
            document.getElementById('approveLotId').value = lotId;

            const approveAmountLabel = document.getElementById('approveAmountLabel');
            const approveDpInput = document.getElementById('approveDpAmount');
            const isSpotCash = breakdown && Number(breakdown.is_spot_cash || 0) === 1;
            const spotCashBalance = Number((breakdown && breakdown.net_tcp_after_reservation) ? breakdown.net_tcp_after_reservation : 0);

            if(isSpotCash){
                approveAmountLabel.innerText = 'Pay Spot Cash Balance (₱)';
                approveDpInput.value = spotCashBalance.toFixed(2);
            } else {
                approveAmountLabel.innerText = 'Required Down Payment (₱)';
                approveDpInput.value = Number(defaultDpAmount || 0).toFixed(2);
            }

            const box = document.getElementById('approveDpBreakdown');
            if(box && breakdown){
                const finalLabel = breakdown.approval_label || 'Required DP 20%';
                const finalAmount = Number(breakdown.approval_amount || breakdown.required_dp || 0);
                const paymentTypeText = breakdown.payment_label || ((breakdown.is_spot_cash == 1) ? 'Cash / Spot Cash' : 'Installment / Straight Payment');

                box.innerHTML =
                    '<div style="display:flex;justify-content:space-between;color:#0f172a;font-weight:800;margin-bottom:4px;"><span>Payment Option</span><strong>' + paymentTypeText + '</strong></div>' +
                    '<div style="display:flex;justify-content:space-between;"><span>Original TCP</span><strong>' + formatPeso(breakdown.original_tcp) + '</strong></div>' +
                    '<div style="display:flex;justify-content:space-between;"><span>Price Difference Amount</span><strong style="color:#059669;">+ ' + formatPeso(breakdown.additional_amount || 0) + '</strong></div>' +
                    '<div style="display:flex;justify-content:space-between;"><span>Final Contract Price</span><strong>' + formatPeso(breakdown.tcp_after_discount) + '</strong></div>' +
                    '<div style="display:flex;justify-content:space-between;"><span>Reservation Fee</span><strong style="color:#b45309;">' + formatPeso(breakdown.reservation_fee) + ' separate payment</strong></div>' +
                    '<hr style="border:0;border-top:1px solid #e2e8f0;margin:8px 0;">' +
                    '<div style="display:flex;justify-content:space-between;"><span>Amount to Finance</span><strong>' + formatPeso(breakdown.balance_to_finance || breakdown.tcp_after_discount) + '</strong></div>' +
                    '<div style="display:flex;justify-content:space-between;color:#166534;font-weight:800;"><span>' + finalLabel + '</span><strong>' + formatPeso(finalAmount) + '</strong></div>';
            }

            const formulaText = document.getElementById('approveFormulaText');
            if(formulaText && breakdown && breakdown.formula_text){
                formulaText.innerText = 'Formula: ' + breakdown.formula_text;
            }

            document.getElementById('approveModal').style.display = 'flex';
        }
        function closeApprove() {
            document.getElementById('approveModal').style.display = 'none';
        }

        function showContractModal(resId, buyerName, mode = 'upload') {
            document.getElementById('contractResId').value = resId;
            document.getElementById('contractBuyerName').innerText = 'Buyer: ' + buyerName;

            const title = document.getElementById('contractModalTitle');
            const submitLabel = document.getElementById('contractSubmitLabel');
            const flow = document.getElementById('contractFlowText');
            if(mode === 'replace'){
                if(title) title.innerHTML = '<i class="fa-solid fa-file-pen" style="color:#7c3aed;"></i> Replace / Update Signed Contract';
                if(submitLabel) submitLabel.innerText = 'Replace / Update Contract';
                if(flow) flow.innerHTML = '<strong>Flow:</strong> A signed contract already exists. Uploading a new file will replace/update the current contract and notify the buyer.';
            } else {
                if(title) title.innerHTML = '<i class="fa-solid fa-file-contract" style="color:#7c3aed;"></i> Upload Signed Contract';
                if(submitLabel) submitLabel.innerText = 'Upload Contract';
                if(flow) flow.innerHTML = '<strong>Flow:</strong> Upload the signed contract here after the reservation is approved. The buyer will be notified and can download it from the buyer dashboard.';
            }

            document.getElementById('contractModal').style.display = 'flex';
        }
        function closeContractModal() {
            document.getElementById('contractModal').style.display = 'none';
        }


        function detailRow(label, value){
            return '<div class="details-row"><span>' + escapeHtml(label) + '</span><strong>' + escapeHtml(value || 'N/A') + '</strong></div>';
        }

        function escapeHtml(value){
            return String(value ?? '').replace(/[&<>'"]/g, function(c){
                return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
            });
        }

        function showFullReservationModal(data){
            if(!data) return;
            document.getElementById('fullReservationTitle').innerText = 'Reservation #' + (data.reservation_id || '');
            document.getElementById('fullReservationSubtitle').innerText = 'Block ' + (data.property?.block || '') + ', Lot ' + (data.property?.lot || '') + ' • ' + (data.property?.location || '');

            const buyer = data.buyer || {};
            const property = data.property || {};
            const financial = data.financial || {};
            const docs = data.documents || {};
            const status = data.status || {};

            document.getElementById('fullBuyerDetails').innerHTML =
                detailRow('Name', buyer.name) + detailRow('Email', buyer.email) + detailRow('Contact', buyer.contact) + detailRow('Address', buyer.address) + detailRow('Agent', buyer.agent);

            document.getElementById('fullPropertyDetails').innerHTML =
                detailRow('Location', property.location) + detailRow('Block', property.block) + detailRow('Lot', property.lot) + detailRow('Area', property.area ? property.area + ' sqm' : '') + detailRow('Classification', property.classification);

            document.getElementById('fullFinancialDetails').innerHTML =
                detailRow('Payment Option', financial.payment_option) + detailRow('Final TCP', financial.final_tcp) + detailRow('Reservation Fee', financial.reservation_fee) + detailRow('Verified Land Payments', financial.verified_payment_total) + detailRow('Outstanding Balance', financial.outstanding_balance);

            const isCancelledReservation = status.reservation === 'CANCELLED';
            const isCancellationRequest = status.reservation === 'CANCELLATION REQUESTED';
            document.getElementById('fullDocumentDetails').innerHTML =
                detailRow('Reservation Status', status.reservation) +
                detailRow('ID Status', (isCancelledReservation || isCancellationRequest) ? 'Locked / paused during cancellation flow' : status.id) +
                detailRow('Payment Status', status.payment) +
                detailRow('Contract Status', (isCancelledReservation || isCancellationRequest) ? 'No contract action required during cancellation flow' : status.contract) +
                detailRow('Fee Proof', docs.fee_proof) + detailRow('Valid ID', docs.valid_id) + detailRow('Live Selfie', docs.live_selfie) + detailRow('Contract', docs.contract) +
                (isCancelledReservation ? detailRow('Cancelled By', status.cancelled_by) + detailRow('Cancelled At', status.cancelled_at) + detailRow('Reason', status.cancellation_reason) : '') +
                (isCancellationRequest ? detailRow('Requested At', status.cancellation_requested_at) + detailRow('Request Reason', status.cancellation_reason) : '');

            const history = Array.isArray(data.history) ? data.history : [];
            document.getElementById('fullHistoryDetails').innerHTML = history.map(item =>
                '<div class="history-item"><strong>' + escapeHtml(item.label) + '</strong><span>' + escapeHtml(item.date) + '</span><small>' + escapeHtml(item.note) + '</small></div>'
            ).join('') || '<div style="color:#64748b;font-size:12px;">No history available.</div>';

            document.getElementById('fullReservationModal').style.display = 'flex';
        }

        function closeFullReservationModal(){
            document.getElementById('fullReservationModal').style.display = 'none';
        }

        function filterReservations(){
            const q = (document.getElementById('reservationSearch')?.value || '').toLowerCase().trim();
            const payment = document.getElementById('paymentFilter')?.value || 'ALL';
            const idFilter = document.getElementById('idFilter')?.value || 'ALL';
            const contract = document.getElementById('contractFilter')?.value || 'ALL';
            const balance = document.getElementById('balanceFilter')?.value || 'ALL';
            const workflow = document.getElementById('workflowFilter')?.value || 'ALL';
            const sortBy = document.getElementById('sortFilter')?.value || 'NEWEST';

            const rows = Array.from(document.querySelectorAll('.reservation-row'));

            rows.forEach(row => {
                const text = row.dataset.search || '';
                const rowPayment = row.dataset.payment || '';
                const rowId = row.dataset.idstatus || '';
                const rowContract = row.dataset.contract || '';
                const rowBalance = row.dataset.balance || '';
                const rowWorkflow = row.dataset.workflow || '';

                let show = true;
                if(q && !text.includes(q)) show = false;
                if(payment !== 'ALL' && rowPayment !== payment) show = false;
                if(contract !== 'ALL' && rowContract !== contract) show = false;
                if(balance !== 'ALL' && rowBalance !== balance) show = false;
                if(workflow !== 'ALL' && !rowWorkflow.split('|').includes(workflow)) show = false;

                if(idFilter === 'VERIFIED' && rowId !== 'MANUALLY VERIFIED') show = false;
                if(idFilter === 'PENDING' && rowId === 'MANUALLY VERIFIED') show = false;
                if(idFilter === 'MISMATCH' && rowId !== 'NAME MISMATCH') show = false;
                if(idFilter === 'REJECTED' && rowId !== 'REJECTED') show = false;

                row.style.display = show ? '' : 'none';
            });

            const tbody = document.querySelector('.table-container tbody');
            if(tbody){
                rows.sort((a, b) => {
                    const dateA = Number(a.dataset.sortDate || 0);
                    const dateB = Number(b.dataset.sortDate || 0);
                    const tcpA = Number(a.dataset.sortTcp || 0);
                    const tcpB = Number(b.dataset.sortTcp || 0);
                    const balA = Number(a.dataset.sortBalance || 0);
                    const balB = Number(b.dataset.sortBalance || 0);

                    if(sortBy === 'OLDEST') return dateA - dateB;
                    if(sortBy === 'TCP_HIGH') return tcpB - tcpA;
                    if(sortBy === 'TCP_LOW') return tcpA - tcpB;
                    if(sortBy === 'UNPAID_FIRST') return balB - balA;
                    return dateB - dateA; // NEWEST
                });

                rows.forEach(row => tbody.appendChild(row));
            }
        }

        function resetReservationFilters(){
            document.getElementById('reservationSearch').value = '';
            document.getElementById('paymentFilter').value = 'ALL';
            document.getElementById('idFilter').value = 'ALL';
            document.getElementById('contractFilter').value = 'ALL';
            document.getElementById('balanceFilter').value = 'ALL';
            document.getElementById('workflowFilter').value = 'ALL';
            document.getElementById('sortFilter').value = 'NEWEST';
            filterReservations();
        }

        document.addEventListener('keydown', function(e){
            if(e.key === "Escape") { closeDoc(); closeVerify(); closeApprove(); closeContractModal(); closeFullReservationModal(); }
        });
    </script>
<script>
function toggleFinanceMenu(event){
    if(event){
        event.preventDefault();
        event.stopPropagation();
    }

    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');

    submenu.classList.toggle('show');

    if(submenu.classList.contains('show')){
        arrow.classList.remove('fa-chevron-down');
        arrow.classList.add('fa-chevron-up');
    } else {
        arrow.classList.remove('fa-chevron-up');
        arrow.classList.add('fa-chevron-down');
    }
}
</script>

<script>
function toggleSidebar(){
    if(window.innerWidth <= 768){
        document.body.classList.toggle('sidebar-open');
    } else {
        document.body.classList.toggle('sidebar-collapsed');
    }
}

document.addEventListener('click', function(e){
    if(window.innerWidth <= 768 && document.body.classList.contains('sidebar-open')){
        const sidebar = document.querySelector('.sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        if(sidebar && toggle && !sidebar.contains(e.target) && !toggle.contains(e.target)){
            document.body.classList.remove('sidebar-open');
        }
    }
});
</script>
</body>
</html>
