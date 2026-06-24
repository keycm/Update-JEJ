<?php
require_once 'config.php';

$message = '';
$type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $email = trim($_POST['email']);

    $stmt = $conn->prepare("
        SELECT id, fullname
        FROM users
        WHERE email = ?
        LIMIT 1
    ");

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows > 0) {

        $user = $result->fetch_assoc();

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $update = $conn->prepare("
            UPDATE users
            SET reset_token = ?, reset_expires = ?
            WHERE id = ?
        ");

        $update->bind_param(
            "ssi",
            $token,
            $expires,
            $user['id']
        );

        $update->execute();

        $reset_link =
            "http://localhost/JEJ_V_6.4/reset_password.php?token="
            . $token;

        /*
        PHPMailer email here
        */

        $message =
            "Password reset link generated successfully.<br><br>
             <strong>Testing Link:</strong><br>
             <a href='$reset_link'>$reset_link</a>";

        $type = 'success';

    } else {

        $message = "Email address not found.";
        $type = 'error';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Forgot Password</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>

body{
    font-family:Inter,sans-serif;
    background:#f5f7f9;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
}

.card{
    width:420px;
    background:#fff;
    border-radius:16px;
    padding:35px;
    box-shadow:0 10px 30px rgba(0,0,0,.08);
}

h2{
    color:#1b5e20;
    margin-bottom:10px;
}

p{
    color:#607d8b;
    font-size:14px;
}

input{
    width:100%;
    padding:12px;
    border:1px solid #ddd;
    border-radius:10px;
    margin-top:15px;
    margin-bottom:15px;
    box-sizing:border-box;
}

button{
    width:100%;
    background:#2e7d32;
    color:#fff;
    border:none;
    padding:12px;
    border-radius:10px;
    font-weight:700;
    cursor:pointer;
}

button:hover{
    background:#1b5e20;
}

.alert-success{
    background:#e8f5e9;
    color:#2e7d32;
    padding:12px;
    border-radius:10px;
    margin-bottom:15px;
}

.alert-error{
    background:#ffebee;
    color:#c62828;
    padding:12px;
    border-radius:10px;
    margin-bottom:15px;
}

.back{
    text-align:center;
    margin-top:15px;
}

.back a{
    color:#2e7d32;
    text-decoration:none;
}

</style>
</head>
<body>

<div class="card">

    <h2>Forgot Password</h2>

    <p>
        Enter your email address and we'll send you a password reset link.
    </p>

    <?php if($message): ?>
        <div class="alert-<?= $type ?>">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <input
            type="email"
            name="email"
            placeholder="Enter Email Address"
            required
        >

        <button type="submit">
            Send Reset Link
        </button>

    </form>

    <div class="back">
        <a href="index.php">
            ← Back to Login
        </a>
    </div>

</div>

</body>
</html>