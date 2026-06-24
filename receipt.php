<?php
// receipt.php
require_once 'config.php';

requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER', 'CASHIER', 'BUYER']);

if(!isset($_GET['id'])){
    die("Invalid Request");
}

$id = (int)$_GET['id']; // reservation id

// Buyer can only access own receipt/reservation.
if ($_SESSION['role'] === 'BUYER') {
    $own = $conn->prepare("SELECT id FROM reservations WHERE id = ? AND user_id = ? LIMIT 1");
    $own->bind_param("ii", $id, $_SESSION['user_id']);
    $own->execute();
    if($own->get_result()->num_rows === 0){
        die("<div style='padding:40px;text-align:center;color:red;font-family:Arial;'><h2>Access Denied</h2><p>You cannot access this receipt.</p></div>");
    }
}

// Fetch Reservation Data
$stmt = $conn->prepare("SELECT r.*, u.fullname, u.email, u.phone, l.block_no, l.lot_no, l.area, l.price_per_sqm, l.total_price, l.location, l.property_type 
                        FROM reservations r 
                        JOIN users u ON r.user_id = u.id 
                        JOIN lots l ON r.lot_id = l.id 
                        WHERE r.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if(!$data) die("Reservation not found.");

// Receipt should be issued only for verified payments.
$has_payment_status = false;
$colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'payment_status'");
if($colCheck && $colCheck->num_rows > 0) $has_payment_status = true;

if($has_payment_status){
    $tx = $conn->prepare("SELECT * FROM transactions WHERE reservation_id = ? AND type='INCOME' AND payment_status='VERIFIED' ORDER BY transaction_date DESC, id DESC LIMIT 1");
} else {
    $like = "%Res#$id%";
    $tx = $conn->prepare("SELECT * FROM transactions WHERE type='INCOME' AND description LIKE ? ORDER BY transaction_date DESC, id DESC LIMIT 1");
}

if($has_payment_status){
    $tx->bind_param("i", $id);
} else {
    $tx->bind_param("s", $like);
}
$tx->execute();
$transaction = $tx->get_result()->fetch_assoc();

if(!$transaction){
    die("<div style='padding:40px;text-align:center;color:#b91c1c;font-family:Arial;'><h2>No Verified Payment Found</h2><p>Receipt can only be generated after the payment is VERIFIED.</p></div>");
}

if(isset($transaction['payment_status']) && in_array($transaction['payment_status'], ['REJECTED','VOIDED','PENDING'])){
    die("<div style='padding:40px;text-align:center;color:#b91c1c;font-family:Arial;'><h2>Invalid Receipt</h2><p>This payment is " . htmlspecialchars($transaction['payment_status']) . ". Only VERIFIED payments can generate receipts.</p></div>");
}

$payment_term_display = (strtoupper($data['payment_type'] ?? '') === 'CASH') ? 'Full Payment (Spot Cash)' : 'Downpayment / Installment';
$receipt_no = !empty($transaction['or_number']) ? $transaction['or_number'] : ('OR-' . str_pad($transaction['id'], 6, '0', STR_PAD_LEFT));
$receipt_date = !empty($transaction['transaction_date']) ? $transaction['transaction_date'] : ($data['reservation_date'] ?? date('Y-m-d'));
$amount_paid = (float)($transaction['amount'] ?? 0);
function e_receipt($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Official Receipt <?= e_receipt($receipt_no) ?> - JEJ Top Priority Corp</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body{font-family:'Inter',sans-serif;background:#f4f6f8;color:#333;margin:0;padding:40px 20px;display:flex;justify-content:center}.receipt-container{background:#fff;width:100%;max-width:850px;padding:50px 60px;box-shadow:0 8px 30px rgba(0,0,0,.08);border-top:8px solid #2e7d32;position:relative;box-sizing:border-box}.header-section{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #edf2f7;padding-bottom:25px;margin-bottom:35px}.company-info h1{margin:0 0 5px;font-size:26px;font-weight:800;color:#1b5e20}.company-info p{margin:3px 0;font-size:13px;color:#555;line-height:1.5}.receipt-title{text-align:right}.receipt-title h2{margin:0 0 5px;font-size:28px;font-weight:800;color:#2d3748;text-transform:uppercase}.receipt-title p{margin:0;font-size:14px;color:#718096;font-weight:500}.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-bottom:40px}.info-box{background:#fafafa;padding:20px;border-radius:8px;border:1px solid #edf2f7}.info-box h3{margin:0 0 15px;font-size:13px;text-transform:uppercase;color:#2e7d32;font-weight:700;border-bottom:1px solid #e2e8f0;padding-bottom:8px}.info-row{display:flex;margin-bottom:8px;font-size:13px;line-height:1.5}.info-row span.label{width:120px;color:#718096;font-weight:500}.info-row span.value{flex:1;color:#2d3748;font-weight:600}table{width:100%;border-collapse:collapse;margin-bottom:30px}th{background:#f7fafc;color:#4a5568;text-align:left;padding:14px 16px;font-size:12px;font-weight:700;text-transform:uppercase;border-bottom:2px solid #e2e8f0;border-top:1px solid #e2e8f0}td{padding:16px;font-size:14px;color:#2d3748;border-bottom:1px solid #edf2f7;vertical-align:top}.text-right{text-align:right}.text-center{text-align:center}.item-title{font-weight:700;color:#1a202c;margin-bottom:4px;display:block}.item-desc{font-size:12px;color:#718096}.totals-container{display:flex;justify-content:flex-end;margin-bottom:50px}.totals-box{width:350px;background:#f8fafc;border:1px solid #edf2f7;border-radius:8px;padding:20px}.total-row{display:flex;justify-content:space-between;padding:8px 0;font-size:14px;color:#4a5568}.total-row.grand-total{border-top:2px solid #e2e8f0;margin-top:10px;padding-top:15px;font-size:18px;font-weight:800;color:#2e7d32}.signatures{display:flex;justify-content:space-between;margin-top:60px;padding-top:20px}.sig-box{width:40%;text-align:center}.sig-line{border-top:1px solid #a0aec0;margin-bottom:8px;padding-top:8px;font-weight:700;color:#2d3748;font-size:15px}.sig-title{font-size:12px;color:#718096;text-transform:uppercase}.footer{margin-top:50px;text-align:center;font-size:11px;color:#a0aec0;border-top:1px solid #edf2f7;padding-top:20px}.btn-print{position:fixed;bottom:40px;right:40px;background:#2e7d32;color:white;padding:16px 32px;border:none;border-radius:50px;cursor:pointer;font-weight:700;font-size:15px;box-shadow:0 10px 20px rgba(46,125,50,.2)}@media print{body{background:white;padding:0;display:block}.receipt-container{box-shadow:none;border-top:none;width:100%;max-width:100%;padding:20px}.btn-print{display:none}}
</style>
</head>
<body>
<button class="btn-print" onclick="window.print()">Print Receipt</button>
<div class="receipt-container">
<div class="header-section"><div class="company-info"><h1>JEJ Top Priority Corporation</h1><p>San Francisco, Nueva Ecija, Philippines</p><p>Tel: (02) 8724-4244</p><p>Email: customerservice@jejsurveying.com</p></div><div class="receipt-title"><h2>Official Receipt</h2><p>OR / Ref Number: <strong><?= e_receipt($receipt_no) ?></strong></p><p>Date Issued: <strong><?= date('F d, Y', strtotime($receipt_date)) ?></strong></p><p>Status: <strong style="color:#166534;">VERIFIED</strong></p></div></div>
<div class="info-grid"><div class="info-box"><h3>Billed To / Buyer Details</h3><div class="info-row"><span class="label">Client Name:</span><span class="value"><?= e_receipt($data['fullname']) ?></span></div><div class="info-row"><span class="label">Contact No:</span><span class="value"><?= e_receipt($data['phone'] ?? $data['contact_number'] ?? '') ?></span></div><div class="info-row"><span class="label">Email Address:</span><span class="value"><?= e_receipt($data['email']) ?></span></div><div class="info-row"><span class="label">Home Address:</span><span class="value"><?= e_receipt($data['buyer_address'] ?? '') ?></span></div></div><div class="info-box"><h3>Transaction Details</h3><div class="info-row"><span class="label">Reservation ID:</span><span class="value">RES-<?= date('Y') ?>-<?= str_pad($data['id'],5,'0',STR_PAD_LEFT) ?></span></div><div class="info-row"><span class="label">Payment Terms:</span><span class="value" style="color:#0ea5e9;font-weight:700;"><?= e_receipt($payment_term_display) ?></span></div><div class="info-row"><span class="label">Description:</span><span class="value"><?= e_receipt($transaction['description']) ?></span></div></div></div>
<table><thead><tr><th width="45%">Property Description</th><th class="text-center" width="15%">Lot Area</th><th class="text-right" width="20%">TCP</th><th class="text-right" width="20%">Amount Paid</th></tr></thead><tbody><tr><td><span class="item-title">Block <?= e_receipt($data['block_no']) ?>, Lot <?= e_receipt($data['lot_no']) ?></span><span class="item-desc"><?= e_receipt($data['property_type']) ?><br>Location: <?= e_receipt($data['location']) ?></span></td><td class="text-center"><?= number_format((float)$data['area'],2) ?> m²</td><td class="text-right">₱<?= number_format((float)$data['total_price'],2) ?></td><td class="text-right" style="font-weight:700;color:#166534;">₱<?= number_format($amount_paid,2) ?></td></tr></tbody></table>
<div class="totals-container"><div class="totals-box"><div class="total-row"><span>Payment Received</span><span>₱<?= number_format($amount_paid,2) ?></span></div><div class="total-row"><span>OR / Reference</span><span><?= e_receipt($receipt_no) ?></span></div><div class="total-row grand-total"><span>Verified Amount</span><span>₱<?= number_format($amount_paid,2) ?></span></div></div></div>
<div class="signatures"><div class="sig-box"><div class="sig-line"><?= e_receipt($data['fullname']) ?></div><div class="sig-title">Buyer's Printed Name & Signature</div></div><div class="sig-box"><div class="sig-line">System Administrator</div><div class="sig-title">Authorized Representative</div></div></div>
<div class="footer"><p>This receipt is valid only for VERIFIED payments. Pending, rejected, and voided payments are not official receipts.<br><strong>JEJ Top Priority Corporation</strong> | All Rights Reserved © <?= date('Y') ?></p></div>
</div>
</body>
</html>
