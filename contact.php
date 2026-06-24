<?php
// 1. SAFE REDIRECTS: Start output buffering to prevent "Headers already sent" errors
ob_start(); 

// 2. SAFE SESSIONS: Only start a session if one hasn't been started yet
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Include your database connection
require_once 'config.php';

// Include PHPMailer files (required for sending OTP & Auto-reply)
require_once 'PHPMailer/Exception.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';

// --- CENTRALIZED AUTHENTICATION LOGIC (Same as index.php) ---
$auth_message = '';
$auth_status = ''; 
$show_modal = '';  

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Process Contact Form Submission
    if (isset($_POST['send_message'])) {
        $contact_name = trim($_POST['contact_name']);
        $contact_email = trim($_POST['contact_email']);
        $contact_subject = trim($_POST['contact_subject']);
        $contact_msg = trim($_POST['contact_message']);
        
        // 1. Insert Inquiry into Database
        $stmt = $conn->prepare("INSERT INTO inquiries (name, email, subject, message, status) VALUES (?, ?, ?, ?, 'UNREAD')");
        if ($stmt) {
            $stmt->bind_param("ssss", $contact_name, $contact_email, $contact_subject, $contact_msg);
            
            if ($stmt->execute()) {
                // 2. Send Auto-Reply Email to the User using PHPMailer
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

                    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                    $mail->addAddress($contact_email); // Send to the person who filled out the form
                    $mail->isHTML(true);
                    $mail->Subject = 'Inquiry Received: ' . $contact_subject;
                    
                    // Email Body
                    $mail->Body    = "<h3>Hello $contact_name,</h3>
                                      <p>Thank you for contacting JEJ Top Priority Corporation. We have successfully received your inquiry!</p>
                                      <p>Our experts will review your message and get back to you within 24 hours.</p>
                                      <hr>
                                      <p><b>Your Message Details:</b></p>
                                      <p><b>Subject:</b> $contact_subject</p>
                                      <p><b>Message:</b><br>" . nl2br(htmlspecialchars($contact_msg)) . "</p>
                                      <br><p>Best Regards,<br><b>JEJ Top Priority Corporation Team</b></p>";
                    
                    $mail->send();
                } catch (Exception $e) {
                    // Email failed, but inquiry is saved. We silently proceed.
                }

                $contact_success_msg = "Thank you, $contact_name! Your message has been sent successfully. Please check your email for confirmation.";
            } else {
                $contact_error_msg = "Database Error: Could not save your inquiry.";
            }
            $stmt->close();
        } else {
            $contact_error_msg = "Database Error: " . $conn->error;
        }
    }

    // STEP 1: Process Registration Request & Send OTP
    if (isset($_POST['register_request'])) {
        $fullname = trim($_POST['fullname']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if ($password !== $confirm_password) {
            $auth_message = "Passwords do not match.";
            $auth_status = "error";
            $show_modal = "registerModal";
        } else {
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
                    $otp = rand(100000, 999999);
                    $_SESSION['temp_reg'] = [
                        'fullname' => $fullname, 'phone' => $phone, 'email' => $email,
                        'password' => md5($password), 'otp' => $otp
                    ];

                    // Send OTP via Email
                    if (empty(SMTP_USER) || empty(SMTP_PASS) || SMTP_USER === 'CHANGE_THIS_TO_YOUR_GMAIL_ADDRESS' || SMTP_PASS === 'CHANGE_THIS_TO_YOUR_16_CHARACTER_GMAIL_APP_PASSWORD') {
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

    // STEP 2: Verify OTP
    elseif (isset($_POST['verify_otp'])) {
        $user_otp = trim($_POST['otp_code']);
        if (isset($_SESSION['temp_reg']) && $user_otp == $_SESSION['temp_reg']['otp']) {
            $data = $_SESSION['temp_reg'];
            $role = 'BUYER';
            $insert_stmt = $conn->prepare("INSERT INTO users (fullname, phone, email, password, role) VALUES (?, ?, ?, ?, ?)");
            if (!$insert_stmt) {
                $auth_message = "SQL Error: " . $conn->error;
                $auth_status = "error";
                $show_modal = "otpModal";
            } else {
                $insert_stmt->bind_param("sssss", $data['fullname'], $data['phone'], $data['email'], $data['password'], $role);
                if ($insert_stmt->execute()) {
                    unset($_SESSION['temp_reg']); 
                    $auth_message = "Registration successful! You can now log in.";
                    $auth_status = "success";
                    $show_modal = "loginModal";
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
    
    // STEP 3: Process Login Request
    elseif (isset($_POST['login'])) {
        $email = trim($_POST['email']);
        $password = $_POST['password'];

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
                if (md5($password) === $row['password']) {
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['fullname'] = $row['fullname'];
                    $_SESSION['role'] = $row['role'];
                    header("Location: contact.php");
                    exit;
                } else {
                    $auth_message = "Invalid password.";
                    $auth_status = "error";
                    $show_modal = "loginModal";
                }
            } else {
                $auth_message = "No account found with that email.";
                $auth_status = "error";
                $show_modal = "loginModal";
            }
            $stmt->close();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us | JEJ Top Priority Corporation</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { 
            --primary: #1e4b36; 
            --primary-light: #2d6a4f;
            --accent: #d4af37; 
            --bg-color: #f8fafc;
            --text-dark: #0f172a;
            --text-gray: #64748b;
            --white: #ffffff;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 20px 40px -10px rgba(0,0,0,0.12);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: var(--bg-color); 
            color: var(--text-dark); 
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* --- ANIMATIONS --- */
        @keyframes fadeInUp {
            0% { opacity: 0; transform: translateY(40px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            0% { opacity: 0; }
            100% { opacity: 1; }
        }
        
        .animate-on-scroll {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .animate-on-scroll.visible { opacity: 1; transform: translateY(0); }

        .hero-title { animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; }
        .hero-subtitle { animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.2s forwards; opacity: 0; }

        /* Navbar - Glassmorphism */
        .nav {
            position: fixed; top: 0; left: 0; right: 0;
            display: flex; justify-content: space-between; align-items: center;
            padding: 15px 5%; background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.3); box-shadow: var(--shadow-sm);
            z-index: 100; transition: all 0.3s ease;
        }

        .brand-wrapper a {
            display: flex; align-items: center; gap: 12px;
            text-decoration: none; color: var(--primary); transition: transform 0.3s ease;
        }
        .brand-wrapper a:hover { transform: scale(1.02); }
        .brand-logo-img { height: 48px; width: auto; object-fit: contain; }
        .brand-text-container { display: flex; flex-direction: column; justify-content: center; }
        .nav-brand { font-size: 1.3rem; font-weight: 800; letter-spacing: -0.5px; line-height: 1.1; color: var(--primary); }
        .nav-brand-sub { font-size: 0.65rem; color: var(--text-gray); font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; }
        
        .nav-links { display: flex; gap: 30px; }
        .nav-links a { text-decoration: none; color: var(--text-gray); font-weight: 600; font-size: 0.95rem; transition: color 0.2s; position: relative; }
        .nav-links a:hover, .nav-links a.active { color: var(--primary); }
        .nav-links a.active::after { content: ''; position: absolute; bottom: -5px; left: 0; width: 100%; height: 2px; background: var(--primary); border-radius: 2px; animation: fadeIn 0.3s ease; }

        .btn-outline { background: transparent; border: 2px solid var(--primary); color: var(--primary); padding: 10px 24px; border-radius: 30px; font-weight: 700; cursor: pointer; transition: all 0.3s ease; }
        .btn-outline:hover { background: var(--primary); color: white; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(30,75,54,0.15);}
        .btn-solid { background: var(--primary); color: white; border: none; padding: 10px 24px; border-radius: 30px; font-weight: 700; cursor: pointer; box-shadow: 0 4px 15px rgba(30, 75, 54, 0.2); transition: all 0.3s ease; }
        .btn-solid:hover { background: var(--primary-light); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(30, 75, 54, 0.3); }

        /* User Menu & Dropdown */
        .user-menu { display: flex; align-items: center; gap: 15px; }
        .notification-bell { position: relative; color: var(--text-gray); font-size: 22px; text-decoration: none; transition: color 0.3s; }
        .notification-bell:hover { color: var(--primary); transform: scale(1.1); }
        .notification-dot { position: absolute; top: 0; right: -2px; width: 10px; height: 10px; background-color: #ef4444; border-radius: 50%; border: 2px solid white; }
        
        .profile-dropdown-container { position: relative; }
        .profile-trigger { display: flex; align-items: center; gap: 12px; background: transparent; border: 1px solid #e2e8f0; cursor: pointer; padding: 6px 12px; border-radius: 40px; transition: all 0.2s ease; }
        .profile-trigger:hover { background: #f8fafc; border-color: #cbd5e1; box-shadow: var(--shadow-sm); }
        .profile-info { text-align: right; }
        .profile-name { display: block; font-weight: 700; font-size: 0.9rem; color: var(--text-dark); }
        .profile-role { display: block; font-size: 0.7rem; color: var(--primary); font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; }
        .avatar-circle { background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border-radius: 50%; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.1rem; box-shadow: 0 2px 8px rgba(30,75,54,0.3); }
        
        .profile-dropdown-menu { display: none; position: absolute; top: 120%; right: 0; background: white; min-width: 240px; border-radius: 16px; box-shadow: var(--shadow-lg); border: 1px solid #f1f5f9; overflow: hidden; z-index: 100; }
        .profile-dropdown-menu.active { display: block; animation: dropIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards; transform-origin: top right; }
        @keyframes dropIn { from { opacity: 0; transform: scale(0.9) translateY(-10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        
        .profile-dropdown-item { display: flex; align-items: center; gap: 12px; padding: 15px 20px; text-decoration: none; color: var(--text-dark); font-size: 0.95rem; font-weight: 600; transition: all 0.2s; }
        .profile-dropdown-item:hover { background: #f8fafc; color: var(--primary); padding-left: 25px; }
        .profile-dropdown-item.logout-btn { color: #ef4444; border-top: 1px solid #f1f5f9; }
        .profile-dropdown-item.logout-btn:hover { background: #fef2f2; color: #dc2626; }

        /* Hero Section (Contact specific) */
        .hero {
            margin-top: 76px; 
            height: 45vh; min-height: 380px;
            background: linear-gradient(to right, rgba(15, 23, 42, 0.85), rgba(30, 75, 54, 0.8)), url('assets/login2.JPG') center/cover no-repeat;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: white; text-align: center; padding: 0 20px;
        }
        .hero h1 { font-size: 3.5rem; font-weight: 800; margin-bottom: 15px; letter-spacing: -1px; text-shadow: 0 2px 15px rgba(0,0,0,0.4); }
        .hero p { font-size: 1.15rem; font-weight: 400; opacity: 0.9; max-width: 600px; text-shadow: 0 1px 5px rgba(0,0,0,0.3); }

        /* Contact Layout */
        .container { max-width: 1200px; margin: 0 auto 80px auto; padding: 0 5%; }
        
        .contact-wrapper {
            display: grid;
            grid-template-columns: 1fr 1.3fr;
            gap: 40px;
            background: white;
            border-radius: 24px;
            padding: 40px;
            box-shadow: var(--shadow-lg);
            margin-top: -60px; /* Overlaps the hero image */
            position: relative;
            z-index: 10;
        }

        /* Contact Info Side */
        .contact-info {
            background: #f8fafc;
            border-radius: 20px;
            padding: 40px 30px;
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
        }
        .contact-info h3 { font-size: 1.8rem; font-weight: 800; color: var(--text-dark); margin-bottom: 25px; }
        .info-item { display: flex; align-items: flex-start; gap: 15px; margin-bottom: 25px; }
        .info-icon { background: white; color: var(--primary); width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; box-shadow: var(--shadow-sm); flex-shrink: 0; }
        .info-text h4 { font-size: 1rem; font-weight: 700; color: var(--text-dark); margin-bottom: 5px; }
        .info-text p { font-size: 0.9rem; color: var(--text-gray); line-height: 1.5; }
        
        /* Functional Map Styling */
        .contact-map {
            margin-top: auto;
            border-radius: 16px;
            overflow: hidden;
            height: 280px; /* Slightly taller for usability */
            box-shadow: var(--shadow-sm);
            border: 2px solid white;
            background: #e2e8f0;
        }
        .contact-map iframe {
            width: 100%;
            height: 100%;
            border: 0;
            display: block;
        }

        /* Contact Form Side */
        .contact-form-area { padding: 20px 10px; }
        .contact-form-area h2 { font-size: 2.2rem; font-weight: 800; color: var(--text-dark); margin-bottom: 10px; letter-spacing: -0.5px; }
        .contact-form-area p { color: var(--text-gray); margin-bottom: 30px; font-size: 1rem; }
        
        .form-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .form-row > div { flex: 1; }
        .input-group { margin-bottom: 20px; }
        .input-label { display: block; font-size: 0.85rem; font-weight: 700; color: var(--text-dark); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        
        .styled-input {
            width: 100%; padding: 15px 20px; border: 2px solid #e2e8f0;
            border-radius: 12px; outline: none; background: #f8fafc;
            font-size: 1rem; color: var(--text-dark); font-family: inherit; font-weight: 500;
            transition: all 0.3s;
        }
        .styled-input:focus { border-color: var(--primary); background: white; box-shadow: 0 0 0 4px rgba(30,75,54,0.1); }
        textarea.styled-input { resize: vertical; min-height: 140px; }
        
        .btn-submit-contact {
            background: var(--primary); color: white; border: none;
            padding: 18px 30px; border-radius: 12px;
            font-size: 1.1rem; font-weight: 800; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            transition: all 0.3s; box-shadow: 0 4px 15px rgba(30,75,54,0.2); width: 100%;
        }
        .btn-submit-contact:hover { background: var(--primary-light); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(30,75,54,0.3); }

        /* Modals and Alerts */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); z-index: 1000; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; padding: 20px; }
        .modal-overlay.active { display: flex; opacity: 1; }
        .modal-content { background: white; display: flex; border-radius: 24px; width: 100%; max-width: 1000px; position: relative; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); transform: translateY(30px) scale(0.95); transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); overflow: hidden; min-height: 550px; }
        .modal-overlay.active .modal-content { transform: translateY(0) scale(1); }
        .modal-close { position: absolute; top: 20px; right: 25px; background: #f1f5f9; border: none; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; cursor: pointer; color: var(--text-gray); z-index: 1001; transition: all 0.2s; }
        .modal-close:hover { background: #e2e8f0; color: #ef4444; transform: rotate(90deg); }
        .modal-left { flex: 1; background: linear-gradient(to bottom, rgba(30,75,54,0.4), rgba(15,23,42,0.9)), url('assets/modal-bg.jpg') center/cover; padding: 50px; display: flex; flex-direction: column; justify-content: flex-end; color: white; }
        .modal-left h3 { font-size: 2rem; font-weight: 800; margin-bottom: 15px; line-height: 1.2; }
        .modal-left p { font-size: 1rem; opacity: 0.9; }
        .modal-right { flex: 1.2; padding: 60px 50px; display: flex; flex-direction: column; justify-content: center; background: white; }
        .modal-title { font-size: 2rem; font-weight: 800; color: var(--text-dark); margin-bottom: 8px; letter-spacing: -0.5px; }
        .modal-subtitle { color: var(--text-gray); font-size: 0.95rem; margin-bottom: 35px; }
        .modal-input { width: 100%; padding: 15px 20px; border: 2px solid #e2e8f0; border-radius: 12px; outline: none; background: #f8fafc; font-size: 1rem; color: var(--text-dark); font-family: inherit; font-weight: 500; transition: all 0.3s; margin-bottom: 15px;}
        .modal-input:focus { border-color: var(--primary); background: white; box-shadow: 0 0 0 4px rgba(30,75,54,0.1); }
        .btn-modal-submit { background: var(--primary); color: white; border: none; padding: 18px; width: 100%; border-radius: 12px; font-size: 1.1rem; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 10px; transition: all 0.3s; box-shadow: 0 4px 15px rgba(30,75,54,0.2); }
        .btn-modal-submit:hover { background: var(--primary-light); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(30,75,54,0.3); }
        .modal-footer-text { margin-top: 25px; text-align: center; font-size: 0.95rem; color: var(--text-gray); font-weight: 500; }
        .modal-footer-text a { color: var(--primary); font-weight: 700; text-decoration: none; transition: 0.2s; }
        .alert-box { padding: 15px 20px; border-radius: 10px; margin-bottom: 25px; font-size: 0.9rem; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .alert-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }

        .footer { background: var(--text-dark); color: white; text-align: center; padding: 30px; margin-top: 80px; font-size: 0.9rem; opacity: 0.9; }
        
        /* Mobile Responive */
        @media (max-width: 900px) {
            .contact-wrapper { grid-template-columns: 1fr; padding: 30px; }
            .hero h1 { font-size: 2.5rem; }
            .modal-content { flex-direction: column; max-height: 90vh; overflow-y: auto; }
            .modal-left { min-height: 200px; padding: 30px; }
            .modal-right { padding: 40px 30px; }
            .nav-links.desktop-only { display: none; }
            .brand-text-container { display: none; }
        }
    </style>
</head>
<body>

    <nav class="nav">
        <div class="brand-wrapper">
            <a href="index.php">
                <img src="assets/logo.png" alt="JEJ Top Priority Corporation Logo" class="brand-logo-img">
                <div class="brand-text-container">
                    <span class="nav-brand">JEJ Top Priority Corporation</span>
                    <span class="nav-brand-sub">Services & Real Estate</span>
                </div>
            </a>
        </div>
        
        <div class="nav-links desktop-only">
            <a href="index.php">Properties</a>
            <a href="contact.php" class="active">Contact Us</a>
        </div>

        <div class="user-menu">
            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="notifications.php" class="notification-bell">
                    <i class="fa-regular fa-bell"></i>
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
            <?php else: ?>
                <button class="btn-outline desktop-only" onclick="openModal('loginModal')">Sign In</button>
                <button class="btn-solid" onclick="openModal('registerModal')">Register Now</button>
            <?php endif; ?>
        </div>
    </nav>

    <header class="hero">
        <h1 class="hero-title">Get in Touch</h1>
        <p class="hero-subtitle">We are here to help you find your dream property and answer any questions you may have.</p>
    </header>

    <div class="container">
        <div class="contact-wrapper animate-on-scroll">
            
            <div class="contact-info">
                <h3>Contact Details</h3>
                
                <div class="info-item">
                    <div class="info-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <div class="info-text">
                        <h4>Head Office</h4>
                        <p>San Francisco<br>Nueva Ecija, Philippines</p>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon"><i class="fa-solid fa-phone"></i></div>
                    <div class="info-text">
                        <h4>Phone Line</h4>
                        <p>+63 912 345 6789<br>+63 2 8123 4567</p>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-icon"><i class="fa-solid fa-envelope"></i></div>
                    <div class="info-text">
                        <h4>Email Support</h4>
                        <p>inquiries@jejsurveying.com<br>support@jejsurveying.com</p>
                    </div>
                </div>

                <div class="contact-map">
                    <iframe 
                        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d241317.11609951334!2d120.9822002!3d14.5995124!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397ca03571ec38b%3A0x69d1d5751069c11f!2sManila%2C%20Metro%20Manila%2C%20Philippines!5e0!3m2!1sen!2sus!4v1700000000000!5m2!1sen!2sus" 
                        allowfullscreen="" 
                        loading="lazy" 
                        referrerpolicy="no-referrer-when-downgrade"
                        title="JEJ Top Priority Corporation Location">
                    </iframe>
                </div>
            </div>

            <div class="contact-form-area">
                <h2>Send us a Message</h2>
                <p>Fill out the form below and our real estate experts will get back to you within 24 hours.</p>

                <?php if(isset($contact_success_msg)): ?>
                    <div class="alert-box alert-success">
                        <i class="fa-solid fa-circle-check"></i>
                        <?= htmlspecialchars($contact_success_msg) ?>
                    </div>
                <?php elseif(isset($contact_error_msg)): ?>
                    <div class="alert-box alert-error">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?= htmlspecialchars($contact_error_msg) ?>
                    </div>
                <?php endif; ?>

                <form action="contact.php" method="POST">
                    <div class="form-row">
                        <div class="input-group">
                            <label class="input-label">Full Name</label>
                            <input type="text" name="contact_name" class="styled-input" placeholder="Juan Dela Cruz" required value="<?= isset($_SESSION['fullname']) ? htmlspecialchars($_SESSION['fullname']) : '' ?>">
                        </div>
                        <div class="input-group">
                            <label class="input-label">Email Address</label>
                            <input type="email" name="contact_email" class="styled-input" placeholder="juan@example.com" required>
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <label class="input-label">Subject</label>
                        <input type="text" name="contact_subject" class="styled-input" placeholder="Inquiry about block & lot..." required>
                    </div>

                    <div class="input-group">
                        <label class="input-label">Message</label>
                        <textarea name="contact_message" class="styled-input" placeholder="How can we help you today?" required></textarea>
                    </div>

                    <button type="submit" name="send_message" class="btn-submit-contact">
                        Send Message <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>


    <div class="modal-overlay" id="loginModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('loginModal')"><i class="fa-solid fa-xmark"></i></button>
            <div class="modal-left">
                <h3>Welcome Back</h3>
                <p>Log in to manage your reservations, track payments, and explore new properties.</p>
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
                
                <form action="contact.php" method="POST">
                    <div>
                        <label style="display:block; margin-bottom:5px; font-weight:bold; font-size:0.9rem;">Email Address</label>
                        <input type="email" name="email" class="modal-input" placeholder="Enter your email" required>
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-weight:bold; font-size:0.9rem; margin-top:10px;">Password</label>
                        <input type="password" name="password" class="modal-input" placeholder="Enter your password" required>
                    </div>
                    <button type="submit" name="login" class="btn-modal-submit">Sign In <i class="fa-solid fa-arrow-right"></i></button>
                </form>
                
                <div class="modal-footer-text">
                    New to JEJ Top Priority Corporation? <a href="#" onclick="event.preventDefault(); switchModal('loginModal', 'registerModal')">Create an account</a>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="registerModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('registerModal')"><i class="fa-solid fa-xmark"></i></button>
            <div class="modal-left">
                <h3>Start Your Journey</h3>
                <p>Create an account today to reserve your dream property and secure your future.</p>
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
                
                <form action="contact.php" method="POST" onsubmit="return validatePassword()">
                    <div style="display:flex; gap:15px;">
                        <div style="flex:1;">
                            <label style="display:block; font-weight:bold; font-size:0.85rem; margin-bottom:5px;">Full Name</label>
                            <input type="text" name="fullname" class="modal-input" placeholder="John Doe" required>
                        </div>
                        <div style="flex:1;">
                            <label style="display:block; font-weight:bold; font-size:0.85rem; margin-bottom:5px;">Phone</label>
                            <input type="text" name="phone" class="modal-input" placeholder="09XX XXX XXXX" required>
                        </div>
                    </div>
                    <div style="margin-top:5px;">
                        <label style="display:block; font-weight:bold; font-size:0.85rem; margin-bottom:5px;">Email Address</label>
                        <input type="email" name="email" class="modal-input" placeholder="john@example.com" required>
                    </div>
                    <div style="display:flex; gap:15px; margin-top:5px;">
                        <div style="flex:1;">
                            <label style="display:block; font-weight:bold; font-size:0.85rem; margin-bottom:5px;">Password</label>
                            <input type="password" name="password" id="reg_pass" class="modal-input" placeholder="••••••••" required>
                        </div>
                        <div style="flex:1;">
                            <label style="display:block; font-weight:bold; font-size:0.85rem; margin-bottom:5px;">Confirm</label>
                            <input type="password" name="confirm_password" id="reg_confirm" class="modal-input" placeholder="••••••••" required>
                        </div>
                    </div>
                    <button type="submit" name="register_request" class="btn-modal-submit" style="margin-top:15px;">Verify Email <i class="fa-solid fa-envelope-circle-check"></i></button>
                </form>
                
                <div class="modal-footer-text">
                    Already have an account? <a href="#" onclick="event.preventDefault(); switchModal('registerModal', 'loginModal')">Sign in here</a>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="otpModal">
        <div class="modal-content" style="max-width: 550px; min-height: 400px;">
            <button class="modal-close" onclick="closeModal('otpModal')"><i class="fa-solid fa-xmark"></i></button>
            <div class="modal-right" style="padding: 50px;">
                <div style="text-align: center; margin-bottom: 25px;">
                    <i class="fa-solid fa-shield-halved" style="font-size: 3rem; color: var(--primary); margin-bottom: 15px;"></i>
                    <h2 class="modal-title" style="text-align: center;">Verify Email</h2>
                    <p class="modal-subtitle" style="text-align: center;">Enter the 6-digit code sent to your email.</p>
                </div>
                
                <?php if ($show_modal == 'otpModal' && !empty($auth_message)): ?>
                    <div class="alert-box alert-<?= $auth_status ?>">
                        <i class="fa-solid <?= $auth_status == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                        <?= htmlspecialchars($auth_message) ?>
                    </div>
                <?php endif; ?>
                
                <form action="contact.php" method="POST">
                    <div>
                        <input type="text" name="otp_code" class="modal-input" placeholder="000000" maxlength="6" style="text-align: center; letter-spacing: 10px; font-size: 28px; padding: 20px; font-weight: 800; color: var(--primary);" required autocomplete="off">
                    </div>
                    <button type="submit" name="verify_otp" class="btn-modal-submit" style="margin-top:15px;">Complete Registration <i class="fa-solid fa-check"></i></button>
                </form>
                
                <div class="modal-footer-text">
                    Didn't receive the code? <a href="#" onclick="event.preventDefault(); switchModal('otpModal', 'registerModal')">Start over</a>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>© <?= date('Y') ?> JEJ Top Priority Corporation. All Rights Reserved. Built with trust and excellence.</p>
    </footer>

    <script>
        function validatePassword() {
            var pass = document.getElementById('reg_pass').value;
            var confirm = document.getElementById('reg_confirm').value;
            if(pass !== confirm) {
                alert("Passwords do not match. Please try again.");
                return false;
            }
            return true;
        }

        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        function switchModal(closeId, openId) {
            document.getElementById(closeId).classList.remove('active');
            setTimeout(() => { document.getElementById(openId).classList.add('active'); }, 300);
        }

        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });

        document.addEventListener("DOMContentLoaded", function() {
            <?php if (!empty($show_modal)): ?>
                openModal('<?= htmlspecialchars($show_modal) ?>');
            <?php endif; ?>

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