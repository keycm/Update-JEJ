<?php
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    die('Access Denied');
}

$user_id = (int)$_SESSION['user_id'];
$tx_id = isset($_GET['tx_id']) ? (int)$_GET['tx_id'] : 0;
if ($tx_id <= 0) {
    die('Invalid request.');
}

$txStmt = $conn->prepare("SELECT id, or_number, transaction_date, amount, description, user_id FROM transactions WHERE id = ? AND type = 'INCOME' LIMIT 1");
$txStmt->bind_param('i', $tx_id);
$txStmt->execute();
$tx = $txStmt->get_result()->fetch_assoc();
$txStmt->close();

if (!$tx || (int)$tx['user_id'] !== $user_id || stripos($tx['description'], 'Down Payment') === false) {
    die('Receipt not found or not authorized.');
}

$res_id = 0;
if (preg_match('/Res#(\d+)/', $tx['description'], $m)) {
    $res_id = (int)$m[1];
}
if ($res_id <= 0) {
    die('Invalid receipt reference.');
}

$resStmt = $conn->prepare(
    "SELECT r.id, r.reservation_date, r.contact_number, r.email, r.buyer_address, r.payment_type,
            u.fullname,
            l.block_no, l.lot_no, l.area, l.price_per_sqm, l.total_price, l.location, l.property_type
     FROM reservations r
     JOIN users u ON u.id = r.user_id
     JOIN lots l ON l.id = r.lot_id
     WHERE r.id = ? AND r.user_id = ? LIMIT 1"
);
$resStmt->bind_param('ii', $res_id, $user_id);
$resStmt->execute();
$data = $resStmt->get_result()->fetch_assoc();
$resStmt->close();

if (!$data) {
    die('Reservation not found.');
}

$dp_amount = (float)$tx['amount'];
$is_spot_cash = (strtoupper($data['payment_type'] ?? '') === 'CASH');
$balance_after_dp = $is_spot_cash ? 0.0 : (float)$data['total_price'] - $dp_amount;

$payment_method = 'N/A';
$payment_reference = '-';
if (preg_match('/Method:\s*([^|]+)/i', $tx['description'], $mMethod)) {
    $payment_method = strtoupper(trim($mMethod[1]));
}
if (preg_match('/Ref:\s*([^|]+)/i', $tx['description'], $mRef)) {
    $payment_reference = trim($mRef[1]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Receipt - <?= htmlspecialchars($tx['or_number']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #eef2f7; margin: 0; padding: 24px; }
        .wrap { max-width: 860px; margin: 0 auto; }
        .print-btn { position: fixed; top: 18px; right: 18px; border: 0; background: #2f855a; color: #fff; padding: 12px 18px; border-radius: 999px; font-weight: 700; cursor: pointer; }
        .receipt { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #2f855a; padding-bottom: 12px; margin-bottom: 18px; }
        .title { font-size: 22px; font-weight: 800; color: #1a202c; }
        .meta { color: #4a5568; font-size: 13px; text-align: right; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 14px; }
        .box h3 { font-size: 13px; color: #2f855a; margin: 0 0 10px; text-transform: uppercase; }
        .row { display: flex; justify-content: space-between; gap: 10px; font-size: 13px; padding: 4px 0; border-bottom: 1px dashed #edf2f7; }
        .row span:first-child { color: #718096; }
        .row span:last-child { color: #1a202c; font-weight: 700; }
        .amount-card { margin-top: 20px; border: 1px solid #c6f6d5; background: #f0fff4; border-radius: 12px; padding: 14px; }
        .amount-card .label { font-size: 12px; color: #2f855a; text-transform: uppercase; font-weight: 700; }
        .amount-card .value { font-size: 30px; font-weight: 800; color: #22543d; margin-top: 4px; }
        .foot { margin-top: 26px; font-size: 12px; color: #718096; border-top: 1px solid #edf2f7; padding-top: 12px; text-align: center; }
        @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }
        @media print {
            body { background: #fff; padding: 0; }
            .print-btn { display: none; }
            .receipt { box-shadow: none; border: none; border-radius: 0; }
        }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">Print Receipt</button>
    <div class="wrap">
        <div class="receipt">
            <div class="header">
                <div class="title">Acknowledgement Receipt</div>
                <div class="meta">
                    OR No: <strong><?= htmlspecialchars($tx['or_number']) ?></strong><br>
                    Date Paid: <strong><?= date('F d, Y', strtotime($tx['transaction_date'])) ?></strong><br>
                    Method: <strong><?= htmlspecialchars($payment_method) ?></strong>
                </div>
            </div>

            <div class="grid">
                <div class="box">
                    <h3>Buyer Information</h3>
                    <div class="row"><span>Name</span><span><?= htmlspecialchars($data['fullname']) ?></span></div>
                    <div class="row"><span>Contact</span><span><?= htmlspecialchars($data['contact_number'] ?? '-') ?></span></div>
                    <div class="row"><span>Email</span><span><?= htmlspecialchars($data['email'] ?? '-') ?></span></div>
                    <div class="row"><span>Address</span><span><?= htmlspecialchars($data['buyer_address'] ?? '-') ?></span></div>
                </div>

                <div class="box">
                    <h3>Property Information</h3>
                    <div class="row"><span>Property</span><span>Block <?= htmlspecialchars($data['block_no']) ?> Lot <?= htmlspecialchars($data['lot_no']) ?></span></div>
                    <div class="row"><span>Type</span><span><?= htmlspecialchars($data['property_type'] ?? '-') ?></span></div>
                    <div class="row"><span>Location</span><span><?= htmlspecialchars($data['location'] ?? '-') ?></span></div>
                    <div class="row"><span>Total Contract Price</span><span>PHP <?= number_format((float)$data['total_price'], 2) ?></span></div>
                </div>
            </div>

            <div class="amount-card">
                <div class="label"><?= $is_spot_cash ? 'Received as Full Payment (Spot Cash)' : 'Received as Down Payment' ?></div>
                <div class="value">PHP <?= number_format($dp_amount, 2) ?></div>
                <div style="margin-top:6px; font-size:13px; color:#2f855a;">Reference No.: <strong><?= htmlspecialchars($payment_reference) ?></strong></div>
                <?php if ($is_spot_cash): ?>
                <div style="margin-top:6px; font-size:13px; color:#2f855a; font-weight:700;">Remaining Balance: <strong style="color:#22543d;">FULLY PAID</strong></div>
                <?php else: ?>
                <div style="margin-top:6px; font-size:13px; color:#2f855a;">Remaining Balance: <strong>PHP <?= number_format($balance_after_dp, 2) ?></strong></div>
                <?php endif; ?>
            </div>

            <div class="foot">
                This document is system-generated as proof of <?= $is_spot_cash ? 'full payment (spot cash)' : 'down payment' ?> for Reservation #<?= (int)$data['id'] ?>.
            </div>
        </div>
    </div>
</body>
</html>
