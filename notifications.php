<?php
// notifications.php

ob_start();

require_once 'config.php';

// Require Login
checkLogin();

// Allowed Roles
requireRole([
    'SUPER ADMIN',
    'ADMIN',
    'MANAGER',
    'CASHIER',
    'AGENT',
    'BUYER'
]);



$user_id = $_SESSION['user_id'];

// 1. Mark all unread notifications as read since the user is now viewing them
$update_stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$update_stmt->bind_param("i", $user_id);
$update_stmt->execute();
$update_stmt->close();

// 2. Fetch all notifications for this user
// Hide stale contact inquiry notifications whose inquiry was already deleted.
// This keeps the full notifications page synced with inquiries.php.
$query = "
    SELECT n.*
    FROM notifications n
    WHERE n.user_id = ?
      AND NOT (
          LOWER(n.title) LIKE '%contact inquiry%'
          AND n.message LIKE '%Inquiry ID:%'
          AND NOT EXISTS (
              SELECT 1
              FROM inquiries i
              WHERE n.message LIKE CONCAT('%Inquiry ID: ', i.id, '%')
          )
      )
    ORDER BY n.created_at DESC
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

// Unread count for the navbar will be 0 now because we just updated them
$unread_count = 0;

$is_admin_notification_clickable = in_array($_SESSION['role'] ?? '', ['SUPER ADMIN', 'ADMIN', 'MANAGER'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | JEJ Top Priority Corporation</title>
    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">
    <!-- Unified Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,600;0,700;0,800&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root { 
            --primary-yellow: #F4D03F;
            --dark-bg: #111827; 
            --light-bg: #F8FAFC;
            --text-dark: #1e293b;
            --text-light: #ffffff;
            --success-green: #22c55e;
            --danger-red: #ef4444;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 20px 40px -10px rgba(0,0,0,0.12);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Roboto', sans-serif; 
            background-color: #f1f5f9; 
            color: var(--text-dark); 
            line-height: 1.6;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100vh; /* Flex column to push footer to bottom safely */
        }

        h1, h2, h3, h4 { font-family: 'Montserrat', sans-serif; }
        a { text-decoration: none; }

        /* --- ANIMATIONS --- */
        @keyframes dropIn { from { opacity: 0; transform: scale(0.9) translateY(-10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        @keyframes fadeIn { 0% { opacity: 0; } 100% { opacity: 1; } }

        .animate-on-scroll {
            opacity: 1;
            transform: none;
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .animate-on-scroll.visible { opacity: 1; transform: translateY(0); }

        /* === ENLARGED NAVBAR (Matches new index.php) === */
        .navbar {
            position: fixed; top: 0; left: 0; width: 100%; padding: 18px 5%;
            display: flex; justify-content: space-between; align-items: center;
            z-index: 1000; color: white;
            background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        
        .nav-logo { display: flex; align-items: center; }
        .nav-logo-text { margin-left: 15px; }
        .nav-logo-text h2 { font-weight: 800; letter-spacing: 0.5px; font-size: 1.5rem; line-height: 1.1; text-shadow: 0 2px 4px rgba(0,0,0,0.5); color: white; }
        .nav-logo-text span { font-family: 'Roboto', sans-serif; font-size: 11px; font-weight: 500; letter-spacing: 1.5px; color: #cbd5e1; display: block; margin-top: 4px; }
        
        .nav-links { display: flex; gap: 40px; }
        .nav-links a { font-size: 1.05rem; font-weight: 500; transition: color 0.3s; color: white;}
        .nav-links a:hover { color: var(--primary-yellow); }
        
        /* User Profile Logic in Nav */
        .nav-actions { display: flex; align-items: center; gap: 20px; }
        .notification-bell { position: relative; color: var(--primary-yellow); font-size: 22px; transition: color 0.3s; }
        .notification-bell:hover { transform: scale(1.1); }
        .notification-dot { position: absolute; top: 0; right: -2px; width: 10px; height: 10px; background-color: #ef4444; border-radius: 50%; border: 2px solid var(--dark-bg); }
        
        .profile-dropdown-container { position: relative; }
        .profile-trigger {
            display: flex; align-items: center; gap: 10px; background: transparent;
            border: 1px solid rgba(255,255,255,0.2); cursor: pointer; padding: 6px 12px;
            border-radius: 40px; transition: all 0.2s ease; color: white;
        }
        .profile-trigger:hover { background: rgba(255,255,255,0.1); border-color: white; }
        .profile-info { text-align: right; }
        .profile-name { display: block; font-weight: 600; font-size: 0.9rem; font-family: 'Roboto', sans-serif;}
        .profile-role { display: block; font-size: 0.7rem; color: var(--primary-yellow); font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;}
        .avatar-circle { background: var(--primary-yellow); color: var(--dark-bg); border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1rem; }
        
        .profile-dropdown-menu {
            display: none; position: absolute; top: 120%; right: 0; background: white;
            min-width: 220px; border-radius: 12px; box-shadow: var(--shadow-lg);
            border: 1px solid #f1f5f9; overflow: hidden; z-index: 100;
        }
        .profile-dropdown-menu.active { display: block; animation: fadeIn 0.2s ease forwards; }
        .profile-dropdown-item {
            display: flex; align-items: center; gap: 12px; padding: 12px 20px;
            text-decoration: none; color: var(--text-dark); font-size: 0.95rem; font-weight: 500; transition: all 0.2s;
        }
        .profile-dropdown-item i { width: 20px; text-align: center; color: #94a3b8; transition: 0.2s; }
        .profile-dropdown-item:hover { background: #f8fafc; color: var(--dark-bg); padding-left: 25px; }
        .profile-dropdown-item:hover i { color: var(--primary-yellow); }
        .profile-dropdown-item.logout-btn { color: #ef4444; border-top: 1px solid #f1f5f9; }
        .profile-dropdown-item.logout-btn i { color: #ef4444; }
        .profile-dropdown-item.logout-btn:hover { background: #fef2f2; color: #dc2626; }

        /* Notifications Page Specific Styles */
        .page-container {
            flex-grow: 1; /* Pushes footer down */
            width: 100%;
            max-width: 900px;
            margin: 140px auto 80px auto; 
            padding: 0 5%;
            position: relative;
            z-index: 1;
        }

        .notifications-wrapper {
            background: white;
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            padding: 40px;
            border: 1px solid #e2e8f0;
        }
        
        .notif-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f1f5f9;
        }

        .notif-header h2 {
            margin: 0;
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--text-dark);
            letter-spacing: -0.5px;
        }

        .notif-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
            width: 100%;
        }

        .notif-item {
            display: flex;
            gap: 20px;
            padding: 25px;
            border-radius: 16px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            width: 100%;
            min-width: 0;
        }

        .notif-item:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: rgba(244, 208, 63, 0.5); /* Yellow border hint on hover */
        }

        .notif-item.not-clickable {
            cursor: default;
        }

        .notif-item.not-clickable:hover {
            transform: none;
            box-shadow: none;
            border-color: #e2e8f0;
        }

        .notif-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            color: var(--dark-bg);
            font-size: 0.82rem;
            font-weight: 800;
            font-family: 'Montserrat', sans-serif;
        }

        .notif-item:hover .notif-action {
            color: #ca8a04;
        }

        .notif-icon {
            width: 50px;
            height: 50px;
            background: rgba(244, 208, 63, 0.15); /* Soft yellow background */
            color: var(--dark-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .notif-content h4 {
            margin: 0 0 8px 0;
            color: var(--text-dark);
            font-size: 1.15rem;
            font-weight: 700;
            font-family: 'Montserrat', sans-serif;
            overflow-wrap: anywhere;
        }

        .notif-content p {
            margin: 0;
            color: #64748b;
            font-size: 0.95rem;
            line-height: 1.5;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .notif-time {
            display: block;
            margin-top: 12px;
            font-size: 0.85rem;
            color: #94a3b8;
            font-weight: 500;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #64748b;
        }
        
        .footer { background: var(--dark-bg); color: #cbd5e1; text-align: center; padding: 30px; margin-top: auto; font-size: 0.9rem; }

        @media (max-width: 768px) {
            .nav-links.desktop-only { display: none; }
            .nav-logo-text { display: none; }
            .navbar { padding: 14px 5%; }
            .nav-actions { gap: 12px; }
            .profile-trigger { padding: 6px 10px; max-width: 235px; }
            .profile-name {
                max-width: 140px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .page-container {
                margin: 115px auto 50px auto;
                padding: 0 14px;
                display: block;
            }
            .notifications-wrapper {
                display: block;
                opacity: 1;
                transform: none;
                visibility: visible;
                padding: 20px;
                border-radius: 16px;
                overflow: visible;
            }
            .notif-header {
                margin-bottom: 18px;
                padding-bottom: 14px;
                align-items: flex-start;
            }
            .notif-header h2 { font-size: 1.8rem; }
            .notif-list { gap: 14px; }
            .notif-item { gap: 14px; padding: 16px; align-items: flex-start; }
            .notif-icon { width: 42px; height: 42px; font-size: 1.15rem; }
            .notif-content { min-width: 0; width: 100%; }
            .notif-content h4 { font-size: 1rem; }
            .notif-content p { font-size: .9rem; }
            .notif-time { font-size: .78rem; }
            .empty-state { padding: 45px 10px; }
            .footer { padding: 22px 16px; }
        }

        @media (max-width: 420px) {
            .page-container { margin-top: 105px; padding: 0 10px; }
            .notifications-wrapper { padding: 16px; border-radius: 14px; }
            .notif-header h2 { font-size: 1.45rem; }
            .notif-item { flex-direction: row; padding: 14px; }
            .profile-trigger { max-width: 220px; }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="nav-logo">
            <a href="index.php" style="display:flex; align-items:center;">
                <!-- Exact verbatim picture logo requested -->
                <img src="assets/logo1.png"
     alt="JEJ Top Priority Corporation"
     style="
     height:70px;
     width:auto;
     object-fit:contain;
     filter:drop-shadow(0 2px 6px rgba(0,0,0,.35));
     ">
                <!-- Text kept next to the logo -->
                <div class="nav-logo-text">
                    <h2>JEJ Top Priority Corporation</h2>
                    <span>SERVICES & REAL ESTATE</span>
                </div>
            </a>
        </div>
        
        <div class="nav-links desktop-only">
            <a href="index.php">Home</a>
            <a href="index.php#properties">Properties</a>
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

    <div class="page-container">
        <div class="notifications-wrapper animate-on-scroll">
            <div class="notif-header">
                <h2>Your Notifications</h2>
            </div>

            <div class="notif-list">
                <?php if($result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <?php
                            $notif_title = strtolower($row['title'] ?? '');
                            $notif_message = strtolower($row['message'] ?? '');
                            $notification_url = 'notifications.php';
                            $notification_label = 'View Details';
                            $notification_icon = 'fa-circle-info';

                            if (strpos($notif_title, 'contact inquiry') !== false || strpos($notif_message, 'contact inquiry') !== false || strpos($notif_message, 'inquiry') !== false) {
                                $notification_url = 'inquiries.php?status=UNREAD';
                                $notification_label = 'Open Inquiries';
                                $notification_icon = 'fa-envelope-open-text';
                            } elseif (strpos($notif_title, 'reservation') !== false || strpos($notif_message, 'reservation') !== false) {
                                $notification_url = 'reservation.php';
                                $notification_label = 'Open Reservations';
                                $notification_icon = 'fa-file-signature';
                            } elseif (strpos($notif_title, 'payment') !== false || strpos($notif_message, 'payment') !== false) {
                                $notification_url = 'verify_payments.php';
                                $notification_label = 'Open Payments';
                                $notification_icon = 'fa-file-invoice-dollar';
                            }
                        ?>
                        <?php if($is_admin_notification_clickable): ?>
                            <a href="<?= htmlspecialchars($notification_url) ?>" class="notif-item">
                        <?php else: ?>
                            <div class="notif-item not-clickable">
                        <?php endif; ?>
                                <div class="notif-icon">
                                    <i class="fa-solid <?= htmlspecialchars($notification_icon) ?>"></i>
                                </div>
                                <div class="notif-content">
                                    <h4><?= htmlspecialchars($row['title']) ?></h4>
                                    <p><?= htmlspecialchars($row['message']) ?></p>
                                    <span class="notif-time">
                                        <i class="fa-regular fa-clock" style="margin-right: 6px;"></i>
                                        <?= date("F j, Y, g:i a", strtotime($row['created_at'])) ?>
                                    </span>

                                    <?php if($is_admin_notification_clickable): ?>
                                        <span class="notif-action">
                                            <?= htmlspecialchars($notification_label) ?> <i class="fa-solid fa-arrow-right"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                        <?php if($is_admin_notification_clickable): ?>
                            </a>
                        <?php else: ?>
                            </div>
                        <?php endif; ?>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-bell-slash" style="font-size: 45px; margin-bottom: 20px; color: #cbd5e1;"></i>
                        <h3 style="color: var(--text-dark); margin-bottom: 8px; font-size: 1.5rem; font-family: 'Montserrat', sans-serif;">No notifications yet</h3>
                        <p>When you receive updates about your reservations or inquiries, they will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer natively pushed to bottom by Flex Column body -->
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> JEJ Top Priority Corporation. All Rights Reserved. Built with precision.</p>
    </footer>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
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
    </script>
</body>
</html>
<?php ob_end_flush(); ?>
