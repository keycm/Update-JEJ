<?php
require 'config.php';
checkAdmin();

if(!isset($_GET['or'])) die("No OR Number provided.");
$or = $_GET['or'];

$query = "SELECT t.*, c.name as category, p.name as project, u.fullname FROM transactions t 
          JOIN accounting_categories c ON t.category_id = c.id
          JOIN projects p ON t.project_id = p.id
          JOIN users u ON t.user_id = u.id WHERE t.or_number = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $or);
$stmt->execute();
$res = $stmt->get_result();
$t = $res->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Voucher - <?= $or ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #fff; color: #000; }
        .voucher-box { border: 2px dashed #000; padding: 30px; margin-top: 50px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div class="container">
    <div class="voucher-box">
        <h2 class="text-center">CAFE EMMANUEL / ECO LAND</h2>
        <h4 class="text-center">Official <?= $t['type'] == 'EXPENSE' ? 'Payment Voucher' : 'Receipt' ?></h4>
        <hr>
        <div class="row mb-4">
            <div class="col-6"><strong>OR Number:</strong> <?= $t['or_number'] ?></div>
            <div class="col-6 text-end"><strong>Date:</strong> <?= $t['transaction_date'] ?></div>
        </div>
        <table class="table table-bordered">
            <tr><th>Category</th><td><?= $t['category'] ?></td></tr>
            <tr><th>Project</th><td><?= $t['project'] ?></td></tr>
            <tr><th>Description</th><td><?= $t['description'] ?></td></tr>
            <tr><th>Amount</th><td><strong>PHP <?= number_format($t['amount'], 2) ?></strong></td></tr>
        </table>
        <div class="mt-5 row text-center">
            <div class="col-6">
                <hr style="width: 80%; margin:auto;">
                <p>Prepared By: <?= $t['fullname'] ?></p>
            </div>
            <div class="col-6">
                <hr style="width: 80%; margin:auto;">
                <p>Received / Approved By</p>
            </div>
        </div>
    </div>
    <div class="text-center mt-4 no-print">
        <button onclick="window.print()" class="btn btn-primary">Print / Save as PDF</button>
        <a href="pos.php" class="btn btn-secondary">Back to POS</a>
    </div>
</div>
</body>
</html>