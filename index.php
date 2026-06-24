<?php
// 1. SAFE REDIRECTS: Start output buffering to prevent "Headers already sent" errors
ob_start(); 

// 2. SAFE SESSIONS: Harden session cookies before starting the session
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

// 3. Security headers for the public landing page
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
}

// 4. Include your database connection
require_once 'config.php';

// Small safe helper functions used by this page only.
if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('jej_first_non_empty')) {
    function jej_first_non_empty(array $row, array $keys, $default = '') {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return $row[$key];
            }
        }
        return $default;
    }
}

if (!function_exists('jej_safe_slug')) {
    function jej_safe_slug($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/\([^)]*\)/', '', $value); // also try base location without parenthesis
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim($value, '_');
    }
}

if (!function_exists('jej_lot_image_url')) {
    function jej_lot_image_url($file, $location = '') {
        $default = 'default_lot.jpg';
        $file = trim((string)$file);

        $candidates = [];
        $location = trim((string)$location);
        $full_location_slug = strtolower(preg_replace('/[^a-z0-9]+/', '_', $location));
        $full_location_slug = trim($full_location_slug, '_');
        $base_location_slug = jej_safe_slug($location);

        // Prefer actual uploaded lot image if it is available.
        if ($file !== '') {
            $file = str_replace('\\', '/', $file);
            $file = ltrim($file, '/');

            // Block path traversal and non-image values before using the value in <img src>.
            if (strpos($file, '..') === false && preg_match('/\.(jpe?g|png|webp|gif)$/i', $file)) {
                if (preg_match('#^(assets|uploads|storage)/#i', $file)) {
                    $candidates[] = $file;
                } else {
                    $candidates[] = 'uploads/' . $file;
                    $candidates[] = 'assets/' . $file;
                    $candidates[] = $file;
                }
            }
        }

        // Professional fallback per location: uploads/map_lambakin.jpg, uploads/map_san_miguel.jpg, etc.
        foreach (array_unique(array_filter([$full_location_slug, $base_location_slug])) as $slug) {
            $candidates[] = 'uploads/map_' . $slug . '.jpg';
            $candidates[] = 'uploads/map_' . $slug . '.png';
            $candidates[] = 'assets/map_' . $slug . '.jpg';
            $candidates[] = 'assets/map_' . $slug . '.png';
        }

        $candidates[] = 'uploads/' . $default;
        $candidates[] = 'assets/' . $default;

        foreach (array_unique($candidates) as $candidate) {
            if (is_file(__DIR__ . '/' . $candidate)) {
                return $candidate;
            }
        }

        return 'assets/' . $default;
    }
}

if (!function_exists('jej_filter_url_without')) {
    function jej_filter_url_without(array $remove_keys) {
        $query = $_GET;
        foreach ($remove_keys as $key) {
            unset($query[$key]);
        }

        $query = array_filter($query, function($value) {
            return trim((string)$value) !== '';
        });

        return 'index.php' . (!empty($query) ? '?' . http_build_query($query) : '') . '#properties';
    }
}

if (!function_exists('jej_format_filter_money')) {
    function jej_format_filter_money($value) {
        if ($value === '' || !is_numeric($value)) {
            return '';
        }

        $amount = (float)$value;
        if ($amount >= 1000000) {
            return '₱' . rtrim(rtrim(number_format($amount / 1000000, 1), '0'), '.') . 'M';
        }
        if ($amount >= 1000) {
            return '₱' . rtrim(rtrim(number_format($amount / 1000, 1), '0'), '.') . 'K';
        }

        return '₱' . number_format($amount);
    }
}

/*
|--------------------------------------------------------------------------
| SMTP Configuration
|--------------------------------------------------------------------------
| SMTP settings are loaded in config.php:
| 1) smtp_credentials.php (local-only, optional)
| 2) config/mail.php
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| PHPMailer
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';


// --- CENTRALIZED AUTHENTICATION LOGIC ---
$auth_message = '';
$auth_status = ''; // 'success' or 'error'
$show_modal = '';  // Determines which modal to keep open ('loginModal', 'registerModal', 'otpModal', or 'forgotModal')

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    $session_csrf = $_SESSION['csrf_token'] ?? '';

    if (
        $posted_csrf === '' ||
        $session_csrf === '' ||
        !hash_equals($session_csrf, $posted_csrf)
    ) {
        // Generate a fresh token so the form rendered after this error is immediately usable.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $auth_message = 'Session expired. Please try signing in again.';
        $auth_status = 'error';
        $show_modal = 'registerModal';
        if (isset($_POST['login'])) {
            $show_modal = 'loginModal';
        } elseif (isset($_POST['verify_otp'])) {
            $show_modal = 'otpModal';
        } elseif (isset($_POST['forgot_request']) || isset($_POST['forgot_reset'])) {
            $show_modal = 'forgotModal';
        }
    } else {

    // STEP 1: Process Registration Request & Send OTP
    if (isset($_POST['register_request'])) {
        $fullname = trim($_POST['fullname'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($fullname === '' || $phone === '' || $email === '' || $password === '' || $confirm_password === '') {
            $auth_message = "Please complete all registration fields.";
            $auth_status = "error";
            $show_modal = "registerModal";
        } elseif (strlen($fullname) > 120 || strlen($phone) > 30 || strlen($email) > 150) {
            $auth_message = "One or more fields are too long. Please check your details.";
            $auth_status = "error";
            $show_modal = "registerModal";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $auth_message = "Please enter a valid email address.";
            $auth_status = "error";
            $show_modal = "registerModal";
        } elseif (!preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) {
            $auth_message = "Please enter a valid phone number.";
            $auth_status = "error";
            $show_modal = "registerModal";
        } elseif (strlen($password) < 8) {
            $auth_message = "Password must be at least 8 characters.";
            $auth_status = "error";
            $show_modal = "registerModal";
        } elseif ($password !== $confirm_password) {
            $auth_message = "Passwords do not match.";
            $auth_status = "error";
            $show_modal = "registerModal";
        } else {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            if (!$stmt) {
                $auth_message = "Database Error: " . $conn->error;
                $auth_status = "error";
                $show_modal = "registerModal";
            } else {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();
                
                if ($stmt->num_rows > 0) {
                    $auth_message = "Email is already registered. Please sign in.";
                    $auth_status = "error";
                    $show_modal = "registerModal";
                } else {
                    // Generate 6-digit OTP
                    $otp = rand(100000, 999999);
                    
                    // Store registration data temporarily in session
                    $_SESSION['temp_reg'] = [
                        'fullname' => $fullname,
                        'phone' => $phone,
                        'email' => $email,
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                        'otp' => $otp,
                        'otp_expires' => time() + 600
                    ];

                    // Send OTP via Email
                    if (empty(SMTP_USER) || empty(SMTP_PASS)) {
                        $auth_message = "SMTP is not configured. Please update config/mail.php with your Gmail address and Gmail App Password.";
                        $auth_status = "error";
                        $show_modal = "registerModal";
                    } else {
                        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host       = SMTP_HOST;
                            $mail->SMTPAuth   = true;
                            $mail->Username   = SMTP_USER;
                            $mail->Password   = SMTP_PASS;
                            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port       = 587;
                            $mail->CharSet    = 'UTF-8';
                            $mail->Timeout    = 30;

                            // Use this only for localhost/XAMPP where SSL certificate verification can fail.
                            $mail->SMTPOptions = [
                                'ssl' => [
                                    'verify_peer' => false,
                                    'verify_peer_name' => false,
                                    'allow_self_signed' => true
                                ]
                            ];

                            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                            $mail->addAddress($email, $fullname);
                            $mail->isHTML(true);
                            $mail->Subject = 'Your JEJ Registration OTP';
                            $mail->Body    = "<h3>Verify Your Account</h3><p>Your OTP for registration is: <b style='font-size:22px; letter-spacing:3px;'>$otp</b></p><p>Please enter this code on the website to complete your registration.</p>";
                            $mail->AltBody = "Your JEJ registration OTP is: $otp";

                            $mail->send();
                            $auth_message = "OTP sent to your email. Please verify to continue.";
                            $auth_status = "success";
                            $show_modal = "otpModal";
                        } catch (Throwable $e) {
                            $debug = (defined('SMTP_SHOW_ERROR') && SMTP_SHOW_ERROR) ? ' Error: ' . $e->getMessage() : '';
                            $auth_message = "Failed to send OTP. Please check your Gmail App Password / internet connection." . $debug;
                            $auth_status = "error";
                            $show_modal = "registerModal";
                        }
                    }
                }
                $stmt->close();
            }
        }
    } 

    // STEP 2: Verify OTP and Finalize Account Creation
    elseif (isset($_POST['verify_otp'])) {
        $user_otp = trim($_POST['otp_code'] ?? '');

        if (!preg_match('/^\d{6}$/', $user_otp)) {
            $auth_message = "Please enter the 6-digit OTP code.";
            $auth_status = "error";
            $show_modal = "otpModal";
        } elseif (!isset($_SESSION['temp_reg'])) {
            $auth_message = "Your registration session expired. Please register again.";
            $auth_status = "error";
            $show_modal = "registerModal";
        } elseif (time() > (int)($_SESSION['temp_reg']['otp_expires'] ?? 0)) {
            unset($_SESSION['temp_reg']);
            $auth_message = "OTP expired. Please register again to receive a new code.";
            $auth_status = "error";
            $show_modal = "registerModal";
        } elseif (hash_equals((string)$_SESSION['temp_reg']['otp'], $user_otp)) {
            $data = $_SESSION['temp_reg'];
            $role = 'BUYER'; // Default role
            
            $insert_stmt = $conn->prepare("INSERT INTO users (fullname, phone, email, password, role) VALUES (?, ?, ?, ?, ?)");
            
            if (!$insert_stmt) {
                $auth_message = "SQL Error: " . $conn->error;
                $auth_status = "error";
                $show_modal = "otpModal";
            } else {
                $insert_stmt->bind_param("sssss", $data['fullname'], $data['phone'], $data['email'], $data['password'], $role);
                
                if ($insert_stmt->execute()) {
                    unset($_SESSION['temp_reg']); // Clear temporary session
                    $auth_message = "Registration successful! You can now log in.";
                    $auth_status = "success";
                    $show_modal = "loginModal"; // Switch to Login Modal
                } else {
                    $auth_message = "Error saving account. Please try again.";
                    $auth_status = "error";
                    $show_modal = "otpModal";
                }
                $insert_stmt->close();
            }
        } else {
            $auth_message = "Invalid OTP code. Please check your email.";
            $auth_status = "error";
            $show_modal = "otpModal";
        }
    }
    
    // STEP 3: Forgot Password Request and Reset
    elseif (isset($_POST['forgot_request'])) {
        $forgot_email = strtolower(trim($_POST['forgot_email'] ?? ''));
        $show_modal = 'forgotModal';

        if ($forgot_email === '' || !filter_var($forgot_email, FILTER_VALIDATE_EMAIL)) {
            $auth_message = 'Please enter a valid registered email address.';
            $auth_status = 'error';
        } elseif (strlen($forgot_email) > 150) {
            $auth_message = 'Email address is too long.';
            $auth_status = 'error';
        } else {
            $stmt = $conn->prepare("SELECT id, fullname, email FROM users WHERE email = ? LIMIT 1");
            if (!$stmt) {
                $auth_message = 'Database error. Please try again later.';
                $auth_status = 'error';
            } else {
                $stmt->bind_param("s", $forgot_email);
                $stmt->execute();
                $forgot_user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                // Use a generic message for unknown emails to avoid exposing registered accounts.
                if (!$forgot_user) {
                    unset($_SESSION['forgot_reset']);
                    $auth_message = 'If this email exists in our system, a reset code will be sent.';
                    $auth_status = 'success';
                } elseif (empty(SMTP_USER) || empty(SMTP_PASS)) {
                    $auth_message = 'SMTP is not configured. Please update config/mail.php with your Gmail address and Gmail App Password.';
                    $auth_status = 'error';
                } else {
                    $reset_otp = rand(100000, 999999);
                    $_SESSION['forgot_reset'] = [
                        'user_id' => (int)$forgot_user['id'],
                        'fullname' => $forgot_user['fullname'],
                        'email' => $forgot_user['email'],
                        'otp' => $reset_otp,
                        'otp_expires' => time() + 600
                    ];

                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = SMTP_HOST;
                        $mail->SMTPAuth   = true;
                        $mail->Username   = SMTP_USER;
                        $mail->Password   = SMTP_PASS;
                        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = 587;
                        $mail->CharSet    = 'UTF-8';
                        $mail->Timeout    = 30;
                        $mail->SMTPOptions = [
                            'ssl' => [
                                'verify_peer' => false,
                                'verify_peer_name' => false,
                                'allow_self_signed' => true
                            ]
                        ];

                        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                        $mail->addAddress($forgot_user['email'], $forgot_user['fullname']);
                        $mail->isHTML(true);
                        $mail->Subject = 'Your JEJ Password Reset Code';
                        $mail->Body    = "<h3>Password Reset Request</h3><p>Your password reset code is: <b style='font-size:22px; letter-spacing:3px;'>$reset_otp</b></p><p>This code expires in 10 minutes. If you did not request this, please ignore this email.</p>";
                        $mail->AltBody = "Your JEJ password reset code is: $reset_otp. This code expires in 10 minutes.";

                        $mail->send();
                        $auth_message = 'Reset code sent to your email. Enter the code and your new password.';
                        $auth_status = 'success';
                    } catch (Throwable $e) {
                        unset($_SESSION['forgot_reset']);
                        $debug = (defined('SMTP_SHOW_ERROR') && SMTP_SHOW_ERROR) ? ' Error: ' . $e->getMessage() : '';
                        $auth_message = 'Failed to send reset code. Please check your email settings / internet connection.' . $debug;
                        $auth_status = 'error';
                    }
                }
            }
        }
    }

    elseif (isset($_POST['forgot_reset'])) {
        $reset_code = trim($_POST['reset_code'] ?? '');
        $new_password = $_POST['reset_password'] ?? '';
        $confirm_password = $_POST['reset_confirm_password'] ?? '';
        $show_modal = 'forgotModal';

        if (!isset($_SESSION['forgot_reset'])) {
            $auth_message = 'Your reset session expired. Please request a new code.';
            $auth_status = 'error';
        } elseif (!preg_match('/^\d{6}$/', $reset_code)) {
            $auth_message = 'Please enter the 6-digit reset code.';
            $auth_status = 'error';
        } elseif (time() > (int)($_SESSION['forgot_reset']['otp_expires'] ?? 0)) {
            unset($_SESSION['forgot_reset']);
            $auth_message = 'Reset code expired. Please request a new code.';
            $auth_status = 'error';
        } elseif (!hash_equals((string)$_SESSION['forgot_reset']['otp'], $reset_code)) {
            $auth_message = 'Invalid reset code. Please check your email.';
            $auth_status = 'error';
        } elseif (strlen($new_password) < 8) {
            $auth_message = 'New password must be at least 8 characters.';
            $auth_status = 'error';
        } elseif ($new_password !== $confirm_password) {
            $auth_message = 'New password and confirmation do not match.';
            $auth_status = 'error';
        } else {
            $reset_user_id = (int)($_SESSION['forgot_reset']['user_id'] ?? 0);
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
            if (!$stmt) {
                $auth_message = 'Database error. Please try again later.';
                $auth_status = 'error';
            } else {
                $stmt->bind_param("si", $new_hash, $reset_user_id);
                if ($stmt->execute()) {
                    unset($_SESSION['forgot_reset']);
                    $auth_message = 'Password reset successful. You can now sign in with your new password.';
                    $auth_status = 'success';
                    $show_modal = 'loginModal';
                } else {
                    $auth_message = 'Unable to reset password. Please try again.';
                    $auth_status = 'error';
                }
                $stmt->close();
            }
        }
    }

    // STEP 4: Process Login Request
    elseif (isset($_POST['login'])) {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        // Login Security: maximum 5 failed attempts, then lock login for 15 minutes.
        $attempt_key = 'login_attempts_' . md5(strtolower($email));
        $lock_key = 'login_lock_' . md5(strtolower($email));
        $max_attempts = 5;
        $lock_minutes = 15;

        if (isset($_SESSION[$lock_key]) && time() < $_SESSION[$lock_key]) {
            $remaining_minutes = ceil(($_SESSION[$lock_key] - time()) / 60);
            $auth_message = "Too many failed login attempts. Please try again after {$remaining_minutes} minute(s).";
            $auth_status = "error";
            $show_modal = "loginModal";
        } else {
            if (isset($_SESSION[$lock_key]) && time() >= $_SESSION[$lock_key]) {
                unset($_SESSION[$lock_key], $_SESSION[$attempt_key]);
            }

            $stmt = $conn->prepare("SELECT id, fullname, password, role FROM users WHERE email = ?");

            if (!$stmt) {
                $auth_message = "Database Error: " . $conn->error;
                $auth_status = "error";
                $show_modal = "loginModal";
            } else {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($row = $result->fetch_assoc()) {
                    $stored_password = (string)$row['password'];
                    $password_ok = password_verify($password, $stored_password) || hash_equals($stored_password, md5($password));

                    if ($password_ok) {
                        // Upgrade legacy MD5 passwords to password_hash after successful login.
                        if (hash_equals($stored_password, md5($password))) {
                            $new_hash = password_hash($password, PASSWORD_DEFAULT);
                            $upgrade_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                            if ($upgrade_stmt) {
                                $upgrade_stmt->bind_param("si", $new_hash, $row['id']);
                                $upgrade_stmt->execute();
                                $upgrade_stmt->close();
                            }
                        }

                        // Login Success
                        unset($_SESSION[$attempt_key], $_SESSION[$lock_key]);

                        if (!empty($_POST['remember'])) {
                            setcookie('jej_remember_email', $email, [
                                'expires' => time() + (86400 * 30),
                                'path' => '/',
                                'domain' => '',
                                'secure' => $is_https,
                                'httponly' => true,
                                'samesite' => 'Strict'
                            ]);
                        } else {
                            setcookie('jej_remember_email', '', [
                                'expires' => time() - 3600,
                                'path' => '/',
                                'domain' => '',
                                'secure' => $is_https,
                                'httponly' => true,
                                'samesite' => 'Strict'
                            ]);
                        }

                        // Prevent session fixation after successful login
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $row['id'];
                        $_SESSION['fullname'] = $row['fullname'];
                        $_SESSION['role'] = $row['role'];

                        // Refresh the page to clear POST data and close modals
                        header("Location: index.php");
                        exit;
                    } else {
                        $_SESSION[$attempt_key] = ($_SESSION[$attempt_key] ?? 0) + 1;

                        if ($_SESSION[$attempt_key] >= $max_attempts) {
                            $_SESSION[$lock_key] = time() + ($lock_minutes * 60);
                            $auth_message = "Too many failed login attempts. Your login is locked for {$lock_minutes} minutes.";
                        } else {
                            $remaining = $max_attempts - $_SESSION[$attempt_key];
                            $auth_message = "Invalid password. {$remaining} attempt(s) remaining.";
                        }

                        $auth_status = "error";
                        $show_modal = "loginModal";
                    }
                } else {
                    $_SESSION[$attempt_key] = ($_SESSION[$attempt_key] ?? 0) + 1;

                    if ($_SESSION[$attempt_key] >= $max_attempts) {
                        $_SESSION[$lock_key] = time() + ($lock_minutes * 60);
                        $auth_message = "Too many failed login attempts. Your login is locked for {$lock_minutes} minutes.";
                    } else {
                        $remaining = $max_attempts - $_SESSION[$attempt_key];
                        $auth_message = "No account found with that email. {$remaining} attempt(s) remaining.";
                    }

                    $auth_status = "error";
                    $show_modal = "loginModal";
                }
                $stmt->close();
            }
        }
    }
    }
}

// --- FETCH NOTIFICATIONS ---
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


// --- FETCH LOGGED-IN USER PROFILE PHOTO ---
$current_profile_photo = '';
if (!function_exists('jej_user_column_exists')) {
    function jej_user_column_exists(mysqli $conn, string $column): bool {
        static $cache = [];
        $key = strtolower($column);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
        if (!$stmt) {
            $cache[$key] = false;
            return false;
        }
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        $cache[$key] = ((int)$count > 0);
        return $cache[$key];
    }
}
if (!function_exists('jej_safe_profile_photo_src')) {
    function jej_safe_profile_photo_src(?string $path): string {
        $path = trim((string)$path);
        if ($path === '') return '';
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        if (strpos($path, '..') !== false || preg_match('#^(https?:)?//#i', $path)) return '';
        if (!preg_match('#^uploads/profile_photos/[A-Za-z0-9._-]+\.(jpe?g|png|webp)$#i', $path)) return '';
        return is_file(__DIR__ . '/' . $path) ? $path : '';
    }
}
if (isset($_SESSION['user_id']) && jej_user_column_exists($conn, 'profile_photo')) {
    $photo_uid = (int)$_SESSION['user_id'];
    $photo_stmt = $conn->prepare("SELECT profile_photo FROM users WHERE id = ? LIMIT 1");
    if ($photo_stmt) {
        $photo_stmt->bind_param('i', $photo_uid);
        $photo_stmt->execute();
        $photo_stmt->bind_result($profile_photo_db);
        if ($photo_stmt->fetch()) {
            $current_profile_photo = jej_safe_profile_photo_src($profile_photo_db);
        }
        $photo_stmt->close();
    }
}

// --- FETCH LOCATIONS FOR DROPDOWN ---
$locations = [];
$loc_stmt = $conn->prepare("SELECT DISTINCT location FROM lots WHERE location IS NOT NULL AND location != ''");
if ($loc_stmt) {
    $loc_stmt->execute();
    $loc_result = $loc_stmt->get_result();
    while($row = $loc_result->fetch_assoc()) { $locations[$row['location']] = $row['location']; }
    $loc_stmt->close();
}

$phase_stmt = $conn->prepare("SELECT DISTINCT name FROM phases WHERE name IS NOT NULL AND name != ''");
if ($phase_stmt) {
    $phase_stmt->execute();
    $phase_result = $phase_stmt->get_result();
    while($row = $phase_result->fetch_assoc()) { $locations[$row['name']] = $row['name']; }
    $phase_stmt->close();
}
sort($locations);

// --- LOT COLUMN DETECTION FOR SAFE FILTERS ---
// This prevents SQL errors if older database versions do not yet have some columns.
$lot_columns = [];
$lot_columns_result = $conn->query("SHOW COLUMNS FROM lots");
if ($lot_columns_result) {
    while ($col = $lot_columns_result->fetch_assoc()) {
        if (!empty($col['Field'])) {
            $lot_columns[$col['Field']] = true;
        }
    }
}

if (!function_exists('jej_pick_lot_column')) {
    function jej_pick_lot_column(array $available_columns, array $candidates) {
        foreach ($candidates as $candidate) {
            if (isset($available_columns[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }
}

if (!function_exists('jej_lot_sql_col')) {
    function jej_lot_sql_col($column) {
        return '`l`.`' . str_replace('`', '``', (string)$column) . '`';
    }
}

$lot_area_filter_col = jej_pick_lot_column($lot_columns, ['area_sqm', 'lot_area', 'area', 'sqm']);
$lot_total_price_filter_col = jej_pick_lot_column($lot_columns, ['total_price', 'tcp', 'price', 'contract_price']);
$lot_price_sqm_filter_col = jej_pick_lot_column($lot_columns, ['price_per_sqm', 'price_sqm', 'sqm_price']);
$lot_class_filter_col = jej_pick_lot_column($lot_columns, ['classification', 'property_overview', 'lot_type', 'type']);

$lot_price_filter_expr = null;
if ($lot_total_price_filter_col) {
    $lot_price_filter_expr = '(' . jej_lot_sql_col($lot_total_price_filter_col) . ' + 0)';
} elseif ($lot_price_sqm_filter_col && $lot_area_filter_col) {
    $lot_price_filter_expr = '((' . jej_lot_sql_col($lot_price_sqm_filter_col) . ' + 0) * (' . jej_lot_sql_col($lot_area_filter_col) . ' + 0))';
} elseif ($lot_price_sqm_filter_col) {
    $lot_price_filter_expr = '(' . jej_lot_sql_col($lot_price_sqm_filter_col) . ' + 0)';
}

// --- PROCESS SEARCH AND FILTERS ---
$where_clauses = ["1=1"];
$params = [];
$types = "";

if(!empty($_GET['q'])){
    $q = "%" . $_GET['q'] . "%";
    $where_clauses[] = "(l.location LIKE ? OR p.name LIKE ?)";
    $params[] = $q; $params[] = $q;
    $types .= "ss";
}
$allowed_statuses = ['AVAILABLE', 'RESERVED', 'SOLD', 'ALL'];
$selected_status = strtoupper(trim($_GET['status'] ?? 'AVAILABLE'));
if (!in_array($selected_status, $allowed_statuses, true)) {
    $selected_status = 'AVAILABLE';
}

if($selected_status !== 'ALL'){
    $where_clauses[] = "l.status = ?";
    $params[] = $selected_status;
    $types .= "s";
}

$selected_classification = strtoupper(trim($_GET['classification'] ?? 'ALL'));
$allowed_classifications = ['ALL', 'FRONT', 'INNER'];
if (!in_array($selected_classification, $allowed_classifications, true)) {
    $selected_classification = 'ALL';
}

if ($selected_classification !== 'ALL' && $lot_class_filter_col) {
    $where_clauses[] = "UPPER(" . jej_lot_sql_col($lot_class_filter_col) . ") LIKE ?";
    $params[] = '%' . $selected_classification . '%';
    $types .= "s";
}

$min_area = trim($_GET['min_area'] ?? '');
$max_area = trim($_GET['max_area'] ?? '');
$min_price = trim($_GET['min_price'] ?? '');
$max_price = trim($_GET['max_price'] ?? '');

if ($lot_area_filter_col && $min_area !== '' && is_numeric($min_area)) {
    $where_clauses[] = "(" . jej_lot_sql_col($lot_area_filter_col) . " + 0) >= ?";
    $params[] = (float)$min_area;
    $types .= "d";
}

if ($lot_area_filter_col && $max_area !== '' && is_numeric($max_area)) {
    $where_clauses[] = "(" . jej_lot_sql_col($lot_area_filter_col) . " + 0) <= ?";
    $params[] = (float)$max_area;
    $types .= "d";
}

if ($lot_price_filter_expr && $min_price !== '' && is_numeric($min_price)) {
    $where_clauses[] = $lot_price_filter_expr . " >= ?";
    $params[] = (float)$min_price;
    $types .= "d";
}

if ($lot_price_filter_expr && $max_price !== '' && is_numeric($max_price)) {
    $where_clauses[] = $lot_price_filter_expr . " <= ?";
    $params[] = (float)$max_price;
    $types .= "d";
}

$where_sql = implode(" AND ", $where_clauses);
$query = "SELECT l.*, p.name as phase_name 
          FROM lots l 
          LEFT JOIN phases p ON l.phase_id = p.id 
          WHERE $where_sql 
          ORDER BY l.status = 'AVAILABLE' DESC, l.id DESC";

$stmt = $conn->prepare($query);

if (!$stmt) {
    die("Prepare failed: " . $conn->error . "<br><br>SQL Query:<br>" . $query);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$total_lot_results = $result ? (int)$result->num_rows : 0;
$active_filter_chips = [];

if (trim($_GET['q'] ?? '') !== '') {
    $active_filter_chips[] = [
        'label' => trim($_GET['q']),
        'url' => jej_filter_url_without(['q'])
    ];
}

if ($min_price !== '' || $max_price !== '') {
    $price_label = trim((jej_format_filter_money($min_price) ?: 'Any') . ' - ' . (jej_format_filter_money($max_price) ?: 'Any'));
    $active_filter_chips[] = [
        'label' => $price_label,
        'url' => jej_filter_url_without(['min_price', 'max_price'])
    ];
}

if ($min_area !== '' || $max_area !== '') {
    $area_label = trim(($min_area !== '' ? number_format((float)$min_area, 2) : 'Any') . ' - ' . ($max_area !== '' ? number_format((float)$max_area, 2) : 'Any') . ' sqm');
    $active_filter_chips[] = [
        'label' => $area_label,
        'url' => jej_filter_url_without(['min_area', 'max_area'])
    ];
}

if ($selected_classification !== 'ALL') {
    $active_filter_chips[] = [
        'label' => ucfirst(strtolower($selected_classification)) . ' lot',
        'url' => jej_filter_url_without(['classification'])
    ];
}

if ($selected_status !== 'AVAILABLE') {
    $active_filter_chips[] = [
        'label' => ucfirst(strtolower($selected_status)),
        'url' => jej_filter_url_without(['status'])
    ];
}

$result_noun = $total_lot_results === 1 ? 'lot' : 'lots';
if (trim($_GET['q'] ?? '') !== '') {
    $lot_result_text = 'Showing ' . $total_lot_results . ' ' . $result_noun . ' in ' . trim($_GET['q']);
} elseif (!empty($active_filter_chips)) {
    $lot_result_text = 'Showing ' . $total_lot_results . ' matching ' . $result_noun;
} elseif ($selected_status === 'AVAILABLE') {
    $lot_result_text = 'Showing ' . $total_lot_results . ' available ' . $result_noun;
} else {
    $lot_result_text = 'Showing ' . $total_lot_results . ' ' . strtolower($selected_status) . ' ' . $result_noun;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>JEJ Top Priority Corporation</title>

    <!-- SEO -->
    <meta name="description" content="JEJ Top Priority Corporation provides professional land surveying, land titling, land documentation, property consultancy, and premium lot sales services in Nueva Ecija and nearby areas.">
    <meta name="keywords" content="JEJ Top Priority Corporation, lots for sale, land survey, lot survey, land titling, Nueva Ecija real estate, property documentation">
    <meta name="theme-color" content="#111827">

    <meta property="og:title" content="JEJ Top Priority Corporation">
    <meta property="og:description" content="Professional land surveying, real estate services, land titling assistance, documentation, and premium lot sales.">
    <meta property="og:image" content="https://jejtoppriority.com/assets/LOGO1.png">
    <meta property="og:url" content="https://jejtoppriority.com/">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="JEJ Top Priority Corporation">

    <link rel="icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="shortcut icon" href="assets/favicon.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/favicon.png">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,600;0,700;0,800&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tom-select/dist/css/tom-select.css" rel="stylesheet">
    
    <style>
        /* === GLOBAL RESET & TYPOGRAPHY === */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary-yellow: #F4D03F;
            --dark-bg: #111827; 
            --light-bg: #F8FAFC;
            --text-dark: #1e293b;
            --text-light: #ffffff;
            --success-green: #22c55e;
            --danger-red: #ef4444;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 20px 40px -10px rgba(0,0,0,0.12);
        }

        body {
            font-family: 'Roboto', sans-serif;
            background-color: #fff;
            color: var(--text-dark);
            line-height: 1.6;
            overflow-x: hidden;
            scroll-behavior: smooth;
        }

        h1, h2, h3, h4, .nav-logo-text h2 { font-family: 'Montserrat', sans-serif; }
        a { text-decoration: none; color: inherit; }
        ul { list-style: none; }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 130px;
        }

        section, header, .footer-wrapper {
            scroll-margin-top: 130px;
        }

        img { max-width: 100%; }

        :focus-visible {
            outline: 3px solid var(--primary-yellow);
            outline-offset: 3px;
        }

        /* --- SCROLL REVEAL ANIMATIONS --- */
        .reveal-up {
            opacity: 0; transform: translateY(40px);
            transition: all 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        .reveal-left {
            opacity: 0; transform: translateX(-40px);
            transition: all 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        .reveal-right {
            opacity: 0; transform: translateX(40px);
            transition: all 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        .reveal-up.visible, .reveal-left.visible, .reveal-right.visible {
            opacity: 1; transform: translate(0, 0);
        }

        /* === BUTTONS === */
        .btn-primary {
            background-color: var(--primary-yellow); color: var(--dark-bg);
            padding: 14px 34px; border-radius: 30px;
            font-family: 'Montserrat', sans-serif; font-weight: 700; font-size: 1rem;
            display: inline-flex; align-items: center; gap: 10px;
            transition: all 0.3s ease; border: none; cursor: pointer;
            box-shadow: 0 4px 15px rgba(244, 208, 63, 0.2);
        }
        .btn-primary:hover { background-color: #e5c338; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(244, 208, 63, 0.4); }
        
        .btn-dark {
            background-color: var(--dark-bg); color: var(--text-light);
            padding: 12px 28px; border-radius: 10px;
            font-family: 'Montserrat', sans-serif; font-weight: 600;
            transition: 0.3s; border: none; cursor: pointer; text-align: center; display: block;
        }
        .btn-dark:hover { background-color: #000; color: var(--primary-yellow); }

        /* === NAVBAR (INCREASED SIZE) === */
        .navbar {
            position: fixed; top: 0; left: 0; width: 100%; padding: 20px 5%;
            display: flex; justify-content: space-between; align-items: center;
            z-index: 1000; color: white;
            background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        
        .nav-logo { display: flex; align-items: center; }
        .nav-logo-text { margin-left: 15px; }
        .nav-logo-text h2 { font-weight: 800; letter-spacing: 0.5px; font-size: 1.6rem; line-height: 1.1; text-shadow: 0 2px 4px rgba(0,0,0,0.5); color: white; }
        .nav-logo-text span { font-family: 'Roboto', sans-serif; font-size: 12px; font-weight: 500; letter-spacing: 1.5px; color: #cbd5e1; display: block; margin-top: 4px; }
        
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
        .avatar-circle { background: var(--primary-yellow); color: var(--dark-bg); border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1rem; }
        .avatar-circle.has-photo { background: transparent; padding: 0; overflow: hidden; border: 2px solid rgba(255,255,255,0.35); }
        .avatar-photo { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 50%; }
        
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
        .profile-dropdown-item:hover { background: #f8fafc; color: var(--dark-bg); padding-left: 25px; }
        .profile-dropdown-item.logout-btn { color: #ef4444; border-top: 1px solid #f1f5f9; }
        .profile-dropdown-item.logout-btn:hover { background: #fef2f2; color: #dc2626; }

        /* === HERO SECTION WITH SLIDER === */
        .hero {
            height: 85vh;
            min-height: 620px;
            display: flex; flex-direction: column; justify-content: center; 
            padding: 0 5%; color: white; position: relative; overflow: hidden;
        }
        
        /* CSS Background Slideshow - smooth crossfade, no white/gray flash */
        .hero-slideshow {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
            background:
                linear-gradient(rgba(15,23,42,0.72), rgba(15,23,42,0.86)),
                url('assets/login1.jpg') center/cover no-repeat;
        }

        .hero-slideshow .slide {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            opacity: 0;
            transform: scale(1.04);
            will-change: opacity, transform;
            backface-visibility: hidden;
        }

        .hero-slideshow .slide::after {
            content: '';
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(rgba(15,23,42,0.68), rgba(15,23,42,0.86));
            pointer-events: none;
        }

        .hero-slideshow .slide:nth-child(1) {
            background-image: url('assets/login1.jpg');
            animation: heroFadeOne 24s infinite ease-in-out;
        }

        .hero-slideshow .slide:nth-child(2) {
            background-image: url('assets/login2.jpg');
            animation: heroFadeTwo 24s infinite ease-in-out;
        }

        .hero-slideshow .slide:nth-child(3) {
            background-image: url('assets/login3.jpg');
            animation: heroFadeThree 24s infinite ease-in-out;
        }

        @keyframes heroFadeOne {
            0%, 28% {
                opacity: 1;
                transform: scale(1.04);
            }
            36%, 92% {
                opacity: 0;
                transform: scale(1.08);
            }
            100% {
                opacity: 1;
                transform: scale(1.04);
            }
        }

        @keyframes heroFadeTwo {
            0%, 28% {
                opacity: 0;
                transform: scale(1.04);
            }
            36%, 61% {
                opacity: 1;
                transform: scale(1.08);
            }
            69%, 100% {
                opacity: 0;
                transform: scale(1.04);
            }
        }

        @keyframes heroFadeThree {
            0%, 61% {
                opacity: 0;
                transform: scale(1.04);
            }
            69%, 92% {
                opacity: 1;
                transform: scale(1.08);
            }
            100% {
                opacity: 0;
                transform: scale(1.04);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .hero-slideshow .slide {
                animation: none !important;
            }
            .hero-slideshow .slide:nth-child(1) {
                opacity: 1;
            }
        }

        .hero-content { max-width: 65%; z-index: 10; margin-top: 80px; }
        .hero h1 { font-size: 3.5rem; font-weight: 800; line-height: 1.1; text-transform: uppercase; margin-bottom: 20px; text-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        .hero h1 span { font-style: italic; color: var(--primary-yellow); }
        .hero p { font-size: 1.1rem; margin-bottom: 24px; font-weight: 300; max-width: 85%; color: #cbd5e1; }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 14px;
            margin-top: 22px;
        }

        .btn-hero-outline {
            background: rgba(255,255,255,.08);
            color: #fff;
            border: 1px solid rgba(255,255,255,.24);
            box-shadow: none;
        }

        .btn-hero-outline:hover {
            background: #fff;
            color: var(--dark-bg);
        }

        /* Floating Search Box inside Hero - Premium Glassmorphism */
        .search-box {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            padding: 8px;
            border-radius: 60px;
            border: 1px solid rgba(255,255,255,0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            max-width: 700px;
            box-shadow:
                0 12px 35px rgba(0,0,0,.28),
                inset 0 1px 0 rgba(255,255,255,.14);
        }

        .search-input-group {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 18px;
            min-width: 0;
            border-right: 1px solid rgba(255,255,255,0.16);
        }

        .search-input-group i {
            color: var(--primary-yellow);
            font-size: 1.15rem;
            filter: drop-shadow(0 4px 10px rgba(244,208,63,.25));
            flex: 0 0 auto;
        }

        .search-input {
            width: 100%;
            border: none;
            outline: none;
            color: white;
            background: transparent;
            font-family: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .search-input option {
            color: var(--text-dark);
            background: white;
            font-weight: 600;
        }

        /* Tom Select searchable dropdown style */
        .hero-search .ts-wrapper.single {
            flex: 1;
            min-width: 0;
        }

        .hero-search .ts-wrapper.single .ts-control {
            min-height: 52px;
            padding: 0 38px 0 0;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            color: #fff;
            font-size: .96rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .hero-search .ts-wrapper.single .ts-control input,
        .hero-search .ts-wrapper.single .ts-control .item,
        .hero-search .ts-wrapper.single .ts-control .ts-input {
            color: #fff !important;
            font-weight: 700;
        }

        .hero-search .ts-wrapper.single .ts-control input::placeholder {
            color: rgba(255,255,255,.78) !important;
            font-weight: 700;
        }

        .hero-search .ts-wrapper.single .ts-control::after {
            content: "\f078";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,.86);
            font-size: .82rem;
            pointer-events: none;
        }

        .hero-search .ts-dropdown {
            margin-top: 14px;
            background: rgba(15,23,42,.94);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 18px;
            box-shadow: 0 24px 50px rgba(0,0,0,.38);
            overflow: hidden;
            color: #fff;
            z-index: 3000;
        }

        .hero-search .ts-dropdown .option {
            padding: 13px 16px;
            color: #e5e7eb;
            font-weight: 700;
            border-bottom: 1px solid rgba(255,255,255,.06);
            transition: .18s ease;
        }

        .hero-search .ts-dropdown .option::before {
            content: "\f3c5";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            color: var(--primary-yellow);
            margin-right: 10px;
        }

        .hero-search .ts-dropdown .active,
        .hero-search .ts-dropdown .option:hover {
            background: rgba(244,208,63,.15);
            color: #fff;
        }

        .hero-search .ts-dropdown .no-results {
            padding: 14px 16px;
            color: #cbd5e1;
            font-weight: 600;
        }

        .btn-search {
            background: linear-gradient(135deg, var(--primary-yellow), #ffd84d);
            color: var(--dark-bg);
            border: none;
            padding: 15px 34px;
            border-radius: 50px;
            font-weight: 800;
            font-family: 'Montserrat', sans-serif;
            font-size: .95rem;
            cursor: pointer;
            transition: .3s ease;
            box-shadow: 0 10px 24px rgba(244,208,63,.35);
            white-space: nowrap;
        }
        .btn-search:hover {
            background: linear-gradient(135deg, #ffe066, #f4d03f);
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 14px 30px rgba(244,208,63,.48);
        }

        /* === OVERLAPPING HERO CARDS === */
        .hero-cards {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;
            padding: 0 5%; margin-top: -80px; position: relative; z-index: 20;
        }
        .h-card {
            height: 200px; border-radius: 20px; background-size: cover; background-position: center;
            padding: 30px; display: flex; flex-direction: column; justify-content: flex-end;
            color: white; box-shadow: 0 15px 35px rgba(0,0,0,0.25); transition: transform 0.3s ease;
        }
        .h-card:hover { transform: translateY(-8px); }
        .h-card:nth-child(1) { background-image: linear-gradient(to top, rgba(15,23,42,0.9), transparent), url('https://images.unsplash.com/photo-1503387762-592deb58ef4e?ixlib=rb-4.0.3'); }
        .h-card:nth-child(2) { background: linear-gradient(135deg, #1e293b, #0f172a); justify-content: center; text-align: center;}
        .h-card:nth-child(3) { background-image: linear-gradient(to top, rgba(15,23,42,0.9), transparent), url('assets/right.png'); }
        
        .h-card h3 { font-size: 1.2rem; font-weight: 700; }
        .h-card-center h3 { font-size: 1.6rem; font-weight: 800; letter-spacing: 1px;}
        .h-card-center h3 span { color: var(--primary-yellow); }
        .h-card-center p { font-size: 0.85rem; color: #94a3b8; margin-top: 8px; font-weight: 400;}

        /* === GENERAL SECTION STYLES === */
        .section-padding { padding: 90px 5%; }
        .section-subtitle { color: #ca8a04; font-family: 'Montserrat', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; font-size: 0.8rem; margin-bottom: 10px; }
        .section-title { font-size: 2.2rem; font-weight: 800; color: var(--text-dark); margin-bottom: 40px; line-height: 1.2; }

        /* === FEATURES GRID === */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
            margin-bottom: 60px;
            align-items: stretch;
        }
        .feature-box {
            background: var(--light-bg);
            padding: 35px 25px;
            border-radius: 16px;
            transition: 0.3s;
            border: 1px solid #e2e8f0;
            display: block;
            height: 100%;
            color: inherit;
            text-decoration: none;
            position: relative;
        }
        .feature-box:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.08); border-color: var(--primary-yellow);}
        .feature-icon { font-size: 2.2rem; margin-bottom: 20px; color: var(--dark-bg); }
        .feature-box h3 { margin-bottom: 10px; font-size: 1.1rem; }
        .feature-box p { font-size: 0.85rem; color: #475569; }

        .service-card {
            cursor: pointer;
            padding-bottom: 74px;
            overflow: hidden;
        }

        .service-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(244,208,63,.13), transparent 42%);
            opacity: 0;
            transition: .3s ease;
            pointer-events: none;
        }

        .service-card:hover::before {
            opacity: 1;
        }

        .service-card:hover .feature-icon {
            color: #ca8a04;
            transform: scale(1.05);
        }

        .service-card-cta {
            position: absolute;
            left: 25px;
            right: 25px;
            bottom: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: #111827;
            color: var(--primary-yellow);
            font-family: 'Montserrat', sans-serif;
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .2px;
            transition: .25s ease;
        }

        .service-card:hover .service-card-cta {
            background: var(--primary-yellow);
            color: var(--dark-bg);
            transform: translateY(-2px);
        }

        .contact-form-area.service-selected {
            box-shadow: 0 0 0 4px rgba(244,208,63,.45), 0 25px 50px rgba(0,0,0,.20);
            transition: box-shadow .35s ease;
        }

        /* === EXCLUSIVE PROPERTIES (PHP LOOP SECTION) === */
        .properties-section {
            background-color: var(--dark-bg);
            color: white;
            text-align: center;
            position: relative;
            overflow: visible;
            scroll-margin-top: 170px;
            padding-top: 120px;
        }
        .properties-section .section-title { color: white; margin-bottom: 24px; }

        .property-filter-form {
            display: grid;
            grid-template-columns: minmax(260px, 1.5fr) repeat(6, minmax(118px, 1fr)) auto auto;
            gap: 12px;
            align-items: center;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.10);
            border-radius: 18px;
            padding: 14px;
            margin: 0 auto 34px auto;
            max-width: 1280px;
            box-shadow: 0 18px 40px rgba(0,0,0,.18);
            position: relative;
            z-index: 50;
        }

        .property-filter-note {
            max-width: 980px;
            margin: -16px auto 24px auto;
            color: #94a3b8;
            font-size: .88rem;
        }

        .property-filter-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }

        .property-filter-field label {
            color: #cbd5e1;
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .4px;
            text-transform: uppercase;
            text-align: left;
        }

        .property-filter-form select,
        .property-filter-form input {
            width: 100%;
            min-height: 48px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,.14);
            background: #0f172a;
            color: #fff;
            padding: 0 14px;
            font-family: inherit;
            font-weight: 700;
            outline: none;
        }

        .property-filter-form option {
            color: var(--text-dark);
            background: #fff;
        }

        .property-filter-form .ts-wrapper {
            width: 100%;
        }

        .property-filter-form .ts-control {
            min-height: 48px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,.14) !important;
            background: #0f172a !important;
            color: #fff !important;
            box-shadow: none !important;
            padding: 0 14px !important;
            font-weight: 700;
            display: flex;
            align-items: center;
        }

        .property-filter-form .ts-control input,
        .property-filter-form .ts-control .item {
            color: #fff !important;
            font-weight: 700;
        }

        .property-filter-form .ts-dropdown {
            background: #0f172a;
            color: #fff;
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 18px 40px rgba(0,0,0,.30);
            z-index: 99999 !important;
        }

        .property-filter-form .ts-dropdown-content {
            max-height: 230px;
            overflow-y: auto;
        }

        .property-filter-form .ts-dropdown .option {
            color: #e5e7eb;
            padding: 12px 14px;
            font-weight: 700;
        }

        .property-filter-form .ts-dropdown .active,
        .property-filter-form .ts-dropdown .option:hover {
            background: rgba(244,208,63,.15);
            color: #fff;
        }

        .btn-filter, .btn-clear-filter {
            min-height: 48px;
            border-radius: 12px;
            border: none;
            padding: 0 18px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn-filter {
            background: var(--primary-yellow);
            color: var(--dark-bg);
        }

        .btn-clear-filter {
            background: transparent;
            color: #e2e8f0;
            border: 1px solid rgba(255,255,255,.18);
        }

        .btn-clear-filter:hover {
            color: var(--primary-yellow);
            border-color: var(--primary-yellow);
        }
        
        .property-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            text-align: left;
            position: relative;
            z-index: 1;
        }
        .hidden-card { display: none !important; }

        .prop-card {
            background: #1e293b; border-radius: 16px; overflow: hidden; transition: all 0.3s ease; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.2); display: flex; flex-direction: column;
            text-decoration: none; color: inherit; border: 1px solid rgba(255,255,255,0.05);
        }
        .prop-card:hover { transform: translateY(-6px); box-shadow: 0 15px 30px rgba(0,0,0,0.4); border-color: rgba(244, 208, 63, 0.3); }
        
        .prop-img-box { position: relative; height: 200px; overflow: hidden; }
        .prop-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.7s; }
        .prop-card:hover .prop-img { transform: scale(1.08); }
        
        .prop-badge {
            position: absolute; top: 15px; left: 15px; padding: 5px 12px; border-radius: 6px;
            font-size: 0.7rem; font-weight: 800; letter-spacing: 0.5px; color: white; text-transform: uppercase;
        }
        .badge-AVAILABLE { background: var(--success-green); color: var(--dark-bg); }
        .badge-SOLD { background: var(--danger-red); }
        .badge-RESERVED { background: #f59e0b; }

        .prop-info { padding: 25px; display: flex; flex-direction: column; flex: 1; }
        .prop-loc { color: #94a3b8; font-size: 0.8rem; font-weight: 600; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
        .prop-loc i { color: var(--primary-yellow); }
        .prop-title { font-size: 1.15rem; font-weight: 700; color: white; margin-bottom: 12px; line-height: 1.3; font-family: 'Montserrat', sans-serif;}

        .prop-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }

        .prop-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border-radius: 999px;
            background: rgba(255,255,255,.06);
            color: #cbd5e1;
            font-size: .75rem;
            font-weight: 700;
        }

        .prop-chip i { color: var(--primary-yellow); }
        
        .prop-footer { margin-top: auto; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.1); display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .prop-price { font-size: 1.15rem; font-weight: 800; color: var(--primary-yellow); font-family: 'Montserrat', sans-serif;}
        .prop-view { color: white; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 5px; }
        .prop-card:hover .prop-view { color: var(--primary-yellow); }

        /* === WHY CHOOSE / RESERVATION PROCESS === */
        .why-section {
            background: #f8fafc;
        }

        .why-grid, .process-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
        }

        .why-item, .process-step {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 26px;
            box-shadow: 0 12px 28px rgba(15,23,42,.05);
        }

        .why-item i {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #111827;
            color: var(--primary-yellow);
            font-size: 1.2rem;
            margin-bottom: 16px;
        }

        .why-item h3, .process-step h3 {
            font-size: 1.05rem;
            margin-bottom: 8px;
            color: var(--text-dark);
        }

        .why-item p, .process-step p {
            color: #475569;
            font-size: .9rem;
        }

        .process-section {
            background: #fff;
        }

        .step-number {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            background: var(--primary-yellow);
            color: var(--dark-bg);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: 'Montserrat', sans-serif;
            font-weight: 900;
            margin-bottom: 16px;
        }

        /* === CONTACT OVERLAP & FOOTER (COLLISION FIXED) === */
        /* === CONTACT SECTION FIX ===
           Do not use negative margin here. The old -100px margin pulled the
           contact card upward and caused it to overlap the form/previous section. */
        .footer-wrapper {
            background-color: #020617;
            color: #fff;
            margin-top: 0;
            padding-top: 70px;
            scroll-margin-top: 130px;
        }

        .contact-overlap {
            position: relative;
            margin: 0 auto 70px auto;
            width: 90%;
            z-index: 10;
            background: linear-gradient(rgba(15,23,42,0.85), rgba(15,23,42,0.85)), url('https://images.unsplash.com/photo-1434626881859-194d67b2b86f?ixlib=rb-4.0.3') center/cover;
            border-radius: 24px;
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            align-items: stretch;
            box-shadow: 0 25px 50px rgba(0,0,0,0.4);
            overflow: hidden;
        }

        .contact-text-area {
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 360px;
        }
        .contact-text-area h4 { color: var(--primary-yellow); font-size: 0.85rem; letter-spacing: 1px; margin-bottom: 15px;}
        .contact-text-area h2 { font-size: 2.4rem; line-height: 1.1; margin-bottom: 10px; font-family: 'Montserrat', sans-serif;}
        .contact-text-area p { color: #cbd5e1; font-size: 1rem; margin-top: 5px; line-height: 1.5; }

        .contact-form-area {
            background: #ffffff;
            padding: 50px;
            border-radius: 30px;
            margin: 15px;
            align-self: center;
        }

        .contact-alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: .9rem;
            font-weight: 700;
        }

        .contact-alert.success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .contact-alert.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        .selected-service-alert {
            display: none;
            align-items: center;
            gap: 8px;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 15px;
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
            font-size: .9rem;
            font-weight: 800;
        }

        .selected-service-alert.active {
            display: flex;
        }

        .hp-field {
            position: absolute !important;
            left: -9999px !important;
            width: 1px !important;
            height: 1px !important;
            overflow: hidden !important;
        }

        .contact-form-area input, .contact-form-area textarea {
            width: 100%; padding: 15px 18px; margin-bottom: 15px;
            border: 1px solid #cbd5e1; border-radius: 8px; font-family: 'Roboto', sans-serif; font-size: 0.95rem; color: var(--text-dark);
        }
        .contact-form-area textarea { height: 110px; resize: none; }
        .btn-submit {
            background: var(--success-green); color: white; width: 100%; padding: 15px; border: none; border-radius: 8px;
            font-weight: 600; font-family: 'Montserrat', sans-serif; font-size: 1rem; cursor: pointer; transition: 0.3s;
        }
        .btn-submit:hover { background: #16a34a; }

        .footer-content { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 40px; padding: 0 5% 50px 5%; }
        .footer-col p { color: #94a3b8; font-size: 0.9rem; margin-top: 15px;}
        .footer-col h4 { font-size: 1.1rem; margin-bottom: 20px; color: white;}
        .footer-col ul li { margin-bottom: 12px; }
        .footer-col ul li a { color: #94a3b8; font-size: 0.9rem; transition: 0.3s;}
        .footer-col ul li a:hover { color: var(--primary-yellow); }

        .footer-quick-actions {
            display: grid;
            gap: 10px;
            margin-top: 16px;
        }

        .footer-contact-note {
            color: #94a3b8;
            font-size: .86rem;
            margin-top: 10px;
            line-height: 1.5;
        }

        .footer-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.14);
            color: #fff;
            font-weight: 800;
            font-size: .86rem;
            transition: .25s ease;
        }

        .footer-action-btn:hover {
            background: var(--primary-yellow);
            border-color: var(--primary-yellow);
            color: var(--dark-bg);
            transform: translateY(-2px);
        }

        /* === MODAL STYLES === */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(8px);
            z-index: 2000; align-items: center; justify-content: center;
            opacity: 0; transition: opacity 0.3s ease; padding: 20px;
        }
        .modal-overlay.active { display: flex; opacity: 1; }
        .modal-content {
            background: white; display: flex; border-radius: 20px;
            width: 100%; max-width: 900px; position: relative;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            transform: translateY(30px) scale(0.95); transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden; min-height: 500px;
        }
        .modal-overlay.active .modal-content { transform: translateY(0) scale(1); }
        .modal-close {
            position: absolute; top: 15px; right: 18px;
            background: #f3f4f6; border: none; width: 40px; height: 40px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 16px; cursor: pointer; color: #334155; z-index: 1001; transition: all 0.3s ease;
            box-shadow: 0 8px 18px rgba(15,23,42,.08);
        }
        .modal-close:hover { background: #ef4444; color: #fff; transform: rotate(90deg) scale(1.05); }

        .modal-left {
            flex: 1; background: linear-gradient(to bottom, rgba(15,23,42,0.6), rgba(15,23,42,0.9)), url('https://images.unsplash.com/photo-1541888086425-d81bb19240f5?ixlib=rb-4.0.3') center/cover;
            padding: 40px; display: flex; flex-direction: column; justify-content: flex-end; color: white;
        }
        .modal-left h3 { font-size: 1.8rem; font-weight: 800; margin-bottom: 12px; line-height: 1.2; font-family: 'Montserrat', sans-serif;}
        .modal-left p { font-size: 0.95rem; color: #cbd5e1; }
        
        .modal-right { flex: 1.2; padding: 50px 40px; display: flex; flex-direction: column; justify-content: center; background: white; }
        .modal-title { font-size: 1.8rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px; font-family: 'Montserrat', sans-serif;}
        .modal-subtitle { color: #64748b; font-size: 0.9rem; margin-bottom: 30px; }
        
        .modal-form-group { margin-bottom: 15px; position: relative; }
        .modal-form-row { display: flex; gap: 15px; margin-bottom: 15px; }
        .modal-form-row > div { flex: 1; position: relative; }
        
        .modal-form-label { display: block; margin-bottom: 6px; font-size: 0.8rem; font-weight: 700; color: var(--text-dark); text-transform: uppercase; font-family: 'Montserrat', sans-serif;}
        .modal-input {
            width: 100%; padding: 12px 15px; border: 2px solid #e2e8f0;
            border-radius: 10px; outline: none; background: #f8fafc;
            font-size: 0.95rem; color: var(--text-dark); font-family: inherit; font-weight: 500; transition: all 0.3s;
        }
        .modal-input:focus { border-color: var(--primary-yellow); background: white; }
        
        .btn-modal-submit {
            background: var(--dark-bg); color: white; border: none;
            padding: 15px; width: 100%; border-radius: 10px;
            font-size: 1rem; font-weight: 800; cursor: pointer; font-family: 'Montserrat', sans-serif;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            margin-top: 10px; transition: all 0.3s;
        }
        .btn-modal-submit:hover { background: #000; color: var(--primary-yellow); }
        
        .modal-footer-text { margin-top: 20px; text-align: center; font-size: 0.9rem; color: #64748b; font-weight: 500; }
        .modal-footer-text a { color: var(--primary-yellow); font-weight: 700; text-decoration: none; transition: 0.2s; color: var(--dark-bg); text-decoration: underline;}
        .modal-footer-text a:hover { color: var(--primary-yellow); }


        /* === MODERN LOGIN MODAL UPGRADES === */
        .login-left{
            flex: 1;
            position: relative;
            padding: 42px;
            color: #fff;
            background:
                linear-gradient(145deg, rgba(15,23,42,.78), rgba(15,23,42,.96)),
                url('assets/login2.jpg') center/cover;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 500px;
            overflow: hidden;
        }

        .login-left::after{
            content:"";
            position:absolute;
            right:-80px;
            bottom:-80px;
            width:220px;
            height:220px;
            border-radius:50%;
            background:rgba(244,208,63,.14);
        }

        .login-brand{
            position:relative;
            z-index:1;
        }

        .login-brand img{
            height:78px;
            width:auto;
            object-fit:contain;
            filter:drop-shadow(0 6px 16px rgba(0,0,0,.35));
            margin-bottom:18px;
        }

        .login-brand-title{
            font-family:'Montserrat', sans-serif;
            font-size:1.45rem;
            line-height:1.15;
            font-weight:900;
            letter-spacing:.2px;
        }

        .login-brand-sub{
            margin-top:8px;
            font-size:.78rem;
            color:#facc15;
            font-weight:800;
            letter-spacing:1.4px;
            text-transform:uppercase;
        }

        .login-benefits{
            position:relative;
            z-index:1;
            display:grid;
            gap:12px;
            margin-top:24px;
        }

        .login-benefits li{
            display:flex;
            align-items:center;
            gap:10px;
            color:#e2e8f0;
            font-size:.93rem;
            font-weight:600;
        }

        .login-benefits i{
            color:#facc15;
            width:18px;
            text-align:center;
        }

        .login-welcome{
            position:relative;
            z-index:1;
        }

        .login-welcome h3{
            font-size:1.8rem;
            font-weight:900;
            margin-bottom:10px;
            font-family:'Montserrat', sans-serif;
        }

        .login-welcome p{
            color:#cbd5e1;
            font-size:.95rem;
            max-width:340px;
        }

        .password-wrapper{
            position:relative;
        }

        .password-wrapper .modal-input{
            padding-right:48px;
        }

        .password-toggle{
            position:absolute;
            right:10px;
            top:50%;
            transform:translateY(-50%);
            width:36px;
            height:36px;
            border:none;
            border-radius:9px;
            background:#eef2f7;
            color:#475569;
            cursor:pointer;
            display:flex;
            align-items:center;
            justify-content:center;
            transition:.25s ease;
        }

        .password-toggle:hover{
            background:var(--primary-yellow);
            color:var(--dark-bg);
        }

        .login-options{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin:4px 0 18px;
            flex-wrap:wrap;
        }

        .remember-me{
            display:flex;
            align-items:center;
            gap:8px;
            color:#475569;
            font-size:.88rem;
            font-weight:700;
            cursor:pointer;
        }

        .remember-me input{
            width:16px;
            height:16px;
            accent-color:#111827;
        }

        .forgot-link{
            color:#111827;
            font-size:.88rem;
            font-weight:800;
            text-decoration:underline;
            text-underline-offset:3px;
        }

        .forgot-link:hover{
            color:#ca8a04;
        }

        .login-note{
            text-align:center;
            margin-top:14px;
            padding:10px 12px;
            border-radius:999px;
            background:#f8fafc;
            color:#64748b;
            font-size:.78rem;
            font-weight:800;
            letter-spacing:.2px;
        }

        @media(max-width:768px){
            .login-left{
                min-height:auto;
                padding:28px;
                gap:24px;
            }

            .login-brand img{
                height:62px;
            }

            .login-brand-title{
                font-size:1.15rem;
            }

            .login-benefits{
                grid-template-columns:1fr;
                gap:9px;
            }

            .login-welcome h3{
                font-size:1.45rem;
            }
        }


        /* Alerts inside Modals */
        .alert-box { padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .alert-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }

        /* Responsive */
        @media(max-width: 1024px) {
            .hero h1 { font-size: 2.8rem; }
            .hero-content { max-width: 85%; }
            .features-grid, .properties-grid { grid-template-columns: 1fr; flex-direction: column; }
            .hero-cards { grid-template-columns: 1fr; margin-top: 20px; }
            .property-filter-form { grid-template-columns: 1fr 1fr; max-width: 100%; }
            .properties-section { padding-top: 110px; }
            
            /* Responsive Contact & Footer Fixes */
            .contact-overlap { grid-template-columns: 1fr; margin: 0 auto 40px auto; width: 95%; border-radius: 20px;}
            .contact-text-area { padding: 40px 30px; min-height: auto; }
            .contact-form-area { margin: 0; border-radius: 0; padding: 40px 30px; align-self: stretch; }
            .footer-wrapper { padding-top: 50px; margin-top: 0; }
            .footer-content { grid-template-columns: 1fr 1fr; }
        }
        .mobile-menu-btn{
    display:none;
    background:#f4d03f;
    color:#111827;
    border:none;
    width:48px;
    height:48px;
    border-radius:12px;
    font-size:20px;
    cursor:pointer;
    font-weight:700;
}

@media(max-width:768px){

    .navbar{
        padding:15px 20px;
    }

    .mobile-menu-btn{
        display:flex;
        align-items:center;
        justify-content:center;
    }

    .nav-links{
        position:absolute;
        top:100%;
        left:0;
        width:100%;
        background:#0f172a;
        display:none;
        flex-direction:column;
        gap:0;
        padding:15px 0;
        border-top:1px solid rgba(255,255,255,.08);
        box-shadow:0 10px 30px rgba(0,0,0,.25);
    }

    .nav-links.show{
        display:flex;
    }

    .nav-links a{
        padding:14px 25px;
        color:#fff;
        font-weight:600;
        border-bottom:1px solid rgba(255,255,255,.05);
    }

    .nav-links a:hover{
        background:rgba(244,208,63,.12);
        color:#f4d03f;
    }

    .hero h1{
        font-size:2.2rem;
    }

    .hero-content{
        max-width:100%;
    }

    .section-title{
        font-size:1.8rem;
    }

    .footer-content{
        grid-template-columns:1fr;
    }

    .modal-content{
        flex-direction:column;
        max-height:90vh;
        overflow-y:auto;
    }

    .modal-left{
        min-height:150px;
        padding:25px;
    }

    .modal-right{
        padding:30px 25px;
    }

    .search-box{
        flex-direction:column;
        border-radius:16px;
        padding:15px;
    }

    .search-input-group{
        border-right:none;
        border-bottom:1px solid rgba(255,255,255,.2);
        padding-bottom:10px;
        margin-bottom:10px;
        width:100%;
    }

    .btn-search{
        width:100%;
    }

    .property-filter-form{
        grid-template-columns:1fr;
        max-width:100%;
    }

    .property-filter-field label{
        text-align:left;
    }

    .properties-section{
        padding-top:95px;
    }

    .hero{
        min-height:100vh;
        height:auto;
        padding-top:110px;
        padding-bottom:70px;
    }

    .hero p{
        max-width:100%;
    }

    .btn-primary{
        padding:12px 18px;
        font-size:.88rem;
    }

    .hero-search .ts-wrapper.single,
    .hero-search .ts-control{
        width:100%;
    }

    .hero-search .ts-dropdown{
        margin-top:8px;
    }

    .nav-logo-text{
        display:none;
    }

    .profile-info{
        display:none !important;
    }

    .features-grid{
        grid-template-columns:1fr;
    }

    .hero-actions{
        width:100%;
    }

    .hero-actions .btn-primary{
        width:100%;
        justify-content:center;
    }

    .nav-actions{
        gap:10px;
    }

    .nav-actions > .btn-primary{
        max-width:145px;
        padding:10px 12px;
        font-size:.78rem;
        white-space:normal;
        line-height:1.15;
        justify-content:center;
    }

    .property-filter-note{
        margin-top:-10px;
        font-size:.82rem;
    }

    .contact-form-area{
        width:100%;
    }
}   


        /* === BACK TO TOP BUTTON === */
        .back-to-top{
            position:fixed;
            right:26px;
            bottom:26px;
            width:54px;
            height:54px;
            border:none;
            border-radius:50%;
            background:linear-gradient(135deg, var(--primary-yellow), #eab308);
            color:var(--dark-bg);
            font-size:20px;
            cursor:pointer;
            display:flex;
            align-items:center;
            justify-content:center;
            box-shadow:0 12px 28px rgba(0,0,0,.28);
            z-index:1500;
            opacity:0;
            visibility:hidden;
            transform:translateY(18px);
            transition:all .3s ease;
        }

        .back-to-top.show{
            opacity:1;
            visibility:visible;
            transform:translateY(0);
        }

        .back-to-top:hover{
            transform:translateY(-5px);
            box-shadow:0 18px 36px rgba(0,0,0,.35);
        }

        @media(max-width:768px){
            .back-to-top{
                width:48px;
                height:48px;
                right:18px;
                bottom:18px;
                font-size:18px;
            }
        }



        /* =========================================================
           PROPERTIES FILTER ALIGN/FIT POLISH
           Keeps the filter bar aligned with the lot-card grid and
           prevents cramped/overflowing controls on desktop/mobile.
        ========================================================= */
        .properties-section .property-filter-form{
            width: 100%;
            max-width: none;
            grid-template-columns:
                minmax(230px, 1.8fr)
                minmax(130px, .85fr)
                repeat(4, minmax(108px, .78fr))
                minmax(125px, .85fr)
                minmax(105px, .68fr)
                minmax(105px, .68fr);
            gap: 10px;
            padding: 12px 14px;
            margin: 0 0 20px 0;
            align-items: end;
        }

        .properties-section .property-filter-field{
            gap: 7px;
        }

        .properties-section .property-filter-field label{
            min-height: 14px;
            line-height: 1;
            white-space: nowrap;
        }

        .properties-section .property-filter-form select,
        .properties-section .property-filter-form input,
        .properties-section .btn-filter,
        .properties-section .btn-clear-filter{
            min-height: 46px;
            height: 46px;
            border-radius: 11px;
        }

        .properties-section .property-filter-form input,
        .properties-section .property-filter-form select{
            padding-inline: 13px;
            font-size: .86rem;
        }

        .properties-section .btn-filter,
        .properties-section .btn-clear-filter{
            width: 100%;
            padding-inline: 14px;
            font-size: .9rem;
        }

        .properties-section .property-filter-note{
            margin: 0 auto 28px auto;
        }

        @media (max-width: 1280px){
            .properties-section .property-filter-form{
                grid-template-columns: repeat(4, minmax(0, 1fr));
                align-items: stretch;
            }
            .properties-section .btn-filter,
            .properties-section .btn-clear-filter{
                align-self: end;
            }
        }

        @media (max-width: 768px){
            .properties-section{
                padding-top: 85px;
            }
            .properties-section .property-filter-form{
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                padding: 12px;
                border-radius: 16px;
            }
            .properties-section .property-filter-field:first-child{
                grid-column: 1 / -1;
            }
            .properties-section .property-filter-form select,
            .properties-section .property-filter-form input,
            .properties-section .btn-filter,
            .properties-section .btn-clear-filter{
                min-height: 44px;
                height: 44px;
                font-size: .82rem;
            }
            .properties-section .property-filter-field label{
                font-size: .66rem;
                white-space: normal;
            }
            .properties-section .property-filter-note{
                margin-top: -4px;
                margin-bottom: 18px;
                padding-inline: 8px;
            }
        }

        @media (max-width: 480px){
            .properties-section .property-filter-form{
                grid-template-columns: 1fr;
            }
        }

        /* === BUYER-FIRST HOMEPAGE UPGRADES === */
        section,
        header,
        .footer-wrapper {
            scroll-margin-top: 150px;
        }

        .section-padding {
            padding-top: 72px;
            padding-bottom: 72px;
        }

        .nav-reservation-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 40px;
            padding: 9px 14px;
            border-radius: 999px;
            background: rgba(244,208,63,.14);
            border: 1px solid rgba(244,208,63,.35);
            color: var(--primary-yellow);
            font-size: .82rem;
            font-weight: 800;
            white-space: nowrap;
            transition: .25s ease;
        }

        .nav-reservation-link:hover {
            background: var(--primary-yellow);
            color: var(--dark-bg);
            transform: translateY(-1px);
        }

        .properties-section .property-filter-form {
            grid-template-columns:
                minmax(240px, 1.4fr)
                repeat(4, minmax(120px, .75fr))
                minmax(105px, .6fr)
                minmax(140px, .75fr)
                minmax(105px, .6fr);
            align-items: end;
            margin-bottom: 18px;
        }

        .btn-more-filter {
            min-height: 46px;
            height: 46px;
            border-radius: 11px;
            border: 1px solid rgba(255,255,255,.18);
            background: rgba(255,255,255,.06);
            color: #e2e8f0;
            padding: 0 14px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap;
            width: 100%;
        }

        .btn-more-filter:hover,
        .btn-more-filter[aria-expanded="true"] {
            color: var(--primary-yellow);
            border-color: var(--primary-yellow);
            background: rgba(244,208,63,.10);
        }

        .more-filters-panel {
            grid-column: 1 / -1;
            display: grid;
            grid-template-columns: minmax(180px, 240px);
            gap: 12px;
            padding-top: 10px;
            border-top: 1px solid rgba(255,255,255,.10);
        }

        .more-filters-panel[hidden] {
            display: none;
        }

        .property-results-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            margin: 0 0 24px 0;
            color: #cbd5e1;
        }

        .property-results-bar p {
            font-size: .95rem;
            font-weight: 800;
            margin: 0;
        }

        .active-filter-chips {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 8px;
        }

        .active-filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(244,208,63,.14);
            color: var(--primary-yellow);
            border: 1px solid rgba(244,208,63,.32);
            font-size: .8rem;
            font-weight: 800;
        }

        .active-filter-chip:hover {
            background: var(--primary-yellow);
            color: var(--dark-bg);
        }

        .prop-img {
            aspect-ratio: 16 / 10;
        }

        .services-section .section-title {
            margin-bottom: 26px;
        }

        .services-section .features-grid {
            margin-bottom: 24px;
        }

        .service-extra {
            display: none;
        }

        .services-section.services-expanded .service-extra {
            display: block;
        }

        .services-more-wrap {
            text-align: center;
        }

        .btn-services-more {
            background: transparent;
            border: 2px solid var(--dark-bg);
            color: var(--dark-bg);
            box-shadow: none;
        }

        .btn-services-more:hover {
            background: var(--dark-bg);
            color: var(--primary-yellow);
        }

        .trust-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: -18px 0 26px 0;
        }

        .trust-badges span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 13px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #334155;
            font-size: .84rem;
            font-weight: 800;
            box-shadow: 0 8px 18px rgba(15,23,42,.05);
        }

        .trust-badges i {
            color: #16a34a;
        }

        @media (max-width: 1280px) {
            .properties-section .property-filter-form {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .nav-reservation-link span {
                display: none;
            }
        }

        @media (max-width: 768px) {
            section,
            header,
            .footer-wrapper {
                scroll-margin-top: 112px;
            }

            .section-padding {
                padding-top: 56px;
                padding-bottom: 56px;
            }

            .properties-section .property-filter-form {
                grid-template-columns: 1fr 1fr;
            }

            .properties-section .property-filter-field:first-child,
            .more-filters-panel {
                grid-column: 1 / -1;
            }

            .property-results-bar {
                justify-content: center;
                text-align: center;
            }

            .active-filter-chips {
                justify-content: center;
                width: 100%;
            }

            .nav-reservation-link {
                width: 40px;
                height: 40px;
                padding: 0;
                justify-content: center;
            }
        }

    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <div class="nav-logo">
            <a href="index.php" style="display:flex; align-items:center;">
                <!-- Using exactly the picture logo provided, verbatim. Height increased for visibility -->
                <img src="assets/LOGO1.png"
     alt="JEJ Top Priority Corporation Logo"
     style="
        height: 75px;
        width: auto;
        object-fit: contain;
        filter: drop-shadow(0 4px 12px rgba(0,0,0,.25));
     ">
                <!-- Text kept next to the logo -->
                <div class="nav-logo-text" style="margin-left: 15px;">
                    <h2>JEJ Top Priority Corporation</h2>
                    <span>SERVICES & REAL ESTATE</span>
                </div>
            </a>
        </div>
        <button type="button" class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu" aria-expanded="false">
    <i class="fa-solid fa-bars"></i>
</button>
        
        <div class="nav-links" id="navLinks">
            <a href="index.php">Home</a>
            <a href="#properties">Properties</a>
            <a href="#reservation-process">Reservation</a>
            <a href="#services">Services</a>
            <a href="#contact">Contact</a>
        </div>

        <div class="nav-actions">
            <?php if(isset($_SESSION['user_id'])): ?>
                <!-- Logged In View -->
                <?php if ($_SESSION['role'] === 'BUYER'): ?>
                    <a href="my_reservations.php" class="nav-reservation-link">
                        <i class="fa-solid fa-file-contract"></i>
                        <span>My Reservations</span>
                    </a>
                <?php endif; ?>
                <a href="notifications.php" class="notification-bell">
                    <i class="fa-regular fa-bell"></i>
                    <?php if($unread_count > 0): ?> <span class="notification-dot"></span> <?php endif; ?>
                </a>
                <div class="profile-dropdown-container">
                    <button type="button" class="profile-trigger" id="profileBtn" aria-label="Open profile menu">
                        <div class="profile-info" style="display: none;"> <!-- Hidden on mobile by default -->
                            <span class="profile-name"><?= htmlspecialchars($_SESSION['fullname']) ?></span>
                            <span class="profile-role"><?= htmlspecialchars($_SESSION['role']) ?></span>
                        </div>
                        <div class="avatar-circle <?= $current_profile_photo !== '' ? 'has-photo' : '' ?>">
                            <?php if ($current_profile_photo !== ''): ?>
                                <img src="<?= e($current_profile_photo) ?>" alt="Profile photo" class="avatar-photo" width="36" height="36" loading="lazy" decoding="async">
                            <?php else: ?>
                                <?= htmlspecialchars(strtoupper(substr($_SESSION['fullname'], 0, 1))) ?>
                            <?php endif; ?>
                        </div>
                    </button>
                        <div class="profile-dropdown-menu" id="profileDropdown">

    <a href="profile.php" class="profile-dropdown-item">
        <i class="fa-regular fa-user"></i> My Profile
    </a>

    <?php if ($_SESSION['role'] === 'CASHIER'): ?>

        <a href="financial.php" class="profile-dropdown-item">
            <i class="fa-solid fa-coins"></i> Finance Dashboard
        </a>

    <?php elseif (in_array($_SESSION['role'], ['SUPER ADMIN','ADMIN','MANAGER'])): ?>

        <a href="admin.php" class="profile-dropdown-item">
            <i class="fa-solid fa-shield-halved"></i> Admin Dashboard
        </a>

    <?php endif; ?>

    <a href="logout.php" class="profile-dropdown-item logout-btn">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
    </a>

</div>
                    </div>
            <?php else: ?>
                <!-- Not Logged In -->
                <button type="button" class="btn-primary" onclick="openModal('loginModal')"><i class="fa-regular fa-user"></i> Login / Register</button>
            <?php endif; ?>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <header class="hero" id="home">
        <!-- New Background Slideshow -->
        <div class="hero-slideshow">
            <div class="slide"></div>
            <div class="slide"></div>
            <div class="slide"></div>
        </div>

        <div class="hero-content reveal-up">
            <h1 class="hero-title">THE FUTURE IS BUILT<br><span>WITH PRECISION</span></h1>
            <p class="hero-subtitle">At JEJ Top Priority Corp, we provide expert land solutions and exclusive premium lot properties, helping you build, invest, and secure your future with confidence.</p>
            
            <form class="search-box hero-search" method="GET" action="index.php#properties">
                <?php if(!empty($_GET['status'])): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($_GET['status']) ?>">
                <?php endif; ?>
                <div class="search-input-group">
                    <i class="fa-solid fa-location-dot"></i>
                    <select name="q" id="heroPropertySearch" class="search-input" placeholder="Search by Location or Phase...">
                        <option value="">Search by Location or Phase...</option>
                        <?php foreach($locations as $loc): ?>
                            <option value="<?= htmlspecialchars($loc) ?>" <?= (isset($_GET['q']) && $_GET['q'] == $loc) ? 'selected' : '' ?>><?= htmlspecialchars($loc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-search">Find Property</button>
            </form>

            <div class="hero-actions">
                <a href="#properties" class="btn-primary"><i class="fa-solid fa-map-location-dot"></i> Browse Available Lots</a>
                <a href="#contact" class="btn-primary btn-hero-outline js-service-inquiry" data-service="Survey Service" aria-label="Inquire about survey services">
                    <i class="fa-solid fa-helmet-safety"></i> Inquire Survey Service
                </a>
            </div>
        </div>
    </header>

    <!-- OVERLAPPING CARDS -->
    <section class="hero-cards reveal-up">
        <div class="h-card"></div>
        <div class="h-card h-card-center">
            <h3><span>JEJ TOP PRIORITY CORP.</span> RESERVATION</h3>
            <p>Manage and reserve your lots seamlessly.</p>
        </div>
        <div class="h-card"></div>
    </section>

    
<!-- EXCLUSIVE PROPERTIES (DYNAMIC PHP SECTION) -->
    <section class="section-padding properties-section" id="properties">
        <div class="section-subtitle reveal-up" style="color: var(--primary-yellow);"><?= !empty($_GET['q']) ? 'SEARCH RESULTS' : 'Available Priority Lots' ?></div>
        <h2 class="section-title reveal-up"><?= !empty($_GET['q']) ? 'Found Properties' : 'Available Lots for Sale' ?></h2>

        <form class="property-filter-form reveal-up" method="GET" action="index.php#properties" aria-label="Available lots filter">
            <div class="property-filter-field">
                <label for="propertyLocationFilter">Location / Phase</label>
                <input type="search" name="q" id="propertyLocationFilter" list="propertyLocationOptions" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Type location or phase" aria-label="Search location or phase">
                <datalist id="propertyLocationOptions">
                    <?php foreach($locations as $loc): ?>
                        <option value="<?= e($loc) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="property-filter-field">
                <label for="minPriceFilter">Min Price</label>
                <input type="number" name="min_price" id="minPriceFilter" min="0" step="1" value="<?= e($_GET['min_price'] ?? '') ?>" placeholder="₱ min" aria-label="Minimum price">
            </div>

            <div class="property-filter-field">
                <label for="maxPriceFilter">Max Price</label>
                <input type="number" name="max_price" id="maxPriceFilter" min="0" step="1" value="<?= e($_GET['max_price'] ?? '') ?>" placeholder="₱ max" aria-label="Maximum price">
            </div>

            <div class="property-filter-field">
                <label for="minAreaFilter">Min Area</label>
                <input type="number" name="min_area" id="minAreaFilter" min="0" step="0.01" value="<?= e($_GET['min_area'] ?? '') ?>" placeholder="sqm min" aria-label="Minimum area in square meters">
            </div>

            <div class="property-filter-field">
                <label for="maxAreaFilter">Max Area</label>
                <input type="number" name="max_area" id="maxAreaFilter" min="0" step="0.01" value="<?= e($_GET['max_area'] ?? '') ?>" placeholder="sqm max" aria-label="Maximum area in square meters">
            </div>

            <div class="property-filter-field">
                <label for="classificationFilter">Type</label>
                <select name="classification" id="classificationFilter" aria-label="Lot classification">
                    <option value="ALL" <?= $selected_classification === 'ALL' ? 'selected' : '' ?>>All Types</option>
                    <option value="FRONT" <?= $selected_classification === 'FRONT' ? 'selected' : '' ?>>Front Lot</option>
                    <option value="INNER" <?= $selected_classification === 'INNER' ? 'selected' : '' ?>>Inner Lot</option>
                </select>
            </div>

            <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
            <button type="button" class="btn-more-filter" id="btnMoreFilters" aria-expanded="<?= $selected_status !== 'AVAILABLE' ? 'true' : 'false' ?>" aria-controls="moreFiltersPanel">
                <i class="fa-solid fa-sliders"></i> More Filters
            </button>
            <a href="index.php#properties" class="btn-clear-filter"><i class="fa-solid fa-rotate-left"></i> Clear</a>

            <div class="more-filters-panel" id="moreFiltersPanel" <?= $selected_status !== 'AVAILABLE' ? '' : 'hidden' ?>>
                <div class="property-filter-field">
                    <label for="propertyStatusFilter">Status</label>
                    <select name="status" id="propertyStatusFilter" aria-label="Lot status">
                        <option value="ALL" <?= $selected_status === 'ALL' ? 'selected' : '' ?>>All Status</option>
                        <option value="AVAILABLE" <?= $selected_status === 'AVAILABLE' ? 'selected' : '' ?>>Available</option>
                        <option value="RESERVED" <?= $selected_status === 'RESERVED' ? 'selected' : '' ?>>Reserved</option>
                        <option value="SOLD" <?= $selected_status === 'SOLD' ? 'selected' : '' ?>>Sold</option>
                    </select>
                </div>
            </div>
        </form>

        <div class="property-results-bar reveal-up">
            <p><?= e($lot_result_text) ?></p>
            <?php if(!empty($active_filter_chips)): ?>
                <div class="active-filter-chips" aria-label="Active filters">
                    <?php foreach($active_filter_chips as $chip): ?>
                        <a href="<?= e($chip['url']) ?>" class="active-filter-chip">
                            <?= e($chip['label']) ?> <span aria-hidden="true">×</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="property-grid">
            <?php if($result && $result->num_rows > 0): ?>
                <?php 
                $card_count = 0; 
                while($row = $result->fetch_assoc()): 
                    $card_count++;
                    $hidden_class = ($card_count > 6) ? 'hidden-card' : '';
                    $rawStatus = strtoupper((string)($row['status'] ?? 'AVAILABLE'));
                    $status_badge = e($rawStatus);
                    $badgeClass = preg_replace('/[^A-Z0-9_-]/', '-', $rawStatus);
                    $locationName = jej_first_non_empty($row, ['location', 'phase_name'], 'JEJ Property');
                    $blockNo = jej_first_non_empty($row, ['block_no', 'block', 'blk'], '-');
                    $lotNo = jej_first_non_empty($row, ['lot_no', 'lot'], '-');
                    $areaValue = jej_first_non_empty($row, ['area_sqm', 'lot_area', 'area', 'sqm']);
                    $lotType = jej_first_non_empty($row, ['classification', 'property_overview', 'lot_type', 'type']);
                    $totalPrice = (float)jej_first_non_empty($row, ['total_price', 'tcp', 'price'], 0);
                    $pricePerSqm = jej_first_non_empty($row, ['price_per_sqm', 'price_sqm']);

                    if ($pricePerSqm === '' && is_numeric($areaValue) && (float)$areaValue > 0 && $totalPrice > 0) {
                        $pricePerSqm = $totalPrice / (float)$areaValue;
                    }

                    // Build safe lot image URL from uploads/ or storage/uploads.
                    $lotImageUrl = jej_lot_image_url($row['lot_image'] ?? '', $locationName);
                ?>
                
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="lot_details.php?id=<?= urlencode($row['id']) ?>" class="prop-card reveal-up <?= $hidden_class ?>">
                <?php else: ?>
                    <a href="#" onclick="event.preventDefault(); openModal('loginModal')" class="prop-card reveal-up <?= $hidden_class ?>">
                <?php endif; ?>
                
                    <div class="prop-img-box">
                        <img src="<?= e($lotImageUrl) ?>"
                             class="prop-img"
                             alt="<?= e($locationName) ?> Block <?= e($blockNo) ?> Lot <?= e($lotNo) ?>"
                             width="640"
                             height="420"
                             loading="lazy"
                             decoding="async">
                        <span class="prop-badge badge-<?= e($badgeClass) ?>"><?= $status_badge ?></span>
                    </div>
                    <div class="prop-info">
                        <div class="prop-loc"><i class="fa-solid fa-map-pin"></i> <?= e($locationName) ?></div>
                        <h3 class="prop-title">Block <?= e($blockNo) ?>, Lot <?= e($lotNo) ?></h3>

                        <div class="prop-meta">
                            <?php if($areaValue !== ''): ?>
                                <span class="prop-chip"><i class="fa-solid fa-vector-square"></i> <?= e($areaValue) ?> sqm</span>
                            <?php endif; ?>
                            <?php if($lotType !== ''): ?>
                                <span class="prop-chip"><i class="fa-solid fa-layer-group"></i> <?= e($lotType) ?></span>
                            <?php endif; ?>
                            <?php if($pricePerSqm !== ''): ?>
                                <span class="prop-chip"><i class="fa-solid fa-tag"></i> ₱<?= number_format((float)$pricePerSqm) ?>/sqm</span>
                            <?php endif; ?>
                        </div>

                        <div class="prop-footer">
                            <div class="prop-price">₱<?= number_format($totalPrice) ?></div>
                            <div class="prop-view">View Details <i class="fa-solid fa-arrow-right"></i></div>
                        </div>
                    </div>
                </a>
                <?php endwhile; ?>
                
            <?php else: ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 60px 20px; background: #1e293b; border-radius: 16px; border: 1px solid rgba(255,255,255,0.1);" class="reveal-up">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 2.5rem; color: #475569; margin-bottom: 15px;"></i>
                    <h3 style="font-size: 1.3rem; color: white; margin-bottom: 8px;">No properties found</h3>
                    <p style="color: #94a3b8; font-size: 0.9rem;">Try adjusting your search filters to find what you're looking for.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if($result && $result->num_rows > 6): ?>
            <div id="viewMoreContainer" style="text-align: center; margin-top: 40px;" class="reveal-up">
                <button type="button" id="btnViewMore" class="btn-primary" style="background: transparent; border: 2px solid var(--primary-yellow); color: var(--primary-yellow);">
                    View More Properties <i class="fa-solid fa-angle-down"></i>
                </button>
            </div>
        <?php endif; ?>
    </section>

    <!-- RESERVATION PROCESS -->
    <section class="section-padding process-section" id="reservation-process">
        <div class="section-subtitle reveal-left">HOW TO RESERVE</div>
        <h2 class="section-title reveal-left">Simple Reservation Process</h2>

        <div class="process-grid">
            <div class="process-step reveal-up">
                <div class="step-number">1</div>
                <h3>Choose Your Lot</h3>
                <p>Browse available lots and review the location, block, lot number, area, and pricing details.</p>
            </div>
            <div class="process-step reveal-up" style="transition-delay:.1s;">
                <div class="step-number">2</div>
                <h3>Create Buyer Account</h3>
                <p>Register with OTP verification so your reservation and documents stay connected to your account.</p>
            </div>
            <div class="process-step reveal-up" style="transition-delay:.2s;">
                <div class="step-number">3</div>
                <h3>Submit Reservation</h3>
                <p>Send your reservation request and wait for ID/payment verification from the JEJ team.</p>
            </div>
            <div class="process-step reveal-up" style="transition-delay:.3s;">
                <div class="step-number">4</div>
                <h3>Track Your Progress</h3>
                <p>Use your buyer portal to monitor status, payments, SOA, and uploaded documents.</p>
            </div>
        </div>
    </section>

    <!-- FEATURES GRID / SERVICES -->
    <section class="section-padding services-section" id="services">
        <div class="section-subtitle reveal-left">OUR CORE SERVICES</div>
        <h2 class="section-title reveal-left">Professional Survey<br>Solutions</h2>
        <div class="features-grid">

            <a href="#contact" class="feature-box service-card reveal-up" data-service="Boundary Relocation Survey" style="transition-delay: 0s;" aria-label="Inquire about Boundary Relocation Survey">
                <div class="feature-icon">
                    <i class="fa-solid fa-draw-polygon"></i>
                </div>
                <h3>Boundary Relocation Survey</h3>
                <p>
                    Accurate re-establishment of property boundaries and corner monuments to resolve disputes and verify ownership limits.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

            <a href="#contact" class="feature-box service-card reveal-up" data-service="Topographic Survey" style="transition-delay: 0.1s;" aria-label="Inquire about Topographic Survey">
                <div class="feature-icon">
                    <i class="fa-solid fa-mountain-sun"></i>
                </div>
                <h3>Topographic Survey</h3>
                <p>
                    Detailed mapping of land features, elevations, and contours for engineering, planning, and development projects.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

            <a href="#contact" class="feature-box service-card reveal-up" data-service="Lot Survey & Verification" style="transition-delay: 0.2s;" aria-label="Inquire about Lot Survey and Verification">
                <div class="feature-icon">
                    <i class="fa-solid fa-map-location-dot"></i>
                </div>
                <h3>Lot Survey &amp; Verification</h3>
                <p>
                    Comprehensive field verification and measurement of lot boundaries, dimensions, and technical descriptions.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

            <a href="#contact" class="feature-box service-card reveal-up" data-service="Subdivision Design & Planning" style="transition-delay: 0.3s;" aria-label="Inquire about Subdivision Design and Planning">
                <div class="feature-icon">
                    <i class="fa-solid fa-compass-drafting"></i>
                </div>
                <h3>Subdivision Design &amp; Planning</h3>
                <p>
                    Professional planning and layout of residential, commercial, and mixed-use subdivisions with regulatory compliance.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

            <a href="#contact" class="feature-box service-card reveal-up" data-service="Property Consolidation & Segregation" style="transition-delay: 0.4s;" aria-label="Inquire about Property Consolidation and Segregation">
                <div class="feature-icon">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <h3>Property Consolidation &amp; Segregation</h3>
                <p>
                    Survey and documentation services for combining or subdividing land parcels to meet ownership and development requirements.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

            <a href="#contact" class="feature-box service-card reveal-up" data-service="Construction Staking & Layout" style="transition-delay: 0.5s;" aria-label="Inquire about Construction Staking and Layout">
                <div class="feature-icon">
                    <i class="fa-solid fa-ruler-combined"></i>
                </div>
                <h3>Construction Staking &amp; Layout</h3>
                <p>
                    Precise field staking and layout services for buildings, roads, utilities, and infrastructure projects.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

            <a href="#contact" class="feature-box service-card service-extra reveal-up" data-service="Land Titling Assistance" style="transition-delay: 0.6s;" aria-label="Inquire about Land Titling Assistance">
                <div class="feature-icon">
                    <i class="fa-solid fa-scroll"></i>
                </div>
                <h3>Land Titling Assistance</h3>
                <p>
                    End-to-end assistance in securing original land titles, transfer certificates, and ownership documentation.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

            <a href="#contact" class="feature-box service-card service-extra reveal-up" data-service="Land Documentation & Approval Processing" style="transition-delay: 0.7s;" aria-label="Inquire about Land Documentation and Approval Processing">
                <div class="feature-icon">
                    <i class="fa-solid fa-file-signature"></i>
                </div>
                <h3>Land Documentation &amp; Approval Processing</h3>
                <p>
                    Preparation and processing of survey plans, technical descriptions, permits, and documentary requirements.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

            <a href="#contact" class="feature-box service-card service-extra reveal-up" data-service="Title Transfer Assistance" style="transition-delay: 0.8s;" aria-label="Inquire about Title Transfer Assistance">
                <div class="feature-icon">
                    <i class="fa-solid fa-file-contract"></i>
                </div>
                <h3>Title Transfer Assistance</h3>
                <p>
                    Professional support in transferring land ownership, including registration and coordination with government agencies.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

            <a href="#contact" class="feature-box service-card service-extra reveal-up" data-service="Land Development Consultancy" style="transition-delay: 0.9s;" aria-label="Inquire about Land Development Consultancy">
                <div class="feature-icon">
                    <i class="fa-solid fa-city"></i>
                </div>
                <h3>Land Development Consultancy</h3>
                <p>
                    Expert guidance for residential, commercial, and estate development projects covering planning, compliance, and implementation.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

            <a href="#contact" class="feature-box service-card service-extra reveal-up" data-service="As-Built Survey & Mapping" style="transition-delay: 1s;" aria-label="Inquire about As-Built Survey and Mapping">
                <div class="feature-icon">
                    <i class="fa-solid fa-map"></i>
                </div>
                <h3>As-Built Survey &amp; Mapping</h3>
                <p>
                    Final documentation of completed structures, site layout, and property improvements for records, permits, and project turnover.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

            <a href="#contact" class="feature-box service-card service-extra reveal-up" data-service="Geodetic Control Survey" style="transition-delay: 1.1s;" aria-label="Inquire about Geodetic Control Survey">
                <div class="feature-icon">
                    <i class="fa-solid fa-satellite-dish"></i>
                </div>
                <h3>Geodetic Control Survey</h3>
                <p>
                    Establishment of accurate survey control points for subdivision, mapping, engineering, and land development projects.
                </p>
                <span class="service-card-cta"><i class="fa-solid fa-message"></i> Inquire Now</span>
            </a>

        </div>
        <div class="services-more-wrap reveal-up">
            <button type="button" id="btnViewMoreServices" class="btn-primary btn-services-more">
                View More Services <i class="fa-solid fa-angle-down"></i>
            </button>
        </div>
    </section>

    <!-- WHY CHOOSE US -->
    <section class="section-padding why-section" id="why-choose">
        <div class="section-subtitle reveal-left">WHY CHOOSE JEJ</div>
        <h2 class="section-title reveal-left">Reliable Land Solutions<br>with Transparent Client Support</h2>

        <div class="trust-badges reveal-up" aria-label="JEJ buyer protection features">
            <span><i class="fa-solid fa-circle-check"></i> Admin verified reservations</span>
            <span><i class="fa-solid fa-lock"></i> Secure document upload</span>
            <span><i class="fa-solid fa-receipt"></i> Payment proof reviewed</span>
            <span><i class="fa-solid fa-file-invoice"></i> SOA after approval</span>
        </div>

        <div class="why-grid">
            <div class="why-item reveal-up">
                <i class="fa-solid fa-user-check"></i>
                <h3>Licensed Survey Support</h3>
                <p>Guided survey assistance for relocation, topographic mapping, as-built documentation, and control points.</p>
            </div>
            <div class="why-item reveal-up" style="transition-delay:.1s;">
                <i class="fa-solid fa-file-shield"></i>
                <h3>Land Documentation Assistance</h3>
                <p>Support for land titling, title transfer, approvals, technical descriptions, and property documentation.</p>
            </div>
            <div class="why-item reveal-up" style="transition-delay:.2s;">
                <i class="fa-solid fa-house-lock"></i>
                <h3>Secure Buyer Portal</h3>
                <p>Buyers can track reservations, statements, contracts, payment history, and uploaded documents in one place.</p>
            </div>
            <div class="why-item reveal-up" style="transition-delay:.3s;">
                <i class="fa-solid fa-receipt"></i>
                <h3>Verified Payment Tracking</h3>
                <p>Payment records are monitored with verification flow, helping buyers see clearer and safer transaction updates.</p>
            </div>
            <div class="why-item reveal-up" style="transition-delay:.4s;">
                <i class="fa-solid fa-handshake"></i>
                <h3>Transparent Lot Reservation</h3>
                <p>Clear property details, lot status, pricing, and reservation steps help clients make faster decisions.</p>
            </div>
        </div>
    </section>

    <!-- FOOTER & CONTACT OVERLAP -->
    <div class="footer-wrapper" id="contact">
        <!-- Cleaned Up Overlapping Form. Uses normal flow instead of absolute positioning to prevent overlap bugs. -->
        <div class="contact-overlap reveal-up">
            <div class="contact-text-area">
                <h4><i class="fa-solid fa-envelope-open-text"></i> CONTACT US</h4>
                <h2>Ready to map your property or reserve a lot?</h2>
                <p>Send us a message and our lead surveyors will get back to you shortly.</p>
            </div>
            <div class="contact-form-area">
                <?php if (isset($_GET['contact']) && $_GET['contact'] === 'success'): ?>
                    <div class="contact-alert success"><i class="fa-solid fa-circle-check"></i> Message sent successfully. Our team will contact you soon.</div>
                <?php elseif (isset($_GET['contact']) && $_GET['contact'] === 'error'): ?>
                    <div class="contact-alert error"><i class="fa-solid fa-circle-exclamation"></i> Message failed. Please check your details and try again.</div>
                <?php endif; ?>

                <div class="selected-service-alert" id="selectedServiceAlert" aria-live="polite">
                    <i class="fa-solid fa-circle-check"></i>
                    <span>Selected Service: <strong id="selectedServiceName"></strong></span>
                </div>

                <form action="actions.php" method="POST" autocomplete="on">
                    <input type="hidden" name="action_type" value="contact_inquiry">
                    <input type="hidden" name="service_type" id="contactServiceType" value="">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <div class="hp-field" aria-hidden="true">
                        <label>Leave this field blank</label>
                        <input type="text" name="website" tabindex="-1" autocomplete="off">
                    </div>
                    <input type="text" name="full_name" placeholder="Full Name / Company" maxlength="120" required>
                    <input type="email" name="email" placeholder="Email Address" maxlength="150" required>
                    <input type="tel" name="phone" placeholder="Phone Number" maxlength="30" required>
                    <textarea name="message" id="contactMessage" placeholder="Click a service card or type your inquiry here..." maxlength="1000" required></textarea>
                    <button type="submit" class="btn-submit">Send Message</button>
                </form>
            </div>
        </div>

        <!-- Footer Content -->
        <div class="footer-content">
            <div class="footer-col reveal-up">
                <div class="nav-logo" style="margin-bottom: 15px; display:flex; align-items:center;">
                    <img src="assets/LOGO1.png"
     alt="JEJ Top Priority Corporation Logo"
     style="
        height: 75px;
        width: auto;
        object-fit: contain;
        filter: drop-shadow(0 4px 12px rgba(0,0,0,.25));
     ">
                    <div class="nav-logo-text" style="margin-left: 10px;">
                        <h2 style="font-size: 1.1rem; line-height: 1;">JEJ Top Priority Corporation</h2>
                        <span style="font-size: 8px;">SERVICES & REAL ESTATE</span>
                    </div>
                </div>
                <p>JEJ Top Priority Corporation provides comprehensive land surveying, land development, and property documentation services. From Boundary Relocation, Topographic Surveys, and Subdivision Design to Construction Layout, Lot Verification, Land Titling, Approval Processing, and Title Transfer Assistance, we deliver precision, compliance, and reliability—helping property owners, investors, and developers transform land into lasting opportunities.</p>
            </div>
            <div class="footer-col reveal-up" style="transition-delay: 0.1s;">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="#home"><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; margin-right: 5px;"></i> Home</a></li>
                    <li><a href="#services"><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; margin-right: 5px;"></i> Services</a></li>
                    <li><a href="#properties"><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; margin-right: 5px;"></i> Properties</a></li>
                </ul>
            </div>
            <div class="footer-col reveal-up" style="transition-delay: 0.2s;">
                <h4>Client Portals</h4>
                <ul>
                    <li><a href="dashboard.php"><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; margin-right: 5px;"></i> User Dashboard</a></li>
                    <li><a href="my_reservations.php"><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; margin-right: 5px;"></i> My Reservations</a></li>
                    <li><a href="statement_of_account.php"><i class="fa-solid fa-chevron-right" style="font-size: 0.7rem; margin-right: 5px;"></i> Statements</a></li>
                </ul>
            </div>
            <div class="footer-col reveal-up" style="transition-delay: 0.3s;">
                <h4>Contact Info</h4>
                <ul>
                    <li style="color: #94a3b8;"><i class="fa-solid fa-location-dot" style="margin-right: 8px;"></i> Purok Francisco, Langla, Jaen, Nueva Ecija, Philippines</li>
                    <li style="color: #94a3b8;"><i class="fa-solid fa-envelope" style="margin-right: 8px;"></i>jejtoppriority@gmail.com</li>
                </ul>
                <p class="footer-contact-note">For faster response, use the call or Facebook message button below. The inquiry form above already has the Send Message button.</p>
                <div class="footer-quick-actions">
                    <a class="footer-action-btn" href="tel:09751346179"><i class="fa-solid fa-phone"></i> Call 0975 134 6179</a>
                    <a class="footer-action-btn" href="https://www.facebook.com/jejtoppriority" target="_blank" rel="noopener"><i class="fa-brands fa-facebook-messenger"></i> Message on Facebook</a>
                </div>
            </div>
        </div>
        <div style="text-align: center; padding: 20px; border-top: 1px solid rgba(255,255,255,0.05); color: #64748b; font-size: 0.85rem;">
            &copy; <?= date('Y') ?> JEJ Top Priority Corporation. All Rights Reserved. Built with precision.
        </div>
    </div>


    <!-- ==========================================
         AUTHENTICATION MODALS (LOGIN, REGISTER, OTP) 
         ========================================== -->

    <!-- LOGIN MODAL -->
    <div class="modal-overlay" id="loginModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('loginModal')" aria-label="Close login modal"><i class="fa-solid fa-xmark"></i></button>

            <div class="login-left">
                <div class="login-brand">
                    <img src="assets/LOGO1.png" alt="JEJ Top Priority Corporation Logo">
                    <div class="login-brand-title">JEJ Top Priority Corporation</div>
                    <div class="login-brand-sub">Services & Real Estate</div>

                    <ul class="login-benefits">
                        <li><i class="fa-solid fa-circle-check"></i> Property Reservation</li>
                        <li><i class="fa-solid fa-circle-check"></i> Real Estate Management</li>
                        <li><i class="fa-solid fa-circle-check"></i> Online Buyer Portal</li>
                        <li><i class="fa-solid fa-circle-check"></i> Secure Document Access</li>
                    </ul>
                </div>

                <div class="login-welcome">
                    <h3>Welcome Back</h3>
                    <p>View available lots, track reservations, manage payments, and download important documents.</p>
                </div>
            </div>

            <div class="modal-right">
                <h2 class="modal-title">Sign In</h2>
                <p class="modal-subtitle">Access your JEJ Top Priority Corporation account.</p>

                <?php if ($show_modal == 'loginModal' && !empty($auth_message)): ?>
                    <div class="alert-box alert-<?= $auth_status ?>">
                        <i class="fa-solid <?= $auth_status == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                        <?= htmlspecialchars($auth_message) ?>
                    </div>
                <?php endif; ?>

                <form action="index.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="modal-form-group">
                        <label class="modal-form-label">Email Address</label>
                        <input type="email" name="email" class="modal-input" placeholder="Enter your email" maxlength="150" autocomplete="email" value="<?= (isset($_POST['login']) && isset($_POST['email'])) ? htmlspecialchars($_POST['email']) : (isset($_COOKIE['jej_remember_email']) ? htmlspecialchars($_COOKIE['jej_remember_email']) : '') ?>" required>
                    </div>

                    <div class="modal-form-group">
                        <label class="modal-form-label">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="loginPassword" name="password" class="modal-input" placeholder="Enter your password" maxlength="128" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Show or hide password">
                                <i class="fa-solid fa-eye" id="passwordToggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="login-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember" <?= isset($_COOKIE['jej_remember_email']) ? 'checked' : '' ?>>
                            Remember Me
                        </label>

                        <a href="#" class="forgot-link" onclick="event.preventDefault(); switchModal('loginModal', 'forgotModal')">
                            Forgot Password?
                        </a>
                    </div>

                    <button type="submit" name="login" class="btn-modal-submit">
                        <i class="fa-solid fa-shield-halved"></i>
                        Secure Sign In
                    </button>

                    <p class="login-note">
                        Administrator • Manager • Cashier • Agent • Buyer
                    </p>
                </form>

                <div class="modal-footer-text">
                    New to JEJ Top Priority Corporation? <a href="#" onclick="event.preventDefault(); switchModal('loginModal', 'registerModal')">Create an account</a>
                </div>
            </div>
        </div>
    </div>

    <!-- REGISTER MODAL -->
    <div class="modal-overlay" id="registerModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('registerModal')"><i class="fa-solid fa-xmark"></i></button>
            <div class="modal-left">
                <h3>Start Your Journey</h3>
                <p>Create an account today to reserve your dream property and secure your structural future.</p>
            </div>
            <div class="modal-right">
                <h2 class="modal-title">Create Account</h2>
                <p class="modal-subtitle">We'll send a secure OTP to verify your email.</p>
                
                <?php if ($show_modal == 'registerModal' && !empty($auth_message)): ?>
                    <div class="alert-box alert-<?= $auth_status ?>">
                        <i class="fa-solid <?= $auth_status == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                        <?= htmlspecialchars($auth_message) ?>
                    </div>
                <?php endif; ?>
                
                <form action="index.php" method="POST" onsubmit="return validatePassword()">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="modal-form-row">
                        <div>
                            <label class="modal-form-label">Full Name</label>
                            <input type="text" name="fullname" class="modal-input" placeholder="John Doe" maxlength="120" autocomplete="name" value="<?= isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : '' ?>" required>
                        </div>
                        <div>
                            <label class="modal-form-label">Phone Number</label>
                            <input type="text" name="phone" class="modal-input" placeholder="09XX XXX XXXX" maxlength="30" autocomplete="tel" value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>" required>
                        </div>
                    </div>
                    <div class="modal-form-group">
                        <label class="modal-form-label">Email Address</label>
                        <input type="email" name="email" class="modal-input" placeholder="john@example.com" maxlength="150" autocomplete="email" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" required>
                    </div>
                    <div class="modal-form-row">
                        <div>
                            <label class="modal-form-label">Password</label>
                            <input type="password" name="password" id="reg_pass" class="modal-input" placeholder="••••••••" maxlength="128" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div>
                            <label class="modal-form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" id="reg_confirm" class="modal-input" placeholder="••••••••" maxlength="128" minlength="8" autocomplete="new-password" required>
                        </div>
                    </div>
                    <button type="submit" name="register_request" class="btn-modal-submit">Verify Email <i class="fa-solid fa-envelope-circle-check"></i></button>
                </form>
                
                <div class="modal-footer-text">
                    Already have an account? <a href="#" onclick="event.preventDefault(); switchModal('registerModal', 'loginModal')">Sign in here</a>
                </div>
            </div>
        </div>
    </div>

    <!-- OTP MODAL -->
    <div class="modal-overlay" id="otpModal">
        <div class="modal-content" style="max-width: 500px; min-height: 400px; margin: 0 auto;">
            <button class="modal-close" onclick="closeModal('otpModal')"><i class="fa-solid fa-xmark"></i></button>
            <div class="modal-right" style="padding: 40px; border-radius: 20px;">
                <div style="text-align: center; margin-bottom: 20px;">
                    <i class="fa-solid fa-shield-halved" style="font-size: 2.5rem; color: var(--primary-yellow); margin-bottom: 10px;"></i>
                    <h2 class="modal-title" style="text-align: center;">Verify Email</h2>
                    <p class="modal-subtitle" style="text-align: center;">Enter the 6-digit code sent to your email.</p>
                </div>
                
                <?php if ($show_modal == 'otpModal' && !empty($auth_message)): ?>
                    <div class="alert-box alert-<?= $auth_status ?>">
                        <i class="fa-solid <?= $auth_status == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                        <?= htmlspecialchars($auth_message) ?>
                    </div>
                <?php endif; ?>
                
                <form action="index.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="modal-form-group">
                        <input type="text" name="otp_code" class="modal-input" placeholder="000000" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" style="text-align: center; letter-spacing: 10px; font-size: 24px; padding: 15px; font-weight: 800; color: var(--dark-bg); border-color: var(--primary-yellow);" required autocomplete="off">
                    </div>
                    <button type="submit" name="verify_otp" class="btn-modal-submit">Complete Registration <i class="fa-solid fa-check"></i></button>
                </form>
                
                <div class="modal-footer-text">
                    Didn't receive the code? <a href="#" onclick="event.preventDefault(); switchModal('otpModal', 'registerModal')">Start over</a>
                </div>
            </div>
        </div>
    </div>



    <!-- FORGOT PASSWORD MODAL -->
    <div class="modal-overlay" id="forgotModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('forgotModal')"><i class="fa-solid fa-xmark"></i></button>
            <div class="modal-left">
                <h3>Recover Access</h3>
                <p>Enter your registered email and we will send a secure reset code to help you create a new password.</p>
                <ul class="login-benefits">
                    <li><i class="fa-solid fa-circle-check"></i> Email OTP verification</li>
                    <li><i class="fa-solid fa-circle-check"></i> Secure password update</li>
                    <li><i class="fa-solid fa-circle-check"></i> Buyer and admin account support</li>
                </ul>
            </div>
            <div class="modal-right">
                <h2 class="modal-title">Forgot Password?</h2>
                <p class="modal-subtitle">Reset your JEJ account password securely.</p>

                <?php if ($show_modal == 'forgotModal' && !empty($auth_message)): ?>
                    <div class="alert-box alert-<?= $auth_status ?>">
                        <i class="fa-solid <?= $auth_status == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                        <?= htmlspecialchars($auth_message) ?>
                    </div>
                <?php endif; ?>

                <?php if (!isset($_SESSION['forgot_reset'])): ?>
                    <form action="index.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="modal-form-group">
                            <label class="modal-form-label">Registered Email Address</label>
                            <input type="email" name="forgot_email" class="modal-input" placeholder="Enter your registered email" maxlength="150" autocomplete="email" value="<?= isset($_POST['forgot_email']) ? htmlspecialchars($_POST['forgot_email']) : '' ?>" required>
                        </div>
                        <button type="submit" name="forgot_request" class="btn-modal-submit">
                            <i class="fa-solid fa-paper-plane"></i>
                            Send Reset Code
                        </button>
                    </form>
                <?php else: ?>
                    <form action="index.php" method="POST" onsubmit="return validateForgotReset()">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="login-note" style="margin-bottom: 16px;">
                            Code sent to <?= htmlspecialchars($_SESSION['forgot_reset']['email'] ?? 'your email') ?>
                        </div>
                        <div class="modal-form-group">
                            <label class="modal-form-label">Reset Code</label>
                            <input type="text" name="reset_code" class="modal-input" placeholder="000000" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" style="text-align:center; letter-spacing:8px; font-size:20px; font-weight:800;" autocomplete="one-time-code" required>
                        </div>
                        <div class="modal-form-row">
                            <div>
                                <label class="modal-form-label">New Password</label>
                                <input type="password" name="reset_password" id="reset_pass" class="modal-input" placeholder="Create new password" maxlength="128" minlength="8" autocomplete="new-password" required>
                            </div>
                            <div>
                                <label class="modal-form-label">Confirm New Password</label>
                                <input type="password" name="reset_confirm_password" id="reset_confirm" class="modal-input" placeholder="Confirm password" maxlength="128" minlength="8" autocomplete="new-password" required>
                            </div>
                        </div>
                        <button type="submit" name="forgot_reset" class="btn-modal-submit">
                            <i class="fa-solid fa-key"></i>
                            Reset Password
                        </button>
                    </form>
                <?php endif; ?>

                <div class="modal-footer-text">
                    Remembered your password? <a href="#" onclick="event.preventDefault(); switchModal('forgotModal', 'loginModal')">Back to sign in</a>
                </div>
            </div>
        </div>
    </div>

    <!-- BACK TO TOP BUTTON -->
    <button id="backToTop" class="back-to-top" aria-label="Back to top">
        <i class="fa-solid fa-arrow-up"></i>
    </button>


    <script src="https://cdn.jsdelivr.net/npm/tom-select/dist/js/tom-select.complete.min.js"></script>

    <!-- SCRIPTS -->
    <script>
        // Show / Hide Login Password
        function togglePassword() {
            const passwordInput = document.getElementById('loginPassword');
            const icon = document.getElementById('passwordToggleIcon');

            if (!passwordInput || !icon) return;

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Form Validation for Passwords
        function validatePassword() {
            var pass = document.getElementById('reg_pass').value;
            var confirm = document.getElementById('reg_confirm').value;
            if(pass !== confirm) {
                alert("Passwords do not match. Please try again.");
                return false;
            }
            return true;
        }

        function validateForgotReset() {
            const pass = document.getElementById('reset_pass');
            const confirm = document.getElementById('reset_confirm');

            if (!pass || !confirm) return true;
            if (pass.value.length < 8) {
                alert('New password must be at least 8 characters.');
                return false;
            }
            if (pass.value !== confirm.value) {
                alert('New password and confirmation do not match.');
                return false;
            }
            return true;
        }

        // Modal Logic
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        function switchModal(closeId, openId) {
            const closeModalEl = document.getElementById(closeId);
            if (closeModalEl) closeModalEl.classList.remove('active');
            setTimeout(() => {
                openModal(openId);
            }, 250);
        }

        // Close modal if user clicks outside of the white content box
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });

        document.addEventListener("DOMContentLoaded", function() {
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const navLinks = document.getElementById('navLinks');

            if (window.TomSelect && document.getElementById('heroPropertySearch')) {
                new TomSelect('#heroPropertySearch', {
                    create: false,
                    allowEmptyOption: true,
                    maxOptions: 1000,
                    sortField: { field: 'text', direction: 'asc' }
                });
            }

            // Property filter uses a normal search input with datalist to avoid the long dropdown covering lot cards.
            const btnMoreFilters = document.getElementById('btnMoreFilters');
            const moreFiltersPanel = document.getElementById('moreFiltersPanel');
            if (btnMoreFilters && moreFiltersPanel) {
                btnMoreFilters.addEventListener('click', function() {
                    const isHidden = moreFiltersPanel.hasAttribute('hidden');
                    moreFiltersPanel.toggleAttribute('hidden', !isHidden);
                    btnMoreFilters.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
                });
            }

            // Make Professional Survey Solutions cards open the inquiry form
            const contactSection = document.getElementById('contact');
            const contactMessage = document.getElementById('contactMessage');
            const contactServiceType = document.getElementById('contactServiceType');
            const selectedServiceAlert = document.getElementById('selectedServiceAlert');
            const selectedServiceName = document.getElementById('selectedServiceName');
            const contactFormArea = document.querySelector('.contact-form-area');

            function openServiceInquiry(serviceName) {
                if (!serviceName || !contactSection || !contactMessage) return;

                const inquiryMessage = `Inquiry Type: ${serviceName}

Hi JEJ Top Priority Corporation,

I would like to inquire about ${serviceName}. Please send me the requirements, estimated timeline, and service cost.

Thank you.`;

                contactMessage.value = inquiryMessage;
                contactMessage.dataset.autofilled = '1';

                if (contactServiceType) {
                    contactServiceType.value = serviceName;
                }

                if (selectedServiceAlert && selectedServiceName) {
                    selectedServiceName.textContent = serviceName;
                    selectedServiceAlert.classList.add('active');
                }

                if (contactFormArea) {
                    contactFormArea.classList.add('service-selected');
                    setTimeout(function() {
                        contactFormArea.classList.remove('service-selected');
                    }, 1800);
                }

                contactSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                window.history.replaceState(null, '', '#contact');

                setTimeout(function() {
                    contactMessage.focus({ preventScroll: true });
                }, 650);
            }

            document.querySelectorAll('.service-card[data-service], .js-service-inquiry[data-service]').forEach(function(card) {
                card.addEventListener('click', function(e) {
                    e.preventDefault();
                    openServiceInquiry(this.dataset.service || this.querySelector('h3')?.textContent.trim());
                });
            });

            // Also support direct links such as index.php?service=Topographic%20Survey#contact
            const serviceFromUrl = new URLSearchParams(window.location.search).get('service');
            if (serviceFromUrl && window.location.hash === '#contact') {
                openServiceInquiry(serviceFromUrl);
            }

            if(mobileBtn && navLinks){
                mobileBtn.addEventListener('click', function(){
                    const isOpen = navLinks.classList.toggle('show');
                    mobileBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });

                navLinks.querySelectorAll('a').forEach(function(link){
                    link.addEventListener('click', function(){
                        navLinks.classList.remove('show');
                        mobileBtn.setAttribute('aria-expanded', 'false');
                    });
                });
            }
            // PHP Injection: Open specific modal if there was an error/success message
            <?php if (!empty($show_modal)): ?>
                openModal('<?= htmlspecialchars($show_modal) ?>');
            <?php endif; ?>

            // Profile Dropdown Toggle Logic
            const profileBtn = document.getElementById('profileBtn');
            const profileDropdown = document.getElementById('profileDropdown');
            if (profileBtn && profileDropdown) {
                profileBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    profileDropdown.classList.toggle('active');
                });
                document.addEventListener('click', function() {
                    profileDropdown.classList.remove('active');
                });
            }

            // View More Properties Logic
            const btnViewMore = document.getElementById('btnViewMore');
            if (btnViewMore) {
                btnViewMore.addEventListener('click', function() {
                    const hiddenCards = document.querySelectorAll('.hidden-card');
                    
                    hiddenCards.forEach((card, index) => {
                        card.classList.remove('hidden-card');
                        setTimeout(() => { card.classList.add('visible'); }, index * 100); 
                    });
                    
                    document.getElementById('viewMoreContainer').style.display = 'none';
                });
            }

            // View More Services Logic
            const btnViewMoreServices = document.getElementById('btnViewMoreServices');
            const servicesSection = document.getElementById('services');
            if (btnViewMoreServices && servicesSection) {
                btnViewMoreServices.addEventListener('click', function() {
                    servicesSection.classList.add('services-expanded');
                    document.querySelectorAll('.service-extra').forEach(function(card, index) {
                        setTimeout(function() {
                            card.classList.add('visible');
                        }, index * 80);
                    });
                    btnViewMoreServices.parentElement.style.display = 'none';
                });
            }

            // Professional Scroll Animations (IntersectionObserver)
            const observerOptions = { threshold: 0.1, rootMargin: "0px 0px -50px 0px" };
            const observer = new IntersectionObserver(function(entries, observer) {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !entry.target.classList.contains('hidden-card')) {
                        entry.target.classList.add('visible');
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.reveal-up, .reveal-left, .reveal-right').forEach(el => {
                observer.observe(el);
            });

            // Back To Top Button
            const backToTop = document.getElementById('backToTop');
            if(backToTop){
                window.addEventListener('scroll', function(){
                    if(window.scrollY > 400){
                        backToTop.classList.add('show');
                    }else{
                        backToTop.classList.remove('show');
                    }
                });

                backToTop.addEventListener('click', function(){
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                });
            }

        });
    </script>
</body>
</html>
<?php 
// End and flush output buffer properly
ob_end_flush(); 
?>
