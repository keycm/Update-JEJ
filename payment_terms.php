<?php
// payment_terms.php

require_once 'config.php';

// Role Access
requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER']);

// Manager Permission Access
if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'res_terms');
}

if(!isset($_GET['res_id'])){
    header("Location: reservation.php");
    exit();
}

$res_id = (int)$_GET['res_id'];
$alert_msg = "";
$alert_type = "";

function money2($amount){
    return number_format((float)$amount, 2);
}

function normalizePaymentType($type){
    $type = strtoupper(trim((string)$type));

    if(
        $type === 'CASH' ||
        $type === 'SPOT CASH' ||
        $type === 'SPOT_CASH' ||
        $type === 'SPOTCASH'
    ){
        return 'CASH';
    }

    return 'INSTALLMENT';
}

function computePaymentTerms($total_price, $payment_type, $reservation_fee = 5000, $term_months = 36){
    $payment_type = normalizePaymentType($payment_type);
    $total_price = (float)$total_price;
    $reservation_fee = (float)$reservation_fee;

    if($reservation_fee <= 0){
        $reservation_fee = 5000;
    }

    if($payment_type === 'CASH'){
        // Spot Cash policy
        $discount_percent = 5.00;
        $required_dp = 0.00;
        $term_months = 0;
    } else {
        // Installment policy
        $discount_percent = 3.00;
        $term_months = (int)$term_months;
        if(!in_array($term_months, [12, 24, 36], true)){
            $term_months = 36;
        }
    }

    $discount_amount = round($total_price * ($discount_percent / 100), 2);
    $tcp_after_discount = round(max($total_price - $discount_amount, 0), 2);
    $net_tcp_after_reservation = round(max($tcp_after_discount - $reservation_fee, 0), 2);

    if($payment_type === 'CASH'){
        $required_dp = 0.00;
        $balance_to_finance = 0.00;
        $balance_due = $net_tcp_after_reservation;
        $monthly_payment = 0.00;
    } else {
        $required_dp = round($net_tcp_after_reservation * 0.20, 2);
        $balance_to_finance = round(max($net_tcp_after_reservation - $required_dp, 0), 2);
        $balance_due = 0.00;
        $monthly_payment = round($balance_to_finance / $term_months, 2);
    }

    return [
        'payment_type' => $payment_type,
        'discount_percent' => $discount_percent,
        'discount_amount' => $discount_amount,
        'reservation_fee' => $reservation_fee,
        'tcp_after_discount' => $tcp_after_discount,
        'net_tcp_after_reservation' => $net_tcp_after_reservation,
        'required_dp' => $required_dp,
        'balance_to_finance' => $balance_to_finance,
        'balance_due' => $balance_due,
        'installment_months' => $term_months,
        'monthly_payment' => $monthly_payment
    ];
}

// Fetch Full Reservation Data
$stmt = $conn->prepare("
    SELECT
        r.*,
        u.fullname,
        u.phone,
        u.email,
        l.block_no,
        l.lot_no,
        l.total_price,
        l.area,
        l.property_type,
        l.location
    FROM reservations r
    JOIN users u ON r.user_id = u.id
    JOIN lots l ON r.lot_id = l.id
    WHERE r.id = ?
");
$stmt->bind_param("i", $res_id);
$stmt->execute();
$resData = $stmt->get_result()->fetch_assoc();

if(!$resData){
    die("Reservation not found in the system.");
}

$total_price = (float)$resData['total_price'];
$current_type = normalizePaymentType($resData['payment_type'] ?? 'INSTALLMENT');
$current_months = (int)($resData['installment_months'] ?? 36);
$current_reservation_fee = (float)($resData['reservation_fee'] ?? 5000);

$terms = computePaymentTerms(
    $total_price,
    $current_type,
    $current_reservation_fee,
    $current_months
);

// Handle Form Submission
if(isset($_POST['save_terms'])){
    $type = normalizePaymentType($_POST['payment_type'] ?? 'INSTALLMENT');
    $months = ($type === 'INSTALLMENT') ? (int)($_POST['term_months'] ?? 36) : 0;

    $terms = computePaymentTerms(
        $total_price,
        $type,
        $current_reservation_fee,
        $months
    );

    $stmt = $conn->prepare("
        UPDATE reservations
        SET
            payment_type = ?,
            discount_percent = ?,
            discount_amount = ?,
            reservation_fee = ?,
            tcp_after_discount = ?,
            net_tcp_after_reservation = ?,
            required_dp = ?,
            installment_months = ?,
            monthly_payment = ?
        WHERE id = ?
    ");

    if(!$stmt){
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param(
        "sddddddidi",
        $terms['payment_type'],
        $terms['discount_percent'],
        $terms['discount_amount'],
        $terms['reservation_fee'],
        $terms['tcp_after_discount'],
        $terms['net_tcp_after_reservation'],
        $terms['required_dp'],
        $terms['installment_months'],
        $terms['monthly_payment'],
        $res_id
    );

    if($stmt->execute()){
        $alert_msg = "Payment terms have been successfully updated using the buyer dashboard computation.";
        $alert_type = "success";

        if (function_exists('logActivity')) {
            logActivity(
                $conn,
                $_SESSION['user_id'],
                "Updated Payment Terms",
                "Res ID: $res_id | Type: {$terms['payment_type']} | Discount: {$terms['discount_percent']}% | Required DP: ₱" . money2($terms['required_dp'])
            );
        }

        // Refresh local data after save
        $resData['payment_type'] = $terms['payment_type'];
        $resData['discount_percent'] = $terms['discount_percent'];
        $resData['discount_amount'] = $terms['discount_amount'];
        $resData['reservation_fee'] = $terms['reservation_fee'];
        $resData['tcp_after_discount'] = $terms['tcp_after_discount'];
        $resData['net_tcp_after_reservation'] = $terms['net_tcp_after_reservation'];
        $resData['required_dp'] = $terms['required_dp'];
        $resData['installment_months'] = $terms['installment_months'];
        $resData['monthly_payment'] = $terms['monthly_payment'];
    } else {
        $alert_msg = "Failed to update payment terms.";
        $alert_type = "error";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configure Payment Terms | JEJ Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #2e7d32;
            --primary-dark: #1b5e20;
            --primary-light: #e8f5e9;
            --gray-bg: #f4f7f6;
            --border: #e2e8f0;
            --text-main: #2d3748;
            --text-muted: #718096;
            --danger: #e11d48;
            --success: #059669;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }

        body {
            background-color: var(--gray-bg);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            margin: 0;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
        }

        .container { max-width: 1050px; width: 100%; }
        .header-actions { margin-bottom: 25px; display: flex; align-items: center; }
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: color 0.2s; }
        .btn-back:hover { color: var(--primary-dark); }

        .alert { padding: 16px 20px; border-radius: 10px; margin-bottom: 25px; font-weight: 500; font-size: 14px; display: flex; align-items: center; gap: 12px; }
        .alert.success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert.error { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }

        .dashboard-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 25px; }
        @media (max-width: 768px) { .dashboard-grid { grid-template-columns: 1fr; } }

        .card { background: #ffffff; border-radius: 16px; box-shadow: var(--shadow); border: 1px solid var(--border); overflow: hidden; }
        .card-header { padding: 20px 25px; border-bottom: 1px solid var(--border); background: #fafbfc; }
        .card-header h2 { margin: 0; font-size: 18px; font-weight: 700; color: var(--primary-dark); }
        .card-body { padding: 25px; }

        .info-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; gap: 15px; }
        .info-row span:first-child { color: var(--text-muted); font-weight: 500; }
        .info-row span:last-child { font-weight: 600; color: var(--text-main); text-align: right; }

        .price-box { background: var(--primary-dark); color: white; padding: 20px; border-radius: 12px; margin-top: 20px; text-align: center; }
        .price-box small { display: block; font-size: 13px; opacity: 0.8; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
        .price-box .amount { font-size: 32px; font-weight: 800; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #4a5568; margin-bottom: 8px; }
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            box-sizing: border-box;
            transition: all 0.2s;
        }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1); }
        select.form-control { cursor: pointer; }

        .breakdown-box {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 20px;
        }

        .break-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .break-row span:first-child { color: var(--text-muted); font-weight: 600; }
        .break-row strong { text-align: right; }
        .break-row.discount strong { color: var(--danger); }
        .break-row.resfee strong { color: var(--success); }
        .break-row.total {
            border-top: 1px solid var(--border);
            padding-top: 12px;
            margin-top: 12px;
            font-weight: 800;
            color: var(--primary-dark);
        }

        .calculator-box {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .balance-display {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-top: 15px;
            gap: 12px;
        }
        .balance-display span:first-child { font-size: 13px; font-weight: 600; color: var(--text-muted); }
        .balance-display span:last-child { font-size: 18px; font-weight: 800; color: #e11d48; text-align: right; }

        .note-box {
            padding: 13px 15px;
            border-radius: 10px;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fed7aa;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.5;
            margin-bottom: 18px;
        }

        .btn-submit {
            background: var(--primary);
            color: white;
            border: none;
            width: 100%;
            padding: 15px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 6px rgba(46, 125, 50, 0.2);
        }
        .btn-submit:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 6px 12px rgba(46, 125, 50, 0.3); }

        .readonly-input {
            background: #eef2f6;
            font-weight: 800;
            color: var(--primary-dark);
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="header-actions">
            <a href="reservation.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Return to Reservations</a>
        </div>

        <?php if($alert_msg): ?>
            <div class="alert <?= $alert_type ?>">
                <i class="fa-solid <?= $alert_type == 'success' ? 'fa-check-circle' : 'fa-circle-exclamation' ?>"></i>
                <?= htmlspecialchars($alert_msg) ?>
            </div>
        <?php endif; ?>

        <div class="dashboard-grid">

            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-file-invoice" style="margin-right: 8px;"></i> Reservation Overview</h2>
                </div>
                <div class="card-body">
                    <h3 style="font-size: 14px; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid var(--border); padding-bottom: 8px; margin: 0 0 15px;">Buyer Details</h3>
                    <div class="info-row"><span>Full Name:</span> <span><?= htmlspecialchars($resData['fullname']) ?></span></div>
                    <div class="info-row"><span>Contact:</span> <span><?= htmlspecialchars($resData['phone'] ?? $resData['contact_number'] ?? 'N/A') ?></span></div>
                    <div class="info-row"><span>Email:</span> <span><?= htmlspecialchars($resData['email'] ?? 'N/A') ?></span></div>

                    <h3 style="font-size: 14px; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid var(--border); padding-bottom: 8px; margin: 25px 0 15px;">Property Details</h3>
                    <div class="info-row"><span>Property Type:</span> <span><?= htmlspecialchars($resData['property_type']) ?></span></div>
                    <div class="info-row"><span>Location:</span> <span><?= htmlspecialchars($resData['location']) ?></span></div>
                    <div class="info-row"><span>Block & Lot:</span> <span style="color: var(--primary); font-weight: 800;">Block <?= htmlspecialchars($resData['block_no']) ?>, Lot <?= htmlspecialchars($resData['lot_no']) ?></span></div>
                    <div class="info-row"><span>Lot Area:</span> <span><?= number_format($resData['area'], 2) ?> m²</span></div>

                    <div class="price-box">
                        <small>Original Total Contract Price</small>
                        <div class="amount">₱<?= money2($total_price) ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-sliders" style="margin-right: 8px;"></i> Configure Payment Terms</h2>
                </div>
                <div class="card-body">
                    <form method="POST">

                        <div class="form-group">
                            <label>Payment Mode / Type</label>
                            <select name="payment_type" id="payment_type" class="form-control" onchange="updateTerms()" required>
                                <option value="CASH" <?= $terms['payment_type'] == 'CASH' ? 'selected' : '' ?>>Spot Cash - 5% Discount, No DP</option>
                                <option value="INSTALLMENT" <?= $terms['payment_type'] == 'INSTALLMENT' ? 'selected' : '' ?>>Installment - 3% Discount, 20% DP</option>
                            </select>
                        </div>

                        <div class="breakdown-box">
                            <div class="break-row">
                                <span>Original TCP</span>
                                <strong id="originalTcpText">₱<?= money2($total_price) ?></strong>
                            </div>
                            <div class="break-row discount">
                                <span id="discountLabel">Discount (<?= money2($terms['discount_percent']) ?>%)</span>
                                <strong id="discountAmountText">- ₱<?= money2($terms['discount_amount']) ?></strong>
                            </div>
                            <div class="break-row">
                                <span>TCP After Discount</span>
                                <strong id="tcpAfterDiscountText">₱<?= money2($terms['tcp_after_discount']) ?></strong>
                            </div>
                            <div class="break-row resfee">
                                <span>Reservation Fee Paid</span>
                                <strong id="reservationFeeText">- ₱<?= money2($terms['reservation_fee']) ?></strong>
                            </div>
                            <div class="break-row total">
                                <span>Net TCP After Reservation</span>
                                <strong id="netTcpText">₱<?= money2($terms['net_tcp_after_reservation']) ?></strong>
                            </div>
                        </div>

                        <div id="cashNote" class="note-box" style="display: <?= $terms['payment_type'] == 'CASH' ? 'block' : 'none' ?>;">
                            <i class="fa-solid fa-circle-info"></i>
                            Spot Cash: No down payment required. Buyer must pay the full net balance within 20 days, otherwise the reservation fee may be forfeited.
                        </div>

                        <div id="installment_setup" style="display: <?= $terms['payment_type'] == 'INSTALLMENT' ? 'block' : 'none' ?>;">

                            <div class="calculator-box">
                                <div class="form-group">
                                    <label>Required Down Payment 20% (₱)</label>
                                    <input type="number" step="0.01" id="dp_amount" class="form-control readonly-input" value="<?= money2($terms['required_dp']) ?>" readonly>
                                    <small style="color: var(--text-muted); font-size: 12px; margin-top: 5px; display: block;">
                                        Computed from buyer dashboard: Net TCP After Reservation × 20%.
                                    </small>
                                </div>

                                <div class="balance-display">
                                    <span>Remaining Balance to Finance</span>
                                    <span id="remaining_balance">₱<?= money2($terms['balance_to_finance']) ?></span>
                                </div>
                            </div>

                            <div class="dashboard-grid" style="gap: 15px; grid-template-columns: 1fr 1fr;">
                                <div class="form-group">
                                    <label>Term Length (Months)</label>
                                    <select name="term_months" id="term_months" class="form-control" onchange="updateTerms()">
                                        <option value="12" <?= ($terms['installment_months'] == 12) ? 'selected' : '' ?>>12 Months</option>
                                        <option value="24" <?= ($terms['installment_months'] == 24) ? 'selected' : '' ?>>24 Months</option>
                                        <option value="36" <?= ($terms['installment_months'] == 36) ? 'selected' : '' ?>>36 Months</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Monthly Amortization (₱)</label>
                                    <input type="number" step="0.01" name="monthly_payment" id="monthly_payment" class="form-control readonly-input" value="<?= money2($terms['monthly_payment']) ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <div id="cash_setup" style="display: <?= $terms['payment_type'] == 'CASH' ? 'block' : 'none' ?>;">
                            <div class="balance-display">
                                <span>Balance Due Within 20 Days</span>
                                <span id="cashBalanceDue">₱<?= money2($terms['balance_due']) ?></span>
                            </div>
                        </div>

                        <button type="submit" name="save_terms" class="btn-submit" style="margin-top:20px;">
                            <i class="fa-solid fa-floppy-disk" style="margin-right: 6px;"></i> Save & Finalize Terms
                        </button>

                    </form>
                </div>
            </div>

        </div>
    </div>

    <script>
    const TCP = <?= json_encode($total_price) ?>;
    const RES_FEE = <?= json_encode($terms['reservation_fee']) ?>;

    function peso(amount){
        return '₱' + Number(amount || 0).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function updateTerms() {
        const type = document.getElementById('payment_type').value;
        const months = parseInt(document.getElementById('term_months')?.value || 36);

        let discountPercent = type === 'CASH' ? 5 : 3;
        let discountAmount = TCP * (discountPercent / 100);
        let tcpAfterDiscount = TCP - discountAmount;
        let netTcp = tcpAfterDiscount - RES_FEE;

        if(netTcp < 0){
            netTcp = 0;
        }

        let requiredDp = 0;
        let financeBalance = 0;
        let monthlyPayment = 0;

        if(type === 'INSTALLMENT'){
            requiredDp = netTcp * 0.20;
            financeBalance = netTcp - requiredDp;
            monthlyPayment = financeBalance / months;

            document.getElementById('installment_setup').style.display = 'block';
            document.getElementById('cash_setup').style.display = 'none';
            document.getElementById('cashNote').style.display = 'none';
        } else {
            document.getElementById('installment_setup').style.display = 'none';
            document.getElementById('cash_setup').style.display = 'block';
            document.getElementById('cashNote').style.display = 'block';
        }

        document.getElementById('discountLabel').innerText = 'Discount (' + discountPercent + '%)';
        document.getElementById('discountAmountText').innerText = '- ' + peso(discountAmount);
        document.getElementById('tcpAfterDiscountText').innerText = peso(tcpAfterDiscount);
        document.getElementById('reservationFeeText').innerText = '- ' + peso(RES_FEE);
        document.getElementById('netTcpText').innerText = peso(netTcp);

        if(document.getElementById('dp_amount')){
            document.getElementById('dp_amount').value = requiredDp.toFixed(2);
        }

        if(document.getElementById('remaining_balance')){
            document.getElementById('remaining_balance').innerText = peso(financeBalance);
        }

        if(document.getElementById('monthly_payment')){
            document.getElementById('monthly_payment').value = monthlyPayment.toFixed(2);
        }

        if(document.getElementById('cashBalanceDue')){
            document.getElementById('cashBalanceDue').innerText = peso(netTcp);
        }
    }

    document.addEventListener('DOMContentLoaded', updateTerms);
    </script>
</body>
</html>