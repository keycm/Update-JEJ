<?php
// audit_logs.php
include 'config.php';

// Security Check
if(!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['SUPER ADMIN', 'ADMIN', 'MANAGER'])){
    header("Location: login.php");
    exit();
}

// --- AUDIT LOG SCHEMA HARDENING ---
// Adds recommended audit trail columns if missing:
// browser_info = user's browser/user agent
// old_value    = JSON/text snapshot before change
// new_value    = JSON/text snapshot after change
function audit_logs_has_column($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    if ($table === '' || $column === '') return false;

    $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    if (!$stmt) return false;
    $stmt->bind_param("s", $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = ($result && $result->num_rows > 0);
    $stmt->close();
    return $exists;
}

function audit_logs_add_column_if_missing($conn, $column, $definition) {
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    if ($column === '') return false;
    if (audit_logs_has_column($conn, 'audit_logs', $column)) return true;

    // Definitions are hard-coded below; no user input is used here.
    return (bool)$conn->query("ALTER TABLE `audit_logs` ADD COLUMN `$column` $definition");
}

audit_logs_add_column_if_missing($conn, 'browser_info', 'TEXT NULL');
audit_logs_add_column_if_missing($conn, 'old_value', 'LONGTEXT NULL');
audit_logs_add_column_if_missing($conn, 'new_value', 'LONGTEXT NULL');

// Fill browser_info for new/edited rows when possible.
// Existing old rows may remain NULL because browser was not stored before.
if (audit_logs_has_column($conn, 'audit_logs', 'browser_info')) {
    $browser_now = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($browser_now !== '') {
        $stmt = $conn->prepare("UPDATE audit_logs SET browser_info = ? WHERE browser_info IS NULL OR browser_info = ''");
        if ($stmt) {
            $stmt->bind_param("s", $browser_now);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// --- SEARCH & SORT LOGIC ---
$search = trim($_GET['search'] ?? '');
$sort = ($_GET['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$where_sql = "1=1";
$params = [];
$types = "";

if ($search !== '') {
    $like = '%' . $search . '%';
    $where_sql .= " AND (
        a.action LIKE ?
        OR a.details LIKE ?
        OR a.reference_table LIKE ?
        OR a.ip_address LIKE ?
        OR a.browser_info LIKE ?
        OR a.old_value LIKE ?
        OR a.new_value LIKE ?
        OR u.fullname LIKE ?
    )";
    $params = [$like, $like, $like, $like, $like, $like, $like, $like];
    $types = "ssssssss";
}

$order_sql = $sort === 'asc' ? "ORDER BY a.created_at ASC" : "ORDER BY a.created_at DESC";

// Fetch the logs with limit using prepared statement
$query = "SELECT 
            a.*,
            u.fullname,
            u.role
          FROM audit_logs a
          LEFT JOIN users u ON a.user_id = u.id
          WHERE $where_sql
          $order_sql
          LIMIT 500";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Audit log query failed: " . htmlspecialchars($conn->error));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$logs = $stmt->get_result();

function audit_short_browser($browser) {
    $browser = (string)$browser;
    if ($browser === '') return 'N/A';

    $os = 'Unknown OS';
    if (stripos($browser, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($browser, 'Android') !== false) $os = 'Android';
    elseif (stripos($browser, 'iPhone') !== false || stripos($browser, 'iPad') !== false) $os = 'iOS';
    elseif (stripos($browser, 'Mac OS') !== false || stripos($browser, 'Macintosh') !== false) $os = 'macOS';
    elseif (stripos($browser, 'Linux') !== false) $os = 'Linux';

    $app = 'Browser';
    if (stripos($browser, 'Edg/') !== false) $app = 'Edge';
    elseif (stripos($browser, 'Chrome/') !== false) $app = 'Chrome';
    elseif (stripos($browser, 'Firefox/') !== false) $app = 'Firefox';
    elseif (stripos($browser, 'Safari/') !== false) $app = 'Safari';

    return $app . ' / ' . $os;
}

function audit_format_value($value) {
    $value = trim((string)$value);
    if ($value === '') return '';

    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return $value;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs | JEJ Top Priority Corporation</title>
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

        body { 
            background-color: #fafcf9; 
            min-height: 100vh; 
            font-family: 'Inter', sans-serif; 
            color: #37474f; 
            margin: 0; 
            padding: 0;
        }

        /* Full Width Panel */
        .main-panel { width: 100%; display: flex; flex-direction: column; }
        
        .top-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background: #ffffff; 
            padding: 20px 60px; 
            border-bottom: 1px solid var(--gray-border); 
            box-shadow: var(--shadow-sm); 
            z-index: 50; 
        }
        .header-title h1 { font-size: 24px; font-weight: 800; color: var(--dark); margin: 0 0 4px 0; letter-spacing: -0.5px;}
        .header-title p { color: var(--text-muted); font-size: 14px; margin: 0; }

        /* Profile Dropdown */
        .profile-dropdown { position: relative; cursor: pointer; }
        .profile-trigger { display: flex; align-items: center; gap: 12px; padding: 6px 12px; border-radius: 10px; transition: background 0.2s; border: 1px solid transparent; }
        .profile-trigger:hover { background: var(--gray-light); border-color: var(--gray-border); }
        .profile-avatar { width: 40px; height: 40px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 16px; box-shadow: 0 2px 4px rgba(46, 125, 50, 0.2);}
        
        .dropdown-menu { display: none; position: absolute; right: 0; top: 110%; background: white; border-radius: 12px; box-shadow: var(--shadow-lg); border: 1px solid var(--gray-border); min-width: 200px; z-index: 1000; overflow: hidden; transform-origin: top right; animation: dropAnim 0.2s ease-out forwards; }
        @keyframes dropAnim { 0% { opacity: 0; transform: scale(0.95) translateY(-10px); } 100% { opacity: 1; transform: scale(1) translateY(0); } }
        .profile-dropdown:hover .dropdown-menu { display: block; }
        .dropdown-item { padding: 12px 16px; display: flex; align-items: center; gap: 12px; color: #455a64; text-decoration: none; font-size: 13px; font-weight: 500; transition: background 0.2s; border-left: 3px solid transparent;}
        .dropdown-item:hover { background: var(--primary-light); color: var(--primary); border-left-color: var(--primary); }
        .dropdown-item.text-danger { color: #d84315; }
        .dropdown-item.text-danger:hover { background: #fbe9e7; color: #bf360c; border-left-color: #d84315; }

        .content-area { padding: 40px 60px; flex: 1; }

        /* Toolbar */
        .toolbar { 
            background: white; 
            padding: 20px 25px; 
            border-radius: 16px; 
            border: 1px solid var(--gray-border); 
            box-shadow: var(--shadow-sm); 
            margin-bottom: 25px; 
            display: flex; 
            flex-wrap: wrap; 
            gap: 15px; 
            align-items: center; 
            justify-content: space-between;
        }
        .form-control { padding: 11px 16px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 14px; outline: none; transition: 0.2s; color: #475569;}
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.15); }
        
        .btn-filter { background: var(--primary); color: white; border: none; padding: 11px 22px; border-radius: 10px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px;}
        .btn-filter:hover { background: var(--dark); box-shadow: 0 4px 6px rgba(46, 125, 50, 0.2); }
        .btn-reset { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 11px 22px; border-radius: 10px; font-weight: 600; cursor: pointer; text-decoration: none; transition: 0.2s;}
        .btn-reset:hover { background: #e2e8f0; }

        /* Table Styling */
        .table-container { background: white; border-radius: 16px; border: 1px solid var(--gray-border); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 18px 24px; font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; background: var(--gray-light); border-bottom: 1px solid var(--gray-border); letter-spacing: 0.5px;}
        td { padding: 18px 24px; border-bottom: 1px solid var(--gray-border); color: #37474f; font-size: 14px; vertical-align: middle; }
        tr:hover td { background: #fdfdfd; }
        tr:last-child td { border-bottom: none; }

        /* Badges */
        .role-badge { padding: 5px 12px; border-radius: 8px; font-size: 11px; font-weight: 800; text-transform: uppercase; display: inline-block; border: 1px solid transparent;}
        .role-SUPER-ADMIN, .role-ADMIN { background: #ede9fe; color: #7c3aed; border-color: #ddd6fe; }
        .role-MANAGER { background: #dbeafe; color: #2563eb; border-color: #bfdbfe; }
        .role-AGENT { background: #d1fae5; color: #059669; border-color: #a7f3d0; }
        .role-BUYER { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }

        /* Buttons */
        .btn-print { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; padding: 10px 20px; border-radius: 10px; font-size: 14px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; cursor: pointer;}
        .btn-print:hover { background: #bae6fd; color: #0369a1; transform: translateY(-1px); }

        .btn-back { background: var(--primary-light); color: var(--primary); border: 1px solid var(--gray-border); padding: 10px 20px; border-radius: 10px; font-size: 14px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; cursor: pointer; }
        .btn-back:hover { background: #c8e6c9; transform: translateY(-1px); }

        .log-date { font-weight: 700; color: #455a64; font-size: 14px;}
        .log-time { font-size: 12px; font-weight: 500; display: block; margin-top: 3px; color: var(--text-muted);}
        .action-text { font-weight: 800; color: var(--primary); font-size: 15px;}
        
        .audit-meta-chip {
            background:#f1f5f9;
            padding:6px 10px;
            border-radius:8px;
            font-size:12px;
            font-weight:600;
            color:#334155;
            display:inline-block;
            max-width:260px;
            overflow-wrap:anywhere;
        }

        .audit-value-grid {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:8px;
            margin-top:10px;
        }

        .audit-value-box {
            background:#f8fafc;
            border:1px solid #e2e8f0;
            border-radius:10px;
            padding:9px 10px;
            font-size:12px;
            color:#334155;
            max-height:150px;
            overflow:auto;
        }

        .audit-value-box strong {
            display:block;
            margin-bottom:6px;
            color:#1b5e20;
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:.4px;
        }

        .audit-value-box pre {
            margin:0;
            white-space:pre-wrap;
            font-family:Consolas, monospace;
            font-size:11px;
            line-height:1.45;
        }

        


        /* FIX: AUDIT LOGS FILTER BAR AUTO-FIT */
        .audit-filter-form {
            display: grid !important;
            grid-template-columns: minmax(260px, 1fr) 220px 180px 110px;
            gap: 12px;
            width: 100%;
            align-items: center;
        }

        .audit-search-box {
            position: relative;
            min-width: 0 !important;
            width: 100%;
        }

        .audit-search-box .form-control {
            width: 100% !important;
            padding-left: 42px !important;
            box-sizing: border-box;
        }

        .audit-sort-box,
        .audit-actions {
            min-width: 0;
            width: 100%;
        }

        .audit-sort-box .form-control {
            width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box;
        }

        .audit-actions {
            display: contents !important;
        }

        .audit-actions .btn-filter,
        .audit-actions .btn-reset {
            width: 100%;
            justify-content: center;
            box-sizing: border-box;
            white-space: nowrap;
        }

        @media (max-width: 1100px) {
            .audit-filter-form {
                grid-template-columns: 1fr 220px;
            }
        }

        @media (max-width: 768px) {
            .top-header {
                padding: 18px 20px;
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .top-header > div:last-child {
                width: 100%;
                flex-wrap: wrap;
            }

            .content-area {
                padding: 24px 18px;
            }

            .audit-filter-form {
                grid-template-columns: 1fr;
            }

            .table-container {
                overflow-x: auto;
            }

            table {
                min-width: 1250px;
            }
        }

        /* Print Styles */
        @media print {
            .top-header, .toolbar { display: none !important; }
            .main-panel { margin: 0; width: 100%; padding: 0; }
            .content-area { padding: 0; }
            .table-container { box-shadow: none; border: none; border-radius: 0; margin: 0; }
            th, td { border: 1px solid #cbd5e1; padding: 10px; }
            body { background: white; }
        }
    </style>
</head>
<body>

    <div class="main-panel">
        
        <div class="top-header">
            <div class="header-title">
                <h1>System Audit Logs</h1>
                <p>Full-screen tracking of activities and system modifications.</p>
            </div>
            
            <div style="display: flex; align-items: center; gap: 15px;">
                <a href="admin.php?view=dashboard" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
                <button onclick="window.print()" class="btn-print"><i class="fa-solid fa-print"></i> Print Logs</button>
                
                <div class="profile-dropdown" style="margin-left: 15px;">
                    <div class="profile-trigger">
                        <div class="profile-avatar">A</div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="logout.php" class="dropdown-item text-danger"><i class="fa-solid fa-arrow-right-from-bracket"></i> Secure Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="content-area">

            <div class="toolbar">
                <form method="GET" class="audit-filter-form">
                    
                    <div class="audit-search-box">
                        <i class="fa-solid fa-search" style="position: absolute; left: 16px; top: 14px; color: #94a3b8; font-size: 15px;"></i>
                        <input type="text" name="search" class="form-control" placeholder="Search by user name, action, or specific details..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    
                    <div class="audit-sort-box">
                        <select name="sort" class="form-control" style="font-weight: 600;">
                            <option value="desc" <?= $sort == 'desc' ? 'selected' : '' ?>>Newest Logs First</option>
                            <option value="asc" <?= $sort == 'asc' ? 'selected' : '' ?>>Oldest Logs First</option>
                        </select>
                    </div>
                    
                    <div class="audit-actions">
                        <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Apply Filter</button>
                        <a href="audit_logs.php" class="btn-reset">Reset</a>
                    </div>

                </form>
            </div>

            <div class="table-container">
                <table>
                    <thead>
    <tr>
        <th style="width:10%;">Date & Time</th>
        <th style="width:14%;">User</th>
        <th style="width:14%;">Action</th>
        <th style="width:28%;">Details / Before & After</th>
        <th style="width:10%;">Reference</th>
        <th style="width:10%;">IP Address</th>
        <th style="width:14%;">Browser</th>
    </tr>
</thead>
                    <tbody>
                        <?php 
                        if($logs && $logs->num_rows > 0):
                            while($row = $logs->fetch_assoc()): 
                        ?>
                        <tr>

    <td>
        <span class="log-date">
            <?= date('M d, Y', strtotime($row['created_at'])) ?>
        </span>

        <span class="log-time">
            <?= date('h:i A', strtotime($row['created_at'])) ?>
        </span>
    </td>

    <td>
        <strong style="color:#1e293b; display:block; font-size:15px; margin-bottom:5px;">
            <?= htmlspecialchars($row['fullname'] ?? 'System Process') ?>
        </strong>

        <span class="role-badge role-<?= str_replace(' ','-',$row['role'] ?? 'BUYER') ?>">
            <?= $row['role'] ?? 'SYSTEM' ?>
        </span>
    </td>

    <td>
        <span class="action-text">
            <?= htmlspecialchars($row['action']) ?>
        </span>
    </td>

    <td style="font-size:14px; color:#475569; line-height:1.6;">
        <?= htmlspecialchars($row['details']) ?>

        <?php
            $oldValue = audit_format_value($row['old_value'] ?? '');
            $newValue = audit_format_value($row['new_value'] ?? '');
        ?>

        <?php if($oldValue !== '' || $newValue !== ''): ?>
            <div class="audit-value-grid">
                <div class="audit-value-box">
                    <strong>Old Value</strong>
                    <pre><?= htmlspecialchars($oldValue !== '' ? $oldValue : '-') ?></pre>
                </div>
                <div class="audit-value-box">
                    <strong>New Value</strong>
                    <pre><?= htmlspecialchars($newValue !== '' ? $newValue : '-') ?></pre>
                </div>
            </div>
        <?php endif; ?>
    </td>

    <td>
        <strong>
            <?= htmlspecialchars($row['reference_table'] ?? '-') ?>
        </strong>

        <br>

        <small style="color:#64748b;">
            ID:
            <?= htmlspecialchars($row['reference_id'] ?? '-') ?>
        </small>
    </td>

    <td>
        <span class="audit-meta-chip">
            <?= htmlspecialchars($row['ip_address'] ?? 'N/A') ?>
        </span>
    </td>

    <td>
        <span class="audit-meta-chip" title="<?= htmlspecialchars($row['browser_info'] ?? 'N/A') ?>">
            <?= htmlspecialchars(audit_short_browser($row['browser_info'] ?? '')) ?>
        </span>
    </td>

</tr>
                        <?php 
                            endwhile; 
                        else:
                        ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 60px; color: #94a3b8;">
                                <i class="fa-solid fa-clipboard-list" style="font-size: 40px; margin-bottom: 15px; display: block; opacity: 0.3;"></i>
                                <span style="font-weight: 500; font-size: 16px;">No activity logs found matching your criteria.</span>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</body>
</html>