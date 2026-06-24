<?php
// contract_status.php
require_once 'config.php';
requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER', 'CASHIER']);

if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'fin_process');
}

$alert_msg = '';
$alert_type = '';

// Safety checks for required columns
$has_contract_status_col = false;
$colCheck = $conn->query("SHOW COLUMNS FROM reservations LIKE 'contract_status'");
if ($colCheck && $colCheck->num_rows > 0) $has_contract_status_col = true;

if (!$has_contract_status_col) {
    $conn->query("ALTER TABLE reservations ADD contract_status ENUM('NOT UPLOADED','UPLOADED','SIGNED','RE-UPLOADED','ARCHIVED') DEFAULT 'NOT UPLOADED'");
}

// Upload / Re-upload Contract
if (isset($_POST['upload_contract'])) {
    $res_id = (int)($_POST['res_id'] ?? 0);

    if ($res_id <= 0) {
        $alert_msg = 'Invalid reservation selected.';
        $alert_type = 'error';
    } elseif (!isset($_FILES['contract_file']) || $_FILES['contract_file']['error'] !== UPLOAD_ERR_OK) {
        $alert_msg = 'Please choose a valid contract file.';
        $alert_type = 'error';
    } else {
        $file = $_FILES['contract_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];

        if (!in_array($ext, $allowed, true)) {
            $alert_msg = 'Invalid file type. Allowed files: PDF, JPG, JPEG, PNG.';
            $alert_type = 'error';
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $alert_msg = 'Contract file is too large. Maximum size is 10MB.';
            $alert_type = 'error';
        } else {
            $check = $conn->prepare("SELECT contract_file FROM reservations WHERE id = ?");
            $check->bind_param('i', $res_id);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();

            if (!$existing) {
                $alert_msg = 'Reservation not found.';
                $alert_type = 'error';
            } else {
                $upload = jej_storage_upload($file, 'contracts', 'contract_' . $res_id, 10);
                $path = $upload['success'] ? $upload['filename'] : '';

                if ($upload['success']) {
                    $status = !empty($existing['contract_file']) ? 'RE-UPLOADED' : 'UPLOADED';

                    $stmt = $conn->prepare("\n                        UPDATE reservations\n                        SET contract_file = ?,\n                            contract_status = ?,\n                            contract_uploaded_at = NOW()\n                        WHERE id = ?\n                    ");
                    $stmt->bind_param('ssi', $path, $status, $res_id);

                    if ($stmt->execute()) {
                        $alert_msg = $status === 'RE-UPLOADED' ? 'Contract re-uploaded successfully.' : 'Contract uploaded successfully.';
                        $alert_type = 'success';
                    } else {
                        $alert_msg = 'Failed to save contract record.';
                        $alert_type = 'error';
                    }
                } else {
                    $alert_msg = 'Failed to upload contract file.';
                    $alert_type = 'error';
                }
            }
        }
    }
}

// Mark as Signed
if (isset($_POST['mark_signed'])) {
    $id = (int)($_POST['signed_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE reservations SET contract_status = 'SIGNED' WHERE id = ? AND contract_file IS NOT NULL AND contract_file <> ''");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $alert_msg = $stmt->affected_rows > 0 ? 'Contract marked as signed.' : 'Upload a contract first before marking as signed.';
    $alert_type = $stmt->affected_rows > 0 ? 'success' : 'error';
}

// Archive Contract
if (isset($_POST['archive_contract'])) {
    $id = (int)($_POST['archive_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE reservations SET contract_status = 'ARCHIVED' WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $alert_msg = 'Contract archived successfully.';
    $alert_type = 'success';
}

$query = "\n    SELECT\n        r.id,\n        r.account_number,\n        r.contract_file,\n        COALESCE(r.contract_status, 'NOT UPLOADED') AS contract_status,\n        r.contract_uploaded_at,\n        r.status AS reservation_status,\n        u.fullname,\n        u.email AS user_email,\n        l.block_no,\n        l.lot_no\n    FROM reservations r\n    JOIN users u ON r.user_id = u.id\n    JOIN lots l ON r.lot_id = l.id\n    WHERE r.status = 'APPROVED'\n    ORDER BY r.id DESC\n";
$res = $conn->query($query);

function contractBadge($status) {
    $status = strtoupper(trim($status ?: 'NOT UPLOADED'));
    if ($status === 'SIGNED') return '<span class="contract-badge signed">SIGNED</span>';
    if ($status === 'UPLOADED') return '<span class="contract-badge uploaded">UPLOADED</span>';
    if ($status === 'RE-UPLOADED') return '<span class="contract-badge reuploaded">RE-UPLOADED</span>';
    if ($status === 'ARCHIVED') return '<span class="contract-badge archived">ARCHIVED</span>';
    return '<span class="contract-badge not-uploaded">NOT UPLOADED</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract Status | JEJ Financials</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #2e7d32; --primary-light: #e8f5e9; --dark: #1b5e20; 
            --gray-light: #f1f8e9; --gray-border: #c8e6c9; --text-muted: #607d8b; 
        }
        body { background-color: #fafcf9; display: flex; min-height: 100vh; overflow-x: hidden; font-family: 'Inter', sans-serif; color: #37474f; margin: 0; }
        .sidebar { width: 260px; background: #ffffff; border-right: 1px solid #c8e6c9; display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08); }
        .brand-box { padding: 25px; border-bottom: 1px solid #c8e6c9; display: flex; align-items: center; gap: 12px; }
        .sidebar-menu { padding: 20px 15px; flex: 1; overflow-y: auto; }
        .menu-link { display: flex; align-items: center; gap: 12px; padding: 12px 18px; color: #455a64; text-decoration: none; font-weight: 500; font-size: 14px; border-radius: 10px; margin-bottom: 6px; transition: all 0.2s ease; }
        .menu-link:hover { background: #f1f8e9; color: #2e7d32; }
        .menu-link.active { background: #e8f5e9; color: #2e7d32; font-weight: 600; border-left: 4px solid #2e7d32; }
        .menu-link i { width: 20px; text-align: center; font-size: 16px; opacity: 0.8; }
        
        .main-panel { margin-left: 260px; flex: 1; padding: 0; width: calc(100% - 260px); display: flex; flex-direction: column; }
        .top-header { display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 20px 40px; border-bottom: 1px solid #c8e6c9; box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08); z-index: 500; }
        .header-title h1 { font-size: 22px; font-weight: 800; color: #1b5e20; margin: 0 0 4px 0;}
        .header-title p { color: #607d8b; font-size: 13px; margin: 0; }
        
        .content-area { padding: 35px 40px; flex: 1; }
        .alert-banner { padding: 16px 20px; border-radius: 10px; margin-bottom: 25px; font-weight: 500; font-size: 14px; box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08); display: flex; align-items: center; gap: 12px;}
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .urgent-banner { background: #fef2f2; border-left: 5px solid #ef4444; padding: 20px; border-radius: 8px; margin-bottom: 30px; display: flex; align-items: flex-start; gap: 15px; }

        .table-container { background: white; border-radius: 16px; border: 1px solid #c8e6c9; box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08); overflow: hidden; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 16px 20px; font-size: 12px; font-weight: 600; color: #607d8b; text-transform: uppercase; background: #f1f8e9; border-bottom: 1px solid #c8e6c9;}
        td { padding: 16px 20px; border-bottom: 1px solid #c8e6c9; color: #37474f; font-size: 13px; vertical-align: top; }
        
        .badge { padding: 5px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; display: inline-block;}
        .badge-full { background: #d1fae5; color: #059669; border: 1px solid #a7f3d0; } 
        .badge-partial { background: #dbeafe; color: #2563eb; border: 1px solid #bfdbfe; }
        .badge-none { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; } 

        .btn-action { padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; transition: 0.2s; border: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; width: 100%; box-sizing: border-box;}
        .btn-record { background: #10b981; color: white; } .btn-record:hover { background: #059669; }
        .btn-remind { background: #ef4444; color: white; } .btn-remind:hover { background: #dc2626; }
        .btn-billing { background: #3b82f6; color: white; } .btn-billing:hover { background: #2563eb; }
        .btn-edit-terms { background: #f59e0b; color: white; } .btn-edit-terms:hover { background: #d97706; }

        .modal { display: none; position: fixed; z-index: 9999; inset: 0; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(3px); padding: 30px; overflow-y: auto; align-items: center; justify-content: center;}
        .modal-content { width: 100%; max-width: 450px; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .modal-header { padding: 20px 25px; border-bottom: 1px solid #c8e6c9; display: flex; justify-content: space-between; align-items: center; background: #f1f8e9; }
        .close-btn { background: none; border: none; font-size: 20px; color: #90a4ae; cursor: pointer; }
        .close-btn:hover { color: #ef4444; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #455a64; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 14px; outline: none; box-sizing: border-box;}

        .menu-dropdown{
    margin-bottom:6px;
}

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
    color:inherit;
    text-decoration:none;
}

.submenu-toggle-btn{
    border:none;
    background:none;
    cursor:pointer;
    width:28px;
    height:28px;
    border-radius:6px;
    color:#2e7d32;
}

.submenu-toggle-btn:hover{
    background:#dff2e1;
}

.submenu{
    display:none !important;
    padding-left:18px;
    margin-top:6px;
}

.submenu.show{
    display:block !important;
}

.submenu-link{
    font-size:13px;
    margin-bottom:6px;
}

.submenu-link.active{
    background:#e8f5e9;
    color:#2e7d32;
    font-weight:700;
    border-left:4px solid #2e7d32;
}
        
    

        /* LANDSCAPE RECORD PAYMENT MODAL */
        .payment-modal-content{
            max-width: 820px !important;
            width: 92vw !important;
            max-height: 88vh;
            overflow: hidden;
        }

        .payment-modal-body{
            padding: 22px 25px;
            max-height: calc(88vh - 78px);
            overflow-y: auto;
        }

        .payment-form-grid{
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 18px;
            align-items: start;
        }

        .payment-form-grid .full-row{
            grid-column: 1 / -1;
        }

        .payment-note{
            background:#fffbeb;
            border:1px solid #fde68a;
            color:#92400e;
            padding:12px;
            border-radius:8px;
            font-size:12px;
            line-height:1.5;
            height:100%;
        }

        .payment-modal-actions{
            grid-column:1 / -1;
            display:flex;
            justify-content:flex-end;
            gap:10px;
            margin-top: 8px;
            padding-top: 16px;
            border-top: 1px solid #c8e6c9;
        }

        .btn-cancel-modal{
            background:#f1f5f9;
            border:1px solid #cbd5e1;
            padding:10px 20px;
            border-radius:8px;
            cursor:pointer;
        }

        .btn-save-modal{
            background:#10b981;
            color:white;
            border:none;
            padding:10px 24px;
            border-radius:8px;
            font-weight:600;
            cursor:pointer;
        }

        @media(max-width: 768px){
            .payment-modal-content{
                max-width: 96vw !important;
            }
            .payment-form-grid{
                grid-template-columns: 1fr;
            }
        }


        /* ANALYTICS CARDS */
        .analytics-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
            gap:18px;
            margin-bottom:30px;
        }

        .analytics-card{
            background:#ffffff;
            border:1px solid #c8e6c9;
            border-radius:16px;
            padding:18px;
            display:flex;
            align-items:center;
            gap:14px;
            box-shadow:0 2px 8px rgba(46,125,50,0.08);
        }

        .analytics-card small{
            display:block;
            color:#607d8b;
            font-size:12px;
            margin-bottom:5px;
            font-weight:700;
            text-transform:uppercase;
        }

        .analytics-card h3{
            margin:0;
            font-size:20px;
            color:#1b5e20;
            font-weight:800;
        }

        .analytics-icon{
            width:50px;
            height:50px;
            min-width:50px;
            border-radius:14px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:20px;
            color:white;
        }


        .analytics-icon.sales{
    background:linear-gradient(135deg,#10b981,#059669);
}

.analytics-icon.collected{
    background:linear-gradient(135deg,#3b82f6,#2563eb);
}

.analytics-icon.pending{
    background:linear-gradient(135deg,#f59e0b,#d97706);
}

.analytics-icon.balance{
    background:linear-gradient(135deg,#ef4444,#dc2626);
}

.analytics-icon.sold{
    background:linear-gradient(135deg,#8b5cf6,#7c3aed);
}

.analytics-icon.available{
    background:linear-gradient(135deg,#14b8a6,#0f766e);
}

.mini-summary-grid{
    display:grid;
    grid-template-columns:repeat(3, minmax(180px, 1fr));
    gap:16px;
    margin-bottom:24px;
}

.mini-card{
    background:white;
    border:1px solid #c8e6c9;
    border-radius:14px;
    padding:16px 18px;
    display:flex;
    align-items:center;
    gap:14px;
    box-shadow:0 2px 8px rgba(46,125,50,.08);
}

.mini-card i{
    width:42px;
    height:42px;
    border-radius:12px;
    background:#e8f5e9;
    color:#2e7d32;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
}

.mini-card small{
    display:block;
    font-size:12px;
    color:#607d8b;
    font-weight:700;
    text-transform:uppercase;
}

.mini-card strong{
    font-size:22px;
    color:#1b5e20;
}

.mini-card.danger i{
    background:#fee2e2;
    color:#dc2626;
}

.mini-card.warning i{
    background:#fef3c7;
    color:#d97706;
}

/* SEARCH FILTERS */
.tracking-filters{
    display:grid;
    grid-template-columns:2fr 1fr 1fr 1fr auto;
    gap:12px;
    padding:18px;
    border-bottom:1px solid #c8e6c9;
    background:#ffffff;
}

.filter-group{
    position:relative;
}

.search-group i{
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:#90a4ae;
    font-size:13px;
}

.filter-group input,
.filter-group select{
    width:100%;
    height:44px;
    border:1px solid #d7e7d4;
    border-radius:10px;
    padding:0 14px;
    font-size:13px;
    background:#fff;
    outline:none;
    transition:.2s ease;
    box-sizing:border-box;
}

.search-group input{
    padding-left:38px;
}

.filter-group input:focus,
.filter-group select:focus{
    border-color:#2e7d32;
    box-shadow:0 0 0 3px rgba(46,125,50,.12);
}

.filter-reset-btn{
    height:44px;
    border:1px solid #c8e6c9;
    background:#f1f8e9;
    color:#2e7d32;
    border-radius:10px;
    padding:0 16px;
    font-weight:700;
    cursor:pointer;
}

.filter-reset-btn:hover{
    background:#e8f5e9;
}

.no-filter-result{
    text-align:center;
    padding:30px;
    color:#94a3b8;
    font-weight:600;
    display:none;
}

@media(max-width:991px){
    .tracking-filters{
        grid-template-columns:1fr;
    }
}
    
    .contract-badge{padding:6px 11px;border-radius:999px;font-size:10px;font-weight:800;display:inline-block;}
    .contract-badge.signed{background:#d1fae5;color:#047857;border:1px solid #a7f3d0;}
    .contract-badge.uploaded{background:#dbeafe;color:#1d4ed8;border:1px solid #bfdbfe;}
    .contract-badge.reuploaded{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;}
    .contract-badge.archived{background:#e2e8f0;color:#334155;border:1px solid #cbd5e1;}
    .contract-badge.not-uploaded{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;}
    .contract-actions{display:grid;grid-template-columns:1fr;gap:8px;max-width:240px;}
    .contract-upload{display:flex;gap:8px;align-items:center;}
    .contract-upload input[type=file]{font-size:11px;width:130px;}
    .btn-signed{background:#16a34a;color:#fff;}
    .btn-archive{background:#64748b;color:#fff;}
    .btn-view{background:#3b82f6;color:#fff;}
    .btn-upload{background:#10b981;color:#fff;}
    .muted{color:#64748b;font-size:12px;}

/* AUTO FIT + WORKING COLLAPSIBLE SIDEBAR */
.sidebar,
.main-panel{
    transition: all .25s ease;
}

.top-header-left{
    display:flex;
    align-items:center;
    gap:12px;
    min-width:0;
}

.sidebar-toggle{
    width:42px;
    height:42px;
    border:none;
    border-radius:10px;
    background:#e8f5e9;
    color:#2e7d32;
    font-size:18px;
    cursor:pointer;
    flex-shrink:0;
}

.sidebar-toggle:hover{ background:#c8e6c9; }

.sidebar .menu-text{ display:inline; }

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
body.sidebar-collapsed .menu-text,
body.sidebar-collapsed .finance-main-link span,
body.sidebar-collapsed .submenu,
body.sidebar-collapsed .submenu-toggle-btn{
    display:none !important;
}
body.sidebar-collapsed .menu-link{
    justify-content:center;
    padding:14px 10px;
    gap:0;
    border-left:0 !important;
}
body.sidebar-collapsed .menu-link i{
    width:22px;
    font-size:18px;
}
body.sidebar-collapsed .finance-main-link{
    justify-content:center;
    gap:0;
}

/* CONTRACT STATUS AUTO FIT */
.content-area{
    max-width:100%;
    box-sizing:border-box;
}
.table-container{
    width:100%;
    overflow-x:auto;
}
.table-container table{
    width:100%;
    min-width:980px;
    table-layout:fixed;
}
.table-container th,
.table-container td{
    padding:14px 16px;
    word-break:break-word;
    overflow-wrap:anywhere;
}
.contract-actions{
    max-width:100%;
}
.contract-upload{
    display:grid;
    grid-template-columns:1fr;
    gap:8px;
}
.contract-upload input[type=file]{
    width:100%;
    max-width:100%;
    box-sizing:border-box;
}
.btn-action{
    white-space:normal;
    line-height:1.25;
}

@media(max-width:1200px){
    .content-area{ padding:24px; }
    .table-container table{ min-width:900px; }
}

.header-right{
    margin-left:auto;
    display:flex;
    align-items:center;
}

/* PROFILE DROPDOWN - CLEAN STYLE SAME AS VERIFY PAYMENTS */
.profile-dropdown{
    position:relative;
    cursor:pointer;
    flex-shrink:0;
}

.profile-trigger{
    display:flex;
    align-items:center;
    gap:12px;
    padding:6px 10px;
    border-radius:10px;
    border:1px solid transparent;
    background:transparent;
    cursor:pointer;
    transition:.2s ease;
    min-width:auto;
}

.profile-trigger:hover{
    background:#f1f8e9;
    border-color:#c8e6c9;
}

.profile-avatar{
    width:42px;
    height:42px;
    border-radius:50%;
    background:#2e7d32;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
    font-size:16px;
    box-shadow:0 6px 14px rgba(46,125,50,.18);
    flex-shrink:0;
}

.profile-info strong{
    display:block;
    font-size:14px;
    line-height:1.15;
    color:#1b5e20;
    font-weight:800;
    white-space:nowrap;
}

.profile-info small{
    display:block;
    margin-top:3px;
    color:#607d8b;
    font-size:12px;
    font-weight:500;
    white-space:nowrap;
}

.profile-trigger > i{
    font-size:12px;
    color:#607d8b;
    margin-left:2px;
}

.dropdown-menu{
    position:absolute;
    top:115%;
    right:0;
    width:240px;
    background:#fff;
    border:1px solid #c8e6c9;
    border-radius:14px;
    box-shadow:0 10px 25px rgba(0,0,0,.12);
    display:none;
    overflow:hidden;
    z-index:9999;
}

.profile-dropdown:hover .dropdown-menu{
    display:block;
}

.dropdown-header{
    padding:15px 16px;
    background:#f1f8e9;
    border-bottom:1px solid #c8e6c9;
}

.dropdown-header strong{
    display:block;
    font-size:13px;
    color:#1b5e20;
    font-weight:800;
}

.dropdown-header span{
    display:block;
    margin-top:5px;
    font-size:11px;
    color:#607d8b;
}

.dropdown-item{
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 16px;
    text-decoration:none;
    color:#37474f;
    font-size:13px;
    font-weight:500;
    border-left:3px solid transparent;
}

.dropdown-item i{
    width:16px;
    text-align:center;
    font-size:13px;
}

.dropdown-item:hover{
    background:#f1f8e9;
    color:#2e7d32;
    border-left-color:#2e7d32;
}

.dropdown-item.text-danger{
    color:#ea580c;
}

.dropdown-item.text-danger:hover{
    background:#fff7ed;
    color:#c2410c;
    border-left-color:#ea580c;
}

@media(max-width:768px){
    .sidebar{
        left:-260px;
        position:fixed;
        width:260px;
        height:100vh;
    }
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
    .header-title h1{ font-size:18px; }
    .header-title p{ display:none; }
    .content-area{ padding:18px; }

    .table-container{
        background:transparent;
        border:0;
        box-shadow:none;
        overflow:visible;
    }
    .table-container table,
    .table-container thead,
    .table-container tbody,
    .table-container th,
    .table-container td,
    .table-container tr{
        display:block;
        width:100%;
        min-width:0;
        box-sizing:border-box;
    }
    .table-container thead{ display:none; }
    .table-container tbody tr{
        background:#fff;
        border:1px solid #c8e6c9;
        border-radius:16px;
        margin-bottom:16px;
        overflow:hidden;
        box-shadow:0 1px 2px rgba(46,125,50,.08);
    }
    .table-container tbody tr td{
        border-bottom:1px solid #e2e8f0;
        padding:14px 16px;
    }
    .table-container tbody tr td:last-child{ border-bottom:0; }
    .table-container tbody tr td::before{
        content:attr(data-label);
        display:block;
        font-size:11px;
        font-weight:800;
        color:#607d8b;
        text-transform:uppercase;
        margin-bottom:7px;
        letter-spacing:.4px;
    }
}

    </style>
</head>
<body>
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$financePages = ['financial.php','payment_tracking.php','daily_reconciliation.php','verify_payments.php','transaction_history.php','aging_due_report.php','contract_status.php','pricing_matrix.php'];
$isFinancePage = in_array($currentPage, $financePages);
?>

<div class="sidebar">
    <div class="brand-box">
        <img src="assets/logo1.png" style="height: 38px; width: auto; border-radius: 8px;">
        <div style="line-height: 1.1;">
            <span style="font-size: 16px; font-weight: 800; color: #2e7d32; display: block;">JEJ Top Priority Corporation</span>
            <span style="font-size: 11px; color: #607d8b; font-weight: 500;">Management Portal</span>
        </div>
    </div>

    <div class="sidebar-menu">

        

        <?php if($_SESSION['role'] !== 'CASHIER'): ?>
            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-bottom: 12px;">MAIN MENU</small>
            <a href="admin.php?view=dashboard" class="menu-link">
                <i class="fa-solid fa-chart-pie"></i> <span class="menu-text">Dashboard</span>
            </a>

            <a href="reservation.php" class="menu-link">
                <i class="fa-solid fa-file-signature"></i> <span class="menu-text">Reservations</span>
            </a>

            <a href="master_list.php" class="menu-link">
                <i class="fa-solid fa-map-location-dot"></i> <span class="menu-text">Master List / Map</span>
            </a>

            <a href="admin.php?view=inventory" class="menu-link">
                <i class="fa-solid fa-plus-circle"></i> <span class="menu-text">Add Property</span>
            </a>
        <?php endif; ?>

        <div class="menu-dropdown">

            <div class="menu-link dropdown-toggle <?= $isFinancePage ? 'active' : '' ?>">
                <a href="financial.php" class="finance-main-link">
                    <i class="fa-solid fa-coins"></i>
                    <span>Financials</span>
                </a>

                <button type="button"
                        class="submenu-toggle-btn"
                        onclick="toggleFinanceMenu(event)"
                        title="Show/Hide Financial Menu">
                    <i class="fa-solid <?= $isFinancePage ? 'fa-chevron-up' : 'fa-chevron-down' ?>" id="financeArrow"></i>
                </button>
            </div>

            <div id="financeSubMenu" class="submenu <?= $isFinancePage ? 'show' : '' ?>">

                <a href="verify_payments.php"
                   class="menu-link submenu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-circle-check"></i>
                    <span class="menu-text">Verify Payments</span>
                </a>

                <a href="payment_tracking.php"
                   class="menu-link submenu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    <span class="menu-text">Payment Tracking</span>
                </a>

                <a href="transaction_history.php"
                   class="menu-link submenu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-list-ul"></i>
                    <span class="menu-text">Ledger List</span>
                </a>

                <a href="daily_reconciliation.php"
                   class="menu-link submenu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-scale-balanced"></i>
                    <span class="menu-text">Daily Reconciliation</span>
                </a>

                <a href="aging_due_report.php" class="menu-link submenu-link <?= $currentPage == 'aging_due_report.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-clock"></i> <span class="menu-text">Aging / Due Report</span>
                </a>

                <a href="contract_status.php"
                    class="menu-link submenu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-file-signature"></i>
                        <span class="menu-text">Contract Status</span>
                </a>

                <a href="manual_buyer_entry.php"
        class="menu-link submenu-link <?= $currentPage == 'manual_buyer_entry.php' ? 'active' : '' ?>">
            <i class="fa-solid fa-user-plus"></i>
                <span class="menu-text">Manual Buyer Entry</span>
        </a>

                <a href="pricing_matrix.php"
                   class="menu-link submenu-link <?= $currentPage == 'pricing_matrix.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-table-list"></i>
                    <span class="menu-text">Pricing Matrix</span>
                </a>
            </div>
        </div>

        <?php if($_SESSION['role'] !== 'CASHIER'): ?>
            

            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px;">SYSTEM</small>

            <a href="index.php" class="menu-link" target="_blank">
                <i class="fa-solid fa-globe"></i> <span class="menu-text">View Website</span>
            </a>
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
            <h1>Contract Status</h1>
            <p>Track Not Uploaded, Uploaded, Signed, Re-uploaded, and Archived contracts.</p>
        </div>
    </div>

    <?php include 'includes/profile_dropdown.php'; ?>

</div>

    <div class="content-area">
        <?php if($alert_msg): ?>
            <div class="alert-banner <?= $alert_type=='success' ? 'alert-success' : 'alert-error' ?>">
                <i class="fa-solid <?= $alert_type=='success'?'fa-check-circle':'fa-exclamation-circle' ?>"></i>
                <?= $alert_msg ?>
            </div>
        <?php endif; ?>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Buyer / Property</th>
                        <th>Account No.</th>
                        <th>Contract Status</th>
                        <th>Uploaded At</th>
                        <th>Contract File</th>
                        <th style="width:260px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if($res && $res->num_rows > 0): ?>
                    <?php while($row = $res->fetch_assoc()): ?>
                        <tr>
                            <td data-label="Buyer / Property">
                                <strong style="color:#1e293b;"><?= htmlspecialchars($row['fullname']) ?></strong><br>
                                <span class="muted"><?= htmlspecialchars($row['user_email']) ?></span><br>
                                <span class="muted">Block <?= htmlspecialchars($row['block_no']) ?> Lot <?= htmlspecialchars($row['lot_no']) ?></span>
                            </td>
                            <td data-label="Account No.">
                                <?= !empty($row['account_number']) ? '<strong style="color:#2563eb;">'.htmlspecialchars($row['account_number']).'</strong>' : '<span class="muted">No account no.</span>' ?>
                            </td>
                            <td data-label="Contract Status"><?= contractBadge($row['contract_status']) ?></td>
                            <td data-label="Uploaded At">
                                <?= !empty($row['contract_uploaded_at']) ? date('M d, Y h:i A', strtotime($row['contract_uploaded_at'])) : '<span class="muted">—</span>' ?>
                            </td>
                            <td data-label="Contract File">
                                <?php if(!empty($row['contract_file'])): ?>
                                    <a href="<?= htmlspecialchars(jej_file_url('contracts', $row['contract_file'])) ?>" target="_blank" class="btn-action btn-view">
                                        <i class="fa-solid fa-eye"></i> View Contract
                                    </a>
                                <?php else: ?>
                                    <span class="muted">No file uploaded</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Action">
                                <div class="contract-actions">
                                    <form method="POST" enctype="multipart/form-data" class="contract-upload">
                                        <input type="hidden" name="res_id" value="<?= (int)$row['id'] ?>">
                                        <input type="file" name="contract_file" accept=".pdf,.jpg,.jpeg,.png" required>
                                        <button type="submit" name="upload_contract" class="btn-action btn-upload">
                                            <i class="fa-solid fa-upload"></i> Upload
                                        </button>
                                    </form>

                                    <?php if(!empty($row['contract_file']) && $row['contract_status'] !== 'ARCHIVED'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="signed_id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" name="mark_signed" class="btn-action btn-signed">
                                                <i class="fa-solid fa-file-circle-check"></i> Mark Signed
                                            </button>
                                        </form>

                                        <form method="POST" onsubmit="return confirm('Archive this contract?');">
                                            <input type="hidden" name="archive_id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" name="archive_contract" class="btn-action btn-archive">
                                                <i class="fa-solid fa-box-archive"></i> Archive
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">No approved reservations found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>

function toggleSidebar(){
    if(window.innerWidth <= 768){
        document.body.classList.toggle('sidebar-open');
    } else {
        document.body.classList.toggle('sidebar-collapsed');
    }
}

document.addEventListener('click', function(e){
    if(window.innerWidth <= 768 &&
       document.body.classList.contains('sidebar-open') &&
       !e.target.closest('.sidebar') &&
       !e.target.closest('.sidebar-toggle')){
        document.body.classList.remove('sidebar-open');
    }
});

function toggleFinanceMenu(event){
    event.preventDefault();
    event.stopPropagation();
    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');
    submenu.classList.toggle('show');
    arrow.classList.toggle('fa-chevron-down');
    arrow.classList.toggle('fa-chevron-up');
}
</script>
</body>
</html>
