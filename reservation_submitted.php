<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    $is_https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    session_start();
}

require_once 'config.php';

checkLogin();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$current_role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
$allowed_roles = ['BUYER', 'SUPER ADMIN', 'ADMIN', 'MANAGER'];

if ($current_user_id <= 0) {
    header('Location: index.php');
    exit();
}

if (!in_array($current_role, $allowed_roles, true)) {
    header('Location: index.php');
    exit();
}

$res_id = (int)($_GET['res_id'] ?? 0);
if ($res_id <= 0) {
    header('Location: my_reservations.php');
    exit();
}

$isAdmin = in_array($current_role, ['SUPER ADMIN', 'ADMIN', 'MANAGER'], true);

$sql = "
    SELECT
        r.*,
        l.block_no,
        l.lot_no,
        l.location,
        l.area,
        l.status AS lot_status,
        u.fullname,
        u.email
    FROM reservations r
    JOIN lots l ON l.id = r.lot_id
    JOIN users u ON u.id = r.user_id
    WHERE r.id = ?
";

if (!$isAdmin) {
    $sql .= " AND r.user_id = ?";
}

$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Unable to load reservation.');
}

if ($isAdmin) {
    $stmt->bind_param('i', $res_id);
} else {
    $stmt->bind_param('ii', $res_id, $current_user_id);
}

$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
    header('Location: my_reservations.php');
    exit();
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function peso($value) {
    return '₱' . number_format((float)$value, 2);
}

$tcp = $res['tcp_after_discount'] ?? $res['discounted_total_price'] ?? 0;
$reservationFee = $res['reservation_fee'] ?? 5000;
$paymentLabel = $res['payment_option']
    ?? $res['payment_terms']
    ?? $res['payment_scheme']
    ?? $res['payment_type']
    ?? 'For review';

if (trim((string)$paymentLabel) === '') {
    $paymentLabel = 'For review';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Submitted | JEJ Top Priority Corporation</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800;900&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --yellow: #F4D03F;
            --dark: #111827;
            --muted: #64748b;
            --line: #e2e8f0;
            --green: #22c55e;
            --bg: #f8fafc;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Roboto', sans-serif;
            background: radial-gradient(circle at top left, rgba(244,208,63,.18), transparent 30%), var(--bg);
            color: #1e293b;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }
        .card {
            width: 100%;
            max-width: 920px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(15,23,42,.12);
        }
        .top {
            background: linear-gradient(135deg, #111827, #020617);
            color: #fff;
            padding: 34px;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 18px;
            align-items: center;
        }
        .icon {
            width: 70px;
            height: 70px;
            border-radius: 999px;
            background: rgba(34,197,94,.14);
            color: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            border: 1px solid rgba(34,197,94,.35);
        }
        h1 {
            margin: 0 0 6px;
            font-family: 'Montserrat', sans-serif;
            font-size: clamp(1.8rem, 4vw, 2.6rem);
            line-height: 1.05;
        }
        .top p { margin: 0; color: #cbd5e1; font-weight: 600; }
        .body { padding: 32px; }
        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: #fffbeb;
            color: #a16207;
            border: 1px solid #fde047;
            font-weight: 900;
            font-family: 'Montserrat', sans-serif;
            text-transform: uppercase;
            font-size: .78rem;
            letter-spacing: .4px;
            margin-bottom: 22px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin: 20px 0 26px;
        }
        .item {
            border: 1px solid var(--line);
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
        }
        .item small {
            display: block;
            color: var(--muted);
            text-transform: uppercase;
            font-family: 'Montserrat', sans-serif;
            font-size: .68rem;
            font-weight: 900;
            letter-spacing: .5px;
            margin-bottom: 5px;
        }
        .item strong {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.05rem;
            color: #111827;
        }
        .next {
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
            border-radius: 18px;
            padding: 18px;
            font-weight: 700;
            margin-bottom: 24px;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 14px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            text-decoration: none;
            border: 1px solid transparent;
        }
        .btn-primary { background: var(--yellow); color: #111827; }
        .btn-dark { background: #111827; color: #fff; }
        .btn-light { background: #fff; color: #111827; border-color: var(--line); }
        @media (max-width: 720px) {
            .top { grid-template-columns: 1fr; text-align: center; justify-items: center; padding: 28px 22px; }
            .body { padding: 24px 18px; }
            .grid { grid-template-columns: 1fr; }
            .actions { flex-direction: column; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <main class="card">
        <section class="top">
            <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <h1>Reservation Submitted</h1>
                <p>Your reservation request was received by JEJ Top Priority Corporation.</p>
            </div>
        </section>

        <section class="body">
            <div class="status"><i class="fa-solid fa-clock"></i> Status: Pending Verification</div>

            <div class="grid">
                <div class="item"><small>Reservation No.</small><strong>#<?= h($res['id']) ?></strong></div>
                <div class="item"><small>Date Submitted</small><strong><?= h(date('M d, Y h:i A', strtotime($res['reservation_date']))) ?></strong></div>
                <div class="item"><small>Property</small><strong>Block <?= h($res['block_no']) ?>, Lot <?= h($res['lot_no']) ?></strong></div>
                <div class="item"><small>Location</small><strong><?= h($res['location']) ?></strong></div>
                <div class="item"><small>Payment Option</small><strong><?= h($paymentLabel) ?></strong></div>
                <div class="item"><small>Reservation Fee</small><strong><?= peso($reservationFee) ?></strong></div>
                <div class="item"><small>Final Contract Price</small><strong><?= peso($tcp) ?></strong></div>
                <div class="item"><small>Lot Status</small><strong><?= h($res['lot_status']) ?></strong></div>
            </div>

            <div class="next">
                <i class="fa-solid fa-circle-info"></i>
                Next step: Please wait for admin review. Your uploaded ID, selfie verification, and payment proof will be checked before the lot is officially approved.
            </div>

            <div class="actions">
                <a class="btn btn-primary" href="my_reservations.php"><i class="fa-solid fa-file-contract"></i> View My Reservations</a>
                <a class="btn btn-dark" href="index.php#properties"><i class="fa-solid fa-map-location-dot"></i> Browse Other Lots</a>
                <a class="btn btn-light" href="index.php"><i class="fa-solid fa-house"></i> Back to Home</a>
            </div>
        </section>
    </main>
</body>
</html>
