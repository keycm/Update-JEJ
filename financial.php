<?php
// financial.php

require_once 'config.php';

$current_user_email = '';
if (!empty($_SESSION['user_id'])) {
    $email_stmt = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    if ($email_stmt) {
        $email_stmt->bind_param("i", $_SESSION['user_id']);
        $email_stmt->execute();
        $email_row = $email_stmt->get_result()->fetch_assoc();
        $email_stmt->close();
        $current_user_email = strtolower(trim((string)($email_row['email'] ?? '')));
    }
}

$is_cashier_finance_user = (
    strtoupper((string)($_SESSION['role'] ?? '')) === 'CASHIER'
    || $current_user_email === 'cashier@jej.com'
);

if (!$is_cashier_finance_user) {
    checkAdmin();
} elseif (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Role Access
if (!$is_cashier_finance_user) {
    requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER']);
}

// Manager Permission Access
if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'fin_full');
}


$alert_msg = "";
$alert_type = "";
$currentPage = basename($_SERVER['PHP_SELF']);



function ensureDefaultExpenseCategories($conn) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'accounting_categories'");
    if(!$tableCheck || $tableCheck->num_rows == 0) return;

    $defaults = [
        'Utilities',
        'Office Supplies',
        'Professional Fee',
        'Transportation',
        'Meals / Allowance',
        'Repairs and Maintenance',
        'Bank Charges',
        'Taxes and Fees',
        'Supplies and Materials',
        'Labor / Contractor',
        'Printing and Documentation',
        'Miscellaneous Expense'
    ];

    foreach($defaults as $catName){
        $check = $conn->prepare("SELECT id FROM accounting_categories WHERE UPPER(name)=UPPER(?) LIMIT 1");
        if(!$check) continue;
        $check->bind_param("s", $catName);
        $check->execute();
        $exists = $check->get_result();

        if(!$exists || $exists->num_rows == 0){
            $groupName = 'Expense';
            $insert = $conn->prepare("INSERT INTO accounting_categories (name, group_name) VALUES (?, ?)");
            if($insert){
                $insert->bind_param("ss", $catName, $groupName);
                $insert->execute();
            }
        }
    }
}

ensureDefaultExpenseCategories($conn);


function financialTableHasColumn($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = (string)$column;

    $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("s", $column);
    $stmt->execute();
    $result = $stmt->get_result();

    return ($result && $result->num_rows > 0);
}

function financialTransactionCashDateExpr($alias = '') {
    global $financialHasVerifiedAt, $financialHasDatePaid, $financialHasCreatedAt;

    $prefix = $alias !== '' ? $alias . '.' : '';

    $transactionDate = $prefix . "transaction_date";
    $createdDate = $financialHasCreatedAt
        ? "DATE(" . $prefix . "created_at)"
        : $transactionDate;

    $cashDateParts = [];
    if ($financialHasVerifiedAt) {
        // Cash-in basis: verified payments count on the date they were actually verified/posted.
        $cashDateParts[] = "DATE(" . $prefix . "verified_at)";
    }
    if ($financialHasDatePaid) {
        $cashDateParts[] = "NULLIF(" . $prefix . "date_paid, '0000-00-00')";
    }
    $cashDateParts[] = $createdDate;
    $cashDateParts[] = $transactionDate;

    $cashDate = "COALESCE(" . implode(', ', $cashDateParts) . ")";

    return "CASE WHEN " . $prefix . "type='INCOME' THEN " . $cashDate . " ELSE " . $transactionDate . " END";
}

// Dashboard chart helpers.
// These prevent fatal "undefined function" errors when reservation-fee rows are merged into the weekly chart.
if (!function_exists('financeWeekLabelFromDate')) {
    function financeWeekLabelFromDate($dateValue) {
        $timestamp = strtotime((string)$dateValue);
        if ($timestamp === false) {
            return ['Week 1', 1];
        }

        $day = (int)date('j', $timestamp);
        if ($day <= 7) {
            return ['Week 1', 1];
        }
        if ($day <= 14) {
            return ['Week 2', 2];
        }
        if ($day <= 21) {
            return ['Week 3', 3];
        }
        if ($day <= 28) {
            return ['Week 4', 4];
        }

        return ['Week 5', 5];
    }
}

if (!function_exists('financeAddChartIncome')) {
    function financeAddChartIncome(&$chart_dates, &$chart_income_totals, &$chart_expense_totals, $weekLabel, $amount) {
        $weekLabel = (string)$weekLabel;
        $amount = (float)$amount;
        $index = array_search($weekLabel, $chart_dates, true);

        if ($index !== false) {
            $chart_income_totals[$index] = (float)($chart_income_totals[$index] ?? 0) + $amount;
            if (!isset($chart_expense_totals[$index])) {
                $chart_expense_totals[$index] = 0;
            }
            return;
        }

        $chart_dates[] = $weekLabel;
        $chart_income_totals[] = $amount;
        $chart_expense_totals[] = 0;

        // Keep Week 1 to Week 5 in the correct visual order after adding a missing week.
        $combined = [];
        foreach ($chart_dates as $i => $label) {
            $weekNo = 99;
            if (preg_match('/Week\s*(\d+)/i', (string)$label, $match)) {
                $weekNo = (int)$match[1];
            }

            $combined[] = [
                'order' => $weekNo,
                'label' => $label,
                'income' => (float)($chart_income_totals[$i] ?? 0),
                'expense' => (float)($chart_expense_totals[$i] ?? 0),
            ];
        }

        usort($combined, function($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        $chart_dates = array_column($combined, 'label');
        $chart_income_totals = array_column($combined, 'income');
        $chart_expense_totals = array_column($combined, 'expense');
    }
}

$financialHasVerifiedAt = financialTableHasColumn($conn, 'transactions', 'verified_at');
$financialHasDatePaid = financialTableHasColumn($conn, 'transactions', 'date_paid');
$financialHasCreatedAt = financialTableHasColumn($conn, 'transactions', 'created_at');
$financeDateExpr = financialTransactionCashDateExpr('');
$financeDateExprT = financialTransactionCashDateExpr('t');



function renderExpenseCategoryOptions($conn) {
    $cats = $conn->query("SELECT * FROM accounting_categories ORDER BY 
        CASE 
            WHEN UPPER(COALESCE(group_name,'')) LIKE '%EXPENSE%' THEN 0 
            WHEN UPPER(COALESCE(group_name,'')) LIKE '%BILL%' THEN 0
            WHEN UPPER(COALESCE(group_name,'')) LIKE '%VOUCHER%' THEN 0
            ELSE 1 
        END,
        group_name, name");
    $hasOptions = false;

    if($cats){
        while($c = $cats->fetch_assoc()){
            $groupRaw = trim($c['group_name'] ?? 'Expense');
            $nameRaw = trim($c['name'] ?? 'Uncategorized');

            $group = strtoupper($groupRaw);
            $name = strtoupper($nameRaw);

            // Bills and Check Vouchers should not use Income categories like [Income] Lot Sales.
            if(strpos($group, 'INCOME') !== false || strpos($name, 'INCOME') !== false || strpos($name, 'LOT SALES') !== false){
                continue;
            }

            $id = (int)$c['id'];
            $label = '[' . htmlspecialchars($groupRaw) . '] ' . htmlspecialchars($nameRaw);
            echo "<option value='{$id}'>{$label}</option>";
            $hasOptions = true;
        }
    }

    if(!$hasOptions){
        echo "<option value=''>No expense categories found</option>";
    }
}


function generateCVONumber($conn) {
    $prefix = 'CVO-' . date('Ymd') . '-';
    $nextNo = 1;

    $stmt = $conn->prepare("SELECT or_number FROM transactions WHERE or_number LIKE CONCAT(?, '%') ORDER BY or_number DESC LIMIT 1");
    if($stmt){
        $stmt->bind_param("s", $prefix);
        $stmt->execute();
        $res = $stmt->get_result();
        if($res && $res->num_rows > 0){
            $row = $res->fetch_assoc();
            $last = $row['or_number'] ?? '';
            $parts = explode('-', $last);
            $lastNo = (int)end($parts);
            $nextNo = $lastNo + 1;
        }
    }

    return $prefix . str_pad($nextNo, 4, '0', STR_PAD_LEFT);
}

// --- HANDLE ADD INCOME FORM SUBMISSION ---
if(isset($_POST['add_income'])){
    $amt = floatval($_POST['amount']);
    $income_source = htmlspecialchars(trim($_POST['income_source'] ?? ''));
    $payment_method = htmlspecialchars(trim($_POST['payment_method'] ?? ''));
    $received_by = htmlspecialchars(trim($_POST['received_by'] ?? ''));
    $desc = htmlspecialchars(trim($_POST['description'] ?? ''));
    $date = $_POST['trans_date'];

    $income_notes = [];
    if($income_source !== '') $income_notes[] = "Source: " . $income_source;
    if($payment_method !== '') $income_notes[] = "Method: " . $payment_method;
    if($received_by !== '') $income_notes[] = "Received by: " . $received_by;
    if($desc !== '') $income_notes[] = $desc;
    $desc = implode(' | ', $income_notes);
    
    // Generate a unique OR/Reference number if none is provided
    $or_num = !empty($_POST['or_number']) ? htmlspecialchars($_POST['or_number']) : 'INC-' . strtoupper(uniqid());
    
    if($amt > 0){
        $check_stmt = $conn->prepare("SELECT id FROM transactions WHERE or_number = ?");
        $check_stmt->bind_param("s", $or_num);
        $check_stmt->execute();
        $check_res = $check_stmt->get_result();

        if($check_res->num_rows > 0) {
            $alert_msg = "Error: The OR / Reference Number '<b>$or_num</b>' is already in use. Please enter a different number.";
            $alert_type = "error";
        } else {
            $stmt = $conn->prepare("INSERT INTO transactions (type, amount, transaction_date, description, or_number) VALUES ('INCOME', ?, ?, ?, ?)");
            $stmt->bind_param("dsss", $amt, $date, $desc, $or_num);
            
            try {
                if($stmt->execute()) {
                    $alert_msg = "Income of ₱" . number_format($amt, 2) . " successfully recorded!";
                    $alert_type = "success";
                } else {
                    $alert_msg = "Failed to record income. Please try again.";
                    $alert_type = "error";
                }
            } catch (mysqli_sql_exception $e) {
                $alert_msg = "Database Error: Please ensure all data is valid and unique.";
                $alert_type = "error";
            }
        }
    } else {
        $alert_msg = "Amount must be greater than zero.";
        $alert_type = "error";
    }
}

// --- HANDLE ENTER BILLS (POS) ---
if(isset($_POST['enter_bill'])){
    // Enter Bills is always money-out. Do not allow Income categories/transactions here.
    $type = 'EXPENSE';
    $category_id = (int)($_POST['category_id'] ?? 0);
    $project_id = (int)($_POST['project_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $payee = htmlspecialchars(trim($_POST['payee'] ?? ''));
    $due_date = htmlspecialchars(trim($_POST['due_date'] ?? ''));
    $bill_status = htmlspecialchars(trim($_POST['bill_status'] ?? 'Unpaid'));
    $description = htmlspecialchars(trim($_POST['description'] ?? ''));
    $date = $_POST['transaction_date'];
    $user_id = $_SESSION['user_id'];

    $bill_notes = [];
    if($description !== '') $bill_notes[] = $description;
    if($due_date !== '') $bill_notes[] = "Due: " . $due_date;
    if($bill_status !== '') $bill_notes[] = "Bill Status: " . $bill_status;
    $description = implode(' | ', $bill_notes);

    if($amount <= 0){
        $alert_msg = "Bill amount must be greater than zero.";
        $alert_type = "error";
    } elseif($category_id <= 0 || $project_id <= 0){
        $alert_msg = "Please select a valid expense category and project.";
        $alert_type = "error";
    } else {
        $or_number = generateORNumber($conn);

        $stmt = $conn->prepare("INSERT INTO transactions (or_number, transaction_date, type, category_id, project_id, amount, description, user_id, payee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssiidsis", $or_number, $date, $type, $category_id, $project_id, $amount, $description, $user_id, $payee);
        
        if($stmt->execute()){
            logActivity($conn, $user_id, "Processed Bill / Expense", "OR: $or_number | Payee: $payee | Amount: ₱" . number_format($amount, 2));
            echo "<script>
                alert('Bill Saved! Voucher Number: $or_number'); 
                window.open('voucher.php?or=$or_number', '_blank');
                window.location.href = 'financial.php';
            </script>";
            exit();
        } else {
            $alert_msg = "Error saving bill: " . $conn->error;
            $alert_type = "error";
        }
    }
}

// --- HANDLE ISSUE CHECK VOUCHER ---
if(isset($_POST['issue_check'])){
    $transaction_date = $_POST['transaction_date'];
    $payee = $_POST['payee'];
    $bank_name = $_POST['bank_name'];
    $check_number = $_POST['check_number'];
    $amount = $_POST['amount'];
    $category_id = $_POST['category_id'];
    $project_id = $_POST['project_id'];
    $description = htmlspecialchars(trim($_POST['description'] ?? ''));
    $prepared_by = htmlspecialchars(trim($_POST['prepared_by'] ?? ''));
    $checked_by = htmlspecialchars(trim($_POST['checked_by'] ?? ''));
    $approved_by = htmlspecialchars(trim($_POST['approved_by'] ?? ''));
    $released_to = htmlspecialchars(trim($_POST['released_to'] ?? ''));
    $user_id = $_SESSION['user_id'];

    $voucher_notes = [];
    if($description !== '') $voucher_notes[] = $description;
    if($prepared_by !== '') $voucher_notes[] = "Prepared by: " . $prepared_by;
    if($checked_by !== '') $voucher_notes[] = "Checked by: " . $checked_by;
    if($approved_by !== '') $voucher_notes[] = "Approved by: " . $approved_by;
    if($released_to !== '') $voucher_notes[] = "Released to: " . $released_to;
    $description = implode(' | ', $voucher_notes);
    
    $cv_number = generateCVNumber($conn);

    $stmt = $conn->prepare("INSERT INTO transactions (or_number, transaction_date, type, category_id, project_id, amount, description, user_id, payee, bank_name, check_number, is_check) VALUES (?, ?, 'EXPENSE', ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    $stmt->bind_param("ssiidsisss", $cv_number, $transaction_date, $category_id, $project_id, $amount, $description, $user_id, $payee, $bank_name, $check_number);
    
    if($stmt->execute()){
        logActivity($conn, $user_id, "Issued Check Voucher", "CV: $cv_number | Payee: $payee | Amount: ₱" . number_format($amount, 2));
        echo "<script>
            alert('Check Voucher Saved! Number: $cv_number'); 
            window.open('print_check_voucher.php?cv=$cv_number', '_blank');
            window.location.href = 'financial.php';
        </script>";
        exit();
    } else {
        $alert_msg = "Error generating voucher: " . $conn->error;
        $alert_type = "error";
    }
}


// --- HANDLE CASH VOUCHER OUT ---
if(isset($_POST['cash_voucher_out'])){
    $transaction_date = $_POST['transaction_date'];
    $payee = htmlspecialchars(trim($_POST['payee'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    $project_id = (int)($_POST['project_id'] ?? 0);
    $cash_purpose = htmlspecialchars(trim($_POST['cash_purpose'] ?? ''));
    $description = htmlspecialchars(trim($_POST['description'] ?? ''));
    $prepared_by = htmlspecialchars(trim($_POST['prepared_by'] ?? ''));
    $checked_by = htmlspecialchars(trim($_POST['checked_by'] ?? ''));
    $approved_by = htmlspecialchars(trim($_POST['approved_by'] ?? ''));
    $released_to = htmlspecialchars(trim($_POST['released_to'] ?? ''));
    $user_id = $_SESSION['user_id'];

    $voucher_notes = [];
    $voucher_notes[] = "Voucher Type: Cash Voucher Out";
    if($cash_purpose !== '') $voucher_notes[] = "Purpose: " . $cash_purpose;
    if($description !== '') $voucher_notes[] = $description;
    if($prepared_by !== '') $voucher_notes[] = "Prepared by: " . $prepared_by;
    if($checked_by !== '') $voucher_notes[] = "Checked by: " . $checked_by;
    if($approved_by !== '') $voucher_notes[] = "Approved by: " . $approved_by;
    if($released_to !== '') $voucher_notes[] = "Released to: " . $released_to;
    $description = implode(' | ', $voucher_notes);

    if($amount <= 0){
        $alert_msg = "Cash voucher amount must be greater than zero.";
        $alert_type = "error";
    } elseif($category_id <= 0 || $project_id <= 0){
        $alert_msg = "Please select a valid expense category and project.";
        $alert_type = "error";
    } elseif($payee === ''){
        $alert_msg = "Please enter the payee / employee name.";
        $alert_type = "error";
    } else {
        $cvo_number = generateCVONumber($conn);
        $bank_name = 'CASH';
        $check_number = '';

        $stmt = $conn->prepare("INSERT INTO transactions (or_number, transaction_date, type, category_id, project_id, amount, description, user_id, payee, bank_name, check_number, is_check) VALUES (?, ?, 'EXPENSE', ?, ?, ?, ?, ?, ?, ?, ?, 0)");
        $stmt->bind_param("ssiidsisss", $cvo_number, $transaction_date, $category_id, $project_id, $amount, $description, $user_id, $payee, $bank_name, $check_number);

        if($stmt->execute()){
            logActivity($conn, $user_id, "Issued Cash Voucher Out", "CVO: $cvo_number | Payee: $payee | Amount: ₱" . number_format($amount, 2));
            echo "<script>
                alert('Cash Voucher Out Saved! Number: $cvo_number');
                window.open('print_cash_voucher.php?cvo=$cvo_number', '_blank');
                window.location.href = 'financial.php';
            </script>";
            exit();
        } else {
            $alert_msg = "Error generating cash voucher: " . $conn->error;
            $alert_type = "error";
        }
    }
}


// --- DATA FETCHING (FINANCIAL & ACCOUNTING) ---
$total_in = 0; $total_out = 0; $current_balance = 0; $net_profit = 0;
$expected_collection = 0; $collection_rate = 0;
$low_fund_threshold = 20000;
$chart_dates = []; $chart_income_totals = []; $chart_expense_totals = [];
$calendar_events = [];
$recent_transactions = [];
$check_vouchers = [];
$cash_vouchers = [];
$upcoming_payables = [];
$has_finance_data = false;

// Month filter for dashboard cards, chart, calendar, and recent transactions.
$selected_month = $_GET['month'] ?? date('Y-m');
if(!preg_match('/^\d{4}-\d{2}$/', $selected_month)){
    $selected_month = date('Y-m');
}
$month_start = $selected_month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$month_label = date('F Y', strtotime($month_start));


// Reservation Fee is counted only when it exists as a VERIFIED transaction.
// No virtual/synthetic reservation fee income is added here.
$reservation_fee_income_total = 0;
$reservation_fee_income_rows = [];

$checkTable = $conn->query("SHOW TABLES LIKE 'transactions'");
if($checkTable && $checkTable->num_rows > 0) {
    // 1. Calculate balances for selected month only.
    $fundStmt = $conn->prepare("SELECT 
        SUM(CASE WHEN type='INCOME' AND (payment_status='VERIFIED' OR payment_status IS NULL) THEN amount ELSE 0 END) AS total_in,
        SUM(CASE WHEN type='EXPENSE' THEN amount ELSE 0 END) AS total_out
        FROM transactions
        WHERE $financeDateExpr BETWEEN ? AND ?");
    if($fundStmt){
        $fundStmt->bind_param('ss', $month_start, $month_end);
        $fundStmt->execute();
        $funds = $fundStmt->get_result()->fetch_assoc();
        $total_in = (float)($funds['total_in'] ?? 0);
        $total_out = (float)($funds['total_out'] ?? 0);
        $current_balance = $total_in - $total_out;
        $net_profit = $current_balance;
        $expected_collection = max($total_in, 0);
        $collection_rate = ($expected_collection > 0) ? min(100, round(($total_in / $expected_collection) * 100)) : 0;
    }

    // 2. Weekly Income vs Expense chart.
    $chart_title = "Weekly Income vs Expense ($month_label)";
    $chartStmt = $conn->prepare("SELECT 
            CASE 
                WHEN DAY($financeDateExpr) BETWEEN 1 AND 7 THEN 'Week 1'
                WHEN DAY($financeDateExpr) BETWEEN 8 AND 14 THEN 'Week 2'
                WHEN DAY($financeDateExpr) BETWEEN 15 AND 21 THEN 'Week 3'
                WHEN DAY($financeDateExpr) BETWEEN 22 AND 28 THEN 'Week 4'
                ELSE 'Week 5'
            END AS label,
            CASE 
                WHEN DAY($financeDateExpr) BETWEEN 1 AND 7 THEN 1
                WHEN DAY($financeDateExpr) BETWEEN 8 AND 14 THEN 2
                WHEN DAY($financeDateExpr) BETWEEN 15 AND 21 THEN 3
                WHEN DAY($financeDateExpr) BETWEEN 22 AND 28 THEN 4
                ELSE 5
            END AS week_order,
            SUM(CASE WHEN type='INCOME' AND (payment_status='VERIFIED' OR payment_status IS NULL) THEN amount ELSE 0 END) AS income_total,
            SUM(CASE WHEN type='EXPENSE' THEN amount ELSE 0 END) AS expense_total
        FROM transactions
        WHERE $financeDateExpr BETWEEN ? AND ?
        GROUP BY week_order, label
        ORDER BY week_order ASC");
    if($chartStmt){
        $chartStmt->bind_param('ss', $month_start, $month_end);
        $chartStmt->execute();
        $chartData = $chartStmt->get_result();
        while($row = $chartData->fetch_assoc()){
            $income = (float)($row['income_total'] ?? 0);
            $expense = (float)($row['expense_total'] ?? 0);
            if($income > 0 || $expense > 0){
                $chart_dates[] = $row['label'];
                $chart_income_totals[] = $income;
                $chart_expense_totals[] = $expense;
                $has_finance_data = true;
            }
        }

        // Add reservation fees into the weekly chart as verified cash-in.
        foreach($reservation_fee_income_rows as $rfChartRow){
            [$weekLabel, $weekOrder] = financeWeekLabelFromDate($rfChartRow['finance_date']);
            financeAddChartIncome($chart_dates, $chart_income_totals, $chart_expense_totals, $weekLabel, (float)$rfChartRow['amount']);
            $has_finance_data = true;
        }
    }

    // 3. Fetch compact calendar events for selected month.
    $evStmt = $conn->prepare("SELECT *, $financeDateExpr AS finance_date FROM transactions
        WHERE $financeDateExpr BETWEEN ? AND ?
        AND (payment_status='VERIFIED' OR payment_status IS NULL)
        ORDER BY finance_date ASC, id ASC");
    if($evStmt){
        $evStmt->bind_param('ss', $month_start, $month_end);
        $evStmt->execute();
        $ev = $evStmt->get_result();
        while($row = $ev->fetch_assoc()){
            $color = ($row['type'] == 'INCOME') ? '#10b981' : '#ef4444';
            $calendar_events[] = [
                'title' => $row['type'] . ': ₱' . number_format((float)$row['amount'], 0),
                'start' => $row['finance_date'] ?? $row['transaction_date'],
                'color' => $color
            ];
        }

        // Add reservation fee calendar events.
        foreach($reservation_fee_income_rows as $rfEventRow){
            $calendar_events[] = [
                'title' => 'INCOME: ₱' . number_format((float)$rfEventRow['amount'], 0),
                'start' => $rfEventRow['finance_date'],
                'color' => '#10b981'
            ];
        }
    }

    // 4. Recent Transactions are more useful than an empty voucher table.
    $recentStmt = $conn->prepare("SELECT 
            t.*, 
            c.name AS category,
            $financeDateExprT AS finance_date,
            COALESCE(NULLIF(t.payee, ''), NULLIF(u.fullname, ''), NULLIF(t.description, ''), 'General Transaction') AS finance_payee
        FROM transactions t
        LEFT JOIN accounting_categories c ON t.category_id = c.id
        LEFT JOIN reservations r ON t.reservation_id = r.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE $financeDateExprT BETWEEN ? AND ?
        AND (
            t.type='EXPENSE'
            OR (t.type='INCOME' AND (t.payment_status='VERIFIED' OR t.payment_status IS NULL))
        )
        ORDER BY finance_date DESC, t.id DESC
        LIMIT 12");
    if($recentStmt){
        $recentStmt->bind_param('ss', $month_start, $month_end);
        $recentStmt->execute();
        $recentRes = $recentStmt->get_result();
        while($row = $recentRes->fetch_assoc()){
            $recent_transactions[] = $row;
        }

        // Add reservation fee to Recent Transactions, then sort with real transactions.
        foreach($reservation_fee_income_rows as $rfRecentRow){
            $recent_transactions[] = $rfRecentRow;
        }
        usort($recent_transactions, function($a, $b){
            $dateA = strtotime($a['finance_date'] ?? $a['transaction_date'] ?? '1970-01-01');
            $dateB = strtotime($b['finance_date'] ?? $b['transaction_date'] ?? '1970-01-01');
            if($dateA === $dateB){
                return strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? ''));
            }
            return $dateB <=> $dateA;
        });
        $recent_transactions = array_slice($recent_transactions, 0, 12);
    }


    // 5. Upcoming payables extracted from Enter Bills descriptions with Due date.
    $payableStmt = $conn->prepare("SELECT t.*, c.name AS category
        FROM transactions t
        LEFT JOIN accounting_categories c ON t.category_id = c.id
        WHERE t.type='EXPENSE'
        ORDER BY t.transaction_date DESC, t.id DESC
        LIMIT 50");
    if($payableStmt){
        $payableStmt->execute();
        $payableRes = $payableStmt->get_result();
        while($row = $payableRes->fetch_assoc()){
            $descText = $row['description'] ?? '';
            $dueDate = '';
            $billStatus = 'Recorded';

            if(preg_match('/Due:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $descText, $m)){
                $dueDate = $m[1];
            }

            if(preg_match('/Bill Status:\s*([^|]+)/i', $descText, $m)){
                $billStatus = trim($m[1]);
            }

            if($dueDate !== '' && strtoupper($billStatus) !== 'PAID'){
                $row['due_date_extracted'] = $dueDate;
                $row['bill_status_extracted'] = $billStatus;
                $upcoming_payables[] = $row;
            }

            if(count($upcoming_payables) >= 6) break;
        }
    }

    // 6. Fetch recent check vouchers only when present.
    $colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'is_check'");
    if($colCheck && $colCheck->num_rows > 0) {
        $cv_stmt = $conn->prepare("SELECT t.*, c.name as category FROM transactions t LEFT JOIN accounting_categories c ON t.category_id = c.id WHERE t.is_check = 1 AND $financeDateExprT BETWEEN ? AND ? ORDER BY $financeDateExprT DESC, t.id DESC LIMIT 10");
        if($cv_stmt) {
            $cv_stmt->bind_param('ss', $month_start, $month_end);
            $cv_stmt->execute();
            $cv_query = $cv_stmt->get_result();
            while($row = $cv_query->fetch_assoc()){
                $check_vouchers[] = $row;
            }
        }

        // 7. Fetch recent Cash Voucher Out records so they can also be printed.
        $cvo_stmt = $conn->prepare("SELECT t.*, c.name as category FROM transactions t LEFT JOIN accounting_categories c ON t.category_id = c.id WHERE t.is_check = 0 AND t.type = 'EXPENSE' AND (t.or_number LIKE 'CVO-%' OR t.description LIKE '%Voucher Type: Cash Voucher Out%') AND $financeDateExprT BETWEEN ? AND ? ORDER BY $financeDateExprT DESC, t.id DESC LIMIT 10");
        if($cvo_stmt) {
            $cvo_stmt->bind_param('ss', $month_start, $month_end);
            $cvo_stmt->execute();
            $cvo_query = $cvo_stmt->get_result();
            while($row = $cvo_query->fetch_assoc()){
                $cash_vouchers[] = $row;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Dashboard | JEJ Financials</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

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
        .menu-link.open { background: var(--primary-light); color: var(--primary); font-weight: 600; border-left: 0; }
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

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 24px; }
        .stat-card { background: white; padding: 25px; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); position: relative; overflow: hidden; transition: transform 0.2s;}
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .stat-card h2 { font-size: 32px; font-weight: 800; margin: 5px 0 0; letter-spacing: -1px;}
        .stat-card small { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.5px; }
        .stat-icon { position: absolute; right: -15px; bottom: -15px; font-size: 90px; opacity: 0.08; transform: rotate(-15deg); transition: transform 0.3s;}
        .stat-card:hover .stat-icon { transform: rotate(0deg) scale(1.1); }
        
        .sc-income { border-top: 4px solid #10b981; }
        .sc-expense { border-top: 4px solid #ef4444; }
        .sc-balance { border-top: 4px solid #3b82f6; }
        .sc-profit { border-top: 4px solid #059669; }
        .sc-income h2{color:#059669 !important;}
        .sc-expense h2{color:#dc2626 !important;}
        .sc-profit h2{color:#059669 !important;}
        .sc-balance h2{color:#2563eb !important;}
        .sc-income .stat-icon{color:#10b981 !important;}
        .sc-expense .stat-icon{color:#ef4444 !important;}
        .sc-profit .stat-icon{color:#059669 !important;}
        .sc-balance .stat-icon{color:#3b82f6 !important;}
        
        /* Dashboard Widgets */
        .dashboard-widgets { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        @media (max-width: 1100px) { .dashboard-widgets { grid-template-columns: 1fr; } }
        
        .widget-card { background: white; padding: 22px; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; min-height: 320px;}
        .widget-card.compact-widget { min-height: 320px; height: auto; }
        .widget-title { font-size: 16px; font-weight: 800; color: #1e293b; margin-bottom: 20px; border-bottom: 1px solid var(--gray-border); padding-bottom: 15px; display: flex; justify-content: space-between; align-items: center;}
        
        .chart-filter { padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; font-weight: 600; color: #475569; outline: none; cursor: pointer; transition: 0.2s;}
        .chart-filter:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.15); }

        /* Action Buttons */
        .finance-actions{
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:12px;
            flex-wrap:nowrap;
            margin-right:15px;
        }

        .btn-action{
            height:48px;
            padding:0 20px;
            border:none;
            border-radius:12px;
            font-size:14px;
            font-weight:700;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            cursor:pointer;
            transition:0.25s ease;
            color:#ffffff;
            white-space:nowrap;
            box-shadow:0 4px 12px rgba(0,0,0,.08);
        }

        .btn-action:hover{
            transform:translateY(-2px);
        }

        /* Green = money in / collection */
        .btn-add-income{
            background:#16a34a;
        }
        .btn-add-income:hover{
            background:#15803d;
        }

        /* Red = money out / expenses */
        .btn-enter-bills{
            background:#dc2626;
        }
        .btn-enter-bills:hover{
            background:#b91c1c;
        }

        /* Purple = voucher / approval */
        .btn-issue-check{
            background:#7c3aed;
        }
        .btn-issue-check:hover{
            background:#6d28d9;
        }

        /* Amber = cash out / payroll / petty cash */
        .btn-cash-voucher{
            background:#f59e0b;
        }
        .btn-cash-voucher:hover{
            background:#d97706;
        }

        /* Blue = reports / export */
        .btn-export{
            background:#2563eb;
        }
        .btn-export:hover{
            background:#1d4ed8;
        }
        /* FILTER BAR */
.finance-filter-form{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}

.finance-filter-form input[type="month"]{
    height:48px;
    min-width:180px;
    padding:0 12px;
    border:1px solid #cbd5e1;
    border-radius:12px;
    font-weight:600;
}

/* APPLY BUTTON */
.btn-filter{
    background:#16a34a;
    color:#fff;
}

.btn-filter:hover{
    background:#15803d;
}

/* RESET BUTTON */
.btn-reset{
    background:#64748b;
    color:#fff;
}

.btn-reset:hover{
    background:#475569;
}

/* SAME HEIGHT */
.finance-filter-form .btn-action{
    height:48px;
    padding:0 18px;
}

@media(max-width:768px){

    .finance-filter-form{
        width:100%;
    }

    .finance-filter-form input[type="month"]{
        width:100%;
    }

    .btn-filter,
    .btn-reset{
        flex:1;
    }
}

        .btn-print { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s;}
        .btn-print:hover { background: #bae6fd; color: #0369a1; }

        /* Table Styling */
        .table-container { background: white; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 30px; }
        .table-header { padding: 20px 24px; border-bottom: 1px solid var(--gray-border); display: flex; justify-content: space-between; align-items: center; background: #fff;}
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; background: var(--gray-light); border-bottom: 1px solid var(--gray-border); letter-spacing: 0.5px;}
        td { padding: 16px 24px; border-bottom: 1px solid var(--gray-border); color: #37474f; font-size: 14px; vertical-align: middle; }
        tr:hover td { background: #fdfdfd; }
        tr:last-child td { border-bottom: none; }

        /* Calendar tweaks */
        .calendar-wrapper { flex: 1; min-height: 0; width: 100%; }
        .finance-control-bar{background:#fff;border:1px solid var(--gray-border);border-radius:14px;padding:14px 18px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:14px;box-shadow:var(--shadow-sm);flex-wrap:wrap;}
        .finance-control-bar label{font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase;margin-right:8px;}
        .finance-control-bar input[type=month]{padding:9px 12px;border:1px solid #cbd5e1;border-radius:9px;font-weight:700;color:#334155;outline:none;}
        .empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;height:230px;color:#94a3b8;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;}
        .empty-state i{font-size:34px;color:#cbd5e1;margin-bottom:10px;}
        .tx-type{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:800;text-transform:uppercase;}
        .tx-income{background:#dcfce7;color:#166534;border:1px solid #86efac;}
        .tx-expense{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
        .tx-status{font-size:11px;font-weight:800;color:#64748b;}
        .month-chip{display:inline-flex;align-items:center;gap:7px;background:var(--primary-light);color:var(--primary);padding:8px 12px;border-radius:999px;font-size:12px;font-weight:800;}
        .recent-finance-table td{padding:13px 20px;}
        .section-spacer{margin-top:24px;}
        .fc .fc-toolbar-title { font-size: 14px !important; color: var(--dark); font-weight: 700;}
        .fc .fc-button { padding: 4px 10px !important; font-size: 12px !important; background: var(--primary) !important; border: none !important; border-radius: 6px !important;}
        .fc .fc-day-today { background: var(--gray-light) !important; }
        .fc-event { font-size: 11px !important; padding: 3px 5px !important; border: none !important; border-radius: 4px !important; font-weight: 600; cursor: pointer; color: white !important;}


        /* MODERN FINANCE DASHBOARD UI */
        .content-area{
            background:linear-gradient(180deg,#fbfdf8 0%,#f7fbf5 100%);
        }

        .stats-grid{
            grid-template-columns:repeat(4,minmax(180px,1fr));
        }

        .stat-card{
            padding:20px 22px;
            border-radius:18px;
            background:rgba(255,255,255,.94);
            box-shadow:0 8px 24px rgba(15,23,42,.045);
        }

        .stat-card h2{
            font-size:clamp(24px,2vw,34px);
            line-height:1.05;
            white-space:nowrap;
        }

        .stat-card small{
            color:#64748b;
        }

        .dashboard-widgets{
            grid-template-columns:minmax(0,1.65fr) minmax(320px,.85fr);
            align-items:stretch;
        }

        .widget-card{
            border-radius:20px;
            background:rgba(255,255,255,.96);
            box-shadow:0 10px 30px rgba(15,23,42,.05);
        }

        .payables-list{
            display:flex;
            flex-direction:column;
            gap:12px;
        }

        .payable-item{
            display:grid;
            grid-template-columns:44px 1fr auto;
            gap:12px;
            align-items:center;
            padding:14px;
            border:1px solid #e2e8f0;
            border-radius:16px;
            background:#ffffff;
        }

        .payable-icon{
            width:44px;
            height:44px;
            border-radius:14px;
            background:#fee2e2;
            color:#dc2626;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:18px;
        }

        .payable-title{
            font-weight:800;
            color:#0f172a;
            font-size:14px;
            margin-bottom:4px;
        }

        .payable-meta{
            font-size:12px;
            color:#64748b;
            line-height:1.35;
        }

        .payable-amount{
            text-align:right;
            font-weight:900;
            color:#dc2626;
            white-space:nowrap;
        }

        .payable-status{
            display:inline-flex;
            margin-top:4px;
            padding:4px 8px;
            border-radius:999px;
            background:#fff7ed;
            color:#c2410c;
            font-size:10px;
            font-weight:900;
            text-transform:uppercase;
        }

        @media (max-width:1200px){
            .stats-grid{
                grid-template-columns:repeat(2,minmax(180px,1fr));
            }

            .dashboard-widgets{
                grid-template-columns:1fr;
            }
        }

        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 9999; inset: 0; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(3px); padding: 30px; overflow-y: auto; align-items: center; justify-content: center;}
        .modal-content { width: 100%; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.3); animation: dropAnim 0.2s ease-out forwards;}
        .modal-sm { max-width: 450px; }
        .modal-lg { max-width: 700px; }
        
        .modal-header { padding: 20px 25px; border-bottom: 1px solid var(--gray-border); display: flex; justify-content: space-between; align-items: center; background: var(--gray-light); }
        .modal-header h2 { margin: 0; font-size: 18px; font-weight: 700; color: var(--dark); display:flex; align-items:center; gap:8px;}
        .close-btn { background: none; border: none; font-size: 20px; color: #90a4ae; cursor: pointer; transition: 0.2s;}
        .close-btn:hover { color: #ef4444; transform: scale(1.1);}
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .full-width { grid-column: 1 / -1; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: #455a64; margin-bottom: 6px; text-transform: uppercase;}
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 14px; outline: none; transition: 0.2s; box-sizing: border-box; background: #f8fafc;}
        .form-control:focus { border-color: var(--primary); background: white; box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.15); }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 35px; }
        
        .menu-dropdown { margin-bottom: 6px; }
        .dropdown-toggle{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    cursor:pointer;
}

.finance-main-link{
    display:flex;
    align-items:center;
    gap:12px;
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

.submenu-toggle-btn:hover{
    background:#dff2e1;
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



        /* AUTO FIT + COLLAPSIBLE SIDEBAR FIX */
        .sidebar,
        .main-panel {
            transition: all 0.25s ease;
        }

        .top-header {
            gap: 16px;
            flex-wrap: wrap;
        }

        .top-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 240px;
            flex: 1 1 auto;
        }

        .header-title {
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
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
        body.sidebar-collapsed .sidebar-menu small,
        body.sidebar-collapsed .submenu,
        body.sidebar-collapsed .submenu-toggle-btn,
        body.sidebar-collapsed .finance-main-link span,
        body.sidebar-collapsed .submenu-link span {
            display: none !important;
        }

        body.sidebar-collapsed .menu-link,
        body.sidebar-collapsed .dropdown-toggle {
            justify-content: center;
            padding: 14px 10px;
            gap: 0;
            border-left: 0 !important;
            font-size: 0;
        }

        body.sidebar-collapsed .menu-link i,
        body.sidebar-collapsed .finance-main-link i {
            font-size: 18px;
            width: 22px;
            margin: 0;
        }

        body.sidebar-collapsed .finance-main-link {
            justify-content: center;
            padding: 0;
            gap: 0;
            flex: 0 0 auto;
        }

        /* FINANCIAL PAGE AUTO FIT */
        .content-area {
            max-width: 100%;
            box-sizing: border-box;
        }

        .dashboard-widgets {
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        }

        .widget-card,
        .stat-card,
        .table-container,
        .finance-control-bar {
            min-width: 0;
            box-sizing: border-box;
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table-container table {
            min-width: 900px;
        }

        .recent-finance-table {
            table-layout: auto;
        }

        .recent-finance-table td,
        .recent-finance-table th {
            white-space: normal;
            word-break: break-word;
        }

        .top-header > div:nth-child(2) {
            flex: 1 1 420px;
            justify-content: flex-end;
        }

        @media (max-width: 1100px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            }
            .content-area {
                padding: 24px;
            }
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
                z-index: 101;
            }

            body.sidebar-open::after {
                content: "";
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.35);
                z-index: 90;
            }

            .top-header {
                padding: 15px 18px;
                align-items: flex-start;
            }

            .top-header-left {
                width: 100%;
                flex: 1 1 100%;
            }

            .top-header > div:nth-child(2) {
                width: 100%;
                flex: 1 1 100%;
                margin-right: 0 !important;
                justify-content: flex-start;
            }

            .header-title h1 {
                font-size: 18px;
            }

            .header-title p,
            .profile-info {
                display: none;
            }

            .content-area {
                padding: 18px;
            }

            .stats-grid,
            .dashboard-widgets,
            .form-grid {
                grid-template-columns: 1fr !important;
            }

            .finance-control-bar form,
            .finance-control-bar input[type=month],
            .finance-control-bar .btn-action {
                width: 100%;
            }

            .btn-action {
                justify-content: center;
            }

            .finance-actions{
                width:100%;
                margin-right:0;
                flex-wrap:wrap;
                justify-content:flex-start;
            }

            .finance-actions .btn-action{
                flex:1 1 calc(50% - 6px);
                min-width:170px;
            }

            .modal {
                padding: 14px;
            }
        }

        /* HEADER ALIGNMENT FIX: actions + administrator on one row */
        .top-header{
            display:flex !important;
            align-items:center !important;
            justify-content:space-between !important;
            gap:20px !important;
            flex-wrap:nowrap !important;
            min-height:86px;
        }

        .top-header-left{
            display:flex !important;
            align-items:center !important;
            gap:14px !important;
            flex:1 1 auto !important;
            min-width:280px !important;
        }

        .header-right{
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:18px;
            margin-left:auto;
            flex:0 0 auto;
            min-width:max-content;
        }

        .header-right .finance-actions{
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:12px;
            flex-wrap:nowrap;
            margin-right:0;
            width:auto;
        }

        .header-right .profile-dropdown{
            flex:0 0 auto;
            margin-left:6px;
        }

        .profile-trigger{
            height:52px;
            box-sizing:border-box;
            white-space:nowrap;
        }

        @media (max-width: 1450px){
            .top-header{
                flex-wrap:wrap !important;
            }
            .header-right{
                flex:1 1 100%;
                width:100%;
                justify-content:flex-start;
                min-width:0;
            }
            .header-right .finance-actions{
                flex-wrap:wrap;
            }
            .header-right .profile-dropdown{
                margin-left:0;
            }
        }

        @media (max-width: 768px){
            .header-right{
                width:100%;
                flex-direction:column;
                align-items:stretch;
                gap:12px;
            }
            .header-right .finance-actions{
                width:100%;
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:10px;
            }
            .header-right .finance-actions .btn-action{
                width:100%;
                min-width:0;
                flex:none;
            }
            .header-right .profile-dropdown{
                width:100%;
            }
            .header-right .profile-trigger{
                width:100%;
                justify-content:center;
            }
        }

        .filter-label{
    height:48px;
    display:flex;
    align-items:center;
    gap:8px;
    padding:0 14px;
    border-radius:12px;
    background:#f1f5f9;
    color:#475569;
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    white-space:nowrap;
}

.finance-filter-form input[type="month"]{
    height:48px;
    min-width:180px;
    max-width:190px;
    padding:0 14px;
    border:1px solid #cbd5e1;
    border-radius:12px;
    font-weight:800;
    color:#0f172a;
    background:#fff;
}

        /* CLICKABLE KPI CARDS */
        .stat-card-link{
            text-decoration:none;
            color:inherit;
            display:block;
        }
        .stat-card-link:focus-visible{
            outline:3px solid rgba(46,125,50,.25);
            border-radius:18px;
        }

        /* CASH FLOW SUMMARY */
        .cashflow-summary{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:14px;
        }
        .cashflow-item{
            padding:16px;
            border:1px solid #e2e8f0;
            border-radius:16px;
            background:#ffffff;
            display:flex;
            align-items:center;
            gap:12px;
        }
        .cashflow-icon{
            width:42px;
            height:42px;
            border-radius:14px;
            display:flex;
            align-items:center;
            justify-content:center;
            flex:0 0 auto;
        }
        .cashflow-icon.in{background:#dcfce7;color:#16a34a;}
        .cashflow-icon.out{background:#fee2e2;color:#dc2626;}
        .cashflow-icon.net{background:#dcfce7;color:#059669;}
        .cashflow-icon.rate{background:#dbeafe;color:#2563eb;}
        .cashflow-label{
            font-size:11px;
            font-weight:900;
            color:#64748b;
            text-transform:uppercase;
            margin-bottom:4px;
        }
        .cashflow-value{
            font-size:20px;
            font-weight:900;
            color:#0f172a;
            line-height:1;
        }
        .cashflow-note{
            grid-column:1/-1;
            padding:12px 14px;
            border-radius:14px;
            background:#f8fafc;
            color:#64748b;
            font-size:12px;
            line-height:1.4;
        }
        .voucher-status{
            display:inline-flex;
            padding:5px 10px;
            border-radius:999px;
            background:#ede9fe;
            color:#6d28d9;
            font-size:11px;
            font-weight:900;
            text-transform:uppercase;
        }
        .needs-category{
            display:inline-flex;
            padding:5px 10px;
            border-radius:999px;
            background:#fff7ed;
            color:#c2410c;
            font-size:11px;
            font-weight:900;
        }

        /* SHORTER FILTER BAR */
        .finance-control-bar{
            padding:10px 16px !important;
            min-height:58px;
        }
        .month-chip,
        .filter-label,
        .finance-filter-form input[type="month"],
        .finance-filter-form .btn-action{
            height:40px !important;
        }
        .finance-filter-form input[type="month"]{
            min-width:165px !important;
            max-width:175px !important;
        }
        .filter-label{
            padding:0 12px !important;
        }

        @media(max-width:768px){
            .cashflow-summary{
                grid-template-columns:1fr;
            }
        }

    

        /* ==========================================================
           RESPONSIVE FINANCIAL DASHBOARD FIX
           Fixes mobile/tablet header actions, KPI card proportion,
           filter bar alignment, and widget/table overflow.
           ========================================================== */
        *, *::before, *::after{ box-sizing:border-box; }
        html, body{ max-width:100%; overflow-x:hidden; }

        .top-header{ width:100%; }
        .top-header-left,
        .header-right,
        .finance-actions,
        .content-area,
        .finance-control-bar,
        .dashboard-widgets,
        .stats-grid{ min-width:0; }

        .header-right{
            display:flex !important;
            align-items:center !important;
            justify-content:flex-end !important;
            gap:16px !important;
            margin-left:auto !important;
            min-width:0 !important;
        }

        .header-right .finance-actions{
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    flex-wrap:nowrap !important;
    width:auto !important;
    margin:0 !important;
}

.header-right .finance-actions .btn-action{
    width:190px !important;
    min-width:190px !important;
    max-width:190px !important;
    height:52px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}

        .finance-actions .btn-action{
            width:100% !important;
            min-width:0 !important;
            height:52px !important;
            padding:0 16px !important;
            border-radius:14px !important;
            white-space:nowrap !important;
            overflow:hidden !important;
            text-overflow:ellipsis !important;
        }

        .stats-grid{
            display:grid !important;
            grid-template-columns:repeat(4, minmax(0, 1fr)) !important;
            gap:20px !important;
            align-items:stretch !important;
        }

        .stat-card-link,
        .stat-card{
            height:100% !important;
        }

        .stat-card{
            min-height:128px !important;
            display:flex !important;
            flex-direction:column !important;
            justify-content:center !important;
            padding:22px 24px !important;
        }

        .stat-card h2{
            font-size:clamp(26px, 2.1vw, 36px) !important;
            line-height:1.05 !important;
            white-space:nowrap !important;
        }

        .stat-card small{
            max-width:85% !important;
            line-height:1.35 !important;
        }

        .finance-control-bar{
            display:flex !important;
            align-items:center !important;
            justify-content:space-between !important;
            gap:14px !important;
            width:100% !important;
        }

        .finance-filter-form{
            display:flex !important;
            align-items:center !important;
            justify-content:flex-end !important;
            gap:10px !important;
            flex-wrap:nowrap !important;
            min-width:0 !important;
        }

        .finance-filter-form input[type="month"]{
            width:175px !important;
            min-width:175px !important;
            max-width:175px !important;
        }

        .dashboard-widgets{
            display:grid !important;
            grid-template-columns:minmax(0, 1.4fr) minmax(340px, .9fr) !important;
            gap:24px !important;
        }

        .widget-card{
            min-width:0 !important;
        }

        .table-container{
            max-width:100% !important;
            overflow-x:auto !important;
        }

        @media (max-width:1450px){
    .header-right .finance-actions{
        flex-wrap:wrap !important;
    }
}
            .top-header-left{
                flex:1 1 auto !important;
            }
            .header-right{
                flex:1 1 100% !important;
                width:100% !important;
                justify-content:space-between !important;
            }
            .header-right .finance-actions{
                grid-template-columns:repeat(5, minmax(125px, 1fr)) !important;
                flex:1 1 auto !important;
            }
        }

        @media (max-width:1280px){
            .stats-grid{
                grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
            }
            .dashboard-widgets{
                grid-template-columns:1fr !important;
            }
        }

        @media (max-width:900px){
            .top-header{
                padding:18px 22px !important;
            }
            .header-right{
                flex-direction:column !important;
                align-items:stretch !important;
                gap:14px !important;
            }
            .header-right .finance-actions{
                width:100% !important;
                grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
                gap:10px !important;
            }
            .profile-dropdown{
                align-self:center !important;
                width:auto !important;
            }
            .profile-trigger{
                width:auto !important;
                justify-content:center !important;
            }
            .finance-control-bar{
                align-items:stretch !important;
                flex-direction:column !important;
            }
            .finance-control-bar > div{
                width:100% !important;
            }
            .month-chip{
                width:100% !important;
                justify-content:center !important;
                height:44px !important;
            }
            .finance-filter-form{
                width:100% !important;
                display:grid !important;
                grid-template-columns:auto minmax(170px, 1fr) auto auto !important;
                align-items:center !important;
            }
            .finance-filter-form input[type="month"]{
                width:100% !important;
                min-width:0 !important;
                max-width:none !important;
            }
            .finance-filter-form .btn-action{
                width:100% !important;
            }
        }

        @media (max-width:768px){
            .main-panel,
            body.sidebar-collapsed .main-panel{
                margin-left:0 !important;
                width:100% !important;
            }

            .top-header{
                padding:16px 18px !important;
                gap:14px !important;
            }

            .top-header-left{
                width:100% !important;
                min-width:0 !important;
                flex:1 1 100% !important;
            }

            .header-title h1{
                font-size:20px !important;
                line-height:1.15 !important;
            }

            .header-title p{
                display:none !important;
            }

            .header-right .finance-actions{
                grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
            }

            .finance-actions .btn-action{
                height:54px !important;
                font-size:13px !important;
                padding:0 8px !important;
                gap:7px !important;
            }

            .finance-actions .btn-action i{
                font-size:13px !important;
                flex-shrink:0 !important;
            }

            .profile-info{
                display:none !important;
            }

            .profile-dropdown{
                margin:0 auto !important;
            }

            .profile-trigger{
                width:54px !important;
                height:54px !important;
                padding:0 !important;
                border-radius:50% !important;
                justify-content:center !important;
            }

            .profile-avatar{
                width:50px !important;
                height:50px !important;
            }

            .dropdown-menu{
                right:50% !important;
                transform:translateX(50%) !important;
            }

            .content-area{
                padding:18px !important;
            }

            .stats-grid{
                grid-template-columns:1fr !important;
                gap:14px !important;
            }

            .stat-card{
                min-height:118px !important;
                padding:20px 24px !important;
            }

            .stat-card h2{
                font-size:30px !important;
            }

            .stat-card small{
                max-width:78% !important;
            }

            .stat-icon{
                font-size:78px !important;
                right:-12px !important;
                bottom:-18px !important;
            }

            .dashboard-widgets{
                grid-template-columns:1fr !important;
                gap:16px !important;
            }

            .widget-card{
                padding:18px !important;
                min-height:auto !important;
            }

            .empty-state{
                min-height:220px !important;
                height:auto !important;
                padding:24px 14px !important;
            }

            .cashflow-summary{
                grid-template-columns:1fr !important;
            }

            .cashflow-note{
                font-size:11px !important;
            }

            .finance-control-bar{
                padding:14px !important;
                border-radius:16px !important;
            }

            .finance-filter-form{
                grid-template-columns:1fr 1fr !important;
                gap:10px !important;
            }

            .filter-label{
                display:none !important;
            }

            .finance-filter-form input[type="month"]{
                grid-column:1 / -1 !important;
                height:48px !important;
            }

            .finance-filter-form .btn-action{
                height:48px !important;
                min-width:0 !important;
                padding:0 10px !important;
            }

            .table-header{
                flex-direction:column !important;
                align-items:flex-start !important;
                gap:12px !important;
            }
        }
        .btn-cash-voucher{
    background:#f59e0b !important;
    color:#fff !important;
}

.btn-cash-voucher i{
    font-size:16px !important;
    display:inline-block !important;
    margin-right:8px !important;
    flex-shrink:0 !important;
}

.btn-cash-voucher span{
    white-space:nowrap !important;
}

        @media (max-width:420px){
            .content-area{
                padding:14px !important;
            }
            .top-header{
                padding:14px !important;
            }
            .header-right .finance-actions{
                grid-template-columns:1fr 1fr !important;
                gap:9px !important;
            }
            .finance-actions .btn-action{
                height:50px !important;
                font-size:12px !important;
                border-radius:13px !important;
            }
            .finance-filter-form{
                grid-template-columns:1fr !important;
            }
            .finance-filter-form input[type="month"]{
                grid-column:auto !important;
            }
            .stat-card h2{
                font-size:28px !important;
            }
        }


        /* PROFILE DROPDOWN - exact compact style copied from reservation.php */
        .profile-dropdown {
            position: relative !important;
            cursor: pointer !important;
            flex-shrink: 0 !important;
        }

        .profile-trigger {
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            padding: 6px 12px !important;
            border-radius: 10px !important;
            transition: background 0.2s, border-color 0.2s !important;
            border: 1px solid transparent !important;
            height: auto !important;
            min-height: 52px !important;
            white-space: nowrap !important;
        }

        .profile-trigger:hover {
            background: var(--gray-light) !important;
            border-color: var(--gray-border) !important;
        }

        .profile-avatar {
            width: 40px !important;
            height: 40px !important;
            background: var(--primary) !important;
            color: white !important;
            border-radius: 50% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-weight: 800 !important;
            font-size: 16px !important;
            box-shadow: 0 2px 4px rgba(46, 125, 50, 0.2) !important;
        }

        .profile-info strong {
            display: block !important;
            font-size: 13px !important;
            color: var(--dark) !important;
            line-height: 1.2 !important;
        }

        .profile-info small {
            font-size: 11px !important;
            color: var(--text-muted) !important;
            font-weight: 500 !important;
        }

        .dropdown-menu {
            display: none;
            position: absolute !important;
            right: 0 !important;
            top: 110% !important;
            background: white !important;
            border-radius: 12px !important;
            box-shadow: var(--shadow-lg) !important;
            border: 1px solid var(--gray-border) !important;
            min-width: 220px !important;
            z-index: 1000 !important;
            overflow: hidden !important;
            transform-origin: top right !important;
            animation: dropAnim 0.2s ease-out forwards !important;
        }

        .profile-dropdown:hover .dropdown-menu {
            display: block !important;
        }

        .dropdown-header {
            padding: 15px !important;
            border-bottom: 1px solid var(--gray-border) !important;
            background: var(--gray-light) !important;
        }

        .dropdown-item {
            padding: 12px 16px !important;
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            color: #455a64 !important;
            text-decoration: none !important;
            font-size: 13px !important;
            font-weight: 500 !important;
            transition: background 0.2s, color 0.2s, border-left-color 0.2s !important;
            border-left: 3px solid transparent !important;
        }

        .dropdown-item:hover {
            background: var(--primary-light) !important;
            color: var(--primary) !important;
            border-left-color: var(--primary) !important;
        }

        .dropdown-item.text-danger {
            color: #d84315 !important;
        }

        .dropdown-item.text-danger:hover {
            background: #fbe9e7 !important;
            color: #bf360c !important;
            border-left-color: #d84315 !important;
        }

        @media (max-width:768px){
            .profile-trigger{
                width:auto !important;
                height:auto !important;
                min-height:52px !important;
                padding:6px 12px !important;
                border-radius:10px !important;
            }

            .profile-avatar{
                width:40px !important;
                height:40px !important;
            }

            .dropdown-menu{
                right:0 !important;
                transform:none !important;
            }
        }

        /* FINAL MOBILE POLISH: full-width financial header/actions/filter */
        @media (max-width: 768px) {
            html,
            body {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: hidden !important;
            }

            .main-panel,
            body.sidebar-collapsed .main-panel {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            .top-header {
                width: 100% !important;
                padding: 18px 16px !important;
                display: grid !important;
                grid-template-columns: 1fr !important;
                gap: 18px !important;
                align-items: stretch !important;
                justify-content: stretch !important;
                min-height: 0 !important;
            }

            .top-header-left {
                width: 100% !important;
                min-width: 0 !important;
                display: grid !important;
                grid-template-columns: 50px minmax(0, 1fr) !important;
                align-items: center !important;
                gap: 14px !important;
            }

            .sidebar-toggle {
                width: 50px !important;
                height: 50px !important;
                flex: 0 0 50px !important;
                border-radius: 14px !important;
            }

            .header-title {
                min-width: 0 !important;
            }

            .header-title h1 {
                font-size: clamp(24px, 7vw, 34px) !important;
                line-height: 1.08 !important;
                white-space: normal !important;
            }

            .header-title p {
                display: none !important;
            }

            .header-right {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: stretch !important;
                justify-content: flex-start !important;
                gap: 16px !important;
                margin: 0 !important;
            }

            .header-right .finance-actions,
            .finance-actions {
                width: 100% !important;
                max-width: 100% !important;
                display: grid !important;
                grid-template-columns: 1fr !important;
                gap: 12px !important;
                margin: 0 !important;
                justify-content: stretch !important;
            }

            .header-right .finance-actions .btn-action,
            .finance-actions .btn-action {
                width: 100% !important;
                min-width: 0 !important;
                max-width: none !important;
                height: 58px !important;
                padding: 0 18px !important;
                border-radius: 18px !important;
                font-size: 15px !important;
                justify-content: center !important;
                white-space: nowrap !important;
            }

            .jej-admin-header-tools {
                width: 100% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 18px !important;
                margin: 2px 0 0 !important;
                flex-wrap: nowrap !important;
            }

            .jej-admin-header-tools .admin-notification-wrapper,
            .jej-admin-header-tools .notification-bell-wrapper,
            .jej-admin-header-tools .notification-bell,
            .jej-admin-header-tools .jej-notification-wrap {
                flex: 0 0 auto !important;
            }

            .jej-admin-header-tools .jej-notification-button {
                width: 58px !important;
                height: 58px !important;
                border-radius: 999px !important;
                font-size: 18px !important;
            }

            .jej-admin-header-tools .profile-dropdown {
                width: auto !important;
                margin: 0 !important;
                align-self: center !important;
            }

            .jej-admin-header-tools .profile-trigger {
                width: auto !important;
                min-width: 0 !important;
                height: 58px !important;
                min-height: 58px !important;
                padding: 6px 14px !important;
                border-radius: 999px !important;
                justify-content: center !important;
                background: #fff !important;
                border: 1px solid var(--gray-border) !important;
            }

            .jej-admin-header-tools .profile-info {
                display: none !important;
            }

            .jej-admin-header-tools .profile-avatar {
                width: 48px !important;
                height: 48px !important;
                font-size: 18px !important;
            }

            .content-area {
                width: 100% !important;
                padding: 18px 14px !important;
            }

            .finance-control-bar {
                width: 100% !important;
                display: grid !important;
                grid-template-columns: 1fr !important;
                gap: 14px !important;
                padding: 16px !important;
                border-radius: 18px !important;
                align-items: stretch !important;
            }

            .finance-control-bar > div,
            .finance-filter-form {
                width: 100% !important;
            }

            .month-chip {
                width: 100% !important;
                height: 54px !important;
                justify-content: center !important;
                border-radius: 999px !important;
                font-size: 14px !important;
            }

            .finance-filter-form {
                display: grid !important;
                grid-template-columns: 1fr !important;
                gap: 12px !important;
                align-items: stretch !important;
                justify-content: stretch !important;
            }

            .filter-label {
                display: none !important;
            }

            .finance-filter-form input[type="month"] {
                width: 100% !important;
                min-width: 0 !important;
                max-width: none !important;
                height: 54px !important;
                text-align: center !important;
                border-radius: 18px !important;
                font-size: 15px !important;
            }

            .finance-filter-form .btn-action {
                width: 100% !important;
                height: 54px !important;
                min-width: 0 !important;
                max-width: none !important;
                border-radius: 18px !important;
                font-size: 15px !important;
            }

            .stats-grid,
            .dashboard-widgets {
                grid-template-columns: 1fr !important;
            }
        }

        @media (min-width: 520px) and (max-width: 768px) {
            .header-right .finance-actions,
            .finance-actions {
                grid-template-columns: 1fr 1fr !important;
            }

            .btn-export {
                grid-column: 1 / -1 !important;
            }

            .finance-filter-form {
                grid-template-columns: 1fr 1fr !important;
            }

            .finance-filter-form input[type="month"] {
                grid-column: 1 / -1 !important;
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
    <?php if($is_cashier_finance_user): ?>

        <a href="financial.php" class="menu-link <?= $currentPage == 'financial.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-coins"></i>
            Financials
        </a>

        <a href="transaction_history.php" class="menu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-list-ul"></i>
            Ledger List
        </a>

        <a href="payment_tracking.php" class="menu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            Payment Tracking
        </a>

        <a href="daily_reconciliation.php" class="menu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-scale-balanced"></i>
            Daily Reconciliation
        </a>

        <a href="verify_payments.php" class="menu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-circle-check"></i>
            Verify Payments
        </a>

        <a href="aging_due_report.php" class="menu-link <?= $currentPage == 'aging_due_report.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock"></i>
            Aging / Due Report
        </a>

        <a href="contract_status.php" class="menu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-signature"></i>
            Contract Status
        </a>

    <?php else: ?>

        <!-- NORMAL ADMIN / MANAGER MENU -->
        <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-bottom: 12px; letter-spacing: 0.5px;">MAIN MENU</small>
        <a href="admin.php" class="menu-link">
            <i class="fa-solid fa-chart-pie"></i>
            Dashboard
        </a>

        <a href="reservation.php" class="menu-link">
        <i class="fa-solid fa-file-signature"></i>
            Reservations
        </a>

        <a href="master_list.php" class="menu-link">
            <i class="fa-solid fa-map-location-dot"></i>
            Master List / Map
        </a>

        <a href="admin.php?view=inventory" class="menu-link">
            <i class="fa-solid fa-plus-circle"></i>
            Add Property
        </a>

        <div class="menu-dropdown">

    <div class="menu-link dropdown-toggle <?= $currentPage == 'financial.php' ? 'active' : 'open' ?>" onclick="window.location.href='financial.php'">

    <div class="finance-main-link">
        <i class="fa-solid fa-coins"></i>
        <span>Financials</span>
    </div>

    <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)" title="Show/Hide Financial Menu">
        <i class="fa-solid fa-chevron-down dropdown-arrow" id="financeArrow"></i>
    </button>

</div>
    <div id="financeSubMenu" class="submenu show">

        <a href="verify_payments.php" class="menu-link submenu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-circle-check"></i>
            <span>Verify Payments</span>
        </a>

        <a href="payment_tracking.php" class="menu-link submenu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            <span>Payment Tracking</span>
        </a>

        <a href="transaction_history.php" class="menu-link submenu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-list-ul"></i>
            <span>Ledger List</span>
        </a>

        <a href="daily_reconciliation.php" class="menu-link submenu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-scale-balanced"></i>
            <span>Daily Reconciliation</span>
        </a>

        <a href="aging_due_report.php" class="menu-link submenu-link <?= $currentPage == 'aging_due_report.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-clock"></i> <span>Aging / Due Report</span>
        </a>

        <a href="contract_status.php"
                    class="menu-link submenu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-file-signature"></i>
                        <span>Contract Status</span>
                </a>
        
        <a href="manual_buyer_entry.php"
        class="menu-link submenu-link <?= $currentPage == 'manual_buyer_entry.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-user-plus"></i>
                <span>Manual Buyer Entry</span>
        </a>

                <a href="pricing_matrix.php"
                   class="menu-link submenu-link <?= $currentPage == 'pricing_matrix.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-table-list"></i>
                    <span class="menu-text">Pricing Matrix</span>
                </a>

    </div>

</div>


            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">SYSTEM</small>
            <a href="index.php" class="menu-link" target="_blank"><i class="fa-solid fa-globe"></i> View Website</a>

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
                    <h1>Financial Dashboard</h1>
                    <p>Track income, expenses, vouchers, and project accounting.</p>
                </div>
            </div>
            
            <div class="header-right">
                <div class="finance-actions">
                <button onclick="openModal('incomeModal')" class="btn-action btn-add-income">
                    <i class="fa-solid fa-circle-plus"></i> Add Income
                </button>

                <button onclick="openModal('billsModal')" class="btn-action btn-enter-bills">
                    <i class="fa-solid fa-arrow-trend-down"></i> Enter Bills
                </button>

                <button onclick="openModal('checkModal')" class="btn-action btn-issue-check">
                    <i class="fa-solid fa-money-check-dollar"></i> Check Voucher
                </button>

                <button onclick="openModal('cashVoucherModal')" class="btn-action btn-cash-voucher">
    <i class="fa-solid fa-wallet"></i>
    <span>Cash Voucher Out</span>
</button>

                <a href="export_excel.php" class="btn-action btn-export">
                    <i class="fa-solid fa-file-export"></i> Export Finance
                </a>
            </div>

            <?php include 'includes/profile_dropdown.php'; ?>
            </div>
        </div>

        <div class="content-area">

            <?php if($alert_msg): ?>
                <div style="padding: 16px 20px; border-radius: 10px; margin-bottom: 25px; font-weight: 500; font-size: 14px; background: <?= $alert_type=='success' ? '#e8f5e9' : '#fbe9e7' ?>; color: <?= $alert_type=='success' ? '#2e7d32' : '#d84315' ?>; border: 1px solid <?= $alert_type=='success' ? '#c8e6c9' : '#ffccbc' ?>; box-shadow: var(--shadow-sm);">
                    <i class="fa-solid <?= $alert_type=='success'?'fa-check-circle':'fa-exclamation-circle' ?>" style="margin-right: 10px;"></i>
                    <?= $alert_msg ?>
                </div>
            <?php endif; ?>

            <?php if($current_balance < $low_fund_threshold): ?>
            <div style="background: #fef2f2; border-left: 5px solid #ef4444; padding: 20px; border-radius: 8px; margin-bottom: 30px; display: flex; align-items: center; gap: 15px; box-shadow: var(--shadow-sm);">
                <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444; font-size: 24px;"></i>
                <div>
                    <strong style="color: #b91c1c; font-size: 15px; display: block; margin-bottom: 4px;">LOW FUND WARNING</strong>
                    <span style="color: #dc2626; font-size: 13px;">Current Balance is <b>₱<?= number_format($current_balance, 2) ?></b>, which is below the safe threshold of ₱<?= number_format($low_fund_threshold) ?>.</span>
                </div>
            </div>
            <?php endif; ?>

            <div class="finance-control-bar">
    <div>
        <span class="month-chip">
            <i class="fa-solid fa-calendar-days"></i>
            Showing: <?= htmlspecialchars($month_label) ?>
        </span>
    </div>

    <form method="GET" class="finance-filter-form">
        <label for="month" class="filter-label">
    <i class="fa-solid fa-calendar-filter"></i>
    Filter Month
</label>

        <input
            type="month"
            id="month"
            name="month"
            value="<?= htmlspecialchars($selected_month) ?>"
        >

        <button type="submit" class="btn-action btn-filter">
            <i class="fa-solid fa-filter"></i>
            Apply
        </button>

        <a href="financial.php" class="btn-action btn-reset">
            <i class="fa-solid fa-rotate-left"></i>
            Reset
        </a>
    </form>
</div>

            <div class="stats-grid">
                <a href="transaction_history.php?type=INCOME&month=<?= urlencode($selected_month) ?>" class="stat-card-link">
                    <div class="stat-card sc-income">
                        <small>Total Income (Collected)</small>
                        <h2 style="color: #059669;">₱<?= number_format($total_in, 2) ?></h2>
                        <i class="fa-solid fa-arrow-trend-up stat-icon" style="color: #10b981;"></i>
                    </div>
                </a>

                <a href="transaction_history.php?type=EXPENSE&month=<?= urlencode($selected_month) ?>" class="stat-card-link">
                    <div class="stat-card sc-expense">
                        <small>Total Expenses (Bills/Checks)</small>
                        <h2 style="color: #dc2626;">₱<?= number_format($total_out, 2) ?></h2>
                        <i class="fa-solid fa-arrow-trend-down stat-icon" style="color: #ef4444;"></i>
                    </div>
                </a>

                <a href="financial.php?month=<?= urlencode($selected_month) ?>#recent-transactions" class="stat-card-link">
                    <div class="stat-card sc-profit">
                        <small>Net Profit</small>
                        <h2 style="color: <?= $net_profit >= 0 ? '#059669' : '#dc2626' ?>;">₱<?= number_format($net_profit, 2) ?></h2>
                        <i class="fa-solid fa-sack-dollar stat-icon" style="color: #059669;"></i>
                    </div>
                </a>

                <a href="transaction_history.php?month=<?= urlencode($selected_month) ?>" class="stat-card-link">
                    <div class="stat-card sc-balance">
                        <small>Current Remaining Balance</small>
                        <h2 style="color: #2563eb;">₱<?= number_format($current_balance, 2) ?></h2>
                        <i class="fa-solid fa-wallet stat-icon" style="color: #3b82f6;"></i>
                    </div>
                </a>
            </div>

            <div class="dashboard-widgets">
                <div class="widget-card compact-widget">
                    <div class="widget-title">
                        <span><i class="fa-solid fa-chart-column" style="color: #10b981; margin-right: 8px;"></i> <?= $chart_title ?></span>
                    </div>
                    <?php if($has_finance_data): ?>
                        <div style="position: relative; height: 250px; width: 100%;">
                            <canvas id="financeChart"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-chart-simple"></i>
                            <strong>No income or expense recorded for <?= htmlspecialchars($month_label) ?>.</strong>
                            <span style="font-size:12px;margin-top:4px;">Use Add Income or Enter Bills to start tracking.</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="widget-card compact-widget">
                    <div class="widget-title">
                        <span><i class="fa-solid fa-wallet" style="color:#2563eb;margin-right:8px;"></i> Cash Flow Summary</span>
                        <span style="font-size:12px;color:#64748b;font-weight:700;"><?= htmlspecialchars($month_label) ?></span>
                    </div>

                    <div class="cashflow-summary">
                        <div class="cashflow-item">
                            <div class="cashflow-icon in"><i class="fa-solid fa-arrow-trend-up"></i></div>
                            <div>
                                <div class="cashflow-label">Cash In</div>
                                <div class="cashflow-value" style="color:#059669;">₱<?= number_format($total_in, 2) ?></div>
                            </div>
                        </div>

                        <div class="cashflow-item">
                            <div class="cashflow-icon out"><i class="fa-solid fa-arrow-trend-down"></i></div>
                            <div>
                                <div class="cashflow-label">Cash Out</div>
                                <div class="cashflow-value" style="color:#dc2626;">₱<?= number_format($total_out, 2) ?></div>
                            </div>
                        </div>

                        <div class="cashflow-item">
                            <div class="cashflow-icon net"><i class="fa-solid fa-scale-balanced"></i></div>
                            <div>
                                <div class="cashflow-label">Net Cash</div>
                                <div class="cashflow-value" style="color:<?= $net_profit >= 0 ? '#059669' : '#dc2626' ?>;">₱<?= number_format($net_profit, 2) ?></div>
                            </div>
                        </div>

                        <div class="cashflow-item">
                            <div class="cashflow-icon rate"><i class="fa-solid fa-percent"></i></div>
                            <div>
                                <div class="cashflow-label">Collection Rate</div>
                                <div class="cashflow-value" style="color:#2563eb;"><?= $collection_rate ?>%</div>
                            </div>
                        </div>

                        <div class="cashflow-note">
                            <strong>Note:</strong> Cash Flow Summary replaces the empty Upcoming Payables box so the dashboard always shows useful financial movement for the selected month.
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-container section-spacer" id="recent-transactions">
                <div class="table-header">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #1e293b;"><i class="fa-solid fa-receipt" style="color: #10b981; margin-right: 8px;"></i> Recent Transactions</h3>
                    <span class="month-chip"><?= htmlspecialchars($month_label) ?></span>
                </div>
                <table class="recent-finance-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Buyer / Payee</th>
                            <th>Category</th>
                            <th>Particulars</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($recent_transactions)): ?>
                            <?php foreach($recent_transactions as $tx): 
                                $txType = strtoupper($tx['type'] ?? '');
                                $txStatus = strtoupper($tx['payment_status'] ?? 'RECORDED');
                                $buyerPayee = trim($tx['finance_payee'] ?? '');
                                if($buyerPayee === '') $buyerPayee = trim($tx['payee'] ?? '');
                                if($buyerPayee === '') $buyerPayee = trim($tx['description'] ?? 'General Transaction');
                            ?>
                            <tr>
                                <td style="font-weight:700;color:#64748b;font-size:13px;"><?= date('M d, Y', strtotime($tx['finance_date'] ?? $tx['transaction_date'])) ?></td>
                                <td style="font-weight:800;color:#1e293b;"><?= htmlspecialchars($buyerPayee) ?></td>
                                <td>
                                    <?php
                                        $catDisplay = $tx['category'] ?? 'Uncategorized';
                                        if($txType === 'EXPENSE' && strtoupper(trim($catDisplay)) === 'LOT SALES'){
                                            echo '<span class="needs-category">Needs Expense Category</span>';
                                        } else {
                                            echo htmlspecialchars($catDisplay);
                                        }
                                    ?>
                                </td>
                                <td style="max-width:300px;color:#475569;font-size:12px;"><?= htmlspecialchars($tx['description'] ?? '') ?></td>
                                <td><span class="tx-type <?= $txType === 'INCOME' ? 'tx-income' : 'tx-expense' ?>"><?= htmlspecialchars($txType) ?></span></td>
                                <td style="font-weight:900;color:<?= $txType === 'INCOME' ? '#059669' : '#dc2626' ?>;">₱<?= number_format((float)$tx['amount'], 2) ?></td>
                                <td><span class="tx-status"><?= htmlspecialchars($txStatus) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align:center;padding:35px;color:#94a3b8;"><i class="fa-solid fa-folder-open" style="font-size:28px;margin-bottom:8px;display:block;color:#cbd5e1;"></i>No transactions recorded for <?= htmlspecialchars($month_label) ?>.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if(!empty($check_vouchers)): ?>
<div class="table-container">
                <div class="table-header">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #1e293b;"><i class="fa-solid fa-money-check" style="color: #8b5cf6; margin-right: 8px;"></i> Recent Check Vouchers</h3>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>CV Number</th>
                            <th>Payee</th>
                            <th>Bank & Check No</th>
                            <th>Particulars</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($check_vouchers)): ?>
                            <?php foreach($check_vouchers as $cv): ?>
                            <tr>
                                <td style="font-weight: 600; color: #64748b; font-size: 13px;"><?= date('M d, Y', strtotime($cv['transaction_date'])) ?></td>
                                <td><strong style="color: #7c3aed;"><?= $cv['or_number'] ?></strong></td>
                                <td style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($cv['payee']) ?></td>
                                <td>
                                    <div style="font-size: 13px; font-weight: 700; color: #334155;"><?= htmlspecialchars($cv['bank_name']) ?></div>
                                    <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">Check #: <?= htmlspecialchars($cv['check_number']) ?></div>
                                </td>
                                <td style="font-size: 12px; color: #475569; max-width: 250px;"><?= htmlspecialchars($cv['description']) ?></td>
                                <td style="font-weight: 700; color: #ef4444; font-size: 15px;">₱<?= number_format($cv['amount'], 2) ?></td>
                                <td>
                                    <?php $cvStatus = $cv['voucher_status'] ?? 'Printed'; ?>
                                    <span class="voucher-status"><?= htmlspecialchars($cvStatus) ?></span>
                                </td>
                                <td>
                                    <a href="print_check_voucher.php?cv=<?= $cv['or_number'] ?>" target="_blank" class="btn-print">
                                        <i class="fa-solid fa-print"></i> Print
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align: center; padding: 40px; color: #94a3b8;"><i class="fa-solid fa-folder-open" style="font-size: 30px; margin-bottom: 10px; display: block; color: #cbd5e1;"></i>No Check Vouchers recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if(!empty($cash_vouchers)): ?>
            <div class="table-container">
                <div class="table-header">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #1e293b;"><i class="fa-solid fa-money-bill-transfer" style="color:#f59e0b; margin-right: 8px;"></i> Recent Cash Vouchers Out</h3>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>CVO Number</th>
                            <th>Payee / Employee</th>
                            <th>Cash Purpose</th>
                            <th>Particulars</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($cash_vouchers as $cvo): ?>
                            <?php
                                $cvoDesc = $cvo['description'] ?? '';
                                $cashPurpose = 'Cash Disbursement';
                                if(preg_match('/Purpose:\s*([^|]+)/i', $cvoDesc, $m)){
                                    $cashPurpose = trim($m[1]);
                                }
                            ?>
                            <tr>
                                <td style="font-weight: 600; color: #64748b; font-size: 13px;"><?= date('M d, Y', strtotime($cvo['transaction_date'])) ?></td>
                                <td><strong style="color:#d97706;"><?= htmlspecialchars($cvo['or_number']) ?></strong></td>
                                <td style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($cvo['payee'] ?? '') ?></td>
                                <td>
                                    <div style="font-size:13px;font-weight:800;color:#92400e;"><?= htmlspecialchars($cashPurpose) ?></div>
                                    <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Mode: Cash</div>
                                </td>
                                <td style="font-size: 12px; color: #475569; max-width: 250px;"><?= htmlspecialchars($cvoDesc) ?></td>
                                <td style="font-weight: 700; color: #ef4444; font-size: 15px;">₱<?= number_format((float)$cvo['amount'], 2) ?></td>
                                <td><span class="voucher-status">Released</span></td>
                                <td>
                                    <a href="print_cash_voucher.php?cvo=<?= urlencode($cvo['or_number']) ?>" target="_blank" class="btn-print" style="background:#f59e0b;">
                                        <i class="fa-solid fa-print"></i> Print
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
        </div>
    </div>

    <div id="incomeModal" class="modal">
        <div class="modal-content modal-sm">
            <div class="modal-header">
                <h2><i class="fa-solid fa-plus-circle" style="color: #10b981;"></i> Add Income</h2>
                <button type="button" class="close-btn" onclick="closeModal('incomeModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" style="padding: 25px;">
                <div class="form-group">
                    <label>Amount (₱)</label>
                    <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label>Date Received</label>
                    <input type="date" name="trans_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Income Source</label>
                    <input type="text" name="income_source" class="form-control" placeholder="e.g., Lot payment, Bank interest">
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method" class="form-control">
                        <option value="Cash">Cash</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Check">Check</option>
                        <option value="GCash / Online Wallet">GCash / Online Wallet</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Received By</label>
                    <input type="text" name="received_by" class="form-control" value="<?= htmlspecialchars($_SESSION['fullname'] ?? 'Administrator') ?>">
                </div>
                <div class="form-group">
                    <label>OR / Reference Number (Optional)</label>
                    <input type="text" name="or_number" class="form-control" placeholder="Leave blank to auto-generate">
                </div>
                <div class="form-group">
                    <label>Description / Particulars</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="E.g., Bank Interest, Miscellaneous Cash..." required></textarea>
                </div>
                
                <div style="margin-top: 20px; text-align: right; border-top: 1px solid var(--gray-border); padding-top: 15px;">
                    <button type="button" onclick="closeModal('incomeModal')" style="background:#f1f5f9; color:#475569; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; margin-right: 10px;">Cancel</button>
                    <button type="submit" name="add_income" style="background:#10b981; color:white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer;"><i class="fa-solid fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>

    <div id="billsModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header" style="background: #fffbeb;">
                <h2><i class="fa-solid fa-cash-register" style="color: #f59e0b;"></i> Record Transaction (Enter Bills)</h2>
                <button type="button" class="close-btn" onclick="closeModal('billsModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" style="padding: 25px;">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Transaction Date</label>
                        <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <input type="hidden" name="type" value="EXPENSE">
                    <div class="form-group">
                        <label>Expense Category (Bills, Vouchers, etc.)</label>
                        <select name="category_id" class="form-control" required>
                            <?php renderExpenseCategoryOptions($conn); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Supplier / Payee</label>
                        <input type="text" name="payee" class="form-control" placeholder="e.g., Meralco, Supplier Name, Contractor">
                    </div>
                    <div class="form-group">
                        <label>Project Tracker</label>
                        <select name="project_id" class="form-control" required>
                            <?php
                            $projs = $conn->query("SELECT * FROM projects ORDER BY name");
                            while($p = $projs->fetch_assoc()){ echo "<option value='{$p['id']}'>{$p['name']}</option>"; }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (PHP)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Payment Status</label>
                        <select name="bill_status" class="form-control">
                            <option value="Unpaid">Unpaid</option>
                            <option value="Paid">Paid</option>
                            <option value="For Payment">For Payment</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label>Description / Notes</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g., Payment for Internet Bill">
                    </div>
                </div>
                
                <div style="margin-top: 15px; text-align: right; border-top: 1px solid var(--gray-border); padding-top: 15px;">
                    <button type="button" onclick="closeModal('billsModal')" style="background:#f1f5f9; color:#475569; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; margin-right: 10px;">Cancel</button>
                    <button type="submit" name="enter_bill" style="background:#f59e0b; color:white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer;"><i class="fa-solid fa-file-invoice-dollar"></i> Save Bill & Generate Voucher</button>
                </div>
            </form>
        </div>
    </div>

    <div id="checkModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header" style="background: #f5f3ff;">
                <h2><i class="fa-solid fa-money-check" style="color: #8b5cf6;"></i> Issue Check Voucher</h2>
                <button type="button" class="close-btn" onclick="closeModal('checkModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" style="padding: 25px;">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Date Issued</label>
                        <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Amount (PHP)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" style="font-weight: 800; color: #ef4444;" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Payee Name (Who receives the check)</label>
                        <input type="text" name="payee" class="form-control" placeholder="e.g., John Doe Surveying Services" required>
                    </div>

                    <div class="form-group">
                        <label>Bank Name</label>
                        <input type="text" name="bank_name" class="form-control" placeholder="e.g., BDO, Metrobank" required>
                    </div>
                    <div class="form-group">
                        <label>Check Number</label>
                        <input type="text" name="check_number" class="form-control" placeholder="e.g., 000123456" required>
                    </div>

                    <div class="form-group">
                        <label>Expense Accounting Category</label>
                        <select name="category_id" class="form-control" required>
                            <?php renderExpenseCategoryOptions($conn); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Project Assignment</label>
                        <select name="project_id" class="form-control" required>
                            <?php
                            $projs = $conn->query("SELECT * FROM projects ORDER BY name");
                            while($p = $projs->fetch_assoc()){ echo "<option value='{$p['id']}'>{$p['name']}</option>"; }
                            ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label>Particulars / Description</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g., Downpayment for Phase 1 Land Subdividing" required>
                    </div>
                    <div class="form-group">
                        <label>Prepared By</label>
                        <input type="text" name="prepared_by" class="form-control" value="<?= htmlspecialchars($_SESSION['fullname'] ?? 'Administrator') ?>">
                    </div>
                    <div class="form-group">
                        <label>Checked By</label>
                        <input type="text" name="checked_by" class="form-control" placeholder="Name of checker">
                    </div>
                    <div class="form-group">
                        <label>Approved By</label>
                        <input type="text" name="approved_by" class="form-control" placeholder="Name of approver">
                    </div>
                    <div class="form-group">
                        <label>Released To</label>
                        <input type="text" name="released_to" class="form-control" placeholder="Name of receiver">
                    </div>
                </div>
                
                <div style="margin-top: 15px; text-align: right; border-top: 1px solid var(--gray-border); padding-top: 15px;">
                    <button type="button" onclick="closeModal('checkModal')" style="background:#f1f5f9; color:#475569; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; margin-right: 10px;">Cancel</button>
                    <button type="submit" name="issue_check" style="background:#8b5cf6; color:white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer;"><i class="fa-solid fa-print"></i> Save & Print Voucher</button>
                </div>
            </form>
        </div>
    </div>

    <div id="cashVoucherModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header" style="background: #fff7ed;">
                <h2><i class="fa-solid fa-money-bill-transfer" style="color:#f59e0b;"></i> Cash Voucher Out</h2>
                <button type="button" class="close-btn" onclick="closeModal('cashVoucherModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" style="padding: 25px;">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Date Released</label>
                        <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Amount (PHP)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" style="font-weight: 800; color: #f59e0b;" required>
                    </div>

                    <div class="form-group">
                        <label>Payee / Employee Name</label>
                        <input type="text" name="payee" class="form-control" placeholder="e.g., Employee Name / Supplier" required>
                    </div>
                    <div class="form-group">
                        <label>Cash Purpose</label>
                        <select name="cash_purpose" class="form-control" required>
                            <option value="Payroll / Salary Release">Payroll / Salary Release</option>
                            <option value="Cash Advance">Cash Advance</option>
                            <option value="Petty Cash">Petty Cash</option>
                            <option value="Reimbursement">Reimbursement</option>
                            <option value="Office Cash Expense">Office Cash Expense</option>
                            <option value="Other Cash Disbursement">Other Cash Disbursement</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Expense Accounting Category</label>
                        <select name="category_id" class="form-control" required>
                            <?php renderExpenseCategoryOptions($conn); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Project Assignment</label>
                        <select name="project_id" class="form-control" required>
                            <?php
                            $projs = $conn->query("SELECT * FROM projects ORDER BY name");
                            while($p = $projs->fetch_assoc()){ echo "<option value='{$p['id']}'>{$p['name']}</option>"; }
                            ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label>Particulars / Description</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g., Salary release for June 2026 / petty cash replenishment" required>
                    </div>

                    <div class="form-group">
                        <label>Prepared By</label>
                        <input type="text" name="prepared_by" class="form-control" value="<?= htmlspecialchars($_SESSION['fullname'] ?? 'Administrator') ?>">
                    </div>
                    <div class="form-group">
                        <label>Checked By</label>
                        <input type="text" name="checked_by" class="form-control" placeholder="Name of checker">
                    </div>
                    <div class="form-group">
                        <label>Approved By</label>
                        <input type="text" name="approved_by" class="form-control" placeholder="Name of approver">
                    </div>
                    <div class="form-group">
                        <label>Released To</label>
                        <input type="text" name="released_to" class="form-control" placeholder="Name of cash receiver">
                    </div>
                </div>

                <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px;margin-top:10px;color:#92400e;font-size:12px;line-height:1.5;">
                    <strong>Note:</strong> Use Cash Voucher Out for salary release, cash advance, petty cash, reimbursement, and other cash disbursements. This will be recorded as an EXPENSE in the financial ledger.
                </div>

                <div style="margin-top: 15px; text-align: right; border-top: 1px solid var(--gray-border); padding-top: 15px;">
                    <button type="button" onclick="closeModal('cashVoucherModal')" style="background:#f1f5f9; color:#475569; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; margin-right: 10px;">Cancel</button>
                    <button type="submit" name="cash_voucher_out" style="background:#f59e0b; color:white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer;"><i class="fa-solid fa-money-bill-transfer"></i> Save Cash Voucher Out</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Modal Functions
    function openModal(id) {
        document.getElementById(id).style.display = 'flex';
    }
    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const formatCurrency = (val) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 0 }).format(val);

        // Chart Initialization
        <?php if($has_finance_data): ?>
        const ctx = document.getElementById('financeChart').getContext('2d');
        window.financeChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_dates) ?>,
                datasets: [
                    {
                        label: 'Income',
                        data: <?= json_encode($chart_income_totals) ?>,
                        backgroundColor: 'rgba(16, 185, 129, 0.85)',
                        hoverBackgroundColor: 'rgba(5, 150, 105, 1)',
                        borderRadius: 6,
                        barThickness: 22
                    },
                    {
                        label: 'Expense',
                        data: <?= json_encode($chart_expense_totals) ?>,
                        backgroundColor: 'rgba(239, 68, 68, 0.85)',
                        hoverBackgroundColor: 'rgba(220, 38, 38, 1)',
                        borderRadius: 6,
                        barThickness: 22
                    }
                ]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { 
                    legend: { display: true, labels: { font: { family: 'Inter', size: 11 } } },
                    tooltip: {
                        callbacks: { label: function(context) { return ' ' + context.dataset.label + ': ' + formatCurrency(context.raw); } }
                    } 
                }, 
                scales: { 
                    y: { 
                        beginAtZero: true, 
                        grid: { color: '#e2e8f0' }, 
                        border: { display: false },
                        ticks: { font: {family: 'Inter', size: 10}, color: '#94a3b8', callback: function(value) { 
                            if(value >= 1000000) return '₱' + (value/1000000).toFixed(1) + 'M';
                            if(value >= 1000) return '₱' + (value/1000).toFixed(1) + 'K';
                            return '₱' + value; 
                        } }
                    }, 
                    x: { 
                        grid: { display: false },
                        ticks: { font: {family: 'Inter', size: 11}, color: '#64748b' }
                    } 
                } 
            }
        });
        <?php endif; ?>

        // Upcoming Payables replaced the old calendar widget.
    });
    </script>
    <script>
function toggleFinanceMenu(event){
    event.preventDefault();
    event.stopPropagation();

    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');

    submenu.classList.toggle('show');

    arrow.style.transform = submenu.classList.contains('show')
        ? 'rotate(180deg)'
        : 'rotate(0deg)';
}
</script>

<script>
function toggleSidebar(){
    if(window.innerWidth <= 768){
        document.body.classList.toggle('sidebar-open');
    } else {
        document.body.classList.toggle('sidebar-collapsed');
    }

    setTimeout(function(){
        if(window.financeChartInstance){
            window.financeChartInstance.resize();
        }
    }, 280);
}
</script>
</body>
</html>
