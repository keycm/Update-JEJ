<?php
// master_list.php

require_once 'config.php';

// CASHIER BLOCK
if ($_SESSION['role'] === 'CASHIER') {
    header("Location: financial.php");
    exit();
}

// Role Access
requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER']);

// Manager Permission Access
if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'inv_full');
}

// Sidebar active state
$currentPage = basename($_SERVER['PHP_SELF']);
$financePages = [
    'financial.php',
    'verify_payments.php',
    'payment_tracking.php',
    'transaction_history.php',
    'daily_reconciliation.php',
    'aging_due_report.php',
    'contract_status.php',
    'pricing_matrix.php'
];
$isFinancePage = in_array($currentPage, $financePages);


$alert_msg = "";
$alert_type = "";

// --- 1. FETCH DISTINCT LOCATIONS & MAP THEM ---
$locations = [];
$map_urls = [];
$locSql = "SELECT location, MAX(subdivision_map) AS subdivision_map FROM lots WHERE location IS NOT NULL AND location != '' GROUP BY location ORDER BY location";
$locRes = $conn->query($locSql);

if ($locRes && $locRes->num_rows > 0) {
    while ($row = $locRes->fetch_assoc()) {
        $loc = trim($row['location']);
        $locations[] = $loc;

        $savedMap = trim((string)($row['subdivision_map'] ?? ''));
        if ($savedMap !== '') {
            $map_urls[$loc] = jej_map_image_url($savedMap);
        } else {
            $map_urls[$loc] = jej_map_image_url($loc);
        }
    }
}

// --- 2. HANDLE LOCATION-SPECIFIC MAP UPLOAD ---
if(isset($_POST['upload_map']) && isset($_FILES['map_image']) && !empty($_POST['map_location']) && $_FILES['map_image']['error'] == 0){
    $loc = $_POST['map_location'];
    $safe_loc = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $loc));
    
    $target_dir = rtrim(STORAGE_PATH, '/\\') . '/';
    if(!is_dir($target_dir)) mkdir($target_dir, 0755, true);

    $allowed_ext = ['png', 'jpg', 'jpeg', 'webp'];
    $ext = strtolower(pathinfo($_FILES['map_image']['name'], PATHINFO_EXTENSION));
    if(!in_array($ext, $allowed_ext, true)){
        $alert_msg = "Invalid map image. Please upload PNG, JPG, JPEG, or WEBP only.";
        $alert_type = "error";
    } else {
        $mapPath = $target_dir . "map_{$safe_loc}." . $ext;

        // Delete existing map variations for this specific location to prevent conflicts.
        foreach(['png','jpg','jpeg','webp'] as $oldExt){
            @unlink($target_dir . "map_{$safe_loc}." . $oldExt);
            @unlink($target_dir . "maps/map_{$safe_loc}." . $oldExt);
            @unlink(__DIR__ . "/uploads/map_{$safe_loc}." . $oldExt);
        }

        if(move_uploaded_file($_FILES['map_image']['tmp_name'], $mapPath)){

    $filenameOnly = basename($mapPath);

    $updateMap = $conn->prepare("
        UPDATE lots 
        SET subdivision_map = ? 
        WHERE location = ?
    ");

    if(!$updateMap){
        die("Prepare failed: " . $conn->error);
    }

    $updateMap->bind_param("ss", $filenameOnly, $loc);
    $updateMap->execute();

    $alert_msg = "New Scheme Map for <strong>{$loc}</strong> uploaded successfully!";
    $alert_type = "success";

    $map_urls[$loc] = jej_map_image_url($filenameOnly);

} else {
    $alert_msg = "Failed to upload the map image.";
    $alert_type = "error";
}
}
}


// --- 2.5. DETECT MAP IMAGE DIMENSIONS FOR PERFECT SVG/POLYGON FIT ---
$map_dimensions = [];
foreach ($map_urls as $locKey => $urlPath) {
    $cleanPath = strtok($urlPath, '?');
    if ($cleanPath && file_exists($cleanPath)) {
        $imgSize = @getimagesize($cleanPath);
        if ($imgSize && !empty($imgSize[0]) && !empty($imgSize[1])) {
            $map_dimensions[$locKey] = [
                'w' => (int)$imgSize[0],
                'h' => (int)$imgSize[1]
            ];
        } else {
            $map_dimensions[$locKey] = ['w' => 1464, 'h' => 1052];
        }
    } else {
        $map_dimensions[$locKey] = ['w' => 1464, 'h' => 1052];
    }
}

// --- 3. FETCH ALL LOTS FOR THE TABLE ---
$lots = [];
$statusCounts = [
    'AVAILABLE' => 0,
    'SOLD' => 0,
    'RESERVED' => 0
];

$sql = "SELECT * FROM lots ORDER BY CAST(block_no AS UNSIGNED), CAST(lot_no AS UNSIGNED)";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $lots[] = $row;
        $status = strtoupper($row['status']);
        if (isset($statusCounts[$status])) {
            $statusCounts[$status]++;
        }
    }
}
$totalLots = count($lots);
$availablePercent = $totalLots > 0 ? round(($statusCounts['AVAILABLE'] / $totalLots) * 100) : 0;
$reservedPercent  = $totalLots > 0 ? round(($statusCounts['RESERVED'] / $totalLots) * 100) : 0;
$soldPercent      = $totalLots > 0 ? round(($statusCounts['SOLD'] / $totalLots) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master List & Map | JEJ Admin</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        :root {
            /* NATURE GREEN THEME */
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
        
        /* Main Panel & Header */
        .main-panel { margin-left: 260px; flex: 1; padding: 0; width: calc(100% - 260px); display: flex; flex-direction: column; }
        
        .top-header { display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 20px 40px; border-bottom: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); z-index: 50; }
        .header-title h1 { font-size: 22px; font-weight: 800; color: var(--dark); margin: 0 0 4px 0; letter-spacing: -0.5px;}
        .header-title p { color: var(--text-muted); font-size: 13px; margin: 0; }

        /* PROFILE DROPDOWN - copied from admin.php style */
        .profile-dropdown {
            position: relative;
            cursor: pointer;
            flex-shrink: 0;
        }

        .profile-trigger {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 12px;
            border-radius: 10px;
            transition: background 0.2s, border-color 0.2s;
            border: 1px solid transparent;
        }

        .profile-trigger:hover {
            background: var(--gray-light);
            border-color: var(--gray-border);
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
            box-shadow: 0 2px 4px rgba(46, 125, 50, 0.2);
        }

        .profile-info strong {
            display: block;
            font-size: 13px;
            color: var(--dark);
            line-height: 1.2;
        }

        .profile-info small {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 110%;
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-border);
            min-width: 220px;
            z-index: 1000;
            overflow: hidden;
            transform-origin: top right;
            animation: dropAnim 0.2s ease-out forwards;
        }

        @keyframes dropAnim {
            0% { opacity: 0; transform: scale(0.95) translateY(-10px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }

        .profile-dropdown:hover .dropdown-menu {
            display: block;
        }

        .dropdown-header {
            padding: 15px;
            border-bottom: 1px solid var(--gray-border);
            background: var(--gray-light);
        }

        .dropdown-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #455a64;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.2s, color 0.2s, border-left-color 0.2s;
            border-left: 3px solid transparent;
        }

        .dropdown-item:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .dropdown-item.text-danger {
            color: #d84315;
        }

        .dropdown-item.text-danger:hover {
            background: #fbe9e7;
            color: #bf360c;
            border-left-color: #d84315;
        }


        .content-area { padding: 35px 40px; flex: 1; }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; transition: transform 0.2s;}
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .stat-card span { font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 5px; letter-spacing: 0.5px;}
        .stat-card strong { font-size: 28px; font-weight: 800; color: var(--dark); }
        
        .sc-total { border-top: 4px solid #3b82f6; } 
        .sc-avail { border-top: 4px solid #10b981; } 
        .sc-res   { border-top: 4px solid #f59e0b; } 
        .sc-sold  { border-top: 4px solid #ef4444; } 

        /* Directory / Table Styling */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; background: var(--gray-light); border-bottom: 1px solid var(--gray-border); letter-spacing: 0.5px;}
        td { padding: 16px 24px; border-bottom: 1px solid var(--gray-border); color: #37474f; font-size: 14px; vertical-align: middle; }
        tr:hover td { background: #fdfdfd; }
        
        /* Map UI Styling */
        .map-container { background: white; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); padding: 20px; margin-bottom: 30px; }
        .map-toolbar { display: flex; gap: 15px; margin-bottom: 15px; align-items: center; flex-wrap: wrap; justify-content: space-between; background: #f8fafc; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; }
        .map-toolbar-left { display: flex; gap: 10px; align-items: center; flex: 1; flex-wrap: wrap; }
        .map-toolbar input[type="text"], .map-toolbar select, .map-toolbar button { padding: 10px 15px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 13px; outline: none; transition: 0.2s;}
        .map-toolbar input[type="text"]:focus, .map-toolbar select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.15); }
        .map-toolbar input[type="text"] { min-width: 220px; }
        .map-toolbar button.btn-reset { background: white; color: var(--text-muted); border: 1px solid var(--gray-border); font-weight: 600; cursor: pointer; }
        .map-toolbar button.btn-reset:hover { background: #f1f5f9; color: #1e293b; }
        
        /* Dynamic Upload Form Styling */
        .map-upload-form { display:flex; gap:10px; align-items:center; background: white; padding: 8px 15px; border-radius: 8px; border: 1px dashed #cbd5e1; }
        .upload-labels { display: flex; flex-direction: column; margin-right: 10px; }
        .upload-labels .lbl-title { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .upload-labels .lbl-loc { font-size: 13px; font-weight: 700; color: var(--primary); }
        .map-upload-form input[type="file"] { font-size: 12px; max-width: 190px; padding: 0; border: none; }
        .map-upload-form button { background: #334155; color: white; border: none; padding: 8px 15px; font-size: 12px; border-radius: 6px; cursor: pointer; font-weight: 600; transition: 0.2s; }
        .map-upload-form button:hover:not(:disabled) { background: #0f172a; }
        .map-upload-form button:disabled { opacity: 0.5; cursor: not-allowed; }

        .legend { display: flex; gap: 15px; font-size: 12px; font-weight: 600; color: #455a64; margin-bottom: 15px;}
        .legend span { display: flex; align-items: center; gap: 5px; }
        .legend i { width: 14px; height: 14px; border-radius: 3px; border: 1px solid rgba(0,0,0,0.1); }

        .map-wrapper { width: 100%; overflow: auto; border-radius: 12px; border: 1px solid var(--gray-border); background: #f8fafc; position: relative; min-height: 400px; }
        #schemeMap { width: 100%; min-width: 0; height: auto; display: block; }
        #mainMapImage { pointer-events: none; }
        
        /* The Overlay when "All Branches" is selected */
        #mapOverlay { position: absolute; inset: 0; background: rgba(248, 250, 252, 0.95); backdrop-filter: blur(4px); display: flex; flex-direction: column; justify-content: center; align-items: center; z-index: 50; }
        #mapOverlay i { font-size: 48px; color: #cbd5e1; margin-bottom: 15px; }
        #mapOverlay h3 { margin: 0 0 5px 0; color: #334155; font-size: 20px; font-weight: 800; }
        #mapOverlay p { margin: 0; color: #64748b; font-size: 14px; font-weight: 500; }

        /* Interactive Polygon Styling */
        .lot { stroke: #ffffff; stroke-width: 1.5; cursor: pointer; transition: all 0.3s ease; }
        .lot:hover { stroke: #1e293b; stroke-width: 3; filter: brightness(1.1); }
        .lot.available { fill: rgba(16, 185, 129, 0.6); } 
        .lot.reserved { fill: rgba(245, 158, 11, 0.6); }   
        .lot.sold { fill: rgba(239, 68, 68, 0.85); stroke: #991b1b; } 
        .lot.hidden-by-filter { opacity: 0; pointer-events: none; }
        
        /* Pinpoint Locate Highlight Styling */
        .lot.lot-dimmed { opacity: 0.15 !important; pointer-events: none; }
        .lot.lot-focused { stroke: #3b82f6 !important; stroke-width: 6 !important; animation: pulseLot 1.5s infinite; z-index: 100; }
        @keyframes pulseLot { 0% { filter: drop-shadow(0 0 2px #3b82f6) brightness(1); } 50% { filter: drop-shadow(0 0 15px #3b82f6) brightness(1.5); } 100% { filter: drop-shadow(0 0 2px #3b82f6) brightness(1); } }
        
        /* Badges & Buttons */
        .status-badge { padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; letter-spacing: 0.3px; display: inline-block;}
        .btn-action { padding: 8px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; border: none; transition: 0.2s;}
        .btn-locate { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd;} 
        .btn-locate:hover { background: #bae6fd; color: #0369a1; }
        .btn-edit { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;} 
        .btn-edit:hover { background: #e2e8f0; color: #334155; }
        .btn-full-edit { background: #ffffff; color: #64748b; border: 1px solid #cbd5e1; }
        .btn-full-edit:hover { background: #f8fafc; color: #475569; border-color: #94a3b8; }
        
        /* Modal & Form Styling */
        .modal { display: none; position: fixed; z-index: 9999; inset: 0; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(3px); padding: 30px; overflow-y: auto; }
        .modal-content { max-width: 550px; margin: 5vh auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .modal-header { padding: 20px 25px; border-bottom: 1px solid var(--gray-border); display: flex; justify-content: space-between; align-items: center; background: var(--gray-light); }
        .modal-header h2 { margin: 0; font-size: 18px; font-weight: 700; color: var(--dark); }
        .close-btn { background: none; border: none; font-size: 20px; color: #90a4ae; cursor: pointer; transition: 0.2s;}
        .close-btn:hover { color: #ef4444; transform: scale(1.1);}
        #modalBody { padding: 25px; }
        
        .alert-box { padding: 16px 20px; border-radius: 10px; margin-bottom: 25px; font-weight: 500; font-size: 14px; box-shadow: var(--shadow-sm); }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }

        .menu-dropdown { margin-bottom: 6px; }

.dropdown-toggle{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:0;
}

.dropdown-toggle.active{
    background: var(--primary-light);
    color: var(--primary);
    font-weight: 600;
    border-left: 4px solid var(--primary);
}

.finance-main-link{
    flex:1;
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 0 12px 18px;
    color:inherit;
    text-decoration:none;
    border-radius:10px;
}

.finance-main-link i{
    width:20px;
    text-align:center;
    font-size:16px;
    opacity:.8;
}

.submenu-toggle-btn{
    width:34px;
    height:34px;
    margin-right:10px;
    border:0;
    background:transparent;
    color:inherit;
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
}

.submenu-toggle-btn:hover{
    background:rgba(46,125,50,.10);
}

.dropdown-arrow{
    font-size:12px;
    transition:transform .25s ease;
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

.submenu .submenu-link{
    font-size:13px;
    padding:10px 14px;
    margin-bottom:4px;
    border-left:2px solid #e5e7eb;
    border-radius:8px;
}

.submenu .submenu-link.active{
    background:var(--primary-light);
    color:var(--primary);
    font-weight:700;
    border-left:4px solid var(--primary);
}


/* AUTO FIT + COLLAPSIBLE SIDEBAR + HAMBURGER */
.sidebar,
.main-panel{
    transition: all .25s ease;
}

.top-header-left{
    display:flex;
    align-items:center;
    gap:14px;
    min-width:0;
}

.sidebar-toggle{
    width:42px;
    height:42px;
    border:0;
    border-radius:10px;
    background:var(--primary-light);
    color:var(--primary);
    font-size:18px;
    cursor:pointer;
    flex-shrink:0;
}

.sidebar-toggle:hover{ background:#c8e6c9; }

body.sidebar-collapsed .sidebar{ width:78px; }
body.sidebar-collapsed .main-panel{
    margin-left:78px;
    width:calc(100% - 78px);
}
body.sidebar-collapsed .brand-box{
    justify-content:center;
    padding:25px 10px;
}
body.sidebar-collapsed .brand-box div,
body.sidebar-collapsed .sidebar-menu small,
body.sidebar-collapsed .finance-main-link span,
body.sidebar-collapsed .submenu,
body.sidebar-collapsed .submenu-toggle-btn{
    display:none !important;
}
body.sidebar-collapsed .menu-link{
    justify-content:center;
    padding:14px 10px !important;
    gap:0;
    font-size:0 !important;
    border-left:0 !important;
}
body.sidebar-collapsed .menu-link i{
    font-size:18px !important;
    width:22px;
    margin:0;
}
body.sidebar-collapsed .finance-main-link{
    justify-content:center;
    padding:14px 0 !important;
    gap:0;
}

/* MASTER LIST AUTO FIT */
.location-card{ width:100%; box-sizing:border-box; }
.loc-body > div[style*="overflow-x"]{
    overflow-x:visible !important;
}
.loc-body table{
    width:100% !important;
    min-width:0 !important;
    table-layout:fixed;
}
.loc-body th,
.loc-body td{
    padding:10px 12px !important;
    word-break:break-word;
    overflow-wrap:anywhere;
    font-size:12px;
}
.loc-body th:nth-child(1){width:8%;}
.loc-body th:nth-child(2){width:14%;}
.loc-body th:nth-child(3){width:13%;}
.loc-body th:nth-child(4){width:10%;}
.loc-body th:nth-child(5){width:16%;}
.loc-body th:nth-child(6){width:13%;}
.loc-body th:nth-child(7){width:26%;}
.loc-body td:last-child{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    align-items:center;
}
.loc-body .btn-action{
    padding:7px 9px;
    font-size:11px;
    flex:1 1 auto;
    justify-content:center;
    white-space:normal;
    min-width:84px;
}
.loc-body img{
    max-width:45px;
}

@media(max-width:900px){
    .sidebar{ left:-260px; }
    .main-panel,
    body.sidebar-collapsed .main-panel{
        margin-left:0;
        width:100%;
    }
    body.sidebar-open .sidebar{
        left:0;
        z-index:101;
    }
    body.sidebar-open::after{
        content:"";
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.35);
        z-index:90;
    }
    .top-header{
        padding:15px 18px;
        gap:12px;
    }
    .header-title h1{font-size:18px;}
    .header-title p{display:none;}
    .profile-info{display:none;}
    .content-area{padding:20px;}
    .stats-grid{grid-template-columns:1fr 1fr;}
    .map-toolbar,
    .map-toolbar-left,
    .map-upload-form{
        flex-direction:column;
        align-items:stretch;
        width:100%;
    }
    .map-toolbar input[type="text"],
    .map-toolbar select,
    .map-upload-form input[type="file"],
    .map-upload-form button{
        width:100%;
        max-width:none;
        min-width:0;
        box-sizing:border-box;
    }
    .loc-header{
        padding:14px 16px !important;
        align-items:flex-start !important;
        gap:10px;
    }
    .loc-body table,
    .loc-body thead,
    .loc-body tbody,
    .loc-body th,
    .loc-body td,
    .loc-body tr{
        display:block;
        width:100% !important;
    }
    .loc-body thead{display:none;}
    .loc-body tr{
        background:#fff;
        border:1px solid var(--gray-border);
        border-radius:12px;
        margin:12px;
        padding:10px;
        box-sizing:border-box;
        box-shadow:var(--shadow-sm);
    }
    .loc-body td{
        border-bottom:1px dashed #e2e8f0 !important;
        padding:9px 4px !important;
        display:flex !important;
        justify-content:space-between;
        gap:12px;
        align-items:center;
    }
    .loc-body td:last-child{
        border-bottom:0 !important;
        justify-content:flex-start;
    }
    .loc-body td:nth-child(1)::before{content:"Image";font-weight:800;color:var(--text-muted);}
    .loc-body td:nth-child(2)::before{content:"Property Type";font-weight:800;color:var(--text-muted);}
    .loc-body td:nth-child(3)::before{content:"Block/Lot";font-weight:800;color:var(--text-muted);}
    .loc-body td:nth-child(4)::before{content:"Area";font-weight:800;color:var(--text-muted);}
    .loc-body td:nth-child(5)::before{content:"Price";font-weight:800;color:var(--text-muted);}
    .loc-body td:nth-child(6)::before{content:"Status";font-weight:800;color:var(--text-muted);}
    .loc-body td:nth-child(7)::before{content:"Actions";font-weight:800;color:var(--text-muted);width:100%;}
}

@media(max-width:520px){
    .stats-grid{grid-template-columns:1fr;}
}

/* === PROFESSIONAL MASTER LIST UPGRADES === */
.master-toolbar{
    background:#ffffff;
    border:1px solid var(--gray-border);
    box-shadow:var(--shadow-sm);
    border-radius:16px;
    padding:14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    margin-bottom:22px;
    flex-wrap:wrap;
}
.view-tabs{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.view-tab{
    border:1px solid var(--gray-border);
    background:#fff;
    color:#455a64;
    padding:10px 14px;
    border-radius:10px;
    font-weight:700;
    font-size:13px;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:8px;
    transition:.2s ease;
}
.view-tab:hover,
.view-tab.active{
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
    box-shadow:0 8px 18px rgba(46,125,50,.18);
}
.master-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.export-btn{
    border:1px solid var(--gray-border);
    background:#f8fafc;
    color:#334155;
    padding:10px 13px;
    border-radius:10px;
    font-weight:700;
    font-size:13px;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:8px;
}
.export-btn:hover{
    background:var(--primary-light);
    color:var(--primary);
}
.stat-card{
    position:relative;
    overflow:hidden;
}
.stat-card small{
    margin-top:6px;
    font-size:12px;
    color:#64748b;
    font-weight:700;
}
.stat-progress{
    height:7px;
    background:#e5e7eb;
    border-radius:999px;
    overflow:hidden;
    margin-top:12px;
}
.stat-progress span{
    display:block;
    height:100%;
    border-radius:999px;
}
.sc-total .stat-progress span{background:#3b82f6;}
.sc-avail .stat-progress span{background:#10b981;}
.sc-res .stat-progress span{background:#f59e0b;}
.sc-sold .stat-progress span{background:#ef4444;}
.map-container{
    scroll-margin-top:90px;
}
.map-toolbar{
    position:sticky;
    top:0;
    z-index:30;
}
.map-tools{
    display:flex;
    gap:8px;
    align-items:center;
    margin-left:auto;
}
.map-tool-btn{
    width:38px;
    height:38px;
    border-radius:9px;
    border:1px solid var(--gray-border);
    background:#fff;
    color:#334155;
    cursor:pointer;
    font-weight:800;
}
.map-tool-btn:hover{
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
}
#svgWrapper{
    max-height:70vh;
    overflow:auto;
}
#svgWrapper svg{
    transform-origin: top left;
}
#schemeMap{
    width:100%;
    min-width:0;
    height:auto;
    display:block;
}
body.master-full-mode .sidebar{
    width:78px;
}
body.master-full-mode .main-panel{
    margin-left:78px;
    width:calc(100% - 78px);
}
body.master-full-mode .brand-box{
    justify-content:center;
    padding:25px 10px;
}
body.master-full-mode .brand-box div,
body.master-full-mode .sidebar-menu small,
body.master-full-mode .finance-main-link span,
body.master-full-mode .submenu,
body.master-full-mode .submenu-toggle-btn{
    display:none !important;
}
body.master-full-mode .menu-link{
    justify-content:center;
    padding:14px 10px !important;
    gap:0;
    font-size:0 !important;
    border-left:0 !important;
}
body.master-full-mode .menu-link i{
    font-size:18px !important;
    width:22px;
    margin:0;
}
body.master-full-mode .finance-main-link{
    justify-content:center;
    padding:14px 0 !important;
    gap:0;
}
body.master-full-mode .content-area{
    padding:28px 34px;
}
body.master-full-mode #svgWrapper{
    max-height:78vh;
}
@media(max-width:900px){
    body.master-full-mode .main-panel{
        margin-left:0;
        width:100%;
    }
}

.lot-tooltip{
    position:fixed;
    z-index:10001;
    display:none;
    min-width:210px;
    background:#0f172a;
    color:white;
    border-radius:12px;
    padding:12px 14px;
    box-shadow:0 18px 45px rgba(15,23,42,.28);
    pointer-events:none;
    font-size:12px;
}
.lot-tooltip strong{
    display:block;
    font-size:14px;
    margin-bottom:5px;
    color:#fff;
}
.lot-tooltip span{
    display:block;
    color:#cbd5e1;
    margin-top:2px;
}
.section-hidden{
    display:none !important;
}
.directory-wrapper,
.map-container{
    animation:fadePanel .18s ease;
}
@keyframes fadePanel{
    from{opacity:0; transform:translateY(8px);}
    to{opacity:1; transform:translateY(0);}
}

/* Stable map and directory layout */
#mapSection, #directorySection{
    width:100%;
    box-sizing:border-box;
}
.map-toolbar-left{
    min-width:0;
}
.map-tools{
    margin-left:0;
}
.map-upload-form{
    margin-left:auto;
}
@media(max-width:1200px){
    .map-toolbar{
        align-items:stretch;
    }
    .map-toolbar-left{
        width:100%;
    }
    .map-upload-form{
        margin-left:0;
        width:100%;
        justify-content:space-between;
    }
}

@media print{
    .sidebar,
    .top-header,
    .master-toolbar,
    .map-container,
    .btn-action,
    .profile-dropdown{
        display:none !important;
    }
    .main-panel{
        margin-left:0 !important;
        width:100% !important;
    }
    .content-area{
        padding:0 !important;
    }
    .directory-wrapper{
        display:block !important;
    }
    .loc-body{
        display:block !important;
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

        <a href="master_list.php" class="menu-link active">
            <i class="fa-solid fa-map-location-dot"></i>
            Master List / Map
        </a>

        <a href="admin.php?view=inventory" class="menu-link">
            <i class="fa-solid fa-plus-circle">
            </i> Add Property
        </a>
<!-- FINANCIAL MENU -->
<div class="menu-dropdown">

    <div class="menu-link dropdown-toggle <?= $isFinancePage ? 'active' : '' ?>">

        <a href="financial.php" class="finance-main-link">
            <i class="fa-solid fa-coins"></i>
            <span>Financials</span>
        </a>

        <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)" title="Show/Hide Financial Menu">
            <i class="fa-solid <?= $isFinancePage ? 'fa-chevron-up' : 'fa-chevron-down' ?> dropdown-arrow" id="financeArrow"></i>
        </button>

    </div>

    <div id="financeSubMenu" class="submenu <?= $isFinancePage ? 'show' : '' ?>">

        <a href="verify_payments.php" class="menu-link submenu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-circle-check"></i>
            Verify Payments
        </a>

        <a href="payment_tracking.php" class="menu-link submenu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            Payment Tracking
        </a>

        <a href="transaction_history.php" class="menu-link submenu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-list-ul"></i>
            Ledger List
        </a>

        <a href="daily_reconciliation.php" class="menu-link submenu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-scale-balanced"></i>
            Daily Reconciliation
        </a>

        <a href="aging_due_report.php" class="menu-link submenu-link <?= $currentPage == 'aging_due_report.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock"></i>
            Aging / Due Report
        </a>

        <a href="contract_status.php" class="menu-link submenu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-file-signature"></i>
            Contract Status
        </a>

        <a href="manual_buyer_entry.php"
        class="menu-link submenu-link <?= $currentPage == 'manual_buyer_entry.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-user-plus"></i>
                Manual Buyer Entry
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
            <a href="agent_tracking.php" class="menu-link <?= $currentPage == 'agent_tracking.php' ? 'active' : '' ?>"><i class="fa-solid fa-user-tie"></i> Agent Tracking</a>
            <a href="inquiries.php" class="menu-link"><i class="fa-solid fa-envelope-open-text"></i> Inquiries</a>
            <a href="accounts.php" class="menu-link"><i class="fa-solid fa-users-gear"></i> Accounts</a>
            <a href="delete_history.php" class="menu-link"><i class="fa-solid fa-trash-can"></i> Delete History</a>
            
            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">SYSTEM</small>
            <a href="index.php" class="menu-link" target="_blank"><i class="fa-solid fa-globe"></i> View Website</a>

</div>
        </div>
    </div>

    <div class="main-panel">
        
        <div class="top-header">
            <div class="top-header-left">
                <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="header-title">
                    <h1>Master List & Scheme Map</h1>
                    <p>Interactive subdivision map and complete property inventory.</p>
                </div>
            </div>
            
            <?php include 'includes/profile_dropdown.php'; ?>
        </div>

        <div class="content-area">

            <?php if($alert_msg): ?>
                <div class="alert-box <?= $alert_type == 'success' ? 'alert-success' : 'alert-error' ?>">
                    <i class="fa-solid <?= $alert_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>" style="margin-right: 8px;"></i>
                    <?= $alert_msg ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card sc-total">
                    <span>Total Lots</span>
                    <strong><?= $totalLots ?></strong>
                    <small>100% inventory records</small>
                    <div class="stat-progress"><span style="width:100%"></span></div>
                </div>
                <div class="stat-card sc-avail">
                    <span>Available</span>
                    <strong><?= $statusCounts['AVAILABLE'] ?></strong>
                    <small><?= $availablePercent ?>% ready for selling</small>
                    <div class="stat-progress"><span style="width:<?= $availablePercent ?>%"></span></div>
                </div>
                <div class="stat-card sc-res">
                    <span>Reserved</span>
                    <strong><?= $statusCounts['RESERVED'] ?></strong>
                    <small><?= $reservedPercent ?>% active accounts</small>
                    <div class="stat-progress"><span style="width:<?= $reservedPercent ?>%"></span></div>
                </div>
                <div class="stat-card sc-sold">
                    <span>Sold</span>
                    <strong><?= $statusCounts['SOLD'] ?></strong>
                    <small><?= $soldPercent ?>% closed sales</small>
                    <div class="stat-progress"><span style="width:<?= $soldPercent ?>%"></span></div>
                </div>
            </div>

            <div class="master-toolbar">
                <div class="view-tabs">
                    <button type="button" class="view-tab active" id="tabMap" onclick="switchMasterView('map')">
                        <i class="fa-solid fa-map-location-dot"></i> Map View
                    </button>
                    <button type="button" class="view-tab" id="tabDirectory" onclick="switchMasterView('directory')">
                        <i class="fa-solid fa-list-ul"></i> Directory View
                    </button>
                    <button type="button" class="view-tab" id="tabAll" onclick="switchMasterView('all')">
                        <i class="fa-solid fa-table-cells-large"></i> Full View
                    </button>
                </div>

                <div class="master-actions">
                    <button type="button" class="export-btn" onclick="exportMasterCSV()">
                        <i class="fa-solid fa-file-csv"></i> Export CSV
                    </button>
                    <button type="button" class="export-btn" onclick="printMasterList()">
                        <i class="fa-solid fa-print"></i> Print Master List
                    </button>
                </div>
            </div>

            <div class="map-container" id="mapSection">
                <div class="map-toolbar">
                    <div class="map-toolbar-left">
                        <div style="display:flex; align-items:center; background: #e2e8f0; border-radius: 8px; padding: 4px; border: 1px solid #cbd5e1;">
                            <span style="font-size: 11px; font-weight: 800; color: #475569; padding: 0 10px; text-transform: uppercase;">1. Set Active Map:</span>
                            <select id="filterLocation" style="border: none; box-shadow: none; font-weight: 600; color: var(--dark); min-width: 180px;">
                                <option value="">-- View All Lots (Directory) --</option>
                                <?php foreach($locations as $loc): ?>
                                    <option value="<?= htmlspecialchars($loc) ?>"><?= htmlspecialchars($loc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <i class="fa-solid fa-filter" style="color: #90a4ae; margin-left: 10px;"></i>
                        <input type="text" id="searchLot" placeholder="Search Block or Lot...">
                        <select id="filterStatus">
                            <option value="">All Statuses</option>
                            <option value="available">Available</option>
                            <option value="reserved">Reserved</option>
                            <option value="sold">Sold</option>
                        </select>
                        <button type="button" class="btn-reset" onclick="resetFilters()"><i class="fa-solid fa-rotate-right"></i></button>
                        <div class="map-tools">
                            <button type="button" class="map-tool-btn" onclick="zoomMap(0.1)" title="Zoom In"><i class="fa-solid fa-plus"></i></button>
                            <button type="button" class="map-tool-btn" onclick="zoomMap(-0.1)" title="Zoom Out"><i class="fa-solid fa-minus"></i></button>
                            <button type="button" class="map-tool-btn" onclick="resetMapZoom()" title="Reset Zoom"><i class="fa-solid fa-expand"></i></button>
                        </div>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="map-upload-form" id="mapUploadForm">
                        <div class="upload-labels">
                            <span class="lbl-title">2. Update Background Map</span>
                            <span class="lbl-loc" id="uploadLocationText">Select Location First</span>
                        </div>
                        <input type="hidden" name="map_location" id="uploadLocationInput" value="">
                        <input type="file" name="map_image" id="mapFileInput" accept="image/*" required>
                        <button type="submit" name="upload_map" id="uploadMapBtn" disabled><i class="fa-solid fa-upload"></i> Upload</button>
                    </form>
                </div>

                <div class="legend">
                    <span><i style="background: rgba(16, 185, 129, 0.8);"></i> Available</span>
                    <span><i style="background: rgba(245, 158, 11, 0.8);"></i> Reserved</span>
                    <span><i style="background: rgba(239, 68, 68, 0.9);"></i> Sold</span>
                </div>

                <div class="map-wrapper" id="svgWrapper">
                    
                    <div id="mapOverlay">
                        <i class="fa-solid fa-map"></i>
                        <h3>Map View Inactive</h3>
                        <p>Please select a specific <strong>Municipality / Branch</strong> from the dropdown above to load its interactive map.</p>
                    </div>

                    <svg id="schemeMap" viewBox="0 0 1464 1052" preserveAspectRatio="xMinYMin meet">
                        <image id="mainMapImage" href="assets/map.png" x="0" y="0" width="1464" height="1052"></image>

                        <?php foreach ($lots as $lot): ?>
                            <?php
                                $statusClass = strtolower($lot['status']);
                                $dataBlock = htmlspecialchars($lot['block_no']);
                                $dataLot = htmlspecialchars($lot['lot_no']);
                                $dataStatus = htmlspecialchars($lot['status']);
                                $dataLocation = htmlspecialchars($lot['location'] ?? '');
                                $dataId = (int)$lot['id'];
                                $overviewRaw = (string)($lot['property_overview'] ?? '');
                                $classification = (stripos($overviewRaw, 'Front Lot') !== false) ? 'Front Lot' : 'Inner Lot';
                                $points = isset($lot['coordinates']) ? htmlspecialchars($lot['coordinates']) : ''; 
                            ?>
                            <?php if(!empty($points)): ?>
                            <polygon
                                class="lot <?= $statusClass ?>"
                                points="<?= $points ?>"
                                data-id="<?= $dataId ?>"
                                data-block="<?= $dataBlock ?>"
                                data-lot="<?= $dataLot ?>"
                                data-status="<?= $dataStatus ?>"
                                data-location="<?= $dataLocation ?>"
                                data-area="<?= htmlspecialchars($lot['area'] ?? '') ?>"
                                data-price="<?= number_format($lot['total_price'] ?? 0) ?>"
                                data-classification="<?= htmlspecialchars($classification) ?>"
                                onclick="openLotDetails(<?= $dataId ?>, '<?= addslashes($dataLocation) ?>')"
                            >
                                <title>Block <?= $dataBlock ?> - Lot <?= $dataLot ?> | <?= htmlspecialchars($classification) ?> | <?= htmlspecialchars($lot['location'] ?? 'N/A') ?> - <?= $dataStatus ?></title>
                            </polygon>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </svg>
                </div>
            </div>

            <div class="directory-wrapper" id="directorySection" style="margin-bottom: 30px;">
                <div style="background: white; border-radius: 12px; margin-bottom: 20px; padding: 20px 24px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm);">
                    <span style="font-size: 16px; font-weight: 700; color: var(--dark);"><i class="fa-solid fa-list-ul" style="color: var(--primary); margin-right: 8px;"></i> Master List Directory (Click a Municipality to Expand)</span>
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
                
                // Sort keys alphabetically
                ksort($groupedLots);

                foreach($groupedLots as $locName => $locLots): 
                    $availLots = array_filter($locLots, function($l) { return strtoupper($l['status']) === 'AVAILABLE'; });
                    $locId = md5($locName);
                ?>
                
                <div class="location-card" data-locname="<?= htmlspecialchars($locName) ?>" style="background: white; border-radius: 12px; margin-bottom: 15px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); overflow: hidden;">
                    
                    <div class="loc-header" onclick="toggleDirectory('<?= $locId ?>')" style="padding: 18px 24px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; transition: background 0.2s;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 40px; height: 40px; background: #e0f2fe; color: #0284c7; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                                <i class="fa-solid fa-map-location"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;"><?= htmlspecialchars($locName) ?></h3>
                                <span style="font-size: 12px; color: #64748b; font-weight: 600;"><?= count($availLots) ?> Available out of <?= count($locLots) ?> Total Lots</span>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-down dir-icon" id="icon-<?= $locId ?>" style="color: #64748b; transition: transform 0.3s;"></i>
                    </div>
                    
                    <div class="loc-body" id="body-<?= $locId ?>" style="display: none; border-top: 1px solid var(--gray-border);">
                        
                        <div style="padding: 20px 24px; background: #fcfdfc; border-bottom: 1px solid var(--gray-border);">
                            <h4 style="margin: 0 0 12px 0; font-size: 13px; color: #2e7d32; text-transform: uppercase; font-weight: 800;"><i class="fa-solid fa-check-circle"></i> Available Lots in <?= htmlspecialchars($locName) ?></h4>
                            <?php if(count($availLots) > 0): ?>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px;">
                                    <?php $counter=1; foreach($availLots as $al): ?>
                                        <div style="background: white; border: 1px solid #c8e6c9; padding: 10px 15px; border-radius: 8px; font-size: 13px; color: #37474f; display: flex; justify-content: space-between; align-items: center;">
                                            <span><strong><?= $counter++ ?>.)</strong> Lot <?= htmlspecialchars($al['lot_no']) ?> — <?= number_format($al['area'], 0) ?> sqm</span>
                                            <span style="width: 8px; height: 8px; background: #10b981; border-radius: 50%; display: inline-block; box-shadow: 0 0 4px #10b981;"></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p style="font-size: 13px; color: #94a3b8; margin: 0;">No available lots currently listed for this area.</p>
                            <?php endif; ?>
                        </div>

                        <div style="overflow-x: auto;">
                            <table style="width: 100%; min-width: 800px;">
                                <thead>
                                    <tr>
                                        <th>Image</th>
                                        <th>Property Type</th>
                                        <th>Block/Lot</th>
                                        <th>Area</th>
                                        <th>Price</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($locLots as $lot): ?>
                                    <tr class="lot-row" data-block="<?= htmlspecialchars(strtolower($lot['block_no'] ?? '')) ?>" data-lot="<?= htmlspecialchars(strtolower($lot['lot_no'] ?? '')) ?>" data-status="<?= htmlspecialchars(strtolower($lot['status'] ?? '')) ?>" data-location="<?= htmlspecialchars($lot['location'] ?? '') ?>">
                                       <td>
                                        <?php
                                        $lotImage = trim((string)($lot['lot_image'] ?? 'default_lot.jpg'));
                                        $imgUrl = jej_lot_image_url($lotImage);
                                        ?>
                                        <img src="<?= htmlspecialchars($imgUrl) ?>"
                                            alt=""
                                            loading="lazy"
                                            style="width:45px;height:45px;object-fit:cover;border-radius:8px;border:1px solid var(--gray-border);display:block;">
                                        </td>
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
                                            <button type="button" class="btn-action btn-locate" onclick="locateLot(<?= $lot['id'] ?>, '<?= htmlspecialchars(addslashes($lot['location'])) ?>')"><i class="fa-solid fa-location-dot"></i> Locate</button>
                                            <button type="button" class="btn-action btn-edit" onclick="openLotDetails(<?= $lot['id'] ?>, '<?= htmlspecialchars(addslashes($lot['location'])) ?>')"><i class="fa-solid fa-pen"></i> Quick Edit</button>
                                            <a href="admin.php?view=inventory&edit_id=<?= $lot['id'] ?>" class="btn-action btn-full-edit"><i class="fa-solid fa-gear"></i> Full Edit</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>

    <div id="lotTooltip" class="lot-tooltip"></div>

    <div id="lotModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Quick Edit Property</h2>
                <button class="close-btn" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="modalBody">
                <p>Loading...</p>
            </div>
        </div>
    </div>

    <script>
        // Data populated from PHP containing { 'Location Name': 'uploads/map_location.jpg' }
        const mapUrls = <?= json_encode($map_urls) ?>;
        const mapDimensions = <?= json_encode($map_dimensions) ?>;

        const modal = document.getElementById('lotModal');
        const modalBody = document.getElementById('modalBody');

        // NEW: Toggle Accordion Directories
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

        // --- 1. SEARCH, FILTER & DYNAMIC MAP SWITCHING LOGIC ---
        function setMapCanvasForLocation(locationValue) {
            const svg = document.getElementById('schemeMap');
            const img = document.getElementById('mainMapImage');
            if (!svg || !img) return;

            /*
               IMPORTANT:
               The saved lot polygon points in the database are already converted
               to this fixed map coordinate system: 1464 x 1052.
               Do NOT change the SVG viewBox to the natural JPG size, otherwise
               polygons will stretch/shift and will not fit the background map.
            */
            const w = 1464;
            const h = 1052;

            defaultViewBox = { x: 0, y: 0, w: w, h: h };
            currentViewBox = { ...defaultViewBox };
            mapZoomLevel = 1;

            svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
            svg.setAttribute('preserveAspectRatio', 'xMinYMin meet');
            img.setAttribute('x', '0');
            img.setAttribute('y', '0');
            img.setAttribute('width', String(w));
            img.setAttribute('height', String(h));
            applySvgViewBox();
        }

        function applyFilters() {
            const searchValue = document.getElementById('searchLot').value.trim().toLowerCase();
            const statusValue = document.getElementById('filterStatus').value.trim().toLowerCase();
            const locationSelect = document.getElementById('filterLocation');
            const locationValue = locationSelect.value; // Exact Case String (e.g. "San Miguel")
            
            // UI Elements
            const mapOverlay = document.getElementById('mapOverlay');
            const mainMapImage = document.getElementById('mainMapImage');
            const uploadBtn = document.getElementById('uploadMapBtn');
            const uploadFormText = document.getElementById('uploadLocationText');
            const uploadInput = document.getElementById('uploadLocationInput');

            // Handle the Map Graphic & Upload Form
            if (locationValue === '') {
                setMapCanvasForLocation('');
                // "View All" Selected -> Show Overlay, lock upload form, hide all polygons
                mapOverlay.style.display = 'flex';
                uploadBtn.disabled = true;
                uploadFormText.innerText = "Select Location First";
                uploadFormText.style.color = "#94a3b8";
                uploadInput.value = "";
            } else {
                setMapCanvasForLocation(locationValue);
                // Specific Location Selected -> Hide overlay, load correct image, unlock upload form
                mapOverlay.style.display = 'none';
                uploadBtn.disabled = false;
                uploadFormText.innerText = locationValue;
                uploadFormText.style.color = "var(--primary)";
                uploadInput.value = locationValue;

                // Set new map image (add timestamp to bypass browser cache if just uploaded)
                if(mapUrls[locationValue]) {
                    mainMapImage.setAttribute('href', mapUrls[locationValue] + (mapUrls[locationValue].includes('?') ? '&v=' : '?v=') + new Date().getTime());
                } else {
                    mainMapImage.setAttribute('href', 'assets/map.png');
                }
            }

            // Sync the Directory Accordion to the Map Filter Dropdown
            document.querySelectorAll('.location-card').forEach(card => {
                const locName = card.dataset.locname;
                const body = card.querySelector('.loc-body');
                const icon = card.querySelector('.dir-icon');
                
                if(locationValue !== '') {
                    // Map selected: hide other cities, force expand the active city
                    if(locName === locationValue) {
                        card.style.display = 'block';
                        body.style.display = 'block';
                        icon.style.transform = 'rotate(180deg)';
                    } else {
                        card.style.display = 'none';
                    }
                } else {
                    // View all selected: show all city headers again
                    card.style.display = 'block';
                }
            });

            // Filter Map Polygons
            document.querySelectorAll('polygon.lot').forEach(lot => {
                const block = (lot.dataset.block || '').toLowerCase();
                const lotNo = (lot.dataset.lot || '').toLowerCase();
                const status = (lot.dataset.status || '').toLowerCase();
                const loc = (lot.dataset.location || '');

                const matchesSearch = searchValue === '' || block.includes(searchValue) || lotNo.includes(searchValue) || (`b-${block} l-${lotNo}`).includes(searchValue);
                const matchesStatus = statusValue === '' || status === statusValue;
                
                // IMPORTANT: Polygon must EXACTLY match the selected map location.
                const matchesLocation = (locationValue !== '' && loc === locationValue);

                if (matchesSearch && matchesStatus && matchesLocation) {
                    if(!lot.classList.contains('lot-dimmed')) lot.classList.remove('hidden-by-filter');
                } else {
                    lot.classList.add('hidden-by-filter');
                }
            });

            // Filter Table Rows
            document.querySelectorAll('.lot-row').forEach(row => {
                const block = row.dataset.block;
                const lotNo = row.dataset.lot;
                const status = row.dataset.status;
                const loc = row.dataset.location;

                const matchesSearch = searchValue === '' || block.includes(searchValue) || lotNo.includes(searchValue) || (`b-${block} l-${lotNo}`).includes(searchValue);
                const matchesStatus = statusValue === '' || status === statusValue;
                
                // Table shows everything naturally, only hides via search/status
                const matchesLocation = locationValue === '' || loc === locationValue;

                if (matchesSearch && matchesStatus && matchesLocation) row.style.display = '';
                else row.style.display = 'none';
            });
        }

        function resetFilters() {
            document.getElementById('searchLot').value = '';
            document.getElementById('filterStatus').value = '';
            // We do NOT reset the location dropdown here so the user doesn't lose their map context
            restoreMapVisibility(); 
        }

        // Event Listeners for Filters
        document.getElementById('searchLot').addEventListener('input', applyFilters);
        document.getElementById('filterStatus').addEventListener('change', applyFilters);
        document.getElementById('filterLocation').addEventListener('change', applyFilters);


        // --- 2. PINPOINT/LOCATE LOT ON MAP ---
function locateLot(id, locationName) {
    const locSelect = document.getElementById('filterLocation');

    if (locSelect.value !== locationName) {
        locSelect.value = locationName;
    }

    applyFilters();
    switchMasterView('map');

    document.getElementById('mapSection').scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });

    let focusedPolygon = null;

    document.querySelectorAll('polygon.lot').forEach(lot => {
        const sameLot = parseInt(lot.dataset.id) === parseInt(id);
        const sameLocation = lot.dataset.location === locationName;

        lot.classList.remove('lot-focused', 'lot-dimmed');

        if (sameLot && sameLocation) {
            lot.classList.remove('hidden-by-filter');
            lot.classList.add('lot-focused');
            focusedPolygon = lot;
        } else {
            lot.classList.add('hidden-by-filter');
        }
    });

    setTimeout(() => {
        if (focusedPolygon) {
            zoomToPolygon(focusedPolygon);
        } else {
            alert('This lot has no saved map pin/coordinates yet. Please Quick Edit > Pin on Map first.');
        }
    }, 250);

    let resetBtn = document.getElementById('clearFocusBtn');

    if (!resetBtn) {
        resetBtn = document.createElement('button');
        resetBtn.id = 'clearFocusBtn';
        resetBtn.type = 'button';
        resetBtn.innerHTML = '<i class="fa-solid fa-eye-slash"></i> Clear Map Focus';
        resetBtn.style.cssText = "background:#ef4444;color:white;border:none;padding:10px 15px;border-radius:8px;font-weight:600;cursor:pointer;";
        resetBtn.onclick = function() {
            restoreMapVisibility();
        };

        document.querySelector('.map-tools').appendChild(resetBtn);
    }
}

// --- 3. OPEN MODAL & ISOLATE POLYGON ---
function openLotDetails(id, locationName) {
    if (isDrawing) return;

    locateLot(id, locationName);

    modal.style.display = 'block';
    modalBody.innerHTML = '<p style="text-align:center; color:#64748b; padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading data...</p>';

    fetch('get_lot.php?id=' + encodeURIComponent(id))
        .then(response => response.text())
        .then(html => {
            modalBody.innerHTML = html;
        })
        .catch(() => {
            modalBody.innerHTML = '<p style="color:#ef4444; text-align:center; padding:20px;">Failed to load data.</p>';
        });
}

function closeModal() {
    modal.style.display = 'none';
    restoreMapVisibility();
}

function restoreMapVisibility() {
    document.querySelectorAll('polygon.lot').forEach(lot => {
        lot.classList.remove('lot-focused');
        lot.classList.remove('lot-dimmed');
        lot.classList.remove('hidden-by-filter');
    });

    let resetBtn = document.getElementById('clearFocusBtn');
    if (resetBtn) resetBtn.remove();

    applyFilters();
    resetMapZoom(false);
}

        window.onclick = function(event) { if (event.target === modal) closeModal(); };


        // --- 4. AJAX FORM SUBMISSION ---
        function saveLot(event) {
            event.preventDefault(); 
            const form = document.getElementById('lotForm');
            const formData = new FormData(form);
            
            let saveResult = document.getElementById('saveResult');
            if (!saveResult) {
                saveResult = document.createElement('div');
                saveResult.id = 'saveResult';
                saveResult.style.marginTop = '15px';
                saveResult.style.textAlign = 'center';
                form.appendChild(saveResult);
            }

            saveResult.innerHTML = '<p style="color:#3b82f6; font-size:14px; font-weight:600;"><i class="fa-solid fa-spinner fa-spin"></i> Saving changes...</p>';

            fetch('save_lot.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    saveResult.innerHTML = '<p style="color:#10b981; font-weight:600; font-size:14px;"><i class="fa-solid fa-check-circle"></i> ' + data.message + '</p>';
                    setTimeout(() => { location.reload(); }, 800);
                } else {
                    saveResult.innerHTML = '<p style="color:#ef4444; font-weight:600; font-size:14px;"><i class="fa-solid fa-circle-exclamation"></i> ' + data.message + '</p>';
                }
            })
            .catch(() => {
                saveResult.innerHTML = '<p style="color:#ef4444; font-weight:600; font-size:14px;"><i class="fa-solid fa-circle-exclamation"></i> Server communication error.</p>';
            });
        }


        // --- 5. INTERACTIVE MAP PINNING TOOL ---
        let isDrawing = false;
        let tempPoints = [];
        let tempPolygon = null;

        function startDrawing() {
            modal.style.display = 'none';

const activeLocation = document.getElementById('filterLocation').value;

document.querySelectorAll('polygon.lot').forEach(lot => {
    lot.classList.remove('lot-focused', 'lot-dimmed');

    if (lot.dataset.location === activeLocation) {
        lot.classList.remove('hidden-by-filter');
    } else {
        lot.classList.add('hidden-by-filter');
    }
});

isDrawing = true;
tempPoints = [];
            
            let banner = document.getElementById('drawBanner');
            if(!banner) {
                banner = document.createElement('div');
                banner.id = 'drawBanner';
                // UPDATED BANNER HTML TO INCLUDE THE UNDO BUTTON
                banner.innerHTML = `
                    <div style="display:flex; align-items:center; gap:15px;">
                        <span><i class="fa-solid fa-pen-ruler"></i> <strong>Map Pin Mode:</strong> Click the corners of the lot on the map to draw its shape.</span>
                        <button onclick="undoLastPoint()" style="background: #f59e0b; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size:13px; transition: 0.2s;" title="Remove the last point clicked"><i class="fa-solid fa-rotate-left"></i> Undo</button>
                        <button onclick="finishDrawing()" style="background: #10b981; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size:13px; transition: 0.2s;"><i class="fa-solid fa-check"></i> Done</button> 
                        <button onclick="cancelDrawing()" style="background: #ef4444; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size:13px; transition: 0.2s;"><i class="fa-solid fa-xmark"></i> Cancel</button>
                    </div>
                `;
                banner.style.cssText = "position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #1e293b; color: white; padding: 15px 25px; border-radius: 12px; z-index: 10000; box-shadow: 0 10px 25px rgba(0,0,0,0.3); font-size: 14px;";
                document.body.appendChild(banner);
            }
            banner.style.display = 'block';

            const svg = document.getElementById('schemeMap');
            tempPolygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
            tempPolygon.setAttribute('fill', 'rgba(245, 158, 11, 0.6)'); 
            tempPolygon.setAttribute('stroke', '#d97706');
            tempPolygon.setAttribute('stroke-width', '4');
            svg.appendChild(tempPolygon);
            
            document.getElementById('svgWrapper').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        document.getElementById('schemeMap').addEventListener('click', function(e) {
            if(!isDrawing) return;
            const svg = document.getElementById('schemeMap');
            let pt = svg.createSVGPoint();
            pt.x = e.clientX;
            pt.y = e.clientY;
            let svgP = pt.matrixTransform(svg.getScreenCTM().inverse());
            let x = Math.round(svgP.x * 10) / 10;
            let y = Math.round(svgP.y * 10) / 10;
            
            // Allow unlimited points to be gathered and formatted
            tempPoints.push(`${x},${y}`);
            tempPolygon.setAttribute('points', tempPoints.join(' '));
        });

        // NEW FUNCTION: Removes the last plotted coordinate
        function undoLastPoint() {
            if (!isDrawing) return;
            if (tempPoints.length > 0) {
                tempPoints.pop(); // Remove the last coordinate from the array
                if (tempPolygon) {
                    tempPolygon.setAttribute('points', tempPoints.join(' ')); // Redraw polygon without the last point
                }
            }
        }

        function finishDrawing() {
            isDrawing = false;
            document.getElementById('drawBanner').style.display = 'none';
            modal.style.display = 'block'; 
            if(tempPoints.length >= 3) {
                let pointsInput = document.getElementById('polygonPoints');
                if(pointsInput) pointsInput.value = tempPoints.join(' ');
            } else {
                alert("Please click at least 3 points on the map to create a valid shape.");
            }
            if(tempPolygon) tempPolygon.remove();
        }

        function cancelDrawing() {
            isDrawing = false;
            document.getElementById('drawBanner').style.display = 'none';
            modal.style.display = 'block';
            if(tempPolygon) tempPolygon.remove();
        }


        // --- PROFESSIONAL VIEW MODE, EXPORT, TOOLTIP & ZOOM ---
        let defaultViewBox = { x: 0, y: 0, w: 1464, h: 1052 };
        let currentViewBox = { ...defaultViewBox };
        let mapZoomLevel = 1;

        function setViewTab(activeId){
            document.querySelectorAll('.view-tab').forEach(btn => btn.classList.remove('active'));
            const active = document.getElementById(activeId);
            if(active) active.classList.add('active');
        }

        function applySvgViewBox(){
            const svg = document.getElementById('schemeMap');
            if(!svg) return;
            svg.setAttribute('viewBox', `${currentViewBox.x} ${currentViewBox.y} ${currentViewBox.w} ${currentViewBox.h}`);
            const wrapper = document.getElementById('svgWrapper');
            if(wrapper){
                wrapper.scrollLeft = 0;
                wrapper.scrollTop = 0;
            }
        }

        function switchMasterView(mode){
            const mapSection = document.getElementById('mapSection');
            const directorySection = document.getElementById('directorySection');

            document.body.classList.remove('master-full-mode');

            if(mode === 'map'){
                mapSection.classList.remove('section-hidden');
                directorySection.classList.add('section-hidden');
                setViewTab('tabMap');
                if(window.innerWidth > 900){
                    document.body.classList.remove('sidebar-collapsed');
                }
            }else if(mode === 'directory'){
                mapSection.classList.add('section-hidden');
                directorySection.classList.remove('section-hidden');
                setViewTab('tabDirectory');
                if(window.innerWidth > 900){
                    document.body.classList.remove('sidebar-collapsed');
                }
            }else{
                mapSection.classList.remove('section-hidden');
                directorySection.classList.remove('section-hidden');
                setViewTab('tabAll');
                if(window.innerWidth > 900){
                    document.body.classList.add('master-full-mode');
                    document.body.classList.add('sidebar-collapsed');
                }
                resetMapZoom(false);
            }
        }

        function zoomMap(step){
            const nextZoom = Math.max(0.7, Math.min(4, mapZoomLevel + step));
            if(nextZoom === mapZoomLevel) return;

            const centerX = currentViewBox.x + currentViewBox.w / 2;
            const centerY = currentViewBox.y + currentViewBox.h / 2;

            mapZoomLevel = nextZoom;
            currentViewBox.w = defaultViewBox.w / mapZoomLevel;
            currentViewBox.h = defaultViewBox.h / mapZoomLevel;
            currentViewBox.x = Math.max(0, Math.min(defaultViewBox.w - currentViewBox.w, centerX - currentViewBox.w / 2));
            currentViewBox.y = Math.max(0, Math.min(defaultViewBox.h - currentViewBox.h, centerY - currentViewBox.h / 2));

            applySvgViewBox();
        }

        function resetMapZoom(scrollToMap = true){
            mapZoomLevel = 1;
            currentViewBox = { ...defaultViewBox };
            applySvgViewBox();
            const wrapper = document.getElementById('svgWrapper');
            if(wrapper){
                wrapper.scrollLeft = 0;
                wrapper.scrollTop = 0;
            }
            if(scrollToMap){
                document.getElementById('mapSection').scrollIntoView({ behavior:'smooth', block:'start' });
            }
        }

        function zoomToPolygon(polygon){
            if(!polygon || typeof polygon.getBBox !== 'function') return;
            try{
                const box = polygon.getBBox();
                const pad = 90;
                currentViewBox.x = Math.max(0, box.x - pad);
                currentViewBox.y = Math.max(0, box.y - pad);
                currentViewBox.w = Math.min(defaultViewBox.w - currentViewBox.x, box.width + pad * 2);
                currentViewBox.h = Math.min(defaultViewBox.h - currentViewBox.y, box.height + pad * 2);
                mapZoomLevel = Math.min(defaultViewBox.w / currentViewBox.w, defaultViewBox.h / currentViewBox.h);
                applySvgViewBox();
            }catch(e){}
        }

        function exportMasterCSV(){
            const rows = [];
            rows.push(['Location','Property Type','Block','Lot','Area','Price','Status']);

            document.querySelectorAll('.lot-row').forEach(row => {
                if(row.style.display === 'none') return;

                const cells = row.querySelectorAll('td');
                const loc = row.dataset.location || '';
                const type = cells[1]?.innerText.trim() || '';
                const blockLot = cells[2]?.innerText.trim() || '';
                const area = cells[3]?.innerText.trim() || '';
                const price = cells[4]?.innerText.trim().replace('₱','PHP ') || '';
                const status = cells[5]?.innerText.trim() || '';

                rows.push([loc,type,blockLot,'',area,price,status]);
            });

            const csv = rows.map(r => r.map(v => '"' + String(v).replaceAll('"','""') + '"').join(',')).join('\n');
            const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'JEJ_Master_List_' + new Date().toISOString().slice(0,10) + '.csv';
            link.click();
            URL.revokeObjectURL(link.href);
        }

        function printMasterList(){
            switchMasterView('directory');
            setTimeout(() => window.print(), 250);
        }

        function initLotTooltips(){
            const tooltip = document.getElementById('lotTooltip');
            if(!tooltip) return;

            document.querySelectorAll('polygon.lot').forEach(lot => {
                lot.addEventListener('mousemove', function(e){
                    tooltip.style.display = 'block';
                    tooltip.style.left = (e.clientX + 14) + 'px';
                    tooltip.style.top = (e.clientY + 14) + 'px';
                    tooltip.innerHTML = `
                        <strong>Block ${this.dataset.block} - Lot ${this.dataset.lot}</strong>
                        <span>Status: ${this.dataset.status}</span>
                        <span>Classification: ${this.dataset.classification || 'Inner Lot'}</span>
                        <span>Area: ${this.dataset.area} sqm</span>
                        <span>Price: ₱${this.dataset.price}</span>
                    `;
                });

                lot.addEventListener('mouseleave', function(){
                    tooltip.style.display = 'none';
                });
            });
        }


        // --- 6. INITIALIZE STATE ON LOAD ---
        window.addEventListener('DOMContentLoaded', () => {
            const locSelect = document.getElementById('filterLocation');
            // Auto-select the first available location map so the map area isn't blank
            if(locSelect.options.length > 1) {
                locSelect.selectedIndex = 1; 
            }
            applyFilters();
            initLotTooltips();
            switchMasterView('map');
        });
    </script>
    
<script>
function toggleSidebar(){
    if(window.innerWidth <= 900){
        document.body.classList.toggle('sidebar-open');
    }else{
        document.body.classList.toggle('sidebar-collapsed');
    }
}

document.addEventListener('click', function(e){
    if(window.innerWidth <= 900 && document.body.classList.contains('sidebar-open')){
        const sidebar = document.querySelector('.sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        if(sidebar && !sidebar.contains(e.target) && toggle && !toggle.contains(e.target)){
            document.body.classList.remove('sidebar-open');
        }
    }
});
</script>

<script>
function toggleFinanceMenu(event){
    if(event){
        event.preventDefault();
        event.stopPropagation();
    }

    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');

    submenu.classList.toggle('show');

    if(submenu.classList.contains('show')){
        arrow.classList.remove('fa-chevron-down');
        arrow.classList.add('fa-chevron-up');
    } else {
        arrow.classList.remove('fa-chevron-up');
        arrow.classList.add('fa-chevron-down');
    }
}
</script>
</body>
</html> 
