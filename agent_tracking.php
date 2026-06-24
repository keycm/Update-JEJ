<?php
// agent_tracking.php
require_once 'config.php';
requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER']);

if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'fin_process');
}

$alert_msg = '';
$alert_type = '';

// Detect transaction columns safely
$transactionResCol = null;
foreach (['reservation_id', 'res_id'] as $possibleCol) {
    $colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE '$possibleCol'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $transactionResCol = $possibleCol;
        break;
    }
}

$transactionStatusCol = null;
foreach (['payment_status', 'status'] as $possibleCol) {
    $colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE '$possibleCol'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $transactionStatusCol = $possibleCol;
        break;
    }
}

$paidSubquery = "
    SELECT
        0 AS reservation_id,
        0 AS total_paid
    WHERE 1=0
";

if ($transactionResCol) {
    $verifiedWhere = "";
    if ($transactionStatusCol) {
        $verifiedWhere = " AND UPPER(COALESCE(`$transactionStatusCol`,'')) = 'VERIFIED' ";
    }

    $paidSubquery = "
        SELECT
            `$transactionResCol` AS reservation_id,
            SUM(COALESCE(amount,0)) AS total_paid
        FROM transactions
        WHERE UPPER(COALESCE(type,'')) = 'INCOME'
        $verifiedWhere
        GROUP BY `$transactionResCol`
    ";
}

/*
    AGENT TRACKING LOGIC

    Assigned Accounts = all reservations assigned to the agent
    Total Buyers      = unique buyers assigned to the agent
    Sold Lots         = verified total payments >= lot total price
    Reserved Lots     = approved reservations that are NOT yet fully paid
    Performance       = sold lots / assigned accounts * 100
*/
$query = "
    SELECT
        r.agent_name,

        COUNT(DISTINCT r.user_id) AS total_buyers,

        COUNT(r.id) AS assigned_accounts,

        SUM(
            CASE
                WHEN UPPER(COALESCE(r.status,'')) = 'APPROVED'
                AND COALESCE(pay.total_paid,0) < COALESCE(l.total_price,0)
                THEN 1 ELSE 0
            END
        ) AS reserved_lots,

        SUM(
            CASE
                WHEN COALESCE(l.total_price,0) > 0
                AND COALESCE(pay.total_paid,0) >= COALESCE(l.total_price,0)
                THEN 1 ELSE 0
            END
        ) AS sold_lots,

        SUM(
            CASE
                WHEN UPPER(COALESCE(r.status,'')) = 'APPROVED'
                THEN COALESCE(l.total_price,0)
                ELSE 0
            END
        ) AS total_sales,

        CASE
            WHEN COUNT(r.id) = 0 THEN 0
            ELSE (
                SUM(
                    CASE
                        WHEN COALESCE(l.total_price,0) > 0
                        AND COALESCE(pay.total_paid,0) >= COALESCE(l.total_price,0)
                        THEN 1 ELSE 0
                    END
                ) / COUNT(r.id)
            ) * 100
        END AS performance_rate

    FROM reservations r

    LEFT JOIN lots l
    ON r.lot_id = l.id

    LEFT JOIN (
        $paidSubquery
    ) pay
    ON pay.reservation_id = r.id

    WHERE r.agent_name IS NOT NULL
    AND TRIM(r.agent_name) <> ''

    GROUP BY r.agent_name

    ORDER BY total_sales DESC, total_buyers DESC
";

$res = $conn->query($query);
if (!$res) {
    die('Agent Tracking Query Error: ' . $conn->error);
}

$agents = [];
$total_agents = 0;
$total_buyers = 0;
$total_reserved = 0;
$total_sold = 0;
$total_sales = 0;

while ($row = $res->fetch_assoc()) {
    $row['total_buyers'] = (int)$row['total_buyers'];
    $row['assigned_accounts'] = (int)$row['assigned_accounts'];
    $row['reserved_lots'] = (int)$row['reserved_lots'];
    $row['sold_lots'] = (int)$row['sold_lots'];
    $row['total_sales'] = (float)$row['total_sales'];
    $row['performance_rate'] = (float)$row['performance_rate'];

    $agents[] = $row;
    $total_agents++;
    $total_buyers += $row['total_buyers'];
    $total_reserved += $row['reserved_lots'];
    $total_sold += $row['sold_lots'];
    $total_sales += $row['total_sales'];
}

function performanceBadge($rate) {
    $rate = (float)$rate;
    if ($rate >= 70) return '<span class="badge badge-full">' . number_format($rate, 1) . '%</span>';
    if ($rate >= 40) return '<span class="badge badge-partial">' . number_format($rate, 1) . '%</span>';
    return '<span class="badge badge-none">' . number_format($rate, 1) . '%</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Tracking | JEJ Admin</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --primary:#2e7d32; --primary-light:#e8f5e9; --dark:#1b5e20; --gray-light:#f1f8e9; --gray-border:#c8e6c9; --text-muted:#607d8b; }
        body { background-color:#fafcf9; display:flex; min-height:100vh; overflow-x:hidden; font-family:'Inter',sans-serif; color:#37474f; margin:0; }
        .sidebar { width:260px; background:#fff; border-right:1px solid #c8e6c9; display:flex; flex-direction:column; position:fixed; height:100vh; z-index:100; box-shadow:0 1px 2px rgba(46,125,50,.08); }
        .brand-box { padding:25px; border-bottom:1px solid #c8e6c9; display:flex; align-items:center; gap:12px; }
        .sidebar-menu { padding:20px 15px; flex:1; overflow-y:auto; }
        .menu-link { display:flex; align-items:center; gap:12px; padding:12px 18px; color:#455a64; text-decoration:none; font-weight:500; font-size:14px; border-radius:10px; margin-bottom:6px; transition:.2s ease; }
        .menu-link:hover { background:#f1f8e9; color:#2e7d32; }
        .menu-link.active { background:#e8f5e9; color:#2e7d32; font-weight:700; border-left:4px solid #2e7d32; }
        .menu-link i { width:20px; text-align:center; font-size:16px; opacity:.8; }
        .menu-dropdown { margin-bottom:6px; }
        .dropdown-toggle { display:flex; align-items:center; justify-content:space-between; gap:8px; cursor:pointer; }
        .finance-main-link { display:flex; align-items:center; gap:12px; flex:1; color:inherit; text-decoration:none; }
        .submenu-toggle-btn { border:none; background:none; cursor:pointer; width:28px; height:28px; border-radius:6px; color:#2e7d32; }
        .submenu-toggle-btn:hover { background:#dff2e1; }
        .submenu { display:none !important; padding-left:18px; margin-top:6px; }
        .submenu.show { display:block !important; }
        .submenu-link { font-size:13px; margin-bottom:6px; }
        .submenu-link.active { background:#e8f5e9; color:#2e7d32; font-weight:700; border-left:4px solid #2e7d32; }
        .main-panel { margin-left:260px; flex:1; padding:0; width:calc(100% - 260px); display:flex; flex-direction:column; }
        .top-header { display:flex; justify-content:space-between; align-items:center; background:#fff; padding:20px 40px; border-bottom:1px solid #c8e6c9; box-shadow:0 1px 2px rgba(46,125,50,.08); z-index:50; }
        .header-title h1 { font-size:22px; font-weight:800; color:#1b5e20; margin:0 0 4px; }
        .header-title p { color:#607d8b; font-size:13px; margin:0; }
        .content-area { padding:35px 40px; flex:1; }
        .analytics-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:18px; margin-bottom:28px; }
        .analytics-card { background:#fff; border:1px solid #c8e6c9; border-radius:16px; padding:18px; display:flex; align-items:center; gap:14px; box-shadow:0 2px 8px rgba(46,125,50,.08); }
        .analytics-card small { display:block; color:#607d8b; font-size:12px; margin-bottom:5px; font-weight:700; text-transform:uppercase; }
        .analytics-card h3 { margin:0; font-size:20px; color:#1b5e20; font-weight:800; }
        .analytics-icon { width:50px; height:50px; min-width:50px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:20px; color:white; }
        .analytics-icon.agents { background:linear-gradient(135deg,#10b981,#059669); }
        .analytics-icon.buyers { background:linear-gradient(135deg,#3b82f6,#2563eb); }
        .analytics-icon.reserved { background:linear-gradient(135deg,#f59e0b,#d97706); }
        .analytics-icon.sold { background:linear-gradient(135deg,#8b5cf6,#7c3aed); }
        .table-container { background:#fff; border-radius:16px; border:1px solid #c8e6c9; box-shadow:0 1px 2px rgba(46,125,50,.08); overflow:hidden; margin-bottom:30px; }
        table { width:100%; border-collapse:collapse; }
        th { text-align:left; padding:16px 20px; font-size:12px; font-weight:700; color:#607d8b; text-transform:uppercase; background:#f1f8e9; border-bottom:1px solid #c8e6c9; }
        td { padding:16px 20px; border-bottom:1px solid #c8e6c9; color:#37474f; font-size:13px; vertical-align:middle; }
        .badge { padding:6px 11px; border-radius:999px; font-size:10px; font-weight:800; text-transform:uppercase; display:inline-block; }
        .badge-full { background:#d1fae5; color:#059669; border:1px solid #a7f3d0; }
        .badge-partial { background:#dbeafe; color:#2563eb; border:1px solid #bfdbfe; }
        .badge-none { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
        .muted { color:#64748b; font-size:12px; }
        .progress-wrap { width:140px; height:8px; background:#e2e8f0; border-radius:999px; overflow:hidden; margin-top:7px; }
        .progress-bar { height:100%; background:#2e7d32; border-radius:999px; }
        @media(max-width:991px){ .main-panel{margin-left:0;width:100%;}.sidebar{position:relative;width:100%;height:auto}.content-area{padding:25px 18px}.top-header{padding:20px}.table-container{overflow-x:auto;} }


        /* AUTO FIT + COLLAPSIBLE SIDEBAR - WORKING FIX */
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
            background:#e8f5e9;
            color:#2e7d32;
            font-size:18px;
            cursor:pointer;
            flex-shrink:0;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .sidebar-toggle:hover{
            background:#c8e6c9;
        }

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
            font-size:0;
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

        body.sidebar-collapsed .finance-main-link i{
            width:22px;
            font-size:18px;
        }

        .content-area{
            max-width:100%;
            box-sizing:border-box;
        }

        .analytics-grid{
            grid-template-columns:repeat(auto-fit,minmax(190px,1fr)) !important;
        }

        .table-container{
            width:100%;
            overflow-x:auto;
        }

        .table-container table{
            width:100%;
            min-width:900px;
            table-layout:fixed;
        }

        .table-container th,
        .table-container td{
            word-break:break-word;
            overflow-wrap:anywhere;
        }

        @media(max-width:1200px){
            .content-area{ padding:24px; }
            .table-container table{ min-width:820px; }
        }

        @media(max-width:768px){
            body{
                display:flex !important;
                flex-direction:row !important;
            }

            .sidebar{
                position:fixed !important;
                top:0;
                left:-260px;
                width:260px !important;
                height:100vh !important;
                z-index:101;
            }

            .main-panel,
            body.sidebar-collapsed .main-panel{
                margin-left:0 !important;
                width:100% !important;
            }

            body.sidebar-open .sidebar{
                left:0;
            }

            body.sidebar-open::after{
                content:"";
                position:fixed;
                inset:0;
                background:rgba(0,0,0,.35);
                z-index:90;
            }

            .top-header{
                padding:15px 18px !important;
                gap:12px;
            }

            .header-title h1{ font-size:18px; }
            .header-title p{ display:none; }
            .content-area{ padding:18px !important; }

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
                border:1px solid #c8e6c9;
                border-radius:16px;
                margin-bottom:16px;
                overflow:hidden;
                box-shadow:0 1px 2px rgba(46,125,50,.08);
            }

            .table-container tbody tr td{
                border-bottom:1px solid #e2e8f0;
                padding:14px 16px;
            }

            .table-container tbody tr td:last-child{ border-bottom:0; }

            .table-container tbody tr td::before{
                display:block;
                font-size:11px;
                font-weight:800;
                color:#607d8b;
                text-transform:uppercase;
                margin-bottom:7px;
                letter-spacing:.4px;
            }

            .table-container tbody tr td:nth-child(1)::before{ content:"Agent Name"; }
            .table-container tbody tr td:nth-child(2)::before{ content:"Total Buyers"; }
            .table-container tbody tr td:nth-child(3)::before{ content:"Assigned Accounts"; }
            .table-container tbody tr td:nth-child(4)::before{ content:"Reserved Lots"; }
            .table-container tbody tr td:nth-child(5)::before{ content:"Sold Lots"; }
            .table-container tbody tr td:nth-child(6)::before{ content:"Total Sales"; }
            .table-container tbody tr td:nth-child(7)::before{ content:"Performance"; }
        }

    </style>
</head>
<body>
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$financePages = ['financial.php','payment_tracking.php','daily_reconciliation.php','verify_payments.php','transaction_history.php','aging_due_report.php','contract_status.php','pricing_matrix.php'];
$isFinancePage = in_array($currentPage, $financePages, true);
?>

<div class="sidebar">
    <div class="brand-box">
        <img src="assets/logo1.png" style="height:38px;width:auto;border-radius:8px;">
        <div style="line-height:1.1;">
            <span style="font-size:16px;font-weight:800;color:#2e7d32;display:block;">JEJ Top Priority Corporation</span>
            <span style="font-size:11px;color:#607d8b;font-weight:500;">Management Portal</span>
        </div>
    </div>

    <div class="sidebar-menu">
        <small style="padding:0 15px;color:#90a4ae;font-weight:700;font-size:11px;display:block;margin-bottom:12px;">MAIN MENU</small>
        <a href="admin.php?view=dashboard" class="menu-link"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
        <a href="reservation.php" class="menu-link"><i class="fa-solid fa-file-signature"></i> Reservations</a>
        <a href="master_list.php" class="menu-link"><i class="fa-solid fa-map-location-dot"></i> Master List / Map</a>
        <a href="admin.php?view=inventory" class="menu-link"><i class="fa-solid fa-plus-circle"></i> Add Property</a>

        <div class="menu-dropdown">
            <div class="menu-link dropdown-toggle <?= $isFinancePage ? 'active' : '' ?>">
                <a href="financial.php" class="finance-main-link"><i class="fa-solid fa-coins"></i><span>Financials</span></a>
                <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)" title="Show/Hide Financial Menu">
                    <i class="fa-solid <?= $isFinancePage ? 'fa-chevron-up' : 'fa-chevron-down' ?>" id="financeArrow"></i>
                </button>
            </div>

            <div id="financeSubMenu" class="submenu <?= $isFinancePage ? 'show' : '' ?>">
                <a href="verify_payments.php" class="menu-link submenu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>"><i class="fa-solid fa-circle-check"></i> Verify Payments</a>
                <a href="payment_tracking.php" class="menu-link submenu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Payment Tracking</a>
                <a href="transaction_history.php" class="menu-link submenu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>"><i class="fa-solid fa-list-ul"></i> Ledger List</a>
                <a href="daily_reconciliation.php" class="menu-link submenu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>"><i class="fa-solid fa-scale-balanced"></i> Daily Reconciliation</a>
                <a href="aging_due_report.php" class="menu-link submenu-link <?= $currentPage == 'aging_due_report.php' ? 'active' : '' ?>"><i class="fa-solid fa-clock"></i> Aging / Due Report</a>
                <a href="contract_status.php" class="menu-link submenu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-signature"></i> Contract Status</a>
            </div>
        </div>

        <small style="padding:0 15px;color:#90a4ae;font-weight:700;font-size:11px;display:block;margin-top:25px;margin-bottom:12px;">SYSTEM</small>

<a href="agent_tracking.php"
   class="menu-link <?= $currentPage == 'agent_tracking.php' ? 'active' : '' ?>">
    <i class="fa-solid fa-user-tie"></i>
    Agent Tracking
</a>

<a href="index.php" class="menu-link" target="_blank">
    <i class="fa-solid fa-globe"></i>
    View Website
</a>
    </div>
</div>

<div class="main-panel">
    <div class="top-header">
        <div class="top-header-left">
            <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div class="header-title">
                <h1>Agent Tracking</h1>
                <p>Show assigned agent performance, buyer count, reserved lots, and sold lots.</p>
            </div>
        </div>
    </div>

    <div class="content-area">
        <div class="analytics-grid">
            <div class="analytics-card"><div class="analytics-icon agents"><i class="fa-solid fa-user-tie"></i></div><div><small>Total Agents</small><h3><?= $total_agents ?></h3></div></div>
            <div class="analytics-card"><div class="analytics-icon buyers"><i class="fa-solid fa-users"></i></div><div><small>Total Buyers</small><h3><?= $total_buyers ?></h3></div></div>
            <div class="analytics-card"><div class="analytics-icon reserved"><i class="fa-solid fa-map-pin"></i></div><div><small>Reserved Lots</small><h3><?= $total_reserved ?></h3></div></div>
            <div class="analytics-card"><div class="analytics-icon sold"><i class="fa-solid fa-house-circle-check"></i></div><div><small>Sold Lots</small><h3><?= $total_sold ?></h3></div></div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Agent Name</th>
                        <th>Total Buyers</th>
                        <th>Assigned Accounts</th>
                        <th>Reserved Lots</th>
                        <th>Sold Lots</th>
                        <th>Total Sales</th>
                        <th>Performance</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(!empty($agents)): ?>
                    <?php foreach($agents as $row): ?>
                        <tr>
                            <td><strong style="color:#1e293b;"><i class="fa-solid fa-user-tie" style="color:#2e7d32;margin-right:6px;"></i><?= htmlspecialchars($row['agent_name']) ?></strong></td>
                            <td><?= number_format($row['total_buyers']) ?></td>
                            <td><?= number_format($row['assigned_accounts']) ?></td>
                            <td><?= number_format($row['reserved_lots']) ?></td>
                            <td><?= number_format($row['sold_lots']) ?></td>
                            <td><strong style="color:#1b5e20;">₱<?= number_format($row['total_sales'], 2) ?></strong></td>
                            <td>
                                <?= performanceBadge($row['performance_rate']) ?>
                                <div class="progress-wrap"><div class="progress-bar" style="width:<?= min(100, max(0, $row['performance_rate'])) ?>%;"></div></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">No assigned agents found.</td></tr>
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

document.addEventListener('click', function(e){
    if(window.innerWidth <= 768 && document.body.classList.contains('sidebar-open')){
        const sidebar = document.querySelector('.sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        if(sidebar && toggle && !sidebar.contains(e.target) && !toggle.contains(e.target)){
            document.body.classList.remove('sidebar-open');
        }
    }
});

window.addEventListener('resize', function(){
    if(window.innerWidth > 768){
        document.body.classList.remove('sidebar-open');
    }
});
function toggleFinanceMenu(event){
    event.preventDefault();
    event.stopPropagation();
    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');
    submenu.classList.toggle('show');
    arrow.classList.toggle('fa-chevron-down');
    arrow.classList.toggle('fa-chevron-up');
}
</script>
</body>
</html>
