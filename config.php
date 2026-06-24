<?php
// config.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Connection Settings
$host = 'localhost';
$user = 'u574500774_JEJcotporation';
$pass = 'JEJtoppriority2026';
$dbname = 'u574500774_JEJcorp';

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Database Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// ==========================================
// SYSTEM HELPER FUNCTIONS
// ==========================================

// 1. Role-Based Access Control
if (!function_exists('requireRole')) {
    function requireRole($allowed_roles = []) {

        if (!isset($_SESSION['user_id'])) {
            header("Location: login.php");
            exit();
        }

        $user_role = $_SESSION['role'] ?? '';

        if (!in_array($user_role, $allowed_roles)) {
            die("
                <div style='font-family:Arial; text-align:center; padding:50px; color:#991b1b;'>
                    <h2>Access Denied</h2>
                    <p>You do not have permission to access this page.</p>
                    <a href='javascript:history.back()'>Go Back</a>
                </div>
            ");
        }
    }
}

// 2. Manager Permission Checker
if (!function_exists('hasPermission')) {
    function hasPermission($conn, $user_id, $column_name) {

        if (!isset($_SESSION['role'])) {
            return false;
        }

        if ($_SESSION['role'] === 'SUPER ADMIN') {
            return true;
        }

        if ($_SESSION['role'] === 'ADMIN') {
            return true;
        }

        if ($_SESSION['role'] !== 'MANAGER') {
            return false;
        }

        $allowed_columns = [
            'inv_full',
            'inv_property',
            'inv_status',
            'inv_price',

            'res_full',
            'res_process',
            'res_status',
            'res_terms',

            'fin_full',
            'fin_process',
            'fin_review',
            'fin_checks',
            'fin_accounts',

            'usr_full',
            'usr_buyers',
            'usr_promote',
            'usr_admins'
        ];

        if (!in_array($column_name, $allowed_columns)) {
            return false;
        }

        $sql = "SELECT $column_name 
                FROM manager_permissions 
                WHERE user_id = ? 
                LIMIT 1";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result && (int)$result[$column_name] === 1;
    }
}

// 3. Require Specific Manager Permission
if (!function_exists('requirePermission')) {
    function requirePermission($conn, $permission) {

        if (!isset($_SESSION['user_id'])) {
            header("Location: login.php");
            exit();
        }

        if (!hasPermission($conn, $_SESSION['user_id'], $permission)) {
            die("
                <div style='font-family:Arial; text-align:center; padding:50px; color:#991b1b;'>
                    <h2>Access Denied</h2>
                    <p>You do not have permission to perform this action.</p>
                    <a href='javascript:history.back()'>Go Back</a>
                </div>
            ");
        }
    }
}

// 4. Basic Login Check
if (!function_exists('checkLogin')) {
    function checkLogin() {
        if (!isset($_SESSION['user_id'])) {
            header("Location: login.php");
            exit();
        }
    }
}

// 5. Admin Check
if (!function_exists('checkAdmin')) {
    function checkAdmin() {

        requireRole([
            'SUPER ADMIN',
            'ADMIN',
            'MANAGER',
            'CASHIER'
        ]);

        // Redirect cashier directly to finance dashboard
        if (
            isset($_SESSION['role']) &&
            $_SESSION['role'] === 'CASHIER'
        ) {

            $current_page = basename($_SERVER['PHP_SELF']);

            $allowed_pages = [
                'financial.php',
                'transaction_history.php',
                'payment_tracking.php',
                'daily_reconciliation.php',
                'verify_payments.php',
                'logout.php'
            ];

            if (!in_array($current_page, $allowed_pages)) {
                header("Location: financial.php");
                exit();
            }
        }
    }
}

// 6. Audit Trail Logger
if (!function_exists('add_audit_log')) {
    function add_audit_log(
        $conn,
        $user_id,
        $action,
        $details = "",
        $reference_table = null,
        $reference_id = null
    ) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'UNKNOWN';

        if ($ip === '::1') {
            $ip = '127.0.0.1';
        }

        $stmt = $conn->prepare("
            INSERT INTO audit_logs
            (
                user_id,
                action,
                details,
                reference_table,
                reference_id,
                ip_address
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        if ($stmt) {
            $stmt->bind_param(
                "isssis",
                $user_id,
                $action,
                $details,
                $reference_table,
                $reference_id,
                $ip
            );
            $stmt->execute();
            $stmt->close();
        }
    }
}

// 7. Backward-compatible old logActivity()
if (!function_exists('logActivity')) {
    function logActivity($conn, $user_id, $action, $details = "") {
        add_audit_log(
            $conn,
            $user_id,
            $action,
            $details,
            null,
            null
        );
    }
}

// 8. Auto-Generate OR Number
if (!function_exists('generateORNumber')) {
    function generateORNumber($conn) {
        $prefix = "OR-" . date("Ymd") . "-";

        $stmt = $conn->prepare("
            SELECT or_number 
            FROM transactions 
            WHERE or_number LIKE CONCAT(?, '%') 
            ORDER BY id DESC 
            LIMIT 1
        ");

        if (!$stmt) {
            return $prefix . "0001";
        }

        $stmt->bind_param("s", $prefix);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $last_num = (int)str_replace($prefix, "", $row['or_number']);
            $new_num = str_pad($last_num + 1, 4, "0", STR_PAD_LEFT);
        } else {
            $new_num = "0001";
        }

        $stmt->close();

        return $prefix . $new_num;
    }
}

// 9. Auto-Generate CV Number
if (!function_exists('generateCVNumber')) {
    function generateCVNumber($conn) {
        $prefix = "CV-" . date("Ymd") . "-";

        $stmt = $conn->prepare("
            SELECT or_number 
            FROM transactions 
            WHERE or_number LIKE CONCAT(?, '%') 
            ORDER BY id DESC 
            LIMIT 1
        ");

        if (!$stmt) {
            return $prefix . "0001";
        }

        $stmt->bind_param("s", $prefix);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $last_num = (int)str_replace($prefix, "", $row['or_number']);
            $new_num = str_pad($last_num + 1, 4, "0", STR_PAD_LEFT);
        } else {
            $new_num = "0001";
        }

        $stmt->close();

        return $prefix . $new_num;
    }
}

// 10. Archive & Delete History Logger
if (!function_exists('logDeletion')) {
    function logDeletion($conn, $module, $record_id, $data_array, $user_id) {
        $data_json = json_encode($data_array);

        $stmt = $conn->prepare("
            INSERT INTO delete_history 
            (module_name, record_id, record_data, deleted_by) 
            VALUES (?, ?, ?, ?)
        ");

        if ($stmt) {
            $stmt->bind_param("sisi", $module, $record_id, $data_json, $user_id);
            $stmt->execute();
            $stmt->close();
        }

        add_audit_log(
            $conn,
            $user_id,
            'Deleted Record',
            'Deleted record from module: ' . $module,
            $module,
            $record_id
        );
    }
}

// 11. Role Helper
if (!function_exists('hasRole')) {
    function hasRole($roles = []) {
        return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles);
    }
}

// 12. Cashier Finance Access
if (!function_exists('isCashierFinancePage')) {

    function isCashierFinancePage() {

        $allowed_pages = [
            'financial.php',
            'transaction_history.php',
            'payment_tracking.php',
            'daily_reconciliation.php',
            'verify_payments.php'
        ];

        return in_array(
            basename($_SERVER['PHP_SELF']),
            $allowed_pages
        );
    }
}

function secureUpload($file, $upload_dir, $prefix = "FILE", $max_size_mb = 5) {

    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No valid file uploaded.'];
    }

    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];
    $allowed_mime = [
        'application/pdf',
        'image/jpeg',
        'image/png'
    ];

    $max_size = $max_size_mb * 1024 * 1024;

    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File too large. Maximum allowed is ' . $max_size_mb . 'MB.'];
    }

    $original_name = $file['name'];
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_ext)) {
        return ['success' => false, 'message' => 'Invalid file type. PDF, JPG, and PNG only.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowed_mime)) {
        return ['success' => false, 'message' => 'Invalid file content detected.'];
    }

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $new_name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destination = rtrim($upload_dir, '/') . '/' . $new_name;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'message' => 'Failed to save uploaded file.'];
    }

    return [
        'success' => true,
        'filename' => $new_name,
        'path' => $destination
    ];
}
?>
