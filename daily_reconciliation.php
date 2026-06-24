<?php
// daily_reconciliation.php

include 'config.php';
checkAdmin();

// ==========================================
// SECURITY
// ==========================================

requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER', 'CASHIER']);

if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'fin_review');
}

// ==========================================
// DATE FILTER
// ==========================================

$selected_date = $_GET['date'] ?? date('Y-m-d');

// ==========================================
// ENCODED PAYMENTS
// ==========================================

$encoded_total = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount),0) AS total
    FROM transactions
    WHERE type='INCOME'
    AND DATE(transaction_date)=?
");

$stmt->bind_param("s", $selected_date);
$stmt->execute();

$res = $stmt->get_result()->fetch_assoc();

$encoded_total = (float)$res['total'];


// ==========================================
// VERIFIED PAYMENTS
// ==========================================

$verified_total = 0;

$has_payment_status = false;

$check = $conn->query("SHOW COLUMNS FROM transactions LIKE 'payment_status'");

if ($check && $check->num_rows > 0) {
    $has_payment_status = true;
}

if ($has_payment_status) {

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount),0) AS total
        FROM transactions
        WHERE type='INCOME'
        AND payment_status='VERIFIED'
        AND DATE(transaction_date)=?
    ");

    $stmt->bind_param("s", $selected_date);
    $stmt->execute();

    $res = $stmt->get_result()->fetch_assoc();

    $verified_total = (float)$res['total'];
}


// ==========================================
// SAVE RECONCILIATION
// ==========================================

$alert_msg = "";
$alert_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $cash  = (float)($_POST['actual_cash'] ?? 0);
    $check_amt = (float)($_POST['actual_check'] ?? 0);
    $bank  = (float)($_POST['actual_bank'] ?? 0);
    $gcash = (float)($_POST['actual_gcash'] ?? 0);

    $remarks = trim($_POST['remarks'] ?? '');

    $total_actual = $cash + $check_amt + $bank + $gcash;

    $shortage_overage = $total_actual - $verified_total;

    $stmt = $conn->prepare("
        INSERT INTO cash_reconciliation
        (
            recon_date,
            encoded_total,
            verified_total,
            actual_cash,
            actual_check,
            actual_bank,
            actual_gcash,
            total_actual,
            shortage_overage,
            remarks,
            reconciled_by
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $uid = $_SESSION['user_id'];

    $stmt->bind_param(
        "sddddddddsi",
        $selected_date,
        $encoded_total,
        $verified_total,
        $cash,
        $check_amt,
        $bank,
        $gcash,
        $total_actual,
        $shortage_overage,
        $remarks,
        $uid
    );

    if ($stmt->execute()) {

        logActivity(
            $conn,
            $_SESSION['user_id'],
            'Daily Cash Reconciliation',
            'Reconciliation completed for ' . $selected_date
        );

        $alert_msg = "Daily reconciliation saved successfully!";
        $alert_type = "success";

    } else {

        $alert_msg = "Failed to save reconciliation.";
        $alert_type = "error";
    }
}


// ==========================================
// LOAD LATEST SAVED RECONCILIATION FOR DISPLAY
// ==========================================

$existing_recon = null;
$recon_stmt = $conn->prepare("
    SELECT cr.*, u.fullname AS reconciled_by_name
    FROM cash_reconciliation cr
    LEFT JOIN users u ON cr.reconciled_by = u.id
    WHERE cr.recon_date = ?
    ORDER BY cr.id DESC
    LIMIT 1
");

if ($recon_stmt) {
    $recon_stmt->bind_param("s", $selected_date);
    $recon_stmt->execute();
    $existing_recon = $recon_stmt->get_result()->fetch_assoc();
    $recon_stmt->close();
}

$actual_cash  = (float)($existing_recon['actual_cash'] ?? 0);
$actual_check = (float)($existing_recon['actual_check'] ?? 0);
$actual_bank  = (float)($existing_recon['actual_bank'] ?? 0);
$actual_gcash = (float)($existing_recon['actual_gcash'] ?? 0);
$total_actual = (float)($existing_recon['total_actual'] ?? 0);
$shortage_overage = (float)($existing_recon['shortage_overage'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actual_cash  = (float)($_POST['actual_cash'] ?? 0);
    $actual_check = (float)($_POST['actual_check'] ?? 0);
    $actual_bank  = (float)($_POST['actual_bank'] ?? 0);
    $actual_gcash = (float)($_POST['actual_gcash'] ?? 0);
    $total_actual = $actual_cash + $actual_check + $actual_bank + $actual_gcash;
    $shortage_overage = $total_actual - $verified_total;
}

$balance_status = 'Not Yet Reconciled';
$balance_class = 'orange';

if ($total_actual > 0 || $shortage_overage != 0) {
    if (abs($shortage_overage) < 0.01) {
        $balance_status = 'Balanced';
        $balance_class = 'green';
    } elseif ($shortage_overage < 0) {
        $balance_status = 'Shortage';
        $balance_class = 'red';
    } else {
        $balance_status = 'Overage';
        $balance_class = 'blue';
    }
}

function peso_recon($amount) {
    return '₱' . number_format((float)$amount, 2);
}

function safe_recon($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Daily Cash Reconciliation | JEJ Financials</title>
<link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
:root { --primary:#2e7d32; --primary-light:#e8f5e9; --dark:#1b5e20; --gray-light:#f1f8e9; --gray-border:#c8e6c9; --text-muted:#607d8b; --shadow-sm:0 1px 2px rgba(46,125,50,.08); --shadow-md:0 4px 6px rgba(46,125,50,.10); }
*{box-sizing:border-box} body{background:#fafcf9;display:flex;min-height:100vh;overflow-x:hidden;font-family:'Inter',sans-serif;color:#37474f;margin:0}.sidebar{width:260px;background:#fff;border-right:1px solid var(--gray-border);display:flex;flex-direction:column;position:fixed;height:100vh;z-index:100;box-shadow:var(--shadow-sm)}.brand-box{padding:25px;border-bottom:1px solid var(--gray-border);display:flex;align-items:center;gap:12px}.sidebar-menu{padding:20px 15px;flex:1;overflow-y:auto}.menu-link{display:flex;align-items:center;gap:12px;padding:12px 18px;color:#455a64;text-decoration:none;font-weight:500;font-size:14px;border-radius:10px;margin-bottom:6px;transition:.2s}.menu-link:hover{background:var(--gray-light);color:var(--primary)}
    .menu-link.active{
        background:var(--primary-light);color:var(--primary);
    font-weight:600;border-left:4px solid var(--primary)}.menu-link i{width:20px;text-align:center;font-size:16px;opacity:.8}.main-panel{margin-left:260px;flex:1;width:calc(100% - 260px);display:flex;flex-direction:column}.top-header{display:flex;justify-content:space-between;align-items:center;background:#fff;padding:20px 40px;border-bottom:1px solid var(--gray-border);box-shadow:var(--shadow-sm);z-index:50}.header-title h1{font-size:22px;font-weight:800;color:var(--dark);margin:0 0 4px}.header-title p{color:var(--text-muted);font-size:13px;margin:0}.profile-box{display:flex;align-items:center;gap:12px}.profile-avatar{width:44px;height:44px;background:var(--primary);color:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;box-shadow:var(--shadow-md)}.profile-info strong{display:block;color:var(--dark);font-size:13px}.profile-info small{color:var(--text-muted);font-size:11px}.content-area{padding:35px 40px;flex:1}.page-card{background:white;border:1px solid var(--gray-border);border-radius:16px;box-shadow:var(--shadow-sm);padding:24px;margin-bottom:24px}.alert{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-weight:600}.alert-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.form-group label{display:block;margin-bottom:7px;font-size:13px;font-weight:700;color:#455a64}.form-control{width:100%;padding:11px 14px;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;font-size:14px;outline:none;transition:.2s}.form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}.card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;margin-bottom:24px}.metric-card{background:white;border-radius:14px;padding:22px;border:1px solid var(--gray-border);box-shadow:var(--shadow-sm);position:relative;overflow:hidden}.metric-card h4{margin:0 0 10px;color:#64748b;font-size:12px;font-weight:800;text-transform:uppercase}.metric-card .value{font-size:28px;font-weight:800}.green{color:#16a34a}.red{color:#dc2626}.blue{color:#2563eb}.orange{color:#ea580c}.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px}.btn{background:var(--primary);color:white;border:none;padding:12px 22px;border-radius:8px;margin-top:20px;cursor:pointer;font-weight:700;font-size:14px}.btn:hover{background:var(--dark)}.status-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;font-weight:800;font-size:13px;background:#f1f5f9}.summary-note{margin-top:16px;padding:14px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;color:#475569;font-size:13px;line-height:1.5}


/* WORKING AUTO-FIT + COLLAPSIBLE SIDEBAR */
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
    background:var(--primary-light);
    color:var(--primary);
    font-size:18px;
    cursor:pointer;
    flex-shrink:0;
}
.sidebar-toggle:hover{ background:var(--gray-border); }

.sidebar .menu-text{ display:inline; }

body.sidebar-collapsed .sidebar{
    width:78px;
}
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
body.sidebar-collapsed .menu-group > a .fa-chevron-down,
body.sidebar-collapsed .menu-group > div{
    display:none !important;
}
body.sidebar-collapsed .menu-link{
    justify-content:center !important;
    padding:14px 10px !important;
    gap:0 !important;
    border-left:0 !important;
}
body.sidebar-collapsed .menu-link i{
    width:22px;
    font-size:18px;
}
body.sidebar-collapsed .menu-group > a > div{
    justify-content:center;
    gap:0 !important;
}

/* DAILY RECON AUTO-FIT */
.content-area{
    max-width:100%;
    box-sizing:border-box;
}
.page-card,
.metric-card,
.card-grid,
.form-grid{
    max-width:100%;
    box-sizing:border-box;
}
.card-grid{
    grid-template-columns:repeat(auto-fit,minmax(210px,1fr));
}
.form-grid{
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
}
.profile-box{
    flex-shrink:0;
}

@media(max-width:900px){
    .profile-info{ display:none; }
}

@media(max-width:768px){
    body{ display:flex; }
    .sidebar{
        left:-260px;
        position:fixed;
        width:260px;
        height:100vh;
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
    .header-title h1{ font-size:18px; }
    .header-title p{ display:none; }
    .content-area{ padding:18px; }
    .page-card{ padding:18px; }
}


/* CONSISTENT PROFILE DROPDOWN - same compact style as reservation.php */
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
    box-shadow:0 2px 6px rgba(46,125,50,.25) !important;
}

.profile-info{
    display:block !important;
    min-width:0 !important;
}

.profile-info strong{
    display:block !important;
    font-size:13px !important;
    font-weight:800 !important;
    color:var(--dark) !important;
    line-height:1.2 !important;
    margin:0 !important;
    white-space:nowrap !important;
}

.profile-info small{
    display:block !important;
    font-size:11px !important;
    font-weight:500 !important;
    color:var(--text-muted) !important;
    line-height:1.2 !important;
    margin-top:2px !important;
    white-space:nowrap !important;
}

.dropdown-menu{
    display:none !important;
    position:absolute !important;
    right:0 !important;
    left:auto !important;
    top:110% !important;
    width:220px !important;
    min-width:220px !important;
    max-width:220px !important;
    background:#fff !important;
    border:1px solid var(--gray-border) !important;
    border-radius:12px !important;
    box-shadow:var(--shadow-lg) !important;
    overflow:hidden !important;
    z-index:9999 !important;
    padding:0 !important;
    margin:0 !important;
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
    font-weight:800 !important;
    color:var(--dark) !important;
    line-height:1.2 !important;
    margin:0 0 3px 0 !important;
}

.dropdown-header span,
.dropdown-header small{
    display:block !important;
    font-size:11px !important;
    color:var(--text-muted) !important;
    line-height:1.2 !important;
}

.dropdown-item{
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    width:100% !important;
    padding:12px 16px !important;
    color:#455a64 !important;
    text-decoration:none !important;
    font-size:13px !important;
    font-weight:500 !important;
    border-left:3px solid transparent !important;
    box-sizing:border-box !important;
    white-space:nowrap !important;
}

.dropdown-item i{
    width:16px !important;
    text-align:center !important;
    flex:0 0 16px !important;
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

/* keep profile compact on mobile */
@media (max-width:768px){
    .header-right{
        margin-left:auto !important;
    }

    .profile-info{
        display:none !important;
    }

    .profile-trigger{
        min-height:44px !important;
        padding:4px 8px !important;
    }

    .profile-avatar{
        width:38px !important;
        height:38px !important;
        min-width:38px !important;
    }

    .dropdown-menu{
        right:0 !important;
        top:105% !important;
    }
}


/* FINAL MOBILE POLISH: Daily Reconciliation */
@media (max-width:768px){
    html,
    body{
        width:100% !important;
        max-width:100% !important;
        overflow-x:hidden !important;
    }

    body{
        background:#f7fbf6 !important;
    }

    .main-panel,
    body.sidebar-collapsed .main-panel{
        margin-left:0 !important;
        width:100% !important;
        max-width:100% !important;
    }

    .top-header{
        width:100% !important;
        padding:18px 16px !important;
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:18px !important;
        align-items:stretch !important;
        min-height:0 !important;
    }

    .top-header-left{
        width:100% !important;
        display:grid !important;
        grid-template-columns:54px minmax(0,1fr) !important;
        align-items:center !important;
        gap:14px !important;
    }

    .sidebar-toggle{
        width:54px !important;
        height:54px !important;
        border-radius:15px !important;
        font-size:20px !important;
    }

    .header-title h1{
        font-size:clamp(25px,8vw,38px) !important;
        line-height:1.08 !important;
        margin:0 !important;
        color:var(--dark) !important;
        white-space:normal !important;
    }

    .header-title p{
        display:none !important;
    }

    .header-right{
        width:100% !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        margin:0 !important;
    }

    .jej-admin-header-tools{
        width:100% !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        gap:18px !important;
        flex-wrap:nowrap !important;
    }

    .jej-admin-header-tools .jej-notification-button{
        width:58px !important;
        height:58px !important;
        border-radius:999px !important;
        font-size:18px !important;
    }

    .profile-trigger{
        min-height:58px !important;
        padding:6px 12px !important;
        border-radius:999px !important;
        background:#fff !important;
        border:1px solid var(--gray-border) !important;
    }

    .profile-avatar{
        width:50px !important;
        height:50px !important;
        min-width:50px !important;
        font-size:18px !important;
    }

    .profile-info{
        display:none !important;
    }

    .content-area{
        width:100% !important;
        padding:18px 16px !important;
    }

    .page-card{
        width:100% !important;
        padding:20px !important;
        border-radius:20px !important;
        margin-bottom:22px !important;
    }

    .page-card .form-group{
        margin:0 !important;
    }

    .form-group label{
        font-size:16px !important;
        font-weight:900 !important;
        color:#1f2937 !important;
        margin-bottom:12px !important;
    }

    .form-control{
        width:100% !important;
        min-height:52px !important;
        border-radius:14px !important;
        font-size:16px !important;
        text-align:center !important;
    }

    .card-grid{
        grid-template-columns:1fr !important;
        gap:20px !important;
        margin-bottom:22px !important;
    }

    .metric-card{
        width:100% !important;
        padding:24px 20px !important;
        border-radius:20px !important;
        min-height:132px !important;
        display:flex !important;
        flex-direction:column !important;
        justify-content:center !important;
    }

    .metric-card h4{
        font-size:14px !important;
        line-height:1.2 !important;
        margin-bottom:14px !important;
    }

    .metric-card .value{
        font-size:clamp(28px,8.4vw,38px) !important;
        line-height:1.05 !important;
        white-space:nowrap !important;
        word-break:normal !important;
        overflow-wrap:normal !important;
    }

    .form-grid{
        grid-template-columns:1fr !important;
        gap:16px !important;
    }

    .summary-note{
        font-size:13px !important;
        border-radius:14px !important;
    }

    .btn{
        width:100% !important;
        min-height:56px !important;
        border-radius:16px !important;
        justify-content:center !important;
        font-size:15px !important;
    }
}

@media (min-width:520px) and (max-width:768px){
    .card-grid{
        grid-template-columns:1fr 1fr !important;
    }
}

</style>
</head>
<body>
<div class="sidebar">
    <div class="brand-box"><img src="assets/logo1.png" style="height:38px;width:auto;border-radius:8px;"><div style="line-height:1.1;"><span style="font-size:16px;font-weight:800;color:var(--primary);display:block;">JEJ Top Priority Corporation</span><span style="font-size:11px;color:var(--text-muted);font-weight:500;">Management Portal</span></div></div>
    <div class="sidebar-menu">

<?php
$current_page = basename($_SERVER['PHP_SELF']);
$currentPage = $current_page;

$financial_pages = [
    'financial.php',
    'transaction_history.php',
    'payment_tracking.php',
    'daily_reconciliation.php',
    'verify_payments.php',
    'aging_due_report.php',
    'contract_status.php',
    'manual_buyer_entry.php',
    'pricing_matrix.php'
];

$is_financial_open = in_array($current_page, $financial_pages);
?>

<!-- DASHBOARD -->
  <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-bottom: 12px;">MAIN MENU</small>
<a href="admin.php"
   class="menu-link <?= $current_page == 'admin.php' ? 'active' : '' ?>">
    <i class="fa-solid fa-chart-pie"></i>
    <span class="menu-text">Dashboard</span>
</a>

<!-- RESERVATIONS -->
<a href="reservation.php"
   class="menu-link <?= $current_page == 'reservation.php' ? 'active' : '' ?>">
    <i class="fa-solid fa-file-signature"></i>
    <span class="menu-text">Reservations</span>
</a>

<!-- MASTER LIST -->
<a href="master_list.php"
   class="menu-link <?= $current_page == 'master_list.php' ? 'active' : '' ?>">
    <i class="fa-solid fa-map-location-dot"></i>
    <span class="menu-text">Master List / Map</span>
</a>

<!-- ADD PROPERTY -->
<a href="admin.php?view=inventory"
   class="menu-link <?= (isset($_GET['view']) && $_GET['view'] == 'inventory') ? 'active' : '' ?>">
    <i class="fa-solid fa-plus-circle"></i>
    <span class="menu-text">Add Property</span>
</a>

<!-- FINANCIAL MENU -->
<div class="menu-group">

    <a href="financial.php"
       class="menu-link <?= $is_financial_open ? 'active' : '' ?>"
       style="justify-content: space-between;">

        <div style="display:flex; align-items:center; gap:12px;">
            <i class="fa-solid fa-coins"></i>
            <span class="menu-text">Financials</span>
        </div>

        <i class="fa-solid fa-chevron-down"
           style="font-size:12px; transition:.2s;"></i>
    </a>

    <div style="margin-left:18px; margin-top:8px;">

        <!-- VERIFY -->
        <a href="verify_payments.php"
           class="menu-link <?= $current_page == 'verify_payments.php' ? 'active' : '' ?>"
           style="padding:10px 14px;">
            <i class="fa-solid fa-circle-check"></i>
            <span class="menu-text">Verify Payments</span>
        </a>

        <!-- PAYMENT TRACKING -->
        <a href="payment_tracking.php"
           class="menu-link <?= $current_page == 'payment_tracking.php' ? 'active' : '' ?>"
           style="padding:10px 14px;">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            <span class="menu-text">Payment Tracking</span>
        </a>

        <!-- LEDGER -->
        <a href="transaction_history.php"
           class="menu-link <?= $current_page == 'transaction_history.php' ? 'active' : '' ?>"
           style="padding:10px 14px;">
            <i class="fa-solid fa-list-ul"></i>
            <span class="menu-text">Ledger List</span>
        </a>

        <!-- DAILY RECON -->
        <a href="daily_reconciliation.php"
           class="menu-link <?= $current_page == 'daily_reconciliation.php' ? 'active' : '' ?>"
           style="padding:10px 14px;">
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
           class="menu-link submenu-link <?= $currentPage == 'pricing_matrix.php' ? 'active' : '' ?>"
           style="padding:10px 14px;">
            <i class="fa-solid fa-table-list"></i>
            <span class="menu-text">Pricing Matrix</span>
        </a>

    </div>
</div>

<small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px;">SYSTEM</small>

            <a href="index.php" class="menu-link" target="_blank">
                <i class="fa-solid fa-globe"></i> <span class="menu-text">View Website</span>
            </a>

</div>

</div>
</div>
<div class="main-panel">
    <div class="top-header">
        <div class="top-header-left">
            <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="header-title">
                <h1>Daily Cash Reconciliation</h1>
                <p>Compare encoded payments, verified payments, and actual cash/check/bank/GCash received.</p>
            </div>
        </div>

        <div class="header-right">
            <?php include 'includes/profile_dropdown.php'; ?>
        </div>
    </div>
    <div class="content-area">
        <?php if($alert_msg != ""): ?><div class="alert alert-<?= safe_recon($alert_type) ?>"><i class="fa-solid <?= $alert_type === 'success' ? 'fa-check-circle' : 'fa-triangle-exclamation' ?>"></i> <?= safe_recon($alert_msg) ?></div><?php endif; ?>
        <div class="page-card"><form method="GET"><div class="form-group"><label>Select Date</label><input type="date" name="date" value="<?= safe_recon($selected_date) ?>" class="form-control" onchange="this.form.submit()"></div></form></div>
        <div class="card-grid"><div class="metric-card"><h4>Encoded Payments</h4><div class="value blue"><?= peso_recon($encoded_total) ?></div></div><div class="metric-card"><h4>Verified Payments</h4><div class="value green"><?= peso_recon($verified_total) ?></div></div><div class="metric-card"><h4>Actual Received</h4><div class="value orange"><?= peso_recon($total_actual) ?></div></div><div class="metric-card"><h4>Shortage / Overage</h4><div class="value <?= safe_recon($balance_class) ?>"><?= peso_recon($shortage_overage) ?></div><div class="status-pill <?= safe_recon($balance_class) ?>" style="margin-top:12px;"><i class="fa-solid fa-scale-balanced"></i> <?= safe_recon($balance_status) ?></div></div></div>
        <div class="page-card"><h2 style="margin:0 0 20px;color:#0f172a;font-size:18px;"><i class="fa-solid fa-cash-register" style="color:var(--primary);"></i> Actual Collection Entry</h2><form method="POST"><div class="form-grid"><div class="form-group"><label>Actual Cash</label><input type="number" step="0.01" name="actual_cash" value="<?= safe_recon($actual_cash) ?>" class="form-control" required></div><div class="form-group"><label>Actual Check</label><input type="number" step="0.01" name="actual_check" value="<?= safe_recon($actual_check) ?>" class="form-control" required></div><div class="form-group"><label>Actual Bank Deposit</label><input type="number" step="0.01" name="actual_bank" value="<?= safe_recon($actual_bank) ?>" class="form-control" required></div><div class="form-group"><label>Actual GCash</label><input type="number" step="0.01" name="actual_gcash" value="<?= safe_recon($actual_gcash) ?>" class="form-control" required></div></div><div class="form-group" style="margin-top:20px;"><label>Remarks / Explanation</label><textarea name="remarks" rows="4" class="form-control"><?= safe_recon($existing_recon['remarks'] ?? '') ?></textarea></div><div class="summary-note"><strong>Formula:</strong> Total Actual = Cash + Check + Bank Deposit + GCash<br>Shortage / Overage = Total Actual - Verified Payments</div><button type="submit" class="btn"><i class="fa-solid fa-floppy-disk"></i> Save Reconciliation</button></form></div>
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

document.addEventListener('click', function(e){
    if(window.innerWidth <= 768 && document.body.classList.contains('sidebar-open')){
        const sidebar = document.querySelector('.sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        if(sidebar && !sidebar.contains(e.target) && toggle && !toggle.contains(e.target)){
            document.body.classList.remove('sidebar-open');
        }
    }
});

window.addEventListener('resize', function(){
    if(window.innerWidth > 768){
        document.body.classList.remove('sidebar-open');
    }
});
</script>

</body>
</html>
