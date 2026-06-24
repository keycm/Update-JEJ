<?php
// Start session before any contact/reservation action that depends on $_SESSION.
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    session_start();
}

include 'config.php';

$action = $_POST['action'] ?? '';

if (!function_exists('jej_redirect_contact')) {
    function jej_redirect_contact($status, $reason = '') {
        if ($reason !== '') {
            error_log('Contact inquiry ' . $status . ': ' . $reason);
        }
        header('Location: index.php?contact=' . urlencode($status) . '#contact');
        exit();
    }
}

if (!function_exists('jej_clean_contact_input')) {
    function jej_clean_contact_input($value, $maxLength) {
        $value = trim((string)$value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return substr($value, 0, $maxLength);
    }
}

if (!function_exists('jej_contact_rate_limited')) {
    function jej_contact_rate_limited() {
        $now = time();
        $windowSeconds = 10 * 60; // 10 minutes
        $maxRequests = 3;

        if (empty($_SESSION['contact_inquiry_window_start']) || ($now - (int)$_SESSION['contact_inquiry_window_start']) > $windowSeconds) {
            $_SESSION['contact_inquiry_window_start'] = $now;
            $_SESSION['contact_inquiry_count'] = 0;
        }

        $_SESSION['contact_inquiry_count'] = (int)($_SESSION['contact_inquiry_count'] ?? 0) + 1;

        return $_SESSION['contact_inquiry_count'] > $maxRequests;
    }
}

if (isset($_POST['action_type']) && $_POST['action_type'] === 'contact_inquiry') {

    // 1) CSRF protection: token must match the one generated in index.php.
    $posted_csrf = $_POST['csrf_token'] ?? '';
    $session_csrf = $_SESSION['csrf_token'] ?? '';

    if ($posted_csrf === '' || $session_csrf === '' || !hash_equals($session_csrf, $posted_csrf)) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        jej_redirect_contact('error', 'Invalid CSRF token');
    }

    // 2) Honeypot anti-spam field. Real users should leave this blank.
    if (!empty($_POST['website'] ?? '')) {
        // Redirect as success so bots do not learn the spam rule.
        jej_redirect_contact('success', 'Honeypot triggered');
    }

    // 3) Simple session-based rate limit.
    if (jej_contact_rate_limited()) {
        jej_redirect_contact('error', 'Rate limit exceeded');
    }

    // 4) Validate and limit user input.
    $name = jej_clean_contact_input($_POST['full_name'] ?? '', 120);
    $email = strtolower(jej_clean_contact_input($_POST['email'] ?? '', 150));
    $phone = jej_clean_contact_input($_POST['phone'] ?? '', 30);
    $message = trim((string)($_POST['message'] ?? ''));

    if (function_exists('mb_substr')) {
        $message = mb_substr($message, 0, 1000, 'UTF-8');
    } else {
        $message = substr($message, 0, 1000);
    }

    if ($name === '' || $email === '' || $phone === '' || $message === '') {
        jej_redirect_contact('error', 'Missing required contact fields');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jej_redirect_contact('error', 'Invalid email format');
    }

    if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) {
        jej_redirect_contact('error', 'Invalid phone format');
    }

    // Strip simple HTML tags from the message before saving.
    $message = strip_tags($message);

    // Optional: include phone in subject because the current inquiries table does not show a phone column here.
    $subject = 'Contact Inquiry | Phone: ' . $phone;

    // 5) Prepared insert. Do not leak DB errors to visitors.
    $stmt = $conn->prepare("\n        INSERT INTO inquiries\n        (name, email, subject, message, status)\n        VALUES (?, ?, ?, ?, 'UNREAD')\n    ");

    if (!$stmt) {
        jej_redirect_contact('error', 'Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('ssss', $name, $email, $subject, $message);

    if (!$stmt->execute()) {
        $stmt_error = $stmt->error;
        $stmt->close();
        jej_redirect_contact('error', 'Insert failed: ' . $stmt_error);
    }

    $inquiry_id = $conn->insert_id;
    $stmt->close();

    // 6) Notify Admins. Keep notifications admin-only.
    $admins = $conn->query("\n        SELECT id\n        FROM users\n        WHERE role IN ('SUPER ADMIN','ADMIN','MANAGER')\n    ");

    if ($admins) {
        while ($admin = $admins->fetch_assoc()) {
            $admin_id = (int)$admin['id'];
            $title = 'New Contact Inquiry';
            $notif_message = $name . ' submitted a contact inquiry. Phone: ' . $phone . '. Inquiry ID: ' . $inquiry_id;

            $notif = $conn->prepare("\n                INSERT INTO notifications\n                (user_id, title, message, is_read, created_at)\n                VALUES (?, ?, ?, 0, NOW())\n            ");

            if ($notif) {
                $notif->bind_param('iss', $admin_id, $title, $notif_message);
                $notif->execute();
                $notif->close();
            }
        }
    }

    // Regenerate token after successful submit to reduce replay risk.
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    jej_redirect_contact('success');
}

// --- USER ACTIONS ---

if (!function_exists('jej_redirect_reservation_error')) {
    function jej_redirect_reservation_error($lot_id, $message) {
        $_SESSION['reservation_error'] = $message;
        $target = 'index.php#properties';
        if ((int)$lot_id > 0) {
            $target = 'lot_details.php?id=' . (int)$lot_id . '#reserve';
        }
        header('Location: ' . $target);
        exit();
    }
}

if (!function_exists('jej_private_storage_root')) {
    function jej_private_storage_root() {
        if (defined('JEJ_PRIVATE_STORAGE')) {
            return rtrim(JEJ_PRIVATE_STORAGE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($documentRoot) {
            $parent = dirname($documentRoot);
            return rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jej_private_storage' . DIRECTORY_SEPARATOR;
        }

        return rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists('jej_private_storage_folder_fallback')) {
    function jej_private_storage_folder_fallback($folder) {
        $folder = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$folder);
        if ($folder === '') {
            $folder = 'misc';
        }

        $dir = jej_private_storage_root() . $folder . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $indexFile = $dir . 'index.html';
        if (!is_file($indexFile)) {
            file_put_contents($indexFile, '');
        }

        return $dir;
    }
}

if (!function_exists('jej_storage_folder_safe')) {
    function jej_storage_folder_safe($folder) {
        if (function_exists('jej_storage_folder')) {
            $dir = jej_storage_folder($folder);
            if ($dir) {
                $dir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                return $dir;
            }
        }

        return jej_private_storage_folder_fallback($folder);
    }
}

if (!function_exists('jej_try_delete_private_upload')) {
    function jej_try_delete_private_upload($folder, $filename) {
        $filename = basename((string)$filename);
        if ($filename === '') {
            return;
        }

        $dir = jej_storage_folder_safe($folder);
        $path = $dir . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

if (!function_exists('jej_secure_private_upload')) {
    function jej_secure_private_upload($fileInputName, $folder, $prefix, $allowPdf, $required, &$upload_error = '') {
        $upload_error = '';

        if (!isset($_FILES[$fileInputName]) || !is_array($_FILES[$fileInputName])) {
            if ($required) {
                $upload_error = $fileInputName . ' was not uploaded.';
            }
            return '';
        }

        $file = $_FILES[$fileInputName];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                $upload_error = $fileInputName . ' was not uploaded.';
            }
            return '';
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $upload_error = 'Upload error code: ' . (int)$file['error'];
            return '';
        }

        $maxBytes = 5 * 1024 * 1024; // 5 MB
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            $upload_error = 'File must not exceed 5MB.';
            return '';
        }

        $originalName = (string)($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $blockedExtensions = ['php', 'phtml', 'phar', 'exe', 'js', 'html', 'htm', 'bat', 'cmd', 'sh', 'com', 'scr', 'vbs'];

        if ($extension === '' || in_array($extension, $blockedExtensions, true)) {
            $upload_error = 'This file type is not allowed.';
            return '';
        }

        $allowedExtensions = $allowPdf ? ['jpg', 'jpeg', 'png', 'pdf'] : ['jpg', 'jpeg', 'png'];
        if (!in_array($extension, $allowedExtensions, true)) {
            $upload_error = $allowPdf ? 'Only JPG, PNG, or PDF files are allowed.' : 'Only JPG or PNG image files are allowed.';
            return '';
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $upload_error = 'Invalid uploaded file.';
            return '';
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $tmpName);
                finfo_close($finfo);
            }
        }

        $allowedMimes = $allowPdf
            ? ['image/jpeg', 'image/png', 'application/pdf', 'application/x-pdf']
            : ['image/jpeg', 'image/png'];

        if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
            $upload_error = 'The file content does not match the allowed file types.';
            return '';
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png'], true) && function_exists('exif_imagetype')) {
            $imageType = @exif_imagetype($tmpName);
            $validImageTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
            if (!in_array($imageType, $validImageTypes, true)) {
                $upload_error = 'Invalid image file.';
                return '';
            }
        }

        // Prefer your existing JEJ storage helper so download.php/admin viewers stay compatible.
        if (function_exists('jej_storage_upload')) {
            $upload = jej_storage_upload($file, $folder, $prefix, 5);
            if (!($upload['success'] ?? false)) {
                $upload_error = $upload['message'] ?? 'Upload failed.';
                return '';
            }

            $filename = basename((string)($upload['filename'] ?? ''));
            if ($filename === '' || strpos($filename, '..') !== false || preg_match('/[\\\/]/', $filename)) {
                $upload_error = 'Storage returned an invalid filename.';
                return '';
            }

            return $filename;
        }

        // Fallback: save to a private folder outside DOCUMENT_ROOT when possible.
        $dir = jej_private_storage_folder_fallback($folder);
        $safePrefix = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$prefix);
        $filename = $safePrefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $target = $dir . $filename;

        if (!move_uploaded_file($tmpName, $target)) {
            $upload_error = 'Failed to move uploaded file.';
            return '';
        }

        @chmod($target, 0640);
        return $filename;
    }
}

if (!function_exists('jej_save_live_selfie_data')) {
    function jej_save_live_selfie_data($dataUri, $userId, &$upload_error = '') {
        $upload_error = '';
        $dataUri = trim((string)$dataUri);

        if ($dataUri === '') {
            $upload_error = 'Live selfie is required.';
            return '';
        }

        if (!preg_match('/^data:image\/(jpeg|jpg|png);base64,/i', $dataUri, $match)) {
            $upload_error = 'Invalid live selfie format.';
            return '';
        }

        $extension = strtolower($match[1]) === 'png' ? 'png' : 'jpg';
        $base64 = preg_replace('/^data:image\/(jpeg|jpg|png);base64,/i', '', $dataUri);
        $base64 = str_replace(' ', '+', $base64);
        $binary = base64_decode($base64, true);

        if ($binary === false) {
            $upload_error = 'Unable to decode live selfie.';
            return '';
        }

        if (strlen($binary) > (5 * 1024 * 1024)) {
            $upload_error = 'Live selfie must not exceed 5MB.';
            return '';
        }

        $tmp = tempnam(sys_get_temp_dir(), 'jej_selfie_');
        if ($tmp === false) {
            $upload_error = 'Unable to create a temporary selfie file.';
            return '';
        }

        file_put_contents($tmp, $binary);

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }

        if ($mime !== '' && !in_array($mime, ['image/jpeg', 'image/png'], true)) {
            @unlink($tmp);
            $upload_error = 'Live selfie content is not a valid JPG or PNG image.';
            return '';
        }

        if (function_exists('exif_imagetype')) {
            $imageType = @exif_imagetype($tmp);
            if (!in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                @unlink($tmp);
                $upload_error = 'Live selfie image is invalid.';
                return '';
            }
        }

        $dir = jej_storage_folder_safe('selfies');
        $filename = 'LIVE_SELFIE_' . (int)$userId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $target = $dir . $filename;

        if (!rename($tmp, $target)) {
            if (!copy($tmp, $target)) {
                @unlink($tmp);
                $upload_error = 'Failed to save live selfie.';
                return '';
            }
            @unlink($tmp);
        }

        @chmod($target, 0640);
        return $filename;
    }
}

if (!function_exists('jej_notify_user')) {
    function jej_notify_user($conn, $user_id, $title, $message) {
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('iss', $user_id, $title, $message);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('jej_reservation_columns')) {
    function jej_reservation_columns($conn) {
        $columns = [];
        $result = $conn->query('SHOW COLUMNS FROM reservations');
        if ($result) {
            while ($col = $result->fetch_assoc()) {
                $columns[$col['Field']] = true;
            }
        }
        return $columns;
    }
}

if($action == 'reserve'){
    checkLogin();

    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $lot_id = (int)($_POST['lot_id'] ?? 0);
    $uploadedFiles = [];

    if ($user_id <= 0) {
        jej_redirect_reservation_error($lot_id, 'Please log in before submitting a reservation.');
    }

    if ($lot_id <= 0) {
        jej_redirect_reservation_error($lot_id, 'Invalid lot selected.');
    }

    try {
        // CSRF protection for the reservation form.
        $posted_csrf = $_POST['csrf_token'] ?? '';
        $session_csrf = $_SESSION['csrf_token'] ?? '';
        if ($posted_csrf === '' || $session_csrf === '' || !hash_equals($session_csrf, $posted_csrf)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            throw new Exception('Session expired. Please refresh the lot page and submit again.');
        }

        // Reservation policy confirmation from the final review step.
        if (empty($_POST['policy_verification']) || empty($_POST['policy_privacy'])) {
            throw new Exception('Please confirm the reservation verification and privacy policy notices before submitting.');
        }

        $contact_number = trim((string)($_POST['contact_number'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $buyer_address = trim((string)($_POST['address'] ?? ''));
        $agent_name = trim((string)($_POST['agent_name'] ?? 'Direct Buyer'));
        $location_mode = strtoupper(trim((string)($_POST['location_mode'] ?? 'LOCAL')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid buyer email address.');
        }

        if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $contact_number)) {
            throw new Exception('Invalid mobile number.');
        }

        if ($buyer_address === '') {
            throw new Exception('Residential address or GPS address is required.');
        }

        $agent_name = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $agent_name));
        if ($agent_name === '') {
            $agent_name = 'Direct Buyer';
        }
        if (function_exists('mb_substr')) {
            $agent_name = mb_substr($agent_name, 0, 120, 'UTF-8');
            $buyer_address = mb_substr($buyer_address, 0, 500, 'UTF-8');
        } else {
            $agent_name = substr($agent_name, 0, 120);
            $buyer_address = substr($buyer_address, 0, 500);
        }

        // GPS fields are only accepted when both latitude and longitude are valid real coordinates.
        $latitude = trim((string)($_POST['buyer_latitude'] ?? ''));
        $longitude = trim((string)($_POST['buyer_longitude'] ?? ''));
        $latitude = ($latitude !== '') ? $latitude : null;
        $longitude = ($longitude !== '') ? $longitude : null;

        if ($location_mode === 'OVERSEAS_GPS') {
            if ($latitude === null || $longitude === null || !is_numeric($latitude) || !is_numeric($longitude)) {
                throw new Exception('Please detect the buyer GPS location before submitting.');
            }
            if ((float)$latitude < -90 || (float)$latitude > 90 || (float)$longitude < -180 || (float)$longitude > 180) {
                throw new Exception('Detected GPS coordinates are invalid.');
            }
        } else {
            $latitude = null;
            $longitude = null;
        }

        // Server-side file validation: JPG/PNG/PDF only for ID/proof, max 5MB, executable files blocked.
        $valid_id_error = '';
        $proof_error = '';
        $selfie_error = '';
        $valid_id_file = '';
        $payment_proof = '';
        $selfie_with_id = '';
        $live_selfie = '';

        $conn->begin_transaction();

        // Lock the lot row until reservation insert/update completes. This prevents double reservation.
        $lotStmt = $conn->prepare('SELECT * FROM lots WHERE id = ? LIMIT 1 FOR UPDATE');
        if (!$lotStmt) {
            throw new Exception('Unable to prepare lot validation.');
        }
        $lotStmt->bind_param('i', $lot_id);
        $lotStmt->execute();
        $lotLocked = $lotStmt->get_result()->fetch_assoc();
        $lotStmt->close();

        if (!$lotLocked) {
            throw new Exception('Property not found.');
        }

        if (strtoupper((string)$lotLocked['status']) !== 'AVAILABLE') {
            throw new Exception('This lot is no longer available for online reservation.');
        }

        $dupStmt = $conn->prepare("SELECT id FROM reservations WHERE lot_id = ? AND status IN ('PENDING', 'APPROVED') LIMIT 1");
        if ($dupStmt) {
            $dupStmt->bind_param('i', $lot_id);
            $dupStmt->execute();
            $existingReservation = $dupStmt->get_result()->fetch_assoc();
            $dupStmt->close();
            if ($existingReservation) {
                throw new Exception('This lot already has an active reservation request.');
            }
        }

        // Final pricing is recomputed from the locked database lot row, never from hidden inputs.
        $payment_option_code = jej_normalize_payment_option($_POST['payment_type'] ?? $_POST['payment_option'] ?? $_POST['payment_terms'] ?? 'SPOT_CASH');

        $reservation_fee = defined('JEJ_DEFAULT_RESERVATION_FEE') ? (float)JEJ_DEFAULT_RESERVATION_FEE : 5000.00;
        if ($reservation_fee <= 0) {
            $reservation_fee = 5000.00;
        }

        $installment_months = (int)($_POST['installment_months'] ?? 36);
        if ($installment_months < 12) $installment_months = 12;
        if ($installment_months > 36) $installment_months = 36;

        $priceCalc = jej_compute_payment_pricing($conn, $lotLocked, $payment_option_code, $installment_months, $reservation_fee);
        $payment_option_label = (string)$priceCalc['payment_label'];
        $additional_per_sqm = (float)$priceCalc['additional_per_sqm'];
        $additional_amount = (float)$priceCalc['additional_amount'];
        $tcp_after_discount = (float)$priceCalc['final_tcp'];
        $net_tcp_after_reservation = (float)$priceCalc['net_tcp_after_reservation'];
        $required_dp = (float)$priceCalc['required_dp'];
        $monthly_payment = (float)$priceCalc['monthly_payment'];
        $payment_type = (string)$priceCalc['payment_type_db'];
        if (!empty($priceCalc['is_spot_cash'])) {
            $installment_months = null;
        }

        $valid_id_file = jej_secure_private_upload('valid_id', 'valid_ids', 'VALID_ID', true, true, $valid_id_error);
        if ($valid_id_file === '') {
            throw new Exception('Valid ID upload failed: ' . $valid_id_error);
        }
        $uploadedFiles[] = ['valid_ids', $valid_id_file];

        $payment_proof = jej_secure_private_upload('proof', 'payment_proofs', 'PAYMENT_PROOF', true, true, $proof_error);
        if ($payment_proof === '') {
            throw new Exception('Payment proof upload failed: ' . $proof_error);
        }
        $uploadedFiles[] = ['payment_proofs', $payment_proof];

        // Live selfie is required. Old file input selfie_id is accepted only as fallback compatibility.
        if (!empty($_POST['live_selfie_data'])) {
            $live_selfie = jej_save_live_selfie_data($_POST['live_selfie_data'], $user_id, $selfie_error);
            if ($live_selfie === '') {
                throw new Exception('Live selfie upload failed: ' . $selfie_error);
            }
            $uploadedFiles[] = ['selfies', $live_selfie];
            $selfie_with_id = $live_selfie; // compatibility for pages that still read selfie_with_id
        } else {
            $selfie_with_id = jej_secure_private_upload('selfie_id', 'selfies', 'SELFIE_ID', false, false, $selfie_error);
            if ($selfie_with_id === '') {
                throw new Exception('Live selfie is required. Please capture a selfie before submitting.');
            }
            $uploadedFiles[] = ['selfies', $selfie_with_id];
            $live_selfie = $selfie_with_id;
        }

        $stmt = $conn->prepare("
            INSERT INTO reservations
            (
                user_id,
                contact_number,
                email,
                lot_id,
                reservation_date,
                status,
                dp_status,
                buyer_address,
                agent_name,
                valid_id_file,
                selfie_with_id,
                payment_proof,
                live_selfie,
                latitude,
                longitude,
                id_verification_status
            )
            VALUES (?, ?, ?, ?, NOW(), 'PENDING', 'UNPAID', ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING REVIEW')
        ");

        if(!$stmt){
            throw new Exception('Prepare failed: ' . $conn->error);
        }

        $stmt->bind_param(
            'ississssssss',
            $user_id,
            $contact_number,
            $email,
            $lot_id,
            $buyer_address,
            $agent_name,
            $valid_id_file,
            $selfie_with_id,
            $payment_proof,
            $live_selfie,
            $latitude,
            $longitude
        );

        if(!$stmt->execute()){
            $err = $stmt->error;
            $stmt->close();
            throw new Exception('Reservation insert failed: ' . $err);
        }

        $reservation_id = (int)$stmt->insert_id;
        $stmt->close();

        $reservationColumns = jej_reservation_columns($conn);
        $discountUpdates = [];
        $discountTypes = '';
        $discountValues = [];

        $addUpdate = function($column, $value, $type) use (&$reservationColumns, &$discountUpdates, &$discountTypes, &$discountValues) {
            if(isset($reservationColumns[$column])){
                $discountUpdates[] = "$column = ?";
                $discountTypes .= $type;
                $discountValues[] = $value;
            }
        };

        // Store trusted server-side pricing values.
        $discount_rate = 0.00;
        $discount_amount = 0.00;

        $addUpdate('payment_type', $payment_type, 's');
        $addUpdate('payment_option', $payment_option_label, 's');
        $addUpdate('payment_terms', $payment_option_label, 's');
        $addUpdate('payment_scheme', $payment_option_code, 's');

        $addUpdate('selected_discount', $discount_rate, 'd');
        $addUpdate('discount_rate', $discount_rate, 'd');
        $addUpdate('discount_percent', $discount_rate, 'd');
        $addUpdate('cash_discount_percent', $discount_rate, 'd');
        $addUpdate('discount_amount', $discount_amount, 'd');
        $addUpdate('cash_discount_amount', $discount_amount, 'd');

        $addUpdate('additional_per_sqm', $additional_per_sqm, 'd');
        $addUpdate('additional_amount', $additional_amount, 'd');
        $addUpdate('discounted_total_price', $tcp_after_discount, 'd');
        $addUpdate('tcp_after_discount', $tcp_after_discount, 'd');
        $addUpdate('reservation_fee', $reservation_fee, 'd');
        $addUpdate('net_tcp_after_reservation', $net_tcp_after_reservation, 'd');
        $addUpdate('balance_after_reservation_fee', $net_tcp_after_reservation, 'd');
        $addUpdate('required_dp', $required_dp, 'd');
        $addUpdate('installment_months', $installment_months, 'i');
        $addUpdate('monthly_payment', $monthly_payment, 'd');

        if(!empty($discountUpdates)){
            $discountTypes .= 'i';
            $discountValues[] = $reservation_id;

            $sql = 'UPDATE reservations SET ' . implode(', ', $discountUpdates) . ' WHERE id = ?';
            $discountStmt = $conn->prepare($sql);
            if($discountStmt){
                $discountStmt->bind_param($discountTypes, ...$discountValues);
                $discountStmt->execute();
                $discountStmt->close();
            }
        }

        $lotUpdate = $conn->prepare("UPDATE lots SET status = 'RESERVED' WHERE id = ? AND status = 'AVAILABLE'");
        if (!$lotUpdate) {
            throw new Exception('Unable to prepare lot status update.');
        }
        $lotUpdate->bind_param('i', $lot_id);
        $lotUpdate->execute();
        if ($lotUpdate->affected_rows !== 1) {
            $lotUpdate->close();
            throw new Exception('This lot was already reserved by another buyer.');
        }
        $lotUpdate->close();

        $block_no = $lotLocked['block_no'] ?? '';
        $lot_no = $lotLocked['lot_no'] ?? '';
        $location = $lotLocked['location'] ?? '';

        jej_notify_user(
            $conn,
            $user_id,
            'Reservation Submitted',
            "Your reservation for Block {$block_no} Lot {$lot_no} at {$location} has been submitted and is pending admin verification and payment confirmation."
        );

        $admin_title = 'New Reservation Request';
        $admin_msg = "A buyer submitted a new reservation for Block {$block_no} Lot {$lot_no} at {$location}. Reservation ID: {$reservation_id}.";

        $admins = $conn->query("SELECT id FROM users WHERE role IN ('SUPER ADMIN', 'ADMIN', 'MANAGER')");
        if ($admins) {
            while($admin = $admins->fetch_assoc()){
                jej_notify_user($conn, (int)$admin['id'], $admin_title, $admin_msg);
            }
        }

        if (function_exists('logActivity')) {
            logActivity($conn, $user_id, 'Submitted Reservation Request', "Reservation ID: {$reservation_id} | Lot ID: {$lot_id} | TCP: ₱" . number_format($tcp_after_discount, 2));
        }

        $conn->commit();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        header('Location: reservation_submitted.php?res_id=' . $reservation_id);
        exit();

    } catch (Throwable $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log('Reservation rollback failed: ' . $rollbackError->getMessage());
            }
        }

        foreach ($uploadedFiles as $fileInfo) {
            jej_try_delete_private_upload($fileInfo[0], $fileInfo[1]);
        }

        error_log('Reservation submit failed: ' . $e->getMessage());
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        jej_redirect_reservation_error($lot_id, $e->getMessage());
    }
}

// --- ADMIN ACTIONS ---

if(isset($_POST['action']) && $_POST['action'] == 'approve_res'){
    checkLogin();
    requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER']);

    $res_id = (int)($_POST['res_id'] ?? 0);
    $lot_id = (int)($_POST['lot_id'] ?? 0);
    $admin_id = (int)($_SESSION['user_id'] ?? 0);

    if($res_id <= 0 || $lot_id <= 0){
        header("Location: reservation.php?status=PENDING&msg=invalid_request");
        exit();
    }
    
    // 1. Update Reservation and Lot Status
    // 1. Generate unique account number per approved reservation/lot
$account_number = 'ACC-' . date('Y') . '-' . str_pad($res_id, 5, '0', STR_PAD_LEFT);

// 2. Update Reservation and Lot Status
$stmt_acc = $conn->prepare("
    UPDATE reservations
    SET status = 'APPROVED',
        account_number = ?
    WHERE id = ?
");

$stmt_acc->bind_param("si", $account_number, $res_id);
$stmt_acc->execute();

$conn->query("UPDATE lots SET status='SOLD' WHERE id='$lot_id'");
    
    // 2. Fetch Data for Financial Entry and Notifications
    $resData = $conn->query("
        SELECT r.*, u.id as buyer_id, u.email, u.fullname, l.block_no, l.lot_no, l.total_price 
        FROM reservations r 
        JOIN users u ON r.user_id = u.id 
        JOIN lots l ON r.lot_id = l.id 
        WHERE r.id='$res_id'
    ")->fetch_assoc();
    
    $amount = $resData['total_price'];
    $desc = "Payment for Lot (Block {$resData['block_no']} Lot {$resData['lot_no']}) - Res#$res_id";
    $buyer_id = $resData['buyer_id'];

    // 3. Ensure Accounting Categories Exist
    $catQuery = $conn->query("SELECT id FROM accounting_categories WHERE name='Lot Sales' LIMIT 1");
    if($catQuery && $catQuery->num_rows > 0){
        $cat_id = $catQuery->fetch_assoc()['id'];
    } else {
        $conn->query("INSERT INTO accounting_categories (name, group_name, type) VALUES ('Lot Sales', 'Income', 'INCOME')");
        $cat_id = $conn->insert_id;
    }

    $projQuery = $conn->query("SELECT id FROM projects LIMIT 1");
    if($projQuery && $projQuery->num_rows > 0){
        $proj_id = $projQuery->fetch_assoc()['id'];
    } else {
        $conn->query("INSERT INTO projects (name) VALUES ('General Operations')");
        $proj_id = $conn->insert_id;
    }

    // 4. Record Income
    $or_number = generateORNumber($conn);
    $date = date('Y-m-d');
    $type = 'INCOME';

    $stmt = $conn->prepare("INSERT INTO transactions (or_number, transaction_date, type, category_id, project_id, amount, description, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssiidsi", $or_number, $date, $type, $cat_id, $proj_id, $amount, $desc, $admin_id);
    
    if($stmt->execute()){
        logActivity($conn, $admin_id, "Approved Reservation & Recorded Income", "Res ID: $res_id | Amount: ₱" . number_format($amount, 2));
    }
    
    // 5. IN-APP NOTIFICATION FOR THE BUYER
    $notif_title = "Reservation Approved!";

    $approved_payment_type = strtoupper($resData['payment_type'] ?? 'INSTALLMENT');
    $approved_required_dp = (float)($resData['required_dp'] ?? 0);
    $approved_net_tcp = (float)($resData['net_tcp_after_reservation'] ?? 0);

    if($approved_payment_type === 'CASH'){
        $notif_msg = "Your reservation for Block {$resData['block_no']} Lot {$resData['lot_no']} is approved. Your account number is {$account_number}. No down payment is required for Spot Cash. Please settle the remaining balance of ₱" . number_format($approved_net_tcp, 2) . " within 20 days.";
    } else {
        $notif_msg = "Your reservation for Block {$resData['block_no']} Lot {$resData['lot_no']} is approved. Your account number is {$account_number}. Please settle your required down payment of ₱" . number_format($approved_required_dp, 2) . " within 20 days.";
    }
    $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    $notif_stmt->bind_param("iss", $buyer_id, $notif_title, $notif_msg);
    $notif_stmt->execute();
    
    // 6. SEND EMAIL NOTIFICATION
    require 'PHPMailer/Exception.php';
    require 'PHPMailer/PHPMailer.php';
    require 'PHPMailer/SMTP.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer();
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST; 
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER; 
        $mail->Password   = SMTP_PASS;   
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = 587;
        $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($resData['email']); 
        $mail->isHTML(true);
        $mail->Subject = 'Reservation Approved - Next Steps';
        if($approved_payment_type === 'CASH'){
            $paymentReminder = "Please be reminded that you need to pay the <b>remaining balance of ₱" . number_format($approved_net_tcp, 2) . " within 20 days</b>. Otherwise, the reservation fee may be forfeited.";
        } else {
            $paymentReminder = "Please be reminded that you need to pay the <b>required down payment of ₱" . number_format($approved_required_dp, 2) . " within 20 days</b> to secure your property fully.";
        }

        $mail->Body    = "Hello {$resData['fullname']},<br><br>Congratulations! Your reservation for <b>Block {$resData['block_no']} Lot {$resData['lot_no']}</b> has been approved.<br><br>{$paymentReminder}<br><br>Thank you,<br>JEJ Top Priority Corporation Team";
        $mail->send();
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
    }

    header("Location: payment_terms.php?res_id=$res_id");
    exit();
}

if(isset($_POST['action']) && $_POST['action'] == 'reject_res'){
    checkLogin();
    requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER']);

    $res_id = (int)($_POST['res_id'] ?? 0);
    $admin_id = (int)($_SESSION['user_id'] ?? 0);

    if($res_id <= 0){
        header("Location: reservation.php?status=PENDING&msg=invalid_request");
        exit();
    }
    
    $row = $conn->query("SELECT r.lot_id, r.user_id, l.block_no, l.lot_no FROM reservations r JOIN lots l ON r.lot_id = l.id WHERE r.id='$res_id'")->fetch_assoc();
    $lot_id = $row['lot_id'];
    $buyer_id = $row['user_id'];
    
    $conn->query("UPDATE reservations SET status='REJECTED' WHERE id='$res_id'");
    $conn->query("UPDATE lots SET status='AVAILABLE' WHERE id='$lot_id'");
    
    logActivity($conn, $admin_id, "Rejected Reservation", "Res ID: $res_id was rejected.");
    
    // IN-APP NOTIFICATION FOR THE BUYER
    $notif_title = "Reservation Rejected";
    $notif_msg = "Unfortunately, your reservation for Block {$row['block_no']} Lot {$row['lot_no']} was not approved. Please contact us for more details.";
    $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    $notif_stmt->bind_param("iss", $buyer_id, $notif_title, $notif_msg);
    $notif_stmt->execute();
    
    // --- BUG FIX: This line was changed to redirect you to the REJECTED tab instead of PENDING ---
    header("Location: reservation.php?status=REJECTED&msg=rejected");
    exit();
}
?>