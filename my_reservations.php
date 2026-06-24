<?php
// my_reservations.php

ob_start();

require_once 'config.php';

// Require Login
checkLogin();

// Buyer Access Only
requireRole(['BUYER']);

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$alert_msg = "";
$alert_type = "";

function jej_valid_post_token(): bool {
    $posted = $_POST['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';
    return $posted !== '' && $session !== '' && hash_equals($session, $posted);
}

function jej_redirect_self($params = []) {
    $query = [];
    foreach (['status', 'q'] as $key) {
        if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $query[$key] = trim((string)$_GET[$key]);
        }
    }
    foreach ($params as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    $url = 'my_reservations.php';
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }
    header('Location: ' . $url);
    exit();
}

function jej_money($value, $decimals = 0): string {
    return '₱' . number_format((float)$value, $decimals);
}

function jej_doc_url($folder, $filename): string {
    $filename = trim((string)$filename);
    if ($filename === '') return '';
    if (function_exists('jej_file_url')) {
        return jej_file_url($folder, $filename);
    }
    return '';
}

function jej_sum_verified_transactions($conn, string $descriptionLike, string $verifiedFilterSql, bool $excludeReservationFee = false): float {
    $sql = "
        SELECT COALESCE(SUM(amount),0) AS total_paid
        FROM transactions
        WHERE type='INCOME'
        {$verifiedFilterSql}
        AND description LIKE ?
    ";

    if ($excludeReservationFee) {
        $sql .= " AND description NOT LIKE '%Reservation Fee%'";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0.00;
    }

    $stmt->bind_param("s", $descriptionLike);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float)($row['total_paid'] ?? 0);
}

function jej_status_label(array $model): string {
    if (!empty($model['is_fully_paid'])) {
        return 'FULLY PAID';
    }
    return strtoupper((string)($model['status'] ?? 'PENDING'));
}

function jej_badge_class(string $label): string {
    return preg_replace('/[^A-Z0-9_-]/', '-', strtoupper($label));
}

function jej_clean_review_status($value): string {
    $status = strtoupper(trim((string)$value));
    $status = preg_replace('/\s+/', ' ', $status);
    return $status;
}

function jej_table_has_column(mysqli $conn, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEscaped = $conn->real_escape_string($column);
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$columnEscaped'");
    return ($check && $check->num_rows > 0);
}

function jej_cancel_column_flags(mysqli $conn): array {
    return [
        'cancelled_by' => jej_table_has_column($conn, 'reservations', 'cancelled_by'),
        'cancelled_at' => jej_table_has_column($conn, 'reservations', 'cancelled_at'),
        'cancellation_reason' => jej_table_has_column($conn, 'reservations', 'cancellation_reason'),
        'cancellation_status' => jej_table_has_column($conn, 'reservations', 'cancellation_status'),
        'cancellation_requested_at' => jej_table_has_column($conn, 'reservations', 'cancellation_requested_at'),
        'cancellation_action_by' => jej_table_has_column($conn, 'reservations', 'cancellation_action_by'),
        'cancellation_action_at' => jej_table_has_column($conn, 'reservations', 'cancellation_action_at'),
        'cancellation_admin_note' => jej_table_has_column($conn, 'reservations', 'cancellation_admin_note'),
    ];
}

function jej_clean_cancel_reason($value): string {
    $reason = trim((string)$value);
    $reason = strip_tags($reason);
    $reason = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $reason);
    $reason = preg_replace('/\s+/u', ' ', $reason);
    if (function_exists('mb_substr')) {
        return mb_substr($reason, 0, 255, 'UTF-8');
    }
    return substr($reason, 0, 255);
}

function jej_build_cancel_reason(): string {
    $choice = jej_clean_cancel_reason($_POST['cancellation_reason_choice'] ?? '');
    $details = jej_clean_cancel_reason($_POST['cancellation_reason_details'] ?? '');
    $legacy = jej_clean_cancel_reason($_POST['cancellation_reason'] ?? '');

    $parts = [];
    if ($choice !== '') $parts[] = $choice;
    if ($details !== '') $parts[] = $details;

    $reason = !empty($parts) ? implode(' - ', $parts) : $legacy;
    $reason = jej_clean_cancel_reason($reason);

    if ($reason === '' || strlen($reason) < 5) {
        throw new Exception("Please provide a cancellation reason before submitting.");
    }

    return $reason;
}

function jej_reservation_is_fully_paid_for_buyer(mysqli $conn, array $row, string $verifiedFilterSql): bool {
    $status = strtoupper(trim((string)($row['status'] ?? '')));
    if ($status !== 'APPROVED') {
        return false;
    }

    $reservation_fee = (float)(
        $row['reservation_fee']
        ?? $row['reservation_amount']
        ?? $row['fee_amount']
        ?? 5000
    );
    if ($reservation_fee <= 0) $reservation_fee = 5000;

    $months = (int)($row['installment_months'] ?? 36);
    if ($months <= 0) $months = 36;

    $payment_type_raw = $row['payment_option']
        ?? $row['payment_scheme']
        ?? $row['payment_type']
        ?? 'INSTALLMENT';

    $lot_for_pricing = [
        'location' => $row['location'] ?? '',
        'classification' => $row['classification'] ?? 'INNER',
        'area' => $row['area'] ?? 0,
        'price_per_sqm' => $row['price_per_sqm'] ?? 0,
        'total_price' => $row['total_price'] ?? 0,
    ];

    if (function_exists('jej_compute_payment_pricing')) {
        $pricing = jej_compute_payment_pricing($conn, $lot_for_pricing, $payment_type_raw, $months, $reservation_fee);
    } else {
        $area = (float)($row['area'] ?? 0);
        $base_psqm = (float)($row['price_per_sqm'] ?? 0);
        $base_tcp = (float)($row['total_price'] ?? 0);
        if ($base_tcp <= 0 && $area > 0) $base_tcp = $area * $base_psqm;
        $is_cash = (stripos((string)$payment_type_raw, 'cash') !== false);
        $final_tcp = $is_cash ? $base_tcp : ($area * ($base_psqm + 500));
        $pricing = [
            'payment_code' => $is_cash ? 'SPOT_CASH' : 'INSTALLMENT',
            'final_tcp' => $final_tcp,
            'required_dp' => $is_cash ? 0 : round($final_tcp * 0.20, 2),
        ];
    }

    $res_id = (int)($row['id'] ?? 0);
    $desc_like_all = "%Res#{$res_id}%";
    $desc_like_dp = "%Down Payment%Res#{$res_id}%";
    $total_verified_paid = jej_sum_verified_transactions($conn, $desc_like_all, $verifiedFilterSql, true);
    $dp_paid_amount = jej_sum_verified_transactions($conn, $desc_like_dp, $verifiedFilterSql);

    $is_cash_payment = (($pricing['payment_code'] ?? 'INSTALLMENT') === 'SPOT_CASH');
    $final_tcp = (float)($pricing['final_tcp'] ?? ($row['total_price'] ?? 0));
    $dp_amount = (float)($pricing['required_dp'] ?? 0);

    $cash_balance_due = max($final_tcp - $total_verified_paid, 0);
    $dp_remaining = max($dp_amount - $dp_paid_amount, 0);

    return ($is_cash_payment && $cash_balance_due <= 0.009)
        || (!$is_cash_payment && $dp_remaining <= 0.009);
}

function jej_id_review_state(array $row): string {
    // Support common column names used by different JEJ versions.
    $raw = '';
    foreach (['id_verification_status', 'valid_id_status', 'id_status', 'document_status'] as $col) {
        if (isset($row[$col]) && trim((string)$row[$col]) !== '') {
            $raw = $row[$col];
            break;
        }
    }

    $status = jej_clean_review_status($raw);

    // reservation.php saves approved KYC as MANUALLY VERIFIED.
    // Treat it as verified on the buyer dashboard so the status reflects immediately.
    if (in_array($status, [
        'VERIFIED',
        'APPROVED',
        'APPROVED ID',
        'ID VERIFIED',
        'VALID ID VERIFIED',
        'MANUALLY VERIFIED',
        'MANUAL VERIFIED',
        'MANUALLY-VERIFIED',
        'MANUAL-VERIFIED'
    ], true)) {
        return 'VERIFIED';
    }

    if (in_array($status, ['REJECTED', 'DECLINED', 'DENIED', 'INVALID', 'INVALID ID', 'ID REJECTED', 'NAME MISMATCH'], true)) {
        return 'REJECTED';
    }

    if (in_array($status, ['OCR MATCHED'], true)) {
        return 'OCR_MATCHED';
    }

    if (in_array($status, ['PENDING REVIEW', 'PENDING', 'FOR REVIEW', 'VERIFYING', 'UNDER REVIEW'], true)) {
        return 'PENDING';
    }

    return $status !== '' ? $status : 'PENDING';
}

function jej_id_review_label(string $state): string {
    if ($state === 'VERIFIED') return 'Verified';
    if ($state === 'REJECTED') return 'Rejected';
    if ($state === 'OCR_MATCHED') return 'OCR Matched';
    return 'Pending Review';
}

function jej_next_step_message(array $m): string {
    $status = strtoupper((string)($m['status'] ?? 'PENDING'));

    if ($status === 'PENDING') {
        if (($m['id_review_state'] ?? '') === 'REJECTED') {
            return 'Your valid ID / selfie verification was rejected by admin. Please upload a clearer replacement document or contact JEJ before approval can continue.';
        }
        if (($m['id_review_state'] ?? '') === 'VERIFIED') {
            return 'Your ID and selfie are already verified. Please wait for reservation payment verification and management approval.';
        }
        if (($m['id_review_state'] ?? '') === 'OCR_MATCHED') {
            return 'Your ID details matched the initial check. Please wait for final admin verification and management approval.';
        }
        return 'Your reservation is under review. Please wait for admin verification of your ID, selfie, and reservation payment proof.';
    }

    if ($status === 'APPROVED') {
        if (!empty($m['is_cash_payment'])) {
            if (($m['cash_balance_due'] ?? 0) <= 0) {
                return 'This reservation is fully paid and completed. You may view your SOA, receipt, and contract anytime.';
            }
            return 'Your account number has been generated. Please settle your remaining spot cash balance within 20 days.';
        }

        if (($m['dp_remaining'] ?? 0) <= 0) {
            return 'Your required down payment is fully verified. You may view your SOA and payment terms.';
        }

        return 'Your account number has been generated. Please settle your required down payment within 20 days.';
    }

    if ($status === 'REJECTED') {
        return 'Your reservation was not approved. Please contact JEJ Top Priority Corporation for assistance.';
    }

    if ($status === 'CANCELLED' || $status === 'CANCELED') {
        return 'This reservation request has been cancelled.';
    }

    return 'Please check the latest status of your reservation.';
}

/*
|--------------------------------------------------------------------------
| Transactions verification filter
|--------------------------------------------------------------------------
| Payment totals should count verified payments only. The code detects the
| verification/status column used by the current database.
|--------------------------------------------------------------------------
*/
$transactionColumns = [];
$txCols = $conn->query("SHOW COLUMNS FROM transactions");
if ($txCols) {
    while ($col = $txCols->fetch_assoc()) {
        $transactionColumns[$col['Field']] = true;
    }
}

$verified_filter_sql = "";
if (isset($transactionColumns['payment_status'])) {
    $verified_filter_sql = " AND UPPER(payment_status) = 'VERIFIED' ";
} elseif (isset($transactionColumns['verification_status'])) {
    $verified_filter_sql = " AND UPPER(verification_status) = 'VERIFIED' ";
} elseif (isset($transactionColumns['status'])) {
    $verified_filter_sql = " AND UPPER(status) IN ('VERIFIED','APPROVED','POSTED','PAID') ";
}

/*
|--------------------------------------------------------------------------
| Pending cancellation request
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_reservation'])) {
    if (!jej_valid_post_token()) {
        $alert_msg = "Session expired. Please refresh the page and try again.";
        $alert_type = "error";
    } else {
        $res_id = (int)($_POST['res_id'] ?? 0);

        $conn->begin_transaction();
        try {
            $cancel_cols = jej_cancel_column_flags($conn);
            $cancel_status_select = $cancel_cols['cancellation_status'] ? ', cancellation_status' : '';
            $stmt = $conn->prepare("
                SELECT r.*{$cancel_status_select},
                       l.total_price,
                       l.area,
                       l.price_per_sqm,
                       l.location,
                       l.classification
                FROM reservations r
                JOIN lots l ON l.id = r.lot_id
                WHERE r.id = ? AND r.user_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            if (!$stmt) {
                throw new Exception("Unable to prepare cancellation request.");
            }

            $stmt->bind_param("ii", $res_id, $user_id);
            $stmt->execute();
            $reservation = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$reservation) {
                throw new Exception("Reservation not found.");
            }

            $reservation_status = strtoupper(trim((string)$reservation['status']));
            $existing_cancel_status = strtoupper(trim((string)($reservation['cancellation_status'] ?? '')));
            if (!in_array($reservation_status, ['PENDING', 'APPROVED'], true)) {
                throw new Exception("Only pending or approved reservations can be cancelled/requested for cancellation.");
            }
            if ($existing_cancel_status === 'PENDING') {
                throw new Exception("You already have a pending cancellation request for this reservation.");
            }

            $cancel_reason = jej_build_cancel_reason();

            if (in_array($reservation_status, ['PENDING', 'APPROVED'], true)) {
                if ($reservation_status === 'APPROVED' && jej_reservation_is_fully_paid_for_buyer($conn, $reservation, $verified_filter_sql)) {
                    throw new Exception("This reservation is fully paid. Cancellation is locked. Please contact JEJ Top Priority Corporation for refund/accounting assistance.");
                }

                if (!$cancel_cols['cancellation_status'] || !$cancel_cols['cancellation_requested_at'] || !$cancel_cols['cancellation_reason']) {
                    throw new Exception("Cancellation request columns are missing. Please ask admin to run the database update first.");
                }

                $request = $conn->prepare("
                    UPDATE reservations
                    SET cancellation_status = 'PENDING',
                        cancellation_reason = ?,
                        cancellation_requested_at = NOW()
                    WHERE id = ? AND user_id = ? AND UPPER(status) IN ('PENDING','APPROVED')
                ");
                if (!$request) {
                    throw new Exception("Unable to submit cancellation request.");
                }

                $request->bind_param("sii", $cancel_reason, $res_id, $user_id);
                $request->execute();
                if ($request->affected_rows < 1) {
                    throw new Exception("Cancellation request was not applied.");
                }
                $request->close();

                if (function_exists('add_audit_log')) {
                    add_audit_log(
                    $conn,
                    $user_id,
                    'Requested Reservation Cancellation',
                    'Buyer requested cancellation for ' . strtolower($reservation_status) . ' reservation #' . $res_id . ' | Reason: ' . $cancel_reason,
                    'reservations',
                    $res_id
                );
                }

                $cancel_details = [
                    'buyer_name' => $_SESSION['fullname'] ?? 'Buyer',
                    'block_no' => '',
                    'lot_no' => '',
                    'location' => '',
                ];

                $detail_stmt = $conn->prepare("
                    SELECT u.fullname AS buyer_name,
                           l.block_no,
                           l.lot_no,
                           l.location
                    FROM reservations r
                    JOIN users u ON u.id = r.user_id
                    JOIN lots l ON l.id = r.lot_id
                    WHERE r.id = ? AND r.user_id = ?
                    LIMIT 1
                ");
                if ($detail_stmt) {
                    $detail_stmt->bind_param("ii", $res_id, $user_id);
                    $detail_stmt->execute();
                    $detail_row = $detail_stmt->get_result()->fetch_assoc();
                    if ($detail_row) {
                        $cancel_details = array_merge($cancel_details, $detail_row);
                    }
                    $detail_stmt->close();
                }

            $admin_title = 'Cancellation Request';
            $admin_message = trim((string)$cancel_details['buyer_name']) .
                ' requested cancellation for ' . strtolower($reservation_status) . ' reservation #' . $res_id .
                ' for Block ' . trim((string)$cancel_details['block_no']) .
                ' Lot ' . trim((string)$cancel_details['lot_no']) .
                ' at ' . trim((string)$cancel_details['location']) .
                    '. Reason: ' . $cancel_reason . '. Please review in reservation.php under Cancellation Requests.';

                $admins = $conn->query("
                    SELECT id
                    FROM users
                    WHERE role IN ('SUPER ADMIN','ADMIN','MANAGER')
                ");
                if ($admins) {
                    while ($admin = $admins->fetch_assoc()) {
                        $admin_id = (int)$admin['id'];
                        $notif = $conn->prepare("
                            INSERT INTO notifications
                            (user_id, title, message, is_read, created_at)
                            VALUES (?, ?, ?, 0, NOW())
                        ");
                        if ($notif) {
                            $notif->bind_param("iss", $admin_id, $admin_title, $admin_message);
                            $notif->execute();
                            $notif->close();
                        }
                    }
                }

            $conn->commit();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $alert_msg = "Cancellation request submitted successfully. Admin will review and accept/reject the request.";
            $alert_type = "success";
            } else {

            $setParts = ["status = 'CANCELLED'"];
            $types = '';
            $values = [];

            if ($cancel_cols['cancelled_by']) {
                $setParts[] = "cancelled_by = ?";
                $types .= 'i';
                $values[] = $user_id;
            }

            if ($cancel_cols['cancelled_at']) {
                $setParts[] = "cancelled_at = NOW()";
            }

            if ($cancel_cols['cancellation_reason']) {
                $setParts[] = "cancellation_reason = ?";
                $types .= 's';
                $values[] = $cancel_reason;
            }
            if ($cancel_cols['cancellation_status']) {
                $setParts[] = "cancellation_status = 'ACCEPTED'";
            }
            if ($cancel_cols['cancellation_requested_at']) {
                $setParts[] = "cancellation_requested_at = NOW()";
            }

            $types .= 'ii';
            $values[] = $res_id;
            $values[] = $user_id;

            $update = $conn->prepare("
                UPDATE reservations
                SET " . implode(', ', $setParts) . "
                WHERE id = ? AND user_id = ? AND UPPER(status) = 'PENDING'
            ");
            if (!$update) {
                throw new Exception("Unable to update reservation status.");
            }

            $update->bind_param($types, ...$values);
            $update->execute();
            if ($update->affected_rows < 1) {
                throw new Exception("Reservation cancellation was not applied.");
            }
            $update->close();

            $lot_id = (int)$reservation['lot_id'];

            // Return the lot to AVAILABLE only when there is no other active reservation
            // for the same lot. Cancelled records remain as audit/history only.
            $active = $conn->prepare("
                SELECT COUNT(*) AS active_count
                FROM reservations
                WHERE lot_id = ?
                  AND UPPER(status) IN ('PENDING','APPROVED')
            ");
            if (!$active) {
                throw new Exception("Unable to check active reservations for this lot.");
            }

            $active->bind_param("i", $lot_id);
            $active->execute();
            $active_count = (int)($active->get_result()->fetch_assoc()['active_count'] ?? 0);
            $active->close();

            if ($active_count === 0) {
                $lotUpdate = $conn->prepare("
                    UPDATE lots
                    SET status = 'AVAILABLE'
                    WHERE id = ?
                      AND UPPER(status) IN ('RESERVED','PENDING','HOLD','ON HOLD')
                ");
                if (!$lotUpdate) {
                    throw new Exception("Unable to return the lot to available status.");
                }

                $lotUpdate->bind_param("i", $lot_id);
                $lotUpdate->execute();
                $lotUpdate->close();
            }

            if (function_exists('add_audit_log')) {
                add_audit_log(
                    $conn,
                    $user_id,
                    'Cancelled Pending Reservation',
                    'Buyer cancelled reservation #' . $res_id . ' | Reason: ' . $cancel_reason,
                    'reservations',
                    $res_id
                );
            }

            // Notify admin/management when a buyer cancels a pending reservation.
            // This keeps reservation.php/admin users aware of buyer-side cancellations.
            $cancel_details = [
                'buyer_name' => $_SESSION['fullname'] ?? 'Buyer',
                'block_no' => '',
                'lot_no' => '',
                'location' => '',
            ];

            $detail_stmt = $conn->prepare("
                SELECT u.fullname AS buyer_name,
                       l.block_no,
                       l.lot_no,
                       l.location
                FROM reservations r
                JOIN users u ON u.id = r.user_id
                JOIN lots l ON l.id = r.lot_id
                WHERE r.id = ? AND r.user_id = ?
                LIMIT 1
            "
            );
            if ($detail_stmt) {
                $detail_stmt->bind_param("ii", $res_id, $user_id);
                $detail_stmt->execute();
                $detail_row = $detail_stmt->get_result()->fetch_assoc();
                if ($detail_row) {
                    $cancel_details = array_merge($cancel_details, $detail_row);
                }
                $detail_stmt->close();
            }

            $admin_title = 'Buyer Cancelled Reservation';
            $admin_message = trim((string)$cancel_details['buyer_name']) .
                ' cancelled reservation #' . $res_id .
                ' for Block ' . trim((string)$cancel_details['block_no']) .
                ' Lot ' . trim((string)$cancel_details['lot_no']) .
                ' at ' . trim((string)$cancel_details['location']) .
                '. The request is now marked CANCELLED. Reason: ' . $cancel_reason . '. If no other active reservation exists, the lot is available again.';

            $admins = $conn->query("
                SELECT id
                FROM users
                WHERE role IN ('SUPER ADMIN','ADMIN','MANAGER')
            "
            );
            if ($admins) {
                while ($admin = $admins->fetch_assoc()) {
                    $admin_id = (int)$admin['id'];
                    $notif = $conn->prepare("
                        INSERT INTO notifications
                        (user_id, title, message, is_read, created_at)
                        VALUES (?, ?, ?, 0, NOW())
                    "
                    );
                    if ($notif) {
                        $notif->bind_param("iss", $admin_id, $admin_title, $admin_message);
                        $notif->execute();
                        $notif->close();
                    }
                }
            }

            $conn->commit();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $alert_msg = "Pending reservation cancelled successfully. Admin has been notified and the lot was returned to AVAILABLE if no other active reservation exists.";
            $alert_type = "success";
            }

        } catch (Throwable $e) {
            $conn->rollback();
            $alert_msg = $e->getMessage();
            $alert_type = "error";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Handle additional payment proof upload
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_qr_payment'])) {
    if (!jej_valid_post_token()) {
        $alert_msg = "Session expired. Please refresh the page and try again.";
        $alert_type = "error";
    } else {
        $res_id = intval($_POST['res_id'] ?? 0);

        if (isset($_FILES['dp_receipt']) && $_FILES['dp_receipt']['error'] == 0) {
            $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
            $ext = strtolower(pathinfo($_FILES['dp_receipt']['name'] ?? '', PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed_ext, true)) {
                $alert_msg = "Please upload PDF, JPG, JPEG, or PNG only.";
                $alert_type = "error";
            } else {
                $upload = jej_storage_upload(
                    $_FILES['dp_receipt'],
                    'payment_proofs',
                    'DP_RECEIPT',
                    5
                );

                if (!$upload['success']) {
                    $alert_msg = $upload['message'];
                    $alert_type = "error";
                } else {
                    $filename = $upload['filename'];

                    $stmt = $conn->prepare("
                        UPDATE reservations
                        SET dp_proof = ?, dp_status = 'VERIFYING'
                        WHERE id = ? AND user_id = ?
                    ");

                    if ($stmt) {
                        $stmt->bind_param("sii", $filename, $res_id, $user_id);

                        if ($stmt->execute()) {
                            $alert_msg = "Payment proof uploaded successfully. Our team will verify it shortly.";
                            $alert_type = "success";

                            if (function_exists('add_audit_log')) {
                                add_audit_log(
                                    $conn,
                                    $user_id,
                                    'Uploaded Payment Proof',
                                    'Buyer uploaded additional payment proof for reservation #' . $res_id,
                                    'reservations',
                                    $res_id
                                );
                            }
                        } else {
                            $alert_msg = "Database Error: Failed to update payment status.";
                            $alert_type = "error";
                        }

                        $stmt->close();
                    } else {
                        $alert_msg = "Database Error: " . $conn->error;
                        $alert_type = "error";
                    }
                }
            }
        } else {
            $alert_msg = "Please select a valid PDF, JPG, or PNG receipt.";
            $alert_type = "error";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/
$unread_count = 0;
$notif_stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $user_id);
    $notif_stmt->execute();
    $notif_stmt->bind_result($unread_count);
    $notif_stmt->fetch();
    $notif_stmt->close();
}


// --- FETCH LOGGED-IN BUYER PROFILE PHOTO ---
$current_profile_photo = '';
if (!function_exists('jej_user_column_exists')) {
    function jej_user_column_exists(mysqli $conn, string $column): bool {
        static $cache = [];
        $key = strtolower($column);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
        if (!$stmt) {
            $cache[$key] = false;
            return false;
        }
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        $cache[$key] = ((int)$count > 0);
        return $cache[$key];
    }
}
if (!function_exists('jej_safe_profile_photo_src')) {
    function jej_safe_profile_photo_src(?string $path): string {
        $path = trim((string)$path);
        if ($path === '') return '';
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        if (strpos($path, '..') !== false || preg_match('#^(https?:)?//#i', $path)) return '';
        if (!preg_match('#^uploads/profile_photos/[A-Za-z0-9._-]+\.(jpe?g|png|webp)$#i', $path)) return '';
        return is_file(__DIR__ . '/' . $path) ? $path : '';
    }
}
if ($user_id > 0 && jej_user_column_exists($conn, 'profile_photo')) {
    $photo_stmt = $conn->prepare("SELECT profile_photo FROM users WHERE id = ? LIMIT 1");
    if ($photo_stmt) {
        $photo_stmt->bind_param('i', $user_id);
        $photo_stmt->execute();
        $photo_stmt->bind_result($profile_photo_db);
        if ($photo_stmt->fetch()) {
            $current_profile_photo = jej_safe_profile_photo_src($profile_photo_db);
        }
        $photo_stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Fetch buyer reservations only
|--------------------------------------------------------------------------
| Buyer security: this page only selects WHERE r.user_id = ?.
|--------------------------------------------------------------------------
*/
$query = "
    SELECT r.*,
           l.block_no,
           l.lot_no,
           l.property_type,
           l.total_price,
           l.location,
           l.area,
           l.price_per_sqm,
           l.classification,
           l.id AS lot_record_id
    FROM reservations r
    JOIN lots l ON r.lot_id = l.id
    WHERE r.user_id = ?
    ORDER BY r.reservation_date DESC
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$reservation_models = [];
$pending_by_lot = [];

while ($row = $result->fetch_assoc()) {
    $status = strtoupper((string)($row['status'] ?? 'PENDING'));
    $cancellation_status = strtoupper(trim((string)($row['cancellation_status'] ?? '')));
    $has_pending_cancellation_request = (in_array($status, ['PENDING', 'APPROVED'], true) && $cancellation_status === 'PENDING');

    $payment_type_raw = $row['payment_option']
        ?? $row['payment_scheme']
        ?? $row['payment_type']
        ?? 'INSTALLMENT';

    $reservation_fee = (float)(
        $row['reservation_fee']
        ?? $row['reservation_amount']
        ?? $row['fee_amount']
        ?? 5000
    );
    if ($reservation_fee <= 0) $reservation_fee = 5000;

    $months = (int)($row['installment_months'] ?? 36);
    if ($months <= 0) $months = 36;

    $lot_for_pricing = [
        'location' => $row['location'] ?? '',
        'classification' => $row['classification'] ?? 'INNER',
        'area' => $row['area'] ?? 0,
        'price_per_sqm' => $row['price_per_sqm'] ?? 0,
        'total_price' => $row['total_price'] ?? 0,
    ];

    if (function_exists('jej_compute_payment_pricing')) {
        $pricing = jej_compute_payment_pricing($conn, $lot_for_pricing, $payment_type_raw, $months, $reservation_fee);
    } else {
        $area = (float)($row['area'] ?? 0);
        $base_psqm = (float)($row['price_per_sqm'] ?? 0);
        $base_tcp = (float)($row['total_price'] ?? 0);
        if ($base_tcp <= 0 && $area > 0) $base_tcp = $area * $base_psqm;
        $payment_code_fallback = (stripos((string)$payment_type_raw, 'cash') !== false) ? 'SPOT_CASH' : 'INSTALLMENT';
        $final_tcp_fallback = $payment_code_fallback === 'SPOT_CASH' ? $base_tcp : ($area * ($base_psqm + 500));
        $pricing = [
            'payment_code' => $payment_code_fallback,
            'payment_label' => $payment_code_fallback === 'SPOT_CASH' ? 'Spot Cash - Base Price / SQM, No DP' : 'Installment - Price / SQM + 20% DP',
            'cash_price_per_sqm' => $base_psqm,
            'selected_price_per_sqm' => $payment_code_fallback === 'SPOT_CASH' ? $base_psqm : ($base_psqm + 500),
            'cash_tcp' => $base_tcp,
            'final_tcp' => $final_tcp_fallback,
            'additional_per_sqm' => max(0, ($payment_code_fallback === 'SPOT_CASH' ? $base_psqm : ($base_psqm + 500)) - $base_psqm),
            'additional_amount' => max(0, $final_tcp_fallback - $base_tcp),
            'required_dp' => $payment_code_fallback === 'SPOT_CASH' ? 0 : round($final_tcp_fallback * 0.20, 2),
            'balance_to_finance' => $payment_code_fallback === 'SPOT_CASH' ? $final_tcp_fallback : max(0, $final_tcp_fallback - round($final_tcp_fallback * 0.20, 2)),
            'reservation_fee' => $reservation_fee,
        ];
    }

    $res_id = (int)$row['id'];
    $desc_like_dp = "%Down Payment%Res#{$res_id}%";
    $desc_like_rf = "%Reservation Fee%Res#{$res_id}%";
    $desc_like_all = "%Res#{$res_id}%";

    $dp_paid_amount = jej_sum_verified_transactions($conn, $desc_like_dp, $verified_filter_sql);
    $reservation_fee_paid_amount = jej_sum_verified_transactions($conn, $desc_like_rf, $verified_filter_sql);
    $total_verified_paid = jej_sum_verified_transactions($conn, $desc_like_all, $verified_filter_sql, true);

    $payment_code = $pricing['payment_code'] ?? 'INSTALLMENT';
    $is_cash_payment = ($payment_code === 'SPOT_CASH');
    $final_tcp = (float)($pricing['final_tcp'] ?? ($row['total_price'] ?? 0));
    $dp_amount = (float)($pricing['required_dp'] ?? 0);
    $balance_to_finance = (float)($pricing['balance_to_finance'] ?? $final_tcp);

    $cash_balance_due = max($final_tcp - $total_verified_paid, 0);
    $dp_remaining = max($dp_amount - $dp_paid_amount, 0);
    $reservation_fee_remaining = max($reservation_fee - $reservation_fee_paid_amount, 0);
    $is_fully_paid = ($status === 'APPROVED' && !$has_pending_cancellation_request) && (
        ($is_cash_payment && $cash_balance_due <= 0.009) ||
        (!$is_cash_payment && $dp_remaining <= 0.009)
    );

    if ($status === 'PENDING') {
        $lotKey = (int)$row['lot_id'];
        $pending_by_lot[$lotKey] = ($pending_by_lot[$lotKey] ?? 0) + 1;
    }

    $id_review_state = jej_id_review_state($row);
    $id_review_label = jej_id_review_label($id_review_state);

    $model = [
        'row' => $row,
        'id' => $res_id,
        'status' => $status,
        'payment_code' => $payment_code,
        'payment_label' => $pricing['payment_label'] ?? ($is_cash_payment ? 'Spot Cash' : 'Installment'),
        'cash_price_per_sqm' => (float)($pricing['cash_price_per_sqm'] ?? 0),
        'selected_price_per_sqm' => (float)($pricing['selected_price_per_sqm'] ?? 0),
        'additional_per_sqm' => (float)($pricing['additional_per_sqm'] ?? 0),
        'additional_amount' => (float)($pricing['additional_amount'] ?? 0),
        'cash_tcp' => (float)($pricing['cash_tcp'] ?? ($row['total_price'] ?? 0)),
        'final_tcp' => $final_tcp,
        'balance_to_finance' => $balance_to_finance,
        'reservation_fee' => $reservation_fee,
        'reservation_fee_paid_amount' => $reservation_fee_paid_amount,
        'reservation_fee_remaining' => $reservation_fee_remaining,
        'reservation_fee_status' => ($reservation_fee_paid_amount >= $reservation_fee) ? 'VERIFIED' : ((strtoupper((string)($row['dp_status'] ?? '')) === 'VERIFYING') ? 'PENDING VERIFICATION' : 'PENDING VERIFICATION'),
        'dp_amount' => $dp_amount,
        'dp_paid_amount' => $dp_paid_amount,
        'dp_remaining' => $dp_remaining,
        'total_verified_paid' => $total_verified_paid,
        'cash_balance_due' => $cash_balance_due,
        'is_cash_payment' => $is_cash_payment,
        'is_fully_paid' => $is_fully_paid,
        'cancellation_status' => $cancellation_status,
        'has_pending_cancellation_request' => $has_pending_cancellation_request,
        'display_status' => $has_pending_cancellation_request ? 'CANCELLATION REQUESTED' : ($is_fully_paid ? 'FULLY PAID' : $status),
        'id_review_state' => $id_review_state,
        'id_review_label' => $id_review_label,
        'duplicate_pending' => false,
    ];

    $model['next_step'] = $has_pending_cancellation_request
        ? 'Your cancellation request has been submitted and is waiting for admin review.'
        : jej_next_step_message($model);
    $reservation_models[] = $model;
}
$stmt->close();

foreach ($reservation_models as &$m) {
    $lotKey = (int)($m['row']['lot_id'] ?? 0);
    if (($m['status'] ?? '') === 'PENDING' && ($pending_by_lot[$lotKey] ?? 0) > 1) {
        $m['duplicate_pending'] = true;
    }
}
unset($m);

$duplicate_warning = false;
foreach ($reservation_models as $m) {
    if (!empty($m['duplicate_pending'])) {
        $duplicate_warning = true;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Summary cards
|--------------------------------------------------------------------------
*/
$total_reservations = count($reservation_models);
$ongoing_reservations_count = count(array_filter($reservation_models, fn($m) => !in_array(($m['status'] ?? ''), ['CANCELLED','CANCELED','REJECTED'], true) && empty($m['is_fully_paid'])));
$pending_count = count(array_filter($reservation_models, fn($m) => ($m['status'] ?? '') === 'PENDING'));
$approved_count = count(array_filter($reservation_models, fn($m) => ($m['status'] ?? '') === 'APPROVED' && empty($m['is_fully_paid'])));
$cancelled_count = count(array_filter($reservation_models, fn($m) => in_array(($m['status'] ?? ''), ['CANCELLED','CANCELED'], true)));
$rejected_count = count(array_filter($reservation_models, fn($m) => ($m['status'] ?? '') === 'REJECTED'));
$fully_paid_count = count(array_filter($reservation_models, fn($m) => !empty($m['is_fully_paid'])));
$total_current_due = 0;
$next_payment_due = null;

foreach ($reservation_models as $m) {
    if (($m['status'] ?? '') !== 'APPROVED') continue;
    if (!empty($m['has_pending_cancellation_request'])) continue;

    $due = !empty($m['is_cash_payment']) ? (float)$m['cash_balance_due'] : (float)$m['dp_remaining'];
    if ($due > 0) {
        $total_current_due += $due;
        if ($next_payment_due === null || $due < $next_payment_due) {
            $next_payment_due = $due;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$allowed_status_filters = ['ALL', 'ONGOING', 'PENDING', 'APPROVED', 'FULLY_PAID', 'CANCELLED', 'REJECTED'];
$default_status_filter = ($ongoing_reservations_count > 0 || $total_reservations === 0) ? 'ONGOING' : 'ALL';
$selected_status = strtoupper(trim($_GET['status'] ?? $default_status_filter));
if (!in_array($selected_status, $allowed_status_filters, true)) {
    $selected_status = $default_status_filter;
}

$search_q = trim((string)($_GET['q'] ?? ''));
$search_lower = function_exists('mb_strtolower') ? mb_strtolower($search_q, 'UTF-8') : strtolower($search_q);

$visible_models = [];
foreach ($reservation_models as $m) {
    $row = $m['row'];

    $rowStatus = strtoupper((string)$m['status']);
    $matches_status = true;
    if ($selected_status === 'ALL') {
        $matches_status = true;
    } elseif ($selected_status === 'ONGOING') {
        // Ongoing view: show only reservations that may still need buyer/admin action.
        $matches_status = !in_array($rowStatus, ['CANCELLED','CANCELED','REJECTED'], true) && empty($m['is_fully_paid']);
    } elseif ($selected_status === 'FULLY_PAID') {
        $matches_status = !empty($m['is_fully_paid']);
    } elseif ($selected_status === 'CANCELLED') {
        $matches_status = in_array($rowStatus, ['CANCELLED','CANCELED'], true);
    } elseif ($selected_status === 'APPROVED') {
        $matches_status = $rowStatus === 'APPROVED' && empty($m['is_fully_paid']);
    } else {
        $matches_status = $rowStatus === $selected_status;
    }

    $matches_search = true;
    if ($search_lower !== '') {
        $haystack = implode(' ', [
            $row['location'] ?? '',
            $row['block_no'] ?? '',
            $row['lot_no'] ?? '',
            $row['account_number'] ?? '',
            $m['payment_label'] ?? '',
            $m['display_status'] ?? '',
        ]);
        $haystack_lower = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
        $matches_search = strpos($haystack_lower, $search_lower) !== false;
    }

    if ($matches_status && $matches_search) {
        $visible_models[] = $m;
    }
}

$status_tabs = [
    'ALL' => 'All',
    'ONGOING' => 'Ongoing',
    'PENDING' => 'Pending',
    'APPROVED' => 'Approved',
    'FULLY_PAID' => 'Fully Paid',
    'CANCELLED' => 'Cancelled',
    'REJECTED' => 'Rejected',
];

$status_counts = array_fill_keys(array_keys($status_tabs), 0);
$status_counts['ALL'] = $total_reservations;
$status_counts['ONGOING'] = $ongoing_reservations_count;
$status_counts['FULLY_PAID'] = $fully_paid_count;
foreach ($reservation_models as $m) {
    $st = strtoupper((string)$m['status']);
    if ($st === 'CANCELED') $st = 'CANCELLED';
    if ($st === 'APPROVED' && !empty($m['is_fully_paid'])) continue;
    if (isset($status_counts[$st])) $status_counts[$st]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reservations | JEJ Top Priority Corporation</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,600;0,700;0,800&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary-yellow: #F4D03F;
            --dark-bg: #111827;
            --light-bg: #F8FAFC;
            --text-dark: #1e293b;
            --text-light: #ffffff;
            --success-green: #22c55e;
            --danger-red: #ef4444;
            --blue: #2563eb;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 20px 40px -10px rgba(0,0,0,0.12);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f1f5f9;
            color: var(--text-dark);
            line-height: 1.6;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        h1, h2, h3, h4 { font-family: 'Montserrat', sans-serif; }
        a { text-decoration: none; color: inherit; }

        @keyframes fadeInUp { 0% { opacity: 0; transform: translateY(40px); } 100% { opacity: 1; transform: translateY(0); } }
        @keyframes dropIn { from { opacity: 0; transform: scale(0.9) translateY(-10px); } to { opacity: 1; transform: scale(1) translateY(0); } }

        .animate-on-scroll { opacity: 0; transform: translateY(30px); transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1); }
        .animate-on-scroll.visible { opacity: 1; transform: translateY(0); }

        .navbar {
            position: fixed; top: 0; left: 0; width: 100%; padding: 18px 5%;
            display: flex; justify-content: space-between; align-items: center;
            z-index: 1000; color: white;
            background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .nav-logo { display: flex; align-items: center; }
        .nav-logo a { display: flex; align-items: center; }
        .nav-logo img { height: 70px; width: auto; object-fit: contain; filter: drop-shadow(0 2px 6px rgba(0,0,0,.35)); }
        .nav-logo-text { margin-left: 15px; }
        .nav-logo-text h2 { font-weight: 800; letter-spacing: 0.5px; font-size: 1.5rem; line-height: 1.1; text-shadow: 0 2px 4px rgba(0,0,0,0.5); color: white; }
        .nav-logo-text span { font-family: 'Roboto', sans-serif; font-size: 11px; font-weight: 500; letter-spacing: 1.5px; color: #cbd5e1; display: block; margin-top: 4px; }

        .nav-links { display: flex; gap: 40px; }
        .nav-links a { font-size: 1.05rem; font-weight: 700; transition: color 0.3s; color: white; }
        .nav-links a:hover { color: var(--primary-yellow); }

        .nav-actions { display: flex; align-items: center; gap: 20px; }
        .notification-bell { position: relative; color: white; font-size: 22px; transition: color 0.3s; }
        .notification-bell:hover { color: var(--primary-yellow); transform: scale(1.1); }
        .notification-dot { position: absolute; top: 0; right: -2px; width: 10px; height: 10px; background-color: #ef4444; border-radius: 50%; border: 2px solid var(--dark-bg); }

        .profile-dropdown-container { position: relative; }
        .profile-trigger {
            display: flex; align-items: center; gap: 10px; background: transparent;
            border: 1px solid rgba(255,255,255,0.2); cursor: pointer; padding: 6px 12px;
            border-radius: 40px; transition: all 0.2s ease; color: white;
        }
        .profile-trigger:hover { background: rgba(255,255,255,0.1); border-color: white; }
        .profile-info { text-align: right; max-width: 150px; }
        .profile-name { display: block; font-weight: 700; font-size: 0.9rem; font-family: 'Roboto', sans-serif; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .profile-role { display: block; font-size: 0.7rem; color: var(--primary-yellow); font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .avatar-circle { background: var(--primary-yellow); color: var(--dark-bg); border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1rem; flex-shrink: 0; }
        .avatar-circle.has-photo { background: transparent; padding: 0; overflow: hidden; border: 2px solid rgba(255,255,255,0.35); }
        .avatar-photo { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 50%; }

        .profile-dropdown-menu {
            display: none; position: absolute; top: 120%; right: 0; background: white;
            min-width: 220px; border-radius: 12px; box-shadow: var(--shadow-lg);
            border: 1px solid #f1f5f9; overflow: hidden; z-index: 100;
        }
        .profile-dropdown-menu.active { display: block; animation: dropIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards; transform-origin: top right; }
        .profile-dropdown-item { display: flex; align-items: center; gap: 12px; padding: 12px 20px; color: var(--text-dark); font-size: 0.95rem; font-weight: 600; transition: all 0.2s; }
        .profile-dropdown-item i { width: 20px; text-align: center; color: #94a3b8; transition: 0.2s; }
        .profile-dropdown-item:hover { background: #f8fafc; color: var(--dark-bg); padding-left: 25px; }
        .profile-dropdown-item:hover i { color: var(--primary-yellow); }
        .profile-dropdown-item.current {
            background: #fffbeb;
            color: var(--dark-bg);
            font-weight: 900;
            border-left: 4px solid var(--primary-yellow);
            padding-left: 16px;
        }
        .profile-dropdown-item.current i { color: var(--primary-yellow); }
        .profile-dropdown-item.current:hover { background: #fef3c7; padding-left: 20px; }
        .profile-dropdown-item.logout-btn { color: #ef4444; border-top: 1px solid #f1f5f9; }
        .profile-dropdown-item.logout-btn i { color: #ef4444; }

        .hero {
            margin-top: 85px;
            min-height: 235px;
            background: linear-gradient(to right, rgba(15, 23, 42, 0.88), rgba(15, 23, 42, 0.62)), url('https://images.unsplash.com/photo-1541888086425-d81bb19240f5?ixlib=rb-4.0.3') center/cover no-repeat;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: white; text-align: center; padding: 52px 20px 86px;
        }
        .hero h1 { font-size: 2.75rem; font-weight: 900; margin-bottom: 10px; letter-spacing: -1px; text-transform: uppercase; animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .hero p { font-size: 1.06rem; font-weight: 500; opacity: 0.94; max-width: 650px; color: #cbd5e1; animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.15s forwards; }

        .res-container { flex-grow: 1; width: 100%; max-width: 1320px; margin: -50px auto 80px auto; padding: 0 5%; position: relative; z-index: 10; }

        .alert-box {
            padding: 18px 25px; border-radius: 14px; margin-bottom: 22px; font-size: 0.95rem; font-weight: 700;
            display: flex; align-items: flex-start; gap: 15px; box-shadow: var(--shadow-md); background: white;
        }
        .alert-error { border-left: 6px solid #ef4444; color: #991b1b; }
        .alert-success { border-left: 6px solid #10b981; color: #047857; }
        .alert-warning { border-left: 6px solid #f59e0b; color: #92400e; }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }
        .summary-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            display: flex;
            gap: 14px;
            align-items: center;
            min-height: 105px;
            color: inherit;
            transition: .22s ease;
        }
        a.summary-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: rgba(244, 208, 63, .65);
        }
        .summary-icon {
            width: 48px; height: 48px; border-radius: 14px; background: #111827; color: var(--primary-yellow);
            display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex: 0 0 auto;
        }
        .summary-card small { display: block; color: #64748b; font-weight: 800; font-size: .74rem; text-transform: uppercase; letter-spacing: .35px; }
        .summary-card strong { display: block; color: var(--text-dark); font-family: 'Montserrat', sans-serif; font-size: 1.35rem; font-weight: 900; line-height: 1.15; margin-top: 3px; }

        .summary-card.pending-summary .summary-icon { background:#fef3c7; color:#92400e; }
        .summary-card.approved-summary .summary-icon { background:#dcfce7; color:#047857; }
        .summary-card.fully-paid-summary .summary-icon { background:#dbeafe; color:#1d4ed8; }
        .summary-card.cancelled-summary .summary-icon { background:#475569; color:#f8fafc; }
        .summary-card.rejected-summary .summary-icon { background:#fee2e2; color:#b91c1c; }
        .res-card.status-cancelled { border-color:#cbd5e1; background:#f8fafc; }
        .res-card.status-cancelled .res-card-body { background:#f1f5f9; }
        .res-card.status-cancelled .res-card-footer { background:#f8fafc; }
        .res-card.status-pending { border-color:#fde68a; }
        .res-card.status-approved { border-color:#bbf7d0; }
        .res-card.status-fully-paid { border-color:#bfdbfe; }
        .res-card.status-rejected { border-color:#fecaca; }
        .res-card.compact-fully-paid,
        .res-card.compact-cancelled {
            align-self: start;
        }
        .res-card.compact-fully-paid .res-card-body,
        .res-card.compact-cancelled .res-card-body {
            padding-bottom: 16px;
        }
        .completion-note {
            display: block;
            margin-top: 6px;
            color: #047857;
            font-size: .75rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .35px;
        }
        .compact-history-note {
            background: white;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 14px;
            color: #334155;
            font-size: .84rem;
            font-weight: 800;
            line-height: 1.55;
        }
        .compact-history-note strong {
            display: block;
            color: var(--text-dark);
            margin-bottom: 2px;
        }
        .compact-history-note .muted-line {
            display: block;
            color: #64748b;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #cbd5e1;
        }
        .cancelled-note-box {
            display:flex;
            gap:12px;
            align-items:flex-start;
            background:#f8fafc;
            color:#475569;
            border:1px solid #cbd5e1;
            border-radius:14px;
            padding:14px;
            margin-top:12px;
            font-weight:800;
        }
        .cancelled-note-box i { margin-top:2px; }

        .tools-panel {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 16px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(420px, auto);
            gap: 14px;
            align-items: center;
        }
        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            min-width: 0;
        }
        .tab-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #475569;
            font-weight: 800;
            font-size: .82rem;
            transition: .2s ease;
        }
        .tab-link.active, .tab-link:hover {
            background: var(--dark-bg);
            color: white;
            border-color: var(--dark-bg);
        }
        .tab-count {
            background: rgba(244,208,63,.22);
            color: #92400e;
            padding: 1px 7px;
            border-radius: 999px;
            font-size: .72rem;
        }
        .tab-link.active .tab-count {
            background: var(--primary-yellow);
            color: var(--dark-bg);
        }
        .search-form {
            display: flex;
            gap: 8px;
            align-items: center;
            min-width: 0;
        }
        .search-input {
            width: 100%;
            min-height: 44px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            padding: 0 14px;
            font-weight: 700;
            color: var(--text-dark);
        }
        .btn-search, .btn-clear {
            min-height: 44px;
            border-radius: 12px;
            border: none;
            padding: 0 14px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        .btn-search { background: var(--primary-yellow); color: var(--dark-bg); }
        .btn-clear { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

        .res-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 26px;
        }

        .res-card {
            background: white; border-radius: 20px; border: 1px solid #e2e8f0; box-shadow: var(--shadow-sm); overflow: hidden;
            display: flex; flex-direction: column; transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .res-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-lg); border-color: rgba(244, 208, 63, 0.5); }
        .res-card-header { padding: 26px 24px 18px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
        .res-title { margin: 0 0 8px 0; font-size: 1.25rem; font-weight: 900; color: var(--text-dark); letter-spacing: -0.4px; }
        .res-subtitle { margin: 0; color: #64748b; font-size: 0.9rem; font-weight: 700; display: flex; align-items: center; gap: 6px; }
        .res-subtitle i { color: var(--primary-yellow); }

        .res-badge { padding: 6px 13px; border-radius: 999px; font-size: 0.72rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .badge-PENDING { background: #fef9c3; color: #a16207; }
        .badge-APPROVED { background: #dcfce7; color: #16a34a; }
        .badge-REJECTED { background: #fee2e2; color: #b91c1c; }
        .badge-CANCELLED, .badge-CANCELED { background: #e2e8f0; color: #475569; }
        .badge-CANCELLATION-REQUESTED { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .badge-FULLY-PAID { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }

        .res-card-body { padding: 22px; flex-grow: 1; background: #f8fafc; }
        .compact-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .mini-stat {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
            min-width: 0;
        }
        .mini-stat.full { grid-column: 1 / -1; }
        .mini-stat small {
            display: block;
            color: #64748b;
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .35px;
            font-weight: 900;
            margin-bottom: 4px;
        }
        .mini-stat strong {
            color: var(--text-dark);
            font-family: 'Montserrat', sans-serif;
            font-size: .98rem;
            font-weight: 900;
            word-break: break-word;
        }
        .mini-stat.accent strong { color: #b45309; }
        .mini-stat.success strong { color: #047857; }

        .next-step-box {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px;
            border-radius: 14px;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            margin-bottom: 14px;
        }
        .next-step-box i { margin-top: 2px; font-size: 1.1rem; }
        .next-step-box strong { display: block; font-family: 'Montserrat', sans-serif; font-size: .92rem; font-weight: 900; margin-bottom: 2px; color: #1e40af; }
        .next-step-box span { display: block; font-size: .84rem; font-weight: 700; line-height: 1.45; }

        .progress-strip {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
            margin: 0 0 14px;
        }
        .progress-step {
            position: relative;
            min-width: 0;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: white;
            padding: 10px 8px;
            color: #64748b;
            text-align: center;
            font-size: .68rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .25px;
            line-height: 1.2;
        }
        .progress-step i {
            display: block;
            margin-bottom: 5px;
            color: #94a3b8;
            font-size: .9rem;
        }
        .progress-step.done {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }
        .progress-step.done i { color: #059669; }
        .progress-step.current {
            background: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }
        .progress-step.current i { color: #d97706; }

        .duplicate-box {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            padding: 12px 14px;
            border-radius: 12px;
            font-weight: 800;
            font-size: .85rem;
            margin-bottom: 14px;
            display: flex;
            gap: 10px;
        }

        .id-rejected-box {
            display:flex;
            gap:10px;
            align-items:flex-start;
            background:#fef2f2;
            border:1px solid #fecaca;
            color:#991b1b;
            border-radius:12px;
            padding:12px 14px;
            margin:12px 0;
            font-size:.85rem;
            font-weight:800;
            line-height:1.45;
        }
        .id-rejected-box i { color:#dc2626; margin-top:2px; }
        .mini-stat.danger { border-color:#fecaca; background:#fef2f2; }
        .mini-stat.danger small, .mini-stat.danger strong { color:#991b1b; }

        details.details-panel {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
        }
        details.details-panel summary {
            list-style: none;
            cursor: pointer;
            padding: 14px 16px;
            font-family: 'Montserrat', sans-serif;
            font-size: .86rem;
            font-weight: 900;
            color: var(--dark-bg);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        details.details-panel summary::-webkit-details-marker { display: none; }
        details.details-panel summary::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: #64748b;
            transition: transform .2s;
        }
        details.details-panel[open] summary::after { transform: rotate(180deg); }
        .details-content {
            border-top: 1px solid #e2e8f0;
            padding: 16px;
        }

        .timeline { list-style: none; padding: 0; margin: 0 0 18px 0; position: relative; }
        .timeline::before { content: ''; position: absolute; left: 15px; top: 8px; bottom: 8px; width: 2px; background: #cbd5e1; }
        .timeline-item { position: relative; padding-left: 45px; margin-bottom: 17px; }
        .timeline-item:last-child { margin-bottom: 0; }
        .timeline-item::before { content: ''; position: absolute; left: 0; top: 2px; width: 32px; height: 32px; border-radius: 50%; background: white; border: 2px solid #cbd5e1; z-index: 2; box-sizing: border-box; transition: all 0.3s; }
        .timeline-item.active::before { border-color: var(--dark-bg); background: var(--dark-bg); box-shadow: 0 0 0 5px rgba(17, 24, 39, 0.08); }
        .timeline-item.active::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; left: 9px; top: 8px; color: var(--primary-yellow); font-size: 14px; z-index: 3; }
        .timeline-item.current::before { border-color: var(--primary-yellow); background: #fffbeb; box-shadow: 0 0 0 5px rgba(244,208,63,.16); }
        .timeline-item.current::after { content: '\f141'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; left: 9px; top: 8px; color: #a16207; font-size: 14px; z-index: 3; }
        .timeline-item.rejected::before { border-color: var(--danger-red); background: #fee2e2; box-shadow: 0 0 0 5px rgba(239,68,68,.14); }
        .timeline-item.rejected::after { content: '\f00d'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; left: 10px; top: 8px; color: #b91c1c; font-size: 14px; z-index: 3; }
        .tl-title { display: block; font-size: .92rem; font-weight: 900; color: var(--text-dark); margin-bottom: 2px; }
        .tl-desc { display: block; font-size: 0.8rem; color: #64748b; line-height: 1.45; font-weight: 700; }

        .section-label {
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            font-size: .84rem;
            color: var(--text-dark);
            margin: 16px 0 9px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .price-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 14px; border-radius: 12px; margin-bottom: 12px; }
        .price-row { display: flex; justify-content: space-between; gap: 14px; font-size: 0.9rem; margin-bottom: 9px; color: #64748b; font-weight: 700; }
        .price-row:last-child { margin-bottom: 0; }
        .price-row span:last-child { text-align: right; color: var(--text-dark); font-weight: 900; }

        .account-note {
            display: flex;
            gap: 10px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            border-radius: 12px;
            padding: 12px;
            font-weight: 800;
            font-size: .82rem;
            margin-bottom: 12px;
        }

        .res-card-footer {
            padding: 20px 22px 22px;
            border-top: 1px solid #e2e8f0;
            background: white;
        }
        .action-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        .action-grid.single { grid-template-columns: 1fr; }
        .action-grid .full-action { grid-column: 1 / -1; }
        .btn-action, .btn-pay, .btn-muted, .btn-danger, .btn-warning, .btn-soa, .btn-contract-download {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 44px; padding: 10px 12px; border-radius: 12px;
            font-family: 'Montserrat', sans-serif; font-weight: 900; font-size: .78rem;
            text-align: center; border: none; cursor: pointer; transition: all .22s ease; width: 100%;
        }
        .btn-action { background: #f8fafc; color: var(--dark-bg); border: 1px solid #e2e8f0; }
        .btn-action:hover { background: #111827; color: white; }
        .btn-pay { background: var(--dark-bg); color: white; }
        .btn-pay:hover { background: #000; color: var(--primary-yellow); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.2); }
        .btn-muted { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .btn-danger { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; }
        .btn-danger:hover { background: #be123c; color: white; }
        .btn-warning { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
        .btn-warning:hover { background:#92400e; color:white; }
        .btn-soa { background: #111827; color: white; }
        .btn-contract-download { background: #2563eb; color: white; }

        .status-msg {
            display: flex; align-items: center; justify-content: flex-start; gap: 14px;
            width: 100%; padding: 15px; border-radius: 12px; font-weight: 800; font-size: 0.92rem;
            font-family: 'Montserrat', sans-serif; margin-bottom: 12px;
        }
        .status-verifying { background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; }
        .status-success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
        .status-error { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }

        .docs-template { display: none; }

        .modal-overlay {
            display: flex; visibility: hidden; opacity: 0; pointer-events: none;
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(8px);
            z-index: 2000; align-items: center; justify-content: center;
            transition: opacity 0.3s ease, visibility 0.3s ease; padding: 20px; box-sizing: border-box;
        }
        .modal-overlay.active { visibility: visible; opacity: 1; pointer-events: auto; }
        .modal-content { background: white; border-radius: 24px; width: 100%; max-width: 560px; position: relative; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4); transform: translateY(30px) scale(0.95); transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); overflow: hidden; padding: 38px; max-height: 88vh; overflow-y: auto; }
        .modal-overlay.active .modal-content { transform: translateY(0) scale(1); }
        .modal-close { position: absolute; top: 18px; right: 20px; background: #f1f5f9; border: none; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; cursor: pointer; color: #64748b; transition: all 0.2s ease; z-index: 1001; }
        .modal-close:hover { color: #ef4444; background: #fee2e2; transform: rotate(90deg); }
        .cancel-form-grid { display:grid; gap:14px; margin-top:18px; }
        .cancel-form-grid label { display:block; font-weight:900; color:var(--text-dark); font-size:.78rem; text-transform:uppercase; letter-spacing:.45px; font-family:'Montserrat',sans-serif; margin-bottom:7px; }
        .cancel-form-grid select,
        .cancel-form-grid textarea {
            width:100%; border:1px solid #cbd5e1; background:#f8fafc; color:#0f172a;
            border-radius:12px; padding:12px 14px; font-weight:700; font-size:.92rem;
            outline:none; transition:.2s ease; font-family:'Roboto',sans-serif;
        }
        .cancel-form-grid textarea { min-height:105px; resize:vertical; line-height:1.55; }
        .cancel-form-grid select:focus,
        .cancel-form-grid textarea:focus { border-color:#be123c; background:white; box-shadow:0 0 0 4px rgba(190,18,60,.1); }
        .cancel-modal-note { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; border-radius:14px; padding:12px 14px; font-weight:800; font-size:.86rem; line-height:1.55; }

        .qr-box { background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 16px; padding: 30px 20px; text-align: center; margin-bottom: 22px; transition: 0.3s; }
        .qr-box i { font-size: 50px; color: var(--dark-bg); margin-bottom: 15px; }
        .qr-box h4 { margin: 0 0 5px 0; color: var(--text-dark); font-size: 1.1rem; font-weight: 900; }
        .qr-box p { margin: 0; color: #64748b; font-size: 0.92rem; font-weight: 700; }
        .file-upload-wrapper { position: relative; width: 100%; height: 65px; margin-bottom: 20px; }
        .file-upload-input { position: absolute; left: 0; top: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 2; }
        .file-upload-display { position: absolute; left: 0; top: 0; width: 100%; height: 100%; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px; display: flex; align-items: center; padding: 0 20px; color: #64748b; font-size: 0.95rem; font-weight: 700; transition: 0.3s; z-index: 1; box-sizing: border-box; gap: 12px; overflow: hidden; }

        .doc-list {
            display: grid;
            gap: 10px;
            margin-top: 16px;
        }
        .doc-link, .doc-empty {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 13px 14px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: var(--text-dark);
            font-weight: 800;
            font-size: .88rem;
        }
        .doc-link:hover { background: #111827; color: white; }
        .doc-empty { color: #64748b; justify-content: flex-start; }

        .soa-modal { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.75); backdrop-filter: blur(6px); z-index: 3000; align-items: center; justify-content: center; padding: 20px; }
        .soa-modal-content { width: 95%; height: 90vh; max-width: 1100px; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35); }
        .soa-modal-header { height: 60px; background: #111827; color: white; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
        .soa-modal-header h2 { font-size: 1.1rem; margin: 0; font-weight: 900; }
        .soa-modal-header button { background: none; border: none; color: white; font-size: 28px; cursor: pointer; }
        #soaFrame { width: 100%; height: calc(90vh - 60px); border: none; }

        .empty-state { grid-column: 1/-1; text-align: center; padding: 70px 20px; background: white; border-radius: 20px; border: 1px solid #e2e8f0; box-shadow: var(--shadow-sm); }
        .empty-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .empty-actions .btn-pay,
        .empty-actions .btn-action {
            width: auto;
            display: inline-flex;
            padding: 14px 24px;
            border-radius: 40px;
        }

        .footer { background: var(--dark-bg); color: #cbd5e1; text-align: center; padding: 30px; margin-top: auto; font-size: 0.9rem; }

        @media (max-width: 1100px) {
            .summary-grid { grid-template-columns: repeat(3, 1fr); }
            .tools-panel { grid-template-columns: 1fr; }
            .search-form { min-width: 0; width: 100%; }
        }

        @media (max-width: 768px) {
            .navbar { padding: 14px 20px; }
            .nav-links.desktop-only, .nav-logo-text, .profile-info.desktop-only { display: none; }
            .nav-logo img { height: 58px; }
            .nav-actions { gap: 12px; }
            .hero { margin-top: 76px; min-height: 220px; padding: 42px 18px 78px; }
            .hero h1 { font-size: 2rem; }
            .hero p { font-size: .95rem; }
            .res-container { margin-top: -44px; padding: 0 15px; }
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
            .summary-card { min-height: auto; }
            .tabs { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
            .tab-link { flex: 0 0 auto; }
            .search-form { flex-direction: column; align-items: stretch; }
            .btn-search, .btn-clear { width: 100%; justify-content: center; }
            .res-grid { grid-template-columns: 1fr; gap: 18px; }
            .res-card-header { padding: 22px 18px 16px; }
            .res-card-body { padding: 18px; }
            .res-card-footer { padding: 18px; }
            .compact-grid { grid-template-columns: 1fr; }
            .progress-strip { grid-template-columns: 1fr; }
            .progress-step { display: flex; align-items: center; justify-content: flex-start; gap: 8px; text-align: left; }
            .progress-step i { margin: 0; }
            .action-grid { grid-template-columns: 1fr; }
            .price-row { flex-direction: column; gap: 2px; }
            .price-row span:last-child { text-align: left; }
            .modal-content { padding: 32px 22px; }
        }

        @media (max-width: 480px) {
            .summary-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="nav-logo">
            <a href="index.php">
                <img src="assets/logo1.png" alt="JEJ Top Priority Corporation">
                <div class="nav-logo-text">
                    <h2>JEJ Top Priority Corporation</h2>
                    <span>SERVICES & REAL ESTATE</span>
                </div>
            </a>
        </div>

        <div class="nav-links desktop-only">
            <a href="index.php">Home</a>
            <a href="index.php#properties">Properties</a>
            <a href="index.php#contact">Contact</a>
        </div>

        <div class="nav-actions">
            <a href="notifications.php" class="notification-bell" title="Notifications">
                <i class="fa-solid fa-bell"></i>
                <?php if($unread_count > 0): ?> <span class="notification-dot"></span> <?php endif; ?>
            </a>

            <div class="profile-dropdown-container">
                <button class="profile-trigger" id="profileBtn">
                    <div class="profile-info desktop-only">
                        <span class="profile-name"><?= h($_SESSION['fullname'] ?? '') ?></span>
                        <span class="profile-role"><?= h($_SESSION['role'] ?? '') ?></span>
                    </div>
                    <div class="avatar-circle <?= $current_profile_photo !== '' ? 'has-photo' : '' ?>">
                        <?php if ($current_profile_photo !== ''): ?>
                            <img src="<?= h($current_profile_photo) ?>" alt="Profile photo" class="avatar-photo">
                        <?php else: ?>
                            <?= h(strtoupper(substr((string)($_SESSION['fullname'] ?? 'B'), 0, 1))) ?>
                        <?php endif; ?>
                    </div>
                </button>
                <div class="profile-dropdown-menu" id="profileDropdown">
                    <a href="profile.php" class="profile-dropdown-item"><i class="fa-regular fa-user"></i> My Profile</a>
                    <a href="my_reservations.php" class="profile-dropdown-item current" aria-current="page"><i class="fa-solid fa-file-contract"></i> My Reservations</a>
                    <a href="logout.php" class="profile-dropdown-item logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <header class="hero">
        <h1>My Property Portfolio</h1>
        <p>Track your reservation status, view verified payment details, and access your SOA after approval.</p>
    </header>

    <div class="res-container">

        <?php if (!empty($alert_msg)): ?>
            <div class="alert-box alert-<?= h($alert_type) ?> animate-on-scroll">
                <i class="fa-solid <?= $alert_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" style="font-size: 1.35rem;"></i>
                <div><?= h($alert_msg) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($duplicate_warning): ?>
            <div class="alert-box alert-warning animate-on-scroll">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.35rem;"></i>
                <div>
                    You already have more than one pending reservation for the same lot. Please cancel the duplicate request or contact JEJ Top Priority Corporation for assistance.
                </div>
            </div>
        <?php endif; ?>

        <div class="summary-grid animate-on-scroll">
            <a href="my_reservations.php?status=ONGOING" class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-folder-open"></i></div>
                <div><small>Active Reservations</small><strong><?= number_format($ongoing_reservations_count) ?></strong></div>
            </a>
            <a href="my_reservations.php?status=FULLY_PAID" class="summary-card fully-paid-summary">
                <div class="summary-icon"><i class="fa-solid fa-shield-check"></i></div>
                <div><small>Fully Paid</small><strong><?= number_format($fully_paid_count) ?></strong></div>
            </a>
            <a href="my_reservations.php?status=APPROVED" class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-wallet"></i></div>
                <div><small>Total Current Due</small><strong><?= jej_money($total_current_due) ?></strong></div>
            </a>
            <a href="my_reservations.php?status=APPROVED" class="summary-card">
                <div class="summary-icon"><i class="fa-solid fa-calendar-check"></i></div>
                <div><small>Next Payment Due</small><strong><?= $next_payment_due === null ? 'None' : jej_money($next_payment_due) ?></strong></div>
            </a>
        </div>

        <div class="tools-panel animate-on-scroll">
            <div class="tabs">
                <?php foreach ($status_tabs as $value => $label): ?>
                    <?php
                        $urlParams = ['status' => $value];
                        if ($search_q !== '') $urlParams['q'] = $search_q;
                        $url = 'my_reservations.php?' . http_build_query($urlParams);
                    ?>
                    <a href="<?= h($url) ?>" class="tab-link <?= $selected_status === $value ? 'active' : '' ?>">
                        <?= h($label) ?>
                        <span class="tab-count"><?= number_format((int)($status_counts[$value] ?? 0)) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <form class="search-form" method="GET" action="my_reservations.php">
                <input type="hidden" name="status" value="<?= h($selected_status) ?>">
                <input type="search"
                       class="search-input"
                       name="q"
                       value="<?= h($search_q) ?>"
                       placeholder="Search by location, block, lot, account number">
                <button type="submit" class="btn-search"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <?php if ($search_q !== '' || $selected_status !== $default_status_filter): ?>
                    <a class="btn-clear" href="my_reservations.php"><i class="fa-solid fa-rotate-left"></i> Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="res-grid">
            <?php if(count($visible_models) > 0): ?>
                <?php foreach($visible_models as $m):
                    $row = $m['row'];
                    $status = $m['status'];
                    $display_status = $m['display_status'];
                    $badge_class = jej_badge_class($display_status);
                    $is_pending = ($status === 'PENDING');
                    $is_approved = ($status === 'APPROVED');
                    $is_rejected = ($status === 'REJECTED');
                    $is_cancelled = ($status === 'CANCELLED' || $status === 'CANCELED');
                    $has_pending_cancellation_request = !empty($m['has_pending_cancellation_request']);
                    $is_fully_paid = !empty($m['is_fully_paid']);
                    $is_completed_fully_paid = $is_fully_paid && !$is_cancelled;
                    $is_cash_payment = !empty($m['is_cash_payment']);
                    $amount_to_pay = $is_cash_payment ? (float)$m['cash_balance_due'] : (float)$m['dp_remaining'];
                    $account_number = trim((string)($row['account_number'] ?? ''));
                    $cancelled_date_raw = trim((string)($row['cancelled_at'] ?? ''));
                    if ($cancelled_date_raw === '') $cancelled_date_raw = (string)($row['reservation_date'] ?? 'now');
                    $cancelled_date = date('M d, Y', strtotime($cancelled_date_raw));
                    $cancel_reason = trim((string)($row['cancellation_reason'] ?? ''));
                    if ($cancel_reason === '') $cancel_reason = 'No reason provided';
                    $idReviewState = (string)($m['id_review_state'] ?? 'PENDING');
                    $idReviewLabel = (string)($m['id_review_label'] ?? 'Pending Review');
                    $idRejected = ($idReviewState === 'REJECTED');
                    $idVerified = $is_approved || ($idReviewState === 'VERIFIED');
                    $proofVerified = $is_approved || ((float)$m['reservation_fee_paid_amount'] >= (float)$m['reservation_fee']);
                    $accountReady = $is_approved && $account_number !== '';
                    $soaReady = $is_approved;
                ?>
                <div class="res-card status-<?= $is_cancelled ? 'cancelled' : ($is_completed_fully_paid ? 'fully-paid' : strtolower((string)$status)) ?> <?= $is_completed_fully_paid ? 'compact-fully-paid' : '' ?> <?= $is_cancelled ? 'compact-cancelled' : '' ?> animate-on-scroll">
                    <div class="res-card-header">
                        <div>
                            <h3 class="res-title">Block <?= h($row['block_no'] ?? '') ?>, Lot <?= h($row['lot_no'] ?? '') ?></h3>
                            <p class="res-subtitle"><i class="fa-solid fa-map-pin"></i> <?= h($row['location'] ?? '') ?></p>
                            <?php if($is_completed_fully_paid): ?>
                                <span class="completion-note">Account completed</span>
                            <?php endif; ?>
                        </div>
                        <span class="res-badge badge-<?= h($badge_class) ?>"><?= h($display_status) ?></span>
                    </div>

                    <div class="res-card-body">
                        <?php if(!empty($m['duplicate_pending'])): ?>
                            <div class="duplicate-box">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <span>You already have a pending reservation for this lot.</span>
                            </div>
                        <?php endif; ?>

                        <?php if($is_cancelled): ?>
                            <div class="compact-history-note">
                                <strong>Date Cancelled</strong>
                                <?= h($cancelled_date) ?>
                                <strong style="margin-top:8px;">Reason</strong>
                                <?= h($cancel_reason) ?>
                                <span class="muted-line">History only. No payment, SOA, or approval action is required.</span>
                            </div>
                        <?php elseif($is_completed_fully_paid): ?>
                            <div class="compact-grid">
                                <div class="mini-stat">
                                    <small>Final TCP</small>
                                    <strong><?= jej_money($m['final_tcp']) ?></strong>
                                </div>
                                <div class="mini-stat success">
                                    <small>Status</small>
                                    <strong>Fully Paid</strong>
                                </div>
                                <div class="mini-stat full">
                                    <small>Account Number</small>
                                    <strong><?= h($account_number !== '' ? $account_number : 'For generation') ?></strong>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="compact-grid">
                                <div class="mini-stat full">
                                    <small>Payment Option</small>
                                    <strong><?= h($m['payment_label']) ?></strong>
                                </div>
                                <div class="mini-stat">
                                    <small>Final TCP</small>
                                    <strong><?= jej_money($m['final_tcp']) ?></strong>
                                </div>
                                <div class="mini-stat accent">
                                    <small>Reservation Fee Submitted</small>
                                    <strong><?= jej_money($m['reservation_fee']) ?></strong>
                                </div>
                                <div class="mini-stat <?= $idRejected ? 'danger' : ($idVerified ? 'success' : '') ?>">
                                    <small>ID Verification</small>
                                    <strong><?= h($idReviewLabel) ?></strong>
                                </div>

                                <?php if($is_approved): ?>
                                    <div class="mini-stat">
                                        <small>Account Number</small>
                                        <strong><?= h($account_number !== '' ? $account_number : 'For generation') ?></strong>
                                    </div>
                                    <div class="mini-stat <?= $amount_to_pay <= 0 ? 'success' : 'accent' ?>">
                                        <small><?= $is_cash_payment ? 'Balance Due Within 20 Days' : 'Down Payment Due' ?></small>
                                        <strong><?= jej_money($amount_to_pay) ?></strong>
                                    </div>
                                <?php else: ?>
                                    <div class="mini-stat full">
                                        <small>Account Number</small>
                                        <strong>Will be generated after approval</strong>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if($is_pending || $is_approved): ?>
                                <div class="progress-strip" aria-label="Reservation progress">
                                    <div class="progress-step done">
                                        <i class="fa-solid fa-flag-checkered"></i>
                                        Reserved
                                    </div>
                                    <div class="progress-step <?= $idVerified ? 'done' : ($idRejected ? '' : 'current') ?>">
                                        <i class="fa-solid fa-id-card"></i>
                                        ID Verified
                                    </div>
                                    <div class="progress-step <?= $proofVerified ? 'done' : ($idVerified ? 'current' : '') ?>">
                                        <i class="fa-solid fa-receipt"></i>
                                        Payment Review
                                    </div>
                                    <div class="progress-step <?= $is_approved ? 'done' : ($proofVerified ? 'current' : '') ?>">
                                        <i class="fa-solid fa-circle-check"></i>
                                        Approved
                                    </div>
                                    <div class="progress-step <?= $soaReady ? 'done' : ($is_approved ? 'current' : '') ?>">
                                        <i class="fa-solid fa-file-invoice"></i>
                                        Contract / SOA
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="next-step-box">
                                <i class="fa-solid fa-circle-info"></i>
                                <div>
                                    <strong><?= $has_pending_cancellation_request ? 'Cancellation Requested' : ($is_pending ? ($idRejected ? 'ID Verification Rejected' : 'Waiting for Management Approval') : 'Next Step') ?></strong>
                                    <span><?= h($m['next_step']) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if($idRejected && $is_pending): ?>
                            <div class="id-rejected-box">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <span>Your ID/selfie was rejected in admin review. Approval is paused until you submit a clearer replacement or contact JEJ for assistance.</span>
                            </div>
                        <?php endif; ?>

                        <?php if(!$is_completed_fully_paid && !$is_cancelled): ?>
                        <details class="details-panel">
                            <summary>Show More Details</summary>
                            <div class="details-content">

                                <div class="section-label"><i class="fa-solid fa-list-check" style="color: var(--primary-yellow);"></i> Reservation Timeline</div>
                                <ul class="timeline">
                                    <li class="timeline-item active">
                                        <span class="tl-title">Reservation Submitted</span>
                                        <span class="tl-desc">Date: <?= h(date('M d, Y', strtotime((string)$row['reservation_date']))) ?></span>
                                    </li>
                                    <li class="timeline-item <?= $idVerified ? 'active' : ($idRejected ? 'rejected' : ($is_pending ? 'current' : '')) ?>">
                                        <span class="tl-title">ID / Selfie Verification</span>
                                        <span class="tl-desc">
                                            <?php if($idVerified): ?>
                                                Identity documents verified or approved.
                                            <?php elseif($idRejected): ?>
                                                Valid ID / selfie was rejected by admin. Please upload a clearer replacement or contact JEJ.
                                            <?php else: ?>
                                                Valid ID and selfie are currently under review.
                                            <?php endif; ?>
                                        </span>
                                    </li>
                                    <li class="timeline-item <?= $proofVerified ? 'active' : ($is_pending ? 'current' : '') ?>">
                                        <span class="tl-title">Payment Proof Verification</span>
                                        <span class="tl-desc"><?= $proofVerified ? 'Reservation payment proof verified.' : 'Reservation fee proof is pending admin verification.' ?></span>
                                    </li>
                                    <li class="timeline-item <?= $is_approved ? 'active' : ($is_pending ? 'current' : '') ?>">
                                        <span class="tl-title">Management Approval</span>
                                        <span class="tl-desc">
                                            <?php if($is_pending): ?>
                                                Documents are currently under management review.
                                            <?php elseif($is_approved): ?>
                                                Your reservation has been approved.
                                            <?php elseif($is_rejected): ?>
                                                Reservation was not approved.
                                            <?php elseif($is_cancelled): ?>
                                                Reservation was cancelled.
                                            <?php else: ?>
                                                Current status: <?= h($status) ?>
                                            <?php endif; ?>
                                        </span>
                                    </li>
                                    <li class="timeline-item <?= $accountReady ? 'active' : '' ?>">
                                        <span class="tl-title">Account Number Generated</span>
                                        <span class="tl-desc"><?= $accountReady ? h($account_number) : 'Generated only after approval.' ?></span>
                                    </li>
                                    <li class="timeline-item <?= $soaReady ? 'active' : '' ?>">
                                        <span class="tl-title">Payment Terms / SOA Available</span>
                                        <span class="tl-desc"><?= $soaReady ? 'SOA and payment terms are available.' : 'SOA will be available after approval.' ?></span>
                                    </li>
                                </ul>

                                <?php if(!$is_approved): ?>
                                    <div class="account-note">
                                        <i class="fa-solid <?= $idRejected ? 'fa-triangle-exclamation' : 'fa-circle-info' ?>"></i>
                                        <span><?= $idRejected ? 'ID verification is rejected. Account number, approval, SOA, and payment terms will remain hidden until the issue is resolved.' : 'Account number will be generated after approval. SOA and payment terms are hidden while the reservation is pending or inactive.' ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="section-label"><i class="fa-solid fa-receipt" style="color: var(--primary-yellow);"></i> Reservation Fee</div>
                                <div class="price-box">
                                    <div class="price-row"><span>Amount</span><span><?= jej_money($m['reservation_fee']) ?></span></div>
                                    <div class="price-row"><span>Status</span><span><?= h($m['reservation_fee_status']) ?></span></div>
                                    <div class="price-row"><span>Verified Reservation Fee Paid</span><span><?= jej_money($m['reservation_fee_paid_amount']) ?></span></div>
                                </div>

                                <div class="section-label"><i class="fa-solid fa-file-contract" style="color: var(--primary-yellow);"></i> Contract Price</div>
                                <div class="price-box">
                                    <div class="price-row"><span>Cash TCP / Base Contract Price</span><span><?= jej_money($m['cash_tcp']) ?></span></div>
                                    <div class="price-row"><span>Payment Option</span><span><?= h($m['payment_label']) ?></span></div>
                                    <div class="price-row"><span>Cash Price / SQM</span><span><?= jej_money($m['cash_price_per_sqm'], 2) ?></span></div>
                                    <div class="price-row"><span>Selected Price / SQM</span><span><?= jej_money($m['selected_price_per_sqm'], 2) ?></span></div>
                                    <?php if($m['additional_per_sqm'] > 0): ?>
                                        <div class="price-row"><span>Additional / SQM</span><span>+ <?= jej_money($m['additional_per_sqm'], 2) ?></span></div>
                                        <div class="price-row"><span>Additional Amount</span><span>+ <?= jej_money($m['additional_amount']) ?></span></div>
                                    <?php endif; ?>
                                    <div class="price-row"><span>Final Contract Price</span><span><?= jej_money($m['final_tcp']) ?></span></div>
                                </div>

                                <?php if($is_approved): ?>
                                    <div class="section-label"><i class="fa-solid fa-wallet" style="color: var(--primary-yellow);"></i> Approved Payment Summary</div>
                                    <div class="price-box">
                                        <?php if($is_cash_payment): ?>
                                            <div class="price-row"><span>Required Down Payment</span><span>₱0</span></div>
                                            <div class="price-row"><span>Balance Due Within 20 Days</span><span><?= jej_money($m['cash_balance_due']) ?></span></div>
                                            <div class="price-row"><span>Total Verified Spot Cash Paid</span><span><?= jej_money($m['total_verified_paid']) ?></span></div>
                                            <div class="price-row"><span>Remaining Spot Cash Balance</span><span><?= jej_money($m['cash_balance_due']) ?></span></div>
                                        <?php else: ?>
                                            <div class="price-row"><span>Target Down Payment</span><span><?= jej_money($m['dp_amount']) ?></span></div>
                                            <div class="price-row"><span>Total Verified DP Paid</span><span><?= jej_money($m['dp_paid_amount']) ?></span></div>
                                            <div class="price-row"><span>Remaining DP Balance</span><span><?= jej_money($m['dp_remaining']) ?></span></div>
                                            <div class="price-row"><span>Balance to Amortize After DP</span><span><?= jej_money($m['balance_to_finance']) ?></span></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </details>
                        <?php endif; ?>
                    </div>

                    <div class="res-card-footer">
                        <?php if($has_pending_cancellation_request): ?>
                            <div class="status-msg status-verifying">
                                <i class="fa-solid fa-hourglass-half" style="font-size:1.5rem;"></i>
                                <div>
                                    <div>Cancellation request waiting for admin review.</div>
                                    <div style="font-size:.78rem;font-family:'Roboto',sans-serif;">This reservation will remain active until admin accepts or rejects your cancellation request.</div>
                                </div>
                            </div>
                            <div class="action-grid">
                                <a href="lot_details.php?id=<?= (int)($row['lot_id'] ?? 0) ?>" class="btn-action"><i class="fa-solid fa-eye"></i> View Reservation Details</a>
                                <button type="button" class="btn-action" onclick="openDocsModal('docs-<?= (int)$row['id'] ?>')"><i class="fa-solid fa-folder-open"></i> View Uploaded Documents</button>
                            </div>
                        <?php elseif($is_pending): ?>
                            <div class="status-msg <?= $idRejected ? 'status-error' : 'status-verifying' ?>">
                                <i class="fa-solid <?= $idRejected ? 'fa-triangle-exclamation' : 'fa-hourglass-half' ?>" style="font-size:1.5rem;"></i>
                                <div>
                                    <div><?= $idRejected ? 'ID Verification Rejected' : 'Waiting for Management Approval' ?></div>
                                    <div style="font-size:.78rem;font-family:'Roboto',sans-serif;"><?= $idRejected ? 'Please submit a clearer ID/selfie or contact JEJ. SOA remains hidden until approval.' : 'SOA and payment terms will be shown after admin approval.' ?></div>
                                </div>
                            </div>
                            <div class="action-grid">
                                <a href="lot_details.php?id=<?= (int)($row['lot_id'] ?? 0) ?>" class="btn-action"><i class="fa-solid fa-eye"></i> View Reservation Details</a>
                                <button type="button" class="btn-action" onclick="openDocsModal('docs-<?= (int)$row['id'] ?>')"><i class="fa-solid fa-folder-open"></i> View Uploaded Documents</button>
                                <button type="button" class="btn-muted" onclick="openPaymentModal(<?= (int)$row['id'] ?>, <?= (float)$m['reservation_fee'] ?>, 'Upload Additional Proof')"><i class="fa-solid fa-upload"></i> Upload Additional Proof</button>
                                <button type="button" class="btn-danger" onclick="openCancelModal(<?= (int)$row['id'] ?>, 'cancel')"><i class="fa-solid fa-ban"></i> Cancel Request</button>
                            </div>
                        <?php elseif($is_approved): ?>
                            <?php if($is_completed_fully_paid): ?>
                                <div class="action-grid">
                                    <button type="button" class="btn-soa" onclick="openSOAModal(<?= (int)$row['id']; ?>)">
                                        <i class="fa-solid fa-file-invoice"></i> View SOA
                                    </button>
                                    <?php if(!empty($row['contract_file'])): ?>
                                        <a href="<?= h(jej_doc_url('contracts', $row['contract_file'])); ?>" target="_blank" class="btn-contract-download">
                                            <i class="fa-solid fa-file-signature"></i> View Contract
                                        </a>
                                    <?php else: ?>
                                        <button type="button" class="btn-action" disabled style="opacity:.65;cursor:not-allowed;"><i class="fa-solid fa-file-signature"></i> Contract Pending</button>
                                    <?php endif; ?>
                                    <a href="lot_details.php?id=<?= (int)($row['lot_id'] ?? 0) ?>" class="btn-action full-action"><i class="fa-solid fa-eye"></i> View Details</a>
                                </div>
                            <?php elseif($amount_to_pay > 0): ?>
                                <button class="btn-pay" onclick="openPaymentModal(<?= (int)$row['id'] ?>, <?= (float)$amount_to_pay ?>, 'Pay Balance / Upload Payment')">
                                    <i class="fa-solid fa-qrcode"></i> Pay Balance / Upload Payment
                                </button>
                            <?php else: ?>
                                <div class="status-msg status-success">
                                    <i class="fa-solid fa-shield-check" style="font-size:1.5rem;"></i>
                                    <div><?= $is_cash_payment ? 'Spot cash balance verified as paid.' : 'Required down payment verified as paid.' ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if(!$is_completed_fully_paid): ?>
                            <div class="action-grid" style="margin-top:10px;">
                                <button type="button" class="btn-soa" onclick="openSOAModal(<?= (int)$row['id']; ?>)">
                                    <i class="fa-solid fa-file-invoice"></i> View SOA
                                </button>
                                <button type="button" class="btn-action" onclick="openDocsModal('docs-<?= (int)$row['id'] ?>')"><i class="fa-solid fa-folder-open"></i> View Uploaded Documents</button>
                                <a href="lot_details.php?id=<?= (int)($row['lot_id'] ?? 0) ?>" class="btn-action"><i class="fa-solid fa-eye"></i> View Reservation Details</a>
                                <?php if(!empty($row['contract_file'])): ?>
                                    <a href="<?= h(jej_doc_url('contracts', $row['contract_file'])); ?>" target="_blank" class="btn-contract-download">
                                        <i class="fa-solid fa-file-signature"></i> View Contract
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn-action" disabled style="opacity:.65;cursor:not-allowed;"><i class="fa-solid fa-file-signature"></i> Contract Pending</button>
                                <?php endif; ?>
                                <?php if(!$has_pending_cancellation_request): ?>
                                    <button type="button" class="btn-warning" onclick="openCancelModal(<?= (int)$row['id'] ?>, 'request')">
                                        <i class="fa-solid fa-file-circle-question"></i> Request Cancellation
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php elseif($is_cancelled): ?>
                            <div class="action-grid">
                                <a href="index.php#properties" class="btn-action"><i class="fa-solid fa-map-location-dot"></i> Browse Lots</a>
                                <button type="button" class="btn-action" onclick="openDocsModal('docs-<?= (int)$row['id'] ?>')"><i class="fa-solid fa-folder-open"></i> View Documents</button>
                            </div>
                        <?php elseif($is_rejected): ?>
                            <div class="status-msg status-error">
                                <i class="fa-solid fa-circle-xmark"></i> Your reservation was not approved. Please contact JEJ for assistance.
                            </div>
                            <div class="action-grid">
                                <a href="index.php#contact" class="btn-action"><i class="fa-solid fa-message"></i> Contact JEJ</a>
                                <button type="button" class="btn-action" onclick="openDocsModal('docs-<?= (int)$row['id'] ?>')"><i class="fa-solid fa-folder-open"></i> View Uploaded Documents</button>
                            </div>
                        <?php else: ?>
                            <div class="status-msg" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;">
                                <i class="fa-solid fa-circle-info"></i> This reservation is <?= h(strtolower($status)) ?>.
                            </div>
                            <div class="action-grid">
                                <a href="index.php#properties" class="btn-action"><i class="fa-solid fa-map-location-dot"></i> Browse Lots</a>
                                <button type="button" class="btn-action" onclick="openDocsModal('docs-<?= (int)$row['id'] ?>')"><i class="fa-solid fa-folder-open"></i> View Uploaded Documents</button>
                            </div>
                        <?php endif; ?>

                        <div id="docs-<?= (int)$row['id'] ?>" class="docs-template">
                            <h2 style="font-weight:900;font-size:1.45rem;margin-bottom:6px;">Uploaded Documents</h2>
                            <p style="color:#64748b;font-weight:700;margin-bottom:10px;">Block <?= h($row['block_no'] ?? '') ?>, Lot <?= h($row['lot_no'] ?? '') ?> • <?= h($row['location'] ?? '') ?></p>
                            <div class="doc-list">
                                <?php
                                    $docs = [
                                        ['Valid Government ID', 'valid_ids', $row['valid_id_file'] ?? ''],
                                        ['Live Selfie Verification', 'selfies', $row['live_selfie'] ?? ($row['selfie_with_id'] ?? '')],
                                        ['Reservation Payment Proof', 'payment_proofs', $row['payment_proof'] ?? ''],
                                        ['Additional Payment Proof', 'payment_proofs', $row['dp_proof'] ?? ''],
                                        ['Signed Contract', 'contracts', $row['contract_file'] ?? ''],
                                    ];
                                    $hasDocs = false;
                                    foreach ($docs as $doc):
                                        [$label, $folder, $filename] = $doc;
                                        $url = jej_doc_url($folder, $filename);
                                        if ($filename !== '' && $url !== ''):
                                            $hasDocs = true;
                                ?>
                                        <a class="doc-link" href="<?= h($url) ?>" target="_blank">
                                            <span><i class="fa-solid fa-file-arrow-down"></i> <?= h($label) ?></span>
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                        </a>
                                <?php
                                        endif;
                                    endforeach;
                                    if (!$hasDocs):
                                ?>
                                    <div class="doc-empty"><i class="fa-solid fa-folder-open"></i> No accessible uploaded documents found.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state animate-on-scroll">
                    <i class="fa-regular fa-folder-open" style="font-size:60px;color:#cbd5e1;margin-bottom:25px;"></i>
                    <h3 style="color:var(--text-dark);font-weight:900;font-size:1.8rem;margin-bottom:10px;">No Reservations Found</h3>
                    <p style="color:#64748b;font-size:1.05rem;margin-bottom:30px;">
                        <?php if($selected_status === 'ONGOING' && $total_reservations > 0): ?>
                            You have no ongoing reservations. You can view completed or cancelled reservation history below.
                        <?php elseif($selected_status === 'CANCELLED'): ?>
                            No cancelled reservations found.
                        <?php elseif($selected_status === 'ALL' && $search_q !== ''): ?>
                            No reservations match your search keyword.
                        <?php else: ?>
                            <?= $total_reservations > 0 ? 'Try changing your filter or search keyword.' : 'Ready to find your dream sustainable lot?' ?>
                        <?php endif; ?>
                    </p>
                    <div class="empty-actions">
                        <?php if($fully_paid_count > 0): ?>
                            <a href="my_reservations.php?status=FULLY_PAID" class="btn-action">
                                <i class="fa-solid fa-shield-check"></i> View Fully Paid
                            </a>
                        <?php endif; ?>
                        <?php if($cancelled_count > 0): ?>
                            <a href="my_reservations.php?status=CANCELLED" class="btn-action">
                                <i class="fa-solid fa-ban"></i> View Cancelled
                            </a>
                        <?php endif; ?>
                        <?php if($search_q !== '' || $selected_status !== $default_status_filter): ?>
                            <a href="my_reservations.php" class="btn-action">
                                <i class="fa-solid fa-table-list"></i> View Default
                            </a>
                        <?php endif; ?>
                        <a href="index.php#properties" class="btn-pay" style="background:var(--primary-yellow);color:var(--dark-bg);">
                            Explore Properties <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal-overlay" id="cancelModal">
        <div class="modal-content">
            <button class="modal-close" type="button" onclick="closeModal('cancelModal')"><i class="fa-solid fa-xmark"></i></button>

            <h2 id="cancelModalTitle" style="font-weight:900;font-size:1.45rem;color:var(--text-dark);margin:0 0 10px 0;">Cancel Reservation</h2>
            <p id="cancelModalIntro" style="color:#64748b;font-size:.95rem;line-height:1.6;font-weight:700;">
                Please provide the reason before submitting. This will be saved for admin review and audit history.
            </p>

            <div class="cancel-modal-note" id="cancelModalNote">
                Pending reservations are cancelled immediately. Approved reservations are sent to admin as a cancellation request first.
            </div>

            <form action="my_reservations.php" method="POST" class="cancel-form-grid" onsubmit="return validateCancelForm();">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="res_id" id="cancelResId" value="">
                <input type="hidden" name="cancel_reservation" value="1">

                <div>
                    <label for="cancelReasonChoice">Reason</label>
                    <select name="cancellation_reason_choice" id="cancelReasonChoice" required>
                        <option value="">Select reason...</option>
                        <option value="Changed mind">Changed mind</option>
                        <option value="Wrong block or lot selected">Wrong block or lot selected</option>
                        <option value="Duplicate reservation">Duplicate reservation</option>
                        <option value="Financial reason">Financial reason</option>
                        <option value="Need more time to decide">Need more time to decide</option>
                        <option value="Other reason">Other reason</option>
                    </select>
                </div>

                <div>
                    <label for="cancelReasonDetails">Details</label>
                    <textarea name="cancellation_reason_details" id="cancelReasonDetails" required minlength="5" maxlength="255" placeholder="Type the buyer cancellation reason here..."></textarea>
                </div>

                <button type="submit" class="btn-danger" id="cancelSubmitBtn">
                    <i class="fa-solid fa-ban"></i> Confirm Cancellation
                </button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="paymentModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('paymentModal')"><i class="fa-solid fa-xmark"></i></button>

            <h2 id="paymentModalTitle" style="font-weight:900;font-size:1.6rem;color:var(--text-dark);margin:0 0 10px 0;">Online Payment</h2>
            <p style="color:#64748b;font-size:.95rem;margin-bottom:22px;line-height:1.6;font-weight:700;">
                Scan the QR code using GCash, Maya, or bank transfer, then upload your PDF/JPG/PNG receipt for admin verification.
            </p>

            <div class="qr-box">
                <i class="fa-solid fa-qrcode"></i>
                <h4>JEJ Top Priority Corporation</h4>
                <p>GCash / Maya / Instapay</p>
                <div style="font-size:2rem;font-weight:900;color:var(--dark-bg);margin-top:14px;letter-spacing:-1px;" id="modalAmountDisplay">₱0.00</div>
            </div>

            <form action="my_reservations.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="res_id" id="modalResId" value="">

                <label style="display:block;font-weight:900;color:var(--text-dark);margin-bottom:12px;font-size:.82rem;text-transform:uppercase;letter-spacing:.5px;font-family:'Montserrat',sans-serif;">
                    Upload Transfer Receipt
                </label>

                <div class="file-upload-wrapper">
                    <input type="file" name="dp_receipt" id="dp_receipt" class="file-upload-input" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required onchange="updateFileName(this)">
                    <div class="file-upload-display" id="fileDisplay">
                        <i class="fa-solid fa-cloud-arrow-up" style="font-size:1.2rem;"></i> Choose PDF/JPG/PNG receipt...
                    </div>
                </div>

                <button type="submit" name="upload_qr_payment" class="btn-pay" style="margin-top:15px;border-radius:10px;background:var(--success-green);color:white;">
                    Submit for Verification <i class="fa-solid fa-check-circle"></i>
                </button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="docsModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('docsModal')"><i class="fa-solid fa-xmark"></i></button>
            <div id="docsModalBody"></div>
        </div>
    </div>

    <div id="soaModal" class="soa-modal">
        <div class="soa-modal-content">
            <div class="soa-modal-header">
                <h2>Statement of Account</h2>
                <button onclick="closeSOAModal()">×</button>
            </div>
            <iframe id="soaFrame" src=""></iframe>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?= date('Y') ?> JEJ Top Priority Corporation. All Rights Reserved. Built with precision.</p>
    </footer>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            document.body.style.overflow = 'auto';

            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');
            if (profileBtn && profileDropdown) {
                profileBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    profileDropdown.classList.toggle('active');
                });
                document.addEventListener('click', function(e) {
                    if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                        profileDropdown.classList.remove('active');
                    }
                });
            }

            const observerOptions = { threshold: 0.08, rootMargin: "0px 0px -40px 0px" };
            const observer = new IntersectionObserver(function(entries, observer) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.animate-on-scroll').forEach(el => { observer.observe(el); });
        });

        function openCancelModal(resId, mode) {
            const title = document.getElementById('cancelModalTitle');
            const intro = document.getElementById('cancelModalIntro');
            const note = document.getElementById('cancelModalNote');
            const submit = document.getElementById('cancelSubmitBtn');

            document.getElementById('cancelResId').value = resId;
            document.getElementById('cancelReasonChoice').value = '';
            document.getElementById('cancelReasonDetails').value = '';

            if (mode === 'request') {
                title.innerText = 'Request Reservation Cancellation';
                intro.innerText = 'Approved reservations require admin review before cancellation is finalized.';
                note.innerText = 'This will not cancel your approved reservation immediately. Admin must accept the cancellation request in reservation.php.';
                submit.innerHTML = '<i class="fa-solid fa-file-circle-question"></i> Submit Cancellation Request';
                submit.className = 'btn-warning';
            } else {
                title.innerText = 'Request Pending Reservation Cancellation';
                intro.innerText = 'Please provide the reason before sending this cancellation request to admin.';
                note.innerText = 'This will not cancel the pending reservation immediately. Admin must accept the cancellation request in reservation.php.';
                submit.innerHTML = '<i class="fa-solid fa-file-circle-question"></i> Submit Cancellation Request';
                submit.className = 'btn-warning';
            }

            document.getElementById('cancelModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function validateCancelForm() {
            const choice = (document.getElementById('cancelReasonChoice').value || '').trim();
            const details = (document.getElementById('cancelReasonDetails').value || '').trim();

            if (!choice || details.length < 5) {
                alert('Please select a reason and type at least 5 characters before submitting.');
                return false;
            }

            return true;
        }

        function openPaymentModal(resId, amount, title) {
            document.getElementById('modalResId').value = resId;
            document.getElementById('modalAmountDisplay').innerText = '₱' + new Intl.NumberFormat('en-PH').format(Number(amount || 0));
            document.getElementById('paymentModalTitle').innerText = title || 'Online Payment';
            document.getElementById('paymentModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function openDocsModal(templateId) {
            const source = document.getElementById(templateId);
            const target = document.getElementById('docsModalBody');
            target.innerHTML = source ? source.innerHTML : '<div class="doc-empty"><i class="fa-solid fa-folder-open"></i> No documents found.</div>';
            document.getElementById('docsModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.classList.remove('active');
            document.body.style.overflow = 'auto';

            const receipt = document.getElementById('dp_receipt');
            const display = document.getElementById('fileDisplay');
            if (receipt) receipt.value = '';
            if (display) {
                display.innerHTML = '<i class="fa-solid fa-cloud-arrow-up" style="font-size:1.2rem;"></i> Choose PDF/JPG/PNG receipt...';
                display.style.borderColor = '#e2e8f0';
                display.style.background = '#f8fafc';
            }
        }

        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                closeModal(event.target.id);
            }
        });

        function updateFileName(input) {
            const display = document.getElementById('fileDisplay');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const allowed = ['image/jpeg', 'image/png', 'application/pdf'];
                const maxSize = 5 * 1024 * 1024;

                if (!allowed.includes(file.type) || file.size > maxSize) {
                    alert('Please upload PDF, JPG, or PNG only. Maximum file size is 5MB.');
                    input.value = '';
                    display.innerHTML = '<i class="fa-solid fa-cloud-arrow-up" style="font-size:1.2rem;"></i> Choose PDF/JPG/PNG receipt...';
                    display.style.borderColor = '#e2e8f0';
                    display.style.background = '#f8fafc';
                    return;
                }

                display.innerHTML = '<i class="fa-solid fa-file-circle-check" style="color: var(--primary-yellow); font-size: 1.2rem;"></i> <span style="color: var(--text-dark);">' + file.name + '</span>';
                display.style.borderColor = 'var(--primary-yellow)';
                display.style.background = 'white';
            } else {
                display.innerHTML = '<i class="fa-solid fa-cloud-arrow-up" style="font-size:1.2rem;"></i> Choose PDF/JPG/PNG receipt...';
                display.style.borderColor = '#e2e8f0';
                display.style.background = '#f8fafc';
            }
        }

        function openSOAModal(resId) {
            document.getElementById('soaFrame').src = 'statement_of_account.php?res_id=' + resId;
            document.getElementById('soaModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeSOAModal() {
            document.getElementById('soaModal').style.display = 'none';
            document.getElementById('soaFrame').src = '';
            document.body.style.overflow = 'auto';
        }
    </script>
</body>
</html>
<?php ob_end_flush(); ?>
