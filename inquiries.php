<?php
// inquiries.php - Manage Contact Form Inquiries
include 'config.php';

// Include PHPMailer files for sending replies
require_once 'PHPMailer/Exception.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';

// 1. Basic Access Control
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['SUPER ADMIN', 'ADMIN', 'MANAGER'])) {
    header("Location: admin.php?view=dashboard");
    exit();
}

// --- NOTIFICATION CHECK LOGIC ---
$unread_count = 0;
if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $notif_stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    if ($notif_stmt) {
        $notif_stmt->bind_param("i", $uid);
        $notif_stmt->execute();
        $notif_stmt->bind_result($unread_count);
        $notif_stmt->fetch();
        $notif_stmt->close();
    }
}

$currentPage = basename($_SERVER['PHP_SELF']);
$financePages = [
    'financial.php',
    'verify_payments.php',
    'payment_tracking.php',
    'transaction_history.php',
    'daily_reconciliation.php',
    'aging_due_report.php',
    'contract_status.php',
    'manual_buyer_entry.php',
    'pricing_matrix.php'
];
$isFinancePage = in_array($currentPage, $financePages, true);

// --- HANDLING AJAX POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    header('Content-Type: application/json');

    // Fetch and mark as READ
    if ($action == 'get_inquiry') {
        $id = intval($_POST['id']);
        
        // Auto-mark as read if unread
        $mark_stmt = $conn->prepare("UPDATE inquiries SET status = 'READ' WHERE id = ? AND status = 'UNREAD'");
        if ($mark_stmt) {
            $mark_stmt->bind_param("i", $id);
            $mark_stmt->execute();
            $mark_stmt->close();
        }

        $stmt = $conn->prepare("SELECT * FROM inquiries WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $inquiry = $stmt->get_result()->fetch_assoc();

        if ($inquiry) {
            echo json_encode(['status' => 'success', 'data' => $inquiry]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Inquiry not found.']);
        }
        exit();
    }

    // Update specific status (e.g., mark as Responded without emailing)
    if ($action == 'update_status') {
        $id = intval($_POST['id']);
        $status = $_POST['status'];

        $stmt = $conn->prepare("UPDATE inquiries SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => "Inquiry marked as $status!"]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error during update.']);
        }
        exit();
    }
    
    // SEND EMAIL REPLY 
    if ($action == 'send_reply') {
        $id = intval($_POST['id']);
        $reply_message = trim($_POST['reply_message']);
        
        if (empty($reply_message)) {
            echo json_encode(['status' => 'error', 'message' => 'Reply message cannot be empty.']);
            exit();
        }

        // Fetch inquiry details to get the recipient email
        $stmt = $conn->prepare("SELECT * FROM inquiries WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $inquiry = $stmt->get_result()->fetch_assoc();
        
        if ($inquiry) {
            $user_email = $inquiry['email'];
            $user_name = $inquiry['name'];
            $orig_subject = $inquiry['subject'] ?: 'Your Inquiry';
            
            $mail = new PHPMailer\PHPMailer\PHPMailer();
            try {
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST; 
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; 
                $mail->Port       = 587;
                $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));

                $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME . ' Support');
                $mail->addAddress($user_email); 
                $mail->isHTML(true);
                $mail->Subject = 'Re: ' . $orig_subject;
                
                // Formatted HTML Email Body
                $mail->Body = "
                <div style='font-family: Arial, sans-serif; color: #333; line-height: 1.6;'>
                    <h3>Hello $user_name,</h3>
                    <p>Thank you for reaching out to JEJ Top Priority Corporation. Here is the response to your inquiry:</p>
                    <div style='background: #f8fafc; padding: 15px; border-left: 4px solid #2e7d32; margin-bottom: 20px;'>
                        " . nl2br(htmlspecialchars($reply_message)) . "
                    </div>
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                    <p style='color: #666; font-size: 13px;'>
                        <b>Original Message:</b><br>
                        " . nl2br(htmlspecialchars($inquiry['message'])) . "
                    </p>
                    <br>
                    <p>Best Regards,<br><b>JEJ Top Priority Corporation Support Team</b></p>
                </div>";
                
                if($mail->send()){
                    // Update status to RESPONDED
                    $upd_stmt = $conn->prepare("UPDATE inquiries SET status = 'RESPONDED' WHERE id = ?");
                    $upd_stmt->bind_param("i", $id);
                    $upd_stmt->execute();
                    
                    echo json_encode(['status' => 'success', 'message' => 'Reply sent successfully via email!']);
                }
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => "Mail Error: {$mail->ErrorInfo}"]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Inquiry not found in database.']);
        }
        exit();
    }

    // Delete Inquiry
    if ($action == 'delete') {
        $id = intval($_POST['id']);

        // Delete the inquiry record first, then remove related admin notifications.
        // Contact inquiry notifications are created with text like: "Inquiry ID: 2".
        // Keeping this synced prevents deleted inquiries from still appearing in the bell
        // dropdown and notifications.php page.
        $stmt = $conn->prepare("DELETE FROM inquiries WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $deletedRows = $stmt->affected_rows;
            $stmt->close();

            $inquiryIdToken = '%Inquiry ID: ' . $id . '%';
            $notif_delete = $conn->prepare("
                DELETE FROM notifications
                WHERE LOWER(title) LIKE '%contact inquiry%'
                  AND message LIKE ?
            ");
            if ($notif_delete) {
                $notif_delete->bind_param("s", $inquiryIdToken);
                $notif_delete->execute();
                $notif_delete->close();
            }

            echo json_encode([
                'status' => 'success',
                'message' => $deletedRows > 0
                    ? 'Inquiry deleted successfully. Related notification removed.'
                    : 'Inquiry was already deleted. Related notification cleanup completed.'
            ]);
        } else {
            $stmt->close();
            echo json_encode(['status' => 'error', 'message' => 'Database error during deletion.']);
        }
        exit();
    }
}

// Fetch Inquiries List - prepared statement version
$where_clauses = [];
$params = [];
$types = '';

if (isset($_GET['search']) && trim($_GET['search']) !== '') {
    $search = '%' . trim($_GET['search']) . '%';
    $where_clauses[] = "(name LIKE ? OR email LIKE ? OR subject LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $types .= 'sss';
}

$allowed_statuses = ['UNREAD', 'READ', 'RESPONDED'];
if (isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses, true)) {
    $where_clauses[] = "status = ?";
    $params[] = $_GET['status'];
    $types .= 's';
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$query = "SELECT * FROM inquiries $where_sql ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$inquiries_result = $stmt->get_result();

// IMPORTANT:
// The notification bell include also runs database queries and may use generic variable names
// such as $result. Fetch inquiry rows into a dedicated array before rendering the header
// so the inbox list cannot be overwritten by includes/profile dropdown code.
$inquiries = [];
if ($inquiries_result) {
    while ($inquiry_row = $inquiries_result->fetch_assoc()) {
        $inquiries[] = $inquiry_row;
    }
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Inquiries | JEJ Admin</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.0/jquery.min.js"></script>
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
            --shadow-md: 0 4px 6px -1px rgba(46, 125, 50, 0.1);
        }

        * { box-sizing: border-box; }
        body { background-color: #fafcf9; display: flex; min-height: 100vh; font-family: 'Inter', sans-serif; color: #37474f; margin: 0; }

        /* Sidebar Styling */
        .sidebar { width: 260px; background: #ffffff; border-right: 1px solid var(--gray-border); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; box-shadow: var(--shadow-sm); }
        .brand-box { padding: 25px; border-bottom: 1px solid var(--gray-border); display: flex; align-items: center; gap: 12px; }
        .sidebar-menu { padding: 20px 15px; flex: 1; overflow-y: auto; }
        .menu-link { display: flex; align-items: center; gap: 12px; padding: 12px 18px; color: #455a64; text-decoration: none; font-weight: 500; font-size: 14px; border-radius: 10px; margin-bottom: 6px; transition: all 0.2s ease; }
        .menu-link:hover { background: var(--gray-light); color: var(--primary); }
        .menu-link.active { background: var(--primary-light); color: var(--primary); font-weight: 600; border-left: 4px solid var(--primary); }
        .menu-link i { width: 20px; text-align: center; font-size: 16px; opacity: 0.8; }

        /* Sidebar dropdown for Financial submenu */
        .menu-dropdown { margin-bottom: 6px; }
        .dropdown-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            cursor: default;
        }
        .dropdown-toggle.open {
            background: var(--primary-light);
            color: var(--primary);
            font-weight: 600;
        }
        .finance-main-link {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            color: inherit;
            text-decoration: none;
            min-width: 0;
        }
        .submenu-toggle-btn {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .submenu-toggle-btn:hover { background: #dff2e1; }
        .dropdown-arrow { transition: transform .2s ease; }
        .submenu {
            display: none;
            padding-left: 18px;
            margin-top: 4px;
            margin-bottom: 8px;
        }
        .submenu.show { display: block; }
        .submenu-link {
            padding-left: 14px !important;
            font-size: 13px;
        }
        .submenu-link i { font-size: 12px !important; }

        /* Main Panel & Header */
        .main-panel { margin-left: 260px; flex: 1; padding: 0; width: calc(100% - 260px); display: flex; flex-direction: column; }
        .top-header { display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 20px 40px; border-bottom: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); }
        .header-title h1 { font-size: 22px; font-weight: 800; color: var(--dark); margin: 0 0 4px 0; }
        .header-title p { color: var(--text-muted); font-size: 13px; margin: 0; }
        
        /* Profile Dropdown */
        .profile-dropdown { position: relative; cursor: pointer; }
        .profile-trigger { display: flex; align-items: center; gap: 12px; padding: 6px 12px; border-radius: 10px; transition: background 0.2s; border: 1px solid transparent; }
        .profile-trigger:hover { background: var(--gray-light); border-color: var(--gray-border); }
        .profile-avatar { width: 40px; height: 40px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 16px; box-shadow: 0 2px 4px rgba(46, 125, 50, 0.2);}
        .profile-info strong { display: block; font-size: 13px; color: var(--dark); line-height: 1.2; }
        .profile-info small { font-size: 11px; color: var(--text-muted); font-weight: 500; }
        
        .dropdown-menu { display: none; position: absolute; right: 0; top: 110%; background: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border: 1px solid var(--gray-border); min-width: 200px; z-index: 1000; overflow: hidden; transform-origin: top right; animation: dropAnim 0.2s ease-out forwards; }
        @keyframes dropAnim { 0% { opacity: 0; transform: scale(0.95) translateY(-10px); } 100% { opacity: 1; transform: scale(1) translateY(0); } }
        .profile-dropdown:hover .dropdown-menu { display: block; }
        .dropdown-header { padding: 15px; border-bottom: 1px solid var(--gray-border); background: var(--gray-light); }
        .dropdown-item { padding: 12px 16px; display: flex; align-items: center; gap: 12px; color: #455a64; text-decoration: none; font-size: 13px; font-weight: 500; transition: background 0.2s; border-left: 3px solid transparent;}
        .dropdown-item:hover { background: var(--primary-light); color: var(--primary); border-left-color: var(--primary); }
        .dropdown-item.text-danger { color: #d84315; }
        .dropdown-item.text-danger:hover { background: #fbe9e7; color: #bf360c; border-left-color: #d84315; }

        .content-area { padding: 35px 40px; flex: 1; }

        /* Table & Card UI */
        .card { background: white; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 30px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid var(--gray-border); display: flex; justify-content: space-between; align-items: center; background: #fff; }
        .card-header h2 { font-size: 16px; font-weight: 800; color: var(--dark); margin: 0; }

        .filters-group { display: flex; gap: 15px; }
        .filter-control { padding: 10px 16px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 13px; outline: none; transition: border-color 0.2s, box-shadow 0.2s;}
        .filter-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.15); }

        .modern-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .modern-table th { text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; background: var(--gray-light); border-bottom: 1px solid var(--gray-border); }
        .modern-table td { padding: 16px 24px; border-bottom: 1px solid var(--gray-border); color: #37474f; vertical-align: middle; }
        .modern-table tr:hover td { background-color: #fdfdfd; }

        .badge { padding: 6px 12px; border-radius: 20px; font-weight: 600; font-size: 11px; display: inline-flex; align-items: center; gap: 6px; }
        .badge-UNREAD { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
        .badge-READ { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .badge-RESPONDED { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }

        .btn-action { padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 6px; color: white; transition: all 0.2s; font-family: 'Inter', sans-serif;}
        .btn-view { background: #f8fafc; color: #0ea5e9; border: 1px solid #bae6fd; } .btn-view:hover { background: #e0f2fe; }
        .btn-delete { background: #f8fafc; color: #ef4444; border: 1px solid #fecaca; } .btn-delete:hover { background: #fee2e2; }

        /* Modals */
        .modal { display: none; position: fixed; z-index: 2000; inset: 0; background-color: rgba(0, 0, 0, 0.6); backdrop-filter: blur(3px); align-items: center; justify-content: center; padding: 20px;}
        .modal-content { background-color: #fff; border-radius: 16px; width: 100%; max-width: 650px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); overflow: hidden; animation: dropAnim 0.2s ease-out forwards; display: flex; flex-direction: column; max-height: 90vh;}
        @keyframes dropAnim { 0% { opacity: 0; transform: scale(0.95) translateY(-10px); } 100% { opacity: 1; transform: scale(1) translateY(0); } }
        .modal-header { padding: 20px 25px; border-bottom: 1px solid var(--gray-border); display: flex; justify-content: space-between; align-items: center; background: var(--gray-light); }
        .modal-header h2 { margin: 0; font-size: 18px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 10px;}
        .close-modal { background: none; border: none; font-size: 20px; color: #90a4ae; cursor: pointer; }
        .close-modal:hover { color: #ef4444; }
        
        .modal-body { padding: 25px; background: #ffffff; overflow-y: auto;}
        .modal-footer { padding: 15px 25px; background: #f8fafc; border-top: 1px solid var(--gray-border); display: flex; justify-content: space-between; align-items: center;}

        .message-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; margin-top: 5px; font-size: 14px; line-height: 1.6; color: #334155; white-space: pre-wrap; max-height: 200px; overflow-y: auto;}
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        .info-block span { display: block; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 4px;}
        .info-block div { font-size: 14px; font-weight: 500; color: #1e293b; }

        .reply-section { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--gray-border); }
        .reply-textarea { width: 100%; min-height: 120px; resize: vertical; margin-top: 8px; font-family: inherit; }

        /* Alerts */
        #alert-area { position: fixed; top: 20px; right: 20px; z-index: 3000; width: 350px; }
        .alert { padding: 16px 20px; border-radius: 10px; color: white; margin-bottom: 10px; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px; box-shadow: var(--shadow-sm); animation: slideIn 0.3s ease-out forwards;}
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background-color: #10b981; border: 1px solid #059669;}
        .alert-error { background-color: #ef4444; border: 1px solid #dc2626;}
        
        .btn { padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--dark); }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; }


        /* AUTO FIT + COLLAPSIBLE SIDEBAR */
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
            background:var(--primary-light);
            color:var(--primary);
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
        body.sidebar-collapsed .submenu,
        body.sidebar-collapsed .submenu-toggle-btn{
            display:none !important;
        }
        body.sidebar-collapsed .menu-link{
            justify-content:center;
            padding:14px 10px !important;
            gap:0;
            border-left:0 !important;
        }
        body.sidebar-collapsed .menu-link i,
        body.sidebar-collapsed .finance-main-link i{
            width:22px;
            font-size:18px;
        }
        body.sidebar-collapsed .finance-main-link{
            flex: 0 0 auto;
            justify-content:center;
            gap:0;
        }

        /* INQUIRIES TABLE AUTO FIT */
        .card{ max-width:100%; }
        .card > div[style*="overflow-x"]{ overflow-x:auto !important; }
        .modern-table{
            min-width:760px;
            table-layout:fixed;
        }
        .modern-table th,
        .modern-table td{
            word-break:break-word;
            overflow-wrap:anywhere;
        }
        .modern-table td:last-child{
            white-space:normal;
        }
        .modern-table .btn-action{
            margin:2px 3px 2px 0;
        }
        .filters-group{
            flex-wrap:wrap;
        }

        @media(max-width:768px){
            body{ display:flex; }
            .sidebar{
                left:-260px;
            }
            .main-panel,
            body.sidebar-collapsed .main-panel{
                margin-left:0;
                width:100%;
            }
            body.sidebar-open .sidebar{
                left:0;
                z-index:2100;
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
                flex-wrap:wrap;
            }
            .header-title h1{ font-size:18px; }
            .header-title p{ display:none; }
            .content-area{ padding:18px; }
            .profile-info{ display:none; }
            .card-header{
                align-items:flex-start;
                flex-direction:column;
                gap:14px;
            }
            .filters-group{
                width:100%;
                display:grid;
                grid-template-columns:1fr;
            }
            .filter-control{ width:100%; }

            .card > div[style*="overflow-x"]{
                overflow:visible !important;
            }
            .modern-table,
            .modern-table thead,
            .modern-table tbody,
            .modern-table th,
            .modern-table td,
            .modern-table tr{
                display:block;
                width:100%;
                min-width:0;
                box-sizing:border-box;
            }
            .modern-table thead{ display:none; }
            .modern-table tr{
                background:#fff;
                border:1px solid var(--gray-border);
                border-radius:16px;
                margin:0 0 16px 0;
                overflow:hidden;
                box-shadow:var(--shadow-sm);
            }
            .modern-table tr td{
                border-bottom:1px solid #e2e8f0;
                padding:14px 16px;
            }
            .modern-table tr td:last-child{ border-bottom:0; }
            .modern-table tr td::before{
                content:attr(data-label);
                display:block;
                font-size:11px;
                font-weight:800;
                color:var(--text-muted);
                text-transform:uppercase;
                margin-bottom:7px;
                letter-spacing:.4px;
            }
            .info-grid{ grid-template-columns:1fr; }
            .modal-footer{ flex-direction:column; align-items:stretch; gap:10px; }
            .modal-footer > div{ display:flex; flex-direction:column; gap:10px; }
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
            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-bottom: 12px; letter-spacing: 0.5px;">MAIN MENU</small>
            <a href="admin.php?view=dashboard" class="menu-link"><i class="fa-solid fa-chart-pie"></i> <span class="menu-text">Dashboard</span></a>
            <a href="reservation.php" class="menu-link"><i class="fa-solid fa-file-signature"></i> <span class="menu-text">Reservations</span></a>
            <a href="master_list.php" class="menu-link"><i class="fa-solid fa-map-location-dot"></i> <span class="menu-text">Master List / Map</span></a>
            <a href="admin.php?view=inventory" class="menu-link"><i class="fa-solid fa-plus-circle"></i> <span class="menu-text">Add Property</span></a>
            <div class="menu-dropdown">
                <div class="menu-link dropdown-toggle <?= $isFinancePage ? 'open' : '' ?>">
                    <a href="financial.php" class="finance-main-link" aria-label="Open Financials dashboard">
                        <i class="fa-solid fa-coins"></i>
                        <span class="menu-text">Financials</span>
                    </a>

                    <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)" title="Show/Hide Financial Menu" aria-label="Show or hide Financial menu" aria-expanded="<?= $isFinancePage ? 'true' : 'false' ?>">
                        <i class="fa-solid fa-chevron-down dropdown-arrow" id="financeArrow" style="<?= $isFinancePage ? 'transform: rotate(180deg);' : '' ?>"></i>
                    </button>
                </div>

                <div id="financeSubMenu" class="submenu <?= $isFinancePage ? 'show' : '' ?>">
                    <a href="verify_payments.php" class="menu-link submenu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-circle-check"></i>
                        <span class="menu-text">Verify Payments</span>
                    </a>

                    <a href="payment_tracking.php" class="menu-link submenu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                        <span class="menu-text">Payment Tracking</span>
                    </a>

                    <a href="transaction_history.php" class="menu-link submenu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-list-ul"></i>
                        <span class="menu-text">Ledger List</span>
                    </a>

                    <a href="daily_reconciliation.php" class="menu-link submenu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-scale-balanced"></i>
                        <span class="menu-text">Daily Reconciliation</span>
                    </a>

                    <a href="aging_due_report.php" class="menu-link submenu-link <?= $currentPage == 'aging_due_report.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-clock"></i>
                        <span class="menu-text">Aging / Due Report</span>
                    </a>

                    <a href="contract_status.php" class="menu-link submenu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-file-signature"></i>
                        <span class="menu-text">Contract Status</span>
                    </a>

                    <a href="manual_buyer_entry.php" class="menu-link submenu-link <?= $currentPage == 'manual_buyer_entry.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-user-plus"></i>
                        <span class="menu-text">Manual Buyer Entry</span>
                    </a>

                    <a href="pricing_matrix.php" class="menu-link submenu-link <?= $currentPage == 'pricing_matrix.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-table-list"></i>
                        <span class="menu-text">Pricing Matrix</span>
                    </a>
                </div>
            </div>
            
            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">MANAGEMENT</small>
            <a href="inquiries.php" class="menu-link active"><i class="fa-solid fa-envelope-open-text"></i> <span class="menu-text">Inquiries</span></a>
            <a href="accounts.php" class="menu-link"><i class="fa-solid fa-users-gear"></i> <span class="menu-text">Accounts</span></a>
            <a href="delete_history.php" class="menu-link"><i class="fa-solid fa-trash-can"></i> <span class="menu-text">Delete History</span></a>
            
            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">SYSTEM</small>
            <a href="index.php" class="menu-link" target="_blank"><i class="fa-solid fa-globe"></i> <span class="menu-text">View Website</span></a>
        </div>
    </div>
    
    <div class="main-panel">
        <div class="top-header">
            <div class="top-header-left">
                <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="header-title">
                    <h1>Contact Inquiries</h1>
                    <p>Manage messages sent from the public contact page.</p>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 20px;">
                
                <?php include 'includes/admin_notification_bell.php'; ?>
                
                <div style="width: 1px; height: 30px; background: var(--gray-border);"></div>

                <div class="profile-dropdown">
                    <div class="profile-trigger">
                        <div class="profile-avatar"><?= strtoupper(substr($_SESSION['fullname'] ?? 'A', 0, 1)) ?></div>
                        <div class="profile-info">
                            <strong><?= htmlspecialchars($_SESSION['fullname'] ?? 'Administrator') ?></strong>
                            <small><?= $_SESSION['role'] ?? 'System Admin' ?> <i class="fa-solid fa-chevron-down" style="font-size: 9px; margin-left: 3px;"></i></small>
                        </div>
                    </div>
                    
                    <div class="dropdown-menu">
                        <div class="dropdown-header">
                            <strong style="display: block; font-size: 13px; color: var(--dark);">JEJ Admin System</strong>
                            <span style="font-size: 11px; color: var(--text-muted);">Logged in successfully</span>
                        </div>
                        <a href="audit_logs.php" class="dropdown-item"><i class="fa-solid fa-clock-rotate-left" style="width:16px;"></i> System Audit Logs</a>
                        <a href="settings.php" class="dropdown-item"><i class="fa-solid fa-gear" style="width:16px;"></i> Account Settings</a>
                        <div style="height: 1px; background: var(--gray-border); margin: 5px 0;"></div>
                        <a href="logout.php" class="dropdown-item text-danger"><i class="fa-solid fa-arrow-right-from-bracket" style="width:16px;"></i> Secure Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="content-area">
            <div id="alert-area"></div>

            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-inbox" style="color: var(--primary); margin-right: 8px;"></i> Inbox</h2>
                    
                    <form method="GET" class="filters-group">
                        <input type="text" name="search" class="filter-control" placeholder="Search sender..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                        <select name="status" class="filter-control" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="UNREAD" <?= ($_GET['status'] ?? '') == 'UNREAD' ? 'selected' : '' ?>>Unread</option>
                            <option value="READ" <?= ($_GET['status'] ?? '') == 'READ' ? 'selected' : '' ?>>Read</option>
                            <option value="RESPONDED" <?= ($_GET['status'] ?? '') == 'RESPONDED' ? 'selected' : '' ?>>Responded</option>
                        </select>
                    </form>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Date Sent</th>
                                <th>Sender</th>
                                <th>Subject</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($inquiries)): ?>
                                <?php foreach($inquiries as $row): ?>
                                    <tr style="<?= $row['status'] == 'UNREAD' ? 'background: #f8fafc; font-weight:600;' : '' ?>">
                                        <td data-label="Status">
                                            <span class="badge badge-<?= $row['status'] ?>">
                                                <?= $row['status'] == 'UNREAD' ? '<i class="fa-solid fa-circle"></i> ' : '' ?><?= $row['status'] ?>
                                            </span>
                                        </td>
                                        <td data-label="Date Sent" style="color: var(--text-muted); font-size: 13px;">
                                            <?= date('M d, Y h:i A', strtotime($row['created_at'])) ?>
                                        </td>
                                        <td data-label="Sender">
                                            <div style="color: #1e293b;"><?= htmlspecialchars($row['name']) ?></div>
                                            <div style="color: var(--text-muted); font-size: 12px;"><i class="fa-regular fa-envelope"></i> <?= htmlspecialchars($row['email']) ?></div>
                                        </td>
                                        <td data-label="Subject"><?= htmlspecialchars($row['subject'] ?: 'No Subject') ?></td>
                                        <td data-label="Actions">
                                            <button class="btn-action btn-view" onclick="openMessageModal(<?= $row['id'] ?>)">
                                                <i class="fa-regular fa-eye"></i> View & Reply
                                            </button>
                                            <button class="btn-action btn-delete" onclick="deleteInquiry(<?= $row['id'] ?>)">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                        <i class="fa-solid fa-inbox" style="font-size: 30px; margin-bottom: 10px; display: block; color: #cbd5e1;"></i>
                                        No inquiries found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="messageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-regular fa-envelope-open" style="color: var(--primary);"></i> Inquiry Details</h2>
                <button type="button" class="close-modal" onclick="closeModal('messageModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div class="modal-body">
                <div class="info-grid">
                    <div class="info-block">
                        <span>Sender Name</span>
                        <div id="v_name">...</div>
                    </div>
                    <div class="info-block">
                        <span>Email Address</span>
                        <div id="v_email">...</div>
                    </div>
                    <div class="info-block">
                        <span>Date Received</span>
                        <div id="v_date">...</div>
                    </div>
                    <div class="info-block">
                        <span>Subject</span>
                        <div id="v_subject">...</div>
                    </div>
                </div>

                <div class="info-block" style="margin-top: 15px;">
                    <span>Message Body</span>
                    <div class="message-box" id="v_message">...</div>
                </div>
                
                <div class="reply-section">
                    <div class="info-block">
                        <span><i class="fa-solid fa-reply"></i> Send an Email Reply</span>
                        <textarea id="reply_message" class="filter-control reply-textarea" placeholder="Type your response to the user here..."></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <input type="hidden" id="current_inquiry_id">
                
                <div>
                    <button type="button" class="btn" style="background:#f1f5f9; border: 1px solid #cbd5e1; color:#475569;" onclick="markAsResponded()">
                        <i class="fa-solid fa-check"></i> Mark Responded
                    </button>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn" style="background:#fff; border: 1px solid #e2e8f0; color:#64748b;" onclick="closeModal('messageModal')">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btnSendReply" onclick="sendReplyEmail()">
                        <i class="fa-solid fa-paper-plane"></i> Send Reply
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleFinanceMenu(event) {
            event.preventDefault();
            event.stopPropagation();

            const submenu = document.getElementById('financeSubMenu');
            const arrow = document.getElementById('financeArrow');
            const button = event.currentTarget;

            if (!submenu) return;

            submenu.classList.toggle('show');
            const isOpen = submenu.classList.contains('show');

            if (arrow) {
                arrow.style.transform = isOpen ? 'rotate(180deg)' : 'rotate(0deg)';
            }
            if (button) {
                button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }
        }

        function toggleSidebar() {
            if (window.innerWidth <= 768) {
                document.body.classList.toggle('sidebar-open');
            } else {
                document.body.classList.toggle('sidebar-collapsed');
            }
        }

        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && document.body.classList.contains('sidebar-open')) {
                const sidebar = document.querySelector('.sidebar');
                const toggle = document.querySelector('.sidebar-toggle');
                if (sidebar && toggle && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                    document.body.classList.remove('sidebar-open');
                }
            }
        });

        function openMessageModal(id) {
            $('#current_inquiry_id').val(id);
            $('#reply_message').val(''); // Clear old replies
            
            $.ajax({
                url: 'inquiries.php',
                method: 'POST',
                data: { action: 'get_inquiry', id: id },
                success: function(res) {
                    if(res.status === 'success'){
                        const data = res.data;
                        $('#v_name').text(data.name);
                        $('#v_email').html(`<a href="mailto:${data.email}" style="color:#0ea5e9; text-decoration:none;">${data.email}</a>`);
                        $('#v_date').text(new Date(data.created_at).toLocaleString());
                        $('#v_subject').text(data.subject || 'No Subject');
                        $('#v_message').text(data.message);
                        
                        $('#messageModal').css('display', 'flex').hide().fadeIn(300);
                    } else {
                        showAlert('error', res.message);
                    }
                }
            });
        }
        
        // New Function to Handle Direct Emailing via AJAX
        function sendReplyEmail() {
            const id = $('#current_inquiry_id').val();
            const replyMsg = $('#reply_message').val().trim();
            
            if(!replyMsg) {
                showAlert('error', 'Please enter a reply message before sending.');
                $('#reply_message').focus();
                return;
            }

            // Update UI to show loading state
            const btn = $('#btnSendReply');
            const originalText = btn.html();
            btn.html('<i class="fa-solid fa-circle-notch fa-spin"></i> Sending...');
            btn.prop('disabled', true);

            $.ajax({
                url: 'inquiries.php',
                method: 'POST',
                data: { 
                    action: 'send_reply', 
                    id: id, 
                    reply_message: replyMsg 
                },
                success: function(res) {
                    btn.html(originalText);
                    btn.prop('disabled', false);
                    
                    if(res.status === 'success'){
                        showAlert('success', res.message);
                        $('#reply_message').val(''); // Clear the textarea
                        setTimeout(() => location.reload(), 1500); // Reload to update status badges
                    } else {
                        showAlert('error', res.message);
                    }
                },
                error: function() {
                    btn.html(originalText);
                    btn.prop('disabled', false);
                    showAlert('error', 'Network error occurred while trying to send the email.');
                }
            });
        }

        // Just mark as responded without emailing
        function markAsResponded() {
            const id = $('#current_inquiry_id').val();
            $.ajax({
                url: 'inquiries.php',
                method: 'POST',
                data: { action: 'update_status', id: id, status: 'RESPONDED' },
                success: function(res) {
                    if(res.status === 'success'){
                        showAlert('success', res.message);
                        setTimeout(() => location.reload(), 1000);
                    }
                }
            });
        }

        function deleteInquiry(id) {
            if(confirm('Are you sure you want to permanently delete this message?')) {
                $.ajax({
                    url: 'inquiries.php',
                    method: 'POST',
                    data: { action: 'delete', id: id },
                    success: function(res) {
                        if(res.status === 'success'){
                            showAlert('success', res.message);
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showAlert('error', res.message);
                        }
                    }
                });
            }
        }

        function closeModal(id) {
            $(`#${id}`).fadeOut(200);
            // Reload page on close to update the "UNREAD" to "READ" badge
            setTimeout(() => location.reload(), 200); 
        }

        function showAlert(type, message) {
            const isSuccess = type === 'success';
            const icon = isSuccess ? 'fa-check-circle' : 'fa-circle-exclamation';
            const alertHtml = `<div class="alert ${isSuccess ? 'alert-success' : 'alert-error'}"><i class="fa-solid ${icon}" style="font-size: 16px;"></i> ${message}</div>`;
            $('#alert-area').html(alertHtml);
            setTimeout(() => $('.alert').fadeOut(500, function() { $(this).remove(); }), 3500);
        }

        window.onclick = function(event) {
            if ($(event.target).hasClass('modal')) {
                closeModal(event.target.id);
            }
        }
    </script>
</body>
</html>
