<?php
// pricing_matrix.php
// JEJ Top Priority Corporation - Pricing Matrix Editor

require_once 'config.php';

checkAdmin();
requireRole(['SUPER ADMIN', 'ADMIN']);

$currentPage = basename($_SERVER['PHP_SELF']);
$financePages = [
    'financial.php',
    'verify_payments.php',
    'payment_tracking.php',
    'transaction_history.php',
    'daily_reconciliation.php',
    'aging_due_report.php',
    'contract_status.php',
    'manual_buyer_entry.php',
    'pricing_matrix.php'
];
$isFinancePage = in_array($currentPage, $financePages, true);

$alert_msg = '';
$alert_type = '';

function pm_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pm_money($value) {
    $value = trim(str_replace(',', '', (string)$value));
    if ($value === '') return 0.00;
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Invalid amount detected. Please enter numbers only.');
    }
    $value = (float)$value;
    if ($value < 0) {
        throw new InvalidArgumentException('Negative amount is not allowed.');
    }
    return round($value, 2);
}

function pm_int($value) {
    $value = trim((string)$value);
    if ($value === '') return 0;
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Invalid payable years value.');
    }
    $value = (int)$value;
    if ($value < 0) {
        throw new InvalidArgumentException('Negative payable years is not allowed.');
    }
    return $value;
}

function pm_date($value) {
    $value = trim((string)$value);
    if ($value === '') return date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        throw new InvalidArgumentException('Invalid effective date format.');
    }
    return $value;
}

function pm_location($value) {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/\s*\(\s*/', ' (', $value);
    $value = preg_replace('/\s*\)\s*/', ')', $value);
    if ($value === '') {
        throw new InvalidArgumentException('Location cannot be blank.');
    }
    return $value;
}


function pm_table_exists($conn, $table) {
    $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
    if ($table === '') return false;

    // Use INFORMATION_SCHEMA instead of SHOW TABLES + prepared statement.
    // This is more reliable on XAMPP/MariaDB when mysqlnd/get_result behaves differently.
    $dbResult = $conn->query("SELECT DATABASE() AS dbname");
    $dbRow = $dbResult ? $dbResult->fetch_assoc() : null;
    $dbName = $dbRow['dbname'] ?? '';

    if ($dbName !== '') {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('ss', $dbName, $table);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();
            return ((int)$count) > 0;
        }
    }

    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return ($res && $res->num_rows > 0);
}

function pm_column_exists($conn, $table, $column) {
    $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
    $column = preg_replace('/[^A-Za-z0-9_]/', '', (string)$column);
    if ($table === '' || $column === '') return false;

    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return ($res && $res->num_rows > 0);
}

function pm_ensure_pricing_matrix_table($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS `pricing_matrix` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `location` VARCHAR(150) NOT NULL,
            `cash_front` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `cash_inner` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `installment_front` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `installment_inner` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `straight_additional_per_sqm` DECIMAL(12,2) NOT NULL DEFAULT 500.00,
            `excess_after_covered_years_per_sqm` DECIMAL(12,2) NOT NULL DEFAULT 300.00,
            `payable_years` INT(11) NOT NULL DEFAULT 3,
            `effective_date` DATE NOT NULL DEFAULT '2026-05-16',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_pricing_matrix_location` (`location`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Cannot create pricing_matrix table: ' . $conn->error);
    }

    $columnsToAdd = [
        'location' => "`location` VARCHAR(150) NOT NULL DEFAULT ''",
        'cash_front' => "`cash_front` DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'cash_inner' => "`cash_inner` DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'installment_front' => "`installment_front` DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'installment_inner' => "`installment_inner` DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'straight_additional_per_sqm' => "`straight_additional_per_sqm` DECIMAL(12,2) NOT NULL DEFAULT 500.00",
        'excess_after_covered_years_per_sqm' => "`excess_after_covered_years_per_sqm` DECIMAL(12,2) NOT NULL DEFAULT 300.00",
        'payable_years' => "`payable_years` INT(11) NOT NULL DEFAULT 3",
        'effective_date' => "`effective_date` DATE NOT NULL DEFAULT '2026-05-16'",
        'created_at' => "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
    ];

    foreach ($columnsToAdd as $column => $definition) {
        if (!pm_column_exists($conn, 'pricing_matrix', $column)) {
            if (!$conn->query("ALTER TABLE `pricing_matrix` ADD COLUMN {$definition}")) {
                throw new RuntimeException('Cannot add missing pricing_matrix column ' . $column . ': ' . $conn->error);
            }
        }
    }

    $indexExists = false;
    $idx = $conn->query("SHOW INDEX FROM `pricing_matrix` WHERE Key_name = 'uniq_pricing_matrix_location'");
    if ($idx && $idx->num_rows > 0) {
        $indexExists = true;
    }
    if (!$indexExists) {
        // Ignore duplicate-key failure here because old data may have duplicate locations.
        @$conn->query("ALTER TABLE `pricing_matrix` ADD UNIQUE KEY `uniq_pricing_matrix_location` (`location`)");
    }

    $count = 0;
    $countRes = $conn->query("SELECT COUNT(*) AS c FROM `pricing_matrix`");
    if ($countRes) {
        $countRow = $countRes->fetch_assoc();
        $count = (int)($countRow['c'] ?? 0);
    }

    // Seed only when table is empty. This will not overwrite existing prices.
    if ($count === 0) {
        $defaults = [
            ['SAN MIGUEL (CAMBIO)', 3500, 2500, 4000, 3000, 500, 300, 3, '2026-05-16'],
            ['TABUATING (SAN LEONARDO)', 3500, 3500, 4000, 4000, 500, 300, 3, '2026-05-16'],
            ['LIWAYWAY (SANTA ROSA)', 2500, 2000, 3000, 2500, 500, 300, 3, '2026-05-16'],
            ['ST. JOSEPH (CABIAO)', 0, 2500, 0, 3000, 500, 300, 3, '2026-05-16'],
            ['SAN VICENTE (CABIAO)', 3000, 2500, 3500, 3000, 500, 300, 3, '2026-05-16'],
            ['POBLACION', 0, 8500, 0, 9000, 500, 300, 3, '2026-05-16'],
            ['LAMBAKIN', 0, 3000, 0, 3500, 500, 300, 3, '2026-05-16'],
            ['LANGLA', 0, 2700, 0, 3500, 500, 300, 3, '2026-05-16'],
            ['SAN FERNANDO SUR', 3500, 3000, 4000, 3500, 500, 300, 3, '2026-05-16'],
            ['ENTABLADO', 0, 3000, 0, 3500, 500, 300, 3, '2026-05-16'],
            ['SAPANG', 0, 4500, 0, 5000, 500, 300, 3, '2026-05-16']
        ];

        $stmt = $conn->prepare("
            INSERT INTO `pricing_matrix`
                (`location`, `cash_front`, `cash_inner`, `installment_front`, `installment_inner`, `straight_additional_per_sqm`, `excess_after_covered_years_per_sqm`, `payable_years`, `effective_date`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if (!$stmt) {
            throw new RuntimeException('Cannot prepare pricing matrix seed query: ' . $conn->error);
        }

        foreach ($defaults as $d) {
            $stmt->bind_param('sddddddis', $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8]);
            if (!$stmt->execute()) {
                throw new RuntimeException('Cannot seed pricing matrix location ' . $d[0] . ': ' . $stmt->error);
            }
        }
        $stmt->close();
    }
}


function pm_csrf_token() {
    if (empty($_SESSION['pricing_matrix_csrf'])) {
        $_SESSION['pricing_matrix_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['pricing_matrix_csrf'];
}

function pm_csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . pm_h(pm_csrf_token()) . '">';
}

function pm_verify_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['pricing_matrix_csrf']) || !hash_equals($_SESSION['pricing_matrix_csrf'], (string)$token)) {
        throw new RuntimeException('Invalid security token. Please refresh the page and try again.');
    }
}

try {
    pm_ensure_pricing_matrix_table($conn);
    $pricingTableReady = pm_table_exists($conn, 'pricing_matrix');
} catch (Throwable $e) {
    $pricingTableReady = false;
    $alert_msg = "Pricing Matrix setup failed: " . pm_h($e->getMessage());
    $alert_type = 'error';
}

if (!$pricingTableReady && $alert_msg === '') {
    $alert_msg = "Table <b>pricing_matrix</b> was not found/created in the active database. Please check config.php database name.";
    $alert_type = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pricingTableReady) {
    try {
        pm_verify_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'save_all') {
            $rows = $_POST['rows'] ?? [];
            if (!is_array($rows) || empty($rows)) {
                throw new InvalidArgumentException('No pricing rows submitted.');
            }

            $stmt = $conn->prepare("\n                UPDATE pricing_matrix\n                SET\n                    location = ?,\n                    cash_front = ?,\n                    cash_inner = ?,\n                    installment_front = ?,\n                    installment_inner = ?,\n                    straight_additional_per_sqm = ?,\n                    excess_after_covered_years_per_sqm = ?,\n                    payable_years = ?,\n                    effective_date = ?\n                WHERE id = ?\n                LIMIT 1\n            ");
            if (!$stmt) {
                throw new RuntimeException('Failed to prepare update query: ' . $conn->error);
            }

            $conn->begin_transaction();
            $updated = 0;

            foreach ($rows as $id => $row) {
                $id = (int)$id;
                if ($id <= 0 || !is_array($row)) continue;

                $location = pm_location($row['location'] ?? '');
                $cashFront = pm_money($row['cash_front'] ?? 0);
                $cashInner = pm_money($row['cash_inner'] ?? 0);
                $installmentFront = pm_money($row['installment_front'] ?? 0);
                $installmentInner = pm_money($row['installment_inner'] ?? 0);
                $straightAdditional = pm_money($row['straight_additional_per_sqm'] ?? 0);
                $excessAfterYears = pm_money($row['excess_after_covered_years_per_sqm'] ?? 0);
                $payableYears = pm_int($row['payable_years'] ?? 0);
                $effectiveDate = pm_date($row['effective_date'] ?? '');

                $stmt->bind_param(
                    'sddddddisi',
                    $location,
                    $cashFront,
                    $cashInner,
                    $installmentFront,
                    $installmentInner,
                    $straightAdditional,
                    $excessAfterYears,
                    $payableYears,
                    $effectiveDate,
                    $id
                );

                if (!$stmt->execute()) {
                    throw new RuntimeException('Failed to update pricing row ID ' . $id . ': ' . $stmt->error);
                }

                $updated++;
            }

            $stmt->close();
            $conn->commit();

            if (function_exists('add_audit_log')) {
                add_audit_log($conn, $_SESSION['user_id'] ?? 0, 'Updated Pricing Matrix', 'Updated ' . $updated . ' pricing matrix row(s).', 'pricing_matrix', null);
            }

            $alert_msg = $updated . ' pricing matrix row(s) saved successfully.';
            $alert_type = 'success';
        }

        if ($action === 'add_new') {
            $location = pm_location($_POST['location'] ?? '');
            $cashFront = pm_money($_POST['cash_front'] ?? 0);
            $cashInner = pm_money($_POST['cash_inner'] ?? 0);
            $installmentFront = pm_money($_POST['installment_front'] ?? 0);
            $installmentInner = pm_money($_POST['installment_inner'] ?? 0);
            $straightAdditional = pm_money($_POST['straight_additional_per_sqm'] ?? 0);
            $excessAfterYears = pm_money($_POST['excess_after_covered_years_per_sqm'] ?? 0);
            $payableYears = pm_int($_POST['payable_years'] ?? 0);
            $effectiveDate = pm_date($_POST['effective_date'] ?? '');

            $check = $conn->prepare("SELECT id FROM pricing_matrix WHERE UPPER(TRIM(location)) = UPPER(TRIM(?)) LIMIT 1");
            if (!$check) {
                throw new RuntimeException('Failed to prepare duplicate check: ' . $conn->error);
            }
            $check->bind_param('s', $location);
            $check->execute();
            $exists = $check->get_result();
            $alreadyExists = ($exists && $exists->num_rows > 0);
            $check->close();

            if ($alreadyExists) {
                throw new InvalidArgumentException('This location already exists. Please edit the existing row instead.');
            }

            $stmt = $conn->prepare("\n                INSERT INTO pricing_matrix\n                    (location, cash_front, cash_inner, installment_front, installment_inner, straight_additional_per_sqm, excess_after_covered_years_per_sqm, payable_years, effective_date, created_at)\n                VALUES\n                    (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())\n            ");
            if (!$stmt) {
                throw new RuntimeException('Failed to prepare insert query: ' . $conn->error);
            }

            $stmt->bind_param(
                'sddddddis',
                $location,
                $cashFront,
                $cashInner,
                $installmentFront,
                $installmentInner,
                $straightAdditional,
                $excessAfterYears,
                $payableYears,
                $effectiveDate
            );

            if (!$stmt->execute()) {
                throw new RuntimeException('Failed to add new location: ' . $stmt->error);
            }

            $newId = $stmt->insert_id;
            $stmt->close();

            if (function_exists('add_audit_log')) {
                add_audit_log($conn, $_SESSION['user_id'] ?? 0, 'Added Pricing Matrix Location', 'Added pricing matrix location: ' . $location, 'pricing_matrix', $newId);
            }

            $alert_msg = 'New location added to pricing matrix successfully.';
            $alert_type = 'success';
        }
    } catch (Throwable $e) {
        if ($conn instanceof mysqli) {
            try {
                $conn->rollback();
            } catch (Throwable $ignore) {}
        }
        $alert_msg = $e->getMessage();
        $alert_type = 'error';
    }
}

$search = trim($_GET['q'] ?? '');
$matrixRows = [];
$totalRows = 0;

if ($pricingTableReady) {
    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $conn->prepare("SELECT * FROM pricing_matrix WHERE location LIKE ? ORDER BY id ASC");
        if ($stmt) {
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $matrixRows[] = $row;
            }
            $stmt->close();
        }
    } else {
        $res = $conn->query("SELECT * FROM pricing_matrix ORDER BY id ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $matrixRows[] = $row;
            }
        }
    }
    $totalRows = count($matrixRows);
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Matrix | JEJ Top Priority Corporation</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root{
            --primary:#2e7d32;
            --primary-light:#e8f5e9;
            --dark:#1b5e20;
            --gray-light:#f1f8e9;
            --gray-border:#c8e6c9;
            --text-muted:#607d8b;
            --danger:#b91c1c;
            --warning:#92400e;
            --shadow-sm:0 1px 2px 0 rgba(46,125,50,.08);
            --shadow-md:0 4px 6px -1px rgba(46,125,50,.10),0 2px 4px -1px rgba(46,125,50,.06);
            --shadow-lg:0 10px 15px -3px rgba(46,125,50,.15),0 4px 6px -2px rgba(46,125,50,.05);
        }
        *{box-sizing:border-box;}
        body{margin:0;background:#fafcf9;display:flex;min-height:100vh;overflow-x:hidden;font-family:'Inter',sans-serif;color:#37474f;}
        a{text-decoration:none;}
        .sidebar{width:260px;background:#fff;border-right:1px solid var(--gray-border);display:flex;flex-direction:column;position:fixed;height:100vh;z-index:100;box-shadow:var(--shadow-sm);transition:width .25s ease,left .25s ease;}
        .brand-box{padding:24px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--gray-border);min-height:88px;overflow:hidden;}
        .brand-box img{height:38px;width:auto;border-radius:8px;object-fit:contain;}
        .brand-box div{line-height:1.1;min-width:0;}
        .brand-box span:first-child{font-size:16px;font-weight:900;color:var(--primary);display:block;white-space:normal;}
        .brand-box span:last-child{font-size:11px;color:var(--text-muted);font-weight:600;}
        .sidebar-menu{padding:20px 15px;flex:1;overflow-y:auto;}
        .sidebar-menu small{padding:0 15px;color:#90a4ae;font-weight:900;font-size:11px;display:block;margin-bottom:12px;letter-spacing:.5px;}
        .menu-link{display:flex;align-items:center;gap:12px;padding:12px 15px;color:#546e7a;text-decoration:none;border-radius:10px;margin-bottom:4px;font-weight:700;font-size:14px;transition:.2s;min-height:44px;}
        .menu-link i{width:20px;text-align:center;color:#78909c;font-size:16px;}
        .menu-link:hover,.menu-link.active{background:var(--primary-light);color:var(--primary);}
        .menu-link:hover i,.menu-link.active i{color:var(--primary);}
        .menu-dropdown{margin-bottom:4px;}
        .dropdown-toggle{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:0;margin-bottom:4px;overflow:hidden;}
        .finance-main-link{display:flex;align-items:center;gap:12px;color:inherit;flex:1;padding:12px 0 12px 15px;min-width:0;}
        .submenu-toggle-btn{border:0;background:transparent;color:inherit;width:44px;height:44px;display:flex;align-items:center;justify-content:center;cursor:pointer;border-radius:10px;}
        .submenu{display:none;margin:5px 0 8px 21px;padding-left:10px;border-left:2px solid #dbead7;}
        .submenu.show{display:block;}
        .submenu .menu-link{font-size:13px;padding:10px 12px;min-height:38px;margin-bottom:3px;border-radius:8px;}
        .submenu .menu-link i{font-size:14px;width:18px;}
        .main-panel{margin-left:260px;width:calc(100% - 260px);min-height:100vh;display:flex;flex-direction:column;transition:margin-left .25s ease,width .25s ease;}
        .top-header{height:auto;min-height:86px;background:white;border-bottom:1px solid var(--gray-border);padding:18px 28px;display:flex;align-items:center;justify-content:space-between;gap:20px;position:sticky;top:0;z-index:50;box-shadow:var(--shadow-sm);}
        .top-header-left{display:flex;align-items:center;gap:14px;min-width:0;}
        .sidebar-toggle{width:44px;height:44px;border:1px solid var(--gray-border);background:#fff;color:var(--primary);border-radius:12px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:18px;transition:.2s;flex:0 0 auto;}
        .sidebar-toggle:hover{background:var(--primary-light);}
        .header-title h1{font-size:23px;font-weight:900;color:var(--dark);margin:0 0 4px;}
        .header-title p{color:var(--text-muted);font-size:13px;margin:0;}
        .header-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;}
        .profile-trigger{display:flex;align-items:center;gap:12px;padding:6px 12px;border-radius:10px;border:1px solid #eef2f7;background:#fff;min-height:52px;}
        .profile-avatar{width:40px;height:40px;background:var(--primary);color:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;}
        .profile-info strong{display:block;font-size:13px;color:var(--dark);}
        .profile-info small{font-size:11px;color:var(--text-muted);}
        .content-area{padding:30px;flex:1;max-width:100%;}
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin-bottom:20px;}
        .stat-card{background:white;border:1px solid var(--gray-border);border-radius:16px;padding:18px;box-shadow:var(--shadow-sm);display:flex;gap:14px;align-items:center;}
        .stat-icon{width:46px;height:46px;border-radius:14px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:19px;flex:0 0 auto;}
        .stat-card small{display:block;color:var(--text-muted);font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.4px;}
        .stat-card strong{display:block;color:#0f172a;font-size:23px;font-weight:900;margin-top:3px;}
        .card{background:white;border:1px solid var(--gray-border);border-radius:18px;box-shadow:var(--shadow-md);overflow:hidden;margin-bottom:22px;}
        .card-header{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap;padding:20px 22px;border-bottom:1px solid var(--gray-border);background:#fff;}
        .card-header h2{margin:0;font-size:17px;font-weight:900;color:var(--dark);display:flex;align-items:center;gap:9px;}
        .card-body{padding:20px 22px;}
        .btn{border:0;border-radius:10px;padding:11px 15px;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:900;font-size:13px;cursor:pointer;line-height:1;text-decoration:none;white-space:nowrap;transition:.2s;min-height:42px;}
        .btn-primary{background:var(--primary);color:white;}
        .btn-primary:hover{background:#1b5e20;}
        .btn-light{background:#f8fafc;color:#334155;border:1px solid #cbd5e1;}
        .btn-light:hover{background:#eef2f7;}
        .btn-warning{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;}
        .alert{padding:14px 16px;border-radius:14px;margin-bottom:18px;font-weight:800;border:1px solid transparent;display:flex;gap:10px;align-items:flex-start;}
        .alert-success{background:#ecfdf5;color:#047857;border-color:#a7f3d0;}
        .alert-error{background:#fef2f2;color:#b91c1c;border-color:#fecaca;}
        .toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
        .search-form{display:flex;align-items:center;gap:9px;flex-wrap:wrap;}
        .form-control{height:42px;border:1px solid #cbd5e1;border-radius:10px;padding:0 12px;background:#fff;color:#0f172a;font:inherit;font-size:13px;font-weight:650;outline:none;}
        .form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.13);}
        .search-input{min-width:280px;}
        .table-wrap{width:100%;overflow-x:auto;border:1px solid #e2e8f0;border-radius:16px;background:#fff;}
        .matrix-table{width:100%;min-width:1380px;border-collapse:collapse;font-size:13px;}
        .matrix-table th{position:sticky;top:0;z-index:1;background:#f1f8e9;color:#1b5e20;text-align:left;padding:13px 12px;border-bottom:1px solid var(--gray-border);font-size:11px;text-transform:uppercase;letter-spacing:.35px;font-weight:900;}
        .matrix-table td{padding:10px 9px;border-bottom:1px solid #e2e8f0;vertical-align:middle;}
        .matrix-table tr:last-child td{border-bottom:0;}
        .matrix-table tr:hover td{background:#fbfef9;}
        .matrix-table input{width:100%;height:38px;border:1px solid #d7e3d2;border-radius:9px;padding:0 9px;font:inherit;font-size:13px;font-weight:700;color:#0f172a;background:#fff;outline:none;}
        .matrix-table input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
        .matrix-table input[type='number']{text-align:right;}
        .id-cell{width:58px;text-align:center;font-weight:900;color:#64748b;}
        .location-cell{min-width:240px;}
        .money-cell{min-width:140px;}
        .years-cell{min-width:110px;}
        .date-cell{min-width:145px;}
        .save-row{margin-top:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
        .note{color:#64748b;font-size:13px;font-weight:650;line-height:1.5;}
        .add-grid{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:14px;}
        .field.full{grid-column:1/-1;}
        .field label{display:block;font-size:11px;color:#64748b;text-transform:uppercase;font-weight:900;margin-bottom:6px;letter-spacing:.35px;}
        .field .form-control{width:100%;}
        body.sidebar-collapsed .sidebar{width:78px;}
        body.sidebar-collapsed .main-panel{margin-left:78px;width:calc(100% - 78px);}
        body.sidebar-collapsed .brand-box{justify-content:center;padding:24px 10px;}
        body.sidebar-collapsed .brand-box div,
        body.sidebar-collapsed .sidebar-menu small,
        body.sidebar-collapsed .menu-text,
        body.sidebar-collapsed .finance-main-link span,
        body.sidebar-collapsed .submenu,
        body.sidebar-collapsed .submenu-toggle-btn{display:none!important;}
        body.sidebar-collapsed .menu-link{justify-content:center;padding:12px 10px;gap:0;}
        body.sidebar-collapsed .menu-link i{width:22px;font-size:18px;}
        body.sidebar-collapsed .finance-main-link{justify-content:center;padding:12px 10px;gap:0;}
        @media(max-width:1000px){
            .sidebar{left:-260px;}
            .main-panel,body.sidebar-collapsed .main-panel{margin-left:0;width:100%;}
            body.sidebar-open .sidebar{left:0;width:260px;z-index:101;}
            body.sidebar-open::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:90;}
            .top-header{padding:16px 18px;align-items:flex-start;flex-direction:column;}
            .header-actions{justify-content:flex-start;width:100%;}
            .profile-info{display:none;}
            .content-area{padding:18px;}
            .add-grid{grid-template-columns:1fr;}
            .search-input{min-width:0;width:100%;}
            .search-form{width:100%;}
            .search-form .form-control,.search-form .btn{width:100%;}
        }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="brand-box">
        <img src="assets/LOGO1.png" alt="JEJ Logo">
        <div>
            <span>JEJ Top Priority Corporation</span>
            <span>Management Portal</span>
        </div>
    </div>

    <div class="sidebar-menu">
        <small>MAIN MENU</small>
        <a href="admin.php" class="menu-link"><i class="fa-solid fa-chart-pie"></i><span class="menu-text">Dashboard</span></a>
        <a href="reservation.php" class="menu-link"><i class="fa-solid fa-file-signature"></i><span class="menu-text">Reservations</span></a>
        <a href="master_list.php" class="menu-link"><i class="fa-solid fa-map-location-dot"></i><span class="menu-text">Master List / Map</span></a>
        <a href="admin.php?view=inventory" class="menu-link"><i class="fa-solid fa-plus-circle"></i><span class="menu-text">Add Property</span></a>

        <div class="menu-dropdown">
            <div class="menu-link dropdown-toggle <?= $isFinancePage ? 'active' : '' ?>">
                <a href="financial.php" class="finance-main-link">
                    <i class="fa-solid fa-coins"></i>
                    <span>Financials</span>
                </a>
                <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)" title="Show/Hide Financial Menu">
                    <i class="fa-solid <?= $isFinancePage ? 'fa-chevron-up' : 'fa-chevron-down' ?>" id="financeArrow"></i>
                </button>
            </div>

            <div id="financeSubMenu" class="submenu <?= $isFinancePage ? 'show' : '' ?>">
                <a href="verify_payments.php" class="menu-link submenu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>"><i class="fa-solid fa-circle-check"></i><span class="menu-text">Verify Payments</span></a>
                <a href="payment_tracking.php" class="menu-link submenu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i><span class="menu-text">Payment Tracking</span></a>
                <a href="transaction_history.php" class="menu-link submenu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>"><i class="fa-solid fa-list-ul"></i><span class="menu-text">Ledger List</span></a>
                <a href="daily_reconciliation.php" class="menu-link submenu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>"><i class="fa-solid fa-scale-balanced"></i><span class="menu-text">Daily Reconciliation</span></a>
                <a href="aging_due_report.php" class="menu-link submenu-link <?= $currentPage == 'aging_due_report.php' ? 'active' : '' ?>"><i class="fa-solid fa-clock"></i><span class="menu-text">Aging / Due Report</span></a>
                <a href="contract_status.php" class="menu-link submenu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-signature"></i><span class="menu-text">Contract Status</span></a>
                <a href="manual_buyer_entry.php" class="menu-link submenu-link <?= $currentPage == 'manual_buyer_entry.php' ? 'active' : '' ?>"><i class="fa-solid fa-user-plus"></i><span class="menu-text">Manual Buyer Entry</span></a>
                <a href="pricing_matrix.php" class="menu-link submenu-link <?= $currentPage == 'pricing_matrix.php' ? 'active' : '' ?>"><i class="fa-solid fa-table-list"></i><span class="menu-text">Pricing Matrix</span></a>
            </div>
        </div>

        <small style="margin-top:25px;">CUSTOMERS</small>
        <a href="buyers.php" class="menu-link"><i class="fa-solid fa-users"></i><span class="menu-text">Buyers</span></a>

        <small style="margin-top:25px;">MANAGEMENT</small>
        <a href="agent_tracking.php" class="menu-link"><i class="fa-solid fa-user-tie"></i><span class="menu-text">Agent Tracking</span></a>
        <a href="inquiries.php" class="menu-link"><i class="fa-solid fa-envelope-open-text"></i><span class="menu-text">Inquiries</span></a>
        <a href="accounts.php" class="menu-link"><i class="fa-solid fa-users-gear"></i><span class="menu-text">Accounts</span></a>
        <a href="delete_history.php" class="menu-link"><i class="fa-solid fa-trash-can"></i><span class="menu-text">Delete History</span></a>

        <small style="margin-top:25px;">SYSTEM</small>
        <a href="index.php" class="menu-link" target="_blank"><i class="fa-solid fa-globe"></i><span class="menu-text">View Website</span></a>
    </div>
</div>

<div class="main-panel">
    <div class="top-header">
        <div class="top-header-left">
            <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar"><i class="fa-solid fa-bars"></i></button>
            <div class="header-title">
                <h1>Pricing Matrix</h1>
                <p>Master price per SQM by location and classification. Ito ang source ng lot TCP computation.</p>
            </div>
        </div>

        <div class="header-actions">
            <a href="financial.php" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Financial</a>
            <div class="profile-trigger">
                <div class="profile-avatar"><?= strtoupper(substr($_SESSION['fullname'] ?? 'A', 0, 1)); ?></div>
                <div class="profile-info">
                    <strong><?= pm_h($_SESSION['fullname'] ?? 'Administrator'); ?></strong>
                    <small><?= pm_h($_SESSION['role'] ?? 'ADMIN'); ?></small>
                </div>
            </div>
        </div>
    </div>

    <div class="content-area">
        <?php if ($alert_msg !== ''): ?>
            <div class="alert alert-<?= $alert_type === 'success' ? 'success' : 'error' ?>">
                <i class="fa-solid <?= $alert_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                <div><?= $alert_msg ?></div>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-table-list"></i></div>
                <div><small>Total Matrix Rows</small><strong><?= number_format($totalRows) ?></strong></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
                <div><small>Default Effective Date</small><strong style="font-size:18px;"><?= pm_h($today) ?></strong></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-shield-halved"></i></div>
                <div><small>Access</small><strong style="font-size:18px;">Admin Only</strong></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2><i class="fa-solid fa-pen-to-square"></i> Edit Pricing Matrix</h2>
                <div class="toolbar">
                    <form method="GET" action="pricing_matrix.php" class="search-form">
                        <input type="text" name="q" class="form-control search-input" placeholder="Search location..." value="<?= pm_h($search) ?>">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                        <?php if ($search !== ''): ?>
                            <a href="pricing_matrix.php" class="btn btn-light"><i class="fa-solid fa-xmark"></i> Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="card-body">
                <?php if ($pricingTableReady): ?>
                    <form method="POST" action="pricing_matrix.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>">
                        <?= pm_csrf_field() ?>
                        <input type="hidden" name="action" value="save_all">

                        <div class="table-wrap">
                            <table class="matrix-table">
                                <thead>
                                    <tr>
                                        <th class="id-cell">ID</th>
                                        <th class="location-cell">Location</th>
                                        <th class="money-cell">Cash Front</th>
                                        <th class="money-cell">Cash Inner</th>
                                        <th class="money-cell">Installment Front</th>
                                        <th class="money-cell">Installment Inner</th>
                                        <th class="money-cell">Straight Add'l / SQM</th>
                                        <th class="money-cell">Excess After Years / SQM</th>
                                        <th class="years-cell">Payable Years</th>
                                        <th class="date-cell">Effective Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($matrixRows)): ?>
                                        <tr><td colspan="10" style="text-align:center;color:#64748b;padding:26px;font-weight:800;">No pricing matrix record found.</td></tr>
                                    <?php endif; ?>

                                    <?php foreach ($matrixRows as $row): ?>
                                        <?php $id = (int)($row['id'] ?? 0); ?>
                                        <tr>
                                            <td class="id-cell"><?= $id ?></td>
                                            <td class="location-cell"><input type="text" name="rows[<?= $id ?>][location]" value="<?= pm_h($row['location'] ?? '') ?>" required></td>
                                            <td class="money-cell"><input type="number" step="0.01" min="0" name="rows[<?= $id ?>][cash_front]" value="<?= pm_h($row['cash_front'] ?? '0.00') ?>"></td>
                                            <td class="money-cell"><input type="number" step="0.01" min="0" name="rows[<?= $id ?>][cash_inner]" value="<?= pm_h($row['cash_inner'] ?? '0.00') ?>"></td>
                                            <td class="money-cell"><input type="number" step="0.01" min="0" name="rows[<?= $id ?>][installment_front]" value="<?= pm_h($row['installment_front'] ?? '0.00') ?>"></td>
                                            <td class="money-cell"><input type="number" step="0.01" min="0" name="rows[<?= $id ?>][installment_inner]" value="<?= pm_h($row['installment_inner'] ?? '0.00') ?>"></td>
                                            <td class="money-cell"><input type="number" step="0.01" min="0" name="rows[<?= $id ?>][straight_additional_per_sqm]" value="<?= pm_h($row['straight_additional_per_sqm'] ?? '500.00') ?>"></td>
                                            <td class="money-cell"><input type="number" step="0.01" min="0" name="rows[<?= $id ?>][excess_after_covered_years_per_sqm]" value="<?= pm_h($row['excess_after_covered_years_per_sqm'] ?? '300.00') ?>"></td>
                                            <td class="years-cell"><input type="number" step="1" min="0" name="rows[<?= $id ?>][payable_years]" value="<?= pm_h($row['payable_years'] ?? '3') ?>"></td>
                                            <td class="date-cell"><input type="date" name="rows[<?= $id ?>][effective_date]" value="<?= pm_h($row['effective_date'] ?? $today) ?>"></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="save-row">
                            <div class="note">
                                <strong>Note:</strong> Use <b>0.00</b> kapag walang Front price. Save All updates all visible rows.
                            </div>
                            <button type="submit" class="btn btn-primary" onclick="return confirm('Save all Pricing Matrix changes?');"><i class="fa-solid fa-floppy-disk"></i> Save All Changes</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="note">Cannot load records because the pricing_matrix table is missing or cannot be created. Please check config.php database name and MySQL user privileges.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($pricingTableReady): ?>
        <div class="card">
            <div class="card-header">
                <h2><i class="fa-solid fa-plus-circle"></i> Add New Location</h2>
                <div class="note">Optional lang ito kung may bagong project/location.</div>
            </div>
            <div class="card-body">
                <form method="POST" action="pricing_matrix.php">
                    <?= pm_csrf_field() ?>
                    <input type="hidden" name="action" value="add_new">

                    <div class="add-grid">
                        <div class="field full">
                            <label>Location</label>
                            <input type="text" name="location" class="form-control" placeholder="Example: NUEVA LOCATION" required>
                        </div>
                        <div class="field">
                            <label>Cash Front</label>
                            <input type="number" step="0.01" min="0" name="cash_front" class="form-control" value="0.00">
                        </div>
                        <div class="field">
                            <label>Cash Inner</label>
                            <input type="number" step="0.01" min="0" name="cash_inner" class="form-control" value="0.00">
                        </div>
                        <div class="field">
                            <label>Installment Front</label>
                            <input type="number" step="0.01" min="0" name="installment_front" class="form-control" value="0.00">
                        </div>
                        <div class="field">
                            <label>Installment Inner</label>
                            <input type="number" step="0.01" min="0" name="installment_inner" class="form-control" value="0.00">
                        </div>
                        <div class="field">
                            <label>Straight Add'l / SQM</label>
                            <input type="number" step="0.01" min="0" name="straight_additional_per_sqm" class="form-control" value="500.00">
                        </div>
                        <div class="field">
                            <label>Excess After Years / SQM</label>
                            <input type="number" step="0.01" min="0" name="excess_after_covered_years_per_sqm" class="form-control" value="300.00">
                        </div>
                        <div class="field">
                            <label>Payable Years</label>
                            <input type="number" step="1" min="0" name="payable_years" class="form-control" value="3">
                        </div>
                        <div class="field">
                            <label>Effective Date</label>
                            <input type="date" name="effective_date" class="form-control" value="<?= pm_h($today) ?>">
                        </div>
                    </div>

                    <div class="save-row">
                        <div class="note">New location will be saved in uppercase format para consistent sa lot locations.</div>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Location</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleFinanceMenu(event){
    if(event) event.stopPropagation();
    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');
    if(!submenu) return;
    submenu.classList.toggle('show');
    if(arrow){
        arrow.classList.toggle('fa-chevron-down');
        arrow.classList.toggle('fa-chevron-up');
    }
}

function toggleSidebar(){
    if(window.innerWidth <= 1000){
        document.body.classList.toggle('sidebar-open');
    }else{
        document.body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('jej_sidebar_collapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
    }
}

if(window.innerWidth > 1000 && localStorage.getItem('jej_sidebar_collapsed') === '1'){
    document.body.classList.add('sidebar-collapsed');
}

document.addEventListener('click', function(e){
    if(window.innerWidth <= 1000 && document.body.classList.contains('sidebar-open')){
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
