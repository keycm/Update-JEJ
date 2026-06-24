<?php
// verify_payments.php

require_once 'config.php';
checkAdmin();

requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER', 'CASHIER']);

if ($_SESSION['role'] === 'MANAGER') {
    requirePermission($conn, 'fin_review');
}

$alert_msg = "";
$alert_type = "";
$admin_id = (int)$_SESSION['user_id'];

function has_col($conn, $table, $col){
    $safe_col = $conn->real_escape_string($col);
    $safe_table = $conn->real_escape_string($table);
    $check = $conn->query("SHOW COLUMNS FROM `$safe_table` LIKE '$safe_col'");
    return ($check && $check->num_rows > 0);
}

function sendBuyerNotification($conn, $user_id, $title, $message){
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    if($stmt){
        $stmt->bind_param("iss", $user_id, $title, $message);
        $stmt->execute();
        $stmt->close();
    }
}

function refreshReservationDpStatus($conn, $reservation_id){
    $resStmt = $conn->prepare("SELECT r.required_dp, l.total_price FROM reservations r JOIN lots l ON r.lot_id = l.id WHERE r.id = ?");
    if(!$resStmt) return;
    $resStmt->bind_param("i", $reservation_id);
    $resStmt->execute();
    $reservation = $resStmt->get_result()->fetch_assoc();
    $resStmt->close();
    if(!$reservation) return;

    $required_dp = (!empty($reservation['required_dp']) && (float)$reservation['required_dp'] > 0)
        ? (float)$reservation['required_dp']
        : ((float)$reservation['total_price'] * 0.20);

    $likeDp = "%Down Payment%Res#{$reservation_id}%";
    $dpStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS verified_dp_paid FROM transactions WHERE type='INCOME' AND payment_status='VERIFIED' AND description LIKE ?");
    if(!$dpStmt) return;
    $dpStmt->bind_param("s", $likeDp);
    $dpStmt->execute();
    $verified_dp_paid = (float)($dpStmt->get_result()->fetch_assoc()['verified_dp_paid'] ?? 0);
    $dpStmt->close();

    $new_status = ($verified_dp_paid >= $required_dp) ? 'PAID' : 'UNPAID';
    $upd = $conn->prepare("UPDATE reservations SET dp_status = ? WHERE id = ?");
    if($upd){
        $upd->bind_param("si", $new_status, $reservation_id);
        $upd->execute();
        $upd->close();
    }
}

$has_rejected_by = has_col($conn, 'transactions', 'rejected_by');
$has_rejected_at = has_col($conn, 'transactions', 'rejected_at');
$has_voided_by = has_col($conn, 'transactions', 'voided_by');
$has_voided_at = has_col($conn, 'transactions', 'voided_at');
$has_void_by = has_col($conn, 'transactions', 'void_by');
$has_void_at = has_col($conn, 'transactions', 'void_at');

$required_cols = ['payment_status','proof_image','verified_by','verified_at','rejection_reason','void_reason','or_locked','or_locked_at','or_locked_by'];
$missing_cols = [];
foreach($required_cols as $col){
    if(!has_col($conn, 'transactions', $col)) $missing_cols[] = $col;
}

function fetchPaymentForReview($conn, $trans_id, $status){
    $fetch = $conn->prepare("SELECT t.id, t.amount, t.description, t.or_number, t.reservation_id, t.payment_status, r.user_id, l.block_no, l.lot_no FROM transactions t LEFT JOIN reservations r ON t.reservation_id = r.id LEFT JOIN lots l ON r.lot_id = l.id WHERE t.id = ? AND t.payment_status = ? LIMIT 1");
    if(!$fetch) return [null, "Fetch Error: " . $conn->error];
    $fetch->bind_param("is", $trans_id, $status);
    $fetch->execute();
    $payment = $fetch->get_result()->fetch_assoc();
    $fetch->close();
    return [$payment, null];
}

if(empty($missing_cols) && isset($_POST['verify_payment'])){
    $trans_id = (int)$_POST['transaction_id'];
    [$payment, $err] = fetchPaymentForReview($conn, $trans_id, 'PENDING');
    if($err){
        $alert_msg = $err; $alert_type = "error";
    } elseif(!$payment){
        $alert_msg = "Payment not found or already reviewed."; $alert_type = "error";
    } else {
        $stmt = $conn->prepare("UPDATE transactions SET payment_status='VERIFIED', verified_by=?, verified_at=NOW(), rejection_reason=NULL, or_locked=1, or_locked_at=NOW(), or_locked_by=? WHERE id=? AND payment_status='PENDING' AND or_locked=0");
        if(!$stmt){
            $alert_msg = "Verify Prepare Error: " . $conn->error; $alert_type = "error";
        } else {
            $stmt->bind_param("iii", $admin_id, $admin_id, $trans_id);
            if($stmt->execute() && $stmt->affected_rows > 0){
                if(!empty($payment['reservation_id'])) refreshReservationDpStatus($conn, (int)$payment['reservation_id']);
                if(!empty($payment['user_id'])){
                    $amount = number_format((float)$payment['amount'], 2);
                    sendBuyerNotification($conn, (int)$payment['user_id'], "Payment Verified", "Your payment of ₱{$amount} for Block {$payment['block_no']} Lot {$payment['lot_no']} has been verified and posted to your account.");
                }
                add_audit_log($conn, $admin_id, 'Payment Verified / OR Locked', 'Payment verified and OR number locked. Transaction ID: ' . $trans_id, 'transactions', $trans_id);
                $alert_msg = "Payment successfully VERIFIED and posted to SOA/balance."; $alert_type = "success";
            } else {
                $alert_msg = "Failed to verify payment. It may have already been reviewed or OR is already locked."; $alert_type = "error";
            }
            $stmt->close();
        }
    }
}

if(empty($missing_cols) && isset($_POST['reject_payment'])){
    $trans_id = (int)$_POST['transaction_id'];
    $reason = trim($_POST['rejection_reason'] ?? '');
    if($reason === '') $reason = 'Rejected after office verification.';
    [$payment, $err] = fetchPaymentForReview($conn, $trans_id, 'PENDING');
    if($err){
        $alert_msg = $err; $alert_type = "error";
    } elseif(!$payment){
        $alert_msg = "Payment not found or already reviewed."; $alert_type = "error";
    } else {
        $set_reject = $has_rejected_by && $has_rejected_at ? "rejected_by=?, rejected_at=NOW()," : "verified_by=?, verified_at=NOW(),";
        $stmt = $conn->prepare("UPDATE transactions SET payment_status='REJECTED', {$set_reject} rejection_reason=? WHERE id=? AND payment_status='PENDING'");
        if(!$stmt){
            $alert_msg = "Reject Prepare Error: " . $conn->error; $alert_type = "error";
        } else {
            $stmt->bind_param("isi", $admin_id, $reason, $trans_id);
            if($stmt->execute() && $stmt->affected_rows > 0){
                if(!empty($payment['reservation_id'])) refreshReservationDpStatus($conn, (int)$payment['reservation_id']);
                if(!empty($payment['user_id'])){
                    $amount = number_format((float)$payment['amount'], 2);
                    sendBuyerNotification($conn, (int)$payment['user_id'], "Payment Rejected", "Your payment of ₱{$amount} for Block {$payment['block_no']} Lot {$payment['lot_no']} was rejected. Reason: {$reason}");
                }
                add_audit_log($conn, $admin_id, 'Payment Rejected', 'Rejected payment. Reason: ' . $reason . ' | Transaction ID: ' . $trans_id, 'transactions', $trans_id);
                $alert_msg = "Payment has been REJECTED and will NOT be counted in SOA/balance."; $alert_type = "success";
            } else {
                $alert_msg = "Failed to reject payment. It may have already been reviewed."; $alert_type = "error";
            }
            $stmt->close();
        }
    }
}

if(empty($missing_cols) && isset($_POST['void_payment'])){
    $trans_id = (int)$_POST['transaction_id'];
    $reason = trim($_POST['void_reason'] ?? '');
    if($reason === '') $reason = 'Voided after verification.';
    [$payment, $err] = fetchPaymentForReview($conn, $trans_id, 'VERIFIED');
    if($err){
        $alert_msg = $err; $alert_type = "error";
    } elseif(!$payment){
        $alert_msg = "Verified payment not found or already voided."; $alert_type = "error";
    } else {
        if($has_voided_by && $has_voided_at){
            $stmt = $conn->prepare("UPDATE transactions SET payment_status='VOIDED', voided_by=?, voided_at=NOW(), void_reason=?, or_locked=1 WHERE id=? AND payment_status='VERIFIED'");
        } elseif($has_void_by && $has_void_at){
            $stmt = $conn->prepare("UPDATE transactions SET payment_status='VOIDED', void_by=?, void_at=NOW(), void_reason=?, or_locked=1 WHERE id=? AND payment_status='VERIFIED'");
        } else {
            $stmt = $conn->prepare("UPDATE transactions SET payment_status='VOIDED', void_reason=?, or_locked=1 WHERE id=? AND payment_status='VERIFIED'");
        }
        if(!$stmt){
            $alert_msg = "Void Prepare Error: " . $conn->error; $alert_type = "error";
        } else {
            if(($has_voided_by && $has_voided_at) || ($has_void_by && $has_void_at)) $stmt->bind_param("isi", $admin_id, $reason, $trans_id);
            else $stmt->bind_param("si", $reason, $trans_id);
            if($stmt->execute() && $stmt->affected_rows > 0){
                if(!empty($payment['reservation_id'])) refreshReservationDpStatus($conn, (int)$payment['reservation_id']);
                if(!empty($payment['user_id'])){
                    $amount = number_format((float)$payment['amount'], 2);
                    sendBuyerNotification($conn, (int)$payment['user_id'], "Payment Voided", "Your verified payment of ₱{$amount} for Block {$payment['block_no']} Lot {$payment['lot_no']} was voided. Reason: {$reason}");
                }
                add_audit_log($conn, $admin_id, 'Payment Voided', 'Voided verified payment. Reason: ' . $reason . ' | Transaction ID: ' . $trans_id, 'transactions', $trans_id);
                $alert_msg = "Payment has been VOIDED and removed from SOA/balance totals."; $alert_type = "success";
            } else {
                $alert_msg = "Failed to void payment. It may have already been voided."; $alert_type = "error";
            }
            $stmt->close();
        }
    }
}

$payments = [];
if(empty($missing_cols)){
    $sql = "
        SELECT 
            t.id, t.amount, t.transaction_date, t.description, t.or_number, t.payment_status,
            t.proof_image, t.rejection_reason, t.void_reason,
            enc.fullname AS encoded_by_name,
            v.fullname AS verified_by_name,
            t.verified_at,
            r.id AS reservation_id,
            buyer.fullname AS buyer_name,
            buyer.email AS buyer_email,
            l.block_no, l.lot_no
        FROM transactions t
        LEFT JOIN users enc ON t.user_id = enc.id
        LEFT JOIN users v ON t.verified_by = v.id
        LEFT JOIN reservations r ON t.reservation_id = r.id
        LEFT JOIN users buyer ON r.user_id = buyer.id
        LEFT JOIN lots l ON r.lot_id = l.id
        WHERE t.type='INCOME'
          AND t.payment_status IN ('PENDING','REJECTED','VERIFIED','VOIDED')
        ORDER BY FIELD(t.payment_status,'PENDING','VERIFIED','REJECTED','VOIDED'), t.transaction_date DESC, t.id DESC
        LIMIT 200
    ";
    $res = $conn->query($sql);
    if($res){ while($row = $res->fetch_assoc()) $payments[] = $row; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Payments | JEJ Financials</title>
<link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
:root{
    --primary:#2e7d32;
    --primary-light:#e8f5e9;
    --dark:#1b5e20;
    --gray-light:#f1f8e9;
    --gray-border:#c8e6c9;
    --text-muted:#607d8b;
    --shadow-lg:0 10px 25px rgba(46,125,50,.16);
}
body { background-color: #fafcf9; display: flex; min-height: 100vh; overflow-x: hidden; font-family: 'Inter', sans-serif; color: #37474f; margin: 0; }
        .sidebar { width: 260px; background: #ffffff; border-right: 1px solid #c8e6c9; display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; box-shadow: 0 1px 2px 0 rgba(46, 125, 50, 0.08); }
        .brand-box { padding: 25px; border-bottom: 1px solid #c8e6c9; display: flex; align-items: center; gap: 12px; }
        .sidebar-menu { padding: 20px 15px; flex: 1; overflow-y: auto; }
        .menu-link { display: flex; align-items: center; gap: 12px; padding: 12px 18px; color: #455a64; text-decoration: none; font-weight: 500; font-size: 14px; border-radius: 10px; margin-bottom: 6px; transition: all 0.2s ease; }
        .menu-link:hover { background: #f1f8e9; color: #2e7d32; }
        .menu-link.active { background: #e8f5e9; color: #2e7d32; font-weight: 600; border-left: 4px solid #2e7d32; }
        .menu-link i { width: 20px; text-align: center; font-size: 16px; opacity: 0.8; }

.dropdown-toggle{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
}

.finance-main-link{
    display:flex;
    align-items:center;
    gap:12px;
    color:inherit;
    text-decoration:none;
    flex:1;
}

.submenu-toggle-btn{
    background:none;
    border:none;
    color:inherit;
    cursor:pointer;
    width:28px;
    height:28px;
    border-radius:6px;
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

.submenu-link.active{
    background:#e8f5e9;
    color:#2e7d32;
    font-weight:800;
}


/* FIX MAIN LAYOUT + VERIFY PAYMENT CONTENT */
.main-panel{
    margin-left:260px;
    flex:1;
    min-height:100vh;
    width:calc(100% - 260px);
    background:#fafcf9;
}
.top-header{
    background:#fff;
    padding:24px 40px;
    border-bottom:1px solid #c8e6c9;
}
.top-header h1{
    margin:0 0 6px 0;
    font-size:24px;
    color:#1b5e20;
    font-weight:800;
}
.top-header p{
    margin:0;
    color:#607d8b;
    font-size:14px;
}
.content{
    padding:35px 40px;
}
.note{
    background:#fffbeb;
    border:1px solid #facc15;
    color:#92400e;
    padding:16px 18px;
    border-radius:10px;
    margin-bottom:22px;
    font-size:14px;
}
.alert{
    padding:14px 18px;
    border-radius:10px;
    margin-bottom:18px;
    font-weight:700;
}
.alert.success{
    background:#e8f5e9;
    color:#2e7d32;
    border:1px solid #c8e6c9;
}
.alert.error{
    background:#fee2e2;
    color:#991b1b;
    border:1px solid #fecaca;
}
.card{
    background:#fff;
    border:1px solid #c8e6c9;
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 1px 2px rgba(46,125,50,.08);
}
.card-header{
    background:#f1f8e9;
    color:#1b5e20;
    font-size:18px;
    font-weight:800;
    padding:18px 22px;
    border-bottom:1px solid #c8e6c9;
}
table{
    width:100%;
    border-collapse:collapse;
}
th{
    background:#f8fafc;
    color:#475569;
    text-transform:uppercase;
    font-size:12px;
    text-align:left;
    padding:14px 18px;
    border-bottom:1px solid #e2e8f0;
}
td{
    padding:16px 18px;
    border-bottom:1px solid #e2e8f0;
    vertical-align:top;
    font-size:13px;
}
.badge{
    padding:6px 12px;
    border-radius:999px;
    font-size:11px;
    font-weight:800;
    display:inline-block;
}
.badge.pending{background:#fef3c7;color:#92400e;}
.badge.verified{background:#dcfce7;color:#166534;}
.badge.rejected{background:#fee2e2;color:#991b1b;}
.badge.voided{background:#e5e7eb;color:#374151;}
.btn{
    border:none;
    border-radius:8px;
    padding:8px 13px;
    font-weight:800;
    cursor:pointer;
}
.btn-verify{background:#10b981;color:#fff;}
.btn-reject{background:#ef4444;color:#fff;margin-top:6px;}
.btn-void{background:#334155;color:#fff;margin-top:6px;}
.reason{
    width:100%;
    box-sizing:border-box;
    padding:8px 10px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    margin-bottom:6px;
}
.proof{color:#2563eb;text-decoration:none;font-weight:700;}
.menu-dropdown{margin-bottom:6px;}


/* AUTO FIT + COLLAPSIBLE SIDEBAR */
.sidebar,
.main-panel{
    transition: all .25s ease;
}
.top-header{
    display:flex;
    align-items:center;
    gap:14px;
}
.sidebar-toggle{
    width:42px;
    height:42px;
    border:0;
    border-radius:10px;
    background:#e8f5e9;
    color:#2e7d32;
    font-size:18px;
    cursor:pointer;
    flex:0 0 42px;
}
.sidebar-toggle:hover{background:#c8e6c9;}
.header-title-wrap{
    min-width:0;
}
body.sidebar-collapsed .sidebar{
    width:78px;
}
body.sidebar-collapsed .main-panel{
    margin-left:78px;
    width:calc(100% - 78px);
}
body.sidebar-collapsed .brand-box{
    justify-content:center;
    padding:25px 10px;
}
body.sidebar-collapsed .brand-box div,
body.sidebar-collapsed .menu-text,
body.sidebar-collapsed .finance-main-link span,
body.sidebar-collapsed .sidebar-menu small,
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

/* VERIFY PAYMENT AUTO FIT TABLE */
.card{
    overflow-x:auto;
}
.card table{
    min-width:980px;
}
.card th:nth-child(1), .card td:nth-child(1){width:105px;}
.card th:nth-child(2), .card td:nth-child(2){width:190px;}
.card th:nth-child(3), .card td:nth-child(3){width:260px;}
.card th:nth-child(4), .card td:nth-child(4){width:150px;}
.card th:nth-child(5), .card td:nth-child(5){width:120px;}
.card th:nth-child(6), .card td:nth-child(6){width:220px;}
td, th{
    overflow-wrap:anywhere;
}
.btn{
    white-space:nowrap;
}
.reason{
    max-width:190px;
}

/* Tablet/mobile card layout */
@media (max-width:1100px){
    .content{padding:22px;}
    .top-header{padding:18px 22px;}
    .card table,
    .card thead,
    .card tbody,
    .card th,
    .card td,
    .card tr{
        display:block;
        width:100% !important;
        min-width:0 !important;
        box-sizing:border-box;
    }
    .card thead{
        display:none;
    }
    .card tr{
        margin:14px;
        border:1px solid #c8e6c9;
        border-radius:14px;
        overflow:hidden;
        background:#fff;
        box-shadow:0 1px 2px rgba(46,125,50,.08);
    }
    .card td{
        display:grid;
        grid-template-columns:150px 1fr;
        gap:12px;
        align-items:start;
        border-bottom:1px solid #e2e8f0;
        padding:12px 14px;
    }
    .card td::before{
        font-weight:800;
        color:#607d8b;
        text-transform:uppercase;
        font-size:11px;
        letter-spacing:.4px;
    }
    .card td:nth-of-type(1)::before{content:"Status";}
    .card td:nth-of-type(2)::before{content:"Buyer / Property";}
    .card td:nth-of-type(3)::before{content:"Payment Details";}
    .card td:nth-of-type(4)::before{content:"Encoded By";}
    .card td:nth-of-type(5)::before{content:"Proof";}
    .card td:nth-of-type(6)::before{content:"Action";}
    .reason{
        max-width:100%;
    }
}
@media (max-width:768px){
    body{display:block;}
    .sidebar{
        left:-260px;
        width:260px;
    }
    .main-panel,
    body.sidebar-collapsed .main-panel{
        margin-left:0;
        width:100%;
    }
    body.sidebar-open .sidebar{
        left:0;
        z-index:1001;
    }
    body.sidebar-open::after{
        content:"";
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.35);
        z-index:1000;
    }
    .top-header h1{
        font-size:20px;
    }
    .top-header p{
        display:none;
    }
    .content{
        padding:18px;
    }
    .card td{
        grid-template-columns:1fr;
        gap:6px;
    }
}
/* CONSISTENT PROFILE DROPDOWN - same compact style as reservation.php */
.header-right{
    margin-left:auto;
    display:flex;
    align-items:center;
    justify-content:flex-end;
    flex:0 0 auto;
}

.profile-dropdown{
    position:relative !important;
    cursor:pointer;
    flex:0 0 auto !important;
    width:auto !important;
}

.profile-trigger{
    width:auto !important;
    min-width:0 !important;
    height:auto !important;
    min-height:52px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:flex-start !important;
    gap:12px !important;
    padding:6px 12px !important;
    border-radius:10px !important;
    border:1px solid transparent !important;
    background:transparent !important;
    box-shadow:none !important;
    line-height:1.2 !important;
}

.profile-trigger:hover{
    background:var(--gray-light) !important;
    border-color:var(--gray-border) !important;
}

.profile-avatar{
    width:40px !important;
    height:40px !important;
    min-width:40px !important;
    border-radius:50% !important;
    background:var(--primary) !important;
    color:#fff !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-weight:800 !important;
    font-size:16px !important;
    box-shadow:0 2px 6px rgba(46,125,50,.25) !important;
}

.profile-info{
    display:block !important;
    min-width:0 !important;
}

.profile-info strong{
    display:block !important;
    font-size:13px !important;
    font-weight:800 !important;
    color:var(--dark) !important;
    line-height:1.2 !important;
    margin:0 !important;
    white-space:nowrap !important;
}

.profile-info small{
    display:block !important;
    font-size:11px !important;
    font-weight:500 !important;
    color:var(--text-muted) !important;
    line-height:1.2 !important;
    margin-top:2px !important;
    white-space:nowrap !important;
}

.dropdown-menu{
    display:none !important;
    position:absolute !important;
    right:0 !important;
    left:auto !important;
    top:110% !important;
    width:220px !important;
    min-width:220px !important;
    max-width:220px !important;
    background:#fff !important;
    border:1px solid var(--gray-border) !important;
    border-radius:12px !important;
    box-shadow:var(--shadow-lg) !important;
    overflow:hidden !important;
    z-index:9999 !important;
    padding:0 !important;
    margin:0 !important;
}

.profile-dropdown:hover .dropdown-menu{
    display:block !important;
}

.dropdown-header{
    padding:15px !important;
    border-bottom:1px solid var(--gray-border) !important;
    background:var(--gray-light) !important;
}

.dropdown-header strong{
    display:block !important;
    font-size:13px !important;
    font-weight:800 !important;
    color:var(--dark) !important;
    line-height:1.2 !important;
    margin:0 0 3px 0 !important;
}

.dropdown-header span,
.dropdown-header small{
    display:block !important;
    font-size:11px !important;
    color:var(--text-muted) !important;
    line-height:1.2 !important;
}

.dropdown-item{
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    width:100% !important;
    padding:12px 16px !important;
    color:#455a64 !important;
    text-decoration:none !important;
    font-size:13px !important;
    font-weight:500 !important;
    border-left:3px solid transparent !important;
    box-sizing:border-box !important;
    white-space:nowrap !important;
}

.dropdown-item i{
    width:16px !important;
    text-align:center !important;
    flex:0 0 16px !important;
}

.dropdown-item:hover{
    background:var(--primary-light) !important;
    color:var(--primary) !important;
    border-left-color:var(--primary) !important;
}

.dropdown-item.text-danger{
    color:#d84315 !important;
}

.dropdown-item.text-danger:hover{
    background:#fbe9e7 !important;
    color:#bf360c !important;
    border-left-color:#d84315 !important;
}

/* keep profile compact on mobile */
@media (max-width:768px){
    .header-right{
        margin-left:auto !important;
    }

    .profile-info{
        display:none !important;
    }

    .profile-trigger{
        min-height:44px !important;
        padding:4px 8px !important;
    }

    .profile-avatar{
        width:38px !important;
        height:38px !important;
        min-width:38px !important;
    }

    .dropdown-menu{
        right:0 !important;
        top:105% !important;
    }
}

</style>
</head>
<body>
<div class="sidebar">
    <div class="brand-box">
        <img src="assets/logo1.png" style="height: 38px; width: auto; border-radius: 8px;">
        <div style="line-height: 1.1;">
            <span style="font-size: 16px; font-weight: 800; color: #2e7d32; display: block;">JEJ Top Priority Corporation</span>
            <span style="font-size: 11px; color: #607d8b; font-weight: 500;">Management Portal</span>
        </div>
    </div>

  <div class="sidebar-menu">

<?php $currentPage = basename($_SERVER['PHP_SELF']); ?>

<?php if($_SESSION['role'] !== 'CASHIER'): ?>
    <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-bottom: 12px; letter-spacing: 0.5px;">MAIN MENU</small>
    
    <a href="admin.php" class="menu-link">
        <i class="fa-solid fa-chart-pie"></i> <span class="menu-text">Dashboard</span>
    </a>

    <a href="reservation.php" class="menu-link">
        <i class="fa-solid fa-file-signature"></i> <span class="menu-text">Reservations</span>
    </a>

    <a href="master_list.php" class="menu-link">
        <i class="fa-solid fa-map-location-dot"></i> <span class="menu-text">Master List / Map</span>
    </a>

    <a href="admin.php?view=inventory" class="menu-link">
        <i class="fa-solid fa-plus-circle"></i>
        <span class="menu-text">Add Property</span>
    </a>

<?php endif; ?>

<div class="menu-dropdown">

    <div class="menu-link dropdown-toggle active">

        <a href="financial.php" class="finance-main-link">
            <i class="fa-solid fa-coins"></i>
            <span>Financials</span>
        </a>

        <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)">
            <i class="fa-solid fa-chevron-up dropdown-arrow" id="financeArrow"></i>
        </button>

    </div>

    <div id="financeSubMenu" class="submenu show">

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
    <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
        <i class="fa-solid fa-bars"></i>
    </button>
    <div class="header-title-wrap">
        <h1><i class="fa-solid fa-shield-halved"></i> Verify Payments</h1>
        <p>Confirm actual payment, reject invalid proof, or void verified payments with reasons.</p>
    </div>

    <div class="header-right">
        <?php include 'includes/profile_dropdown.php'; ?>
    </div>
</div>


<div class="content">
<?php if(!empty($missing_cols)): ?><div class="alert error">Database update required. Missing columns: <?= htmlspecialchars(implode(', ', $missing_cols)) ?>.</div><?php endif; ?>
<?php if($alert_msg): ?><div class="alert <?= $alert_type=='success'?'success':'error' ?>"><?= $alert_msg ?></div><?php endif; ?>
<div class="note"><b>Payment Status Flow:</b> New payments stay <b>PENDING</b>. Only <b>VERIFIED</b> payments count in SOA/balance. <b>REJECTED</b> and <b>VOIDED</b> records remain visible for audit but are not posted.</div>
<div class="card"><div class="card-header">Payment Review Records</div><table><thead><tr><th>Status</th><th>Buyer / Property</th><th>Payment Details</th><th>Encoded By</th><th>Proof</th><th>Action</th></tr></thead><tbody>
<?php if(empty($payments)): ?><tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:40px;">No payment records found.</td></tr><?php else: foreach($payments as $pay): ?>
<tr><td><span class="badge <?= strtolower($pay['payment_status']) ?>"><?= htmlspecialchars($pay['payment_status']) ?></span></td><td><b><?= htmlspecialchars($pay['buyer_name'] ?? 'N/A') ?></b><br><small><?= htmlspecialchars($pay['buyer_email'] ?? '') ?></small><br><small>Block <?= htmlspecialchars($pay['block_no'] ?? '-') ?> Lot <?= htmlspecialchars($pay['lot_no'] ?? '-') ?></small></td><td><b style="color:#059669;">₱<?= number_format((float)$pay['amount'],2) ?></b><br><small>OR/Ref: <b><?= htmlspecialchars($pay['or_number']) ?></b></small><br><small>Date: <?= date('M d, Y', strtotime($pay['transaction_date'])) ?></small><br><small><?= htmlspecialchars($pay['description']) ?></small><?php if($pay['payment_status']=='REJECTED' && !empty($pay['rejection_reason'])): ?><br><small style="color:#b91c1c;"><b>Rejected:</b> <?= htmlspecialchars($pay['rejection_reason']) ?></small><?php endif; ?><?php if($pay['payment_status']=='VOIDED' && !empty($pay['void_reason'])): ?><br><small style="color:#334155;"><b>Voided:</b> <?= htmlspecialchars($pay['void_reason']) ?></small><?php endif; ?></td><td><?= htmlspecialchars($pay['encoded_by_name'] ?? 'Unknown') ?></td><td><?php if(!empty($pay['proof_image'])): ?><a class="proof" href="<?= htmlspecialchars($pay['proof_image']) ?>" target="_blank"><i class="fa-solid fa-paperclip"></i> View Proof</a><?php else: ?><span style="color:#94a3b8;">No file</span><?php endif; ?></td><td>
<?php if($pay['payment_status']=='PENDING'): ?><form method="POST" style="display:inline;" onsubmit="return confirm('Verify this payment? Make sure actual money/deposit was received.');"><input type="hidden" name="transaction_id" value="<?= (int)$pay['id'] ?>"><button class="btn btn-verify" name="verify_payment"><i class="fa-solid fa-check"></i> Verify</button></form><form method="POST" style="margin-top:6px;" onsubmit="return confirm('Reject this payment record?');"><input type="hidden" name="transaction_id" value="<?= (int)$pay['id'] ?>"><input class="reason" name="rejection_reason" placeholder="Reason for rejection" required><button class="btn btn-reject" name="reject_payment"><i class="fa-solid fa-xmark"></i> Reject</button></form><?php elseif($pay['payment_status']=='VERIFIED'): ?><small>Verified by: <?= htmlspecialchars($pay['verified_by_name'] ?? 'N/A') ?><br><?= htmlspecialchars($pay['verified_at'] ?? '') ?></small><form method="POST" style="margin-top:6px;" onsubmit="return confirm('Void this VERIFIED payment? It will be removed from SOA totals but kept in audit.');"><input type="hidden" name="transaction_id" value="<?= (int)$pay['id'] ?>"><input class="reason" name="void_reason" placeholder="Reason for void" required><button class="btn btn-void" name="void_payment"><i class="fa-solid fa-ban"></i> Void</button></form><?php else: ?><small>No further action.</small><?php endif; ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div></div>
<script>

function toggleSidebar(){
    if(window.innerWidth <= 768){
        document.body.classList.toggle('sidebar-open');
    }else{
        document.body.classList.toggle('sidebar-collapsed');
    }
}
document.addEventListener('click', function(e){
    if(window.innerWidth <= 768 && document.body.classList.contains('sidebar-open')){
        const sidebar = document.querySelector('.sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        if(sidebar && toggle && !sidebar.contains(e.target) && !toggle.contains(e.target)){
            document.body.classList.remove('sidebar-open');
        }
    }
});

function toggleFinanceMenu(event){
    event.preventDefault();
    event.stopPropagation();

    const submenu = document.getElementById('financeSubMenu');
    const arrow = document.getElementById('financeArrow');

    submenu.classList.toggle('show');

    arrow.classList.toggle('fa-chevron-up');
    arrow.classList.toggle('fa-chevron-down');
}
</script>
</body></html>
