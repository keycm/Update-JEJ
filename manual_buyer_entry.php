<?php
// manual_buyer_entry.php
// Manual encoding of previous / legacy buyers so they can be tracked in Payment Tracking, Ledger, Aging Report, Agent Tracking, and Dashboard.

require_once 'config.php';
checkAdmin();
requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER']);

if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'fin_full');
}

$currentPage = basename($_SERVER['PHP_SELF']);
$financePages = [
    'financial.php',
    'payment_tracking.php',
    'daily_reconciliation.php',
    'verify_payments.php',
    'transaction_history.php',
    'aging_due_report.php',
    'contract_status.php',
    'manual_buyer_entry.php',
    'pricing_matrix.php'
];
$isFinancePage = in_array($currentPage, $financePages, true);

$alert_msg = '';
$alert_type = '';

function table_columns(mysqli $conn, string $table): array {
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM `$table`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    return $cols;
}

function has_col(mysqli $conn, string $table, string $col): bool {
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = table_columns($conn, $table);
    }
    return in_array($col, $cache[$table], true);
}

function bind_type($value): string {
    if (is_int($value)) return 'i';
    if (is_float($value) || is_double($value)) return 'd';
    return 's';
}

function insert_dynamic(mysqli $conn, string $table, array $data): int {
    $cols = table_columns($conn, $table);
    $filtered = [];

    foreach ($data as $key => $value) {
        if (in_array($key, $cols, true)) {
            $filtered[$key] = $value;
        }
    }

    if (empty($filtered)) {
        throw new Exception("No matching columns found for table {$table}.");
    }

    $names = array_keys($filtered);
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $names) . "`) VALUES ($placeholders)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $types = '';
    $values = [];
    foreach ($filtered as $value) {
        $types .= bind_type($value);
        $values[] = $value;
    }

    $stmt->bind_param($types, ...$values);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $insertId = $stmt->insert_id;
    $stmt->close();
    return $insertId;
}

function generate_account_number(mysqli $conn, int $reservationId): string {
    $year = date('Y');
    $seq = str_pad((string)$reservationId, 4, '0', STR_PAD_LEFT);
    return "JEJ-{$year}-{$seq}";
}

function generate_manual_or(mysqli $conn): string {
    $date = date('Ymd');
    $prefix = "OR-{$date}-";
    $counter = 1;

    do {
        $or = $prefix . str_pad((string)$counter, 4, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("SELECT id FROM transactions WHERE or_number = ? LIMIT 1");
        if (!$stmt) return $or;
        $stmt->bind_param('s', $or);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        $counter++;
    } while ($exists);

    return $or;
}

function add_verified_income(mysqli $conn, int $reservationId, float $amount, string $date, string $description, int $userId = 0): void {
    if ($amount <= 0) return;

    $data = [
        'or_number' => generate_manual_or($conn),
        'transaction_date' => $date,
        'type' => 'INCOME',
        'amount' => $amount,
        'description' => $description,
        'reservation_id' => $reservationId,
        'user_id' => $userId,
        'status' => 'VERIFIED',
        'payment_status' => 'VERIFIED'
    ];

    insert_dynamic($conn, 'transactions', $data);
}

// Fetch lots for dropdown
$lots = [];
$lotQuery = $conn->query("SELECT id, block_no, lot_no, location, total_price, status FROM lots ORDER BY block_no, lot_no");
if ($lotQuery) {
    while ($row = $lotQuery->fetch_assoc()) {
        $lots[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_manual_buyer'])) {
    try {
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['buyer_address'] ?? '');
        $agentName = trim($_POST['agent_name'] ?? '');
        $lotId = (int)($_POST['lot_id'] ?? 0);
        $requiredDp = (float)($_POST['required_dp'] ?? 0);
        $paymentType = trim($_POST['payment_type'] ?? 'INSTALLMENT');
        $installmentMonths = (int)($_POST['installment_months'] ?? 0);
        $monthlyPayment = (float)($_POST['monthly_payment'] ?? 0);
        $dpPaid = (float)($_POST['dp_paid'] ?? 0);
        $amortPaid = (float)($_POST['amortization_paid'] ?? 0);
        $reservationDate = $_POST['reservation_date'] ?? date('Y-m-d');
        $remarks = trim($_POST['remarks'] ?? 'Manual previous buyer entry');

        if ($fullname === '') {
            throw new Exception('Buyer name is required.');
        }
        if ($lotId <= 0) {
            throw new Exception('Please select a lot.');
        }

        // Get lot price
        $stmtLot = $conn->prepare("SELECT total_price FROM lots WHERE id = ? LIMIT 1");
        if (!$stmtLot) throw new Exception($conn->error);
        $stmtLot->bind_param('i', $lotId);
        $stmtLot->execute();
        $lotRow = $stmtLot->get_result()->fetch_assoc();
        $stmtLot->close();

        if (!$lotRow) {
            throw new Exception('Selected lot was not found.');
        }

        $totalPrice = (float)($lotRow['total_price'] ?? 0);
        if ($requiredDp <= 0 && $totalPrice > 0) {
            $requiredDp = $totalPrice * 0.20;
        }

        if ($paymentType === 'INSTALLMENT' && $installmentMonths > 0 && $monthlyPayment <= 0) {
            $monthlyPayment = max(0, ($totalPrice - $requiredDp) / $installmentMonths);
        }

        $totalPaid = $dpPaid + $amortPaid;
        $dpStatus = ($dpPaid >= $requiredDp && $requiredDp > 0) ? 'PAID' : (($dpPaid > 0) ? 'PARTIAL' : 'NO PAYMENT');

        $conn->begin_transaction();

        // Find or create buyer user
        $userId = 0;
        if ($email !== '') {
            $stmtUser = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            if (!$stmtUser) throw new Exception($conn->error);
            $stmtUser->bind_param('s', $email);
            $stmtUser->execute();
            $userRow = $stmtUser->get_result()->fetch_assoc();
            $stmtUser->close();
            if ($userRow) {
                $userId = (int)$userRow['id'];
            }
        }

        if ($userId <= 0) {
            $defaultPassword = md5('123456');
            $userId = insert_dynamic($conn, 'users', [
                'fullname' => $fullname,
                'phone' => $phone,
                'email' => $email,
                'password' => $defaultPassword,
                'role' => 'BUYER'
            ]);
        }

        // Create approved reservation/account
        $reservationId = insert_dynamic($conn, 'reservations', [
            'user_id' => $userId,
            'contact_number' => $phone,
            'email' => $email,
            'lot_id' => $lotId,
            'reservation_date' => $reservationDate,
            'status' => 'APPROVED',
            'required_dp' => $requiredDp,
            'dp_status' => $dpStatus,
            'payment_type' => $paymentType,
            'installment_months' => $installmentMonths,
            'monthly_payment' => $monthlyPayment,
            'notes' => $remarks,
            'buyer_address' => $address,
            'agent_name' => $agentName
        ]);

        // Account number after getting reservation ID
        if (has_col($conn, 'reservations', 'account_number')) {
            $accountNo = generate_account_number($conn, $reservationId);
            $stmtAcc = $conn->prepare("UPDATE reservations SET account_number = ? WHERE id = ?");
            if (!$stmtAcc) throw new Exception($conn->error);
            $stmtAcc->bind_param('si', $accountNo, $reservationId);
            $stmtAcc->execute();
            $stmtAcc->close();
        }

        // Add verified beginning balances to ledger
        add_verified_income($conn, $reservationId, $dpPaid, $reservationDate, "Down Payment for Res#{$reservationId} - manual previous buyer", $_SESSION['user_id'] ?? 0);
        add_verified_income($conn, $reservationId, $amortPaid, $reservationDate, "Monthly Amortization for Res#{$reservationId} - manual previous buyer", $_SESSION['user_id'] ?? 0);

        // Update lot status if the column exists
        $newLotStatus = ($totalPrice > 0 && $totalPaid >= $totalPrice) ? 'SOLD' : 'RESERVED';
        if (has_col($conn, 'lots', 'status')) {
            $stmtLotStatus = $conn->prepare("UPDATE lots SET status = ? WHERE id = ?");
            if ($stmtLotStatus) {
                $stmtLotStatus->bind_param('si', $newLotStatus, $lotId);
                $stmtLotStatus->execute();
                $stmtLotStatus->close();
            }
        } elseif (has_col($conn, 'lots', 'current_status')) {
            $stmtLotStatus = $conn->prepare("UPDATE lots SET current_status = ? WHERE id = ?");
            if ($stmtLotStatus) {
                $stmtLotStatus->bind_param('si', $newLotStatus, $lotId);
                $stmtLotStatus->execute();
                $stmtLotStatus->close();
            }
        }

        if (function_exists('logActivity')) {
            logActivity($conn, $_SESSION['user_id'] ?? 0, 'Manual Buyer Entry', "Created previous buyer account Res#{$reservationId} for {$fullname}");
        }

        $conn->commit();
        $alert_msg = "Previous buyer account successfully created. Reservation ID: #{$reservationId}.";
        $alert_type = 'success';

    } catch (Exception $e) {
        if ($conn->errno === 0) {
            // ignore
        }
        try { $conn->rollback(); } catch (Throwable $t) {}
        $alert_msg = 'Error: ' . $e->getMessage();
        $alert_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Buyer Entry | JEJ Admin</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root{
            --primary:#2e7d32;
            --primary-light:#e8f5e9;
            --dark:#1b5e20;
            --gray-light:#f1f8e9;
            --gray-border:#c8e6c9;
            --text-muted:#607d8b;
            --shadow-sm:0 1px 2px 0 rgba(46,125,50,.08);
            --shadow-md:0 4px 6px -1px rgba(46,125,50,.1),0 2px 4px -1px rgba(46,125,50,.06);
        }
        *{box-sizing:border-box;}
        body{background:#fafcf9;display:flex;min-height:100vh;overflow-x:hidden;font-family:'Inter',sans-serif;color:#37474f;margin:0;}
        .sidebar{width:260px;background:#fff;border-right:1px solid var(--gray-border);display:flex;flex-direction:column;position:fixed;height:100vh;z-index:100;box-shadow:var(--shadow-sm);}
        .brand-box{padding:25px;border-bottom:1px solid var(--gray-border);display:flex;align-items:center;gap:12px;}
        .sidebar-menu{padding:20px 15px;flex:1;overflow-y:auto;}
        .menu-link{display:flex;align-items:center;gap:12px;padding:12px 18px;color:#455a64;text-decoration:none;font-weight:500;font-size:14px;border-radius:10px;margin-bottom:6px;transition:all .2s ease;}
        .menu-link:hover{background:var(--gray-light);color:var(--primary);}
        .menu-link.active{background:var(--primary-light);color:var(--primary);font-weight:700;border-left:4px solid var(--primary);}
        .menu-link i{width:20px;text-align:center;font-size:16px;opacity:.85;}
        .menu-dropdown{margin-bottom:6px;}
        .dropdown-toggle{display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;}
        .finance-main-link{display:flex;align-items:center;gap:12px;flex:1;color:inherit;text-decoration:none;}
        .submenu-toggle-btn{background:none;border:none;color:inherit;cursor:pointer;width:28px;height:28px;border-radius:6px;}
        .submenu-toggle-btn:hover{background:#dff2e1;}
        .submenu{display:none!important;padding-left:18px;margin-top:4px;margin-bottom:8px;}
        .submenu.show{display:block!important;}
        .main-panel{margin-left:260px;flex:1;width:calc(100% - 260px);display:flex;flex-direction:column;}
        .top-header{display:flex;justify-content:space-between;align-items:center;background:#fff;padding:20px 40px;border-bottom:1px solid var(--gray-border);box-shadow:var(--shadow-sm);}
        .header-title h1{font-size:22px;font-weight:800;color:var(--dark);margin:0 0 4px;letter-spacing:-.5px;}
        .header-title p{color:var(--text-muted);font-size:13px;margin:0;}
        /* PROFILE DROPDOWN - same style as verify_payments.php */
        .header-right{
            margin-left:auto;
            display:flex;
            align-items:center;
            justify-content:flex-end;
            flex-shrink:0;
        }

        .profile-dropdown{
            position:relative;
        }

        .profile-trigger{
            display:flex;
            align-items:center;
            gap:12px;
            padding:6px 10px;
            border-radius:10px;
            cursor:pointer;
            transition:.2s ease;
            border:1px solid transparent;
            background:transparent;
        }

        .profile-trigger:hover{
            background:#f1f8e9;
            border-color:#c8e6c9;
        }

        .profile-avatar{
            width:42px;
            height:42px;
            border-radius:50%;
            background:#2e7d32;
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:800;
            font-size:16px;
            flex-shrink:0;
        }

        .profile-info strong{
            display:block;
            font-size:14px;
            color:#1b5e20;
            line-height:1.2;
        }

        .profile-info small{
            color:#607d8b;
            font-size:12px;
        }

        .profile-trigger > i{
            font-size:12px;
            color:#607d8b;
        }

        .dropdown-menu{
            position:absolute;
            top:110%;
            right:0;
            width:240px;
            background:#fff;
            border:1px solid #c8e6c9;
            border-radius:14px;
            box-shadow:0 10px 25px rgba(0,0,0,.12);
            display:none;
            overflow:hidden;
            z-index:9999;
        }

        .profile-dropdown:hover .dropdown-menu{
            display:block;
        }

        .dropdown-header{
            padding:18px;
            background:#f1f8e9;
            border-bottom:1px solid #c8e6c9;
        }

        .dropdown-header strong{
            display:block;
            font-size:14px;
            color:#1b5e20;
        }

        .dropdown-header span{
            font-size:12px;
            color:#607d8b;
        }

        .dropdown-item{
            display:flex;
            align-items:center;
            gap:12px;
            padding:12px 18px;
            text-decoration:none;
            color:#37474f;
            font-size:14px;
            font-weight:500;
        }

        .dropdown-item i{
            width:18px;
            text-align:center;
            font-size:14px;
        }

        .dropdown-item:hover{
            background:#f8fafc;
        }

        .dropdown-item.text-danger{
            color:#ea580c;
        }
        .content-area{padding:35px 40px;flex:1;}
        .alert{padding:16px 20px;border-radius:12px;margin-bottom:25px;font-weight:600;font-size:14px;box-shadow:var(--shadow-sm);}
        .alert.success{background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9;}
        .alert.error{background:#fbe9e7;color:#d84315;border:1px solid #ffccbc;}
        .form-card{background:#fff;border:1px solid var(--gray-border);border-radius:18px;box-shadow:var(--shadow-sm);overflow:hidden;}
        .card-header{padding:22px 26px;background:var(--gray-light);border-bottom:1px solid var(--gray-border);}
        .card-header h2{margin:0;color:var(--dark);font-size:18px;font-weight:800;display:flex;gap:10px;align-items:center;}
        .card-body{padding:26px;}
        .section-label{font-size:13px;font-weight:800;color:var(--dark);margin:0 0 16px;display:flex;gap:8px;align-items:center;border-bottom:1px solid var(--gray-border);padding-bottom:10px;}
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 22px;margin-bottom:28px;}
        .form-grid.three{grid-template-columns:repeat(3,minmax(0,1fr));}
        .form-group label{display:block;font-size:12px;font-weight:800;color:#455a64;margin-bottom:7px;text-transform:uppercase;letter-spacing:.2px;}
        .form-control{width:100%;padding:12px 14px;border:1px solid #cbd5e1;border-radius:9px;font-family:inherit;font-size:14px;outline:none;background:#f8fafc;transition:.2s;}
        .form-control:focus{border-color:var(--primary);background:white;box-shadow:0 0 0 3px rgba(46,125,50,.15);}
        .full-width{grid-column:1/-1;}
        .note-box{background:#fff8e1;border:1px solid #ffd54f;color:#8a4b00;border-radius:12px;padding:14px 16px;font-size:13px;line-height:1.5;margin-bottom:24px;}
        .actions{display:flex;justify-content:flex-end;gap:12px;padding-top:20px;border-top:1px solid var(--gray-border);}
        .btn{border:none;border-radius:9px;padding:12px 20px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px;font-family:inherit;}
        .btn-success{background:#10b981;color:#fff;}
        .btn-success:hover{background:#059669;}
        .btn-light{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;}

        /* AUTO FIT + COLLAPSIBLE SIDEBAR */
        .sidebar,
        .main-panel{transition:all .25s ease;}
        .top-header-left{display:flex;align-items:center;gap:12px;min-width:0;}
        .sidebar-toggle{width:42px;height:42px;border:none;border-radius:10px;background:var(--primary-light);color:var(--primary);font-size:18px;cursor:pointer;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;}
        .sidebar-toggle:hover{background:#c8e6c9;}
        .sidebar .menu-text{display:inline;}
        body.sidebar-collapsed .sidebar{width:78px;}
        body.sidebar-collapsed .main-panel{margin-left:78px;width:calc(100% - 78px);}
        body.sidebar-collapsed .brand-box{justify-content:center;padding:25px 10px;}
        body.sidebar-collapsed .brand-box div,
        body.sidebar-collapsed .sidebar-menu small,
        body.sidebar-collapsed .menu-text,
        body.sidebar-collapsed .finance-main-link span,
        body.sidebar-collapsed .submenu,
        body.sidebar-collapsed .submenu-toggle-btn{display:none!important;}
        body.sidebar-collapsed .menu-link{justify-content:center;padding:14px 10px;gap:0;border-left:0!important;}
        body.sidebar-collapsed .menu-link i{width:22px;font-size:18px;}
        body.sidebar-collapsed .finance-main-link{justify-content:center;gap:0;}
        .content-area{max-width:100%;box-sizing:border-box;}
        .form-card{width:100%;max-width:100%;}
        .form-grid,.form-grid.three{grid-template-columns:repeat(auto-fit,minmax(240px,1fr));}
        .actions{flex-wrap:wrap;}
        @media(max-width:900px){.form-grid,.form-grid.three{grid-template-columns:1fr}.top-header{padding:18px 22px}.content-area{padding:22px}}
        @media(max-width:768px){
            body{display:flex;}
            .sidebar{left:-260px;position:fixed;width:260px;height:100vh;}
            .main-panel,body.sidebar-collapsed .main-panel{margin-left:0;width:100%;}
            body.sidebar-open .sidebar{left:0;z-index:101;}
            body.sidebar-open::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:90;}
            .top-header{padding:15px 18px;gap:12px;}
            .header-title h1{font-size:18px;}
            .header-title p{display:none;}
            .profile-info{display:none;}
            .profile-trigger{padding:6px;background:transparent;border-color:transparent;min-width:0;}
            .dropdown-menu{right:0;width:220px;}
            .content-area{padding:18px;}
            .card-body{padding:18px;}
        }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="brand-box">
        <img src="assets/logo1.png" style="height:38px;width:auto;border-radius:8px;">
        <div style="line-height:1.1;">
            <span style="font-size:16px;font-weight:800;color:var(--primary);display:block;">JEJ Top Priority Corporation</span>
            <span style="font-size:11px;color:var(--text-muted);font-weight:500;">Management Portal</span>
        </div>
    </div>

    <div class="sidebar-menu">
        <small style="padding:0 15px;color:#90a4ae;font-weight:700;font-size:11px;display:block;margin-bottom:12px;letter-spacing:.5px;">MAIN MENU</small>

        <a href="admin.php" class="menu-link"><i class="fa-solid fa-chart-pie"></i> <span class="menu-text">Dashboard</span></a>
        <a href="reservation.php" class="menu-link"><i class="fa-solid fa-file-signature"></i> <span class="menu-text">Reservations</span></a>
        <a href="master_list.php" class="menu-link"><i class="fa-solid fa-map-location-dot"></i> <span class="menu-text">Master List / Map</span></a>
        <a href="admin.php?view=inventory" class="menu-link"><i class="fa-solid fa-plus-circle"></i> <span class="menu-text">Add Property</span></a>

        <div class="menu-dropdown">
            <div class="menu-link dropdown-toggle <?= $isFinancePage ? 'active' : '' ?>" onclick="window.location.href='financial.php'">
                <div class="finance-main-link">
                    <i class="fa-solid fa-coins"></i>
                    <span>Financials</span>
                </div>
                <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)" title="Show/Hide Financial Menu">
                    <i class="fa-solid <?= $isFinancePage ? 'fa-chevron-up' : 'fa-chevron-down' ?>" id="financeArrow"></i>
                </button>
            </div>

            <div id="financeSubMenu" class="submenu <?= $isFinancePage ? 'show' : '' ?>">
                <a href="verify_payments.php" class="menu-link submenu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>"><i class="fa-solid fa-circle-check"></i> <span class="menu-text">Verify Payments</span></a>
                <a href="payment_tracking.php" class="menu-link submenu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> <span class="menu-text">Payment Tracking</span></a>
                <a href="transaction_history.php" class="menu-link submenu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>"><i class="fa-solid fa-list-ul"></i> <span class="menu-text">Ledger List</span></a>
                <a href="daily_reconciliation.php" class="menu-link submenu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>"><i class="fa-solid fa-scale-balanced"></i> <span class="menu-text">Daily Reconciliation</span></a>
                <a href="aging_due_report.php" class="menu-link submenu-link <?= $currentPage == 'aging_due_report.php' ? 'active' : '' ?>"><i class="fa-solid fa-clock"></i> <span class="menu-text">Aging / Due Report</span></a>
                <a href="contract_status.php" class="menu-link submenu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-signature"></i> <span class="menu-text">Contract Status</span></a>
                <a href="manual_buyer_entry.php" class="menu-link submenu-link <?= $currentPage == 'manual_buyer_entry.php' ? 'active' : '' ?>"><i class="fa-solid fa-user-plus"></i> <span class="menu-text">Manual Buyer Entry</span></a>

                <a href="pricing_matrix.php"
                   class="menu-link submenu-link <?= $currentPage == 'pricing_matrix.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-table-list"></i>
                    <span class="menu-text">Pricing Matrix</span>
                </a>
            </div>
        </div>

        <small style="padding:0 15px;color:#90a4ae;font-weight:700;font-size:11px;display:block;margin-top:25px;margin-bottom:12px;letter-spacing:.5px;">MANAGEMENT</small>
        <a href="agent_tracking.php" class="menu-link <?= $currentPage == 'agent_tracking.php' ? 'active' : '' ?>"><i class="fa-solid fa-user-tie"></i> <span class="menu-text">Agent Tracking</span></a>
        <a href="inquiries.php" class="menu-link"><i class="fa-solid fa-briefcase"></i> <span class="menu-text">Inquiries</span></a>
        <a href="accounts.php" class="menu-link"><i class="fa-solid fa-users-gear"></i> <span class="menu-text">Accounts</span></a>
        <a href="delete_history.php" class="menu-link"><i class="fa-solid fa-trash-can"></i> <span class="menu-text">Delete History</span></a>

        <small style="padding:0 15px;color:#90a4ae;font-weight:700;font-size:11px;display:block;margin-top:25px;margin-bottom:12px;letter-spacing:.5px;">SYSTEM</small>
        <a href="index.php" class="menu-link" target="_blank"><i class="fa-solid fa-globe"></i> <span class="menu-text">View Website</span></a>
    </div>
</div>

<div class="main-panel">
    <div class="top-header">
        <div class="top-header-left">
            <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="header-title">
                <h1>Manual Buyer Entry</h1>
                <p>Add previous or legacy buyers for payment tracking, ledger, aging report, and agent performance.</p>
            </div>
        </div>
        <div class="header-right">
            <?php include 'includes/admin_notification_bell.php'; ?>

            <div class="profile-dropdown">
                <div class="profile-trigger">
                    <div class="profile-avatar">
                        <?= strtoupper(substr($_SESSION['fullname'] ?? 'A',0,1)); ?>
                    </div>

                    <div class="profile-info">
                        <strong><?= htmlspecialchars($_SESSION['fullname'] ?? 'Super Admin'); ?></strong>
                        <small><?= htmlspecialchars($_SESSION['role'] ?? 'ADMIN'); ?></small>
                    </div>

                    <i class="fa-solid fa-chevron-down"></i>
                </div>

                <div class="dropdown-menu">
                    <div class="dropdown-header">
                        <strong>JEJ Admin System</strong>
                        <span>Logged in successfully</span>
                    </div>

                    <a href="audit_logs.php" class="dropdown-item">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        System Audit Logs
                    </a>

                    <a href="account_settings.php" class="dropdown-item">
                        <i class="fa-solid fa-gear"></i>
                        Account Settings
                    </a>

                    <a href="logout.php" class="dropdown-item text-danger">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i>
                        Secure Logout
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="content-area">
        <?php if($alert_msg): ?>
            <div class="alert <?= $alert_type ?>">
                <i class="fa-solid <?= $alert_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                <?= htmlspecialchars($alert_msg) ?>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <div class="card-header">
                <h2><i class="fa-solid fa-user-plus"></i> Add Previous Buyer Account</h2>
            </div>

            <form method="POST" class="card-body">
                <div class="note-box">
                    <strong><i class="fa-solid fa-circle-info"></i> Note:</strong>
                    This page creates an approved buyer reservation manually. Any DP or amortization amount entered here will be saved as verified income in the ledger.
                </div>

                <h3 class="section-label"><i class="fa-solid fa-user"></i> Buyer Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Buyer Name</label>
                        <input type="text" name="fullname" class="form-control" placeholder="e.g., Juan Dela Cruz" required>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="buyer@email.com">
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="phone" class="form-control" placeholder="09XXXXXXXXX">
                    </div>
                    <div class="form-group">
                        <label>Agent Name</label>
                        <input type="text" name="agent_name" class="form-control" placeholder="e.g., Bianca">
                    </div>
                    <div class="form-group full-width">
                        <label>Buyer Address</label>
                        <input type="text" name="buyer_address" class="form-control" placeholder="Complete address">
                    </div>
                </div>

                <h3 class="section-label"><i class="fa-solid fa-map-location-dot"></i> Property / Lot Details</h3>
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Select Lot</label>
                        <select name="lot_id" class="form-control" required>
                            <option value="">-- Select Lot --</option>
                            <?php foreach($lots as $lot): ?>
                                <option value="<?= (int)$lot['id'] ?>">
                                    Block <?= htmlspecialchars($lot['block_no']) ?> Lot <?= htmlspecialchars($lot['lot_no']) ?>
                                    <?= !empty($lot['location']) ? ' - ' . htmlspecialchars($lot['location']) : '' ?>
                                    | ₱<?= number_format((float)$lot['total_price'], 2) ?>
                                    <?= !empty($lot['status']) ? ' | ' . htmlspecialchars($lot['status']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reservation / Start Date</label>
                        <input type="date" name="reservation_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Payment Type</label>
                        <select name="payment_type" class="form-control" required>
                            <option value="INSTALLMENT">Installment</option>
                            <option value="SPOT CASH">Spot Cash</option>
                        </select>
                    </div>
                </div>

                <h3 class="section-label"><i class="fa-solid fa-money-bill-wave"></i> Previous Payment / Terms</h3>
                <div class="form-grid three">
                    <div class="form-group">
                        <label>Required DP</label>
                        <input type="number" step="0.01" name="required_dp" class="form-control" placeholder="Auto 20% if blank">
                    </div>
                    <div class="form-group">
                        <label>DP Paid</label>
                        <input type="number" step="0.01" name="dp_paid" class="form-control" value="0">
                    </div>
                    <div class="form-group">
                        <label>Amortization Paid</label>
                        <input type="number" step="0.01" name="amortization_paid" class="form-control" value="0">
                    </div>
                    <div class="form-group">
                        <label>Installment Months</label>
                        <input type="number" name="installment_months" class="form-control" value="36">
                    </div>
                    <div class="form-group">
                        <label>Monthly Payment</label>
                        <input type="number" step="0.01" name="monthly_payment" class="form-control" placeholder="Auto compute if blank">
                    </div>
                    <div class="form-group">
                        <label>Remarks</label>
                        <input type="text" name="remarks" class="form-control" value="Manual previous buyer entry">
                    </div>
                </div>

                <div class="actions">
                    <a href="payment_tracking.php" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Back</a>
                    <button type="submit" name="save_manual_buyer" class="btn btn-success">
                        <i class="fa-solid fa-save"></i> Save Manual Buyer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleSidebar(){
    if (window.innerWidth <= 768) {
        document.body.classList.toggle('sidebar-open');
    } else {
        document.body.classList.toggle('sidebar-collapsed');
    }
}

document.addEventListener('click', function(e){
    if (window.innerWidth <= 768 && document.body.classList.contains('sidebar-open')) {
        const sidebar = document.querySelector('.sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        if (sidebar && toggle && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
            document.body.classList.remove('sidebar-open');
        }
    }
});

window.addEventListener('resize', function(){
    if (window.innerWidth > 768) {
        document.body.classList.remove('sidebar-open');
    }
});

function toggleFinanceMenu(event){
    event.preventDefault();
    event.stopPropagation();
    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');
    submenu.classList.toggle('show');
    if (arrow) {
        arrow.classList.toggle('fa-chevron-up', submenu.classList.contains('show'));
        arrow.classList.toggle('fa-chevron-down', !submenu.classList.contains('show'));
    }
}
</script>
</body>
</html>
                                
