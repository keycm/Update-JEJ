<?php
// profile.php

ob_start();

require_once 'config.php';

// Require Login
checkLogin();

// All logged-in users can access their own profile
requireRole([
    'SUPER ADMIN',
    'ADMIN',
    'MANAGER',
    'CASHIER',
    'AGENT',
    'BUYER'
]);



$user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

function profile_column_exists(mysqli $conn, string $column): bool {
    static $cache = [];
    if (isset($cache[$column])) {
        return $cache[$column];
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users'
        AND COLUMN_NAME = ?
    ");
    if (!$stmt) {
        $cache[$column] = false;
        return false;
    }

    $stmt->bind_param("s", $column);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    $cache[$column] = ((int)$count > 0);
    return $cache[$column];
}

function profile_safe_photo_path(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }

    $path = str_replace('\\', '/', $path);
    if (strpos($path, '..') !== false || preg_match('#^(https?:)?//#i', $path)) {
        return '';
    }

    return $path;
}

$has_phone_column = profile_column_exists($conn, 'phone');
$has_address_column = profile_column_exists($conn, 'address');
$has_profile_photo_column = profile_column_exists($conn, 'profile_photo');
$has_created_at_column = profile_column_exists($conn, 'created_at');
$has_updated_at_column = profile_column_exists($conn, 'updated_at');

if (empty($_SESSION['profile_csrf_token'])) {
    $_SESSION['profile_csrf_token'] = bin2hex(random_bytes(32));
}
$profile_csrf_token = $_SESSION['profile_csrf_token'];

// --- NOTIFICATION CHECK LOGIC ---
$unread_count = 0;
$notif_stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $user_id);
    $notif_stmt->execute();
    $notif_stmt->bind_result($unread_count);
    $notif_stmt->fetch();
    $notif_stmt->close();
}

$select_fields = ['fullname', 'email', 'role', 'password'];
if ($has_phone_column) {
    $select_fields[] = 'phone';
}
if ($has_address_column) {
    $select_fields[] = 'address';
}
if ($has_profile_photo_column) {
    $select_fields[] = 'profile_photo';
}
if ($has_created_at_column) {
    $select_fields[] = 'created_at';
}
if ($has_updated_at_column) {
    $select_fields[] = 'updated_at';
}

// Fetch current user details
$stmt = $conn->prepare("SELECT " . implode(', ', $select_fields) . " FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$profile_photo = profile_safe_photo_path($user['profile_photo'] ?? '');
$profile_avatar_letter = strtoupper(substr((string)($user['fullname'] ?? 'U'), 0, 1));
$back_url = in_array($_SESSION['role'] ?? '', ['SUPER ADMIN', 'ADMIN', 'MANAGER', 'CASHIER'], true) ? 'admin.php' : 'my_reservations.php';
$back_label = in_array($_SESSION['role'] ?? '', ['SUPER ADMIN', 'ADMIN', 'MANAGER', 'CASHIER'], true) ? 'Back to Dashboard' : 'Back to My Reservations';
$current_password_field = 'current_password_' . substr(hash('sha256', $profile_csrf_token), 0, 12);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $submitted_token = $_POST['profile_csrf_token'] ?? '';
    $fullname = trim((string)($_POST['fullname'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $current_password = (string)($_POST['current_password'] ?? '');
    foreach ($_POST as $field_name => $field_value) {
        if (strpos((string)$field_name, 'current_password_') === 0) {
            $current_password = (string)$field_value;
            break;
        }
    }
    $new_password = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');
    $remove_profile_photo = isset($_POST['remove_profile_photo']) && $_POST['remove_profile_photo'] === '1';
    $email_changed = strcasecmp($email, (string)($user['email'] ?? '')) !== 0;
    $password_change_requested = ($new_password !== '' || $confirm_password !== '');
    $new_profile_photo = $profile_photo;

    if (!hash_equals($_SESSION['profile_csrf_token'] ?? '', $submitted_token)) {
        $error_msg = "Security check failed. Please refresh the page and try again.";
    } elseif (empty($fullname) || empty($email)) {
        $error_msg = "Your Fullname and Email are required to keep your profile updated.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = "Please enter a valid email address.";
    } elseif (strlen($fullname) > 120 || strlen($email) > 150) {
        $error_msg = "Full name or email is too long. Please shorten the details and try again.";
    } elseif ($has_phone_column && $phone !== '' && (!preg_match('/^[0-9+()\-\s]{7,30}$/', $phone) || strlen($phone) > 30)) {
        $error_msg = "Please enter a valid phone number.";
    } elseif ($has_address_column && strlen($address) > 500) {
        $error_msg = "Address is too long. Please keep it within 500 characters.";
    } else {
        $email_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        if ($email_check) {
            $email_check->bind_param("si", $email, $user_id);
            $email_check->execute();
            $email_exists = $email_check->get_result()->num_rows > 0;
            $email_check->close();
            if ($email_exists) {
                $error_msg = "That email address is already used by another account.";
            }
        }

        $sensitive_change = $email_changed || $password_change_requested;
        if (empty($error_msg) && $sensitive_change) {
            $stored_password = (string)($user['password'] ?? '');
            $current_ok = password_verify($current_password, $stored_password) || hash_equals($stored_password, md5($current_password));
            if ($current_password === '' || !$current_ok) {
                $error_msg = "Please enter your current password before changing your email or password.";
            }
        }

        // Handle password update if fields are filled
        if (empty($error_msg) && $password_change_requested) {
            if ($new_password !== $confirm_password) {
                $error_msg = "The passwords you entered do not match. Please try again.";
            } elseif (strlen($new_password) < 8) {
                $error_msg = "Your new password must be at least 8 characters long.";
            } elseif (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
                $error_msg = "Your new password must include uppercase, lowercase, and number characters.";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            }
        }

        if (empty($error_msg) && ($remove_profile_photo || !empty($_FILES['profile_photo']['name']))) {
            if (!$has_profile_photo_column) {
                $error_msg = "Profile photo storage is not installed yet. Please add the profile_photo column first.";
            } elseif ($remove_profile_photo) {
                if ($new_profile_photo !== '' && strpos($new_profile_photo, 'uploads/profile_photos/') === 0 && is_file(__DIR__ . '/' . $new_profile_photo)) {
                    @unlink(__DIR__ . '/' . $new_profile_photo);
                }
                $new_profile_photo = '';
            } elseif (!empty($_FILES['profile_photo']['name'])) {
                $photo = $_FILES['profile_photo'];
                $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
                $max_photo_size = 5 * 1024 * 1024;
                $ext = strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));

                if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $error_msg = "Profile photo upload failed. Please try another image.";
                } elseif (($photo['size'] ?? 0) > $max_photo_size) {
                    $error_msg = "Profile photo must be 5MB or smaller.";
                } elseif (!in_array($ext, $allowed_ext, true)) {
                    $error_msg = "Profile photo must be JPG, PNG, or WEBP only.";
                } elseif (!@getimagesize($photo['tmp_name'])) {
                    $error_msg = "Uploaded file is not a valid image.";
                } else {
                    $upload_dir = __DIR__ . '/uploads/profile_photos';
                    if (!is_dir($upload_dir)) {
                        @mkdir($upload_dir, 0755, true);
                    }

                    if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                        $error_msg = "Profile photo folder is not writable.";
                    } else {
                        $filename = 'profile_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $target = $upload_dir . '/' . $filename;
                        if (move_uploaded_file($photo['tmp_name'], $target)) {
                            if ($new_profile_photo !== '' && strpos($new_profile_photo, 'uploads/profile_photos/') === 0 && is_file(__DIR__ . '/' . $new_profile_photo)) {
                                @unlink(__DIR__ . '/' . $new_profile_photo);
                            }
                            $new_profile_photo = 'uploads/profile_photos/' . $filename;
                        } else {
                            $error_msg = "Could not save the profile photo.";
                        }
                    }
                }
            }
        }

        if (empty($error_msg)) {
            $set_parts = ['fullname = ?', 'email = ?'];
            $values = [$fullname, $email];
            $types = 'ss';

            if ($has_phone_column) {
                $set_parts[] = 'phone = ?';
                $values[] = $phone;
                $types .= 's';
            }
            if ($has_address_column) {
                $set_parts[] = 'address = ?';
                $values[] = $address;
                $types .= 's';
            }
            if ($has_profile_photo_column) {
                $set_parts[] = 'profile_photo = ?';
                $values[] = $new_profile_photo;
                $types .= 's';
            }
            if (!empty($hashed_password)) {
                $set_parts[] = 'password = ?';
                $values[] = $hashed_password;
                $types .= 's';
            }
            if ($has_updated_at_column) {
                $set_parts[] = 'updated_at = NOW()';
            }

            $values[] = $user_id;
            $types .= 'i';

            $update_stmt = $conn->prepare("UPDATE users SET " . implode(', ', $set_parts) . " WHERE id = ?");
            if ($update_stmt) {
                $bind_values = [$types];
                foreach ($values as $key => $value) {
                    $bind_values[] = &$values[$key];
                }
                call_user_func_array([$update_stmt, 'bind_param'], $bind_values);
            } else {
                $error_msg = "We encountered a small database issue. Please try again later.";
            }
        }

        if (empty($error_msg) && isset($update_stmt)) {
            if ($update_stmt->execute()) {
                $success_msg = "Profile updated successfully.";
                // Update session variables
                $_SESSION['fullname'] = $fullname;
                $user['fullname'] = $fullname;
                $user['email'] = $email;
                if ($has_phone_column) {
                    $user['phone'] = $phone;
                }
                if ($has_address_column) {
                    $user['address'] = $address;
                }
                if ($has_profile_photo_column) {
                    $user['profile_photo'] = $new_profile_photo;
                    $profile_photo = profile_safe_photo_path($new_profile_photo);
                }
                if ($has_updated_at_column) {
                    $user['updated_at'] = date('Y-m-d H:i:s');
                }
                $_SESSION['profile_csrf_token'] = bin2hex(random_bytes(32));
                $profile_csrf_token = $_SESSION['profile_csrf_token'];
            } else {
                $error_msg = "We encountered a small database issue. Please try again later.";
            }
            $update_stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | JEJ Top Priority Corporation</title>
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
        @keyframes fadeInUp { 0% { opacity: 0; transform: translateY(40px); } 100% { opacity: 1; transform: translateY(0); } }
        @keyframes fadeIn { 0% { opacity: 0; } 100% { opacity: 1; } }
        @keyframes dropIn { from { opacity: 0; transform: scale(0.9) translateY(-10px); } to { opacity: 1; transform: scale(1) translateY(0); } }

        .animate-on-scroll {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .animate-on-scroll.visible { opacity: 1; transform: translateY(0); }

        .hero-title { animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; }
        .hero-subtitle { animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.2s forwards; opacity: 0; }

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
        .notification-bell { position: relative; color: white; font-size: 22px; transition: color 0.3s; }
        .notification-bell:hover { color: var(--primary-yellow); transform: scale(1.1); }
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
        .avatar-circle { background: var(--primary-yellow); color: var(--dark-bg); border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1rem; overflow: hidden; }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        
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

        /* Hero Section (Profile specific) */
        .hero {
            margin-top: 85px; 
            height: 40vh; min-height: 350px;
            background: linear-gradient(to right, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.6)), url('https://images.unsplash.com/photo-1541888086425-d81bb19240f5?ixlib=rb-4.0.3') center/cover no-repeat;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: white; text-align: center; padding: 0 20px 60px 20px;
        }
        .hero h1 { font-size: 3.5rem; font-weight: 800; margin-bottom: 10px; letter-spacing: -1px; text-transform: uppercase; text-shadow: 0 2px 15px rgba(0,0,0,0.4); }
        .hero p { font-size: 1.1rem; font-weight: 400; opacity: 0.9; max-width: 600px; color: #cbd5e1; text-shadow: 0 1px 5px rgba(0,0,0,0.3); }

        /* Profile Layout */
        .container { flex-grow: 1; width: 100%; max-width: 1200px; margin: 0 auto 80px auto; padding: 0 5%; }
        
        .profile-wrapper {
            max-width: 900px;
            margin: -80px auto 0 auto;
            background: white;
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
            position: relative;
            z-index: 10;
            padding: 34px 40px 40px;
            border: 1px solid #e2e8f0;
        }

        .profile-avatar-container { text-align: center; margin-top: -90px; margin-bottom: 12px; }
        
        .profile-avatar-large {
            width: 110px; height: 110px;
            background: var(--primary-yellow);
            color: var(--dark-bg); font-size: 2.5rem; font-weight: 800;
            border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            border: 5px solid white; font-family: 'Montserrat', sans-serif; overflow: hidden;
        }
        .profile-avatar-large img { width: 100%; height: 100%; object-fit: cover; }
        .profile-photo-actions {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .photo-upload-btn,
        .photo-remove-btn {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: var(--text-dark);
            padding: 8px 14px;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 800;
            font-family: 'Montserrat', sans-serif;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all .2s ease;
        }
        .photo-upload-btn:hover { background: var(--dark-bg); color: white; border-color: var(--dark-bg); }
        .photo-remove-btn { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
        .photo-remove-btn:hover { background: #dc2626; color: white; border-color: #dc2626; }
        .photo-help {
            width: 100%;
            color: #64748b;
            font-size: .78rem;
            font-weight: 600;
            text-align: center;
            margin-top: 2px;
            line-height: 1.45;
        }

        .user-title { text-align: center; margin-bottom: 24px; }
        .user-title h2 { margin: 0; font-size: 2rem; font-weight: 800; color: var(--text-dark); letter-spacing: -0.5px; }
        
        .role-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(244, 208, 63, 0.15); color: var(--dark-bg);
            padding: 6px 16px; border-radius: 30px;
            font-size: 0.85rem; font-weight: 800; text-transform: uppercase;
            margin-top: 10px; border: 1px solid var(--primary-yellow); letter-spacing: 0.5px;
            font-family: 'Montserrat', sans-serif;
        }

        /* Form UI Updates */
        .form-section-title {
            font-size: 1.3rem; font-weight: 800; color: var(--text-dark);
            margin-bottom: 16px; display: flex; align-items: center; gap: 10px;
            border-bottom: 2px solid #f1f5f9; padding-bottom: 10px;
        }
        .form-section-title i { color: var(--primary-yellow); }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-bottom: 22px; }
        .form-row.single { grid-template-columns: 1fr; }
        .form-group { width: 100%; }
        
        .form-label { display: block; margin-bottom: 8px; font-weight: 700; color: var(--text-dark); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; font-family: 'Montserrat', sans-serif;}
        
        .form-control {
            width: 100%; padding: 15px 20px; border: 2px solid #e2e8f0;
            border-radius: 12px; outline: none; background: #f8fafc;
            font-size: 1rem; color: var(--text-dark); font-family: 'Roboto', sans-serif; font-weight: 500;
            transition: all 0.3s;
        }
        .form-control::placeholder { color: #94a3b8; }
        .form-control:focus { border-color: var(--primary-yellow); background: white; box-shadow: 0 0 0 4px rgba(244,208,63,0.1); }
        textarea.form-control { min-height: 92px; resize: vertical; }
        .form-control[disabled] { cursor: not-allowed; opacity: .75; }
        .account-details-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 26px;
        }
        .account-detail-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 12px;
            min-height: 72px;
        }
        .account-detail-card span {
            display: block;
            color: #64748b;
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            font-family: 'Montserrat', sans-serif;
            margin-bottom: 6px;
        }
        .account-detail-card strong {
            display: block;
            color: var(--text-dark);
            font-size: .95rem;
            word-break: break-word;
        }
        .password-field { position: relative; }
        .password-field .form-control { padding-right: 52px; }
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 10px;
            background: #e2e8f0;
            color: #475569;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all .2s ease;
        }
        .toggle-password:hover { background: var(--dark-bg); color: white; }
        .security-note {
            font-size: 0.9rem;
            color: #64748b;
            margin-top: -10px;
            margin-bottom: 25px;
            font-weight: 600;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
        }
        .password-rules {
            margin-top: 8px;
            color: #64748b;
            font-size: .82rem;
            font-weight: 600;
            line-height: 1.45;
        }

        .section-divider { height: 1px; background: transparent; margin: 28px 0; }

        .btn-submit {
            background: var(--dark-bg); color: white; border: none;
            padding: 16px 40px; border-radius: 12px;
            font-size: 1.05rem; font-weight: 800; font-family: 'Montserrat', sans-serif;
            cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .btn-submit:hover { background: #000; color: var(--primary-yellow); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2); }
        .btn-secondary {
            background: #f8fafc;
            color: var(--text-dark);
            border: 2px solid #e2e8f0;
            padding: 16px 28px;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 800;
            font-family: 'Montserrat', sans-serif;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all .2s ease;
        }
        .btn-secondary:hover { background: #e2e8f0; transform: translateY(-2px); }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        /* Alerts */
        .alert { padding: 18px 25px; border-radius: 12px; margin-bottom: 30px; font-weight: 600; display: flex; align-items: center; gap: 15px; font-size: 0.95rem; box-shadow: var(--shadow-sm); }
        .alert-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        .footer { background: var(--dark-bg); color: #cbd5e1; text-align: center; padding: 30px; margin-top: auto; font-size: 0.9rem; }

        @media (max-width: 768px) {
            .nav-links.desktop-only { display: none; }
            .nav-logo-text { display: none; }
            .form-row { grid-template-columns: 1fr; gap: 20px; }
            .account-details-grid { grid-template-columns: 1fr 1fr; }
            .profile-wrapper { padding: 30px 20px; margin-top: -60px; border-radius: 20px; }
            textarea.form-control { min-height: 84px; }
            .hero h1 { font-size: 2.5rem; }
            .btn-submit, .btn-secondary { width: 100%; }
            .form-actions { flex-direction: column-reverse; }
        }
        @media (max-width: 520px) {
            .account-details-grid { grid-template-columns: 1fr; }
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
                        <?php if($profile_photo): ?>
                            <img src="<?= htmlspecialchars($profile_photo) ?>" alt="Profile photo">
                        <?php else: ?>
                            <?= htmlspecialchars(strtoupper(substr($_SESSION['fullname'], 0, 1))) ?>
                        <?php endif; ?>
                    </div>
                </button>
                <div class="profile-dropdown-menu" id="profileDropdown">
                    <a href="profile.php" class="profile-dropdown-item" style="color: var(--dark-bg);"><i class="fa-regular fa-user" style="color: var(--primary-yellow);"></i> My Profile</a>
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

    <header class="hero">
        <h1 class="hero-title">My Profile</h1>
        <p class="hero-subtitle">Manage your account information and security settings.</p>
    </header>

    <div class="container">
        <div class="profile-wrapper animate-on-scroll">
            
            <div class="profile-avatar-container">
                <div class="profile-avatar-large">
                    <?php if($profile_photo): ?>
                        <img src="<?= htmlspecialchars($profile_photo) ?>" alt="Profile photo">
                    <?php else: ?>
                        <?= htmlspecialchars($profile_avatar_letter) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="user-title">
                <h2><?= htmlspecialchars($user['fullname']) ?></h2>
                <div class="role-badge">
                    <i class="fa-solid fa-shield-halved"></i> <?= htmlspecialchars($user['role']) ?>
                </div>
            </div>

            <div class="profile-body">
                <?php if($success_msg): ?>
                    <div class="alert alert-success profile-alert">
                        <i class="fa-solid fa-circle-check" style="font-size: 1.4rem;"></i> 
                        <?= htmlspecialchars($success_msg) ?>
                    </div>
                <?php endif; ?>
                <?php if($error_msg): ?>
                    <div class="alert alert-error profile-alert">
                        <i class="fa-solid fa-circle-exclamation" style="font-size: 1.4rem;"></i> 
                        <?= htmlspecialchars($error_msg) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="profile.php" autocomplete="off" enctype="multipart/form-data">
                    <input type="hidden" name="profile_csrf_token" value="<?= htmlspecialchars($profile_csrf_token) ?>">
                    <input type="hidden" name="remove_profile_photo" id="removeProfilePhoto" value="0">

                    <div class="profile-photo-actions">
                        <input type="file" name="profile_photo" id="profilePhotoInput" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" hidden>
                        <label class="photo-upload-btn" for="profilePhotoInput">
                            <i class="fa-solid fa-camera"></i> <?= $profile_photo ? 'Change Photo' : 'Upload Photo' ?>
                        </label>
                        <?php if($profile_photo): ?>
                            <button type="button" class="photo-remove-btn" id="removePhotoBtn">
                                <i class="fa-solid fa-trash"></i> Remove Photo
                            </button>
                        <?php endif; ?>
                        <div class="photo-help" id="profilePhotoName">
                            Use a clear 1x1 photo. JPG, PNG, or WEBP only. Max 5MB.
                            <?php if(!$has_profile_photo_column): ?>
                                Add the profile_photo column first to save photos.
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="section-divider"></div>

                    <h3 class="form-section-title"><i class="fa-solid fa-circle-info"></i> Account Details</h3>
                    <div class="account-details-grid">
                        <div class="account-detail-card">
                            <span>Role</span>
                            <strong><?= htmlspecialchars($user['role'] ?? 'User') ?></strong>
                        </div>
                        <div class="account-detail-card">
                            <span>Account ID</span>
                            <strong>#<?= htmlspecialchars((string)$user_id) ?></strong>
                        </div>
                        <div class="account-detail-card">
                            <span>Member Since</span>
                            <strong><?= !empty($user['created_at']) ? htmlspecialchars(date('M d, Y', strtotime($user['created_at']))) : 'Not available' ?></strong>
                        </div>
                        <div class="account-detail-card">
                            <span>Last Updated</span>
                            <strong><?= !empty($user['updated_at']) ? htmlspecialchars(date('M d, Y', strtotime($user['updated_at']))) : 'Not available' ?></strong>
                        </div>
                    </div>
                    
                    <h3 class="form-section-title"><i class="fa-regular fa-id-badge"></i> Personal Information</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($user['fullname']) ?>" maxlength="120" autocomplete="name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>" maxlength="150" autocomplete="email" required>
                        </div>
                    </div>

                    <div class="form-row single">
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" maxlength="30" autocomplete="tel" placeholder="09XX XXX XXXX" <?= $has_phone_column ? '' : 'disabled' ?>>
                            <?php if(!$has_phone_column): ?><div class="password-rules">Add the phone column first to save phone numbers.</div><?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row single">
                        <div class="form-group">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" maxlength="500" autocomplete="street-address" placeholder="Complete address" <?= $has_address_column ? '' : 'disabled' ?>><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                            <?php if(!$has_address_column): ?><div class="password-rules">Add the address column first to save buyer address.</div><?php endif; ?>
                        </div>
                    </div>

                    <div class="section-divider"></div>

                    <h3 class="form-section-title"><i class="fa-solid fa-lock"></i> Security Details</h3>
                    <p class="security-note">
                        Current password is required for email or password changes.
                    </p>

                    <div class="form-row single">
                        <div class="form-group">
                            <label class="form-label">Current Password</label>
                            <div class="password-field">
                                <input type="password" name="<?= htmlspecialchars($current_password_field) ?>" class="form-control" placeholder="Required for email/password changes" autocomplete="new-password" data-lpignore="true" data-form-type="other" readonly onfocus="this.removeAttribute('readonly');">
                                <button type="button" class="toggle-password" aria-label="Show current password"><i class="fa-regular fa-eye"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <div class="password-field">
                                <input type="password" name="new_password" class="form-control" placeholder="Create a new password" autocomplete="new-password">
                                <button type="button" class="toggle-password" aria-label="Show new password"><i class="fa-regular fa-eye"></i></button>
                            </div>
                            <div class="password-rules">Minimum 8 characters with uppercase, lowercase, and number.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <div class="password-field">
                                <input type="password" name="confirm_password" class="form-control" placeholder="Type it again to confirm" autocomplete="new-password">
                                <button type="button" class="toggle-password" aria-label="Show confirm password"><i class="fa-regular fa-eye"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="<?= htmlspecialchars($back_url) ?>" class="btn-secondary">
                            <i class="fa-solid fa-arrow-left"></i> <?= htmlspecialchars($back_label) ?>
                        </a>
                        <button type="submit" class="btn-submit">
                            Save Changes <i class="fa-solid fa-floppy-disk"></i>
                        </button>
                    </div>
                </form>
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

            document.querySelectorAll('input[name^="current_password_"], input[name="current_password"], input[name="new_password"], input[name="confirm_password"]').forEach(input => {
                input.type = 'password';
                input.value = '';
            });

            const profileAlerts = document.querySelectorAll('.profile-alert');
            profileAlerts.forEach(alert => {
                if (alert.classList.contains('alert-success')) {
                    setTimeout(() => {
                        alert.style.transition = 'opacity .35s ease, transform .35s ease';
                        alert.style.opacity = '0';
                        alert.style.transform = 'translateY(-8px)';
                        setTimeout(() => alert.remove(), 400);
                    }, 3000);
                }
            });

            const photoInput = document.getElementById('profilePhotoInput');
            const photoName = document.getElementById('profilePhotoName');
            const removePhotoBtn = document.getElementById('removePhotoBtn');
            const removeProfilePhoto = document.getElementById('removeProfilePhoto');

            if (photoInput && photoName) {
                photoInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        photoName.textContent = 'Selected: ' + this.files[0].name;
                        if (removeProfilePhoto) removeProfilePhoto.value = '0';
                    }
                });
            }

            if (removePhotoBtn && removeProfilePhoto && photoInput && photoName) {
                removePhotoBtn.addEventListener('click', function() {
                    removeProfilePhoto.value = '1';
                    photoInput.value = '';
                    photoName.textContent = 'Photo will be removed after saving changes.';
                });
            }

            document.querySelectorAll('.toggle-password').forEach(button => {
                button.addEventListener('click', function() {
                    const input = this.parentElement.querySelector('input');
                    const icon = this.querySelector('i');
                    if (!input || !icon) return;

                    const show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    icon.classList.toggle('fa-eye', !show);
                    icon.classList.toggle('fa-eye-slash', show);
                });
            });
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>
