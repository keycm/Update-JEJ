<?php
// admin.php
include 'config.php';

// Security Check
if(!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['SUPER ADMIN', 'ADMIN', 'MANAGER', 'CASHIER'])){
    header("Location: login.php");
    exit();
}

$view = $_GET['view'] ?? 'dashboard';
$currentPage = basename($_SERVER['PHP_SELF']);
$edit_mode = false;
$edit_data = [];
$alert_msg = "";
$alert_type = "";

// --- HANDLING ALERTS ---
if(isset($_GET['msg'])){
    $m = $_GET['msg'];
    if($m=='added') { $alert_msg = "Successfully added to database!"; $alert_type = "success"; }
    if($m=='updated') { $alert_msg = "Property details updated."; $alert_type = "success"; }
    if($m=='deleted') { $alert_msg = "Property deleted and moved to Archive."; $alert_type = "error"; }
    if($m=='bulk_added') {
        $count = $_GET['count'] ?? 'Multiple';
        $alert_msg = "$count properties bulk added successfully!"; 
        $alert_type = "success"; 
    }
}

// --- INVENTORY ACTIONS ---
if(isset($_POST['save_lot'])){
    $entry_mode = $_POST['entry_mode'] ?? 'single';
    
    // Smart Location Handler
    $location = $_POST['location'] ?? NULL;
    if($location == 'NEW_AREA'){
        $location = !empty(trim($_POST['new_location'] ?? '')) ? trim($_POST['new_location']) : NULL;
        // If adding a new area only, force single mode
        if(empty($_POST['block_no']) && empty($_POST['lot_no'])) {
            $entry_mode = 'single';
        }
    }

    $prop_type = $_POST['property_type'] ?? 'Lot';
    $block = $_POST['block_no'] ?? '';
    
    // Safely handle empty number inputs (defaults to 0 if empty)
    $area = !empty($_POST['area']) ? $_POST['area'] : 0;
    $price_sqm = !empty($_POST['price_sqm']) ? $_POST['price_sqm'] : 0; 
    $total = !empty($_POST['total_price']) ? $_POST['total_price'] : 0;
    
    $status = $_POST['status'] ?? 'AVAILABLE';
    
    // Process the pricing rules into the overview
    $lot_class = $_POST['lot_class'] ?? 'Inner Lot';
    $terms = $_POST['terms'] ?? '0';
    $term_text = ($terms == '0') ? "Spot Cash Payment" : "$terms Years Installment";
    $base_overview = $_POST['property_overview'] ?? '';
    
    $overview = "📌 [PRICING CONFIGURATION]\nClassification: $lot_class\nPayment Terms: $term_text\n\n" . $base_overview;
    
    $lat = !empty($_POST['latitude']) ? $_POST['latitude'] : NULL;
    $lng = !empty($_POST['longitude']) ? $_POST['longitude'] : NULL;
    
    $lot_image = $_POST['current_image'] ?? ''; 
    if(isset($_FILES['lot_image']) && $_FILES['lot_image']['error'] == 0){
        $target_dir = "uploads/";
        if(!is_dir($target_dir)) mkdir($target_dir);
        $filename = time() . "_" . basename($_FILES["lot_image"]["name"]);
        move_uploaded_file($_FILES["lot_image"]["tmp_name"], $target_dir . $filename);
        $lot_image = $filename;
    }

    if($entry_mode == 'bulk') {
        // --- BULK ENTRY LOGIC ---
        $start_lot = (int)$_POST['start_lot'];
        $end_lot = (int)$_POST['end_lot'];
        $added = 0;
        
        $stmt = $conn->prepare("INSERT INTO lots (location, property_type, block_no, lot_no, area, price_per_sqm, total_price, status, property_overview, lot_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        for($i = $start_lot; $i <= $end_lot; $i++) {
            $current_lot = (string)$i;
            $stmt->bind_param("ssssdddsss", $location, $prop_type, $block, $current_lot, $area, $price_sqm, $total, $status, $overview, $lot_image);
            if($stmt->execute()){
                $added++;
            }
        }
        
        logActivity($conn, $_SESSION['user_id'], "Bulk Added Properties", "Added $added lots to Block $block in $location");
        header("Location: admin.php?view=inventory&msg=bulk_added&count=$added");
        exit();

    } else {
        // --- SINGLE ENTRY / UPDATE LOGIC ---
        $lot_no = $_POST['lot_no'] ?? '';

        if(!empty($_POST['lot_id'])){
            $id = $_POST['lot_id'];
            $stmt = $conn->prepare("UPDATE lots SET location=?, property_type=?, block_no=?, lot_no=?, area=?, price_per_sqm=?, total_price=?, status=?, property_overview=?, latitude=?, longitude=?, lot_image=? WHERE id=?");
            $stmt->bind_param("ssssdddssddsi", $location, $prop_type, $block, $lot_no, $area, $price_sqm, $total, $status, $overview, $lat, $lng, $lot_image, $id);
            $stmt->execute();
            $target_lot_id = $id;
            $msg = "updated";
            
            logActivity($conn, $_SESSION['user_id'], "Updated Property", "Lot ID: $id | Block: $block, Lot: $lot_no | Status: $status");
        } else {
            $stmt = $conn->prepare("INSERT INTO lots (location, property_type, block_no, lot_no, area, price_per_sqm, total_price, status, property_overview, latitude, longitude, lot_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssdddssdds", $location, $prop_type, $block, $lot_no, $area, $price_sqm, $total, $status, $overview, $lat, $lng, $lot_image);
            $stmt->execute();
            $target_lot_id = $conn->insert_id;
            $msg = "added";

            logActivity($conn, $_SESSION['user_id'], "Added New Property/Area", "ID: $target_lot_id | Location: $location");
        }

        if(isset($_FILES['gallery'])){
            $count = count($_FILES['gallery']['name']);
            if(!is_dir("uploads/")) mkdir("uploads/");
            for($i=0; $i<$count; $i++){
                if($_FILES['gallery']['error'][$i] == 0){
                    $g_filename = time() . "_" . $i . "_" . basename($_FILES['gallery']['name'][$i]);
                    if(move_uploaded_file($_FILES['gallery']['tmp_name'][$i], "uploads/" . $g_filename)){
                        $conn->query("INSERT INTO lot_gallery (lot_id, image_path) VALUES ('$target_lot_id', '$g_filename')");
                    }
                }
            }
        }

        header("Location: admin.php?view=inventory&msg=$msg");
        exit();
    }
}

if(isset($_GET['delete_id'])){
    $id = $_GET['delete_id'];
    $lot_data = $conn->query("SELECT * FROM lots WHERE id='$id'")->fetch_assoc();
    if($lot_data) { logDeletion($conn, 'Property Inventory', $id, $lot_data, $_SESSION['user_id']); }
    logActivity($conn, $_SESSION['user_id'], "Deleted Property", "Removed Lot ID: $id from inventory");

    $conn->query("DELETE FROM reservations WHERE lot_id='$id'");
    $conn->query("DELETE FROM lot_gallery WHERE lot_id='$id'");
    $conn->query("DELETE FROM lots WHERE id='$id'");
    header("Location: admin.php?view=inventory&msg=deleted");
    exit();
}

if(isset($_GET['edit_id'])){
    $view = 'inventory'; 
    $edit_mode = true;
    $id = $_GET['edit_id'];
    $edit_data = $conn->query("SELECT * FROM lots WHERE id='$id'")->fetch_assoc();
}

// --- DATA FETCHING (PROPERTY INVENTORY OVERVIEW) ---
// Business rule:
// RESERVED = approved reservation/account that still has outstanding balance.
// SOLD     = approved reservation/account where verified payments fully cover the buyer's payable TCP.
// Payable TCP follows your real-estate flow:
//   Spot Cash   = TCP - 5% discount - reservation fee
//   Installment = TCP - 3% discount - reservation fee
// This makes the dashboard match Reservation, Payment Tracking, and SOA.

$stats_pending = 0;
$stats_reserved = 0;
$stats_sold = 0;
$stats_avail = 0;

function adminTableHasColumn($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnEscaped = $conn->real_escape_string($column);
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$columnEscaped'");
    return ($check && $check->num_rows > 0);
}

function adminReservationNumberValue($row, $keys, $default = 0) {
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '' && $row[$key] !== null) {
            return (float)$row[$key];
        }
    }
    return (float)$default;
}

function adminReservationPaymentType($row) {
    foreach (['payment_type', 'payment_category', 'payment_terms', 'terms_type', 'payment_option', 'payment_method'] as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return strtoupper(trim((string)$row[$key]));
        }
    }
    return '';
}

function adminComputePayableTcp($row) {
    $original_tcp = adminReservationNumberValue($row, ['total_price', 'tcp', 'original_tcp', 'original_total_price'], 0);

    $payment_type = adminReservationPaymentType($row);
    $payment_type_clean = strtoupper(str_replace(['_', '-'], ' ', $payment_type));

    $is_spot_cash = (
        strpos($payment_type_clean, 'CASH') !== false ||
        strpos($payment_type_clean, 'SPOT') !== false
    );

    $is_installment = (
        $payment_type_clean === '' ||
        strpos($payment_type_clean, 'INSTALL') !== false
    );

    $discount_percent = adminReservationNumberValue($row, [
        'discount_percent',
        'discount_rate',
        'selected_discount',
        'cash_discount_percent',
        'cash_discount_rate',
        'discount'
    ], 0);

    if($is_spot_cash && $discount_percent <= 0) $discount_percent = 5.00;
    if($is_installment && $discount_percent <= 0) $discount_percent = 3.00;

    $discount_amount_from_db = adminReservationNumberValue($row, [
        'discount_amount',
        'cash_discount_amount'
    ], 0);

    $tcp_after_discount_from_db = adminReservationNumberValue($row, [
        'tcp_after_discount',
        'discounted_total_price',
        'discounted_tcp',
        'total_after_discount'
    ], 0);

    if ($discount_amount_from_db > 0) {
        $discount_amount = $discount_amount_from_db;
    } elseif ($tcp_after_discount_from_db > 0 && $tcp_after_discount_from_db < $original_tcp) {
        $discount_amount = $original_tcp - $tcp_after_discount_from_db;
    } else {
        $discount_amount = round($original_tcp * ($discount_percent / 100), 2);
    }

    $reservation_fee = adminReservationNumberValue($row, [
        'reservation_fee',
        'reservation_payment',
        'reservation_amount',
        'reservation_fee_paid'
    ], 5000);

    $tcp_after_discount = max($original_tcp - $discount_amount, 0);
    return max($tcp_after_discount - $reservation_fee, 0);
}

function adminGetVerifiedPaymentTotals($conn) {
    $totals = [];

    $checkTransactions = $conn->query("SHOW TABLES LIKE 'transactions'");
    if(!$checkTransactions || $checkTransactions->num_rows == 0) return $totals;

    $reservationCol = null;
    foreach(['reservation_id', 'res_id', 'reservation'] as $col){
        if(adminTableHasColumn($conn, 'transactions', $col)){
            $reservationCol = $col;
            break;
        }
    }
    if(!$reservationCol) return $totals;

    $amountCol = null;
    foreach(['amount', 'amount_paid', 'payment_amount', 'total_amount'] as $col){
        if(adminTableHasColumn($conn, 'transactions', $col)){
            $amountCol = $col;
            break;
        }
    }
    if(!$amountCol) return $totals;

    $conditions = [];

    $statusCol = null;
    foreach(['status', 'payment_status', 'verification_status'] as $col){
        if(adminTableHasColumn($conn, 'transactions', $col)){
            $statusCol = $col;
            break;
        }
    }
    if($statusCol){
        $conditions[] = "UPPER(COALESCE(`$statusCol`,'')) IN ('VERIFIED','PAID','APPROVED')";
    }

    if(adminTableHasColumn($conn, 'transactions', 'type')){
        $conditions[] = "UPPER(COALESCE(`type`,'')) IN ('INCOME','PAYMENT','RECEIPT')";
    }

    $where = count($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $sql = "
        SELECT `$reservationCol` AS reservation_id,
               COALESCE(SUM(`$amountCol`),0) AS total_paid
        FROM transactions
        $where
        GROUP BY `$reservationCol`
    ";

    $result = $conn->query($sql);
    if($result){
        while($row = $result->fetch_assoc()){
            $totals[(int)$row['reservation_id']] = (float)$row['total_paid'];
        }
    }

    return $totals;
}

$pendingResult = $conn->query("
    SELECT COUNT(*) AS count
    FROM reservations
    WHERE UPPER(COALESCE(status,'')) = 'PENDING'
");
if($pendingResult){
    $stats_pending = (int)($pendingResult->fetch_assoc()['count'] ?? 0);
}

$availResult = $conn->query("
    SELECT COUNT(*) AS count
    FROM lots
    WHERE UPPER(COALESCE(status,'')) = 'AVAILABLE'
");
if($availResult){
    $stats_avail = (int)($availResult->fetch_assoc()['count'] ?? 0);
}

$paymentTotalsByReservation = adminGetVerifiedPaymentTotals($conn);

$approvedReservations = $conn->query("
    SELECT r.*, l.total_price
    FROM reservations r
    LEFT JOIN lots l ON r.lot_id = l.id
    WHERE UPPER(COALESCE(r.status,'')) = 'APPROVED'
");

if($approvedReservations){
    while($reservation = $approvedReservations->fetch_assoc()){
        $reservation_id = (int)$reservation['id'];
        $payable_tcp = adminComputePayableTcp($reservation);
        $verified_paid = (float)($paymentTotalsByReservation[$reservation_id] ?? 0);

        // Reservation fee may be recorded together with payments. Counting sold at payable TCP
        // makes Spot Cash discounted accounts like ₱422,500 paid count as sold.
        if($payable_tcp > 0 && $verified_paid >= ($payable_tcp - 0.01)){
            $stats_sold++;
        } else {
            $stats_reserved++;
        }
    }
}

// Keep lot inventory status synced for dashboard consistency.
// Fully paid approved reservations become SOLD; active approved but not fully paid remain RESERVED.
if($approvedReservations){
    // Result set was already consumed above; run a lightweight sync query again.
    $syncReservations = $conn->query("
        SELECT r.*, l.total_price
        FROM reservations r
        LEFT JOIN lots l ON r.lot_id = l.id
        WHERE UPPER(COALESCE(r.status,'')) = 'APPROVED'
    ");

    if($syncReservations){
        while($reservation = $syncReservations->fetch_assoc()){
            $reservation_id = (int)$reservation['id'];
            $lot_id = (int)$reservation['lot_id'];
            $payable_tcp = adminComputePayableTcp($reservation);
            $verified_paid = (float)($paymentTotalsByReservation[$reservation_id] ?? 0);
            $new_lot_status = ($payable_tcp > 0 && $verified_paid >= ($payable_tcp - 0.01)) ? 'SOLD' : 'RESERVED';
            $conn->query("UPDATE lots SET status='$new_lot_status' WHERE id=$lot_id AND UPPER(COALESCE(status,'')) <> '$new_lot_status'");
        }
    }
}

// FETCH ALL LOTS FOR INVENTORY DIRECTORY TAB
$lots = [];
if($view == 'inventory') {
    $sql = "SELECT * FROM lots ORDER BY location ASC, CAST(block_no AS UNSIGNED), CAST(lot_no AS UNSIGNED)";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $lots[] = $row;
        }
    }
}

// --- DATA FETCHING (MINI FINANCIAL & CALENDAR) ---
$income_months = []; $income_totals = [];
$expense_months = []; $expense_totals = [];
$calendar_events = [];

$resQuery = $conn->query("SELECT r.reservation_date, l.block_no, l.lot_no FROM reservations r JOIN lots l ON r.lot_id = l.id");
while($r = $resQuery->fetch_assoc()){
    $calendar_events[] = [
        'title' => 'Res: B'.$r['block_no'].' L'.$r['lot_no'],
        'start' => date('Y-m-d', strtotime($r['reservation_date'])),
        'color' => '#f57c00'
    ];
}

$recent_reservations = $conn->query("
    SELECT r.*, u.fullname, l.block_no, l.lot_no, l.total_price 
    FROM reservations r JOIN users u ON r.user_id = u.id JOIN lots l ON r.lot_id = l.id 
    ORDER BY r.reservation_date DESC LIMIT 10
");

$checkTable = $conn->query("SHOW TABLES LIKE 'transactions'");
if($checkTable && $checkTable->num_rows > 0) {
    $incData = $conn->query("SELECT DATE_FORMAT(transaction_date, '%b %Y') as month_label, DATE_FORMAT(transaction_date, '%Y-%m') as month_val, SUM(amount) as monthly_total FROM transactions WHERE type='INCOME' GROUP BY month_val, month_label ORDER BY month_val DESC LIMIT 6");
    while($row = $incData->fetch_assoc()){
        $income_months[] = $row['month_label'];
        $income_totals[] = $row['monthly_total'];
    }
    $income_months = array_reverse($income_months);
    $income_totals = array_reverse($income_totals);

    $expData = $conn->query("SELECT DATE_FORMAT(transaction_date, '%b %Y') as month_label, DATE_FORMAT(transaction_date, '%Y-%m') as month_val, SUM(amount) as monthly_total FROM transactions WHERE type='EXPENSE' GROUP BY month_val, month_label ORDER BY month_val DESC LIMIT 6");
    while($row = $expData->fetch_assoc()){
        $expense_months[] = $row['month_label'];
        $expense_totals[] = $row['monthly_total'];
    }
    $expense_months = array_reverse($expense_months);
    $expense_totals = array_reverse($expense_totals);
}

// --- ENHANCED DASHBOARD METRICS ---
$dash_total_income = 0;
$dash_total_expenses = 0;
$dash_net_profit = 0;
$dash_expected_collection = 0;
$dash_total_collected = 0;
$dash_outstanding_balance = 0;
$dash_collection_rate = 0;
$dash_total_buyers = 0;
$dash_active_buyers = 0;
$dash_fully_paid_buyers = 0;
$dash_overdue_buyers = 0;
$dash_total_lots = 0;
$dash_sales_percent = 0;
$dash_top_agent_name = 'No agent data yet';
$dash_top_agent_count = 0;
$dash_recent_activity = [];
$dash_upcoming_activities = [];
$dash_alerts = [];

// Fill the last 6 months so the Monthly Income chart does not look empty/misaligned.
$monthMapIncome = [];
$monthMapExpense = [];
for($i = 5; $i >= 0; $i--){
    $monthKey = date('Y-m', strtotime("-$i months"));
    $monthLabel = date('M Y', strtotime($monthKey . '-01'));
    $monthMapIncome[$monthKey] = ['label' => $monthLabel, 'total' => 0];
    $monthMapExpense[$monthKey] = ['label' => $monthLabel, 'total' => 0];
}

$checkTransactionsDash = $conn->query("SHOW TABLES LIKE 'transactions'");
if($checkTransactionsDash && $checkTransactionsDash->num_rows > 0) {
    $incomeWhere = "type='INCOME'";
    if(adminTableHasColumn($conn, 'transactions', 'payment_status')){
        $incomeWhere .= " AND (payment_status='VERIFIED' OR payment_status IS NULL)";
    }

    $dashIncomeRes = $conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM transactions WHERE $incomeWhere");
    if($dashIncomeRes) $dash_total_income = (float)($dashIncomeRes->fetch_assoc()['total'] ?? 0);

    $dashExpenseRes = $conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM transactions WHERE type='EXPENSE'");
    if($dashExpenseRes) $dash_total_expenses = (float)($dashExpenseRes->fetch_assoc()['total'] ?? 0);

    $dashMonthlyIncome = $conn->query("
        SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month_key, COALESCE(SUM(amount),0) AS monthly_total
        FROM transactions
        WHERE $incomeWhere
        AND transaction_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
        GROUP BY month_key
    ");
    if($dashMonthlyIncome){
        while($row = $dashMonthlyIncome->fetch_assoc()){
            if(isset($monthMapIncome[$row['month_key']])){
                $monthMapIncome[$row['month_key']]['total'] = (float)$row['monthly_total'];
            }
        }
    }

    $dashMonthlyExpense = $conn->query("
        SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month_key, COALESCE(SUM(amount),0) AS monthly_total
        FROM transactions
        WHERE type='EXPENSE'
        AND transaction_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
        GROUP BY month_key
    ");
    if($dashMonthlyExpense){
        while($row = $dashMonthlyExpense->fetch_assoc()){
            if(isset($monthMapExpense[$row['month_key']])){
                $monthMapExpense[$row['month_key']]['total'] = (float)$row['monthly_total'];
            }
        }
    }

    $income_months = array_column($monthMapIncome, 'label');
    $income_totals = array_column($monthMapIncome, 'total');
    $expense_months = array_column($monthMapExpense, 'label');
    $expense_totals = array_column($monthMapExpense, 'total');
}
$dash_net_profit = $dash_total_income - $dash_total_expenses;

$buyersCountRes = $conn->query("SELECT COUNT(*) AS total FROM users WHERE UPPER(COALESCE(role,''))='BUYER'");
if($buyersCountRes) $dash_total_buyers = (int)($buyersCountRes->fetch_assoc()['total'] ?? 0);

$activeBuyersRes = $conn->query("SELECT COUNT(DISTINCT user_id) AS total FROM reservations WHERE user_id IS NOT NULL");
if($activeBuyersRes) $dash_active_buyers = (int)($activeBuyersRes->fetch_assoc()['total'] ?? 0);

$totalLotsRes = $conn->query("SELECT COUNT(*) AS total FROM lots");
if($totalLotsRes) $dash_total_lots = (int)($totalLotsRes->fetch_assoc()['total'] ?? 0);
$dash_sales_percent = $dash_total_lots > 0 ? round(($stats_sold / $dash_total_lots) * 100, 1) : 0;

$allApprovedReservations = $conn->query("
    SELECT r.*, l.total_price
    FROM reservations r
    LEFT JOIN lots l ON r.lot_id = l.id
    WHERE UPPER(COALESCE(r.status,'')) = 'APPROVED'
");
if($allApprovedReservations){
    $buyerPaidMap = [];
    while($reservation = $allApprovedReservations->fetch_assoc()){
        $rid = (int)$reservation['id'];
        $uid = (int)($reservation['user_id'] ?? 0);
        $payable = adminComputePayableTcp($reservation);
        $paid = (float)($paymentTotalsByReservation[$rid] ?? 0);
        $balance = max($payable - $paid, 0);

        $dash_expected_collection += $payable;
        $dash_total_collected += min($paid, $payable > 0 ? $payable : $paid);
        $dash_outstanding_balance += $balance;

        if($uid > 0 && $payable > 0 && $paid >= ($payable - 0.01)){
            $buyerPaidMap[$uid] = true;
        }

        if($balance > 0.01){
            $dash_overdue_buyers++;
        }
    }
    $dash_fully_paid_buyers = count($buyerPaidMap);
}
$dash_collection_rate = $dash_expected_collection > 0 ? round(($dash_total_collected / $dash_expected_collection) * 100, 1) : 0;

// Top agent detection supports several common reservation column names.
$agentColumn = null;
foreach(['agent_id','sales_agent_id','assigned_agent_id'] as $col){
    if(adminTableHasColumn($conn, 'reservations', $col)){
        $agentColumn = $col;
        break;
    }
}
if($agentColumn){
    $topAgentQuery = $conn->query("
        SELECT u.fullname, COUNT(r.id) AS total_sales
        FROM reservations r
        LEFT JOIN users u ON r.`$agentColumn` = u.id
        WHERE r.`$agentColumn` IS NOT NULL
        GROUP BY r.`$agentColumn`, u.fullname
        ORDER BY total_sales DESC
        LIMIT 1
    ");
    if($topAgentQuery && $topAgentQuery->num_rows > 0){
        $topAgent = $topAgentQuery->fetch_assoc();
        $dash_top_agent_name = $topAgent['fullname'] ?: 'Unassigned Agent';
        $dash_top_agent_count = (int)($topAgent['total_sales'] ?? 0);
    }
}

// Recent activity feed from audit logs.
$checkAudit = $conn->query("SHOW TABLES LIKE 'audit_logs'");
if($checkAudit && $checkAudit->num_rows > 0){
    $activityQuery = $conn->query("
        SELECT a.action, a.details, a.created_at, u.fullname
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        ORDER BY a.created_at DESC
        LIMIT 6
    ");
    if($activityQuery){
        while($row = $activityQuery->fetch_assoc()){
            $dash_recent_activity[] = $row;
        }
    }
}

// Replace calendar with clean upcoming activity list.
$upcomingQuery = $conn->query("
    SELECT r.reservation_date, u.fullname, l.block_no, l.lot_no, r.status
    FROM reservations r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN lots l ON r.lot_id = l.id
    WHERE r.reservation_date >= CURDATE()
    ORDER BY r.reservation_date ASC
    LIMIT 6
");
if($upcomingQuery){
    while($row = $upcomingQuery->fetch_assoc()){
        $dash_upcoming_activities[] = $row;
    }
}

// Alerts section.
if($stats_pending > 0) $dash_alerts[] = ['type'=>'warning', 'icon'=>'fa-clock', 'text'=>"$stats_pending pending reservation request(s) need review."];
if($dash_overdue_buyers > 0) $dash_alerts[] = ['type'=>'danger', 'icon'=>'fa-triangle-exclamation', 'text'=>"$dash_overdue_buyers buyer account(s) still have outstanding balances."];
if($stats_avail <= 5) $dash_alerts[] = ['type'=>'warning', 'icon'=>'fa-map', 'text'=>"Available lots are low. Only $stats_avail lot(s) remain."];
if($dash_collection_rate < 80 && $dash_expected_collection > 0) $dash_alerts[] = ['type'=>'warning', 'icon'=>'fa-percent', 'text'=>"Collection rate is only $dash_collection_rate%. Follow up unpaid accounts."];
if(empty($dash_alerts)) $dash_alerts[] = ['type'=>'success', 'icon'=>'fa-circle-check', 'text'=>'No critical alerts. Dashboard status looks good.'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | JEJ Admin</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-geosearch@3.11.0/dist/geosearch.css" />

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

    <style>
        :root {
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

        /* Financial collapsible submenu */
        .menu-dropdown { margin-bottom: 6px; }
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
    flex:1;
    color:inherit;
    text-decoration:none;
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
}

.submenu.show{
    display:block !important;
}
        
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

        /* Stats Grid - Nature Colors */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 35px; }
        .stat-card { background: white; padding: 24px; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); position: relative; overflow: hidden; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .stat-card h2 { font-size: 34px; font-weight: 800; color: var(--dark); margin: 8px 0 0; letter-spacing: -1px; }
        .stat-card small { font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; }
        .stat-icon { position: absolute; right: -15px; bottom: -15px; font-size: 90px; opacity: 0.08; transform: rotate(-10deg); transition: transform 0.3s; }
        .stat-card:hover .stat-icon { transform: rotate(0deg) scale(1.1); }

        .sc-autumn { border-top: 4px solid #d84315; } .sc-autumn .stat-icon { color: #d84315; }
        .sc-wood { border-top: 4px solid #8d6e63; } .sc-wood .stat-icon { color: #8d6e63; } 
        .sc-stone { border-top: 4px solid #546e7a; } .sc-stone .stat-icon { color: #546e7a; } 
        .sc-leaf { border-top: 4px solid #43a047; } .sc-leaf .stat-icon { color: #43a047; } 

        /* Dashboard Widgets */
        .dashboard-widgets {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 24px;
            margin-bottom: 35px;
            align-items: stretch;
        }

        .widget-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            border: 1px solid var(--gray-border);
            box-shadow: var(--shadow-sm);
            min-width: 0;
            overflow: hidden;
        }

        .widget-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 20px;
            border-bottom: 1px solid var(--gray-border);
            padding-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .chart-box {
            position: relative;
            height: clamp(220px, 28vw, 300px);
            width: 100%;
            min-width: 0;
        }

        .chart-box canvas {
            display: block !important;
            width: 100% !important;
            height: 100% !important;
        }

        @media (min-width: 1200px) {
            .dashboard-widgets {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(320px, 0.85fr);
            }
        }

        @media (max-width: 1100px) {
            .dashboard-widgets {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .dashboard-widgets .widget-card:nth-child(3) {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 700px) {
            .dashboard-widgets {
                grid-template-columns: 1fr;
            }

            .dashboard-widgets .widget-card:nth-child(3) {
                grid-column: auto;
            }

            .widget-card {
                padding: 18px;
            }

            .chart-box {
                height: 230px;
            }
        }

        /* Table Styling */
        .table-container { background: white; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 30px; }
        .table-header { padding: 20px 24px; border-bottom: 1px solid var(--gray-border); display: flex; justify-content: space-between; align-items: center; background: #fff; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; background: var(--gray-light); border-bottom: 1px solid var(--gray-border); letter-spacing: 0.5px;}
        td { padding: 16px 24px; border-bottom: 1px solid var(--gray-border); color: #37474f; font-size: 14px; vertical-align: middle; }
        tr:hover td { background: #fdfdfd; }
        tr:last-child td { border-bottom: none; }

        /* Forms & Buttons */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        .input-group { margin-bottom: 18px; }
        .input-group label { display: block; font-size: 13px; font-weight: 600; color: #455a64; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 12px 16px; border: 1px solid #a5d6a7; border-radius: 8px; background: #fff; font-family: inherit; font-size: 14px; transition: all 0.2s; box-sizing: border-box; }
        .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.15); }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='none' stroke='%23455a64' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 35px; }
        
        .section-header { margin: 25px 0 15px 0; font-size: 16px; font-weight: 700; color: var(--dark); border-bottom: 2px solid var(--gray-light); padding-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .box-highlight { background: #e0f2fe; padding: 15px 20px; border-radius: 8px; border: 1px solid #bae6fd; grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; }
        
        .btn-action { padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-block; cursor: pointer; transition: all 0.2s;}
        .btn-edit { background: var(--primary-light); color: var(--primary); border: 1px solid rgba(46, 125, 50, 0.2); }
        .btn-edit:hover { background: #c8e6c9; }
        .btn-delete { background: #ffebee; color: #c62828; border: 1px solid #ffccbc; }
        .btn-delete:hover { background: #ffcdd2; }
        .btn-save { background: var(--primary); color: white; border: none; padding: 14px 28px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px; box-shadow: 0 4px 6px rgba(46, 125, 50, 0.2); transition: all 0.2s;}
        .btn-save:hover { background: var(--dark); box-shadow: 0 6px 8px rgba(27, 94, 32, 0.3); transform: translateY(-1px);}

        /* Miscellaneous */
        #map { height: 350px; width: 100%; border-radius: 12px; border: 1px solid #a5d6a7; z-index: 1; }
        .fc .fc-toolbar-title { font-size: 15px !important; color: var(--dark); font-weight: 700;}
        .fc .fc-button { padding: 4px 10px !important; font-size: 12px !important; background: var(--primary) !important; border: none !important; border-radius: 6px !important;}
        .fc .fc-day-today { background: var(--gray-light) !important; } 
        .fc-event { font-size: 11px !important; padding: 3px 5px !important; border: none !important; border-radius: 4px !important; font-weight: 600; cursor: pointer;}
        .status-badge { padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; letter-spacing: 0.3px; display: inline-block;}



        /* Enhanced Dashboard Cards */
        .enhanced-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:20px; margin-bottom:24px; }
        .mini-metric-card { background:#fff; border:1px solid var(--gray-border); border-radius:16px; box-shadow:var(--shadow-sm); padding:20px; position:relative; overflow:hidden; min-height:118px; }
        .mini-metric-card small { display:block; font-size:11px; font-weight:800; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px; }
        .mini-metric-card strong { display:block; font-size:26px; font-weight:900; color:var(--dark); letter-spacing:-.6px; }
        .mini-metric-card span { display:block; font-size:12px; color:#64748b; font-weight:600; margin-top:5px; }
        .mini-metric-card i { position:absolute; right:18px; bottom:16px; font-size:42px; opacity:.10; color:var(--primary); }
        .dash-section-title { margin:4px 0 14px; font-size:14px; font-weight:900; color:var(--dark); display:flex; align-items:center; gap:8px; text-transform:uppercase; letter-spacing:.4px; }
        .dashboard-main-grid { display:grid; grid-template-columns:minmax(0,1.45fr) minmax(320px,.9fr); gap:24px; margin-bottom:30px; align-items:stretch; }
        .dashboard-side-grid { display:grid; grid-template-columns:1fr; gap:24px; }
        .activity-list, .upcoming-list, .alert-list { display:flex; flex-direction:column; gap:12px; }
        .activity-item, .upcoming-item, .alert-item { border:1px solid #e2e8f0; background:#fff; border-radius:12px; padding:13px 14px; display:flex; gap:12px; align-items:flex-start; }
        .activity-item i, .upcoming-item i, .alert-item i { width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; background:var(--primary-light); color:var(--primary); flex-shrink:0; }
        .activity-item strong, .upcoming-item strong, .alert-item strong { display:block; font-size:13px; color:#1e293b; margin-bottom:3px; }
        .activity-item small, .upcoming-item small, .alert-item small { display:block; font-size:12px; color:#64748b; line-height:1.45; }
        .alert-item.danger i { background:#fee2e2; color:#dc2626; }
        .alert-item.warning i { background:#fef3c7; color:#d97706; }
        .alert-item.success i { background:#dcfce7; color:#16a34a; }
        .progress-wrap { margin-top:14px; }
        .progress-label { display:flex; justify-content:space-between; align-items:center; font-size:12px; color:#64748b; font-weight:800; margin-bottom:8px; }
        .progress-track { width:100%; height:12px; background:#e2e8f0; border-radius:999px; overflow:hidden; }
        .progress-fill { height:100%; background:linear-gradient(90deg,#2e7d32,#66bb6a); border-radius:999px; }
        .quick-stat-row { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
        .quick-stat { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; }
        .quick-stat small { color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; }
        .quick-stat strong { display:block; margin-top:5px; font-size:20px; color:var(--dark); }
        .top-agent-box { display:flex; align-items:center; gap:14px; padding:16px; border:1px solid var(--gray-border); border-radius:14px; background:var(--gray-light); }
        .top-agent-avatar { width:48px; height:48px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:18px; flex-shrink:0; }
        @media(max-width:1100px){ .dashboard-main-grid{grid-template-columns:1fr;} }


        /* CATCHY DASHBOARD REFINEMENT */
        .dashboard-kpi-row { margin-bottom: 24px; }
        .kpi-card { min-height: 138px; }
        .kpi-sub { display:block; margin-top:6px; color:#64748b; font-size:12px; font-weight:700; }

        .hero-finance-card {
            background: linear-gradient(135deg, #1b5e20 0%, #2e7d32 55%, #66bb6a 100%);
            color: #fff;
            border-radius: 22px;
            padding: 26px 30px;
            margin-bottom: 28px;
            box-shadow: 0 18px 36px rgba(46,125,50,.20);
            display:grid;
            grid-template-columns: minmax(260px, 1.2fr) minmax(360px, 1.4fr);
            gap:24px;
            align-items:center;
            position:relative;
            overflow:hidden;
        }
        .hero-finance-card::after {
            content:"";
            position:absolute;
            right:-70px;
            bottom:-90px;
            width:260px;
            height:260px;
            border-radius:50%;
            background:rgba(255,255,255,.12);
        }
        .hero-finance-main small,
        .hero-finance-metrics small {
            display:block;
            font-size:12px;
            font-weight:900;
            letter-spacing:.7px;
            text-transform:uppercase;
            opacity:.85;
        }
        .hero-finance-main strong {
            display:block;
            margin-top:6px;
            font-size:40px;
            font-weight:900;
            letter-spacing:-1.2px;
        }
        .hero-finance-main span {
            display:block;
            margin-top:5px;
            font-weight:700;
            opacity:.9;
        }
        .hero-finance-metrics {
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:12px;
            position:relative;
            z-index:1;
        }
        .hero-finance-metrics > div {
            background:rgba(255,255,255,.92);
            color:#1e293b;
            border-radius:16px;
            padding:15px 16px;
            box-shadow:0 6px 18px rgba(0,0,0,.08);
        }
        .hero-finance-metrics strong {
            display:block;
            margin-top:6px;
            font-size:20px;
            font-weight:900;
        }
        .hero-progress {
            grid-column:1 / -1;
            position:relative;
            z-index:1;
        }
        .hero-progress .progress-label { color:#fff; }
        .hero-progress .progress-track { background:rgba(255,255,255,.26); }
        .hero-progress .progress-fill { background:#fff; }

        .catchy-dashboard-grid {
            display:grid;
            grid-template-columns:minmax(0, 1.55fr) minmax(330px, .75fr);
            gap:24px;
            align-items:start;
            margin-bottom:30px;
        }
        .dashboard-left,
        .dashboard-right {
            display:flex;
            flex-direction:column;
            gap:24px;
            min-width:0;
        }
        .income-feature-card {
            border:1px solid #a5d6a7;
            box-shadow:0 16px 32px rgba(46,125,50,.10);
        }
        .big-chart { height: clamp(300px, 34vw, 440px); }
        .mini-chart { height: 210px; }
        .dashboard-two-col,
        .operations-grid {
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:24px;
        }
        .progress-widget,
        .buyer-summary-card,
        .quick-actions-card,
        .alerts-compact-card,
        .expense-mini-card,
        .activity-feed-card {
            min-height:auto;
        }
        .sales-big-number {
            font-size:48px;
            font-weight:900;
            color:var(--dark);
            line-height:1;
            letter-spacing:-1px;
        }
        .sales-big-number span {
            font-size:22px;
            color:#64748b;
            margin-left:4px;
        }
        .buyer-snapshot {
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:12px;
        }
        .buyer-snapshot > div {
            border:1px solid #e2e8f0;
            background:#f8fafc;
            border-radius:13px;
            padding:14px;
        }
        .buyer-snapshot small {
            display:block;
            font-size:11px;
            font-weight:900;
            color:#64748b;
            text-transform:uppercase;
        }
        .buyer-snapshot strong {
            display:block;
            margin-top:5px;
            color:var(--dark);
            font-size:23px;
            font-weight:900;
        }
        .quick-actions-card {
            background:#fff;
            border:1px solid var(--gray-border);
            border-radius:18px;
            padding:22px;
            box-shadow:var(--shadow-sm);
        }
        .quick-actions-grid {
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:12px;
        }
        .quick-actions-grid a {
            background:var(--gray-light);
            border:1px solid var(--gray-border);
            color:var(--dark);
            border-radius:14px;
            padding:14px 12px;
            text-decoration:none;
            font-size:12px;
            font-weight:900;
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            gap:8px;
            min-height:82px;
            text-align:center;
            transition:.2s;
        }
        .quick-actions-grid a:hover {
            background:var(--primary);
            color:#fff;
            transform:translateY(-2px);
            box-shadow:var(--shadow-md);
        }
        .quick-actions-grid i { font-size:20px; }
        .compact-list .alert-item { padding:12px; }
        .operations-grid { align-items:start; }
        .recent-table-card { margin-bottom:0; overflow-x:auto; }
        .recent-table-card table { min-width:780px; }

        @media(max-width:1200px){
            .catchy-dashboard-grid,
            .operations-grid {
                grid-template-columns:1fr;
            }
            .dashboard-two-col {
                grid-template-columns:1fr;
            }
        }
        @media(max-width:900px){
            .hero-finance-card {
                grid-template-columns:1fr;
            }
            .hero-finance-metrics {
                grid-template-columns:1fr;
            }
        }
        @media(max-width:640px){
            .hero-finance-card { padding:22px; }
            .hero-finance-main strong { font-size:30px; }
            .quick-actions-grid,
            .buyer-snapshot {
                grid-template-columns:1fr;
            }
        }

        /* CLEAN INVENTORY KPI CARDS - WATERMARK ICON STYLE */
        .inventory-kpi-row{
            grid-template-columns:repeat(4,minmax(190px,1fr));
            gap:18px;
            margin-bottom:24px;
        }

        .inventory-kpi-card{
            min-height:128px !important;
            padding:22px 22px 18px !important;
            border-radius:18px !important;
            background:#fff !important;
            box-shadow:0 10px 26px rgba(15,23,42,.045) !important;
            position:relative;
            overflow:hidden;
        }

        .inventory-kpi-card::before{
            content:"";
            position:absolute;
            inset:0;
            background:linear-gradient(135deg, rgba(255,255,255,.92), rgba(248,250,252,.78));
            z-index:0;
        }

        .inventory-kpi-card > *{
            position:relative;
            z-index:1;
        }

        .inventory-kpi-card .kpi-top{
            display:block;
            margin-bottom:12px;
        }

        .inventory-kpi-card .kpi-top small{
            margin:0;
            color:#64748b;
            font-size:12px;
            font-weight:900;
            letter-spacing:.45px;
            text-transform:uppercase;
        }

        .inventory-kpi-card .kpi-icon-badge{
            display:none !important;
        }

        .inventory-kpi-card .kpi-bg-icon{
            position:absolute;
            right:-18px;
            bottom:-22px;
            z-index:0;
            font-size:96px;
            line-height:1;
            opacity:.075;
            transform:rotate(-12deg);
            pointer-events:none;
        }

        .inventory-kpi-card .kpi-number-line{
            display:flex;
            align-items:flex-end;
            gap:8px;
            margin-bottom:6px;
        }

        .inventory-kpi-card h2{
            margin:0 !important;
            font-size:36px !important;
            line-height:1 !important;
            font-weight:900 !important;
            letter-spacing:-.8px;
        }

        .inventory-kpi-card .kpi-ratio{
            font-size:13px;
            font-weight:900;
            color:#64748b;
            margin-bottom:3px;
        }

        .inventory-kpi-card .kpi-sub{
            display:block;
            margin-top:4px;
            font-size:12px;
            color:#64748b;
            font-weight:700;
        }

        .kpi-mini-progress{
            margin-top:12px;
            height:8px;
            border-radius:999px;
            background:#e2e8f0;
            overflow:hidden;
        }

        .kpi-mini-progress span{
            display:block;
            height:100%;
            border-radius:999px;
        }

        .kpi-pending{border-top:4px solid #f59e0b !important;color:#d97706;}
        .kpi-pending h2{color:#d97706 !important;}
        .kpi-pending .kpi-mini-progress span{background:#f59e0b;}

        .kpi-reserved{border-top:4px solid #3b82f6 !important;color:#2563eb;}
        .kpi-reserved h2{color:#2563eb !important;}
        .kpi-reserved .kpi-mini-progress span{background:#3b82f6;}

        .kpi-sold{border-top:4px solid #16a34a !important;color:#15803d;}
        .kpi-sold h2{color:#15803d !important;}
        .kpi-sold .kpi-mini-progress span{background:#16a34a;}

        .kpi-available{border-top:4px solid #0891b2 !important;color:#0e7490;}
        .kpi-available h2{color:#0e7490 !important;}
        .kpi-available .kpi-mini-progress span{background:#0891b2;}

        @media(max-width:1200px){
            .inventory-kpi-row{
                grid-template-columns:repeat(2,minmax(190px,1fr));
            }
        }

        @media(max-width:640px){
            .inventory-kpi-row{
                grid-template-columns:1fr;
            }
        }

        /* HERO FINANCIAL CARD AUTO-FIT FIX */
        .hero-finance-card {
            display: flex !important;
            flex-wrap: wrap !important;
            align-items: stretch !important;
            gap: 18px !important;
            min-width: 0 !important;
            overflow: hidden !important;
        }

        .hero-finance-main {
            flex: 1 1 360px !important;
            min-width: 260px !important;
            max-width: 100% !important;
            position: relative;
            z-index: 1;
        }

        .hero-finance-main strong {
            font-size: clamp(30px, 4.5vw, 56px) !important;
            line-height: 1.05 !important;
            letter-spacing: -1px !important;
            white-space: normal !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
        }

        .hero-finance-metrics {
            flex: 1 1 390px !important;
            min-width: 260px !important;
            max-width: 100% !important;
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)) !important;
            gap: 12px !important;
            align-self: flex-start !important;
        }

        .hero-finance-metrics > div {
            min-width: 0 !important;
            overflow: hidden !important;
        }

        .hero-finance-metrics strong {
            font-size: clamp(16px, 1.75vw, 20px) !important;
            line-height: 1.15 !important;
            white-space: normal !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
        }

        .hero-progress {
            flex: 1 1 100% !important;
            min-width: 0 !important;
            position: relative;
            z-index: 1;
        }

        @media(max-width: 1250px) {
            .hero-finance-card {
                align-items: flex-start !important;
            }
            .hero-finance-main,
            .hero-finance-metrics {
                flex: 1 1 100% !important;
            }
        }

        @media(max-width: 640px) {
            .hero-finance-card {
                padding: 20px !important;
            }
            .hero-finance-main {
                min-width: 0 !important;
            }
            .hero-finance-main strong {
                font-size: clamp(28px, 10vw, 38px) !important;
            }
            .hero-finance-metrics {
                min-width: 0 !important;
                grid-template-columns: 1fr !important;
            }
        }


        /* MODERN EXECUTIVE FINANCIAL SUMMARY */
        .modern-finance-summary{
            display:grid;
            grid-template-columns:minmax(320px,1.35fr) repeat(3,minmax(180px,.65fr));
            gap:18px;
            margin-bottom:28px;
            align-items:stretch;
        }

        .finance-main-card,
        .finance-mini-card{
            background:#fff;
            border:1px solid #d8ead8;
            border-radius:20px;
            box-shadow:0 12px 28px rgba(15,23,42,.06);
            position:relative;
            overflow:hidden;
            min-width:0;
        }

        .finance-main-card{
            padding:26px 28px;
            background:linear-gradient(135deg,#ffffff 0%,#f7fff8 100%);
            border-top:5px solid #16a34a;
        }

        .finance-watermark{
            position:absolute;
            right:-26px;
            bottom:-32px;
            font-size:125px;
            line-height:1;
            color:#16a34a;
            opacity:.055;
            transform:rotate(-12deg);
            pointer-events:none;
        }

        .finance-label,
        .finance-mini-card small{
            display:block;
            font-size:12px;
            font-weight:900;
            color:#64748b;
            text-transform:uppercase;
            letter-spacing:.6px;
        }

        .finance-amount{
            margin-top:8px;
            font-size:clamp(34px,4vw,56px);
            line-height:1;
            font-weight:900;
            color:#0f7a3b;
            letter-spacing:-1.5px;
            overflow-wrap:anywhere;
        }

        .finance-sub{
            margin-top:10px;
            font-size:15px;
            font-weight:800;
            color:#166534;
        }

        .finance-progress-wrap{
            margin-top:20px;
            position:relative;
            z-index:1;
        }

        .finance-progress-label{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            font-size:12px;
            font-weight:800;
            color:#475569;
            margin-bottom:8px;
        }

        .finance-progress-track{
            height:11px;
            background:#e2e8f0;
            border-radius:999px;
            overflow:hidden;
        }

        .finance-progress-fill{
            height:100%;
            background:linear-gradient(90deg,#16a34a,#22c55e);
            border-radius:999px;
        }

        .finance-mini-card{
            padding:22px;
            display:flex;
            flex-direction:column;
            justify-content:center;
            min-height:135px;
        }

        .finance-mini-card strong{
            margin-top:8px;
            font-size:clamp(20px,1.8vw,28px);
            font-weight:900;
            line-height:1.1;
            overflow-wrap:anywhere;
        }

        .finance-mini-card span{
            display:block;
            margin-top:7px;
            font-size:12px;
            font-weight:700;
            color:#64748b;
        }

        .finance-mini-icon{
            position:absolute;
            right:18px;
            bottom:14px;
            font-size:42px;
            opacity:.08;
            pointer-events:none;
        }

        .finance-outstanding{border-top:5px solid #f59e0b;}
        .finance-outstanding strong,
        .finance-outstanding .finance-mini-icon{color:#b45309;}

        .finance-profit{border-top:5px solid #16a34a;}
        .finance-profit strong,
        .finance-profit .finance-mini-icon{color:#15803d;}

        .finance-expense{border-top:5px solid #dc2626;}
        .finance-expense strong,
        .finance-expense .finance-mini-icon{color:#dc2626;}

        @media(max-width:1200px){
            .modern-finance-summary{
                grid-template-columns:1fr 1fr;
            }

            .finance-main-card{
                grid-column:1 / -1;
            }
        }

        @media(max-width:700px){
            .modern-finance-summary{
                grid-template-columns:1fr;
            }

            .finance-main-card,
            .finance-mini-card{
                padding:20px;
            }
        }


        /* AUTO FIT + COLLAPSIBLE SIDEBAR */
        .sidebar,
        .main-panel {
            transition: all 0.25s ease;
        }

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

        .sidebar-toggle:hover {
            background: #c8e6c9;
        }

        body.sidebar-collapsed .sidebar {
            width: 78px;
        }

        body.sidebar-collapsed .main-panel {
            margin-left: 78px;
            width: calc(100% - 78px);
        }

        body.sidebar-collapsed .brand-box {
            justify-content: center;
            padding: 25px 10px;
        }

        body.sidebar-collapsed .brand-box div,
        body.sidebar-collapsed .menu-text,
        body.sidebar-collapsed .finance-main-link span,
        body.sidebar-collapsed .sidebar-menu small,
        body.sidebar-collapsed .submenu,
        body.sidebar-collapsed .submenu-toggle-btn {
            display: none !important;
        }

        body.sidebar-collapsed .menu-link {
            justify-content: center;
            padding: 14px 10px;
            gap: 0;
            border-left: 0 !important;
        }

        body.sidebar-collapsed .menu-link i {
            font-size: 18px;
            width: 22px;
        }

        body.sidebar-collapsed .finance-main-link {
            justify-content: center;
            gap: 0;
        }

        @media (max-width: 768px) {
            .sidebar {
                left: -260px;
            }

            .main-panel,
            body.sidebar-collapsed .main-panel {
                margin-left: 0;
                width: 100%;
            }

            body.sidebar-open .sidebar {
                left: 0;
            }

            body.sidebar-open::after {
                content: "";
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.35);
                z-index: 90;
            }

            body.sidebar-open .sidebar {
                z-index: 101;
            }

            .top-header {
                padding: 15px 18px;
                gap: 12px;
            }

            .header-title h1 {
                font-size: 18px;
            }

            .header-title p {
                display: none;
            }

            .content-area {
                padding: 20px;
            }

            .profile-info {
                display: none;
            }

            .stats-grid,
            .dashboard-widgets,
            .form-grid,
            .form-grid-3 {
                grid-template-columns: 1fr !important;
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

    <!-- CASHIER ONLY -->
    <?php if($_SESSION['role'] === 'CASHIER'): ?>

        <a href="financial.php" class="menu-link">
            <i class="fa-solid fa-coins"></i>
            <span class="menu-text">Financials</span>
        </a>

        <a href="transaction_history.php" class="menu-link">
            <i class="fa-solid fa-list-ul"></i>
            <span class="menu-text">Ledger List</span>
        </a>

        <a href="payment_tracking.php" class="menu-link">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            <span class="menu-text">Payment Tracking</span>
        </a>

        <a href="daily_reconciliation.php" class="menu-link">
            <i class="fa-solid fa-scale-balanced"></i>
            <span class="menu-text">Daily Reconciliation</span>
        </a>

        <a href="verify_payments.php" class="menu-link">
            <i class="fa-solid fa-circle-check"></i>
            <span class="menu-text">Verify Payments</span>
        </a>

    <?php else: ?>

        <!-- NORMAL ADMIN / MANAGER MENU -->
        <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-bottom: 12px; letter-spacing: 0.5px;">MAIN MENU</small>
        <a href="admin.php" class="menu-link <?= ($view == 'dashboard') ? 'active' : '' ?>">
        <i class="fa-solid fa-chart-pie"></i>
            <span class="menu-text">Dashboard</span>
        </a>

        <a href="reservation.php" class="menu-link <?= ($view == 'reservations') ? 'active' : '' ?>">
            <i class="fa-solid fa-file-signature"></i>
            <span class="menu-text">Reservations</span>
        </a>

        <a href="master_list.php" class="menu-link">
            <i class="fa-solid fa-map-location-dot"></i>
            <span class="menu-text">Master List / Map</span>
        </a>

        <a href="admin.php?view=inventory" class="menu-link <?= ($view == 'inventory') ? 'active' : '' ?>">
            <i class="fa-solid fa-plus-circle"></i>
            <span class="menu-text">Add Property</span>
        </a>
<!-- FINANCIAL MENU -->
<div class="menu-dropdown">

    <div class="menu-link dropdown-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['financial.php','verify_payments.php','payment_tracking.php','transaction_history.php','daily_reconciliation.php','pricing_matrix.php']) ? 'active' : '' ?>">

        <a href="financial.php" class="finance-main-link">
            <i class="fa-solid fa-coins"></i>
            <span>Financials</span>
        </a>

        <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)">
            <i class="fa-solid fa-chevron-down dropdown-arrow" id="financeArrow"></i>
        </button>

    </div>

    <div id="financeSubMenu"
class="submenu <?= in_array(basename($_SERVER['PHP_SELF']), ['financial.php','verify_payments.php','payment_tracking.php','transaction_history.php','daily_reconciliation.php','pricing_matrix.php']) ? 'show' : '' ?>">

        <a href="verify_payments.php" class="menu-link submenu-link">
            <i class="fa-solid fa-circle-check"></i> <span class="menu-text">Verify Payments</span>
        </a>

        <a href="payment_tracking.php" class="menu-link submenu-link">
            <i class="fa-solid fa-file-invoice-dollar"></i> <span class="menu-text">Payment Tracking</span>
        </a>

        <a href="transaction_history.php" class="menu-link submenu-link">
            <i class="fa-solid fa-list-ul"></i> <span class="menu-text">Ledger List</span>
        </a>

        <a href="daily_reconciliation.php" class="menu-link submenu-link">
            <i class="fa-solid fa-scale-balanced"></i> <span class="menu-text">Daily Reconciliation</span>
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
        
        <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">CUSTOMERS</small>

            <a href="buyers.php" class="menu-link <?= ($currentPage == 'buyers.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i>
                <span class="menu-text">Buyers</span>
            </a>

        <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">MANAGEMENT</small>
            
            <a href="agent_tracking.php" class="menu-link"><i class="fa-solid fa-user-tie"></i><span class="menu-text">Agent Tracking</span></a>
            <a href="inquiries.php" class="menu-link"><i class="fa-solid fa-envelope-open-text"></i><span class="menu-text">Inquiries</span></a>
            <a href="accounts.php" class="menu-link"><i class="fa-solid fa-users-gear"></i><span class="menu-text">Accounts</span></a>
            <a href="delete_history.php" class="menu-link"><i class="fa-solid fa-trash-can"></i><span class="menu-text">Delete History</span></a>
            
            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">SYSTEM</small>
            <a href="index.php" class="menu-link" target="_blank"><i class="fa-solid fa-globe"></i><span class="menu-text">View Website</span></a>

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
                    <h1><?= $view == 'dashboard' ? 'Overview Dashboard' : 'Property Inventory Management' ?></h1>
                    <p><?= $view == 'dashboard' ? "Welcome back! Here's what's happening with your estate today." : "Configure land pricing, rapidly bulk add properties, and view inventory." ?></p>
                </div>
            </div>
            
            <?php include 'includes/profile_dropdown.php'; ?>
        </div>

        <div class="content-area">
            <?php if($alert_msg): ?>
                <div style="padding: 16px 20px; border-radius: 10px; margin-bottom: 25px; font-weight: 500; font-size: 14px; background: <?= $alert_type=='success' ? '#e8f5e9' : '#fbe9e7' ?>; color: <?= $alert_type=='success' ? '#2e7d32' : '#d84315' ?>; border: 1px solid <?= $alert_type=='success' ? '#c8e6c9' : '#ffccbc' ?>; box-shadow: var(--shadow-sm);">
                    <i class="fa-solid <?= $alert_type=='success'?'fa-check-circle':'fa-exclamation-circle' ?>" style="margin-right: 10px;"></i>
                    <?= $alert_msg ?>
                </div>
            <?php endif; ?>

            <?php if($view == 'dashboard'): ?>

                <!-- EXECUTIVE KPI ROW -->
                <?php
                    $pending_percent = $stats_pending > 0 ? min(100, max(8, $stats_pending * 10)) : 0;
                    $reserved_percent = $dash_total_lots > 0 ? round(($stats_reserved / $dash_total_lots) * 100, 1) : 0;
                    $sold_percent = $dash_total_lots > 0 ? round(($stats_sold / $dash_total_lots) * 100, 1) : 0;
                    $available_percent = $dash_total_lots > 0 ? round(($stats_avail / $dash_total_lots) * 100, 1) : 0;
                ?>
                <div class="stats-grid dashboard-kpi-row inventory-kpi-row">
                    <div class="inventory-kpi-card kpi-pending">

    <i class="fa-solid fa-clock kpi-bg-icon"></i>

    <div class="kpi-top">
        <small>PENDING REQUESTS</small>
    </div>

    <div class="kpi-number-line">
        <h2><?= $stats_pending ?></h2>
    </div>

    <span class="kpi-sub">
        <?= $stats_pending > 0 ? 'Needs approval' : 'No pending approvals' ?>
    </span>

    <div class="kpi-mini-progress">
        <span style="width:<?= min($stats_pending * 10,100) ?>%"></span>
    </div>

</div>

                    <div class="inventory-kpi-card kpi-reserved">

    <i class="fa-solid fa-file-signature kpi-bg-icon"></i>

    <div class="kpi-top">
        <small>RESERVED PROPERTIES</small>
    </div>

    <div class="kpi-number-line">
        <h2><?= $stats_reserved ?></h2>
        <span class="kpi-ratio">/<?= $dash_total_lots ?></span>
    </div>

    <span class="kpi-sub">
        <?= round(($stats_reserved / max($dash_total_lots,1))*100) ?>% active accounts
    </span>

    <div class="kpi-mini-progress">
        <span style="width:<?= round(($stats_reserved/max($dash_total_lots,1))*100) ?>%"></span>
    </div>

</div>

                    <div class="inventory-kpi-card kpi-sold">

    <i class="fa-solid fa-circle-check kpi-bg-icon"></i>

    <div class="kpi-top">
        <small>SOLD UNITS</small>
    </div>

    <div class="kpi-number-line">
        <h2><?= $stats_sold ?></h2>
        <span class="kpi-ratio">/<?= $dash_total_lots ?></span>
    </div>

    <span class="kpi-sub">
        <?= $dash_sales_percent ?>% inventory sold
    </span>

    <div class="kpi-mini-progress">
        <span style="width:<?= $dash_sales_percent ?>%"></span>
    </div>

</div>

                    <div class="inventory-kpi-card kpi-available">

    <i class="fa-solid fa-map-location-dot kpi-bg-icon"></i>

    <div class="kpi-top">
        <small>AVAILABLE LOTS</small>
    </div>

    <div class="kpi-number-line">
        <h2><?= $stats_avail ?></h2>
        <span class="kpi-ratio">/<?= $dash_total_lots ?></span>
    </div>

    <span class="kpi-sub">
        <?= round(($stats_avail / max($dash_total_lots,1))*100) ?>% ready for selling
    </span>

    <div class="kpi-mini-progress">
        <span style="width:<?= round(($stats_avail/max($dash_total_lots,1))*100) ?>%"></span>
    </div>

</div>
                </div>
                <!-- MODERN EXECUTIVE FINANCIAL SUMMARY -->
                <div class="modern-finance-summary">

                    <div class="finance-main-card">
                        <div class="finance-watermark">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>

                        <div class="finance-label">Total Verified Income</div>
                        <div class="finance-amount">₱<?= number_format($dash_total_income, 2) ?></div>
                        <div class="finance-sub">Collection rate: <?= $dash_collection_rate ?>%</div>

                        <div class="finance-progress-wrap">
                            <div class="finance-progress-label">
                                <span>Collection Progress</span>
                                <strong><?= $dash_collection_rate ?>%</strong>
                            </div>
                            <div class="finance-progress-track">
                                <div class="finance-progress-fill" style="width:<?= min(100, $dash_collection_rate) ?>%;"></div>
                            </div>
                        </div>
                    </div>

                    <div class="finance-mini-card finance-outstanding">
                        <div class="finance-mini-icon">
                            <i class="fa-solid fa-wallet"></i>
                        </div>
                        <small>Outstanding</small>
                        <strong>₱<?= number_format($dash_outstanding_balance, 2) ?></strong>
                        <span>Uncollected balance</span>
                    </div>

                    <div class="finance-mini-card finance-profit">
                        <div class="finance-mini-icon">
                            <i class="fa-solid fa-arrow-trend-up"></i>
                        </div>
                        <small>Net Profit</small>
                        <strong>₱<?= number_format($dash_net_profit, 2) ?></strong>
                        <span>Income minus expenses</span>
                    </div>

                    <div class="finance-mini-card finance-expense">
                        <div class="finance-mini-icon">
                            <i class="fa-solid fa-arrow-trend-down"></i>
                        </div>
                        <small>Expenses</small>
                        <strong>₱<?= number_format($dash_total_expenses, 2) ?></strong>
                        <span>Bills and checks</span>
                    </div>

                </div>

<!-- MAIN DASHBOARD LAYOUT -->
                <div class="catchy-dashboard-grid">
                    <div class="dashboard-left">

                        <div class="widget-card income-feature-card">
                            <div class="widget-title">
                                <span><i class="fa-solid fa-chart-column" style="color:#43a047;margin-right:8px;"></i> Monthly Income Trend</span>
                                <a href="financial.php" style="font-size:12px;color:var(--primary);text-decoration:none;font-weight:700;">Details <i class="fa-solid fa-arrow-right"></i></a>
                            </div>
                            <div class="chart-box big-chart"><canvas id="incomeChart"></canvas></div>
                        </div>

                        <div class="dashboard-two-col">
                            <div class="widget-card progress-widget">
                                <div class="widget-title"><span><i class="fa-solid fa-map-location-dot" style="color:var(--primary);margin-right:8px;"></i> Sales Progress</span></div>
                                <div class="sales-big-number"><?= number_format($stats_sold) ?><span>/<?= number_format($dash_total_lots) ?></span></div>
                                <div class="progress-wrap">
                                    <div class="progress-label"><span>Inventory Sold</span><span><?= $dash_sales_percent ?>%</span></div>
                                    <div class="progress-track"><div class="progress-fill" style="width:<?= min(100, $dash_sales_percent) ?>%;"></div></div>
                                </div>
                            </div>

                            <div class="widget-card buyer-summary-card">
                                <div class="widget-title"><span><i class="fa-solid fa-users" style="color:var(--primary);margin-right:8px;"></i> Buyers Snapshot</span></div>
                                <div class="buyer-snapshot">
                                    <div><small>Total</small><strong><?= number_format($dash_total_buyers) ?></strong></div>
                                    <div><small>Active</small><strong><?= number_format($dash_active_buyers) ?></strong></div>
                                    <div><small>Fully Paid</small><strong><?= number_format($dash_fully_paid_buyers) ?></strong></div>
                                    <div><small>With Balance</small><strong style="color:<?= $dash_overdue_buyers > 0 ? '#dc2626' : '#166534' ?>;"><?= number_format($dash_overdue_buyers) ?></strong></div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="dashboard-right">

                        <div class="quick-actions-card">
                            <div class="widget-title"><span><i class="fa-solid fa-bolt" style="color:#f59e0b;margin-right:8px;"></i> Quick Actions</span></div>
                            <div class="quick-actions-grid">
                                <a href="admin.php?view=inventory"><i class="fa-solid fa-plus-circle"></i><span>Add Property</span></a>
                                <a href="manual_buyer_entry.php"><i class="fa-solid fa-user-plus"></i><span>Add Buyer</span></a>
                                <a href="reservation.php"><i class="fa-solid fa-file-signature"></i><span>Reservations</span></a>
                                <a href="verify_payments.php"><i class="fa-solid fa-circle-check"></i><span>Verify Payment</span></a>
                            </div>
                        </div>

                        <div class="widget-card alerts-compact-card">
                            <div class="widget-title"><span><i class="fa-solid fa-bell" style="color:#f59e0b;margin-right:8px;"></i> Priority Alerts</span></div>
                            <div class="alert-list compact-list">
                                <?php foreach(array_slice($dash_alerts, 0, 3) as $al): ?>
                                    <div class="alert-item <?= htmlspecialchars($al['type']) ?>">
                                        <i class="fa-solid <?= htmlspecialchars($al['icon']) ?>"></i>
                                        <div>
                                            <strong><?= ucfirst(htmlspecialchars($al['type'])) ?></strong>
                                            <small><?= htmlspecialchars($al['text']) ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="widget-card expense-mini-card">
                            <div class="widget-title">
                                <span><i class="fa-solid fa-money-check-dollar" style="color:#d84315;margin-right:8px;"></i> Expense Trend</span>
                            </div>
                            <div class="chart-box mini-chart"><canvas id="expenseChart"></canvas></div>
                        </div>

                    </div>
                </div>

                <!-- OPERATIONS SECTION -->
                <div class="operations-grid">
                    <div class="table-container recent-table-card">
                        <div class="table-header">
                            <h3 style="margin:0;font-size:16px;font-weight:800;color:var(--dark);"><i class="fa-solid fa-list-check" style="color:var(--primary);margin-right:8px;"></i> Recent Reservations</h3>
                            <a href="reservation.php" style="font-size:13px;font-weight:700;color:var(--primary);text-decoration:none;">View All <i class="fa-solid fa-arrow-right" style="margin-left:4px;"></i></a>
                        </div>
                        <table>
                            <thead>
                                <tr><th>Date</th><th>Buyer</th><th>Property</th><th>Price</th><th>Status</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php if($recent_reservations && $recent_reservations->num_rows > 0): ?>
                                    <?php while($res = $recent_reservations->fetch_assoc()): ?>
                                    <tr>
                                        <td style="font-weight:600;color:var(--text-muted);"><?= date('M d, Y', strtotime($res['reservation_date'])) ?></td>
                                        <td style="font-weight:700;color:#263238;"><?= htmlspecialchars($res['fullname']) ?></td>
                                        <td style="font-weight:700;color:var(--primary);">Block <?= $res['block_no'] ?>, Lot <?= $res['lot_no'] ?></td>
                                        <td style="font-weight:800;">₱<?= number_format($res['total_price']) ?></td>
                                        <td>
                                            <?php 
                                                $status_colors = ['PENDING' => ['bg'=>'#fff3e0', 'col'=>'#e65100'], 'APPROVED' => ['bg'=>'#e8f5e9', 'col'=>'#2e7d32'], 'REJECTED' => ['bg'=>'#ffebee', 'col'=>'#c62828']];
                                                $sc = $status_colors[$res['status']] ?? ['bg'=>'#eceff1', 'col'=>'#546e7a'];
                                            ?>
                                            <span class="status-badge" style="background: <?= $sc['bg'] ?>; color: <?= $sc['col'] ?>;"><?= $res['status'] ?></span>
                                        </td>
                                        <td><a href="reservation.php?status=<?= $res['status'] ?>" class="btn-action btn-edit"><i class="fa-solid fa-pen-to-square" style="margin-right:4px;"></i> Manage</a></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);font-weight:500;">No recent reservations available.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="widget-card activity-feed-card">
                        <div class="widget-title">
                            <span><i class="fa-solid fa-clock-rotate-left" style="color:var(--primary);margin-right:8px;"></i> Recent Activity</span>
                            <a href="audit_logs.php" style="font-size:12px;color:var(--primary);text-decoration:none;font-weight:700;">View Logs <i class="fa-solid fa-arrow-right"></i></a>
                        </div>
                        <div class="activity-list">
                            <?php if(!empty($dash_recent_activity)): ?>
                                <?php foreach(array_slice($dash_recent_activity, 0, 4) as $act): ?>
                                    <div class="activity-item">
                                        <i class="fa-solid fa-check"></i>
                                        <div>
                                            <strong><?= htmlspecialchars($act['action'] ?? 'Activity') ?></strong>
                                            <small><?= htmlspecialchars($act['fullname'] ?? 'System') ?> · <?= htmlspecialchars($act['details'] ?? '') ?> · <?= date('M d, Y h:i A', strtotime($act['created_at'])) ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="activity-item"><i class="fa-solid fa-circle-info"></i><div><strong>No recent activity</strong><small>System logs will appear here.</small></div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const formatCurrency = (val) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 0 }).format(val);

                    const commonOptions = { 
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: function(context) { return ' Total: ' + formatCurrency(context.raw); } } }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                border: {display: false},
                                grid: {color: '#eceff1'},
                                ticks: {
                                    font: {family: 'Inter', size: 10},
                                    color: '#78909c',
                                    callback: function(value) {
                                        if(value >= 1000000) return '₱' + (value/1000000).toFixed(1) + 'M';
                                        if(value >= 1000) return '₱' + (value/1000).toFixed(1) + 'K';
                                        return '₱' + value;
                                    }
                                }
                            },
                            x: {
                                grid: { display: false },
                                ticks: { font: {family: 'Inter', size: 11}, color: '#607d8b', maxRotation: 25, minRotation: 0 }
                            }
                        }
                    };

                    const ctxIncome = document.getElementById('incomeChart').getContext('2d');
                    window.incomeChartInstance = new Chart(ctxIncome, {
                        type: 'bar',
                        data: {
                            labels: <?= json_encode($income_months) ?>,
                            datasets: [{
                                label: 'Income',
                                data: <?= json_encode($income_totals) ?>,
                                backgroundColor: 'rgba(46, 125, 50, 0.90)',
                                hoverBackgroundColor: 'rgba(27, 94, 32, 1)',
                                borderRadius: 10,
                                barPercentage: 0.60,
                                categoryPercentage: 0.65,
                                maxBarThickness: 46
                            }]
                        },
                        options: commonOptions
                    });

                    const ctxExpense = document.getElementById('expenseChart').getContext('2d');
                    window.expenseChartInstance = new Chart(ctxExpense, {
                        type: 'line',
                        data: {
                            labels: <?= json_encode($expense_months) ?>,
                            datasets: [{
                                label: 'Expenses',
                                data: <?= json_encode($expense_totals) ?>,
                                backgroundColor: 'rgba(216, 67, 21, 0.10)',
                                borderColor: 'rgba(216, 67, 21, 0.95)',
                                borderWidth: 3,
                                tension: 0.35,
                                fill: true,
                                pointRadius: 4,
                                pointBackgroundColor: '#fff',
                                pointBorderColor: 'rgba(216, 67, 21, 1)',
                                pointHoverRadius: 6
                            }]
                        },
                        options: commonOptions
                    });
                });
                </script>

            <?php elseif($view == 'inventory'): ?>
                <div class="table-container" style="padding: 0; overflow: visible; background: transparent; border: none; box-shadow: none;">
                    
                    <div style="background: white; padding: 35px; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); margin-bottom: 30px;">
                        
                        <div style="margin-bottom: 25px; border-bottom: 1px solid var(--gray-border); padding-bottom: 15px;">
                            <span style="font-size: 18px; font-weight: 700; color: var(--dark);"><i class="fa-solid <?= $edit_mode ? 'fa-pen-to-square' : 'fa-plus-circle' ?>" style="color: var(--primary); margin-right: 8px;"></i> <?= $edit_mode ? 'Edit Property Details' : 'Add New Property' ?></span>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="lot_id" value="<?= $edit_mode ? $edit_data['id'] : '' ?>">
                            <input type="hidden" name="current_image" value="<?= $edit_mode ? $edit_data['lot_image'] : '' ?>">
                            <input type="hidden" name="latitude" id="lat" value="<?= $edit_mode ? $edit_data['latitude'] : '' ?>">
                            <input type="hidden" name="longitude" id="lng" value="<?= $edit_mode ? $edit_data['longitude'] : '' ?>">
                            
                            <?php if(!$edit_mode): ?>
                            <div class="input-group" id="entryModeSection" style="grid-column: 1 / -1; margin-bottom: 25px; background: #f8fafc; padding: 18px 24px; border-radius: 10px; border: 1px dashed #94a3b8;">
                                <label style="margin-bottom: 12px; font-size: 15px; color: #0f172a;"><i class="fa-solid fa-layer-group" style="color: var(--primary); margin-right: 6px;"></i> Select Entry Mode</label>
                                <div style="display: flex; gap: 30px;">
                                    <label style="cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 600; color: #334155;">
                                        <input type="radio" name="entry_mode" value="single" checked onchange="toggleEntryMode()" style="width: 18px; height: 18px; accent-color: var(--primary);"> 
                                        Single Lot Entry
                                    </label>
                                    <label style="cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 600; color: #334155;">
                                        <input type="radio" name="entry_mode" value="bulk" onchange="toggleEntryMode()" style="width: 18px; height: 18px; accent-color: var(--primary);"> 
                                        Bulk Entry (Multiple Lots)
                                    </label>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="section-header"><i class="fa-solid fa-circle-info" style="color: var(--primary);"></i> Location Information</div>
                            <div class="form-grid">
                                <div class="input-group" style="grid-column: 1 / -1;">
                                    <label>Location / Area Database</label>
                                    <select name="location" id="locationSelect" class="form-control" onchange="checkNewLocation(); calcTotal();">
                                        <option value="">-- Select Existing Area --</option>
                                        <?php 
                                        $locQuery = $conn->query("SELECT DISTINCT location FROM lots WHERE location IS NOT NULL AND location != '' ORDER BY location ASC");
                                        while($locRow = $locQuery->fetch_assoc()):
                                            $locVal = htmlspecialchars($locRow['location']);
                                        ?>
                                            <option value="<?= $locVal ?>" <?= ($edit_mode && ($edit_data['location']??'') == $locVal) ? 'selected' : '' ?>><?= $locVal ?></option>
                                        <?php endwhile; ?>
                                        <option value="NEW_AREA" style="font-weight: bold; color: var(--primary);">+ Add New Area / Municipality...</option>
                                    </select>
                                    <input type="text" name="new_location" id="newLocationInput" class="form-control" placeholder="Type new area name here..." style="display: none; margin-top: 10px; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);">
                                    <small id="newAreaHelperText" style="display:none; color: #0284c7; font-weight: 600; margin-top: 8px;"><i class="fa-solid fa-circle-info"></i> The form below has been hidden. You are now only creating an area category.</small>
                                </div>
                            </div>

                            <div id="lotDetailsWrapper">
                                <div class="section-header" style="margin-top: 20px;"><i class="fa-solid fa-tags" style="color: var(--primary);"></i> Property Classification</div>
                                <div class="form-grid">
                                    <div class="input-group">
                                        <label>Property Type</label>
                                        <select name="property_type" class="form-control">
                                            <option value="Lot" <?= ($edit_mode && ($edit_data['property_type']??'')=='Lot')?'selected':'' ?>>Lot</option>
                                            <option value="Subdivision" <?= ($edit_mode && ($edit_data['property_type']??'')=='Subdivision')?'selected':'' ?>>Subdivision</option>
                                            <option value="Land" <?= ($edit_mode && ($edit_data['property_type']??'')=='Land')?'selected':'' ?>>Land</option>
                                            <option value="Farm" <?= ($edit_mode && ($edit_data['property_type']??'')=='Farm')?'selected':'' ?>>Farm</option>
                                            <option value="Shop" <?= ($edit_mode && ($edit_data['property_type']??'')=='Shop')?'selected':'' ?>>Shop</option>
                                            <option value="Business" <?= ($edit_mode && ($edit_data['property_type']??'')=='Business')?'selected':'' ?>>Business</option>
                                        </select>
                                    </div>
                                    <div class="input-group">
                                        <label>Current Status</label>
                                        <select name="status" class="form-control">
                                            <option value="AVAILABLE" <?= ($edit_mode && $edit_data['status']=='AVAILABLE')?'selected':'' ?>>Available</option>
                                            <option value="RESERVED" <?= ($edit_mode && $edit_data['status']=='RESERVED')?'selected':'' ?>>Reserved</option>
                                            <option value="SOLD" <?= ($edit_mode && $edit_data['status']=='SOLD')?'selected':'' ?>>Sold</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="section-header" style="margin-top: 30px;"><i class="fa-solid fa-calculator" style="color: var(--primary);"></i> Lot Detail & Installment Terms</div>
                                <div class="form-grid-3">
                                    <div class="input-group">
                                        <label>Block</label>
                                        <input type="text" name="block_no" id="block_no" class="form-control req-field" placeholder="e.g., 5" value="<?= $edit_mode?$edit_data['block_no']:'' ?>" required>
                                    </div>
                                    
                                    <div class="input-group single-mode-field">
                                        <label>Lot No.</label>
                                        <input type="text" name="lot_no" id="lot_no" class="form-control req-field" placeholder="e.g., 12" value="<?= $edit_mode?$edit_data['lot_no']:'' ?>" <?= !$edit_mode ? 'required' : '' ?>>
                                    </div>
                                    <?php if(!$edit_mode): ?>
                                    <div class="input-group bulk-mode-field" style="display: none;">
                                        <label>Lot Range (Start to End)</label>
                                        <div style="display: flex; gap: 10px;">
                                            <input type="number" name="start_lot" id="start_lot" class="form-control req-field" placeholder="Start (e.g. 1)">
                                            <input type="number" name="end_lot" id="end_lot" class="form-control req-field" placeholder="End (e.g. 20)">
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <div class="input-group">
                                        <label>Lot Area (sqm) <?= !$edit_mode ? '<span class="bulk-mode-field" style="display:none; color:#64748b; font-weight:normal;">(Applied to all in range)</span>' : '' ?></label>
                                        <input type="number" name="area" id="area" class="form-control req-field" placeholder="0" value="<?= $edit_mode?$edit_data['area']:'' ?>" required oninput="calcTotal()">
                                    </div>
                                    
                                    <div class="input-group">
                                        <label>Selling Price / SQM <span style="color:#64748b; font-weight:500;">(auto based on area price board)</span></label>
                                        <input type="number" id="base_price" class="form-control req-field" placeholder="0.00" value="<?= $edit_mode?$edit_data['price_per_sqm']:'' ?>" required oninput="calcTotal()">
                                    </div>
                                    <div class="input-group">
                                        <label>Classification</label>
                                        <select name="lot_class" id="lot_class" class="form-control" onchange="calcTotal()">
                                            <option value="Inner Lot">Inner Lot</option>
                                            <option value="Front Lot">Front Lot</option>
                                        </select>
                                    </div>
                                    <div class="input-group">
                                        <label>Installment Type</label>
                                        <select name="terms" id="terms" class="form-control" onchange="calcTotal()">
                                            <option value="0">Cash Payment</option>
                                            <option value="1">1 Year Installment</option>
                                            <option value="2">2 Years Installment</option>
                                            <option value="3">3 Years Installment</option>
                                        </select>
                                    </div>

                                    <input type="hidden" name="price_sqm" id="price_sqm" value="<?= $edit_mode?$edit_data['price_per_sqm']:'' ?>">
                                    <input type="hidden" name="total_price" id="total" value="<?= $edit_mode?$edit_data['total_price']:'' ?>">

                                    <div class="box-highlight">
                                        <div style="flex: 1;">
                                            <span style="display: block; font-size: 11px; font-weight: 700; color: #0369a1; text-transform: uppercase; letter-spacing: 0.5px;">Selling Price / SQM</span>
                                            <span id="display_price_sqm" style="display: block; font-size: 20px; font-weight: 800; color: #0284c7; margin-top: 4px;">₱ 0.00</span>
                                        </div>
                                        <div style="flex: 1; border-left: 1px solid #bae6fd; padding-left: 20px;">
                                            <span style="display: block; font-size: 11px; font-weight: 700; color: #0369a1; text-transform: uppercase; letter-spacing: 0.5px;">Total Contract Price</span>
                                            <span id="display_total" style="display: block; font-size: 20px; font-weight: 800; color: #0284c7; margin-top: 4px;">₱ 0.00</span>
                                        </div>
                                        <div style="flex: 1.5; border-left: 1px solid #bae6fd; padding-left: 20px;">
                                            <span style="display: block; font-size: 11px; font-weight: 700; color: #0369a1; text-transform: uppercase; letter-spacing: 0.5px;">Monthly Amortization</span>
                                            <span id="display_monthly" style="display: block; font-size: 20px; font-weight: 800; color: #0284c7; margin-top: 4px;">Spot Cash</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="section-header" style="margin-top: 30px;"><i class="fa-solid fa-images" style="color: var(--primary);"></i> Media & Details</div>
                                
                                <div class="input-group">
                                    <label>Property Overview & Description</label>
                                    <textarea name="property_overview" class="form-control" rows="4" placeholder="Describe the property, nearby landmarks, or specific features..."><?= $edit_mode ? ($edit_data['property_overview'] ?? '') : '' ?></textarea>
                                </div>

                                <div class="form-grid">
                                    <div class="input-group">
                                        <label>Main Property Image</label>
                                        <input type="file" name="lot_image" class="form-control" style="padding: 9px 16px;">
                                        <?php if($edit_mode && $edit_data['lot_image']): ?>
                                            <small style="display:block; margin-top:6px; color: var(--text-muted); font-weight: 500;">Current File: <?= $edit_data['lot_image'] ?></small>
                                        <?php endif; ?>
                                    </div>

                                    <div class="input-group">
                                        <label>Other Angles / Gallery (Multiple)</label>
                                        <input type="file" name="gallery[]" class="form-control" multiple accept="image/*" style="padding: 9px 16px;">
                                        <small style="color: var(--text-muted); font-weight: 500; display: block; margin-top: 6px;">Hold Ctrl/Cmd to select multiple images.</small>
                                    </div>
                                </div>

                                <?php if($edit_mode): ?>
                                    <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
                                        <?php 
                                        $gal_res = $conn->query("SELECT * FROM lot_gallery WHERE lot_id='$id'");
                                        while($img = $gal_res->fetch_assoc()):
                                        ?>
                                            <div style="width: 70px; height: 70px; border-radius: 8px; overflow: hidden; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm);">
                                                <img src="<?= htmlspecialchars(jej_lot_image_url($img['image_path'] ?? 'default_lot.jpg')) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="input-group single-mode-field" style="margin-top: 10px;">
                                    <label><i class="fa-solid fa-map-pin" style="color:#d84315; margin-right: 5px;"></i> Pin Location (Search or Click)</label>
                                    <div id="map"></div>
                                    <small style="color: var(--text-muted); display: block; margin-top: 8px; font-weight: 500;">Use the search icon (top-left) to find a city, or click anywhere to pin manually.</small>
                                </div>
                            </div>
                            <div style="margin-top: 35px; padding-top: 20px; border-top: 1px solid var(--gray-border); text-align: right;">
                                <?php if($edit_mode): ?>
                                    <a href="admin.php?view=inventory" class="btn-action" style="background:#eceff1; color:#546e7a; margin-right:12px; padding: 12px 24px; font-size: 14px; border: 1px solid #cfd8dc;">Cancel Edit</a>
                                <?php endif; ?>
                                <button type="submit" name="save_lot" class="btn-save">
                                    <i class="fa-solid fa-cloud-arrow-up" style="margin-right: 6px;"></i> <span id="submitBtnText"><?= $edit_mode ? 'Update Property' : 'Save Property' ?></span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <?php if(!$edit_mode): ?>
                        <div class="directory-wrapper" style="margin-bottom: 30px;">
                            <div style="background: white; border-radius: 12px; margin-bottom: 20px; padding: 20px 24px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm);">
                                <span style="font-size: 16px; font-weight: 700; color: var(--dark);"><i class="fa-solid fa-list-ul" style="color: var(--primary); margin-right: 8px;"></i> Existing Property Inventory (Grouped by Area)</span>
                            </div>

                            <?php 
                            // Group lots by location
                            $groupedLots = [];
                            foreach($lots as $l) {
                                $lName = !empty($l['location']) ? $l['location'] : 'Unassigned';
                                if(!isset($groupedLots[$lName])) {
                                    $groupedLots[$lName] = [];
                                }
                                $groupedLots[$lName][] = $l;
                            }
                            ksort($groupedLots);

                            foreach($groupedLots as $locName => $locLots): 
                                // Filter out the "dummy" lots used to create Area labels
                                $validLots = array_filter($locLots, function($l) { 
                                    return !empty($l['block_no']) || !empty($l['lot_no']) || $l['area'] > 0; 
                                });
                                $availLots = array_filter($validLots, function($l) { 
                                    return strtoupper($l['status']) === 'AVAILABLE'; 
                                });
                                $locId = md5($locName);
                            ?>
                            
                            <div class="location-card" style="background: white; border-radius: 12px; margin-bottom: 15px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); overflow: hidden;">
                                <div class="loc-header" onclick="toggleDirectory('<?= $locId ?>')" style="padding: 18px 24px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; transition: background 0.2s;">
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <div style="width: 40px; height: 40px; background: #e0f2fe; color: #0284c7; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                                            <i class="fa-solid fa-map-location"></i>
                                        </div>
                                        <div>
                                            <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;"><?= htmlspecialchars($locName) ?></h3>
                                            <span style="font-size: 12px; color: #64748b; font-weight: 600;"><?= count($availLots) ?> Available out of <?= count($validLots) ?> Total Lots</span>
                                        </div>
                                    </div>
                                    <i class="fa-solid fa-chevron-down dir-icon" id="icon-<?= $locId ?>" style="color: #64748b; transition: transform 0.3s;"></i>
                                </div>
                                
                                <div class="loc-body" id="body-<?= $locId ?>" style="display: none; border-top: 1px solid var(--gray-border);">
                                    <div style="overflow-x: auto;">
                                        <table style="width: 100%; min-width: 800px;">
                                            <thead>
                                                <tr>
                                                    <th>Image</th>
                                                    <th>Property Type</th>
                                                    <th>Block/Lot</th>
                                                    <th>Area</th>
                                                    <th>Total Price</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($validLots as $lot): ?>
                                                <tr>
                                                    <td><img src="<?= htmlspecialchars(jej_lot_image_url($lot['lot_image'] ?? "default_lot.jpg")) ?>" style="width: 45px; height: 45px; object-fit: cover; border-radius: 8px; border: 1px solid var(--gray-border);"></td>
                                                    <td style="font-size: 12px; font-weight: 600; color: #64748b;"><?= htmlspecialchars($lot['property_type'] ?? 'Lot') ?></td>
                                                    <td style="font-weight: 700; color: var(--primary);">B-<?= htmlspecialchars($lot['block_no']) ?> L-<?= htmlspecialchars($lot['lot_no']) ?></td>
                                                    <td><?= htmlspecialchars($lot['area']) ?> sqm</td>
                                                    <td style="font-weight: 600; color: #1e293b;">₱<?= number_format($lot['total_price']) ?></td>
                                                    <td>
                                                        <?php 
                                                            $badges = [
                                                                'AVAILABLE' => ['bg'=>'#d1fae5', 'col'=>'#065f46'],
                                                                'RESERVED'  => ['bg'=>'#fef3c7', 'col'=>'#92400e'],
                                                                'SOLD'      => ['bg'=>'#fee2e2', 'col'=>'#991b1b']
                                                            ];
                                                            $b = $badges[strtoupper($lot['status'])] ?? ['bg'=>'#f1f5f9', 'col'=>'#475569'];
                                                        ?>
                                                        <span class="status-badge" style="background: <?= $b['bg'] ?>; color: <?= $b['col'] ?>;"><?= strtoupper($lot['status']) ?></span>
                                                    </td>
                                                    <td>
                                                        <a href="admin.php?view=inventory&edit_id=<?= $lot['id'] ?>" class="btn-action btn-edit"><i class="fa-solid fa-pen" style="margin-right: 4px;"></i> Edit</a>
                                                        <a href="admin.php?delete_id=<?= $lot['id'] ?>" class="btn-action btn-delete" onclick="return confirm('Are you sure you want to delete this property? This action cannot be undone.');"><i class="fa-solid fa-trash" style="margin-right: 4px;"></i> Delete</a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                                
                                                <?php if(count($validLots) == 0): ?>
                                                    <tr>
                                                        <td colspan="7" style="text-align: center; padding: 30px; color: var(--text-muted); font-weight: 500;">
                                                            No properties have been added to this area yet.
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                </div>

                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                <script src="https://unpkg.com/leaflet-geosearch@3.11.0/dist/bundle.min.js"></script>
                <script>
                // --- NEW FUNCTION: Hides form if "NEW_AREA" is selected ---
                function checkNewLocation() {
                    var sel = document.getElementById('locationSelect');
                    var inp = document.getElementById('newLocationInput');
                    var wrapper = document.getElementById('lotDetailsWrapper');
                    var entryModeSec = document.getElementById('entryModeSection');
                    var btnText = document.getElementById('submitBtnText');
                    var helperText = document.getElementById('newAreaHelperText');
                    
                    if(sel && sel.value === 'NEW_AREA') {
                        // 1. Show the text input to type the area
                        inp.style.display = 'block';
                        inp.required = true;
                        helperText.style.display = 'block';
                        
                        // 2. Hide everything else
                        if(wrapper) wrapper.style.display = 'none';
                        if(entryModeSec) entryModeSec.style.display = 'none';
                        
                        // 3. Remove 'required' tags so the form can submit without them
                        document.querySelectorAll('.req-field').forEach(el => el.removeAttribute('required'));
                        
                        // 4. Change button text
                        btnText.innerText = "Save New Area Only";
                        
                    } else {
                        // Restore Everything
                        if(inp) {
                            inp.style.display = 'none';
                            inp.required = false;
                        }
                        if(helperText) helperText.style.display = 'none';
                        if(wrapper) wrapper.style.display = 'block';
                        if(entryModeSec) entryModeSec.style.display = 'block';
                        
                        // Restore 'required' fields based on mode
                        document.getElementById('block_no').setAttribute('required', 'true');
                        document.getElementById('area').setAttribute('required', 'true');
                        document.getElementById('base_price').setAttribute('required', 'true');
                        
                        if(document.querySelector('input[name="entry_mode"]:checked') && document.querySelector('input[name="entry_mode"]:checked').value === 'single') {
                            if(document.getElementById('lot_no')) document.getElementById('lot_no').setAttribute('required', 'true');
                        }
                        
                        btnText.innerText = "<?= $edit_mode ? 'Update Property' : 'Save Property' ?>";
                    }
                }

                // Bulk vs Single Toggle Function
                function toggleEntryMode() {
                    const mode = document.querySelector('input[name="entry_mode"]:checked').value;
                    const singleFields = document.querySelectorAll('.single-mode-field');
                    const bulkFields = document.querySelectorAll('.bulk-mode-field');
                    
                    if (mode === 'bulk') {
                        singleFields.forEach(el => { el.style.display = 'none'; });
                        bulkFields.forEach(el => { el.style.display = 'block'; });
                        document.getElementById('lot_no').required = false;
                        document.getElementById('start_lot').required = true;
                        document.getElementById('end_lot').required = true;
                        document.getElementById('submitBtnText').innerText = "Process Bulk Entry";
                    } else {
                        singleFields.forEach(el => { el.style.display = 'block'; });
                        bulkFields.forEach(el => { el.style.display = 'none'; });
                        document.getElementById('lot_no').required = true;
                        document.getElementById('start_lot').required = false;
                        document.getElementById('end_lot').required = false;
                        document.getElementById('submitBtnText').innerText = "Save Single Property";
                    }
                }

                // Accordion Function for Directory
                function toggleDirectory(locId) {
                    const body = document.getElementById('body-' + locId);
                    const icon = document.getElementById('icon-' + locId);
                    if (body.style.display === 'none' || body.style.display === '') {
                        body.style.display = 'block';
                        icon.style.transform = 'rotate(180deg)';
                    } else {
                        body.style.display = 'none';
                        icon.style.transform = 'rotate(0deg)';
                    }
                }
                // UPDATED LOCATION & SELLING PRICE LOGIC
                // Based on the May 16, 2026 price board.
                // This updates only the inventory form computation and keeps your existing save flow.
                function normalizeAreaName(value){
                    return (value || '')
                        .toString()
                        .toUpperCase()
                        .replace(/\s+/g, ' ')
                        .replace(/\s*\(\s*/g, '(')
                        .replace(/\s*\)\s*/g, ')')
                        .trim();
                }

                function calcTotal(){
                    const areaInput = document.getElementById('area');
                    const basePriceInput = document.getElementById('base_price');
                    const locationSelect = document.getElementById('locationSelect');
                    const classSelect = document.getElementById('lot_class');
                    const termsSelect = document.getElementById('terms');

                    if(!areaInput || !basePriceInput || !locationSelect || !classSelect || !termsSelect) return;

                    const lotArea = parseFloat(areaInput.value) || 0;
                    const selectedLocation = normalizeAreaName(locationSelect.value);
                    const lotClass = (classSelect.value || 'Inner Lot').toLowerCase();
                    const terms = parseInt(termsSelect.value, 10) || 0;

                    const priceBoard = {
                        'SAN MIGUEL(CAMBIO)':       {frontCash:3500, innerCash:2500, frontInst:4000, innerInst:3000},
                        'TABUATING(SAN LEONARDO)':  {frontCash:3500, innerCash:3500, frontInst:4000, innerInst:4000},
                        'LIWAYWAY(SANTA ROSA)':     {frontCash:2500, innerCash:2000, frontInst:3000, innerInst:2500},
                        'ST. JOSEPH(CABIAO)':       {frontCash:0,    innerCash:2500, frontInst:0,    innerInst:3000},
                        'SAN VICENTE(CABIAO)':      {frontCash:3000, innerCash:2500, frontInst:3500, innerInst:3000},
                        'POBLACION':                {frontCash:0,    innerCash:8500, frontInst:0,    innerInst:9000},
                        'LAMBAKIN':                 {frontCash:0,    innerCash:3000, frontInst:0,    innerInst:3500},
                        'LANGLA':                   {frontCash:0,    innerCash:2700, frontInst:0,    innerInst:3500},
                        'SAN FERNANDO SUR':         {frontCash:3500, innerCash:3000, frontInst:4000, innerInst:3500},
                        'ENTABLADO':                {frontCash:0,    innerCash:3000, frontInst:0,    innerInst:3500}
                    };

                    const isFrontLot = lotClass.includes('front');
                    const isCashPayment = terms === 0;
                    let sellingPriceSqm = parseFloat(basePriceInput.value) || 0;

                    if(priceBoard[selectedLocation]){
                        if(isCashPayment){
                            sellingPriceSqm = isFrontLot ? priceBoard[selectedLocation].frontCash : priceBoard[selectedLocation].innerCash;
                        } else {
                            sellingPriceSqm = isFrontLot ? priceBoard[selectedLocation].frontInst : priceBoard[selectedLocation].innerInst;
                        }

                        // Keep the visible price field synced, so the saved hidden fields never mismatch.
                        basePriceInput.value = sellingPriceSqm.toFixed(2);
                    }

                    const totalPrice = lotArea * sellingPriceSqm;

                    document.getElementById('price_sqm').value = sellingPriceSqm.toFixed(2);
                    document.getElementById('total').value = totalPrice.toFixed(2);

                    const fmt = (val) => (parseFloat(val) || 0).toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });

                    document.getElementById('display_price_sqm').innerText = "₱ " + fmt(sellingPriceSqm);
                    document.getElementById('display_total').innerText = "₱ " + fmt(totalPrice);

                    const monthlyDisplay = document.getElementById('display_monthly');
                    if(monthlyDisplay){
                        if(isCashPayment || totalPrice <= 0){
                            monthlyDisplay.innerText = "Spot Cash";
                        } else {
                            // Price board payable term: 3 YEARS = 36 months.
                            const monthly = totalPrice / 36;
                            monthlyDisplay.innerText = "₱ " + fmt(monthly) + " / mo.";
                        }
                    }
                }

                document.addEventListener('DOMContentLoaded', function() {
                    calcTotal(); 
                    
                    var initialLat = <?= $edit_mode && !empty($edit_data['latitude']) ? $edit_data['latitude'] : '14.5995' ?>; 
                    var initialLng = <?= $edit_mode && !empty($edit_data['longitude']) ? $edit_data['longitude'] : '120.9842' ?>;
                    
                    var streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 });
                    var satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 });

                    var map = L.map('map', { center: [initialLat, initialLng], zoom: 13, layers: [satelliteLayer] });
                    L.control.layers({"Satellite": satelliteLayer, "Streets": streetLayer}).addTo(map);

                    const provider = new GeoSearch.OpenStreetMapProvider();
                    const searchControl = new GeoSearch.GeoSearchControl({ provider: provider, style: 'bar', showMarker: true });
                    map.addControl(searchControl);

                    var marker;
                    function updatePin(lat, lng) {
                        document.getElementById('lat').value = lat;
                        document.getElementById('lng').value = lng;
                        if (marker) marker.setLatLng([lat, lng]);
                        else marker = L.marker([lat, lng]).addTo(map);
                    }

                    <?php if($edit_mode && !empty($edit_data['latitude'])): ?>
                        marker = L.marker([initialLat, initialLng]).addTo(map);
                    <?php endif; ?>

                    map.on('click', function(e) { updatePin(e.latlng.lat, e.latlng.lng); });
                    map.on('geosearch/showlocation', function(result) { updatePin(result.location.y, result.location.x); });
                });
                </script>
            <?php endif; ?>
        </div>
    </div>
    <script>
function toggleFinanceMenu(){
    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');

    if(!submenu || !arrow) return;

    submenu.classList.toggle('show');
    arrow.style.transform = submenu.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0deg)';
}

document.addEventListener('DOMContentLoaded', function(){
    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');

    if(!submenu || !arrow) return;

    // Keep submenu closed by default
    submenu.classList.remove('show');
    arrow.style.transform = 'rotate(0deg)';

    // Auto-open only when current page belongs to Financial submenu
    const currentPage = window.location.pathname.split('/').pop();
    const financePages = [
        'verify_payments.php',
        'payment_tracking.php',
        'transaction_history.php',
        'daily_reconciliation.php'
    ];

    if(financePages.includes(currentPage)){
        submenu.classList.add('show');
        arrow.style.transform = 'rotate(180deg)';

        submenu.querySelectorAll('a').forEach(function(link){
            if(link.getAttribute('href') === currentPage){
                link.classList.add('active');
            }
        });
    }
});
</script>

<script>
function toggleSidebar(){
    if(window.innerWidth <= 768){
        document.body.classList.toggle('sidebar-open');
    } else {
        document.body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('jej_sidebar_collapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
    }

    setTimeout(function(){
        if(window.incomeChartInstance) window.incomeChartInstance.resize();
        if(window.expenseChartInstance) window.expenseChartInstance.resize();

        if(window.miniCalendar && window.miniCalendar.updateSize){
            window.miniCalendar.updateSize();
        }

        window.dispatchEvent(new Event('resize'));
    }, 280);
}

(function(){
    if(window.innerWidth > 768 && localStorage.getItem('jej_sidebar_collapsed') === '1'){
        document.body.classList.add('sidebar-collapsed');
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
})();
</script>

</body>
</html>
