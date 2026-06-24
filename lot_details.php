<?php
// lot_details.php

ob_start();

require_once 'config.php';

// Require Login
checkLogin();

// Role Access
requireRole([
    'SUPER ADMIN',
    'ADMIN',
    'MANAGER',
    'AGENT',
    'BUYER'
]);

// Manager Permission Access
if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'inv_property');
}

// CSRF token for reservation form.
// NOTE: actions.php must verify this token before processing reservations.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validate Lot ID
if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$id = (int)$_GET['id'];


// Fetch Lot Details
$stmt = $conn->prepare("SELECT * FROM lots WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$lot = $stmt->get_result()->fetch_assoc();

if(!$lot) die("Property not found.");

// Fetch Gallery Images
$gallery_stmt = $conn->prepare("SELECT * FROM lot_gallery WHERE lot_id = ?");
$gallery_stmt->bind_param("i", $id);
$gallery_stmt->execute();
$gallery_res = $gallery_stmt->get_result();

// Build array of all images for the JS Gallery
$js_images = [];
// Add Main Image first
$main_img = !empty($lot['lot_image']) ? jej_lot_image_url($lot['lot_image']) : jej_lot_image_url('default_lot.jpg');
$js_images[] = $main_img;

// Add Gallery Images
$gallery_html = ""; 
while($img = $gallery_res->fetch_assoc()){
    $path = jej_lot_image_url($img['image_path']);
    $js_images[] = $path;
    $gallery_html .= '<div class="thumb-box" onclick="openLightbox(\''.$path.'\')"><img src="'.$path.'" class="thumb-img"></div>';
}

// --- FETCH SUBDIVISION PLAN MAP IMAGE ---
// Supports old public uploads and new storage/uploads paths.
// Priority: lots.subdivision_map -> map filename/location helper -> default asset.
$current_map = jej_map_image_url($lot['subdivision_map'] ?? ($lot['location'] ?? ''));

if (empty($current_map)) {
    $current_map = jej_public_base_url() . '/assets/map.png';
}

// Fetch all lots to render the subdivision context
$all_lots = [];

$res_lots_stmt = $conn->prepare("
    SELECT id, block_no, lot_no, status, coordinates, location
    FROM lots
    WHERE location = ?
");

$res_lots_stmt->bind_param("s", $lot['location']);
$res_lots_stmt->execute();
$res_lots = $res_lots_stmt->get_result();

if($res_lots && $res_lots->num_rows > 0){
    while($r = $res_lots->fetch_assoc()){
        $all_lots[] = $r;
    }
}

// Fetch unread notifications for the navbar
$unread_count = 0;
$notif_stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $_SESSION['user_id']);
    $notif_stmt->execute();
    $notif_stmt->bind_result($unread_count);
    $notif_stmt->fetch();
    $notif_stmt->close();
}

// Fetch logged-in buyer information for auto-fill reservation form
$buyer = [];
$buyer_stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
if ($buyer_stmt) {
    $buyer_stmt->bind_param("i", $_SESSION['user_id']);
    $buyer_stmt->execute();
    $buyer_res = $buyer_stmt->get_result();
    if ($buyer_res && $buyer_res->num_rows > 0) {
        $buyer = $buyer_res->fetch_assoc();
    }
    $buyer_stmt->close();
}

function pickValue($source, $keys, $fallback = '') {
    foreach ($keys as $key) {
        if (isset($source[$key]) && trim((string)$source[$key]) !== '') {
            return trim((string)$source[$key]);
        }
    }
    return $fallback;
}

$buyer_fullname = pickValue($buyer, ['fullname', 'full_name', 'name'], $_SESSION['fullname'] ?? '');
$buyer_email    = pickValue($buyer, ['email', 'email_address'], $_SESSION['email'] ?? '');
$buyer_contact  = pickValue($buyer, ['contact_number', 'contact', 'phone', 'mobile', 'mobile_no'], '');
$buyer_address  = pickValue($buyer, ['address', 'buyer_address', 'home_address'], '');
$buyer_agent    = pickValue($buyer, ['agent_name', 'agent'], 'Direct Buyer');

$reservation_fee = 5000;
$cash_calc = jej_compute_payment_pricing($conn, $lot, 'SPOT_CASH', 36, $reservation_fee);
$installment_calc = jej_compute_payment_pricing($conn, $lot, 'INSTALLMENT', 36, $reservation_fee);
$straight_calc = jej_compute_payment_pricing($conn, $lot, 'STRAIGHT_PAYMENT', 36, $reservation_fee);
$default_calc = $cash_calc;

$tcp = (float)$default_calc['final_tcp'];
$display_price_per_sqm = (float)$default_calc['cash_price_per_sqm'];
$pricing_options = [
    'SPOT_CASH' => $cash_calc,
    'INSTALLMENT' => $installment_calc,
    'STRAIGHT_PAYMENT' => $straight_calc,
];

// Validate true GPS coordinates. Pixel coordinates such as "503.6, 191.6" must not be shown in Leaflet.
$lot_lat = isset($lot['latitude']) ? (float)$lot['latitude'] : 0;
$lot_lng = isset($lot['longitude']) ? (float)$lot['longitude'] : 0;
$has_geo_coordinates = (
    is_numeric($lot['latitude'] ?? null) &&
    is_numeric($lot['longitude'] ?? null) &&
    $lot_lat >= -90 && $lot_lat <= 90 &&
    $lot_lng >= -180 && $lot_lng <= 180 &&
    !($lot_lat == 0.0 && $lot_lng == 0.0)
);

$lot_classification = pickValue($lot, ['classification', 'lot_classification', 'property_classification'], 'Inner');
$lot_status = strtoupper(trim((string)($lot['status'] ?? '')));
$is_lot_available = ($lot_status === 'AVAILABLE');

$review_lot_label = 'Block ' . ($lot['block_no'] ?? '') . ', Lot ' . ($lot['lot_no'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Details | JEJ Top Priority Corporation</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">

    <!-- Unified Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,600;0,700;0,800&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
        :root {
            --primary-yellow: #F4D03F;
            --dark-bg: #111827; 
            --light-bg: #F8FAFC;
            --text-dark: #1e293b;
            --text-light: #ffffff;
            --success-green: #22c55e;
            --danger-red: #ef4444;
            --gray-border: #e2e8f0;
            
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 20px 40px -10px rgba(0,0,0,0.12);
        }

        body { 
            font-family: 'Roboto', sans-serif; 
            background-color: var(--light-bg); 
            color: var(--text-dark); 
            line-height: 1.6;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        h1, h2, h3, h4, .nav-logo-text h2 { font-family: 'Montserrat', sans-serif; }
        a { text-decoration: none; color: inherit; }
        ul { list-style: none; }

        /* --- ANIMATIONS --- */
        @keyframes fadeInUp { 0% { opacity: 0; transform: translateY(40px); } 100% { opacity: 1; transform: translateY(0); } }
        @keyframes fadeIn { 0% { opacity: 0; } 100% { opacity: 1; } }
        @keyframes dropIn { from { opacity: 0; transform: scale(0.9) translateY(-10px); } to { opacity: 1; transform: scale(1) translateY(0); } }

        .animate-on-scroll { opacity: 0; transform: translateY(30px); transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1); }
        .animate-on-scroll.visible { opacity: 1; transform: translateY(0); }

        /* === NAVBAR (BUG FIXES APPLIED) === */
        .navbar {
            position: fixed; top: 0; left: 0; width: 100%; padding: 15px 5%;
            display: flex; justify-content: space-between; align-items: center;
            z-index: 1000; color: white;
            background: rgba(15, 23, 42, 0.98); backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.05);
            box-sizing: border-box;
        }
        
        .nav-logo { display: flex; align-items: center; }
        .nav-logo-text { margin-left: 15px; }
        .nav-logo-text h2 { font-weight: 800; letter-spacing: 0.5px; font-size: 1.4rem; line-height: 1.1; text-shadow: 0 2px 4px rgba(0,0,0,0.5); color: white; margin: 0;}
        .nav-logo-text span { font-family: 'Roboto', sans-serif; font-size: 10px; font-weight: 500; letter-spacing: 1.5px; color: #cbd5e1; display: block; margin-top: 2px; }
        
        .nav-links { display: flex; gap: 30px; } /* Reduced gap to prevent squeezing */
        .nav-links a { font-size: 0.95rem; font-weight: 500; transition: color 0.3s; color: white;}
        .nav-links a:hover { color: var(--primary-yellow); }
        
        /* User Profile Logic in Nav */
        .nav-actions { display: flex; align-items: center; gap: 15px; }
        .notification-bell { position: relative; color: white; font-size: 22px; transition: color 0.3s; }
        .notification-bell:hover { color: var(--primary-yellow); transform: scale(1.1); }
        .notification-dot { position: absolute; top: 0; right: -2px; width: 10px; height: 10px; background-color: var(--danger-red); border-radius: 50%; border: 2px solid var(--dark-bg); }
        
        .profile-dropdown-container { position: relative; }
        .profile-trigger {
            display: flex; align-items: center; gap: 10px; background: transparent;
            border: 1px solid rgba(255,255,255,0.2); cursor: pointer; padding: 5px 12px;
            border-radius: 40px; transition: all 0.2s ease; color: white;
        }
        .profile-trigger:hover { background: rgba(255,255,255,0.1); border-color: white; }
        
        /* BUG FIX: Truncate long names */
        .profile-info { text-align: right; max-width: 120px; } 
        .profile-name { 
            display: block; font-weight: 600; font-size: 0.85rem; font-family: 'Roboto', sans-serif;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; 
        }
        .profile-role { 
            display: block; font-size: 0.65rem; color: var(--primary-yellow); font-weight: 700; 
            letter-spacing: 0.5px; text-transform: uppercase;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        
        .avatar-circle { background: var(--primary-yellow); color: var(--dark-bg); border-radius: 50%; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1rem; flex-shrink: 0;}
        
        .profile-dropdown-menu {
            display: none; position: absolute; top: 120%; right: 0; background: white;
            min-width: 220px; border-radius: 12px; box-shadow: var(--shadow-lg);
            border: 1px solid #f1f5f9; overflow: hidden; z-index: 100;
        }
        .profile-dropdown-menu.active { display: block; animation: fadeIn 0.2s ease forwards; }
        .profile-dropdown-item { display: flex; align-items: center; gap: 12px; padding: 12px 20px; text-decoration: none; color: var(--text-dark); font-size: 0.95rem; font-weight: 500; transition: all 0.2s; }
        .profile-dropdown-item i { width: 20px; text-align: center; color: #94a3b8; transition: 0.2s; }
        .profile-dropdown-item:hover { background: #f8fafc; color: var(--dark-bg); padding-left: 25px; }
        .profile-dropdown-item:hover i { color: var(--primary-yellow); }
        .profile-dropdown-item.logout-btn { color: var(--danger-red); border-top: 1px solid #f1f5f9; }
        .profile-dropdown-item.logout-btn i { color: var(--danger-red); }
        .profile-dropdown-item.logout-btn:hover { background: #fef2f2; color: #dc2626; }

        /* General Page Layout */
        .main-content { flex-grow: 1; padding: 110px 5% 50px 5%; width: 100%; max-width: 1400px; margin: 0 auto; }
        .breadcrumb { margin: 0 0 25px; font-size: 0.9rem; color: #64748b; display: flex; align-items: center; gap: 10px; font-weight: 500;}
        .breadcrumb a { color: var(--text-dark); text-decoration: none; transition: 0.2s; font-weight: 600;}
        .breadcrumb a:hover { color: var(--primary-yellow); }

        /* --- MEDIA & ACTION GRID (Top Section) --- */
        .media-action-grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 40px; align-items: start; margin-bottom: 40px;}

        /* Image Gallery */
        .main-img-box { position: relative; border-radius: 20px; overflow: hidden; height: 450px; box-shadow: var(--shadow-md); background: #f8fafc; cursor: pointer; border: 1px solid var(--gray-border);}
        .main-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1); }
        .main-img-box:hover .main-img { transform: scale(1.05); }
        
        .badge { position: absolute; padding: 6px 14px; border-radius: 8px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; box-shadow: 0 4px 15px rgba(0,0,0,0.2); letter-spacing: 0.5px;}
        .badge.AVAILABLE { background: var(--success-green); color: var(--dark-bg); }
        .badge.RESERVED { background: #f59e0b; color: white; }
        .badge.SOLD { background: var(--danger-red); color: white; }

        .gallery-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-top: 15px; margin-bottom: 25px; }
        .thumb-box { height: 80px; border-radius: 12px; overflow: hidden; cursor: pointer; border: 2px solid transparent; opacity: 0.7; transition: 0.3s; box-shadow: var(--shadow-sm);}
        .thumb-box:hover { border-color: var(--primary-yellow); opacity: 1; transform: translateY(-4px); box-shadow: var(--shadow-md);}
        .thumb-img { width: 100%; height: 100%; object-fit: cover; }

        /* --- PROPERTY DETAILS CARD --- */
        .specs-card { background: white; border-radius: 20px; padding: 35px; box-shadow: var(--shadow-md); border: 1px solid var(--gray-border); }
        .specs-card .prop-type { font-size: 0.75rem; font-weight: 800; color: var(--dark-bg); text-transform: uppercase; letter-spacing: 1px; background: rgba(244, 208, 63, 0.2); padding: 6px 12px; border-radius: 8px; display: inline-block; margin-bottom: 15px; border: 1px solid var(--primary-yellow);}
        .specs-card h2 { font-size: 2.2rem; font-weight: 800; color: var(--text-dark); margin: 0 0 10px; letter-spacing: -0.5px; line-height: 1.2;}
        .specs-card .location { color: #64748b; font-size: 1rem; font-weight: 600; margin-bottom: 25px; display: flex; align-items: center; gap: 8px; }
        
        .price-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: #f8fafc; padding: 25px; border-radius: 16px; border: 1px solid #e2e8f0; margin-bottom: 30px; }
        .price-grid div small { display: block; font-size: 0.8rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; font-family: 'Montserrat', sans-serif;}
        .price-grid div strong { font-size: 1.2rem; color: var(--text-dark); font-weight: 800; font-family: 'Montserrat', sans-serif;}
        .price-grid .total-row { grid-column: 1 / -1; border-top: 1px dashed #cbd5e1; padding-top: 15px; margin-top: 5px; }
        .price-grid .total-row strong { font-size: 1.8rem; color: var(--primary-yellow); font-weight: 900; letter-spacing: -0.5px; text-shadow: 0px 1px 2px rgba(0,0,0,0.1);}

        .specs-card h4 { font-size: 1.2rem; font-weight: 800; color: var(--text-dark); margin: 0 0 10px; }
        .specs-card p { font-size: 0.95rem; color: #475569; line-height: 1.7; margin: 0; font-weight: 500;}

        /* Reservation Form */
        .form-section { position: relative; }
        .form-card {
            background: white;
            border-radius: 20px;
            padding: 0;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-border);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            height: fit-content;
            position: sticky;
            top: 105px;
        }
        .form-header { padding: 30px 35px 20px; background: white; border-bottom: 1px solid #f1f5f9;}
        .form-body { padding: 25px 35px 35px; }

        .reservation-steps {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            padding: 18px 35px 0;
            background: white;
        }

        .reservation-step-indicator {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #64748b;
            border-radius: 999px;
            padding: 9px 8px;
            font-size: .72rem;
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: .2px;
        }

        .reservation-step-indicator.active {
            background: var(--dark-bg);
            color: var(--primary-yellow);
            border-color: var(--dark-bg);
        }

        .reservation-step-indicator.done {
            background: #f0fdf4;
            color: #166534;
            border-color: #86efac;
        }

        .reservation-step-panel { display: none; animation: fadeIn .25s ease; }
        .reservation-step-panel.active { display: block; }

        .step-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            color: var(--text-dark);
            font-size: 1rem;
        }

        .step-title span {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-yellow);
            color: var(--dark-bg);
            font-size: .85rem;
        }

        .wizard-actions {
            display: flex;
            gap: 12px;
            margin-top: 18px;
        }

        .btn-step {
            flex: 1;
            border: none;
            border-radius: 12px;
            padding: 14px 16px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            cursor: pointer;
            transition: .25s ease;
        }

        .btn-step.next, .btn-step.submit-review {
            background: var(--dark-bg);
            color: white;
        }

        .btn-step.next:hover, .btn-step.submit-review:hover {
            background: #000;
            color: var(--primary-yellow);
            transform: translateY(-1px);
        }

        .btn-step.prev {
            background: #f8fafc;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .btn-step.prev:hover {
            background: #eef2f7;
        }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; font-family: 'Montserrat', sans-serif;}
        .form-control { width: 100%; padding: 14px 18px; border-radius: 12px; border: 2px solid #e2e8f0; background: #f8fafc; font-size: 0.95rem; font-family: 'Roboto', sans-serif; font-weight: 500; transition: 0.3s; box-sizing: border-box; outline: none; color: var(--text-dark);}
        .form-control:focus { border-color: var(--primary-yellow); box-shadow: 0 0 0 4px rgba(244, 208, 63, 0.1); background: white;}
        .form-control::placeholder { color: #94a3b8; }
        .form-control[readonly] {
            background: #eef2f7;
            color: #475569;
            cursor: not-allowed;
            border-color: #dbe3ee;
        }

        .geo-toggle-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 18px;
        }
        .geo-check-label {
            display: flex !important;
            align-items: flex-start;
            gap: 10px;
            margin: 0 !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
            font-family: 'Roboto', sans-serif !important;
            font-size: 0.92rem !important;
            color: #334155 !important;
            cursor: pointer;
        }
        .geo-check-label input {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: var(--success-green);
            flex-shrink: 0;
        }
        .geo-note {
            display: block;
            margin-top: 8px;
            margin-left: 28px;
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 600;
            line-height: 1.4;
        }
        .btn-geo {
            width: 100%;
            border: none;
            background: #0f172a;
            color: #ffffff;
            padding: 14px 18px;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            margin-top: 10px;
            font-family: 'Montserrat', sans-serif;
            transition: 0.25s;
        }
        .btn-geo:hover {
            background: #000000;
            color: var(--primary-yellow);
            transform: translateY(-1px);
        }
        .geo-status {
            display: none;
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #f0fdf4;
            border: 1px solid #86efac;
            color: #166534;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .discount-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 22px;
        }
        .discount-title {
            font-family: 'Montserrat', sans-serif;
            font-weight: 800;
            color: var(--text-dark);
            font-size: 1rem;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .calc-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 14px;
        }
        .calc-item {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px;
        }
        .calc-item small {
            display: block;
            color: #64748b;
            font-weight: 800;
            text-transform: uppercase;
            font-size: 0.68rem;
            letter-spacing: .4px;
            margin-bottom: 4px;
        }
        .calc-item strong {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.05rem;
            color: var(--text-dark);
        }
        .calc-item.highlight {
            border-color: var(--primary-yellow);
            background: #fffbeb;
        }
        .calc-item.success {
            border-color: #86efac;
            background: #f0fdf4;
        }
        
        .btn-submit { width: 100%; background: var(--dark-bg); color: white; border: none; padding: 18px; border-radius: 12px; font-weight: 800; font-size: 1.1rem; cursor: pointer; margin-top: 10px; transition: 0.3s; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); letter-spacing: 0.5px; font-family: 'Montserrat', sans-serif;}
        .btn-submit:hover { background: #000; color: var(--primary-yellow); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);}

        /* Alerts inside form */
        .alert-warning { background: #fffbeb; border: 1px solid #fde047; padding: 18px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px; }

        .policy-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 16px;
            display: grid;
            gap: 12px;
            margin-top: 18px;
        }

        .policy-box label {
            display: flex !important;
            align-items: flex-start;
            gap: 10px;
            margin: 0 !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
            font-family: 'Roboto', sans-serif !important;
            font-size: .88rem !important;
            color: #334155 !important;
            line-height: 1.45;
            cursor: pointer;
        }

        .policy-box input { width: 18px; height: 18px; margin-top: 2px; flex-shrink: 0; accent-color: var(--success-green); }

        .overview-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 16px;
        }

        .overview-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
        }

        .overview-item small {
            display: block;
            color: #64748b;
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 3px;
        }

        .overview-item strong {
            display: block;
            color: var(--text-dark);
            font-family: 'Montserrat', sans-serif;
            font-size: .98rem;
            line-height: 1.25;
        }

        .map-placeholder {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 12px;
            padding: 40px 30px;
            text-align: center;
            background: #f8fafc;
            color: #64748b;
        }

        .map-placeholder i {
            font-size: 3rem;
            color: #cbd5e1;
        }

        .map-placeholder strong {
            color: var(--text-dark);
            font-family: 'Montserrat', sans-serif;
        }

        .scheme-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: flex-end;
        }

        .legend-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: .72rem;
            font-weight: 900;
            color: #475569;
            font-family: 'Montserrat', sans-serif;
        }

        .legend-swatch {
            width: 15px;
            height: 15px;
            border-radius: 4px;
            border: 2px solid currentColor;
        }

        .legend-available { color: #15803d; background: rgba(34,197,94,.8); }
        .legend-reserved { color: #a16207; background: rgba(234,179,8,.8); }
        .legend-sold { color: #991b1b; background: rgba(239,68,68,.9); }
        .legend-current { color: #00a6c8; background: rgba(0,229,255,.45); }

        .open-plan-btn {
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: .78rem;
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            cursor: pointer;
            transition: .25s ease;
        }
        .open-plan-btn:hover { background: var(--dark-bg); color: var(--primary-yellow); border-color: var(--dark-bg); }

        .sticky-summary-bar {
            position: fixed;
            left: 50%;
            bottom: 18px;
            transform: translateX(-50%);
            width: min(960px, calc(100% - 32px));
            background: rgba(15,23,42,.96);
            color: white;
            border: 1px solid rgba(255,255,255,.12);
            box-shadow: 0 20px 45px rgba(0,0,0,.25);
            border-radius: 999px;
            z-index: 999;
            padding: 12px 16px 12px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            backdrop-filter: blur(12px);
        }

        .sticky-summary-bar strong { color: var(--primary-yellow); font-family: 'Montserrat', sans-serif; }
        .sticky-summary-btn {
            border: none;
            background: var(--primary-yellow);
            color: var(--dark-bg);
            border-radius: 999px;
            padding: 11px 18px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            cursor: pointer;
            white-space: nowrap;
        }

        .review-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.72);
            z-index: 3000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(8px);
        }

        .review-modal-overlay.active { display: flex; }

        .review-modal {
            background: white;
            border-radius: 22px;
            width: min(680px, 100%);
            box-shadow: 0 30px 70px rgba(0,0,0,.35);
            overflow: hidden;
            animation: dropIn .22s ease;
        }

        .review-modal-header {
            padding: 24px 28px;
            background: #0f172a;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .review-modal-header h3 { margin: 0; font-size: 1.35rem; }
        .review-modal-close {
            border: none;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(255,255,255,.12);
            color: white;
            cursor: pointer;
            font-size: 1.25rem;
        }

        .review-modal-body { padding: 24px 28px; }
        .review-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }

        .review-item {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 14px;
            padding: 13px 14px;
        }

        .review-item small {
            display: block;
            color: #64748b;
            font-size: .7rem;
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .review-item strong {
            color: var(--text-dark);
            font-family: 'Montserrat', sans-serif;
        }

        .review-modal-actions {
            display: flex;
            gap: 12px;
            padding: 0 28px 28px;
        }
        .review-modal-actions button {
            flex: 1;
            border-radius: 12px;
            padding: 15px;
            border: none;
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            cursor: pointer;
        }
        .btn-cancel-review { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0 !important; }
        .btn-confirm-review { background: var(--dark-bg); color: white; }

        .file-help {
            display: block;
            margin-top: 7px;
            color: #64748b;
            font-size: .75rem;
            font-weight: 700;
        }

        /* --- MAPS GRID (Scheme + Geo Map Side by Side on Bottom) --- */
        .maps-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; margin-top: 30px; }
        
        .map-wrapper { display: flex; flex-direction: column; width: 100%; border-radius: 20px; border: 1px solid var(--gray-border); background: #ffffff; overflow: hidden; box-shadow: var(--shadow-md); position: relative; transition: all 0.3s ease; height: 600px; }
        
        /* FULLSCREEN MODIFIER for Scheme Map */
        .map-wrapper.fullscreen { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; z-index: 9999; border-radius: 0; margin: 0; border: none; }

        .map-header { padding: 20px 25px; background: white; border-bottom: 1px solid var(--gray-border); font-size: 1.1rem; font-weight: 800; color: var(--text-dark); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; font-family: 'Montserrat', sans-serif;}
        
        /* Scheme Map specifics */
        .svg-container { flex: 1; width: 100%; position: relative; background: #ffffff; overflow: hidden; cursor: grab; }
        .svg-container:active { cursor: grabbing; }
        #schemeMap { width: 100%; height: 100%; transform-origin: center center; display: block; transition: transform 0.05s linear;}
        
        .map-controls-overlay { position: absolute; right: 20px; top: 50%; transform: translateY(-50%); display: flex; flex-direction: column; gap: 5px; z-index: 100; background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: var(--shadow-md); }
        .zoom-btn { background: transparent; color: #64748b; border: none; width: 45px; height: 45px; font-size: 1.1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; border-bottom: 1px solid #f1f5f9; }
        .zoom-btn:last-child { border-bottom: none; }
        .zoom-btn:hover { background: #f8fafc; color: var(--dark-bg); }

        /* Geo Map specifics */
        #map-display { flex: 1; width: 100%; z-index: 1; background: #e2e8f0; height: 100%;}

        /* --- HIGH VISIBILITY MAP LOT HIGHLIGHTING --- */
        .lot { transition: all 0.3s ease; }
        .lot.available { fill: rgba(34, 197, 94, 0.6); stroke: #16a34a; stroke-width: 2; } 
        .lot.reserved { fill: rgba(245, 158, 11, 0.6); stroke: #d97706; stroke-width: 2; } 
        .lot.sold { fill: rgba(239, 68, 68, 0.7); stroke: #dc2626; stroke-width: 2; } 
        
        .lot-dimmed { opacity: 0.35; pointer-events: none; } 
        .lot-focused { 
            stroke: #00e5ff !important; 
            stroke-width: 7 !important; 
            fill: rgba(0, 229, 255, 0.5) !important; 
            animation: pulseLot 1.5s infinite; 
            z-index: 100; 
            opacity: 1 !important; 
        }
        @keyframes pulseLot {
            0% { filter: drop-shadow(0 0 5px #00e5ff) brightness(1); }
            50% { filter: drop-shadow(0 0 25px #00e5ff) brightness(1.4); }
            100% { filter: drop-shadow(0 0 5px #00e5ff) brightness(1); }
        }

        /* Lightbox */
        .lightbox { display: none; position: fixed; z-index: 2000; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(8px); justify-content: center; align-items: center; flex-direction: column; }
        .lightbox img { max-width: 90%; max-height: 85vh; border-radius: 12px; box-shadow: 0 15px 50px rgba(0,0,0,0.5); user-select: none; }
        .lb-controls { position: absolute; top: 50%; width: 100%; display: flex; justify-content: space-between; padding: 0 40px; transform: translateY(-50%); pointer-events: none; }
        .lb-btn { pointer-events: auto; background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(255,255,255,0.2); width: 55px; height: 55px; border-radius: 50%; font-size: 1.2rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.3s; backdrop-filter: blur(5px); }
        .lb-btn:hover { background: var(--primary-yellow); border-color: var(--primary-yellow); color: var(--dark-bg); transform: scale(1.1); }
        .close-btn { position: absolute; top: 30px; right: 40px; color: white; font-size: 2rem; cursor: pointer; background: rgba(0,0,0,0.3); width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: 0.3s;}
        .close-btn:hover { background: #ef4444; transform: rotate(90deg);}

        .footer { background: var(--dark-bg); color: #cbd5e1; text-align: center; padding: 30px; margin-top: auto; font-size: 0.9rem; }

        @media (max-width: 1000px) { 
            .media-action-grid { grid-template-columns: 1fr; }
            .maps-grid { grid-template-columns: 1fr; } 
            .form-card { position: static; top: auto; }
            .nav-links.desktop-only { display: none; }
            .nav-logo-text { display: none; }
            .profile-info { display: none; }
            .sticky-summary-bar { border-radius: 18px; align-items: flex-start; flex-direction: column; }
            .sticky-summary-btn { width: 100%; }
        }

        @media (max-width: 640px) {
            .reservation-steps { grid-template-columns: 1fr 1fr; padding: 16px 20px 0; }
            .form-header, .form-body { padding-left: 22px; padding-right: 22px; }
            .overview-grid, .review-grid, .calc-grid { grid-template-columns: 1fr; }
            .wizard-actions, .review-modal-actions { flex-direction: column; }
            .main-img-box { height: 300px; }
            .gallery-grid { grid-template-columns: repeat(3, 1fr); }
        }
    </style>
</head>
<body>

    <div id="lightbox" class="lightbox">
        <div class="close-btn" onclick="closeLightbox()">&times;</div>
        <div class="lb-controls">
            <button class="lb-btn" onclick="changeSlide(-1)"><i class="fa-solid fa-chevron-left"></i></button>
            <button class="lb-btn" onclick="changeSlide(1)"><i class="fa-solid fa-chevron-right"></i></button>
        </div>
        <div style="overflow: hidden; display: flex; justify-content: center; align-items: center; width: 100%; height: 85vh;">
            <img id="lightbox-img" src="" style="transition: transform 0.2s ease;">
        </div>
        <div style="display: flex; gap: 15px; margin-top: 20px; align-items: center;">
            <button class="lb-btn" onclick="zoomImage(-0.2)" style="width: 45px; height: 45px; font-size: 1rem;" title="Zoom Out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
            <div style="color: white; font-weight: 700; font-size: 1rem; background: rgba(0,0,0,0.6); padding: 8px 20px; border-radius: 30px;">
                <span id="lb-counter">1</span> / <?= count($js_images) ?>
            </div>
            <button class="lb-btn" onclick="zoomImage(0.2)" style="width: 45px; height: 45px; font-size: 1rem;" title="Zoom In"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
        </div>
    </div>

    <nav class="navbar">
        <div class="nav-logo">
    <a href="index.php" style="display:flex; align-items:center;">
        <img src="assets/logo1.png"
             alt="JEJ Top Priority Corporation Logo"
             style="height:55px;width:auto;display:block;">

        <div class="nav-logo-text">
            <h2>JEJ Top Priority Corporation</h2>
            <span>REALTY & PROFESSIONAL SERVICES</span>
        </div>
    </a>
</div>
        
        <div class="nav-links desktop-only">
            <a href="index.php">Home</a>
            <a href="index.php#properties" class="active">Properties</a>
            <a href="index.php#contact">Contact</a>
        </div>

        <div class="nav-actions">
            <a href="notifications.php" class="notification-bell" title="Notifications">
                <i class="fa-solid fa-bell"></i>
                <?php if($unread_count > 0): ?> <span class="notification-dot"></span> <?php endif; ?>
            </a>
            
            <div class="profile-dropdown-container">
                <button class="profile-trigger" id="profileBtn">
                    <div class="profile-info desktop-only">
                        <span class="profile-name"><?= htmlspecialchars($_SESSION['fullname']) ?></span>
                        <span class="profile-role"><?= htmlspecialchars($_SESSION['role']) ?></span>
                    </div>
                    <div class="avatar-circle">
                        <?= htmlspecialchars(strtoupper(substr($_SESSION['fullname'], 0, 1))) ?>
                    </div>
                </button>
                <div class="profile-dropdown-menu" id="profileDropdown">
                    <a href="profile.php" class="profile-dropdown-item"><i class="fa-regular fa-user"></i> My Profile</a>
                    <?php if(in_array($_SESSION['role'], ['SUPER ADMIN', 'ADMIN', 'MANAGER'])): ?>
                        <a href="admin.php" class="profile-dropdown-item"><i class="fa-solid fa-shield-halved"></i> Admin Dashboard</a>
                    <?php else: ?>
                        <a href="my_reservations.php" class="profile-dropdown-item"><i class="fa-solid fa-file-contract"></i> My Reservations</a>
                    <?php endif; ?>
                    <a href="logout.php" class="profile-dropdown-item logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <main class="main-content">

        <div class="breadcrumb animate-on-scroll">
            <a href="index.php"><i class="fa-solid fa-house" style="margin-right: 4px;"></i> Home</a>
            <i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i>
            <span><?= htmlspecialchars($lot['location']) ?></span>
            <i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i>
            <strong style="color: var(--text-dark);">Block <?= htmlspecialchars($lot['block_no']) ?> Lot <?= htmlspecialchars($lot['lot_no']) ?></strong>
        </div>

        <div class="media-action-grid animate-on-scroll">
            
            <div class="gallery-section">
                <div class="main-img-box" onclick="openLightbox('<?= $main_img ?>')">
                    <img src="<?= $main_img ?>" class="main-img">
                    <span class="badge <?= $lot['status'] ?>" style="top:20px; left:20px; right:auto;"><?= $lot['status'] ?></span>
                    <div style="position: absolute; bottom: 20px; right: 20px; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); color: white; padding: 10px 18px; border-radius: 10px; font-size: 0.85rem; font-weight: 700; pointer-events: none; border: 1px solid rgba(255,255,255,0.2);">
                        <i class="fa-solid fa-expand" style="margin-right: 5px;"></i> View Full Screen
                    </div>
                </div>

                <div class="gallery-grid">
                    <div class="thumb-box" onclick="openLightbox('<?= $main_img ?>')">
                        <img src="<?= $main_img ?>" class="thumb-img">
                    </div>
                    <?= $gallery_html ?>
                </div>

                <div class="specs-card">
                    <span class="prop-type"><?= $lot['property_type'] ?: 'Residential Lot' ?></span>
                    
                    <h2>Block <?= htmlspecialchars($lot['block_no']) ?>, Lot <?= htmlspecialchars($lot['lot_no']) ?></h2>
                    
                    <div class="location">
                        <i class="fa-solid fa-location-dot" style="color: var(--primary-yellow);"></i> <?= htmlspecialchars($lot['location']) ?>
                    </div>

                    <div class="price-grid">
                        <div>
                            <small>Lot Area</small>
                            <strong><?= number_format($lot['area']) ?> m²</strong>
                        </div>
                        <div>
                            <small>Price / SQM</small>
                            <strong>₱<?= number_format($display_price_per_sqm) ?></strong>
                        </div>
                        <div class="total-row">
                            <small>Total Contract Price</small>
                            <strong>₱<?= number_format($default_calc['cash_tcp']) ?></strong>
                        </div>
                    </div>

                    <h4>Property Overview</h4>
                    <div class="overview-grid">
                        <div class="overview-item">
                            <small>Location</small>
                            <strong><?= htmlspecialchars($lot['location']) ?></strong>
                        </div>
                        <div class="overview-item">
                            <small>Block</small>
                            <strong><?= htmlspecialchars($lot['block_no']) ?></strong>
                        </div>
                        <div class="overview-item">
                            <small>Lot</small>
                            <strong><?= htmlspecialchars($lot['lot_no']) ?></strong>
                        </div>
                        <div class="overview-item">
                            <small>Area</small>
                            <strong><?= number_format((float)$lot['area']) ?> m²</strong>
                        </div>
                        <div class="overview-item">
                            <small>Classification</small>
                            <strong><?= htmlspecialchars($lot_classification) ?></strong>
                        </div>
                        <div class="overview-item">
                            <small>Status</small>
                            <strong><?= htmlspecialchars(ucfirst(strtolower($lot_status))) ?></strong>
                        </div>
                        <div class="overview-item">
                            <small>Price / SQM</small>
                            <strong>₱<?= number_format($display_price_per_sqm) ?></strong>
                        </div>
                        <div class="overview-item">
                            <small>Total Contract Price</small>
                            <strong>₱<?= number_format($default_calc['cash_tcp']) ?></strong>
                        </div>
                        <div class="overview-item" style="grid-column: 1 / -1;">
                            <small>Geographic Pin</small>
                            <strong><?= $has_geo_coordinates ? 'Map pin available' : 'Map pin not yet available' ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-card">
                    <?php if($is_lot_available): ?>
                        <div class="form-header">
                            <h3 style="font-size: 1.5rem; font-weight: 800; margin: 0 0 5px; color: var(--text-dark); font-family: 'Montserrat', sans-serif;">Reserve Property</h3>
                            <p style="color: #64748b; font-size: 0.95rem; margin: 0; font-weight: 500;">Fill out the details below to secure this lot.</p>
                        </div>

                        <div class="reservation-steps" aria-label="Reservation steps">
                            <div class="reservation-step-indicator active" data-step-indicator="1">1. Buyer</div>
                            <div class="reservation-step-indicator" data-step-indicator="2">2. Payment</div>
                            <div class="reservation-step-indicator" data-step-indicator="3">3. Documents</div>
                            <div class="reservation-step-indicator" data-step-indicator="4">4. Review</div>
                        </div>

                        <div class="form-body">
                            <form action="actions.php" method="POST" enctype="multipart/form-data" id="reservationForm" data-max-file-size="5242880">
                                <input type="hidden" name="action" value="reserve">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="lot_id" value="<?= $lot['id'] ?>">
                                <input type="hidden" name="server_verified_lot_area" value="<?= htmlspecialchars((string)$lot['area'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="server_verified_price_per_sqm" value="<?= htmlspecialchars((string)$display_price_per_sqm, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="server_verified_tcp" value="<?= htmlspecialchars((string)$default_calc['final_tcp'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="reservation_fee" id="reservationFeeInput" value="<?= $reservation_fee ?>">
                                <input type="hidden" name="discount_rate" id="discountRateInput" value="0">
                                <input type="hidden" name="discount_amount" id="discountAmountInput" value="0">
                                <input type="hidden" name="discounted_total_price" id="discountedTcpInput" value="<?= $default_calc['final_tcp'] ?>">
                                <input type="hidden" name="balance_after_reservation_fee" id="balanceAfterFeeInput" value="<?= $default_calc['final_tcp'] ?>">
                                <input type="hidden" name="required_dp" id="requiredDpInput" value="0">
                                <input type="hidden" name="payment_scheme" id="paymentSchemeInput" value="SPOT_CASH">
                                <input type="hidden" name="additional_per_sqm" id="additionalPerSqmInput" value="0">
                                <input type="hidden" name="additional_amount" id="additionalAmountInput" value="0">

                                <div class="reservation-step-panel active" data-step-panel="1">
                                    <div class="step-title"><span>1</span> Buyer Information</div>

                                <div class="form-group">
                                    <label>Full Name <span style="color:#ef4444">*</span></label>
                                    <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($buyer_fullname) ?>" readonly required>
                                </div>

                                <div class="form-group">
                                    <label>Email Address <span style="color:#ef4444">*</span></label>
                                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($buyer_email) ?>" readonly required>
                                </div>

                                <div style="display:flex; gap:15px;">
                                    <div class="form-group" style="flex:1;">
                                        <label>Mobile No. <span style="color:#ef4444">*</span></label>
                                        <input type="text" name="contact_number" class="form-control" value="<?= htmlspecialchars($buyer_contact) ?>" readonly required>
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label>Agent Name</label>
                                        <input type="text" name="agent_name" class="form-control" value="<?= htmlspecialchars($buyer_agent) ?>" placeholder="Who assisted you?">
                                    </div>
                                </div>

                                <div class="geo-toggle-box">
                                    <label class="geo-check-label">
                                        <input type="checkbox" id="overseasBuyer" name="is_overseas_buyer" value="1">
                                        <span>
                                            Buyer is currently overseas
                                            <small class="geo-note">
                                                Use GPS location instead of typing a complete home address.
                                            </small>
                                        </span>
                                    </label>
                                </div>

                                <input type="hidden" name="location_mode" id="locationMode" value="LOCAL">
                                <input type="hidden" name="buyer_latitude" id="buyerLatitude" value="">
                                <input type="hidden" name="buyer_longitude" id="buyerLongitude" value="">

                                <div class="form-group" id="addressSection">
                                    <label>Residential Address <span style="color:#ef4444">*</span></label>
                                    <input type="text"
                                           name="address"
                                           id="localAddress"
                                           class="form-control"
                                           value="<?= htmlspecialchars($buyer_address) ?>"
                                           placeholder="House No, Street, Brgy, City"
                                           required>
                                </div>

                                <div id="geoSection" style="display:none;">
                                    <div class="form-group">
                                        <label>Current GPS Location <span style="color:#ef4444">*</span></label>
                                        <input type="text"
                                               name="address"
                                               id="geoAddress"
                                               class="form-control"
                                               placeholder="Click Detect My Location"
                                               readonly
                                               disabled>
                                        <button type="button" class="btn-geo" onclick="getBuyerLocation()">
                                            <i class="fa-solid fa-location-crosshairs" style="margin-right:8px;"></i>
                                            Detect My Location
                                        </button>
                                        <div id="geoStatus" class="geo-status"></div>
                                        <small style="display:block; margin-top:8px; color:#64748b; font-size:0.75rem; font-weight:700;">
                                            <i class="fa-solid fa-circle-info" style="color: var(--primary-yellow);"></i>
                                            Browser permission is required. The saved address will include GPS coordinates for verification.
                                        </small>
                                    </div>
                                </div>

                                <div class="wizard-actions">
                                    <button type="button" class="btn-step next" onclick="goReservationStep(2)">Next: Payment Option <i class="fa-solid fa-arrow-right"></i></button>
                                </div>
                                </div>

                                <div class="reservation-step-panel" data-step-panel="2">
                                    <div class="step-title"><span>2</span> Payment Option</div>

                                <div class="discount-box">
                                    <div class="discount-title">
                                        <i class="fa-solid fa-tags" style="color: var(--primary-yellow);"></i>
                                        Payment Option & Updated Price List Computation
                                    </div>

                                    <div class="form-group">
                                        <label>Payment Option</label>
                                        <select class="form-control" id="paymentOption" name="payment_type" required>
                                            <option value="SPOT_CASH">Spot Cash - Base Price / SQM, No DP</option>
                                            <option value="INSTALLMENT">Installment - Updated Price / SQM + 20% DP</option>
                                            <option value="STRAIGHT_PAYMENT">Straight Payment - Cash Price + ₱<?= number_format($straight_calc['straight_additional_per_sqm'], 0) ?>/sqm + 20% DP</option>
                                        </select>
                                        <small id="discountNote" style="display:block; margin-top:8px; color:#64748b; font-size:0.75rem; font-weight:700;">
                                            <i class="fa-solid fa-circle-check" style="color: var(--success-green);"></i>
                                            Spot Cash selected: uses base Cash Price/SQM. Reservation fee is recorded separately in Payment Tracking.
                                        </small>
                                    </div>

                                    <div class="form-group" id="installmentMonthsGroup">
                                        <label>Installment Terms</label>
                                        <select class="form-control" id="installmentMonths" name="installment_months">
                                            <option value="12">12 Months</option>
                                            <option value="24">24 Months</option>
                                            <option value="36" selected>36 Months</option>
                                        </select>
                                    </div>

                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label>Selected Pricing Option</label>
                                        <input type="text"
                                               class="form-control"
                                               id="discountDisplay"
                                               value="Spot Cash - Base Price / SQM, No DP"
                                               readonly>
                                    </div>

                                    <div class="calc-grid">
                                        <div class="calc-item">
                                            <small>Cash TCP / Base Contract Price</small>
                                            <strong id="originalTcpText">₱<?= number_format($tcp, 2) ?></strong>
                                        </div>
                                        <div class="calc-item">
                                            <small>Price Difference Amount</small>
                                            <strong id="discountAmountText">₱0.00</strong>
                                        </div>
                                        <div class="calc-item highlight">
                                            <small>Final Contract Price</small>
                                            <strong id="discountedTcpText">₱<?= number_format($tcp, 2) ?></strong>
                                        </div>
                                        <div class="calc-item">
                                            <small>Reservation Fee to Pay</small>
                                            <strong id="reservationFeeText">₱<?= number_format($reservation_fee, 2) ?></strong>
                                        </div>
                                        <div class="calc-item success" style="grid-column: 1 / -1;">
                                            <small>Amount to Finance</small>
                                            <strong id="balanceAfterFeeText">₱<?= number_format($default_calc['balance_to_finance'], 2) ?></strong>
                                        </div>
                                        <div class="calc-item highlight" style="grid-column: 1 / -1;">
                                            <small>Required Down Payment</small>
                                            <strong id="requiredDpText">₱0.00</strong>
                                        </div>
                                        <div class="calc-item" id="monthlyPaymentBox" style="grid-column: 1 / -1;">
                                            <small>Estimated Monthly Amortization</small>
                                            <strong id="monthlyPaymentText">₱0.00</strong>
                                        </div>
                                    </div>
                                </div>

                                <div class="wizard-actions">
                                    <button type="button" class="btn-step prev" onclick="goReservationStep(1)"><i class="fa-solid fa-arrow-left"></i> Back</button>
                                    <button type="button" class="btn-step next" onclick="goReservationStep(3)">Next: Upload Documents <i class="fa-solid fa-arrow-right"></i></button>
                                </div>
                                </div>

                                <div class="reservation-step-panel" data-step-panel="3">
                                    <div class="step-title"><span>3</span> Upload Documents</div>

                                <div style="border-top: 1px dashed #cbd5e1; margin: 10px 0 25px;"></div>
                                
                                <div style="margin-bottom: 20px;">
                                    <strong style="font-size: 1rem; color: var(--text-dark); font-weight: 800; font-family: 'Montserrat', sans-serif;">Required Documents</strong>
                                    <p style="font-size: 0.85rem; color: #64748b; margin: 4px 0 0; font-weight: 500;">Please upload clear files only. Accepted: JPG, PNG, PDF. Maximum size: 5MB per file.</p>
                                </div>

                                <div class="form-group">
                                    <label>1. Valid Government ID</label>
                                    <input type="file" name="valid_id" id="validIdFile" class="form-control secure-file" style="padding: 10px 15px; background: white;" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
                                    <small class="file-help"><i class="fa-solid fa-shield-halved"></i> JPG, PNG, or PDF only. Max 5MB. Files should be renamed and stored privately by actions.php.</small>
                                </div>
                                <div class="form-group">
                                    <label>2. Live Selfie Verification</label>

                                    <div id="cameraContainer" style="display:none;">
                                        <video id="camera"
                                               autoplay
                                               playsinline
                                               style="
                                                    width:100%;
                                                    border-radius:12px;
                                                    border:2px solid #e2e8f0;
                                                    background:#000;
                                                    max-height:320px;
                                               ">
                                        </video>
                                    </div>

                                    <canvas id="snapshot" style="display:none;"></canvas>

                                    <input type="hidden"
                                           name="live_selfie_data"
                                           id="liveSelfieData"
                                           required>

                                    <button type="button"
                                            class="btn-geo"
                                            id="startCameraBtn"
                                            onclick="startCamera()">
                                        <i class="fa-solid fa-camera"></i>
                                        Start Camera
                                    </button>

                                    <button type="button"
                                            class="btn-geo"
                                            id="captureBtn"
                                            onclick="captureSelfie()"
                                            style="display:none;">
                                        <i class="fa-solid fa-camera"></i>
                                        Capture Selfie
                                    </button>

                                    <button type="button"
                                            class="btn-geo"
                                            id="retakeBtn"
                                            onclick="retakeSelfie()"
                                            style="display:none; background:#475569;">
                                        <i class="fa-solid fa-rotate"></i>
                                        Retake Selfie
                                    </button>

                                    <div id="selfieStatus"
                                         class="geo-status"
                                         style="display:none;">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>3. Proof of Reservation Payment</label>
                                    <input type="file" name="proof" id="paymentProofFile" class="form-control secure-file" style="padding: 10px 15px; background: white;" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
                                    <small class="file-help"><i class="fa-solid fa-circle-info" style="color: var(--primary-yellow);"></i> Reservation fee of ₱<?= number_format($reservation_fee, 2) ?> is required. This will be reviewed by admin before the lot is officially reserved.</small>
                                </div>

                                <div class="alert-warning">
                                    <i class="fa-solid fa-circle-exclamation" style="color: #ca8a04; font-size: 1.2rem; margin-top: 2px;"></i>
                                    <div>
                                        <strong style="color: #a16207; font-size: 0.95rem; display: block; margin-bottom: 2px;">Payment Required</strong>
                                        <span style="color: #b45309; font-size: 0.85rem; font-weight: 600; line-height: 1.4; display: block;">A reservation fee of <b>₱<?= number_format($reservation_fee, 2) ?></b> is required. This will be reviewed by admin before the lot is officially reserved.</span>
                                    </div>
                                </div>

                                <div class="wizard-actions">
                                    <button type="button" class="btn-step prev" onclick="goReservationStep(2)"><i class="fa-solid fa-arrow-left"></i> Back</button>
                                    <button type="button" class="btn-step next" onclick="goReservationStep(4)">Next: Review <i class="fa-solid fa-arrow-right"></i></button>
                                </div>
                                </div>

                                <div class="reservation-step-panel" data-step-panel="4">
                                    <div class="step-title"><span>4</span> Review & Submit</div>

                                    <div class="calc-grid">
                                        <div class="calc-item">
                                            <small>Lot</small>
                                            <strong><?= htmlspecialchars($review_lot_label) ?></strong>
                                        </div>
                                        <div class="calc-item">
                                            <small>Location</small>
                                            <strong><?= htmlspecialchars($lot['location']) ?></strong>
                                        </div>
                                        <div class="calc-item highlight">
                                            <small>Final Contract Price</small>
                                            <strong id="reviewFinalTcpText">₱<?= number_format($tcp, 2) ?></strong>
                                        </div>
                                        <div class="calc-item">
                                            <small>Reservation Fee</small>
                                            <strong>₱<?= number_format($reservation_fee, 2) ?></strong>
                                        </div>
                                        <div class="calc-item" style="grid-column: 1 / -1;">
                                            <small>Payment Option</small>
                                            <strong id="reviewPaymentOptionText">Spot Cash - Base Price / SQM, No DP</strong>
                                        </div>
                                        <div class="calc-item">
                                            <small>Valid ID</small>
                                            <strong id="reviewIdStatus">No file selected</strong>
                                        </div>
                                        <div class="calc-item">
                                            <small>Live Selfie</small>
                                            <strong id="reviewSelfieStatus">Not captured</strong>
                                        </div>
                                        <div class="calc-item">
                                            <small>Payment Proof</small>
                                            <strong id="reviewProofStatus">No file selected</strong>
                                        </div>
                                    </div>

                                    <div class="policy-box">
                                        <label>
                                            <input type="checkbox" name="policy_verification" id="policyVerification" value="1" required>
                                            <span>I understand that this reservation is subject to admin verification and payment confirmation.</span>
                                        </label>
                                        <label>
                                            <input type="checkbox" name="policy_privacy" id="policyPrivacy" value="1" required>
                                            <span>I agree that JEJ Top Priority Corporation may process my submitted ID, selfie, address, and payment proof for reservation verification.</span>
                                        </label>
                                    </div>

                                    <div class="wizard-actions">
                                        <button type="button" class="btn-step prev" onclick="goReservationStep(3)"><i class="fa-solid fa-arrow-left"></i> Back</button>
                                        <button type="button" class="btn-step submit-review" onclick="openReservationReview()"><i class="fa-solid fa-clipboard-check"></i> Review Reservation</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center; padding: 80px 40px; background: #fef2f2; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 500px;">
                            <div style="width: 80px; height: 80px; background: #fee2e2; color: #dc2626; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin-bottom: 25px; box-shadow: 0 4px 10px rgba(220, 38, 38, 0.2);">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <h3 style="margin: 0 0 12px; color: #991b1b; font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px; font-family: 'Montserrat', sans-serif;">Property Unavailable</h3>
                            <p style="color: #b91c1c; font-size: 1rem; line-height: 1.6; margin: 0; font-weight: 500;">This lot is no longer available. It has been marked as <strong><?= htmlspecialchars($lot['status']) ?></strong> and cannot be reserved online at this time.</p>
                            <a href="index.php#properties" style="margin-top: 30px; padding: 16px 30px; background: white; color: #dc2626; border: 2px solid #fecdd3; border-radius: 12px; text-decoration: none; font-weight: 800; font-size: 1rem; transition: 0.3s; box-shadow: 0 4px 6px rgba(220, 38, 38, 0.1);">Browse Other Lots <i class="fa-solid fa-arrow-right" style="margin-left: 5px;"></i></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="maps-grid animate-on-scroll">
            
            <div class="map-wrapper" id="schemeWrapper">
                <div class="map-header">
                    <span><i class="fa-solid fa-map" style="color: var(--primary-yellow); margin-right: 8px;"></i> Subdivision Plan</span>
                    <div class="scheme-legend">
                        <span class="legend-chip"><span class="legend-swatch legend-available"></span> Available</span>
                        <span class="legend-chip"><span class="legend-swatch legend-reserved"></span> Reserved</span>
                        <span class="legend-chip"><span class="legend-swatch legend-sold"></span> Sold</span>
                        <span class="legend-chip"><span class="legend-swatch legend-current"></span> Current Lot</span>
                        <button type="button" class="open-plan-btn" onclick="toggleMapFullscreen()"><i class="fa-solid fa-expand"></i> Open Full Subdivision Plan</button>
                    </div>
                </div>
                
                <div class="svg-container" id="svgContainer">
                    <div class="map-controls-overlay">
                        <button class="zoom-btn" onclick="toggleMapFullscreen()" title="Toggle Fullscreen"><i class="fa-solid fa-expand" id="fsIcon"></i></button>
                        <button class="zoom-btn" onclick="zoomMap(0.2)" title="Zoom In"><i class="fa-solid fa-plus"></i></button>
                        <button class="zoom-btn" onclick="zoomMap(-0.2)" title="Zoom Out"><i class="fa-solid fa-minus"></i></button>
                        <button class="zoom-btn" onclick="resetMap()" title="Reset View"><i class="fa-solid fa-rotate-left"></i></button>
                    </div>
                    
                    <svg id="schemeMap" viewBox="0 0 1464 1052" preserveAspectRatio="xMidYMid meet">
                        <image href="<?= htmlspecialchars($current_map, ENT_QUOTES) ?>" x="0" y="0" width="1464" height="1052"></image>
                        <?php foreach ($all_lots as $l): 
                            $points = htmlspecialchars($l['coordinates'] ?? '');
                            if(empty($points)) continue;
                            
                            $isCurrent = ($l['id'] == $lot['id']);
                            $statusClass = strtolower($l['status']);
                            $polyClass = "lot " . $statusClass . ($isCurrent ? " lot-focused" : " lot-dimmed");
                        ?>
                        <polygon class="<?= $polyClass ?>" points="<?= $points ?>">
                            <title>Block <?= htmlspecialchars($l['block_no']) ?> - Lot <?= htmlspecialchars($l['lot_no']) ?></title>
                        </polygon>
                        <?php endforeach; ?>
                    </svg>
                </div>
                
                <div style="padding: 15px 25px; background: white; font-size: 0.85rem; color: #64748b; border-top: 1px solid var(--gray-border); flex-shrink: 0; text-align: center; display: flex; align-items: center; justify-content: center; gap: 15px; flex-wrap: wrap;">
                    <span style="color: #00a6c8; text-shadow: 0 0 4px rgba(0,229,255,0.35); font-weight: 900;"><i class="fa-solid fa-location-crosshairs"></i> Cyan Border = Current Lot</span>
                    <span style="font-weight: 800;"><strong style="color:#059669;">Green = Available</strong> • <strong style="color:#d97706;">Yellow = Reserved</strong> • <strong style="color:#dc2626;">Red = Sold</strong></span>
                </div>
            </div>

            <?php if($has_geo_coordinates): ?>
            <div class="map-wrapper geo-wrapper">
                <div class="map-header">
                    <span><i class="fa-solid fa-earth-asia" style="color: var(--primary-yellow); margin-right: 8px;"></i> Geographic Location</span>
                </div>
                <div id="map-display"></div>
            </div>
            <?php else: ?>
            <div class="map-wrapper geo-wrapper">
                <div class="map-header">
                    <span><i class="fa-solid fa-earth-asia" style="color: var(--primary-yellow); margin-right: 8px;"></i> Geographic Location</span>
                </div>
                <div class="map-placeholder">
                    <i class="fa-solid fa-location-dot"></i>
                    <strong>Geographic map is not yet available for this lot.</strong>
                    <span>This prevents showing pixel map coordinates as GPS pins.</span>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <?php if($is_lot_available): ?>
    <div class="sticky-summary-bar">
        <div>
            <span>Total: <strong id="stickyTotalText">₱<?= number_format($tcp, 2) ?></strong></span>
            <span style="color:#64748b; margin: 0 10px;">|</span>
            <span>Reservation: <strong>₱<?= number_format($reservation_fee, 2) ?></strong></span>
        </div>
        <button type="button" class="sticky-summary-btn" onclick="scrollToReservationForm()">Reserve Now</button>
    </div>

    <div class="review-modal-overlay" id="reservationReviewModal" role="dialog" aria-modal="true" aria-labelledby="reservationReviewTitle">
        <div class="review-modal">
            <div class="review-modal-header">
                <h3 id="reservationReviewTitle"><i class="fa-solid fa-clipboard-check" style="color: var(--primary-yellow);"></i> Review Reservation</h3>
                <button type="button" class="review-modal-close" onclick="closeReservationReview()" aria-label="Close">&times;</button>
            </div>
            <div class="review-modal-body">
                <div class="review-grid">
                    <div class="review-item">
                        <small>Lot</small>
                        <strong><?= htmlspecialchars($review_lot_label) ?></strong>
                    </div>
                    <div class="review-item">
                        <small>Location</small>
                        <strong><?= htmlspecialchars($lot['location']) ?></strong>
                    </div>
                    <div class="review-item">
                        <small>Final Contract Price</small>
                        <strong id="modalFinalTcpText">₱<?= number_format($tcp, 2) ?></strong>
                    </div>
                    <div class="review-item">
                        <small>Reservation Fee</small>
                        <strong>₱<?= number_format($reservation_fee, 2) ?></strong>
                    </div>
                    <div class="review-item" style="grid-column: 1 / -1;">
                        <small>Payment Option</small>
                        <strong id="modalPaymentOptionText">Spot Cash - Base Price / SQM, No DP</strong>
                    </div>
                    <div class="review-item">
                        <small>Uploaded ID</small>
                        <strong id="modalIdStatus">No</strong>
                    </div>
                    <div class="review-item">
                        <small>Selfie</small>
                        <strong id="modalSelfieStatus">No</strong>
                    </div>
                    <div class="review-item">
                        <small>Payment Proof</small>
                        <strong id="modalProofStatus">No</strong>
                    </div>
                </div>
                <div class="alert-warning" style="margin-bottom:0;">
                    <i class="fa-solid fa-circle-info" style="color:#ca8a04; font-size:1.1rem; margin-top:2px;"></i>
                    <div>
                        <strong style="color:#92400e;">Pending Verification</strong>
                        <span style="display:block; color:#92400e; font-size:.85rem;">After submission, your reservation will be sent for admin review. The lot is not officially reserved until documents and payment are verified.</span>
                    </div>
                </div>
            </div>
            <div class="review-modal-actions">
                <button type="button" class="btn-cancel-review" onclick="closeReservationReview()">Cancel</button>
                <button type="button" class="btn-confirm-review" onclick="confirmReservationSubmit()"><i class="fa-solid fa-check"></i> Confirm Reservation</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer natively pushed to bottom by Flex Column body -->
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> JEJ Top Priority Corporation. All Rights Reserved. Built with precision.</p>
    </footer>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Force reset body overflow on load
            document.body.style.overflow = 'auto';

            // Profile Dropdown Logic
            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');
            if (profileBtn && profileDropdown) {
                profileBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    profileDropdown.classList.toggle('active');
                });
                document.addEventListener('click', function(e) {
                    if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                        profileDropdown.classList.remove('active');
                    }
                });
            }

            // IntersectionObserver for scroll animations
            const observerOptions = { threshold: 0.1, rootMargin: "0px 0px -50px 0px" };
            const observer = new IntersectionObserver(function(entries, observer) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.animate-on-scroll').forEach(el => { observer.observe(el); });
        });

        // --- OVERSEAS BUYER GEOLOCATION ADDRESS ---
        function toggleOverseasLocation() {
            const overseasBuyer = document.getElementById('overseasBuyer');
            const addressSection = document.getElementById('addressSection');
            const geoSection = document.getElementById('geoSection');
            const localAddress = document.getElementById('localAddress');
            const geoAddress = document.getElementById('geoAddress');
            const locationMode = document.getElementById('locationMode');

            if (!overseasBuyer || !addressSection || !geoSection || !localAddress || !geoAddress) return;

            if (overseasBuyer.checked) {
                addressSection.style.display = 'none';
                geoSection.style.display = 'block';

                localAddress.disabled = true;
                localAddress.required = false;

                geoAddress.disabled = false;
                geoAddress.required = true;

                if (locationMode) locationMode.value = 'OVERSEAS_GPS';
            } else {
                addressSection.style.display = 'block';
                geoSection.style.display = 'none';

                localAddress.disabled = false;
                localAddress.required = true;

                geoAddress.disabled = true;
                geoAddress.required = false;
                geoAddress.value = '';

                document.getElementById('buyerLatitude').value = '';
                document.getElementById('buyerLongitude').value = '';

                if (locationMode) locationMode.value = 'LOCAL';
            }
        }

        function getBuyerLocation() {
            const geoAddress = document.getElementById('geoAddress');
            const geoStatus = document.getElementById('geoStatus');
            const latInput = document.getElementById('buyerLatitude');
            const lngInput = document.getElementById('buyerLongitude');

            if (!navigator.geolocation) {
                alert('Geolocation is not supported by this browser.');
                return;
            }

            if (geoStatus) {
                geoStatus.style.display = 'block';
                geoStatus.style.background = '#fffbeb';
                geoStatus.style.borderColor = '#fde047';
                geoStatus.style.color = '#92400e';
                geoStatus.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Detecting current location...';
            }

            navigator.geolocation.getCurrentPosition(function(position) {
                const lat = position.coords.latitude.toFixed(8);
                const lng = position.coords.longitude.toFixed(8);
                const accuracy = Math.round(position.coords.accuracy || 0);

                if (latInput) latInput.value = lat;
                if (lngInput) lngInput.value = lng;

                const savedLocation = 'Overseas GPS Location: ' + lat + ', ' + lng + ' | Accuracy: ' + accuracy + ' meters';
                if (geoAddress) geoAddress.value = savedLocation;

                if (geoStatus) {
                    geoStatus.style.background = '#f0fdf4';
                    geoStatus.style.borderColor = '#86efac';
                    geoStatus.style.color = '#166534';
                    geoStatus.innerHTML = '<i class="fa-solid fa-circle-check"></i> Location detected successfully.';
                }
            }, function(error) {
                let message = 'Unable to retrieve location.';
                if (error.code === error.PERMISSION_DENIED) {
                    message = 'Location permission was denied. Please allow location access in your browser.';
                } else if (error.code === error.POSITION_UNAVAILABLE) {
                    message = 'Location information is unavailable.';
                } else if (error.code === error.TIMEOUT) {
                    message = 'Location request timed out. Please try again.';
                }

                if (geoStatus) {
                    geoStatus.style.background = '#fef2f2';
                    geoStatus.style.borderColor = '#fecaca';
                    geoStatus.style.color = '#991b1b';
                    geoStatus.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + message;
                } else {
                    alert(message);
                }
            }, {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const overseasBuyer = document.getElementById('overseasBuyer');
            if (overseasBuyer) {
                overseasBuyer.addEventListener('change', toggleOverseasLocation);
                toggleOverseasLocation();
            }
        });

        // --- BUYER PAYMENT OPTION + PRICING MATRIX COMPUTATION ---
        const pricingOptions = <?= json_encode($pricing_options) ?>;
        const reservationFee = <?= json_encode($reservation_fee) ?>;

        function moneyFormat(value) {
            return '₱' + Number(value || 0).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function getPricingOption(option) {
            return pricingOptions[option] || pricingOptions.SPOT_CASH;
        }

        function updateReservationComputation() {
            const paymentOption = document.getElementById('paymentOption');
            const discountNote = document.getElementById('discountNote');
            const discountDisplay = document.getElementById('discountDisplay');
            const installmentMonths = document.getElementById('installmentMonths');
            const installmentMonthsGroup = document.getElementById('installmentMonthsGroup');
            const monthlyPaymentBox = document.getElementById('monthlyPaymentBox');

            if (!paymentOption) return;

            const option = paymentOption.value;
            const pricing = getPricingOption(option);
            const isSpotCash = Number(pricing.is_spot_cash || 0) === 1;
            const finalTcp = Number(pricing.final_tcp || 0);
            let requiredDp = Number(pricing.required_dp || 0);
            const balanceToFinance = Number(pricing.balance_to_finance || 0);
            let monthlyPayment = Number(pricing.monthly_payment || 0);

            if (!isSpotCash) {
                if (installmentMonthsGroup) installmentMonthsGroup.style.display = 'block';
                if (monthlyPaymentBox) monthlyPaymentBox.style.display = 'block';
                const months = parseInt(installmentMonths ? installmentMonths.value : 36, 10);
                monthlyPayment = months > 0 ? balanceToFinance / months : 0;
            } else {
                if (installmentMonthsGroup) installmentMonthsGroup.style.display = 'none';
                if (monthlyPaymentBox) monthlyPaymentBox.style.display = 'none';
                requiredDp = 0;
                monthlyPayment = 0;
            }

            if (discountNote) {
                discountNote.innerHTML =
                    '<i class="fa-solid fa-circle-check" style="color: var(--success-green);"></i> ' +
                    (pricing.payment_note || 'Pricing computed from the updated pricing matrix.');
            }

            document.getElementById('originalTcpText').innerText = moneyFormat(pricing.cash_tcp || pricing.original_tcp || 0);
            document.getElementById('discountAmountText').innerText = moneyFormat(pricing.additional_amount || 0);
            document.getElementById('discountedTcpText').innerText = moneyFormat(finalTcp);
            document.getElementById('reservationFeeText').innerText = moneyFormat(reservationFee);
            document.getElementById('balanceAfterFeeText').innerText = moneyFormat(balanceToFinance);
            document.getElementById('requiredDpText').innerText = moneyFormat(requiredDp);

            const monthlyPaymentText = document.getElementById('monthlyPaymentText');
            if (monthlyPaymentText) {
                monthlyPaymentText.innerText = moneyFormat(monthlyPayment);
            }

            if (discountDisplay) {
                discountDisplay.value = pricing.payment_label || paymentOption.options[paymentOption.selectedIndex].text;
            }

            document.getElementById('discountRateInput').value = '0';
            document.getElementById('discountAmountInput').value = '0.00';
            document.getElementById('discountedTcpInput').value = finalTcp.toFixed(2);
            document.getElementById('balanceAfterFeeInput').value = finalTcp.toFixed(2);
            document.getElementById('requiredDpInput').value = requiredDp.toFixed(2);
            document.getElementById('paymentSchemeInput').value = option;

            const additionalPerSqmInput = document.getElementById('additionalPerSqmInput');
            const additionalAmountInput = document.getElementById('additionalAmountInput');
            if (additionalPerSqmInput) additionalPerSqmInput.value = Number(pricing.additional_per_sqm || 0).toFixed(2);
            if (additionalAmountInput) additionalAmountInput.value = Number(pricing.additional_amount || 0).toFixed(2);

            if (typeof refreshReservationReview === 'function') {
                refreshReservationReview();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const paymentOption = document.getElementById('paymentOption');
            const installmentMonths = document.getElementById('installmentMonths');

            if (paymentOption) {
                paymentOption.addEventListener('change', updateReservationComputation);
            }

            if (installmentMonths) {
                installmentMonths.addEventListener('change', updateReservationComputation);
            }

            document.querySelectorAll('.secure-file').forEach(function(input) {
                input.addEventListener('change', function() {
                    validateSecureFileInput(input);
                    refreshReservationReview();
                });
            });

            const reservationForm = document.getElementById('reservationForm');
            if (reservationForm) {
                reservationForm.addEventListener('submit', function(e) {
                    if (!reservationForm.dataset.confirmed) {
                        e.preventDefault();
                        openReservationReview();
                    }
                });
            }

            updateReservationComputation();
            refreshReservationReview();
        });

        function validateStepFields(stepNumber) {
            const panel = document.querySelector('[data-step-panel="' + stepNumber + '"]');
            if (!panel) return true;

            const fields = Array.from(panel.querySelectorAll('input, select, textarea'))
                .filter(el => !el.disabled && el.offsetParent !== null);

            for (const field of fields) {
                if (!field.checkValidity()) {
                    field.reportValidity();
                    field.focus();
                    return false;
                }
            }

            if (stepNumber === 3) {
                const idInput = document.getElementById('validIdFile');
                const proofInput = document.getElementById('paymentProofFile');
                if (idInput && !validateSecureFileInput(idInput)) return false;
                if (proofInput && !validateSecureFileInput(proofInput)) return false;

                const selfieData = document.getElementById('liveSelfieData');
                if (selfieData && !selfieData.value) {
                    alert('Please capture your live selfie verification before continuing.');
                    return false;
                }
            }

            return true;
        }

        function goReservationStep(targetStep) {
            const current = Number(document.querySelector('.reservation-step-panel.active')?.dataset.stepPanel || 1);

            if (targetStep > current) {
                for (let step = current; step < targetStep; step++) {
                    if (!validateStepFields(step)) return;
                }
            }

            document.querySelectorAll('.reservation-step-panel').forEach(panel => {
                panel.classList.toggle('active', Number(panel.dataset.stepPanel) === targetStep);
            });

            document.querySelectorAll('.reservation-step-indicator').forEach(indicator => {
                const step = Number(indicator.dataset.stepIndicator);
                indicator.classList.toggle('active', step === targetStep);
                indicator.classList.toggle('done', step < targetStep);
            });

            refreshReservationReview();
            document.querySelector('.form-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function validateSecureFileInput(input) {
            if (!input || !input.files || !input.files.length) return true;

            const allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
            const allowedMime = ['image/jpeg', 'image/png', 'application/pdf'];
            const maxSize = 5 * 1024 * 1024;
            const file = input.files[0];
            const ext = (file.name.split('.').pop() || '').toLowerCase();

            if (!allowedExtensions.includes(ext) || (file.type && !allowedMime.includes(file.type))) {
                alert('Invalid file type. Please upload JPG, PNG, or PDF only.');
                input.value = '';
                return false;
            }

            if (file.size > maxSize) {
                alert('File is too large. Maximum allowed size is 5MB.');
                input.value = '';
                return false;
            }

            return true;
        }

        function refreshReservationReview() {
            const paymentDisplay = document.getElementById('discountDisplay')?.value || '';
            const finalTcp = document.getElementById('discountedTcpText')?.innerText || '';
            const idFile = document.getElementById('validIdFile')?.files?.length ? 'Yes' : 'No';
            const proofFile = document.getElementById('paymentProofFile')?.files?.length ? 'Yes' : 'No';
            const selfieOk = document.getElementById('liveSelfieData')?.value ? 'Yes' : 'No';

            const idInline = document.getElementById('reviewIdStatus');
            const proofInline = document.getElementById('reviewProofStatus');
            const selfieInline = document.getElementById('reviewSelfieStatus');
            const payInline = document.getElementById('reviewPaymentOptionText');
            const tcpInline = document.getElementById('reviewFinalTcpText');

            if (idInline) idInline.innerText = idFile === 'Yes' ? 'Yes' : 'No file selected';
            if (proofInline) proofInline.innerText = proofFile === 'Yes' ? 'Yes' : 'No file selected';
            if (selfieInline) selfieInline.innerText = selfieOk === 'Yes' ? 'Yes' : 'Not captured';
            if (payInline) payInline.innerText = paymentDisplay;
            if (tcpInline) tcpInline.innerText = finalTcp;

            const modalId = document.getElementById('modalIdStatus');
            const modalProof = document.getElementById('modalProofStatus');
            const modalSelfie = document.getElementById('modalSelfieStatus');
            const modalPay = document.getElementById('modalPaymentOptionText');
            const modalTcp = document.getElementById('modalFinalTcpText');
            const stickyTotal = document.getElementById('stickyTotalText');

            if (modalId) modalId.innerText = idFile;
            if (modalProof) modalProof.innerText = proofFile;
            if (modalSelfie) modalSelfie.innerText = selfieOk;
            if (modalPay) modalPay.innerText = paymentDisplay;
            if (modalTcp) modalTcp.innerText = finalTcp;
            if (stickyTotal) stickyTotal.innerText = finalTcp;
        }

        function openReservationReview() {
            const form = document.getElementById('reservationForm');
            if (!form) return;

            for (let step = 1; step <= 4; step++) {
                if (!validateStepFields(step)) {
                    goReservationStep(step);
                    return;
                }
            }

            const policyVerification = document.getElementById('policyVerification');
            const policyPrivacy = document.getElementById('policyPrivacy');
            if (policyVerification && !policyVerification.checked) {
                goReservationStep(4);
                policyVerification.reportValidity();
                return;
            }
            if (policyPrivacy && !policyPrivacy.checked) {
                goReservationStep(4);
                policyPrivacy.reportValidity();
                return;
            }

            refreshReservationReview();
            document.getElementById('reservationReviewModal')?.classList.add('active');
        }

        function closeReservationReview() {
            document.getElementById('reservationReviewModal')?.classList.remove('active');
        }

        function confirmReservationSubmit() {
            const form = document.getElementById('reservationForm');
            if (!form) return;
            form.dataset.confirmed = '1';
            form.submit();
        }

        function scrollToReservationForm() {
            document.querySelector('.form-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // --- LEAFLET MAP LOGIC ---
        <?php if($has_geo_coordinates): ?>
        var map = L.map('map-display').setView([<?= json_encode($lot_lat) ?>, <?= json_encode($lot_lng) ?>], 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        
        var markerIcon = L.divIcon({
            className: 'custom-div-icon',
            html: "<div style='background-color:var(--danger-red); width:18px; height:18px; border-radius:50%; border:3px solid white; box-shadow:0 0 15px rgba(0,0,0,0.5);'></div>",
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        });
        L.marker([<?= json_encode($lot_lat) ?>, <?= json_encode($lot_lng) ?>], {icon: markerIcon}).addTo(map)
         .bindPopup("<b>Block <?= $lot['block_no'] ?> Lot <?= $lot['lot_no'] ?></b><br>JEJ Top Priority Corporation").openPopup();
        
        setTimeout(() => { map.invalidateSize(); }, 500);
        <?php endif; ?>

        // --- SCHEME MAP LOGIC (ZOOM & PAN & FULLSCREEN) ---
        let mapScale = 1;
        let mapPanX = 0;
        let mapPanY = 0;
        let isPanning = false;
        let startDrag = { x: 0, y: 0 };

        const svgContainer = document.getElementById('svgContainer');
        const schemeMap = document.getElementById('schemeMap');
        const schemeWrapper = document.getElementById('schemeWrapper');
        const fsIcon = document.getElementById('fsIcon');

        window.addEventListener('load', function() {
            const focusedLot = document.querySelector('.lot-focused');
            if (svgContainer && focusedLot) {
                const bbox = focusedLot.getBBox();
                const scaleX = svgContainer.clientWidth / 1464; 
                const scrollTargetX = (bbox.x * scaleX) - (svgContainer.clientWidth / 2);
                if(scrollTargetX > 0) { svgContainer.scrollLeft = scrollTargetX; }
            }
        });

        function setMapTransform() {
            schemeMap.style.transform = `translate(${mapPanX}px, ${mapPanY}px) scale(${mapScale})`;
        }

        function zoomMap(delta) {
            mapScale += delta;
            if(mapScale < 0.5) mapScale = 0.5;
            if(mapScale > 5) mapScale = 5;
            setMapTransform();
        }

        function resetMap() {
            mapScale = 1; mapPanX = 0; mapPanY = 0;
            setMapTransform();
        }

        function toggleMapFullscreen() {
            schemeWrapper.classList.toggle('fullscreen');
            if (schemeWrapper.classList.contains('fullscreen')) {
                fsIcon.classList.remove('fa-expand');
                fsIcon.classList.add('fa-compress');
                document.body.style.overflow = 'hidden';
            } else {
                fsIcon.classList.remove('fa-compress');
                fsIcon.classList.add('fa-expand');
                document.body.style.overflow = 'auto';
            }
            resetMap();
        }

        svgContainer.addEventListener('wheel', function(e) {
            e.preventDefault();
            const delta = e.deltaY > 0 ? -0.1 : 0.1;
            zoomMap(delta);
        });

        svgContainer.addEventListener('mousedown', function(e) {
            e.preventDefault();
            isPanning = true;
            startDrag = { x: e.clientX - mapPanX, y: e.clientY - mapPanY };
            svgContainer.style.cursor = 'grabbing';
        });

        window.addEventListener('mouseup', function() {
            isPanning = false;
            svgContainer.style.cursor = 'grab';
        });

        window.addEventListener('mousemove', function(e) {
            if (!isPanning) return;
            e.preventDefault();
            mapPanX = (e.clientX - startDrag.x);
            mapPanY = (e.clientY - startDrag.y);
            setMapTransform();
        });


        // --- LIGHTBOX LOGIC ---
        const allImages = <?php echo json_encode($js_images); ?>;
        let currentIdx = 0;
        let currentLbZoom = 1;

        function zoomImage(step) {
            currentLbZoom += step;
            if (currentLbZoom < 0.5) currentLbZoom = 0.5; 
            if (currentLbZoom > 4) currentLbZoom = 4;     
            document.getElementById('lightbox-img').style.transform = `scale(${currentLbZoom})`;
        }

        function resetLbZoom() {
            currentLbZoom = 1;
            document.getElementById('lightbox-img').style.transform = `scale(${currentLbZoom})`;
        }

        function openLightbox(src) {
            const index = allImages.indexOf(src);
            if(index !== -1) {
                currentIdx = index;
                resetLbZoom();
                updateLightboxImage();
                document.getElementById('lightbox').style.display = 'flex';
                document.body.style.overflow = 'hidden'; 
            }
        }

        function closeLightbox() {
            document.getElementById('lightbox').style.display = 'none';
            document.body.style.overflow = 'auto'; 
            resetLbZoom();
        }

        function changeSlide(step) {
            currentIdx += step;
            if (currentIdx >= allImages.length) currentIdx = 0;
            if (currentIdx < 0) currentIdx = allImages.length - 1;
            resetLbZoom();
            updateLightboxImage();
        }

        function updateLightboxImage() {
            document.getElementById('lightbox-img').src = allImages[currentIdx];
            document.getElementById('lb-counter').innerText = currentIdx + 1;
        }

        document.addEventListener('keydown', function(e) {
            if(document.getElementById('lightbox').style.display === 'flex') {
                if(e.key === 'ArrowLeft') changeSlide(-1);
                if(e.key === 'ArrowRight') changeSlide(1);
                if(e.key === 'Escape') closeLightbox();
            }
            if(e.key === 'Escape' && schemeWrapper.classList.contains('fullscreen')) {
                toggleMapFullscreen();
            }
        });
        let cameraStream = null;

        async function startCamera(){

            const video = document.getElementById('camera');
            const container = document.getElementById('cameraContainer');
            const startBtn = document.getElementById('startCameraBtn');
            const captureBtn = document.getElementById('captureBtn');
            const retakeBtn = document.getElementById('retakeBtn');
            const status = document.getElementById('selfieStatus');

            function showCameraStatus(message, type = 'warning'){
                if(!status) return;
                status.style.display = 'block';
                if(type === 'success'){
                    status.style.background = '#f0fdf4';
                    status.style.borderColor = '#86efac';
                    status.style.color = '#166534';
                }else if(type === 'error'){
                    status.style.background = '#fef2f2';
                    status.style.borderColor = '#fecaca';
                    status.style.color = '#991b1b';
                }else{
                    status.style.background = '#fffbeb';
                    status.style.borderColor = '#fde047';
                    status.style.color = '#92400e';
                }
                status.innerHTML = message;
            }

            // Camera permission popup only appears in Secure Contexts.
            // Works on https:// and http://localhost. It will NOT work on normal http:// IP/domain.
            if(!window.isSecureContext){
                showCameraStatus(
                    '<i class="fa-solid fa-triangle-exclamation"></i> Camera requires HTTPS or localhost. Use https://jejtoppriority.com or http://localhost only.',
                    'error'
                );
                return;
            }

            if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
                showCameraStatus(
                    '<i class="fa-solid fa-triangle-exclamation"></i> Camera is not supported by this browser. Please use Chrome, Edge, or mobile Chrome.',
                    'error'
                );
                return;
            }

            // If the browser already blocked camera for this site, Chrome may not show popup again.
            // This explains why no permission popup appears.
            try{
                if(navigator.permissions && navigator.permissions.query){
                    const permission = await navigator.permissions.query({name: 'camera'});
                    if(permission.state === 'denied'){
                        showCameraStatus(
                            '<i class="fa-solid fa-triangle-exclamation"></i> Camera is blocked for this site. Click the lock icon beside the URL → Site settings → Camera → Allow, then reload this page.',
                            'error'
                        );
                        return;
                    }
                }
            }catch(e){
                // Some browsers do not support camera permission query. Continue to getUserMedia.
            }

            showCameraStatus('<i class="fa-solid fa-spinner fa-spin"></i> Requesting camera permission...', 'warning');

            // Stop any previous stream first.
            stopCamera();

            const constraintsList = [
                { video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false },
                { video: { facingMode: 'user' }, audio: false },
                { video: true, audio: false }
            ];

            let lastError = null;

            for(const constraints of constraintsList){
                try{
                    const stream = await navigator.mediaDevices.getUserMedia(constraints);

                    cameraStream = stream;
                    video.srcObject = stream;
                    await video.play();

                    container.style.display = 'block';
                    startBtn.style.display = 'none';
                    captureBtn.style.display = 'block';
                    retakeBtn.style.display = 'none';

                    const preview = document.getElementById('selfiePreview');
                    if(preview){
                        preview.style.display = 'none';
                    }

                    showCameraStatus('<i class="fa-solid fa-circle-check"></i> Camera opened. Position your face then click Capture Selfie.', 'success');
                    return;
                }catch(error){
                    lastError = error;
                }
            }

            let message = 'Unable to access camera.';
            if(lastError){
                if(lastError.name === 'NotAllowedError' || lastError.name === 'PermissionDeniedError'){
                    message = 'Camera access denied or blocked. Click the lock icon beside the URL → Site settings → Camera → Allow, then reload.';
                }else if(lastError.name === 'NotFoundError' || lastError.name === 'DevicesNotFoundError'){
                    message = 'No camera device found. Please connect/enable your webcam.';
                }else if(lastError.name === 'NotReadableError' || lastError.name === 'TrackStartError'){
                    message = 'Camera is already being used by another app/browser tab. Close other camera apps and try again.';
                }else if(lastError.name === 'SecurityError'){
                    message = 'Camera is blocked by browser security policy. Check .htaccess Permissions-Policy and allow camera=(self).';
                }else if(lastError.name === 'OverconstrainedError'){
                    message = 'Camera settings are not supported by this device. Try another browser or webcam.';
                }
            }

            showCameraStatus('<i class="fa-solid fa-triangle-exclamation"></i> ' + message, 'error');
            console.log('Camera error:', lastError);
        }

        function stopCamera(){

            if(cameraStream){
                cameraStream.getTracks().forEach(function(track){
                    track.stop();
                });
                cameraStream = null;
            }

            const video = document.getElementById('camera');
            if(video){
                video.srcObject = null;
            }

        }

        function captureSelfie(){

            const video = document.getElementById('camera');
            const canvas = document.getElementById('snapshot');
            const status = document.getElementById('selfieStatus');
            const hiddenInput = document.getElementById('liveSelfieData');
            const container = document.getElementById('cameraContainer');
            const captureBtn = document.getElementById('captureBtn');
            const startBtn = document.getElementById('startCameraBtn');
            const retakeBtn = document.getElementById('retakeBtn');

            if(!cameraStream || !video || video.videoWidth === 0){
                alert('Please click Start Camera first.');
                return;
            }

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;

            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            const imageData = canvas.toDataURL('image/jpeg', 0.90);
            hiddenInput.value = imageData;

            stopCamera();

            if(container){
                container.style.display = 'none';
            }

            let preview = document.getElementById('selfiePreview');

            if(!preview){
                preview = document.createElement('img');
                preview.id = 'selfiePreview';
                preview.style.width = '100%';
                preview.style.borderRadius = '12px';
                preview.style.border = '2px solid #22c55e';
                preview.style.marginTop = '10px';
                preview.style.display = 'block';

                const selfieGroup = hiddenInput.closest('.form-group');
                selfieGroup.insertBefore(preview, status);
            }

            preview.src = imageData;
            preview.style.display = 'block';

            if(status){
                status.style.display = 'block';
                status.innerHTML = '<i class="fa-solid fa-circle-check"></i> Live selfie captured successfully. Camera closed.';
                status.style.background = '#f0fdf4';
                status.style.borderColor = '#86efac';
                status.style.color = '#166534';
            }

            if(captureBtn){
                captureBtn.style.display = 'none';
            }

            if(startBtn){
                startBtn.style.display = 'none';
            }

            if(retakeBtn){
                retakeBtn.style.display = 'block';
            }

            if (typeof refreshReservationReview === 'function') {
                refreshReservationReview();
            }

        }

        function retakeSelfie(){

            const hiddenInput = document.getElementById('liveSelfieData');
            const preview = document.getElementById('selfiePreview');
            const status = document.getElementById('selfieStatus');

            if(hiddenInput){
                hiddenInput.value = '';
            }

            if(preview){
                preview.style.display = 'none';
            }

            if(status){
                status.style.display = 'none';
            }

            if (typeof refreshReservationReview === 'function') {
                refreshReservationReview();
            }

            startCamera();

        }

        window.addEventListener('beforeunload', stopCamera);
    </script>
    
</body>
</html>
<?php ob_end_flush(); ?>