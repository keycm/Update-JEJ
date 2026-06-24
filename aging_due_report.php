<?php
// aging_due_report.php

require_once 'config.php';

// Role Access
requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER', 'CASHIER']);

// Manager Permission Access
if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'fin_process');
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
    'manual_buyer_entry.php',
    'pricing_matrix.php'
];
$isFinancePage = in_array($currentPage, $financePages);

// Check optional payment_status column
$has_payment_status_col = false;
$colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'payment_status'");
if ($colCheck && $colCheck->num_rows > 0) {
    $has_payment_status_col = true;
}

$today = new DateTime(date('Y-m-d'));
$aging_rows = [];
$overdue_count = 0;
$due_soon_count = 0;
$current_count = 0;
$total_due = 0;

$query = "
    SELECT 
        r.*,
        u.fullname,
        u.email AS user_email,
        u.phone,
        l.block_no,
        l.lot_no,
        l.total_price
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    JOIN lots l ON r.lot_id = l.id
    WHERE r.status = 'APPROVED'
    ORDER BY r.reservation_date DESC
";

$res = $conn->query($query);

if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {

        $res_id = (int)$row['id'];
        $tcp = (float)$row['total_price'];
        $required_dp = (!empty($row['required_dp']) && (float)$row['required_dp'] > 0)
            ? (float)$row['required_dp']
            : round($tcp * 0.20, 2);

        $term_months = (!empty($row['installment_months']) && (int)$row['installment_months'] > 0)
            ? (int)$row['installment_months']
            : 36;

        $monthly_payment = (!empty($row['monthly_payment']) && (float)$row['monthly_payment'] > 0)
            ? (float)$row['monthly_payment']
            : (($tcp - $required_dp) / max($term_months, 1));

        $statusFilter = $has_payment_status_col
            ? "AND (payment_status = 'VERIFIED' OR payment_status IS NULL)"
            : "";

        // Verified DP paid
        $desc_dp = "%Down Payment%Res#{$res_id}%";
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total_paid
            FROM transactions
            WHERE type = 'INCOME'
            $statusFilter
            AND description LIKE ?
        ");
        $stmt->bind_param("s", $desc_dp);
        $stmt->execute();
        $dp_paid = (float)($stmt->get_result()->fetch_assoc()['total_paid'] ?? 0);
        $stmt->close();

        // Verified amortization paid
        $desc_amort = "%Amortization%Res#{$res_id}%";
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total_paid
            FROM transactions
            WHERE type = 'INCOME'
            $statusFilter
            AND description LIKE ?
        ");
        $stmt->bind_param("s", $desc_amort);
        $stmt->execute();
        $amort_paid = (float)($stmt->get_result()->fetch_assoc()['total_paid'] ?? 0);
        $stmt->close();

        $dp_balance = max($required_dp - $dp_paid, 0);
        $is_dp_paid = ($dp_balance <= 0);

        // DP not yet paid: include as DP due, not monthly amortization
        if (!$is_dp_paid) {
            $base_date = !empty($row['reservation_date']) ? $row['reservation_date'] : date('Y-m-d');
            $due_date = (new DateTime(date('Y-m-d', strtotime($base_date))))->modify('+20 days');
            $amount_due = $dp_balance;
            $report_type = 'Down Payment';
            $paid_months = 0;
            $unpaid_months = 0;
        } else {
            // Monthly amortization begins after DP deadline / approval date
            $base_date = !empty($row['reservation_date']) ? $row['reservation_date'] : date('Y-m-d');
            $start_date = (new DateTime(date('Y-m-d', strtotime($base_date))))->modify('+1 month');

            $paid_months = ($monthly_payment > 0) ? (int)floor($amort_paid / $monthly_payment) : 0;
            $paid_months = min($paid_months, $term_months);

            $due_date = clone $start_date;
            if ($paid_months > 0) {
                $due_date->modify("+{$paid_months} months");
            }

            $unpaid_months = max($term_months - $paid_months, 0);
            $amount_due = ($unpaid_months > 0) ? $monthly_payment : 0;
            $report_type = 'Monthly Amortization';

            if ($unpaid_months <= 0) {
                continue; // fully paid amortization
            }
        }

        $diff = (int)$today->diff($due_date)->format('%r%a');

        if ($diff < 0) {
            $aging_status = 'OVERDUE';
            $aging_class = 'badge-overdue';
            $overdue_count++;
        } elseif ($diff <= 7) {
            $aging_status = 'DUE SOON';
            $aging_class = 'badge-due-soon';
            $due_soon_count++;
        } else {
            $aging_status = 'CURRENT';
            $aging_class = 'badge-current';
            $current_count++;
        }

        $total_due += $amount_due;

        $row['report_type'] = $report_type;
        $row['required_dp'] = $required_dp;
        $row['dp_paid'] = $dp_paid;
        $row['dp_balance'] = $dp_balance;
        $row['monthly_payment_calc'] = $monthly_payment;
        $row['amort_paid'] = $amort_paid;
        $row['paid_months'] = $paid_months;
        $row['unpaid_months'] = $unpaid_months;
        $row['due_date'] = $due_date->format('Y-m-d');
        $row['days_due'] = $diff;
        $row['amount_due'] = $amount_due;
        $row['aging_status'] = $aging_status;
        $row['aging_class'] = $aging_class;

        $aging_rows[] = $row;
    }
}

// Sort most urgent first
usort($aging_rows, function ($a, $b) {
    return $a['days_due'] <=> $b['days_due'];
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aging / Due Report | JEJ Financials</title>
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
            --shadow-lg: 0 10px 25px rgba(46,125,50,.16);
        }

        * { box-sizing: border-box; }

        body {
            background-color: #fafcf9;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
            font-family: 'Inter', sans-serif;
            color: #37474f;
            margin: 0;
        }

        .sidebar {
            width: 260px;
            background: #ffffff;
            border-right: 1px solid var(--gray-border);
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 100;
            box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08);
        }

        .brand-box {
            padding: 25px;
            border-bottom: 1px solid var(--gray-border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-menu {
            padding: 20px 15px;
            flex: 1;
            overflow-y: auto;
        }

        .menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            color: #455a64;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            border-radius: 10px;
            margin-bottom: 6px;
            transition: all 0.2s ease;
        }

        .menu-link:hover {
            background: var(--gray-light);
            color: var(--primary);
        }

        .menu-link.active {
            background: var(--primary-light);
            color: var(--primary);
            font-weight: 700;
            border-left: 4px solid var(--primary);
        }

        .menu-link i {
            width: 20px;
            text-align: center;
            font-size: 16px;
            opacity: 0.8;
        }

        .menu-dropdown { margin-bottom: 6px; }

        .dropdown-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            cursor: pointer;
        }

        .finance-main-link {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            color: inherit;
            text-decoration: none;
        }

        .submenu-toggle-btn {
            border: none;
            background: none;
            cursor: pointer;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            color: var(--primary);
        }

        .submenu-toggle-btn:hover { background: #dff2e1; }

        .submenu {
            display: none !important;
            padding-left: 18px;
            margin-top: 6px;
        }

        .submenu.show { display: block !important; }

        .submenu-link {
            font-size: 13px;
            margin-bottom: 6px;
        }

        .submenu-link.active {
            background: var(--primary-light);
            color: var(--primary);
            font-weight: 700;
            border-left: 4px solid var(--primary);
        }

        .main-panel {
            margin-left: 260px;
            flex: 1;
            width: calc(100% - 260px);
            display: flex;
            flex-direction: column;
        }

        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            padding: 20px 40px;
            border-bottom: 1px solid var(--gray-border);
            box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08);
        }

        .header-title h1 {
            font-size: 22px;
            font-weight: 800;
            color: var(--dark);
            margin: 0 0 4px 0;
        }

        .header-title p {
            color: var(--text-muted);
            font-size: 13px;
            margin: 0;
        }

        .content-area {
            padding: 35px 40px;
            flex: 1;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            border: 1px solid var(--gray-border);
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08);
            border-top: 4px solid var(--primary);
        }

        .summary-card span {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .summary-card strong {
            font-size: 26px;
            color: #0f172a;
        }

        .summary-card.overdue { border-top-color: #ef4444; }
        .summary-card.due-soon { border-top-color: #f59e0b; }
        .summary-card.current { border-top-color: #22c55e; }
        .summary-card.amount { border-top-color: #3b82f6; }

        .table-container {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--gray-border);
            box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 16px 18px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            background: var(--gray-light);
            border-bottom: 1px solid var(--gray-border);
        }

        td {
            padding: 16px 18px;
            border-bottom: 1px solid var(--gray-border);
            color: #37474f;
            font-size: 13px;
            vertical-align: top;
        }

        tr:last-child td { border-bottom: none; }

        .badge {
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            display: inline-block;
        }

        .badge-overdue {
            background: #fee2e2;
            color: #b91c1c;
        }

        .badge-due-soon {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-current {
            background: #dcfce7;
            color: #166534;
        }

        .btn-action {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            justify-content: center;
        }

        .btn-billing {
            background: #3b82f6;
            color: white;
        }

        .btn-pay {
            background: #10b981;
            color: white;
        }

        .filters {
            background: white;
            border: 1px solid var(--gray-border);
            border-radius: 14px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .filters select,
        .filters input {
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: inherit;
        }

        @media(max-width: 1100px) {
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media(max-width: 768px) {
            .sidebar { position: relative; width: 100%; height: auto; }
            body { flex-direction: column; }
            .main-panel { margin-left: 0; width: 100%; }
            .summary-grid { grid-template-columns: 1fr; }
            .content-area { padding: 20px; }
        }
    

        /* WORKING AUTO-FIT + COLLAPSIBLE SIDEBAR FIX */
        .sidebar,
        .main-panel { transition: all .25s ease; }

        .top-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .sidebar-toggle {
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 10px;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 18px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .sidebar-toggle:hover { background: #c8e6c9; }

        body.sidebar-collapsed .sidebar { width: 78px; }
        body.sidebar-collapsed .main-panel {
            margin-left: 78px;
            width: calc(100% - 78px);
        }
        body.sidebar-collapsed .brand-box {
            justify-content: center;
            padding: 25px 10px;
        }
        body.sidebar-collapsed .brand-box div,
        body.sidebar-collapsed .sidebar-menu small,
        body.sidebar-collapsed .finance-main-link span,
        body.sidebar-collapsed .submenu,
        body.sidebar-collapsed .submenu-toggle-btn {
            display: none !important;
        }
        body.sidebar-collapsed .menu-link {
            justify-content: center !important;
            padding: 14px 10px !important;
            gap: 0 !important;
            border-left: 0 !important;
            font-size: 0 !important;
        }
        body.sidebar-collapsed .menu-link i {
            width: 22px;
            font-size: 18px !important;
        }
        body.sidebar-collapsed .finance-main-link {
            justify-content: center;
            gap: 0;
        }

        /* TABLE / PAGE AUTO-FIT */
        .content-area,
        .table-container,
        .filters,
        .summary-grid { max-width: 100%; box-sizing: border-box; }
        .table-container { width: 100%; overflow-x: auto; }
        #agingTable {
            width: 100%;
            min-width: 980px;
            table-layout: fixed;
        }
        #agingTable th,
        #agingTable td {
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .filters { flex-wrap: wrap; }
        .filters input { min-width: 220px; }
        .summary-grid { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)) !important; }

        @media(max-width: 768px) {
            body { display: flex !important; flex-direction: row !important; }
            .sidebar {
                position: fixed !important;
                width: 260px !important;
                height: 100vh !important;
                left: -260px;
                top: 0;
                z-index: 1000;
            }
            body.sidebar-open .sidebar { left: 0; }
            .main-panel,
            body.sidebar-collapsed .main-panel {
                margin-left: 0 !important;
                width: 100% !important;
            }
            .top-header {
                padding: 15px 18px !important;
                gap: 12px;
            }
            .header-title h1 { font-size: 18px; }
            .header-title p { display: none; }
            .content-area { padding: 18px !important; }
            .filters { display: grid; grid-template-columns: 1fr; }
            .filters input,
            .filters select { width: 100%; min-width: 0; }

            .table-container {
                background: transparent;
                border: 0;
                box-shadow: none;
                overflow: visible;
            }
            #agingTable,
            #agingTable thead,
            #agingTable tbody,
            #agingTable th,
            #agingTable td,
            #agingTable tr {
                display: block;
                width: 100%;
                min-width: 0;
                box-sizing: border-box;
            }
            #agingTable thead { display: none; }
            #agingTable tbody tr {
                background: #fff;
                border: 1px solid var(--gray-border);
                border-radius: 16px;
                margin-bottom: 16px;
                overflow: hidden;
                box-shadow: 0 1px 2px rgba(46,125,50,.08);
            }
            #agingTable tbody tr td {
                border-bottom: 1px solid #e2e8f0;
                padding: 14px 16px;
            }
            #agingTable tbody tr td:last-child { border-bottom: 0; }
            #agingTable tbody tr td::before {
                display: block;
                font-size: 11px;
                font-weight: 800;
                color: var(--text-muted);
                text-transform: uppercase;
                margin-bottom: 7px;
                letter-spacing: .4px;
            }
            #agingTable tbody tr td:nth-child(1)::before { content: "Buyer / Property"; }
            #agingTable tbody tr td:nth-child(2)::before { content: "Due Type"; }
            #agingTable tbody tr td:nth-child(3)::before { content: "Due Date"; }
            #agingTable tbody tr td:nth-child(4)::before { content: "Days"; }
            #agingTable tbody tr td:nth-child(5)::before { content: "Amount Due"; }
            #agingTable tbody tr td:nth-child(6)::before { content: "Payment Status"; }
            #agingTable tbody tr td:nth-child(7)::before { content: "Actions"; }
        }


        /* CONSISTENT PROFILE DROPDOWN - copied from verify_payments.php */
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

        @media (max-width:768px){
            .header-right{ margin-left:auto !important; }
            .profile-info{ display:none !important; }
            .profile-trigger{ min-height:44px !important; padding:4px 8px !important; }
            .profile-avatar{ width:38px !important; height:38px !important; min-width:38px !important; }
            .dropdown-menu{ right:0 !important; top:105% !important; }
        }

    </style>
</head>
<body>

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
                <i class="fa-solid fa-chart-pie"></i> Dashboard
            </a>

            <a href="reservation.php" class="menu-link">
                <i class="fa-solid fa-file-signature"></i> Reservations
            </a>

            <a href="master_list.php" class="menu-link">
                <i class="fa-solid fa-map-location-dot"></i> Master List / Map
            </a>

            <a href="admin.php?view=inventory" class="menu-link">
                <i class="fa-solid fa-plus-circle"></i> Add Property
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

                <a href="contract_status.php"
                    class="menu-link submenu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-file-signature"></i>
                        Contract Status
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

        <?php if($_SESSION['role'] !== 'CASHIER'): ?>
            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px;">SYSTEM</small>

            <a href="index.php" class="menu-link" target="_blank">
                <i class="fa-solid fa-globe"></i> View Website
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
                <h1>Aging / Due Report</h1>
                <p>Show unpaid monthly amortizations, overdue accounts, and due-soon buyers.</p>
            </div>
        </div>

        <div class="header-right">
            <?php include 'includes/admin_notification_bell.php'; ?>

            <div class="profile-dropdown">
                <div class="profile-trigger">
                    <div class="profile-avatar">
                        <?= strtoupper(substr($_SESSION['fullname'] ?? 'A', 0, 1)); ?>
                    </div>

                    <div class="profile-info">
                        <strong><?= htmlspecialchars($_SESSION['fullname'] ?? 'Super Admin'); ?></strong>
                        <small><?= htmlspecialchars($_SESSION['role'] ?? 'ADMIN'); ?></small>
                    </div>

                    <i class="fa-solid fa-chevron-down" style="font-size:12px;color:var(--text-muted);"></i>
                </div>

                <div class="dropdown-menu">
                    <div class="dropdown-header">
                        <strong>JEJ Admin System</strong>
                        <small>Logged in successfully</small>
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

        <div class="summary-grid">
            <div class="summary-card overdue">
                <span>Overdue Accounts</span>
                <strong><?= number_format($overdue_count) ?></strong>
            </div>

            <div class="summary-card due-soon">
                <span>Due Soon</span>
                <strong><?= number_format($due_soon_count) ?></strong>
            </div>

            <div class="summary-card current">
                <span>Current / Upcoming</span>
                <strong><?= number_format($current_count) ?></strong>
            </div>

            <div class="summary-card amount">
                <span>Total Due Amount</span>
                <strong>₱<?= number_format($total_due, 2) ?></strong>
            </div>
        </div>

        <div class="filters">
            <strong style="color:#1b5e20;">Report View:</strong>
            <select id="statusFilter" onchange="filterReport()">
                <option value="ALL">All Accounts</option>
                <option value="OVERDUE">Overdue Only</option>
                <option value="DUE SOON">Due Soon Only</option>
                <option value="CURRENT">Current Only</option>
            </select>

            <input type="text" id="searchBox" placeholder="Search buyer, property, email..." onkeyup="filterReport()" style="flex:1;">
        </div>

        <div class="table-container">
            <table id="agingTable">
                <thead>
                    <tr>
                        <th>Buyer / Property</th>
                        <th>Due Type</th>
                        <th>Due Date</th>
                        <th>Days</th>
                        <th>Amount Due</th>
                        <th>Payment Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if(!empty($aging_rows)): ?>
                        <?php foreach($aging_rows as $row): ?>
                            <tr data-status="<?= htmlspecialchars($row['aging_status']) ?>">
                                <td>
                                    <strong style="color:#0f172a;"><?= htmlspecialchars($row['fullname']) ?></strong><br>
                                    <span style="color:#64748b; font-size:12px;">
                                        <i class="fa-regular fa-envelope"></i>
                                        <?= htmlspecialchars($row['user_email'] ?? '') ?>
                                    </span><br>
                                    <span style="color:#2e7d32; font-size:12px; font-weight:700;">
                                        Block <?= htmlspecialchars($row['block_no']) ?> Lot <?= htmlspecialchars($row['lot_no']) ?>
                                    </span>
                                </td>

                                <td>
                                    <strong><?= htmlspecialchars($row['report_type']) ?></strong><br>
                                    <?php if($row['report_type'] === 'Monthly Amortization'): ?>
                                        <span style="font-size:12px; color:#64748b;">
                                            Paid Months: <?= (int)$row['paid_months'] ?> /
                                            <?= (int)$row['installment_months'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:#64748b;">
                                            DP Paid: ₱<?= number_format($row['dp_paid'], 2) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= date('M d, Y', strtotime($row['due_date'])) ?>
                                </td>

                                <td>
                                    <?php if($row['days_due'] < 0): ?>
                                        <strong style="color:#b91c1c;"><?= abs((int)$row['days_due']) ?> days overdue</strong>
                                    <?php elseif($row['days_due'] == 0): ?>
                                        <strong style="color:#92400e;">Due today</strong>
                                    <?php else: ?>
                                        <strong style="color:#166534;"><?= (int)$row['days_due'] ?> days left</strong>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <strong style="color:#0f172a;">₱<?= number_format($row['amount_due'], 2) ?></strong>
                                </td>

                                <td>
                                    <span class="badge <?= $row['aging_class'] ?>">
                                        <?= htmlspecialchars($row['aging_status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                        <a class="btn-action btn-pay" href="payment_tracking.php">
                                            <i class="fa-solid fa-cash-register"></i> Pay
                                        </a>

                                        <a class="btn-action btn-billing" href="statement_of_account.php?res_id=<?= (int)$row['id'] ?>">
                                            <i class="fa-solid fa-file-invoice"></i> SOA
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:40px; color:#94a3b8;">
                                No unpaid or due accounts found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<script>
function toggleSidebar(){
    if(window.innerWidth <= 768){
        document.body.classList.toggle('sidebar-open');
    } else {
        document.body.classList.toggle('sidebar-collapsed');
    }
}

function toggleFinanceMenu(event){
    event.preventDefault();
    event.stopPropagation();

    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');

    submenu.classList.toggle('show');
    arrow.classList.toggle('fa-chevron-down');
    arrow.classList.toggle('fa-chevron-up');
}

function filterReport(){
    const status = document.getElementById('statusFilter').value.toLowerCase();
    const search = document.getElementById('searchBox').value.toLowerCase();
    const rows = document.querySelectorAll('#agingTable tbody tr[data-status]');

    rows.forEach(row => {
        const rowStatus = row.dataset.status.toLowerCase();
        const text = row.innerText.toLowerCase();

        const statusOk = status === 'all' || rowStatus === status;
        const searchOk = text.includes(search);

        row.style.display = (statusOk && searchOk) ? '' : 'none';
    });
}
</script>

</body>
</html>
