<?php
// transaction_history.php

require_once 'config.php';
checkAdmin();

// Role Access
requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER', 'CASHIER', 'BUYER','CASHIER']);

if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'fin_review');
}

// --- FILTERING LOGIC ---
$where_clauses = ["1=1"];
$params = [];
$types = "";

// 1. Filter by Type (Income, Expense, Check Voucher)
$filter_type = $_GET['type'] ?? 'ALL';
if ($filter_type == 'INCOME') {
    $where_clauses[] = "t.type = 'INCOME'";
} elseif ($filter_type == 'EXPENSE') {
    $where_clauses[] = "t.type = 'EXPENSE' AND (t.is_check = 0 OR t.is_check IS NULL)";
} elseif ($filter_type == 'CHECK') {
    $where_clauses[] = "t.is_check = 1";
}


// 2. Filter by Payment Status
$filter_status = $_GET['payment_status'] ?? 'ALL';
if ($filter_status !== 'ALL') {
    $where_clauses[] = "t.payment_status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

// 2. Filter by Date Range
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

if (!empty($start_date)) {
    $where_clauses[] = "t.transaction_date >= ?";
    $params[] = $start_date;
    $types .= "s";
}
if (!empty($end_date)) {
    $where_clauses[] = "t.transaction_date <= ?";
    $params[] = $end_date;
    $types .= "s";
}

// 3. Search Query (Description, OR Number, Payee)
$search = $_GET['search'] ?? '';
if (!empty($search)) {
    $search_param = "%" . $search . "%";
    $where_clauses[] = "(t.description LIKE ? OR t.or_number LIKE ? OR t.payee LIKE ?)";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$where_sql = implode(" AND ", $where_clauses);

// ==========================================
// BUYER ACCESS PROTECTION
// ==========================================

if ($_SESSION['role'] === 'BUYER') {

    $where_clauses[] = "
        EXISTS (
            SELECT 1
            FROM reservations r
            WHERE r.id = t.reservation_id
            AND r.user_id = ?
        )
    ";

    $params[] = $_SESSION['user_id'];
    $types .= "i";
}

$where_sql = implode(" AND ", $where_clauses);


// ==========================================
// MAIN QUERY
// ==========================================

$query = "
    SELECT 
        t.*, 
        c.name as category_name
    FROM transactions t
    LEFT JOIN accounting_categories c 
        ON t.category_id = c.id
    WHERE $where_sql
    ORDER BY t.transaction_date DESC, t.id DESC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Calculate totals for the current view
$total_income_view = 0;
$total_expense_view = 0;
$net_cash_view = 0;
$total_transactions_view = 0;
$check_vouchers_view = 0;
$pending_count_view = 0;
$transactions = [];
$running_balances = [];

while($row = $result->fetch_assoc()){
    $transactions[] = $row;
    $total_transactions_view++;

    $pay_status = strtoupper(trim($row['payment_status'] ?? 'VERIFIED'));
    if($pay_status === '') $pay_status = 'VERIFIED';

    if($pay_status === 'PENDING' || $pay_status === 'UNPAID') {
        $pending_count_view++;
    }

    if((int)($row['is_check'] ?? 0) === 1) {
        $check_vouchers_view++;
    }

    if($row['type'] == 'INCOME') {
        if($pay_status === 'VERIFIED' || $pay_status === 'RECORDED'){
            $total_income_view += (float)$row['amount'];
        }
    } else {
        $total_expense_view += (float)$row['amount'];
    }
}

$net_cash_view = $total_income_view - $total_expense_view;

// Running balance based on the filtered results, calculated oldest to newest.
$running_total = 0;
$transactions_for_balance = array_reverse($transactions);
foreach($transactions_for_balance as $rb){
    $amount = (float)($rb['amount'] ?? 0);
    $running_total += ($rb['type'] === 'INCOME') ? $amount : -$amount;
    $running_balances[(int)$rb['id']] = $running_total;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History | JEJ Financials</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        :root {
            /* NATURE GREEN THEME (Primary Structure) */
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

        /* Sidebar Styling */
        .sidebar { width: 260px; background: #ffffff; border-right: 1px solid var(--gray-border); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; box-shadow: var(--shadow-sm); }
        .brand-box { padding: 25px; border-bottom: 1px solid var(--gray-border); display: flex; align-items: center; gap: 12px; }
        .sidebar-menu { padding: 20px 15px; flex: 1; overflow-y: auto; }
        .menu-link { display: flex; align-items: center; gap: 12px; padding: 12px 18px; color: #455a64; text-decoration: none; font-weight: 500; font-size: 14px; border-radius: 10px; margin-bottom: 6px; transition: all 0.2s ease; }
        .menu-link:hover { background: var(--gray-light); color: var(--primary); }
        .menu-link.active { background: var(--primary-light); color: var(--primary); font-weight: 600; border-left: 4px solid var(--primary); }
        .menu-link i { width: 20px; text-align: center; font-size: 16px; opacity: 0.8; }
        
        /* Main Panel & Header */
        .main-panel { margin-left: 260px; flex: 1; padding: 0; width: calc(100% - 260px); display: flex; flex-direction: column; }
        
        .top-header { display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 20px 40px; border-bottom: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); z-index: 50; }
        .header-title h1 { font-size: 22px; font-weight: 800; color: var(--dark); margin: 0 0 4px 0; letter-spacing: -0.5px;}
        .header-title p { color: var(--text-muted); font-size: 13px; margin: 0; }

        /* Profile Dropdown */
        .profile-dropdown { position: relative; cursor: pointer; }
        .profile-trigger { display: flex; align-items: center; gap: 12px; padding: 6px 12px; border-radius: 10px; transition: background 0.2s; border: 1px solid transparent; }
        .profile-trigger:hover { background: var(--gray-light); border-color: var(--gray-border); }
        .profile-avatar { width: 40px; height: 40px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 16px; box-shadow: 0 2px 4px rgba(46, 125, 50, 0.2);}
        .profile-info strong { display: block; font-size: 13px; color: var(--dark); line-height: 1.2; }
        .profile-info small { font-size: 11px; color: var(--text-muted); font-weight: 500; }
        
        .dropdown-menu { display: none; position: absolute; right: 0; top: 110%; background: white; border-radius: 12px; box-shadow: var(--shadow-lg); border: 1px solid var(--gray-border); min-width: 200px; z-index: 1000; overflow: hidden; transform-origin: top right; animation: dropAnim 0.2s ease-out forwards; }
        @keyframes dropAnim { 0% { opacity: 0; transform: scale(0.95) translateY(-10px); } 100% { opacity: 1; transform: scale(1) translateY(0); } }
        .profile-dropdown:hover .dropdown-menu { display: block; }
        .dropdown-header { padding: 15px; border-bottom: 1px solid var(--gray-border); background: var(--gray-light); }
        .dropdown-item { padding: 12px 16px; display: flex; align-items: center; gap: 12px; color: #455a64; text-decoration: none; font-size: 13px; font-weight: 500; transition: background 0.2s; border-left: 3px solid transparent;}
        .dropdown-item:hover { background: var(--primary-light); color: var(--primary); border-left-color: var(--primary); }
        .dropdown-item.text-danger { color: #d84315; }
        .dropdown-item.text-danger:hover { background: #fbe9e7; color: #bf360c; border-left-color: #d84315; }

        .content-area { padding: 35px 40px; flex: 1; }

        /* Toolbar / Filters */
        .toolbar { background: white; padding: 20px; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); margin-bottom: 25px; display: flex; flex-wrap: wrap; gap: 15px; align-items: center; justify-content: space-between;}
        .filter-group { display: flex; gap: 10px; align-items: center; flex-wrap: wrap;}
        .form-control { padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 13px; outline: none; transition: 0.2s; color: #475569;}
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.15); }
        
        .btn-filter { background: var(--primary); color: white; border: none; padding: 9px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px;}
        .btn-filter:hover { background: var(--dark); box-shadow: 0 4px 6px rgba(46, 125, 50, 0.2); }
        .btn-reset { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 9px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; transition: 0.2s;}
        .btn-reset:hover { background: #e2e8f0; }

        /* Summary Cards */
        .summary-wrapper { display: flex; gap: 20px; margin-bottom: 25px; }
        .summary-card { flex: 1; background: white; padding: 15px 20px; border-radius: 12px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 15px;}
        .summary-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .si-inc { background: #d1fae5; color: #059669; }
        .si-exp { background: #fee2e2; color: #dc2626; }
        .si-net { background: #dbeafe; color: #2563eb; }
        .si-count { background: #ede9fe; color: #7c3aed; }
        .si-pending { background: #fff7ed; color: #c2410c; }
        .si-voucher { background: #f3e8ff; color: #7c3aed; }

        .ledger-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-left:auto;}
        .btn-export-ledger,.btn-print-ledger{
            background:#2563eb;
            color:#fff;
            border:none;
            padding:9px 14px;
            border-radius:8px;
            font-weight:700;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:7px;
            font-size:13px;
            cursor:pointer;
            transition:.2s;
        }
        .btn-print-ledger{background:#475569;}
        .btn-export-ledger:hover,.btn-print-ledger:hover{transform:translateY(-1px);filter:brightness(.95);}

        .amount-balance{
            color:#2563eb;
            font-weight:900;
            white-space:nowrap;
        }
        .row-actions{
            display:flex;
            align-items:center;
            gap:6px;
            flex-wrap:wrap;
        }
        .btn-row{
            padding:7px 10px;
            border-radius:8px;
            font-size:11px;
            font-weight:800;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:5px;
            border:1px solid #cbd5e1;
            background:#f8fafc;
            color:#334155;
        }
        .btn-row.print{background:#e0f2fe;color:#0369a1;border-color:#bae6fd;}
        .btn-row.voucher{background:#ede9fe;color:#6d28d9;border-color:#ddd6fe;}
        .btn-row:hover{filter:brightness(.97);}

        .b-recorded { background:#e0f2fe; color:#0369a1; }
        .b-unpaid { background:#fff7ed; color:#c2410c; }
        .b-paid { background:#dcfce7; color:#166534; }
        .b-cancelled { background:#e2e8f0; color:#475569; }
        
        /* Table Styling */
        .table-container { background: white; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; background: var(--gray-light); border-bottom: 1px solid var(--gray-border); letter-spacing: 0.5px;}
        td { padding: 16px 24px; border-bottom: 1px solid var(--gray-border); color: #37474f; font-size: 13px; vertical-align: middle; }
        tr:hover td { background: #fdfdfd; }
        tr:last-child td { border-bottom: none; }

        /* Badges */
        .badge { padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; letter-spacing: 0.3px; display: inline-block;}
        .b-inc { background: #d1fae5; color: #059669; } /* Emerald */
        .b-exp { background: #fef3c7; color: #d97706; } /* Amber */
        .b-chk { background: #ede9fe; color: #7c3aed; } /* Violet */
        .b-pending { background:#fff7ed; color:#c2410c; }
        .b-verified { background:#dcfce7; color:#166534; }
        .b-rejected { background:#fee2e2; color:#991b1b; }
        .b-voided { background:#334155; color:white; }

        .amount-pos { color: #10b981; font-weight: 700; }
        .amount-neg { color: #ef4444; font-weight: 700; }

            .menu-dropdown{margin-bottom:6px;}
            .dropdown-toggle{
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:8px;
            }
            .finance-main-link{
                display:flex;
                align-items:center;
                gap:12px;
                color:inherit;
                text-decoration:none;
                flex:1;
            }
            .submenu-toggle-btn{
                background:none;
                border:none;
                color:inherit;
                cursor:pointer;
                width:28px;
                height:28px;
                border-radius:6px;
            }
            .submenu{
                display:none !important;
                padding-left:18px;
                margin-top:4px;
                margin-bottom:8px;
            }
            .submenu.show{
                display:block !important;
            }
            .submenu-link.active{
                background:var(--primary-light);
                color:var(--primary);
                font-weight:800;
            }



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

        /* LEDGER TABLE AUTO FIT */
        .content-area{
            max-width:100%;
            box-sizing:border-box;
        }
        .toolbar form{
            box-sizing:border-box;
        }
        .summary-wrapper{
            flex-wrap:wrap;
        }
        .summary-card{
            min-width:220px;
        }
        .table-container{
            width:100%;
            overflow-x:auto;
        }
        .table-container table{
            width:100%;
            min-width:1250px;
            table-layout:fixed;
        }
        .table-container th,
        .table-container td{
            padding:14px 16px;
            word-break:break-word;
            overflow-wrap:anywhere;
        }
        .table-container td:nth-child(6){
            font-size:12px;
        }
        .filter-group input,
        .filter-group select{
            max-width:100%;
            box-sizing:border-box;
        }

        @media(max-width:1200px){
            .content-area{ padding:24px; }
            .table-container table{ min-width:1150px; }
            .toolbar form{ gap:10px !important; }
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
                flex-wrap:wrap;
            }
            .top-header > div[style*="display: flex"]{
                margin-right:0 !important;
                width:100%;
            }
            .header-title h1{ font-size:18px; }
            .header-title p{ display:none; }
            .profile-info{ display:none; }
            .content-area{ padding:18px; }
            .toolbar{
                padding:14px;
                border-radius:14px;
            }
            .toolbar form,
            .filter-group{
                width:100% !important;
                flex-direction:column;
                align-items:stretch !important;
            }
            .filter-group .form-control,
            .filter-group .btn-filter,
            .filter-group .btn-reset,
            .filter-group a,
            .filter-group button{
                width:100% !important;
                box-sizing:border-box;
                justify-content:center;
            }
            .summary-wrapper{
                flex-direction:column;
                gap:14px;
            }
            .summary-card{
                min-width:0;
                width:100%;
                box-sizing:border-box;
            }
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
            .table-container tbody tr{
                background:#fff;
                border:1px solid var(--gray-border);
                border-radius:16px;
                margin-bottom:16px;
                overflow:hidden;
                box-shadow:var(--shadow-sm);
            }
            .table-container tbody tr td{
                border-bottom:1px solid #e2e8f0;
                padding:14px 16px;
            }
            .table-container tbody tr td:last-child{ border-bottom:0; }
            .table-container tbody tr td::before{
                content:attr(data-label);
                display:block;
                font-size:11px;
                font-weight:800;
                color:var(--text-muted);
                text-transform:uppercase;
                margin-bottom:7px;
                letter-spacing:.4px;
            }
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


/* TRANSACTION HEADER ALIGNMENT */
.top-header{
    display:flex !important;
    align-items:center !important;
    gap:14px !important;
}
.ledger-actions{
    margin-left:auto !important;
}
.header-right{
    margin-left:0 !important;
}
@media(max-width:1100px){
    .top-header{
        flex-wrap:wrap !important;
    }
    .ledger-actions{
        order:3;
        width:100%;
        margin-left:0 !important;
        justify-content:flex-start;
    }
    .header-right{
        margin-left:auto !important;
    }
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

            <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>

            <?php if($_SESSION['role'] !== 'CASHIER'): ?>
                <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-bottom: 12px;">MAIN MENU</small>
                <a href="admin.php" class="menu-link">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span class="menu-text">Dashboard</span>
                </a>

                <a href="reservation.php" class="menu-link">
                    <i class="fa-solid fa-file-signature"></i>
                    <span class="menu-text">Reservations</span>
                </a>

                <a href="master_list.php" class="menu-link">
                    <i class="fa-solid fa-map-location-dot"></i>
                    <span class="menu-text">Master List / Map</span>
                </a>

                <a href="admin.php?view=inventory" class="menu-link">
                    <i class="fa-solid fa-plus-circle"></i>
                    <span class="menu-text">Add Property</span>
                </a>

            <?php endif; ?>

            <div class="menu-dropdown">

                <div class="menu-link dropdown-toggle active">

                    <a href="financial.php" class="finance-main-link">
                        <i class="fa-solid fa-coins"></i>
                        <span>Financials</span>
                    </a>

                    <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)">
                        <i class="fa-solid fa-chevron-up dropdown-arrow" id="financeArrow"></i>
                    </button>

                </div>

                <div id="financeSubMenu" class="submenu show">

                    <a href="verify_payments.php" class="menu-link submenu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-circle-check"></i>
                        <span class="menu-text">Verify Payments</span>
                    </a>

                    <a href="payment_tracking.php" class="menu-link submenu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                        <span class="menu-text">Payment Tracking</span>
                    </a>

                    <a href="transaction_history.php" class="menu-link submenu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-list-ul"></i>
                        <span class="menu-text">Ledger List</span>
                    </a>

                    <a href="daily_reconciliation.php" class="menu-link submenu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>">
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
                    <h1>Financial Ledger & Transactions</h1>
                    <p>Complete history of all income, bills, and vouchers.</p>
                </div>
            </div>
            
            <div class="ledger-actions">
                <a href="financial.php" class="btn-reset" style="background: var(--primary-light); color: var(--primary); border-color: var(--gray-border);"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
                <button type="button" class="btn-print-ledger" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Ledger</button>
                <a href="export_excel.php" class="btn-export-ledger"><i class="fa-solid fa-file-export"></i> Export</a>
            </div>

            <div class="header-right">
                <?php include 'includes/profile_dropdown.php'; ?>
            </div>
        </div>

        <div class="content-area">

            <div class="toolbar">
                <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; width: 100%; align-items: center;">
                    <div class="filter-group">
                        <select name="type" class="form-control">
                            <option value="ALL" <?= $filter_type == 'ALL' ? 'selected' : '' ?>>All Transactions</option>
                            <option value="INCOME" <?= $filter_type == 'INCOME' ? 'selected' : '' ?>>Income Only</option>
                            <option value="EXPENSE" <?= $filter_type == 'EXPENSE' ? 'selected' : '' ?>>Expenses (Bills)</option>
                            <option value="CHECK" <?= $filter_type == 'CHECK' ? 'selected' : '' ?>>Check Vouchers</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <select name="payment_status" class="form-control">
                            <option value="ALL" <?= $filter_status == 'ALL' ? 'selected' : '' ?>>All Status</option>
                            <option value="VERIFIED" <?= $filter_status == 'VERIFIED' ? 'selected' : '' ?>>Verified</option>
                            <option value="PENDING" <?= $filter_status == 'PENDING' ? 'selected' : '' ?>>Pending</option>
                            <option value="UNPAID" <?= $filter_status == 'UNPAID' ? 'selected' : '' ?>>Unpaid</option>
                            <option value="PAID" <?= $filter_status == 'PAID' ? 'selected' : '' ?>>Paid</option>
                            <option value="REJECTED" <?= $filter_status == 'REJECTED' ? 'selected' : '' ?>>Rejected</option>
                            <option value="CANCELLED" <?= $filter_status == 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" placeholder="Start Date">
                        <span style="color: #94a3b8; font-size: 12px; font-weight: 600;">TO</span>
                        <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" placeholder="End Date">
                    </div>

                    <div class="filter-group" style="flex: 1;">
                        <div style="position: relative; width: 100%;">
                            <i class="fa-solid fa-search" style="position: absolute; left: 12px; top: 11px; color: #94a3b8; font-size: 13px;"></i>
                            <input type="text" name="search" class="form-control" placeholder="Search Payee, Reference, or Description..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 32px; width: 90%;">
                        </div>
                    </div>

                    <div class="filter-group">
                        <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Apply</button>
                        <a href="transaction_history.php" class="btn-reset">Reset</a>
                    </div>
                </form>
            </div>

            <div class="summary-wrapper">
                <div class="summary-card">
                    <div class="summary-icon si-inc"><i class="fa-solid fa-arrow-trend-up"></i></div>
                    <div>
                        <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">Filtered Income</span>
                        <div style="font-size: 20px; font-weight: 800; color: #059669;">₱<?= number_format($total_income_view, 2) ?></div>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-icon si-exp"><i class="fa-solid fa-arrow-trend-down"></i></div>
                    <div>
                        <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">Filtered Expenses</span>
                        <div style="font-size: 20px; font-weight: 800; color: #dc2626;">₱<?= number_format($total_expense_view, 2) ?></div>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-icon si-net"><i class="fa-solid fa-wallet"></i></div>
                    <div>
                        <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">Net Cash Flow</span>
                        <div style="font-size: 20px; font-weight: 800; color: <?= $net_cash_view >= 0 ? '#2563eb' : '#dc2626' ?>;">₱<?= number_format($net_cash_view, 2) ?></div>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-icon si-count"><i class="fa-solid fa-list-check"></i></div>
                    <div>
                        <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">Transactions</span>
                        <div style="font-size: 20px; font-weight: 800; color: #7c3aed;"><?= number_format($total_transactions_view) ?></div>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-icon si-voucher"><i class="fa-solid fa-money-check-dollar"></i></div>
                    <div>
                        <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">Check Vouchers</span>
                        <div style="font-size: 20px; font-weight: 800; color: #7c3aed;"><?= number_format($check_vouchers_view) ?></div>
                    </div>
                </div>

                <div class="summary-card">
                    <div class="summary-icon si-pending"><i class="fa-solid fa-clock"></i></div>
                    <div>
                        <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">Pending / Unpaid</span>
                        <div style="font-size: 20px; font-weight: 800; color: #c2410c;"><?= number_format($pending_count_view) ?></div>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reference / OR</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Payee</th>
                            <th>Category</th>
                            <th style="width: 24%;">Description</th>
                            <th>Amount</th>
                            <th>Balance</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($transactions)): ?>
                            <?php foreach($transactions as $t): ?>
                            <?php
                                $is_check = (int)($t['is_check'] ?? 0) === 1;
                                $tx_type = $t['type'] ?? '';
                                $payment_status = strtoupper(trim($t['payment_status'] ?? 'VERIFIED'));
                                if($payment_status === '') $payment_status = 'VERIFIED';
                                $status_class = 'b-' . strtolower(str_replace(' ', '-', $payment_status));
                                $balance_after = $running_balances[(int)$t['id']] ?? 0;
                            ?>
                            <tr>
                                <td data-label="Date" style="font-weight: 600; color: #64748b;"><?= date('M d, Y', strtotime($t['transaction_date'])) ?></td>

                                <td data-label="Reference / OR">
                                    <strong style="color: #1e293b;"><?= htmlspecialchars($t['or_number'] ?? 'N/A') ?></strong>
                                    <?php if($is_check): ?>
                                        <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">CHK: <?= htmlspecialchars($t['check_number']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <td data-label="Type">
                                    <?php 
                                        if($tx_type == 'INCOME') {
                                            echo '<span class="badge b-inc"><i class="fa-solid fa-arrow-trend-up"></i> INCOME</span>';
                                        } else {
                                            if($is_check) {
                                                echo '<span class="badge b-chk"><i class="fa-solid fa-money-check-dollar"></i> CHECK VOUCHER</span>';
                                            } else {
                                                echo '<span class="badge b-exp"><i class="fa-solid fa-arrow-trend-down"></i> CASH EXPENSE</span>';
                                            }
                                        }
                                    ?>
                                </td>

                                <td data-label="Status">
                                    <span class="badge <?= $status_class ?>"><?= htmlspecialchars($payment_status) ?></span>
                                </td>

                                <td data-label="Payee">
                                    <div style="font-weight: 800; color: #334155;">
                                        <?= htmlspecialchars(!empty($t['payee']) ? $t['payee'] : 'General / System') ?>
                                    </div>
                                </td>

                                <td data-label="Category">
                                    <div style="font-size: 12px; color: #64748b; font-weight: 700;">
                                        <i class="fa-solid fa-tag"></i> <?= htmlspecialchars($t['category_name'] ?? 'Uncategorized') ?>
                                    </div>
                                </td>

                                <td data-label="Description" style="color: #475569; line-height: 1.4;">
                                    <?= htmlspecialchars($t['description']) ?>
                                </td>

                                <td data-label="Amount" class="<?= $tx_type == 'INCOME' ? 'amount-pos' : 'amount-neg' ?>">
                                    <i class="fa-solid <?= $tx_type == 'INCOME' ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i>
                                    <?= $tx_type == 'INCOME' ? '+' : '-' ?> ₱<?= number_format((float)$t['amount'], 2) ?>
                                </td>

                                <td data-label="Balance" class="amount-balance">
                                    ₱<?= number_format($balance_after, 2) ?>
                                </td>

                                <td data-label="Actions">
                                    <div class="row-actions">
                                        <?php if($is_check): ?>
                                            <a href="print_check_voucher.php?cv=<?= urlencode($t['or_number']) ?>" target="_blank" class="btn-row print">
                                                <i class="fa-solid fa-print"></i> Print
                                            </a>
                                        <?php elseif($tx_type === 'EXPENSE'): ?>
                                            <a href="voucher.php?or=<?= urlencode($t['or_number']) ?>" target="_blank" class="btn-row voucher">
                                                <i class="fa-solid fa-file-invoice"></i> Voucher
                                            </a>
                                        <?php else: ?>
                                            <a href="transaction_history.php?search=<?= urlencode($t['or_number']) ?>" class="btn-row">
                                                <i class="fa-solid fa-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" style="text-align: center; padding: 50px; color: #94a3b8;">
                                    <i class="fa-solid fa-folder-open" style="font-size: 34px; margin-bottom: 15px; display: block; color: #cbd5e1;"></i>
                                    <span style="font-weight: 500;">No transactions found matching your filters.</span>
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
    if (window.innerWidth <= 768) {
        document.body.classList.toggle('sidebar-open');
    } else {
        document.body.classList.toggle('sidebar-collapsed');
    }
}

function toggleFinanceMenu(event){
    if(event){
        event.preventDefault();
        event.stopPropagation();
    }

    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');

    if(!submenu || !arrow) return;

    submenu.classList.toggle('show');

    arrow.classList.toggle('fa-chevron-up');
    arrow.classList.toggle('fa-chevron-down');
}

document.addEventListener('click', function(e){
    if(window.innerWidth <= 768 && document.body.classList.contains('sidebar-open')){
        if(!e.target.closest('.sidebar') && !e.target.closest('.sidebar-toggle')){
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