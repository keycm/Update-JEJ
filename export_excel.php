<?php
require 'config.php';
checkAdmin();

logActivity($conn, $_SESSION['user_id'], "Exported Transactions to Excel");

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=financial_report_' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, array('OR Number', 'Date', 'Type', 'Category', 'Project', 'Amount', 'Description', 'Processed By'));

$query = "SELECT t.or_number, t.transaction_date, t.type, c.name as category, p.name as project, t.amount, t.description, u.fullname 
          FROM transactions t 
          JOIN accounting_categories c ON t.category_id = c.id
          JOIN projects p ON t.project_id = p.id
          JOIN users u ON t.user_id = u.id ORDER BY t.transaction_date DESC";

$result = $conn->query($query);
while($row = $result->fetch_assoc()) {
    fputcsv($output, $row);
}
fclose($output);
exit();
?>