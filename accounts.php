<?php
// accounts.php - JEJ Top Priority Corporation Admin Accounts Management

require_once 'config.php';

// CASHIER BLOCK
if ($_SESSION['role'] === 'CASHIER') {
    header("Location: financial.php");
    exit();
}

// Role Access
requireRole(['SUPER ADMIN', 'ADMIN', 'MANAGER']);

// Manager permission is checked below using canView().
// This allows a Manager to access this page if they have ANY user/account permission.

$active_page = "accounts";
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
$current_role = $_SESSION['role'];

// 2. Fetch Manager Permissions globally for the Sidebar
$sidebar_perms = [];
if ($_SESSION['role'] == 'MANAGER') {
    $p_stmt = $conn->prepare("SELECT * FROM manager_permissions WHERE user_id = ?");
    $p_stmt->bind_param("i", $_SESSION['user_id']);
    $p_stmt->execute();
    $sidebar_perms = $p_stmt->get_result()->fetch_assoc() ?: [];
}

// 3. Permission Helper Function
function canView($module) {
    global $sidebar_perms;
    if ($_SESSION['role'] == 'SUPER ADMIN' || $_SESSION['role'] == 'ADMIN') return true;
    
    if ($_SESSION['role'] == 'MANAGER') {
        if (empty($sidebar_perms)) return false; // No permissions set yet
        
        $prefix = explode('_', $module)[0];
        if (!empty($sidebar_perms[$prefix . '_full'])) return true; // Has full access to category
        
        return !empty($sidebar_perms[$module]); // Has specific access
    }
    return false;
}

// 4. Strict Page-Level Protection
// If a Manager has no User management permissions, kick them to dashboard
if ($_SESSION['role'] == 'MANAGER') {
    if (!canView('usr_buyers') && !canView('usr_admins') && !canView('usr_promote')) {
        header("Location: admin.php?view=dashboard");
        exit();
    }
}

// --- HANDLING AJAX POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    header('Content-Type: application/json');

    // Create Staff Account
    if ($action == 'create_account') {
        if (!canView('usr_buyers') && !canView('usr_admins') && !canView('usr_promote')) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized to create accounts.']);
            exit();
        }
        $fullname = $_POST['fullname'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $password = $_POST['password'];
        $role = $_POST['role'];

        if (in_array($role, ['ADMIN', 'SUPER ADMIN']) && $current_role != 'SUPER ADMIN') {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized to create high-level roles.']);
            exit();
        }

        $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $chk->bind_param("s", $email);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Email address already registered.']);
            exit();
        }

        $hashed_pass = md5($password); 
        $stmt = $conn->prepare("INSERT INTO users (fullname, email, phone, password, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $fullname, $email, $phone, $hashed_pass, $role);

        if ($stmt->execute()) {
            add_audit_log($conn, $_SESSION['user_id'], 'Created Account', 'Created account for: ' . $fullname . ' Role: ' . $role, 'users', $conn->insert_id);
            echo json_encode(['status' => 'success', 'message' => 'Account created successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error during creation.']);
        }
        exit();
    }

    // Update Account Basic Info
    if ($action == 'update_account') {
        if (!canView('usr_buyers') && !canView('usr_admins')) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized to update accounts.']);
            exit();
        }
        $user_id = $_POST['user_id'];
        $fullname = $_POST['fullname'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $role = $_POST['role'];

        if($user_id == $_SESSION['user_id']){
            $role = $_SESSION['role']; 
        }

        $stmt = $conn->prepare("UPDATE users SET fullname=?, email=?, phone=?, role=? WHERE id=?");
        $stmt->bind_param("ssssi", $fullname, $email, $phone, $role, $user_id);

        if ($stmt->execute()) {
            add_audit_log($conn, $_SESSION['user_id'], 'Updated Account', 'Updated account ID: ' . $user_id . ' Role: ' . $role, 'users', $user_id);
            echo json_encode(['status' => 'success', 'message' => 'Account updated successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error during update.']);
        }
        exit();
    }
    
    // Quick Promote / Demote
    if ($action == 'change_role') {
        if (!canView('usr_promote')) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized to promote/demote users.']);
            exit();
        }
        $user_id = $_POST['user_id'];
        $new_role = $_POST['new_role'];

        if($user_id == $_SESSION['user_id']){
            echo json_encode(['status' => 'error', 'message' => 'Cannot change your own role.']);
            exit();
        }

        $stmt = $conn->prepare("UPDATE users SET role=? WHERE id=?");
        $stmt->bind_param("si", $new_role, $user_id);
        if ($stmt->execute()) {
            add_audit_log($conn, $_SESSION['user_id'], 'Changed User Role', 'Changed user ID ' . $user_id . ' to ' . $new_role, 'users', $user_id);
            echo json_encode(['status' => 'success', 'message' => "User successfully changed to $new_role!"]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error during role update.']);
        }
        exit();
    }

    // Save Manager Permissions
    if ($action == 'save_manager_permissions') {
        if (!canView('usr_promote') && !canView('usr_admins')) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized to save manager permissions.']);
            exit();
        }
        $user_id = $_POST['user_id'];
        $perms = [
            'inv_full', 'inv_property', 'inv_status', 'inv_price',
            'res_full', 'res_process', 'res_status', 'res_terms',
            'fin_full', 'fin_process', 'fin_review', 'fin_checks', 'fin_accounts',
            'usr_full', 'usr_buyers', 'usr_promote', 'usr_admins'
        ];

        $vals = [$user_id];
        $types = "i";

        foreach($perms as $p){
            $vals[] = isset($_POST[$p]) ? 1 : 0;
            $types .= "i";
        }

        $sql = "INSERT INTO manager_permissions (user_id, " . implode(", ", $perms) . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE ";
        $update_parts = [];
        foreach($perms as $p){ $update_parts[] = "$p = VALUES($p)"; }
        $sql .= implode(", ", $update_parts);

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Prepare error: ' . $conn->error]);
            exit();
        }

        $bind_values = [];
        $bind_values[] = $types;
        foreach ($vals as $key => $value) {
            $bind_values[] = &$vals[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_values);

        if ($stmt->execute()) {
            add_audit_log($conn, $_SESSION['user_id'], 'Updated Manager Permissions', 'Updated permissions for manager user ID: ' . $user_id, 'manager_permissions', $user_id);
            echo json_encode(['status' => 'success', 'message' => 'Permissions saved successfully!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error saving permissions.']);
        }
        exit();
    }
// Delete Account
if ($action == 'delete_account') {
    $user_id = intval($_POST['user_id']);

    if ($user_id == $_SESSION['user_id']) {
        echo json_encode(['status' => 'error', 'message' => 'You cannot delete your own account.']);
        exit();
    }

    if ($current_role != 'SUPER ADMIN' && $current_role != 'ADMIN') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized to delete accounts.']);
        exit();
    }

    // Optional: delete manager permissions first
    $perm_stmt = $conn->prepare("DELETE FROM manager_permissions WHERE user_id = ?");
    $perm_stmt->bind_param("i", $user_id);
    $perm_stmt->execute();

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);

    if ($stmt->execute()) {
        add_audit_log($conn, $_SESSION['user_id'], 'Deleted Account', 'Deleted user account ID: ' . $user_id, 'users', $user_id);
        echo json_encode(['status' => 'success', 'message' => 'Account deleted successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error during deletion.']);
    }
    exit();
}
    // Get Account Details & Permissions
    if($action == 'get_account_details'){
        $id = $_POST['id'];
        
        $u_stmt = $conn->prepare("SELECT id, fullname, email, phone, role FROM users WHERE id = ?");
        $u_stmt->bind_param("i", $id);
        $u_stmt->execute();
        $user = $u_stmt->get_result()->fetch_assoc();

        if(!$user) { echo json_encode(['status' => 'error']); exit(); }

        $permissions = null;
        if($user['role'] == 'MANAGER'){
            $p_stmt = $conn->prepare("SELECT * FROM manager_permissions WHERE user_id = ?");
            $p_stmt->bind_param("i", $id);
            $p_stmt->execute();
            $permissions = $p_stmt->get_result()->fetch_assoc();
        }

        echo json_encode(['status' => 'success', 'user' => $user, 'permissions' => $permissions]);
        exit();
    }
}

// Fetch Accounts List
$where_clauses = ["role IN ('SUPER ADMIN','ADMIN','MANAGER','CASHIER','AGENT')"];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $s = $conn->real_escape_string($_GET['search']);
    $where_clauses[] = "(fullname LIKE '%$s%' OR email LIKE '%$s%' OR phone LIKE '%$s%')";
}
if (isset($_GET['role']) && !empty($_GET['role'])) {
    $allowed_staff_roles = ['SUPER ADMIN','ADMIN','MANAGER','CASHIER','AGENT'];
    $r = strtoupper(trim($_GET['role']));
    if (in_array($r, $allowed_staff_roles, true)) {
        $r = $conn->real_escape_string($r);
        $where_clauses[] = "role = '$r'";
    }
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

$accounts_query = "SELECT id, fullname, email, phone, role, created_at FROM users $where_sql ORDER BY created_at DESC";
$accounts_result = $conn->query($accounts_query);

// Helper function for Avatar colors based on ID
function getAvatarColor($id) {
    $colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#f43f5e', '#0ea5e9', '#14b8a6'];
    return $colors[$id % count($colors)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Accounts | JEJ Admin</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.0/jquery.min.js"></script>
    <style>
        :root {
            /* NATURE GREEN THEME (Primary Structure from Financials) */
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

        * { box-sizing: border-box; }
        body { background-color: #fafcf9; display: flex; min-height: 100vh; overflow-x: hidden; font-family: 'Inter', sans-serif; color: #37474f; margin: 0; }

        /* --- Sidebar Styling (Identical to financial.php) --- */
        .sidebar { width: 260px; background: #ffffff; border-right: 1px solid var(--gray-border); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; box-shadow: var(--shadow-sm); }
        .brand-box { padding: 25px; border-bottom: 1px solid var(--gray-border); display: flex; align-items: center; gap: 12px; }
        .sidebar-menu { padding: 20px 15px; flex: 1; overflow-y: auto; }
        .menu-link { display: flex; align-items: center; gap: 12px; padding: 12px 18px; color: #455a64; text-decoration: none; font-weight: 500; font-size: 14px; border-radius: 10px; margin-bottom: 6px; transition: all 0.2s ease; }
        .menu-link:hover { background: var(--gray-light); color: var(--primary); }
        .menu-link.active { background: var(--primary-light); color: var(--primary); font-weight: 600; border-left: 4px solid var(--primary); }
        .menu-link.open { background: var(--primary-light); color: var(--primary); font-weight: 600; }
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
        .finance-main-link {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            color: inherit;
            text-decoration: none;
            min-width: 0;
        }
        .finance-main-link span,
        .submenu-link span,
        .menu-text { display: inline; }
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
            padding-left: 14px;
            font-size: 13px;
        }

        /* --- Main Panel & Header (Identical to financial.php) --- */
        .main-panel { margin-left: 260px; flex: 1; padding: 0; width: calc(100% - 260px); display: flex; flex-direction: column; }
        @media (max-width: 992px) { .main-panel { margin-left: 0; width: 100%; } .sidebar { display: none; } }

        .top-header { display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 20px 40px; border-bottom: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); z-index: 50; }
        .header-title h1 { font-size: 22px; font-weight: 800; color: var(--dark); margin: 0 0 4px 0; letter-spacing: -0.5px;}
        .header-title p { color: var(--text-muted); font-size: 13px; margin: 0; }

        /* Profile Dropdown */
        .profile-dropdown { position: relative; cursor: pointer; }
        .profile-trigger { display: flex; align-items: center; gap: 12px; padding: 6px 12px; border-radius: 10px; transition: background 0.2s; border: 1px solid transparent; }
        .profile-trigger:hover { background: var(--gray-light); border-color: var(--gray-border); }
        .profile-avatar { width: 40px; height: 40px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 16px; box-shadow: 0 2px 4px rgba(46, 125, 50, 0.2);}
        .profile-info strong { display: block; font-size: 13px; color: var(--dark); line-height: 1.2; }
        .profile-info small { font-size: 11px; color: var(--text-muted); font-weight: 500; }
        
        .dropdown-menu { display: none; position: absolute; right: 0; top: 110%; background: white; border-radius: 12px; box-shadow: var(--shadow-lg); border: 1px solid var(--gray-border); min-width: 200px; z-index: 1000; overflow: hidden; transform-origin: top right; animation: dropAnim 0.2s ease-out forwards; }
        @keyframes dropAnim { 0% { opacity: 0; transform: scale(0.95) translateY(-10px); } 100% { opacity: 1; transform: scale(1) translateY(0); } }
        .profile-dropdown:hover .dropdown-menu { display: block; }
        .dropdown-header { padding: 15px; border-bottom: 1px solid var(--gray-border); background: var(--gray-light); }
        .dropdown-item { padding: 12px 16px; display: flex; align-items: center; gap: 12px; color: #455a64; text-decoration: none; font-size: 13px; font-weight: 500; transition: background 0.2s; border-left: 3px solid transparent;}
        .dropdown-item:hover { background: var(--primary-light); color: var(--primary); border-left-color: var(--primary); }
        .dropdown-item.text-danger { color: #d84315; }
        .dropdown-item.text-danger:hover { background: #fbe9e7; color: #bf360c; border-left-color: #d84315; }

        /* Content Area */
        .content-area { padding: 35px 40px; flex: 1; }

        .btn { padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 8px; font-family: inherit; transition: all 0.2s; box-shadow: var(--shadow-sm); }
        .btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--dark); }

        /* --- Card & Table --- */
        .card { background: white; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 30px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid var(--gray-border); display: flex; justify-content: space-between; align-items: center; background: #fff; flex-wrap: wrap; gap: 15px;}
        .card-header h2 { font-size: 16px; font-weight: 800; color: var(--dark); margin: 0; }

        .filters-group { display: flex; gap: 15px; flex-wrap: wrap;}
        .filter-control { padding: 10px 16px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 13px; background: #fff; color: #475569; transition: all 0.2s; outline: none; }
        .filter-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.15); }
        select.filter-control { padding-right: 35px; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; }

        .modern-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .modern-table th { text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; background: var(--gray-light); border-bottom: 1px solid var(--gray-border); letter-spacing: 0.5px;}
        .modern-table td { padding: 16px 24px; border-bottom: 1px solid var(--gray-border); color: #37474f; font-size: 14px; vertical-align: middle; }
        .modern-table tr:hover td { background-color: #fdfdfd; }
        .modern-table tr:last-child td { border-bottom: none; }

        /* User Info styling */
        .user-info { display: flex; align-items: center; gap: 15px; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; display: flex; justify-content: center; align-items: center; color: white; font-weight: 700; font-size: 16px; box-shadow: var(--shadow-sm); }
        
        /* =========================================
   ROLE BADGES
========================================= */

.badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 14px;
    border-radius:999px;
    font-weight:700;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.4px;
    border:1px solid transparent;
    min-width:95px;
    text-align:center;
}

/* SUPER ADMIN */
.role-super-admin{
    background:#fee2e2;
    color:#b91c1c;
    border-color:#fecaca;
}

/* ADMIN */
.role-admin{
    background:#dbeafe;
    color:#0369a1;
    border-color:#bae6fd;
}

/* MANAGER */
.role-manager{
    background:#dcfce7;
    color:#166534;
    border-color:#bbf7d0;
}

/* CASHIER */
.role-cashier{
    background:#fef3c7;
    color:#92400e;
    border-color:#fde68a;
}

/* AGENT */
.role-agent{
    background:#ede9fe;
    color:#6d28d9;
    border-color:#ddd6fe;
}

/* BUYER */
.role-buyer{
    background:#f1f5f9;
    color:#334155;
    border-color:#cbd5e1;
}

/* DEFAULT */
.role-default{
    background:#e5e7eb;
    color:#374151;
    border-color:#d1d5db;
}

        /* Table Action Buttons */
        .action-btns { display: flex; gap: 6px; flex-wrap: wrap;}
        .btn-action { padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 6px; color: white; transition: all 0.2s; font-family: 'Inter', sans-serif;}
        .btn-action:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }
        .btn-edit { background: #f8fafc; color: #0ea5e9; border: 1px solid #bae6fd; } .btn-edit:hover { background: #e0f2fe; }
        .btn-delete { background: #f8fafc; color: #ef4444; border: 1px solid #fecaca; } .btn-delete:hover { background: #fee2e2; }
        .btn-promote { background: #10b981; } .btn-promote:hover { background: #059669; }
        .btn-demote { background: #64748b; } .btn-demote:hover { background: #475569; }
        .btn-perms { background: #f59e0b; } .btn-perms:hover { background: #d97706; }

        /* --- Modals --- */
        .modal { display: none; position: fixed; z-index: 2000; inset: 0; background-color: rgba(0, 0, 0, 0.6); backdrop-filter: blur(3px); align-items: center; justify-content: center; padding: 20px;}
        .modal-content { background-color: #fff; border-radius: 16px; width: 100%; max-width: 600px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); overflow: hidden; animation: dropAnim 0.2s ease-out forwards; }
        
        .modal-header { padding: 20px 25px; border-bottom: 1px solid var(--gray-border); display: flex; justify-content: space-between; align-items: center; background: var(--gray-light); }
        .modal-header h2 { margin: 0; font-size: 18px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 10px;}
        .close-modal { background: none; border: none; font-size: 20px; color: #90a4ae; cursor: pointer; transition: 0.2s; padding: 0;}
        .close-modal:hover { color: #ef4444; transform: scale(1.1); }
        
        .modal-body { padding: 25px; background: #ffffff;}
        .modal-footer { padding: 15px 25px; background: #f8fafc; border-top: 1px solid var(--gray-border); text-align: right; }

        /* Forms */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group.full-width { grid-column: span 2; }
        .form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #455a64; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #ffffff; transition: 0.2s; outline: none; box-sizing: border-box; font-family: inherit;}
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.15); }
        
        /* Permissions Modal Specifics */
        .modal-permissions { max-width: 800px; }
        .perm-header-summary { display: flex; gap: 15px; align-items: center; background: var(--primary-light); padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; border: 1px solid var(--gray-border); }
        .perm-header-summary h3 { margin: 0; font-size: 16px; color: var(--dark); }
        .permissions-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        
        .perm-section { border: 1px solid var(--gray-border); border-radius: 12px; overflow: hidden; background: white;}
        .perm-section-header { padding: 12px 15px; background: var(--gray-light); border-bottom: 1px solid var(--gray-border); font-weight: 700; color: var(--dark); font-size: 14px;}
        .perm-list { padding: 15px; display: flex; flex-direction: column; gap: 10px;}
        
        .checkbox-container { display: flex; align-items: center; position: relative; cursor: pointer; font-size: 13px; font-weight: 500; user-select: none; color: #455a64;}
        .checkbox-container input { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
        .checkmark { height: 20px; width: 20px; background-color: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; margin-right: 12px; transition: all 0.2s; display: flex; justify-content: center; align-items: center;}
        .checkbox-container:hover input ~ .checkmark { background-color: #e2e8f0; }
        .checkbox-container input:checked ~ .checkmark { background-color: var(--primary); border-color: var(--primary); }
        .checkmark:after { content: "\f00c"; font-family: "Font Awesome 6 Free"; font-weight: 900; color: white; display: none; font-size: 11px; }
        .checkbox-container input:checked ~ .checkmark:after { display: block; }
        
        .perm-item.full-access { border-bottom: 1px solid var(--gray-border); padding-bottom: 10px; margin-bottom: 5px; }
        .perm-item.full-access .checkbox-container { font-weight: 700; color: var(--dark); }

        /* Alerts */
        #alert-area { position: fixed; top: 20px; right: 20px; z-index: 3000; width: 350px; }
        .alert { padding: 16px 20px; border-radius: 10px; color: white; margin-bottom: 10px; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 14px; box-shadow: var(--shadow-sm); animation: slideIn 0.3s ease-out forwards;}
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .alert-success { background-color: #10b981; border: 1px solid #059669;}
        .alert-error { background-color: #ef4444; border: 1px solid #dc2626;}

    

/* FIX: AUTO FIT + COLLAPSIBLE SIDEBAR + HAMBURGER */
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

.sidebar-toggle:hover{ background:var(--gray-border); }

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
body.sidebar-collapsed .sidebar-menu small,
body.sidebar-collapsed .menu-text,
body.sidebar-collapsed .submenu,
body.sidebar-collapsed .submenu-toggle-btn,
body.sidebar-collapsed .finance-main-link span,
body.sidebar-collapsed .submenu-link span{
    display:none !important;
}

body.sidebar-collapsed .menu-link,
body.sidebar-collapsed .dropdown-toggle{
    justify-content:center;
    padding:14px 10px;
    gap:0;
    font-size:0;
    border-left:0 !important;
}

body.sidebar-collapsed .menu-link i,
body.sidebar-collapsed .finance-main-link i{
    width:22px;
    font-size:18px;
    margin:0;
}

body.sidebar-collapsed .finance-main-link{
    justify-content:center;
    padding:0;
    gap:0;
    flex:0 0 auto;
}

body.sidebar-collapsed .menu-link .badge,
body.sidebar-collapsed .menu-link span:not(.badge){
    display:none !important;
}

.content-area{
    max-width:100%;
    box-sizing:border-box;
}

.card > div[style*="overflow-x: auto"]{
    width:100%;
    overflow-x:auto !important;
}

.modern-table{
    min-width:850px;
    table-layout:auto;
}

.modern-table th,
.modern-table td{
    word-break:break-word;
    overflow-wrap:anywhere;
}

.filters-group{
    max-width:100%;
}

.filters-group .filter-control{
    min-width:180px;
}

@media (max-width: 992px){
    .sidebar{
        display:flex !important;
        position:fixed !important;
        left:-260px;
        top:0;
        width:260px;
        height:100vh;
        z-index:1001;
        transition:left .25s ease;
    }

    .main-panel,
    body.sidebar-collapsed .main-panel{
        margin-left:0 !important;
        width:100% !important;
    }

    body.sidebar-open .sidebar{
        left:0;
    }

    body.sidebar-open::after{
        content:"";
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.35);
        z-index:1000;
    }

    .top-header{
        padding:15px 18px;
        gap:12px;
        align-items:flex-start;
    }

    .header-title h1{ font-size:18px; }
    .header-title p{ display:none; }
    .profile-info{ display:none; }
    .content-area{ padding:18px; }
    .card-header{ align-items:flex-start; }
    .filters-group{ width:100%; }
    .filters-group .filter-control{ width:100%; min-width:0; }
    .permissions-grid{ grid-template-columns:1fr; }
    .form-grid{ grid-template-columns:1fr; }
    .form-group.full-width{ grid-column:span 1; }
}

@media (max-width: 768px){
    .card > div[style*="overflow-x: auto"]{
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

    .modern-table td{
        border-bottom:1px solid #e2e8f0;
        padding:14px 16px;
    }

    .modern-table td:last-child{ border-bottom:0; }

    .modern-table td::before{
        content:attr(data-label);
        display:block;
        font-size:11px;
        font-weight:800;
        color:var(--text-muted);
        text-transform:uppercase;
        margin-bottom:7px;
        letter-spacing:.4px;
    }

    .user-info{
        align-items:flex-start;
    }

    .action-btns{
        flex-direction:column;
    }

    .btn-action{
        width:100%;
        justify-content:center;
    }
}

/* FINAL MOBILE POLISH: Staff Accounts */
@media (max-width:768px){
    html,
    body{
        width:100% !important;
        max-width:100% !important;
        overflow-x:hidden !important;
    }

    body{
        background:#f7fbf6 !important;
    }

    .main-panel,
    body.sidebar-collapsed .main-panel{
        margin-left:0 !important;
        width:100% !important;
        max-width:100% !important;
    }

    .top-header{
        width:100% !important;
        padding:18px 16px !important;
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:18px !important;
        align-items:stretch !important;
        min-height:0 !important;
    }

    .top-header-left{
        width:100% !important;
        display:grid !important;
        grid-template-columns:54px minmax(0,1fr) !important;
        align-items:center !important;
        gap:14px !important;
    }

    .sidebar-toggle{
        width:54px !important;
        height:54px !important;
        border-radius:15px !important;
        font-size:20px !important;
    }

    .header-title h1{
        font-size:clamp(28px,8vw,40px) !important;
        line-height:1.05 !important;
        margin:0 !important;
        color:var(--dark) !important;
        white-space:normal !important;
    }

    .header-title p{
        display:none !important;
    }

    .top-header > div[style*="display: flex"]{
        width:100% !important;
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:16px !important;
        align-items:stretch !important;
        justify-content:stretch !important;
        margin:0 !important;
    }

    .top-header .btn-primary{
        width:100% !important;
        min-height:58px !important;
        border-radius:18px !important;
        justify-content:center !important;
        font-size:15px !important;
    }

    .top-header .jej-notification-wrap,
    .top-header .profile-dropdown{
        justify-self:center !important;
    }

    .top-header .jej-notification-button{
        width:58px !important;
        height:58px !important;
        border-radius:999px !important;
        font-size:18px !important;
    }

    .profile-trigger{
        min-height:58px !important;
        padding:6px 12px !important;
        border-radius:999px !important;
        background:#fff !important;
        border:1px solid var(--gray-border) !important;
    }

    .profile-avatar{
        width:50px !important;
        height:50px !important;
        min-width:50px !important;
        font-size:18px !important;
    }

    .profile-info{
        display:none !important;
    }

    .content-area{
        width:100% !important;
        padding:18px 16px !important;
    }

    .card{
        width:100% !important;
        border-radius:22px !important;
        margin-bottom:22px !important;
        overflow:hidden !important;
    }

    .card-header{
        padding:22px !important;
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:18px !important;
    }

    .card-header h2{
        font-size:clamp(22px,6.5vw,34px) !important;
        line-height:1.1 !important;
        color:var(--dark) !important;
    }

    .filters-group{
        width:100% !important;
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:14px !important;
    }

    .filters-group .filter-control{
        width:100% !important;
        min-width:0 !important;
        min-height:58px !important;
        border-radius:16px !important;
        font-size:16px !important;
    }

    .card > div[style*="overflow-x: auto"]{
        overflow:visible !important;
    }

    .modern-table,
    .modern-table thead,
    .modern-table tbody,
    .modern-table tr,
    .modern-table th,
    .modern-table td{
        display:block !important;
        width:100% !important;
        min-width:0 !important;
    }

    .modern-table{
        border-collapse:separate !important;
        border-spacing:0 !important;
    }

    .modern-table thead{
        display:none !important;
    }

    .modern-table tr{
        margin:0 !important;
        border:1px solid var(--gray-border) !important;
        border-left:0 !important;
        border-right:0 !important;
        border-radius:0 !important;
        box-shadow:none !important;
        background:#fff !important;
    }

    .modern-table tr + tr{
        border-top:0 !important;
    }

    .modern-table td{
        padding:20px 22px !important;
        border-bottom:1px solid #e2e8f0 !important;
    }

    .modern-table td:last-child{
        border-bottom:0 !important;
    }

    .modern-table td::before{
        content:attr(data-label);
        display:block !important;
        margin-bottom:12px !important;
        color:#64748b !important;
        font-size:14px !important;
        font-weight:900 !important;
        letter-spacing:.6px !important;
        text-transform:uppercase !important;
    }

    .modern-table td:first-child::before{
        content:"User Profile" !important;
    }

    .modern-table td:nth-child(2)::before{
        content:"System Role" !important;
    }

    .modern-table td:nth-child(3)::before{
        content:"Date Added" !important;
    }

    .modern-table td:nth-child(4)::before{
        content:"Actions" !important;
    }

    .user-info{
        align-items:center !important;
        gap:16px !important;
    }

    .user-info .avatar,
    .avatar{
        width:62px !important;
        height:62px !important;
        min-width:62px !important;
        font-size:24px !important;
    }

    .badge{
        min-width:0 !important;
        padding:10px 20px !important;
        font-size:15px !important;
    }

    .action-btns{
        width:100% !important;
        display:grid !important;
        grid-template-columns:1fr !important;
        gap:12px !important;
    }

    .btn-action{
        width:100% !important;
        min-height:50px !important;
        border-radius:12px !important;
        justify-content:center !important;
        font-size:15px !important;
    }

    #alert-area{
        width:calc(100% - 32px) !important;
        left:16px !important;
        right:16px !important;
        top:14px !important;
    }
}

@media (min-width:520px) and (max-width:768px){
    .top-header > div[style*="display: flex"]{
        grid-template-columns:1fr auto auto !important;
        align-items:center !important;
    }

    .filters-group{
        grid-template-columns:1fr 220px !important;
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
            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-bottom: 12px; letter-spacing: 0.5px;">MAIN MENU</small>
            
            <a href="admin.php?view=dashboard" class="menu-link"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
            
            <?php if(canView('res_process') || canView('res_status') || canView('res_terms')): ?>
                <a href="reservation.php" class="menu-link"><i class="fa-solid fa-file-signature"></i> Reservations</a>
            <?php endif; ?>

            <?php if(canView('inv_property') || canView('inv_status') || canView('inv_price')): ?>
                <a href="master_list.php" class="menu-link"><i class="fa-solid fa-map-location-dot"></i> Master List / Map</a>
                <a href="admin.php?view=inventory" class="menu-link"><i class="fa-solid fa-plus-circle"></i> Add Property</a>
            <?php endif; ?>

            <?php if(canView('fin_process') || canView('fin_review') || canView('fin_checks')): ?>
                <div class="menu-dropdown">
                    <div class="menu-link dropdown-toggle <?= $currentPage == 'financial.php' ? 'active' : ($isFinancePage ? 'open' : '') ?>">
                        <a href="financial.php" class="finance-main-link" aria-label="Open Financial Dashboard">
                            <i class="fa-solid fa-coins"></i>
                            <span>Financials</span>
                        </a>

                        <button type="button" class="submenu-toggle-btn" onclick="toggleFinanceMenu(event)" title="Show/Hide Financial Menu" aria-label="Show or hide Financial menu" aria-expanded="<?= $isFinancePage ? 'true' : 'false' ?>">
                            <i class="fa-solid fa-chevron-down dropdown-arrow" id="financeArrow" style="<?= $isFinancePage ? 'transform: rotate(180deg);' : '' ?>"></i>
                        </button>
                    </div>

                    <div id="financeSubMenu" class="submenu <?= $isFinancePage ? 'show' : '' ?>">
                        <a href="verify_payments.php" class="menu-link submenu-link <?= $currentPage == 'verify_payments.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Verify Payments</span>
                        </a>

                        <a href="payment_tracking.php" class="menu-link submenu-link <?= $currentPage == 'payment_tracking.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-file-invoice-dollar"></i>
                            <span>Payment Tracking</span>
                        </a>

                        <a href="transaction_history.php" class="menu-link submenu-link <?= $currentPage == 'transaction_history.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-list-ul"></i>
                            <span>Ledger List</span>
                        </a>

                        <a href="daily_reconciliation.php" class="menu-link submenu-link <?= $currentPage == 'daily_reconciliation.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-scale-balanced"></i>
                            <span>Daily Reconciliation</span>
                        </a>

                        <a href="aging_due_report.php" class="menu-link submenu-link <?= $currentPage == 'aging_due_report.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-clock"></i>
                            <span>Aging / Due Report</span>
                        </a>

                        <a href="contract_status.php" class="menu-link submenu-link <?= $currentPage == 'contract_status.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-file-signature"></i>
                            <span>Contract Status</span>
                        </a>

                        <a href="manual_buyer_entry.php" class="menu-link submenu-link <?= $currentPage == 'manual_buyer_entry.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-user-plus"></i>
                            <span>Manual Buyer Entry</span>
                        </a>

                        <a href="pricing_matrix.php" class="menu-link submenu-link <?= $currentPage == 'pricing_matrix.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-table-list"></i>
                            <span>Pricing Matrix</span>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if(canView('usr_buyers')): ?>
                <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">CUSTOMERS</small>
                <a href="buyers.php" class="menu-link <?= $currentPage == 'buyers.php' ? 'active' : '' ?>"><i class="fa-solid fa-users"></i> Buyers</a>
            <?php endif; ?>

            <?php if(canView('usr_buyers') || canView('usr_admins') || canView('usr_promote')): ?>
                <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">MANAGEMENT</small>
                <a href="inquiries.php" class="menu-link"><i class="fa-solid fa-envelope-open-text"></i> Inquiries</a>
                <a href="accounts.php" class="menu-link active"><i class="fa-solid fa-users-gear"></i> Accounts</a>
            <?php endif; ?>

            <?php if($_SESSION['role'] == 'SUPER ADMIN' || $_SESSION['role'] == 'ADMIN'): ?>
                <a href="delete_history.php" class="menu-link"><i class="fa-solid fa-trash-can"></i> Delete History</a>
            <?php endif; ?>

            <small style="padding: 0 15px; color: #90a4ae; font-weight: 700; font-size: 11px; display: block; margin-top: 25px; margin-bottom: 12px; letter-spacing: 0.5px;">SYSTEM</small>
            <a href="index.php" class="menu-link" target="_blank"><i class="fa-solid fa-globe"></i> View Website</a>
            
        </div>
    </div>
    
    <div class="main-panel">
        
        <div class="top-header">
            <div class="top-header-left">
                <button type="button" class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="header-title">
                    <h1>Staff Accounts</h1>
                    <p>Manage admin, manager, cashier, and agent accounts only. Buyer accounts are separated in Buyers.</p>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; flex-wrap:wrap; align-items: center; margin-right: 20px;">
                <?php if(canView('usr_promote') || canView('usr_admins')): ?>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    <i class="fa-solid fa-user-plus"></i> Create Staff Account
                </button>
                <?php endif; ?>

                <?php include 'includes/admin_notification_bell.php'; ?>

                <div class="profile-dropdown">
                    <div class="profile-trigger">
                        <div class="profile-avatar">A</div>
                        <div class="profile-info">
                            <strong>Administrator</strong>
                            <small>System Admin <i class="fa-solid fa-chevron-down" style="font-size: 9px; margin-left: 3px;"></i></small>
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
                    <h2><i class="fa-solid fa-address-book" style="color: var(--primary); margin-right: 8px;"></i> Staff Accounts List</h2>
                    
                    <form method="GET" class="filters-group">
                        <input type="text" name="search" class="filter-control" placeholder="Search name, email..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                        <select name="role" class="filter-control" onchange="this.form.submit()">
                            <option value="">All Roles</option>
                            <option value="SUPER ADMIN" <?= ($_GET['role'] ?? '') == 'SUPER ADMIN' ? 'selected' : '' ?>>Super Admin</option>
                            <option value="ADMIN" <?= ($_GET['role'] ?? '') == 'ADMIN' ? 'selected' : '' ?>>Admin</option>
                            <option value="MANAGER" <?= ($_GET['role'] ?? '') == 'MANAGER' ? 'selected' : '' ?>>Manager</option>
                            <option value="CASHIER" <?= ($_GET['role'] ?? '') == 'CASHIER' ? 'selected' : '' ?>>Cashier</option>
                            <option value="AGENT" <?= ($_GET['role'] ?? '') == 'AGENT' ? 'selected' : '' ?>>Agent</option>
                        </select>
                    </form>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>User Profile</th>
                                <th>System Role</th>
                                <th>Date Added</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($accounts_result && $accounts_result->num_rows > 0): ?>
                                <?php while($row = $accounts_result->fetch_assoc()): ?>
                                    <tr>
                                        <td data-label="User Profile">
                                            <div class="user-info">
                                                <div class="avatar" style="background-color: <?= getAvatarColor($row['id']) ?>">
                                                    <?= strtoupper(substr($row['fullname'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 700; color: #1e293b; font-size: 14px;"><?= htmlspecialchars($row['fullname']) ?></div>
                                                    <div style="color: var(--text-muted); font-size: 12px; margin-top: 3px; display: flex; gap: 10px;">
                                                        <span><i class="fa-regular fa-envelope"></i> <?= htmlspecialchars($row['email']) ?></span>
                                                        <?php if(!empty($row['phone'])): ?>
                                                            <span><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($row['phone']) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="System Role">
                                            <?php

$role = strtoupper(trim($row['role'] ?? ''));

$roleClass = [
    'SUPER ADMIN' => 'role-super-admin',
    'ADMIN'       => 'role-admin',
    'MANAGER'     => 'role-manager',
    'CASHIER'     => 'role-cashier',
    'AGENT'       => 'role-agent',
    'BUYER'       => 'role-buyer'
][$role] ?? 'role-default';

?>

<span class="badge <?= $roleClass ?>">
    <?= htmlspecialchars($role) ?>
</span>
                                        </td>
                                        <td data-label="Date Added" style="color: var(--text-muted); font-weight: 500;">
                                            <?= date('M d, Y', strtotime($row['created_at'])) ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="action-btns">
                                                
                                                <?php if(canView('usr_admins') || canView('usr_buyers')): ?>
                                                    <button class="btn-action btn-edit" onclick="openEditModal(<?= $row['id'] ?>)" title="Edit User">
                                                        <i class="fa-solid fa-pen"></i> Edit
                                                    </button>
                                                <?php endif; ?>

                                                <?php if($row['role'] == 'MANAGER' && canView('usr_promote')): ?>
                                                    <button class="btn-action btn-perms" onclick="openPermissionsModal(<?= $row['id'] ?>)" title="Edit Permissions">
                                                        <i class="fa-solid fa-shield-halved"></i> Perms
                                                    </button>
                                                    <button class="btn-action btn-demote" onclick="changeRole(<?= $row['id'] ?>, 'AGENT')" title="Demote to Agent">
                                                        <i class="fa-solid fa-arrow-down"></i> Demote to Agent
                                                    </button>
                                                <?php endif; ?>

                                                <?php if($row['id'] != $_SESSION['user_id'] && canView('usr_admins')): ?>
                                                    <button class="btn-action btn-delete" onclick="deleteAccount(<?= $row['id'] ?>)" title="Delete User">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                        <i class="fa-solid fa-users-slash" style="font-size: 30px; margin-bottom: 10px; display: block; color: #cbd5e1;"></i>
                                        No accounts found matching your criteria.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="accountModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle"><i class="fa-solid fa-user-plus" style="color: var(--primary);"></i> Create Staff Account</h2>
                <button type="button" class="close-modal" onclick="closeModal('accountModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="accountForm" style="padding: 25px;">
                <input type="hidden" name="action" id="formAction" value="create_account">
                <input type="hidden" name="user_id" id="formUserId" value="">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="fullname" id="f_fullname" class="form-control" placeholder="e.g. Juan Dela Cruz" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" id="f_email" class="form-control" placeholder="name@example.com" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" id="f_phone" class="form-control" placeholder="09XX XXX XXXX">
                    </div>
                    <div class="form-group full-width" id="passwordGroup">
                        <label class="form-label">Account Password</label>
                        <input type="password" name="password" id="f_password" class="form-control" placeholder="••••••••" required minlength="4">
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label">System Role</label>
                        <select name="role" id="f_role" class="form-control filter-control" required>
                            <option value="MANAGER">Manager (Staff)</option>
                            <option value="CASHIER">Cashier</option>
                            <option value="AGENT">Agent</option>
                            <?php if($current_role == 'SUPER ADMIN'): ?>
                                <option value="ADMIN">Administrator</option>
                                <option value="SUPER ADMIN">Super Admin</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 20px; text-align: right; border-top: 1px solid var(--gray-border); padding-top: 15px;">
                    <button type="button" class="btn" onclick="closeModal('accountModal')" style="background:#f1f5f9; color:#475569; border: 1px solid #cbd5e1; margin-right: 10px;">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-save"></i> Save Staff Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="permissionsModal" class="modal" style="display: flex;">
        <div class="modal-content modal-permissions">
            <div class="modal-header" style="background: #fffbeb;">
                <h2><i class="fa-solid fa-shield-halved" style="color: #f59e0b;"></i> Manager Permissions Setup</h2>
                <button type="button" class="close-modal" onclick="closeModal('permissionsModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="permissionsForm" style="padding: 25px;">
                <input type="hidden" name="action" value="save_manager_permissions">
                <input type="hidden" name="user_id" id="p_user_id" value="">
                
                <div class="perm-header-summary">
                    <i class="fa-solid fa-user-gear" style="font-size: 30px; color: var(--primary);"></i>
                    <div>
                        <h3>Configure Access Rights</h3>
                        <p id="p_manager_name" style="margin: 4px 0 0 0; font-size:12px; color: #64748b;">Loading user details...</p>
                    </div>
                </div>

                <div class="permissions-grid">
                    <div class="perm-section">
                        <div class="perm-section-header">Inventory Management</div>
                        <div class="perm-list">
                            <div class="perm-item full-access">
                                <label class="checkbox-container">Full Inventory Access<input type="checkbox" name="inv_full" id="p_inv_full" onchange="toggleFullAccess(this, 'inv')"><span class="checkmark"></span></label>
                            </div>
                            <label class="checkbox-container">Manage Property Listings<input type="checkbox" name="inv_property" class="perm-inv"><span class="checkmark"></span></label>
                            <label class="checkbox-container">Update Lot Status<input type="checkbox" name="inv_status" class="perm-inv"><span class="checkmark"></span></label>
                            <label class="checkbox-container">Adjust Pricing<input type="checkbox" name="inv_price" class="perm-inv"><span class="checkmark"></span></label>
                        </div>
                    </div>

                    <div class="perm-section">
                        <div class="perm-section-header">Reservation Management</div>
                        <div class="perm-list">
                            <div class="perm-item full-access">
                                <label class="checkbox-container">Full Reservation Access<input type="checkbox" name="res_full" id="p_res_full" onchange="toggleFullAccess(this, 'res')"><span class="checkmark"></span></label>
                            </div>
                            <label class="checkbox-container">Process Reservations<input type="checkbox" name="res_process" class="perm-res"><span class="checkmark"></span></label>
                            <label class="checkbox-container">Approve/Reject Requests<input type="checkbox" name="res_status" class="perm-res"><span class="checkmark"></span></label>
                            <label class="checkbox-container">Modify Payment Terms<input type="checkbox" name="res_terms" class="perm-res"><span class="checkmark"></span></label>
                        </div>
                    </div>

                    <div class="perm-section">
                        <div class="perm-section-header">Financials & Payments</div>
                        <div class="perm-list">
                            <div class="perm-item full-access">
                                <label class="checkbox-container">Full Financial Access<input type="checkbox" name="fin_full" id="p_fin_full" onchange="toggleFullAccess(this, 'fin')"><span class="checkmark"></span></label>
                            </div>
                            <label class="checkbox-container">Process Ledger Payments<input type="checkbox" name="fin_process" class="perm-fin"><span class="checkmark"></span></label>
                            <label class="checkbox-container">Issue Check Vouchers<input type="checkbox" name="fin_checks" class="perm-fin"><span class="checkmark"></span></label>
                            <label class="checkbox-container">Manage Bank Accounts<input type="checkbox" name="fin_accounts" class="perm-fin"><span class="checkmark"></span></label>
                        </div>
                    </div>

                    <div class="perm-section">
                        <div class="perm-section-header">Users & Accounts</div>
                        <div class="perm-list">
                            <div class="perm-item full-access">
                                <label class="checkbox-container">Full User Access<input type="checkbox" name="usr_full" id="p_usr_full" onchange="toggleFullAccess(this, 'usr')"><span class="checkmark"></span></label>
                            </div>
                            <label class="checkbox-container">Manage Buyer Accounts<input type="checkbox" name="usr_buyers" class="perm-usr"><span class="checkmark"></span></label>
                            <label class="checkbox-container">Promote to Manager<input type="checkbox" name="usr_promote" class="perm-usr"><span class="checkmark"></span></label>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 20px; text-align: right; border-top: 1px solid var(--gray-border); padding-top: 15px;">
                    <button type="button" class="btn" onclick="closeModal('permissionsModal')" style="background:#f1f5f9; color:#475569; border: 1px solid #cbd5e1; margin-right: 10px;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #f59e0b;"><i class="fa-solid fa-save"></i> Save Permissions</button>
                </div>
            </form>
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

        function toggleSidebar(){
            if (window.innerWidth <= 992) {
                document.body.classList.toggle('sidebar-open');
            } else {
                document.body.classList.toggle('sidebar-collapsed');
            }
        }

        document.addEventListener('click', function(e){
            if (document.body.classList.contains('sidebar-open') &&
                !e.target.closest('.sidebar') &&
                !e.target.closest('.sidebar-toggle')) {
                document.body.classList.remove('sidebar-open');
            }
        });

        window.addEventListener('resize', function(){
            if (window.innerWidth > 992) {
                document.body.classList.remove('sidebar-open');
            }
        });

        // Ensure modals are hidden on load (overriding inline flex used for previewing)
        document.getElementById('permissionsModal').style.display = 'none';

        function closeModal(id) {
            $(`#${id}`).fadeOut(200);
            if(id === 'accountModal') $('#accountForm')[0].reset();
            if(id === 'permissionsModal') $('#permissionsForm')[0].reset();
        }

        function openCreateModal() {
            $('#modalTitle').html('<i class="fa-solid fa-user-plus" style="color: var(--primary);"></i> Create Staff Account');
            $('#formAction').val('create_account');
            $('#formUserId').val('');
            $('#passwordGroup').show();
            
            // Re-enable and require password for creation
            $('#f_password').prop('disabled', false).prop('required', true); 
            
            $('#accountModal').css('display', 'flex').hide().fadeIn(300);
        }

        function openEditModal(id) {
            $('#modalTitle').html('<i class="fa-solid fa-user-pen" style="color: #0ea5e9;"></i> Edit Account');
            $('#formAction').val('update_account');
            $('#formUserId').val(id);
            $('#passwordGroup').hide(); 
            
            // Completely disable the password field so HTML5 validation ignores it during Edit
            $('#f_password').prop('disabled', true).prop('required', false);

            $.ajax({
                url: 'accounts.php',
                method: 'POST',
                data: { action: 'get_account_details', id: id },
                success: function(response){
                    if(response.status === 'success'){
                        const u = response.user;
                        $('#f_fullname').val(u.fullname);
                        $('#f_email').val(u.email);
                        $('#f_phone').val(u.phone);
                        $('#f_role').val(u.role);
                        $('#accountModal').css('display', 'flex').hide().fadeIn(300);
                    }
                }
            });
        }

        // QUICK PROMOTE / DEMOTE LOGIC
        function changeRole(id, newRole) {
            if(confirm(`Are you sure you want to change this user's role to ${newRole}?`)) {
                $.ajax({
                    url: 'accounts.php',
                    method: 'POST',
                    data: { action: 'change_role', user_id: id, new_role: newRole },
                    success: function(response){
                        if(response.status === 'success'){
                            showAlert('success', response.message);
                            setTimeout(()=> location.reload(), 1000);
                        } else {
                            showAlert('error', response.message);
                        }
                    }
                });
            }
        }

        $('#accountForm').on('submit', function(e){
            e.preventDefault();
            $.ajax({
                url: 'accounts.php',
                method: 'POST',
                data: $(this).serialize(),
                success: function(response){
                    if(response.status === 'success'){
                        showAlert('success', response.message);
                        setTimeout(()=> location.reload(), 1000);
                    } else {
                        showAlert('error', response.message);
                    }
                }
            });
        });

        function toggleFullAccess(source, sectionPrefix) {
            $(`.perm-${sectionPrefix}`).prop('checked', source.checked);
        }

        function openPermissionsModal(id) {
            $('#p_user_id').val(id);
            $('#permissionsForm')[0].reset();
            
            $.ajax({
                url: 'accounts.php',
                method: 'POST',
                data: { action: 'get_account_details', id: id },
                success: function(response){
                    if(response.status === 'success'){
                        $('#p_manager_name').html(`<strong>${response.user.fullname}</strong> &nbsp;|&nbsp; <i class="fa-regular fa-envelope"></i> ${response.user.email}`);
                        
                        if(response.permissions){
                            for (const key in response.permissions) {
                                if (response.permissions[key] == 1) {
                                    $(`input[name="${key}"]`).prop('checked', true);
                                }
                            }
                        }
                        $('#permissionsModal').css('display', 'flex').hide().fadeIn(300);
                    }
                }
            });
        }

        $('#permissionsForm').on('submit', function(e){
            e.preventDefault();
            $.ajax({
                url: 'accounts.php',
                method: 'POST',
                data: $(this).serialize(),
                success: function(response){
                    if(response.status === 'success'){
                        showAlert('success', response.message);
                        setTimeout(() => closeModal('permissionsModal'), 1000);
                    } else {
                        showAlert('error', response.message);
                    }
                }
            });
        });
        
        function deleteAccount(id) {
    if (confirm('Are you sure you want to delete this account? This action cannot be undone.')) {
        $.ajax({
            url: 'accounts.php',
            method: 'POST',
            data: { action: 'delete_account', user_id: id },
            success: function(response) {
                if (response.status === 'success') {
                    showAlert('success', response.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('error', response.message);
                }
            },
            error: function() {
                showAlert('error', 'Request failed. Please try again.');
            }
        });
    }
}


        function showAlert(type, message) {
            const isSuccess = type === 'success';
            const icon = isSuccess ? 'fa-check-circle' : 'fa-circle-exclamation';
            const alert = `<div class="alert ${isSuccess ? 'alert-success' : 'alert-error'}"><i class="fa-solid ${icon}" style="font-size: 16px;"></i> ${message}</div>`;
            $('#alert-area').html(alert);
            setTimeout(() => $('.alert').fadeOut(500, function() { $(this).remove(); }), 3000);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if ($(event.target).hasClass('modal')) {
                $(event.target).fadeOut(200);
            }
        }
    </script>
</body>
</html>
