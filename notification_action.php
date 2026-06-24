<?php
require_once 'config.php';

checkLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
if($userId <= 0){
    header('Location: index.php');
    exit();
}

function jej_notification_safe_redirect($url): string {
    $url = trim((string)$url);
    if($url === '') return 'notifications.php';
    if(preg_match('/^(https?:)?\/\//i', $url)) return 'notifications.php';
    if(strpos($url, "\n") !== false || strpos($url, "\r") !== false) return 'notifications.php';
    return $url;
}

function jej_notification_infer_target($title, $message): string {
    $text = strtolower((string)$title . ' ' . (string)$message);
    if(strpos($text, 'cancellation') !== false || strpos($text, 'cancel') !== false) return 'reservation.php?status=CANCELLATION_REQUESTS';
    if(strpos($text, 'payment') !== false || strpos($text, 'receipt') !== false || strpos($text, 'proof') !== false) return 'verify_payments.php';
    if(strpos($text, 'inquiry') !== false || strpos($text, 'message') !== false || strpos($text, 'contact') !== false) return 'inquiries.php';
    if(strpos($text, 'contract') !== false) return 'contract_status.php';
    if(strpos($text, 'buyer') !== false) return 'buyers.php';
    if(strpos($text, 'reservation') !== false || strpos($text, 'id verification') !== false || strpos($text, 'id ') !== false) return 'reservation.php?status=ACTION_NEEDED';
    return 'notifications.php';
}

function jej_notification_has_column(mysqli $conn, string $column): bool {
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    if($column === '') return false;
    $escaped = $conn->real_escape_string($column);
    $check = $conn->query("SHOW COLUMNS FROM notifications LIKE '{$escaped}'");
    return ($check && $check->num_rows > 0);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if($action === 'mark_all_read'){
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    if($stmt){
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }

    $back = $_SERVER['HTTP_REFERER'] ?? 'notifications.php';
    header('Location: ' . jej_notification_safe_redirect($back));
    exit();
}

if($action === 'open'){
    $notificationId = (int)($_GET['id'] ?? 0);
    $redirect = jej_notification_safe_redirect($_GET['redirect'] ?? '');

    if($notificationId > 0){
        $targetColumnSql = jej_notification_has_column($conn, 'target_url') ? ', target_url' : '';
        $stmt = $conn->prepare("SELECT title, message{$targetColumnSql} FROM notifications WHERE id = ? AND user_id = ? LIMIT 1");
        if($stmt){
            $stmt->bind_param("ii", $notificationId, $userId);
            $stmt->execute();
            $notification = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if($notification){
                $mark = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
                if($mark){
                    $mark->bind_param("ii", $notificationId, $userId);
                    $mark->execute();
                    $mark->close();
                }

                if(!empty($notification['target_url'])){
                    $redirect = jej_notification_safe_redirect($notification['target_url']);
                } elseif($redirect === 'notifications.php'){
                    $redirect = jej_notification_infer_target($notification['title'] ?? '', $notification['message'] ?? '');
                }
            }
        }
    }

    header('Location: ' . jej_notification_safe_redirect($redirect));
    exit();
}

header('Location: notifications.php');
exit();
