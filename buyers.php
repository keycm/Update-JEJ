<?php
// buyers.php - Buyer / Customer Management

require_once 'config.php';
checkAdmin();

if ($_SESSION['role'] === 'CASHIER') {
    header("Location: financial.php");
    exit();
}

requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER']);

$currentPage = basename($_SERVER['PHP_SELF']);
$current_role = $_SESSION['role'] ?? '';

// Manager permission support
$sidebar_perms = [];
if (($current_role ?? '') === 'MANAGER') {
    $p_stmt = $conn->prepare("SELECT * FROM manager_permissions WHERE user_id = ?");
    if ($p_stmt) {
        $p_stmt->bind_param("i", $_SESSION['user_id']);
        $p_stmt->execute();
        $sidebar_perms = $p_stmt->get_result()->fetch_assoc() ?: [];
        $p_stmt->close();
    }
}

function canView($module) {
    global $sidebar_perms;
    if (($_SESSION['role'] ?? '') === 'SUPER ADMIN' || ($_SESSION['role'] ?? '') === 'ADMIN') return true;
    if (($_SESSION['role'] ?? '') === 'MANAGER') {
        if (empty($sidebar_perms)) return false;
        $prefix = explode('_', $module)[0];
        if (!empty($sidebar_perms[$prefix . '_full'])) return true;
        return !empty($sidebar_perms[$module]);
    }
    return false;
}

if (($current_role ?? '') === 'MANAGER' && !canView('usr_buyers')) {
    header("Location: admin.php?view=dashboard");
    exit();
}

function hasColumn($conn, $table, $column) {
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $check = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    return ($check && $check->num_rows > 0);
}

function money($value) {
    return '₱' . number_format((float)$value, 2);
}

function roleBadge($status) {
    $status = strtoupper(trim($status ?: 'NO RESERVATION'));
    $class = 'status-default';
    if (in_array($status, ['APPROVED', 'RESERVED'], true)) $class = 'status-approved';
    if (in_array($status, ['PENDING', 'FOR APPROVAL'], true)) $class = 'status-pending';
    if (in_array($status, ['SOLD', 'FULLY PAID'], true)) $class = 'status-sold';
    if (in_array($status, ['CANCELLED', 'REJECTED', 'DISAPPROVED'], true)) $class = 'status-cancelled';
    return '<span class="status-badge ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

function getBuyerCalc(array $row) {
    global $conn;
    if (empty($row['reservation_id'])) {
        return [
            'final_tcp' => 0.00,
            'payment_label' => 'No Reservation',
            'required_dp' => 0.00,
            'balance_to_finance' => 0.00,
            'is_spot_cash' => 0
        ];
    }
    $reservation_fee = isset($row['reservation_fee']) && (float)$row['reservation_fee'] > 0 ? (float)$row['reservation_fee'] : 5000;
    $months = isset($row['installment_months']) && (int)$row['installment_months'] > 0 ? (int)$row['installment_months'] : 36;
    $payment_option = $row['payment_scheme'] ?? $row['payment_option'] ?? $row['payment_terms'] ?? $row['payment_type'] ?? 'SPOT_CASH';
    return jej_compute_payment_pricing($conn, $row, $payment_option, $months, $reservation_fee);
}

function getBuyerTcpBase(array $row) {
    $calc = $row['_pricing_calc'] ?? getBuyerCalc($row);
    return (float)($calc['final_tcp'] ?? 0);
}

function getBuyerPricingLabel(array $row) {
    $calc = $row['_pricing_calc'] ?? getBuyerCalc($row);
    return (string)($calc['payment_label'] ?? 'Pricing Matrix');
}

$hasPaymentStatus = hasColumn($conn, 'transactions', 'payment_status');
$hasReservationId = hasColumn($conn, 'transactions', 'reservation_id');
$paymentStatusSql = $hasPaymentStatus ? "AND t.payment_status = 'VERIFIED'" : "";
$paidJoinSql = $hasReservationId ? "
    LEFT JOIN (
        SELECT reservation_id, SUM(amount) AS total_paid
        FROM transactions t
        WHERE t.type = 'INCOME' $paymentStatusSql
        AND (t.description IS NULL OR t.description NOT LIKE 'Reservation Fee%')
        GROUP BY reservation_id
    ) pay ON pay.reservation_id = r.id
" : "";
$paidSelectSql = $hasReservationId ? "COALESCE(pay.total_paid, 0) AS total_paid" : "0 AS total_paid";

// Make buyers.php compatible with older/newer reservations table schemas.
// Your current database has reservations.payment_type, but not payment_option/payment_terms.
$resPaymentTypeSelect = hasColumn($conn, 'reservations', 'payment_type')
    ? "r.payment_type"
    : "NULL AS payment_type";
$resPaymentOptionSelect = hasColumn($conn, 'reservations', 'payment_option')
    ? "r.payment_option"
    : "NULL AS payment_option";
$resPaymentTermsSelect = hasColumn($conn, 'reservations', 'payment_terms')
    ? "r.payment_terms"
    : "NULL AS payment_terms";
$resPaymentSchemeSelect = hasColumn($conn, 'reservations', 'payment_scheme')
    ? "r.payment_scheme"
    : "NULL AS payment_scheme";

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = ["u.role = 'BUYER'"];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(u.fullname LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR r.account_number LIKE ? OR l.block_no LIKE ? OR l.lot_no LIKE ?)";
    $like = '%' . $search . '%';
    for ($i = 0; $i < 6; $i++) { $params[] = $like; $types .= 's'; }
}

if ($statusFilter !== '') {
    if ($statusFilter === 'NO_RESERVATION') {
        $where[] = "r.id IS NULL";
    } else {
        $where[] = "r.status = ?";
        $params[] = $statusFilter;
        $types .= 's';
    }
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT
        u.id AS user_id,
        u.fullname,
        u.email,
        u.phone,
        u.created_at,
        r.id AS reservation_id,
        r.account_number,
        r.status AS reservation_status,
        r.reservation_date,
        r.dp_status,
        $resPaymentTypeSelect,
        $resPaymentOptionSelect,
        $resPaymentTermsSelect,
        $resPaymentSchemeSelect,
        r.required_dp,
        r.monthly_payment,
        r.tcp_after_discount,
        r.net_tcp_after_reservation,
        r.discount_amount,
        r.reservation_fee,
        l.id AS lot_id,
        l.block_no,
        l.lot_no,
        l.location,
        l.area,
        l.price_per_sqm,
        l.total_price,
        l.classification,
        $paidSelectSql
    FROM users u
    LEFT JOIN reservations r ON r.user_id = u.id
    LEFT JOIN lots l ON r.lot_id = l.id
    $paidJoinSql
    WHERE $whereSql
    ORDER BY u.fullname ASC, r.id DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Buyers query error: ' . $conn->error);
}
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$buyers = [];
$totalBuyers = 0;
$activeBuyers = 0;
$reservedBuyers = 0;
$fullyPaidBuyers = 0;

$seenBuyerIds = [];
while ($row = $result->fetch_assoc()) {
    $pricingCalc = getBuyerCalc($row);
    $row['_pricing_calc'] = $pricingCalc;
    $tcp = (float)($pricingCalc['final_tcp'] ?? 0);
    $paid = (float)($row['total_paid'] ?? 0);
    $balance = max($tcp - $paid, 0);
    $isFullyPaid = (!empty($row['reservation_id']) && $tcp > 0 && $balance <= 0.01);

    $row['computed_tcp'] = $tcp;
    $row['required_dp'] = (float)($pricingCalc['required_dp'] ?? ($row['required_dp'] ?? 0));
    $row['computed_balance'] = $balance;
    $row['is_fully_paid'] = $isFullyPaid;
    $buyers[] = $row;

    $uid = (int)$row['user_id'];
    if (!isset($seenBuyerIds[$uid])) {
        $seenBuyerIds[$uid] = true;
        $totalBuyers++;
        if (!empty($row['reservation_id'])) $activeBuyers++;
    }
    if (!empty($row['reservation_id']) && strtoupper((string)$row['reservation_status']) === 'APPROVED') $reservedBuyers++;
    if ($isFullyPaid) $fullyPaidBuyers++;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Buyers | JEJ Admin</title>
<link rel="icon" href="assets/favicon.png" type="image/x-icon">
<link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
<link rel="apple-touch-icon" href="assets/favicon.png">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:#2e7d32;--primary-light:#e8f5e9;--dark:#1b5e20;--gray-light:#f1f8e9;--gray-border:#c8e6c9;--text-muted:#607d8b;--shadow-sm:0 1px 2px rgba(46,125,50,.08);--shadow-md:0 4px 6px rgba(46,125,50,.10)}
*{box-sizing:border-box}body{background:#fafcf9;display:flex;min-height:100vh;overflow-x:hidden;font-family:'Inter',sans-serif;color:#37474f;margin:0}.sidebar{width:260px;background:#fff;border-right:1px solid var(--gray-border);display:flex;flex-direction:column;position:fixed;height:100vh;z-index:100;box-shadow:var(--shadow-sm);transition:.25s}.brand-box{padding:25px;border-bottom:1px solid var(--gray-border);display:flex;align-items:center;gap:12px}.sidebar-menu{padding:20px 15px;flex:1;overflow-y:auto}.menu-link{display:flex;align-items:center;gap:12px;padding:12px 18px;color:#455a64;text-decoration:none;font-weight:500;font-size:14px;border-radius:10px;margin-bottom:6px;transition:.2s}.menu-link:hover{background:var(--gray-light);color:var(--primary)}.menu-link.active{background:var(--primary-light);color:var(--primary);font-weight:700;border-left:4px solid var(--primary)}.menu-link i{width:20px;text-align:center;font-size:16px;opacity:.85}.menu-text{display:inline}.menu-dropdown{margin-bottom:6px}.dropdown-toggle{display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer}.finance-main-link{display:flex;align-items:center;gap:12px;flex:1;color:inherit;text-decoration:none}.submenu-toggle-btn{background:none;border:none;color:inherit;cursor:pointer;width:28px;height:28px;border-radius:6px}.submenu-toggle-btn:hover{background:#dff2e1}.submenu{display:none!important;padding-left:18px;margin-top:4px;margin-bottom:8px}.submenu.show{display:block!important}.main-panel{margin-left:260px;flex:1;width:calc(100% - 260px);display:flex;flex-direction:column;transition:.25s}.top-header{display:flex;justify-content:space-between;align-items:center;background:#fff;padding:20px 40px;border-bottom:1px solid var(--gray-border);box-shadow:var(--shadow-sm);z-index:50;gap:15px}.top-header-left{display:flex;align-items:center;gap:12px;min-width:0}.sidebar-toggle{width:42px;height:42px;border:none;border-radius:10px;background:var(--primary-light);color:var(--primary);font-size:18px;cursor:pointer;flex-shrink:0}.sidebar-toggle:hover{background:#c8e6c9}.header-title h1{font-size:22px;font-weight:800;color:var(--dark);margin:0 0 4px}.header-title p{color:var(--text-muted);font-size:13px;margin:0}.content-area{padding:35px 40px;flex:1;max-width:100%}.profile-trigger{display:flex;align-items:center;gap:12px;padding:6px 12px;border-radius:10px}.profile-avatar{width:40px;height:40px;background:var(--primary);color:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold}.profile-info strong{display:block;font-size:13px;color:var(--dark)}.profile-info small{font-size:11px;color:var(--text-muted)}.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:24px}.summary-card{background:white;border:1px solid var(--gray-border);border-radius:14px;padding:18px;display:flex;align-items:center;gap:14px;box-shadow:var(--shadow-sm)}.summary-card i{width:44px;height:44px;border-radius:12px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:18px}.summary-card small{display:block;font-size:12px;color:var(--text-muted);font-weight:800;text-transform:uppercase}.summary-card strong{font-size:22px;color:var(--dark)}.card{background:white;border:1px solid var(--gray-border);border-radius:16px;box-shadow:var(--shadow-sm);overflow:hidden}.card-header{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap;padding:20px 24px;border-bottom:1px solid var(--gray-border)}.card-header h2{margin:0;font-size:16px;font-weight:800;color:var(--dark)}.filters-group{display:flex;gap:12px;flex-wrap:wrap}.filter-control{height:42px;padding:0 14px;border-radius:9px;border:1px solid #cbd5e1;font-family:inherit;font-size:13px;background:white;color:#475569;outline:none}.filter-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}.btn-filter{height:42px;border:none;border-radius:9px;padding:0 16px;background:var(--primary);color:white;font-weight:800;cursor:pointer}.btn-reset{height:42px;border:1px solid #cbd5e1;border-radius:9px;padding:0 16px;background:#f8fafc;color:#475569;font-weight:800;text-decoration:none;display:inline-flex;align-items:center}.table-wrap{width:100%;overflow-x:auto}.modern-table{width:100%;min-width:980px;border-collapse:collapse;font-size:14px;table-layout:fixed}.modern-table th{text-align:left;padding:15px 18px;font-size:12px;font-weight:800;color:var(--text-muted);text-transform:uppercase;background:var(--gray-light);border-bottom:1px solid var(--gray-border)}.modern-table td{padding:16px 18px;border-bottom:1px solid var(--gray-border);vertical-align:top;overflow-wrap:anywhere}.modern-table tr:last-child td{border-bottom:none}.buyer-info{display:flex;align-items:flex-start;gap:12px}.avatar{width:42px;height:42px;min-width:42px;border-radius:50%;background:#10b981;color:white;font-weight:800;display:flex;align-items:center;justify-content:center}.muted{color:#64748b;font-size:12px}.status-badge{display:inline-flex;align-items:center;padding:6px 11px;border-radius:999px;font-size:10px;font-weight:900;text-transform:uppercase}.status-approved{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.status-pending{background:#fef3c7;color:#92400e;border:1px solid #fde68a}.status-sold{background:#dbeafe;color:#1d4ed8;border:1px solid #bfdbfe}.status-cancelled{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.status-default{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}.btn-action{padding:8px 11px;border-radius:8px;font-size:12px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;gap:6px;margin:2px}.btn-view{background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd}.btn-pay{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.btn-soa{background:#ede9fe;color:#6d28d9;border:1px solid #ddd6fe}.progress{width:100%;height:8px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:7px}.progress-bar{height:100%;background:var(--primary);border-radius:999px}body.sidebar-collapsed .sidebar{width:78px}body.sidebar-collapsed .main-panel{margin-left:78px;width:calc(100% - 78px)}body.sidebar-collapsed .brand-box{justify-content:center;padding:25px 10px}body.sidebar-collapsed .brand-box div,body.sidebar-collapsed .sidebar-menu small,body.sidebar-collapsed .menu-text,body.sidebar-collapsed .finance-main-link span,body.sidebar-collapsed .submenu,body.sidebar-collapsed .submenu-toggle-btn{display:none!important}body.sidebar-collapsed .menu-link{justify-content:center;padding:14px 10px;gap:0;border-left:0!important}body.sidebar-collapsed .menu-link i{width:22px;font-size:18px}body.sidebar-collapsed .finance-main-link{justify-content:center;gap:0}
@media(max-width:768px){.sidebar{left:-260px}.main-panel,body.sidebar-collapsed .main-panel{margin-left:0;width:100%}body.sidebar-open .sidebar{left:0;z-index:101}body.sidebar-open::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:90}.top-header{padding:15px 18px}.header-title h1{font-size:18px}.header-title p,.profile-info{display:none}.content-area{padding:18px}.filters-group{width:100%;display:grid;grid-template-columns:1fr}.table-wrap{overflow:visible}.modern-table,.modern-table thead,.modern-table tbody,.modern-table th,.modern-table td,.modern-table tr{display:block;width:100%;min-width:0}.modern-table thead{display:none}.modern-table tr{background:#fff;border:1px solid var(--gray-border);border-radius:14px;margin:0 0 14px;overflow:hidden}.modern-table td{border-bottom:1px solid #e2e8f0;padding:13px 15px}.modern-table td:last-child{border-bottom:0}.modern-table td::before{content:attr(data-label);display:block;font-size:11px;font-weight:900;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px}.buyer-info{align-items:center}}
</style>
</head>
<body>
<?php
$financePages = ['financial.php','payment_tracking.php','daily_reconciliation.php','verify_payments.php','transaction_history.php','aging_due_report.php','contract_status.php','manual_buyer_entry.php','pricing_matrix.php'];
$isFinancePage = in_array($currentPage, $financePages, true);
?>
<div class="sidebar">
    <div class="brand-box">
        <img src="assets/logo1.png" style="height:38px;width:auto;border-radius:8px;">
        <div style="line-height:1.1;"><span style="font-size:16px;font-weight:800;color:var(--primary);display:block;">JEJ Top Priority Corporation</span><span style="font-size:11px;color:var(--text-muted);font-weight:500;">Management Portal</span></div>
    </div>
    <div class="sidebar-menu">
        <small style="padding:0 15px;color:#90a4ae;font-weight:700;font-size:11px;display:block;margin-bottom:12px;letter-spacing:.5px;">MAIN MENU</small>
        <a href="admin.php?view=dashboard" class="menu-link"><i class="fa-solid fa-chart-pie"></i><span class="menu-text">Dashboard</span></a>
        <a href="reservation.php" class="menu-link"><i class="fa-solid fa-file-signature"></i><span class="menu-text">Reservations</span></a>
        <a href="master_list.php" class="menu-link"><i class="fa-solid fa-map-location-dot"></i><span class="menu-text">Master List / Map</span></a>
        <a href="admin.php?view=inventory" class="menu-link"><i class="fa-solid fa-plus-circle"></i><span class="menu-text">Add Property</span></a>

        <div class="menu-dropdown">
            <div class="menu-link dropdown-toggle <?= $isFinancePage ? 'active' : '' ?>">
                <a href="financial.php" class="finance-main-link"><i class="fa-solid fa-coins"></i><span>Financials</span></a>
                <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)" title="Show/Hide Financial Menu"><i class="fa-solid <?= $isFinancePage ? 'fa-chevron-up' : 'fa-chevron-down' ?>" id="financeArrow"></i></button>
            </div>
            <div id="financeSubMenu" class="submenu <?= $isFinancePage ? 'show' : '' ?>">
                <a href="verify_payments.php" class="menu-link submenu-link"><i class="fa-solid fa-circle-check"></i><span class="menu-text">Verify Payments</span></a>
                <a href="payment_tracking.php" class="menu-link submenu-link"><i class="fa-solid fa-file-invoice-dollar"></i><span class="menu-text">Payment Tracking</span></a>
                <a href="transaction_history.php" class="menu-link submenu-link"><i class="fa-solid fa-list-ul"></i><span class="menu-text">Ledger List</span></a>
                <a href="daily_reconciliation.php" class="menu-link submenu-link"><i class="fa-solid fa-scale-balanced"></i><span class="menu-text">Daily Reconciliation</span></a>
                <a href="aging_due_report.php" class="menu-link submenu-link"><i class="fa-solid fa-clock"></i><span class="menu-text">Aging / Due Report</span></a>
                <a href="contract_status.php" class="menu-link submenu-link"><i class="fa-solid fa-file-signature"></i><span class="menu-text">Contract Status</span></a>
                <a href="manual_buyer_entry.php" class="menu-link submenu-link"><i class="fa-solid fa-user-plus"></i><span class="menu-text">Manual Buyer Entry</span></a>

                <a href="pricing_matrix.php"
                   class="menu-link submenu-link <?= $currentPage == 'pricing_matrix.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-table-list"></i>
                    <span class="menu-text">Pricing Matrix</span>
                </a>
            </div>
        </div>

        <!-- CUSTOMERS -->
<small style="padding:0 15px;color:#90a4ae;font-weight:700;font-size:11px;display:block;margin-top:25px;margin-bottom:12px;letter-spacing:.5px;">
    CUSTOMERS
</small>

<a href="buyers.php" class="menu-link active">
    <i class="fa-solid fa-users"></i>
    <span class="menu-text">Buyers</span>
</a>

<!-- MANAGEMENT -->
<small style="padding:0 15px;color:#90a4ae;font-weight:700;font-size:11px;display:block;margin-top:25px;margin-bottom:12px;letter-spacing:.5px;">
    MANAGEMENT
</small>

<a href="agent_tracking.php" class="menu-link">
    <i class="fa-solid fa-user-tie"></i>
    <span class="menu-text">Agent Tracking</span>
</a>

<a href="inquiries.php" class="menu-link">
    <i class="fa-solid fa-envelope-open-text"></i>
    <span class="menu-text">Inquiries</span>
</a>

<a href="accounts.php" class="menu-link">
    <i class="fa-solid fa-users-gear"></i>
    <span class="menu-text">Accounts</span>
</a>

<a href="delete_history.php" class="menu-link">
    <i class="fa-solid fa-trash-can"></i>
    <span class="menu-text">Delete History</span>
</a>

        <small style="padding:0 15px;color:#90a4ae;font-weight:700;font-size:11px;display:block;margin-top:25px;margin-bottom:12px;letter-spacing:.5px;">SYSTEM</small>
        <a href="index.php" class="menu-link" target="_blank"><i class="fa-solid fa-globe"></i><span class="menu-text">View Website</span></a>
    </div>
</div>

<div class="main-panel">
    <div class="top-header">
        <div class="top-header-left">
            <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar"><i class="fa-solid fa-bars"></i></button>
            <div class="header-title"><h1>Buyers</h1><p>Buyer accounts with reservation, property, and payment status.</p></div>
        </div>
        <div class="profile-trigger"><div class="profile-avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['fullname'] ?? 'A', 0, 1))) ?></div><div class="profile-info"><strong><?= htmlspecialchars($_SESSION['fullname'] ?? 'Administrator') ?></strong><small><?= htmlspecialchars($_SESSION['role'] ?? 'System Admin') ?></small></div></div>
    </div>

    <div class="content-area">
        <div class="summary-grid">
            <div class="summary-card"><i class="fa-solid fa-users"></i><div><small>Total Buyers</small><strong><?= number_format($totalBuyers) ?></strong></div></div>
            <div class="summary-card"><i class="fa-solid fa-user-check"></i><div><small>Active Buyers</small><strong><?= number_format($activeBuyers) ?></strong></div></div>
            <div class="summary-card"><i class="fa-solid fa-map-pin"></i><div><small>Reserved Accounts</small><strong><?= number_format($reservedBuyers) ?></strong></div></div>
            <div class="summary-card"><i class="fa-solid fa-circle-check"></i><div><small>Fully Paid</small><strong><?= number_format($fullyPaidBuyers) ?></strong></div></div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2><i class="fa-solid fa-address-book" style="color:var(--primary);margin-right:8px;"></i> Buyer List</h2>
                <form method="GET" class="filters-group">
                    <input type="text" name="search" class="filter-control" placeholder="Search buyer, email, phone, block/lot..." value="<?= htmlspecialchars($search) ?>">
                    <select name="status" class="filter-control" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="APPROVED" <?= $statusFilter === 'APPROVED' ? 'selected' : '' ?>>Approved / Reserved</option>
                        <option value="PENDING" <?= $statusFilter === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                        <option value="SOLD" <?= $statusFilter === 'SOLD' ? 'selected' : '' ?>>Sold</option>
                        <option value="CANCELLED" <?= $statusFilter === 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
                        <option value="NO_RESERVATION" <?= $statusFilter === 'NO_RESERVATION' ? 'selected' : '' ?>>No Reservation</option>
                    </select>
                    <button class="btn-filter" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                    <a href="buyers.php" class="btn-reset"><i class="fa-solid fa-rotate-left"></i>&nbsp; Reset</a>
                </form>
            </div>
            <div class="table-wrap">
                <table class="modern-table">
                    <thead><tr><th style="width:28%;">Buyer Information</th><th style="width:22%;">Reservation / Property</th><th style="width:22%;">Financial Status</th><th style="width:13%;">Status</th><th style="width:15%;">Actions</th></tr></thead>
                    <tbody>
                    <?php if(!empty($buyers)): ?>
                        <?php foreach($buyers as $row):
                            $tcp = (float)($row['computed_tcp'] ?? getBuyerTcpBase($row));
                            $paid = (float)($row['total_paid'] ?? 0);
                            $balance = (float)($row['computed_balance'] ?? max($tcp - $paid, 0));
                            $percent = $tcp > 0 ? min(100, max(0, ($paid / $tcp) * 100)) : 0;
                            $statusLabel = !empty($row['is_fully_paid']) ? 'FULLY PAID' : ($row['reservation_status'] ?: 'NO RESERVATION');
                        ?>
                        <tr>
                            <td data-label="Buyer Information">
                                <div class="buyer-info"><div class="avatar"><?= htmlspecialchars(strtoupper(substr($row['fullname'] ?? 'B', 0, 1))) ?></div><div><strong style="color:#1e293b;"><?= htmlspecialchars($row['fullname'] ?? 'Unnamed Buyer') ?></strong><br><span class="muted"><i class="fa-regular fa-envelope"></i> <?= htmlspecialchars($row['email'] ?? '') ?></span><br><span class="muted"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($row['phone'] ?? 'No phone') ?></span></div></div>
                            </td>
                            <td data-label="Reservation / Property">
                                <?php if(!empty($row['reservation_id'])): ?>
                                    <strong style="color:var(--primary);">Block <?= htmlspecialchars($row['block_no'] ?? '-') ?> Lot <?= htmlspecialchars($row['lot_no'] ?? '-') ?></strong><br>
                                    <span class="muted"><?= htmlspecialchars($row['location'] ?? '') ?></span><br>
                                    <?php if(!empty($row['account_number'])): ?><span class="muted"><i class="fa-solid fa-hashtag"></i> <?= htmlspecialchars($row['account_number']) ?></span><br><?php endif; ?>
                                    <span class="muted">Reserved: <?= !empty($row['reservation_date']) ? date('M d, Y', strtotime($row['reservation_date'])) : '—' ?></span>
                                <?php else: ?>
                                    <span class="muted">No reservation/property yet</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Financial Status">
                                <div class="muted">Option: <strong style="color:#0f766e;"><?= htmlspecialchars(getBuyerPricingLabel($row)) ?></strong></div>
                                <div class="muted">Final TCP: <strong style="color:#0f172a;"><?= money($tcp) ?></strong></div>
                                <?php if(!empty($row['required_dp'])): ?><div class="muted">Required DP: <strong style="color:#0f172a;"><?= money($row['required_dp']) ?></strong></div><?php endif; ?>
                                <div class="muted">Paid: <strong style="color:#166534;"><?= money($paid) ?></strong></div>
                                <div class="muted">Balance: <strong style="color:<?= $balance <= 0.01 ? '#166534' : '#b91c1c' ?>;"><?= money($balance) ?></strong></div>
                                <div class="progress"><div class="progress-bar" style="width:<?= $percent ?>%;"></div></div>
                            </td>
                            <td data-label="Status"><?= roleBadge($statusLabel) ?></td>
                            <td data-label="Actions">
                                <?php if(!empty($row['reservation_id'])): ?>
                                    <a class="btn-action btn-view" href="reservation.php"><i class="fa-solid fa-eye"></i> View</a>
                                    <a class="btn-action btn-pay" href="payment_tracking.php"><i class="fa-solid fa-cash-register"></i> Payments</a>
                                    <a class="btn-action btn-soa" href="statement_of_account.php?res_id=<?= (int)$row['reservation_id'] ?>"><i class="fa-solid fa-file-invoice"></i> SOA</a>
                                <?php else: ?>
                                    <a class="btn-action btn-view" href="manual_buyer_entry.php"><i class="fa-solid fa-plus"></i> Add Reservation</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center;padding:45px;color:#94a3b8;"><i class="fa-solid fa-users-slash" style="font-size:30px;display:block;margin-bottom:10px;color:#cbd5e1;"></i>No buyer accounts found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar(){
    if(window.innerWidth <= 768){
        document.body.classList.toggle('sidebar-open');
    }else{
        document.body.classList.toggle('sidebar-collapsed');
    }
}
function toggleFinanceMenu(event){
    event.preventDefault();
    event.stopPropagation();
    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');
    if(!submenu) return;
    submenu.classList.toggle('show');
    if(arrow){
        arrow.classList.toggle('fa-chevron-up', submenu.classList.contains('show'));
        arrow.classList.toggle('fa-chevron-down', !submenu.classList.contains('show'));
    }
}
document.addEventListener('click', function(e){
    if(window.innerWidth <= 768 && document.body.classList.contains('sidebar-open')){
        const sidebar = document.querySelector('.sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        if(sidebar && !sidebar.contains(e.target) && toggle && !toggle.contains(e.target)){
            document.body.classList.remove('sidebar-open');
        }
    }
});
</script>
</body>
</html>
