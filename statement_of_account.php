<?php
// statement_of_account.php

require_once 'config.php';

// Role Access
requireRole([
    'SUPER ADMIN',
    'ADMIN',
    'MANAGER',
    'CASHIER',
    'BUYER'
]);

// Manager Permission Access
if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'fin_review');
}

// Validate Request
if (!isset($_GET['res_id'])) {
    die("<div style='color:red; text-align:center; padding:20px;'>Invalid Request.</div>");
}

$res_id = (int) $_GET['res_id'];

// ==========================================
// BUYER ACCESS PROTECTION
// ==========================================
// Buyer can only open his/her own Statement of Account.
// Admin / Manager / Cashier can open all SOA records.

if ($_SESSION['role'] === 'BUYER') {

    $check_stmt = $conn->prepare("
        SELECT id
        FROM reservations
        WHERE id = ?
        AND user_id = ?
        LIMIT 1
    ");

    if (!$check_stmt) {
        die("<div style='color:red; text-align:center; padding:20px;'>Access Check Error.</div>");
    }

    $check_stmt->bind_param(
        "ii",
        $res_id,
        $_SESSION['user_id']
    );

    $check_stmt->execute();

    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows === 0) {
        die("
            <div style='padding:40px;text-align:center;color:red;font-family:Arial;'>
                <h2>Access Denied</h2>
                <p>You cannot view this Statement of Account.</p>
            </div>
        ");
    }
}

// Check if transactions.payment_status column exists.
// If it exists, SOA will count VERIFIED payments only.
// If it does not exist yet, it will still load old records safely.
$has_payment_status_col = false;
$colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'payment_status'");
if ($colCheck && $colCheck->num_rows > 0) {
    $has_payment_status_col = true;
}


function peso($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Fetch reservation, buyer, and lot details.
$query = "SELECT 
            r.*, 
            u.fullname, 
            u.email, 
            u.phone,
            r.buyer_address,
            l.block_no, 
            l.lot_no, 
            l.total_price, 
            l.area,
            l.location,
            l.price_per_sqm,
            l.classification
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          JOIN lots l ON r.lot_id = l.id
          WHERE r.id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $res_id);
$stmt->execute();
$resData = $stmt->get_result()->fetch_assoc();

if (!$resData) {
    die("<div style='color:red; text-align:center; padding:20px;'>Reservation not found or query failed.</div>");
}

$reservation_fee_default = 5000.00;
$reservation_fee_amount = isset($resData['reservation_fee']) && (float)$resData['reservation_fee'] > 0
    ? (float)$resData['reservation_fee']
    : $reservation_fee_default;

$payment_option_for_calc = $resData['payment_scheme'] ?? $resData['payment_option'] ?? $resData['payment_terms'] ?? $resData['payment_type'] ?? 'SPOT_CASH';
$total_months = isset($resData['installment_months']) && (int)$resData['installment_months'] > 0
    ? (int)$resData['installment_months']
    : 36;

$priceCalc = jej_compute_payment_pricing($conn, $resData, $payment_option_for_calc, $total_months, $reservation_fee_amount);

$tcp = (float)$priceCalc['cash_tcp'];
$payment_type_raw = strtoupper(trim($resData['payment_type'] ?? $priceCalc['payment_type_db'] ?? 'INSTALLMENT'));
$is_spot_cash = ((int)$priceCalc['is_spot_cash'] === 1);
$is_installment = !$is_spot_cash;

$discount_percent = 0.00;
$discount_amount = 0.00;
$tcp_after_discount = (float)$priceCalc['final_tcp'];
$additional_amount = (float)$priceCalc['additional_amount'];
$additional_per_sqm = (float)$priceCalc['additional_per_sqm'];
$payment_option_label = $priceCalc['payment_label'];

$reservation_fee_due = $reservation_fee_amount;
$reservation_fee_paid = 0.00;

$net_tcp_after_reservation_fee = $tcp_after_discount;

$dp_rate = 0.20;
$dp_required = $is_spot_cash ? 0.00 : (float)$priceCalc['required_dp'];

$spot_cash_balance_due = $is_spot_cash ? $net_tcp_after_reservation_fee : 0.00;
$balance_to_amortize = $is_spot_cash ? 0.00 : (float)$priceCalc['balance_to_finance'];

$total_months = $is_spot_cash ? 0 : $total_months;
if ($total_months < 12 && !$is_spot_cash) $total_months = 12;
if ($total_months > 36) $total_months = 36;

$years_display = $is_spot_cash ? 0 : ($total_months / 12);

$expected_monthly_payment = $is_spot_cash ? 0.00 : ($balance_to_amortize / max($total_months, 1));
$monthly_payment = $expected_monthly_payment;

// Fetch all income transactions linked to this reservation.
$transactions = [];
$total_reservation_fee_paid = 0;
$total_dp_paid = 0;
$total_amort_paid = 0;
$total_cash_balance_paid = 0;
$desc_filter = "%Res#$res_id%";

if ($has_payment_status_col) {
    $tx_query = $conn->prepare("
        SELECT * FROM transactions
        WHERE type='INCOME'
        AND payment_status = 'VERIFIED'
        AND description LIKE ?
        ORDER BY transaction_date ASC, id ASC
    ");
} else {
    $tx_query = $conn->prepare("
        SELECT * FROM transactions
        WHERE type='INCOME'
        AND description LIKE ?
        ORDER BY transaction_date ASC, id ASC
    ");
}

if (!$tx_query) {
    die("<div style='color:red; text-align:center; padding:20px;'>SOA Query Error: " . e($conn->error) . "</div>");
}

$tx_query->bind_param("s", $desc_filter);
$tx_query->execute();
$tx_result = $tx_query->get_result();

while ($t = $tx_result->fetch_assoc()) {
    $transactions[] = $t;
    $desc = $t['description'] ?? '';
    $amount = (float)$t['amount'];

    // Classify verified payments cleanly.
    // Reservation Fee must remain separate; it should not become DP, excess DP, or partial amortization.
    if (
        stripos($desc, 'Reservation Fee') !== false ||
        stripos($desc, 'RES-FEE') !== false ||
        preg_match('/(^|[^A-Z])RF([^A-Z]|$)/i', $desc)
    ) {
        $total_reservation_fee_paid += $amount;
    } elseif (stripos($desc, 'Spot Cash Balance') !== false || stripos($desc, 'Cash Balance') !== false) {
        $total_cash_balance_paid += $amount;
    } elseif (stripos($desc, 'Down Payment') !== false || stripos($desc, 'DP') !== false) {
        $total_dp_paid += $amount;
    } elseif (stripos($desc, 'Amortization') !== false || stripos($desc, 'Monthly') !== false) {
        $total_amort_paid += $amount;
    } else {
        if ($is_spot_cash) {
            $total_cash_balance_paid += $amount;
        } elseif ($total_dp_paid >= $dp_required) {
            $total_amort_paid += $amount;
        } else {
            $total_dp_paid += $amount;
        }
    }
}

// Verified reservation fee payment for display/audit only.
// It is not used as amortization money and is not auto-generated in the ledger.
$reservation_fee_paid = (float)$total_reservation_fee_paid;
$is_reservation_fee_paid = ($reservation_fee_paid >= $reservation_fee_due && $reservation_fee_due > 0);

// Build Date Paid per amortization month.
$payment_dates_by_month = [];
$auto_month_pointer = 1;

foreach ($transactions as $tx) {
    $desc = $tx['description'] ?? '';
    if (stripos($desc, 'Amortization') === false && stripos($desc, 'Monthly') === false) {
        continue;
    }

    $paid_date_raw = (!empty($tx['date_paid']) && $tx['date_paid'] !== '0000-00-00')
        ? $tx['date_paid']
        : ($tx['transaction_date'] ?? null);

    if (empty($paid_date_raw)) {
        continue;
    }

    $paid_date_display = date('M d, Y', strtotime($paid_date_raw));
    $tx_month = isset($tx['amortization_month']) ? (int)$tx['amortization_month'] : 0;

    if ($tx_month > 0 && $tx_month <= $total_months) {
        $payment_dates_by_month[$tx_month] = $paid_date_display;
        $auto_month_pointer = max($auto_month_pointer, $tx_month + 1);
        continue;
    }

    // Fallback for older records without amortization_month.
    $months_covered = max(1, (int)floor(((float)$tx['amount'] / max($monthly_payment, 1)) + 0.0001));
    for ($m = 0; $m < $months_covered && $auto_month_pointer <= $total_months; $m++) {
        while (isset($payment_dates_by_month[$auto_month_pointer]) && $auto_month_pointer <= $total_months) {
            $auto_month_pointer++;
        }
        if ($auto_month_pointer <= $total_months) {
            $payment_dates_by_month[$auto_month_pointer] = $paid_date_display;
            $auto_month_pointer++;
        }
    }
}

// Apply payments based on selected payment type.
if ($is_spot_cash) {
    $total_dp_credited = 0.00;
    $excess_dp_to_amortization = 0.00;
    $total_amort_credited = 0.00;
    $total_cash_balance_credited = (float)$total_cash_balance_paid;

    // Reservation fee is separate. Outstanding balance is the spot cash balance basis minus verified spot cash payments.
    $overall_balance = max(0, $spot_cash_balance_due - $total_cash_balance_credited);
    $remaining_amort_balance = 0.00;
    $is_dp_fully_settled = true;
    $is_amort_fully_paid = true;
} else {
    // Apply recorded DP payments against the required DP based on the NET TCP.
    // Reservation fee is separate, so it is NOT subtracted from required DP and NOT credited to amortization.
    // Any DP payment in excess of the required DP is automatically credited to amortization.
    $total_dp_credited = min($total_dp_paid, $dp_required);
    $excess_dp_to_amortization = max(0, $total_dp_paid - $total_dp_credited);

    $total_amort_credited = $total_amort_paid + $excess_dp_to_amortization;
    $total_cash_balance_credited = 0.00;

    // Outstanding balance is based on Net Final Contract Price minus DP/amortization payments.
    $overall_balance = max(0, $net_tcp_after_reservation_fee - ($total_dp_credited + $total_amort_credited));
    $remaining_amort_balance = max(0, $balance_to_amortize - $total_amort_credited);
    $is_dp_fully_settled = ($total_dp_credited >= $dp_required);
    $is_amort_fully_paid = ($total_amort_credited >= $balance_to_amortize && $balance_to_amortize > 0);
}

// Total paid including reservation fee, for display/audit only.
$total_paid_including_reservation_fee = $reservation_fee_paid + $total_dp_credited + $total_amort_credited + $total_cash_balance_credited;

$total_due_for_stub = $overall_balance;
$is_account_fully_settled = ($overall_balance <= 0);
$buyer_address = !empty($resData['buyer_address']) ? $resData['buyer_address'] : 'N/A';
?>

<style>
.print-only { display: none; }
@media print {
    .no-print-ledger,
    .no-print-button {
        display: none !important;
    }
    .print-only { display: block !important; }

    #soaStatement {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 auto !important;
    }
}
</style>

<div id="soaStatement"
     data-buyer-name="<?= e($resData['fullname'] ?? 'N/A') ?>"
     data-buyer-address="<?= e($buyer_address) ?>"
     data-account-no="<?= e($resData['id'] ?? '') ?>"
     data-total-due="<?= number_format($total_due_for_stub, 2) ?>"
     style="font-family: 'Inter', sans-serif; color: #334155;">

    <!-- PRINT ONLY: official SOA header -->
    <div class="print-only" style="margin-bottom:18px;">
        <div style="display:flex; align-items:center; gap:24px;">
            <div style="width:160px; text-align:center;">
                <img src="assets/logo1.png" style="width:145px; height:auto;">
            </div>
            <div style="flex:1; background:#062b4a; color:#fff; padding:22px 30px; font-size:22px; font-weight:800; text-transform:uppercase; letter-spacing:.5px; clip-path:polygon(5% 0,100% 0,96% 100%,0% 100%);">
                STATEMENT OF ACCOUNT &amp; AMORTIZATION
            </div>
        </div>
    </div>

    <!-- Buyer and Property Overview -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:30px;">
        <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:20px; border-radius:12px;">
            <h4 style="margin:0 0 15px; color:#0f172a; font-size:15px; border-bottom:1px solid #cbd5e1; padding-bottom:8px;">Buyer Information</h4>
            <div style="font-size:13px; margin-bottom:6px;"><strong>Name:</strong> <?= e($resData['fullname'] ?? 'N/A') ?></div>
            <div style="font-size:13px; margin-bottom:6px;"><strong>Email:</strong> <?= e($resData['email'] ?? 'N/A') ?></div>
            <div style="font-size:13px; margin-bottom:6px;"><strong>Contact:</strong> <?= e($resData['phone'] ?? $resData['contact_number'] ?? 'N/A') ?></div>
            <div style="font-size:13px;"><strong>Address:</strong> <?= e($buyer_address) ?></div>
        </div>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:20px; border-radius:12px;">
            <h4 style="margin:0 0 15px; color:#0f172a; font-size:15px; border-bottom:1px solid #cbd5e1; padding-bottom:8px;">Property Overview</h4>
            <div style="font-size:13px; margin-bottom:6px;"><strong>Property:</strong> Block <?= e($resData['block_no']) ?> Lot <?= e($resData['lot_no']) ?></div>
            <div style="font-size:13px; margin-bottom:6px;"><strong>Lot Area:</strong> <?= e($resData['area']) ?> sqm</div>
            <div style="font-size:13px; margin-bottom:6px;"><strong>Original Total Contract Price (TCP):</strong> <?= peso($tcp) ?></div>

            <div style="font-size:13px; margin-bottom:6px; color:#0f766e; font-weight:800;"><strong>Payment Option:</strong> <?= e($payment_option_label) ?></div>
            <div style="font-size:13px; margin-bottom:6px; color:#059669; font-weight:800;"><strong>Price Difference Amount:</strong> + <?= peso($additional_amount) ?></div>
            <div style="font-size:13px; margin-bottom:6px;"><strong>Final Contract Price:</strong> <?= peso($tcp_after_discount) ?></div>
            <div style="font-size:13px; margin-bottom:6px; color:#059669; font-weight:700;"><strong>Reservation Fee Paid:</strong> <?= peso($reservation_fee_paid) ?></div>
            <div style="font-size:13px; margin-bottom:6px; color:#0f172a; font-weight:800;"><strong>Balance to Finance:</strong> <?= peso($balance_to_amortize) ?></div>

            <?php if ($is_spot_cash): ?>
                <div style="font-size:13px; color:#0f766e; font-weight:bold;"><strong>Spot Cash Balance Due:</strong> <?= peso($spot_cash_balance_due) ?></div>
            <?php else: ?>
                <div style="font-size:13px; color:#2e7d32; font-weight:bold;"><strong>Required 20% DP:</strong> <?= peso($dp_required) ?></div>
                <div style="font-size:12px; color:#64748b; font-weight:600; margin-top:4px;">Balance to Amortize After DP: <?= peso($balance_to_amortize) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary Cards -->
    <?php if ($is_spot_cash): ?>
        <div style="display:flex; gap:15px; margin-bottom:30px;">
            <div style="flex:1; background:white; border:1px solid #c8e6c9; border-left:4px solid #10b981; padding:15px; border-radius:8px;">
                <span style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Spot Cash Paid</span>
                <div style="font-size:18px; font-weight:800; color:#10b981; margin-top:5px;"><?= peso($total_cash_balance_credited) ?></div>
                <div style="font-size:10px; color:#64748b; margin-top:4px; line-height:1.35;">
                    Reservation Fee paid separately: <?= peso($reservation_fee_paid) ?><br>
                    Spot Cash Balance Due: <?= peso($spot_cash_balance_due) ?>
                </div>
                <div style="font-size:11px; color:<?= $is_account_fully_settled ? '#10b981' : '#ef4444' ?>; margin-top:5px; font-weight:600;">
                    <?php if ($is_account_fully_settled): ?>
                        <i class="fa-solid fa-circle-check"></i> Total Contract Price Fully Paid
                    <?php else: ?>
                        <i class="fa-solid fa-circle-exclamation"></i> Remaining Spot Cash Balance: <?= peso($overall_balance) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div style="flex:1; background:white; border:1px solid <?= $is_account_fully_settled ? '#c8e6c9' : '#fecaca' ?>; border-left:4px solid <?= $is_account_fully_settled ? '#10b981' : '#ef4444' ?>; padding:15px; border-radius:8px;">
                <span style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Spot Cash Outstanding Balance</span>
                <div style="font-size:18px; font-weight:800; color:<?= $is_account_fully_settled ? '#10b981' : '#ef4444' ?>; margin-top:5px;"><?= peso($overall_balance) ?></div>
                <div style="font-size:11px; color:<?= $is_account_fully_settled ? '#10b981' : '#ef4444' ?>; margin-top:5px; font-weight:600;">
                    <?php if ($is_account_fully_settled): ?>
                        <i class="fa-solid fa-circle-check"></i> Account Fully Settled
                    <?php else: ?>
                        <i class="fa-solid fa-clock"></i> Awaiting Spot Cash Balance Payment
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div style="display:flex; gap:15px; margin-bottom:30px;">
            <div style="flex:1; background:white; border:1px solid #c8e6c9; border-left:4px solid #10b981; padding:15px; border-radius:8px;">
                <span style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Total DP Paid</span>
                <div style="font-size:18px; font-weight:800; color:#10b981; margin-top:5px;"><?= peso($total_dp_credited) ?></div>
                <div style="font-size:10px; color:#64748b; margin-top:4px; line-height:1.35;">
                    Reservation Fee paid separately: <?= peso($reservation_fee_paid) ?><br>
                    Required DP on Net TCP: <?= peso($dp_required) ?>
                </div>
                <div style="font-size:11px; color:<?= $is_dp_fully_settled ? '#10b981' : '#ef4444' ?>; margin-top:5px; font-weight:600;">
                    <?php if ($is_dp_fully_settled): ?>
                        <i class="fa-solid fa-check-circle"></i> DP Fully Settled
                    <?php else: ?>
                        <i class="fa-solid fa-circle-exclamation"></i> DP Balance: <?= peso(max(0, $dp_required - $total_dp_credited)) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div style="flex:1; background:white; border:1px solid #bfdbfe; border-left:4px solid #3b82f6; padding:15px; border-radius:8px;">
                <span style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Total Amortization Paid</span>
                <div style="font-size:18px; font-weight:800; color:#3b82f6; margin-top:5px;"><?= peso($total_amort_credited) ?></div>
                <?php if ($excess_dp_to_amortization > 0): ?>
                    <div style="font-size:10px; color:#64748b; margin-top:4px; line-height:1.35;">
                        Includes excess DP credit: <?= peso($excess_dp_to_amortization) ?>
                    </div>
                <?php endif; ?>
                <div style="font-size:11px; color:<?= $is_amort_fully_paid ? '#10b981' : '#3b82f6' ?>; margin-top:5px; font-weight:600;">
                    <?php if ($balance_to_amortize <= 0): ?>
                        <i class="fa-solid fa-circle-info"></i> No Amortization Required
                    <?php elseif ($is_amort_fully_paid): ?>
                        <i class="fa-solid fa-check-circle"></i> Amortization Fully Paid
                    <?php elseif ($total_amort_paid > 0): ?>
                        <i class="fa-solid fa-clock"></i> Ongoing Installment Payments
                    <?php else: ?>
                        <i class="fa-solid fa-hourglass-start"></i> No Amortization Payment Yet
                    <?php endif; ?>
                </div>
            </div>

            <div style="flex:1; background:white; border:1px solid <?= $is_account_fully_settled ? '#c8e6c9' : '#fecaca' ?>; border-left:4px solid <?= $is_account_fully_settled ? '#10b981' : '#ef4444' ?>; padding:15px; border-radius:8px;">
                <span style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Overall Outstanding Balance</span>
                <div style="font-size:18px; font-weight:800; color:<?= $is_account_fully_settled ? '#10b981' : '#ef4444' ?>; margin-top:5px;"><?= peso($overall_balance) ?></div>
                <div style="font-size:11px; color:<?= $is_account_fully_settled ? '#10b981' : '#ef4444' ?>; margin-top:5px; font-weight:600;">
                    <?php if ($is_account_fully_settled): ?>
                        <i class="fa-solid fa-circle-check"></i> Account Fully Settled
                    <?php else: ?>
                        <i class="fa-solid fa-circle-exclamation"></i> Outstanding Contract Balance
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Official Payment Ledger: visible on screen, hidden in print -->
    <div class="no-print-ledger">
        <h3 style="font-size:16px; color:#0f172a; margin-bottom:10px; border-bottom:2px solid #e2e8f0; padding-bottom:5px;">
            <i class="fa-solid fa-list-check" style="color:var(--primary); margin-right:5px;"></i> Official Payment Ledger
        </h3>

        <table style="width:100%; border-collapse:collapse; margin-bottom:30px; font-size:13px;">
            <thead>
                <tr style="background:#f1f5f9;">
                    <th style="padding:10px; text-align:left; border:1px solid #e2e8f0; color:#475569;">Date</th>
                    <th style="padding:10px; text-align:left; border:1px solid #e2e8f0; color:#475569;">OR / Ref Number</th>
                    <th style="padding:10px; text-align:left; border:1px solid #e2e8f0; color:#475569;">Description / Category</th>
                    <th style="padding:10px; text-align:right; border:1px solid #e2e8f0; color:#475569;">Amount Paid</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="4" style="padding:20px; text-align:center; border:1px solid #e2e8f0; color:#94a3b8;">
                            No payments recorded yet. Record a payment to see it here.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td style="padding:10px; border:1px solid #e2e8f0;"><?= date('M d, Y', strtotime($t['transaction_date'])) ?></td>
                            <td style="padding:10px; border:1px solid #e2e8f0; font-weight:bold; color:#0369a1;"><?= e($t['or_number']) ?></td>
                            <td style="padding:10px; border:1px solid #e2e8f0;"><?= e($t['description']) ?></td>
                            <td style="padding:10px; border:1px solid #e2e8f0; text-align:right; font-weight:bold; color:#10b981;"><?= peso($t['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Monthly Amortization Schedule -->
    <?php if ($is_spot_cash): ?>
        <div style="background:#ecfdf5; border:1px solid #a7f3d0; padding:15px; border-radius:8px; color:#047857; font-size:13px; font-weight:700; margin-bottom:20px;">
            <i class="fa-solid fa-circle-info"></i>
            Spot Cash account: no down payment and no monthly amortization schedule. The buyer must settle the remaining spot cash balance within 20 days.
        </div>
    <?php else: ?>
        <h3 style="font-size:16px; color:#0f172a; margin-bottom:10px; border-bottom:2px solid #e2e8f0; padding-bottom:5px;">
            <i class="fa-solid fa-calendar-days" style="color:var(--primary); margin-right:5px;"></i>
            Monthly Amortization Schedule (<?= $total_months ?> Months / <?= number_format($years_display, 1) ?> Years)
        </h3>

        <?php if ($total_dp_credited < $dp_required): ?>
            <div style="background:#fffbeb; border:1px solid #fef08a; padding:15px; border-radius:8px; color:#b45309; font-size:13px; font-weight:600; margin-bottom:20px;">
                <i class="fa-solid fa-triangle-exclamation"></i> Note: The amortization schedule officially begins only after the 20% Down Payment is fully settled.
            </div>
        <?php endif; ?>

        <table class="amortization-two-column" style="width:100%; border-collapse:collapse; font-size:11px;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="padding:6px; text-align:center; border:1px solid #cbd5e1;">Month</th>
                    <th style="padding:6px; text-align:center; border:1px solid #cbd5e1;">Monthly Due</th>
                    <th style="padding:6px; text-align:center; border:1px solid #cbd5e1;">Remaining Balance</th>
                    <th style="padding:6px; text-align:center; border:1px solid #cbd5e1;">Status</th>
                    <th style="padding:6px 2px; text-align:center; border:1px solid #cbd5e1; width:72px;">Date Paid</th>
                    <th style="padding:6px; text-align:center; border:1px solid #cbd5e1;">Month</th>
                    <th style="padding:6px; text-align:center; border:1px solid #cbd5e1;">Monthly Due</th>
                    <th style="padding:6px; text-align:center; border:1px solid #cbd5e1;">Remaining Balance</th>
                    <th style="padding:6px; text-align:center; border:1px solid #cbd5e1;">Status</th>
                    <th style="padding:6px 2px; text-align:center; border:1px solid #cbd5e1; width:72px;">Date Paid</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $amort_rows = [];
                $remaining_amort_funds = $total_amort_credited;
                $running_balance = $balance_to_amortize;

                for ($i = 1; $i <= $total_months; $i++) {
                    $running_balance = max(0, $running_balance - $monthly_payment);
                    $paid_date_display = $payment_dates_by_month[$i] ?? '—';

                    $remaining_amort_funds = round($remaining_amort_funds, 2);
                    $monthly_due_check = round($monthly_payment, 2);

                    if ($remaining_amort_funds >= $monthly_due_check || abs($remaining_amort_funds - $monthly_due_check) <= 1.00) {
                        $status_html = '<span style="background:#dcfce7; color:#16a34a; padding:3px 6px; border-radius:4px; font-size:9px; font-weight:bold; text-transform:uppercase;">PAID</span>';
                        $remaining_amort_funds = max(0, $remaining_amort_funds - $monthly_due_check);
                    } elseif ($remaining_amort_funds > 0) {
                        $status_html = '<span style="background:#fef3c7; color:#b45309; padding:3px 6px; border-radius:4px; font-size:9px; font-weight:bold; text-transform:uppercase;">PARTIAL</span>';
                        $remaining_amort_funds = 0;
                    } else {
                        $status_html = '<span style="background:#f1f5f9; color:#94a3b8; padding:3px 6px; border-radius:4px; font-size:9px; font-weight:bold; text-transform:uppercase;">UNPAID</span>';
                    }

                    $amort_rows[$i] = [
                        'month' => $i,
                        'monthly_due' => peso($monthly_payment),
                        'remaining_balance' => peso($running_balance),
                        'status' => $status_html,
                        'date_paid' => $paid_date_display
                    ];
                }

                $half = (int)ceil($total_months / 2);
                for ($left = 1; $left <= $half; $left++):
                    $right = $left + $half;
                    $leftRow = $amort_rows[$left] ?? null;
                    $rightRow = $amort_rows[$right] ?? null;
                ?>
                    <tr>
                        <td style="padding:5px; text-align:center; border:1px solid #e2e8f0; font-weight:bold;"><?= e($leftRow['month'] ?? '') ?></td>
                        <td style="padding:5px; text-align:center; border:1px solid #e2e8f0;"><?= e($leftRow['monthly_due'] ?? '') ?></td>
                        <td style="padding:5px; text-align:center; border:1px solid #e2e8f0;"><?= e($leftRow['remaining_balance'] ?? '') ?></td>
                        <td style="padding:5px; text-align:center; border:1px solid #e2e8f0;"><?= $leftRow['status'] ?? '' ?></td>
                        <td style="padding:4px 2px; text-align:center; border:1px solid #e2e8f0; font-size:8px; white-space:nowrap; line-height:1; width:72px;"><?= e($leftRow['date_paid'] ?? '') ?></td>

                        <td style="padding:5px; text-align:center; border:1px solid #e2e8f0; font-weight:bold;"><?= e($rightRow['month'] ?? '') ?></td>
                        <td style="padding:5px; text-align:center; border:1px solid #e2e8f0;"><?= e($rightRow['monthly_due'] ?? '') ?></td>
                        <td style="padding:5px; text-align:center; border:1px solid #e2e8f0;"><?= e($rightRow['remaining_balance'] ?? '') ?></td>
                        <td style="padding:5px; text-align:center; border:1px solid #e2e8f0;"><?= $rightRow['status'] ?? '' ?></td>
                        <td style="padding:4px 2px; text-align:center; border:1px solid #e2e8f0; font-size:8px; white-space:nowrap; line-height:1; width:72px;"><?= e($rightRow['date_paid'] ?? '') ?></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- PRINT ONLY: reminders, contact, and payment stub -->
    <div class="print-only" style="margin-top:16px;">
        <div style="display:grid; grid-template-columns:1.2fr .8fr; gap:16px; margin-bottom:12px;">
            <div>
                <div style="background:#05621e; color:white; padding:7px 10px; border-radius:5px 5px 0 0; font-size:11px; font-weight:800;">🔔 PAYMENT REMINDERS</div>
                <div style="border:1px solid #b7c9de; padding:10px; font-size:9px; line-height:1.35;">
                    1. Please present this Statement of Account when paying.<br>
                    2. Payments made after due date may not be reflected immediately.<br>
                    3. Please make all checks payable to <strong>JEJ SURVEYING SERVICES</strong>.<br>
                    4. Keep your official receipt for future reference.<br>
                    5. Any unpaid balance shall remain due until fully settled.
                </div>
            </div>
            <div>
                <div style="background:#062b4a; color:white; padding:7px 10px; border-radius:5px 5px 0 0; font-size:11px; font-weight:800;">☎ CONTACT US</div>
                <div style="border:1px solid #b7c9de; padding:10px; font-size:9px; line-height:1.35;">
                    (02) 8724-4244<br>
                    customerservice@jejsurveying.com<br>
                    www.jejsurveying.com<br>
                    facebook.com/jejsurveyingservices
                </div>
            </div>
        </div>

        <div style="font-size:8px;">
            <div style="display:grid; grid-template-columns:1fr 1fr 1.2fr; gap:0; border-top:1px dashed #062b4a; padding-top:8px;">
                <div style="border-right:1px solid #b7c9de; padding:7px;">
                    <strong><?= e($resData['fullname']) ?></strong><br>
                    <?= e($buyer_address) ?>
                </div>
                <div style="border-right:1px solid #b7c9de; padding:7px;">
                    <strong>HOUSING ACCOUNT NO.</strong><br>
                    <?= e($resData['account_number'] ?? ('JEJ-' . date('Y') . '-' . str_pad((string)$res_id, 4, '0', STR_PAD_LEFT))) ?><br><br>
                    <strong>TOTAL AMOUNT DUE</strong><br>
                    <span style="color:#10b981; font-weight:800;"><?= peso($total_due_for_stub) ?></span>
                </div>
                <div style="padding:7px;">
                    <strong>TO BE FILLED UP BY THE BORROWER</strong><br><br>
                    ☐ Cash Payment &nbsp;&nbsp;&nbsp;&nbsp; ☐ Check Payment<br><br>
                    Check No. __________________ &nbsp;&nbsp; Bank / Branch __________________
                </div>
            </div>
        </div>
    </div>

    <div class="no-print-button" style="margin-top:20px; text-align:right;">
        <button type="button" onclick="if(window.parent && window.parent.printStatement){ window.parent.printStatement(); } else { window.print(); }" style="background:#f1f5f9; border:1px solid #cbd5e1; padding:10px 20px; border-radius:8px; font-weight:600; cursor:pointer; transition:0.2s;">
            <i class="fa-solid fa-print"></i> Print Statement
        </button>
    </div>
</div>
                