<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied');
}

$type = jej_clean_storage_type($_GET['type'] ?? '');
$file = jej_safe_filename($_GET['file'] ?? '');

$allowed = ['DOCS', 'contracts', 'selfies', 'valid_ids', 'payment_proofs', 'reservation_proofs', 'receipts', 'profiles', 'sample', 'maps', 'lots image', 'lots_image', 'lot_images'];
if (!in_array($type, $allowed, true) || $file === '') {
    http_response_code(400);
    exit('Invalid file request');
}

$path = '';
foreach (jej_storage_candidate_bases() as $base) {
    $candidate = $base . $type . '/' . $file;
    if (is_file($candidate)) {
        $path = $candidate;
        break;
    }

    // Backward-compatible root storage fallback for migrated files/maps copied
    // directly into storage/uploads/ instead of storage/uploads/{type}/.
    $rootCandidate = $base . $file;
    if (is_file($rootCandidate)) {
        $path = $rootCandidate;
        break;
    }
}

// Backward-compatible fallback for older records/files.
if ($path === '') {
    $fallbacks = [
        __DIR__ . '/uploads/' . $type . '/' . $file,
        __DIR__ . '/storage/uploads/' . $type . '/' . $file,
        __DIR__ . '/uploads/' . $file,
        __DIR__ . '/storage/uploads/' . $file,
    ];
    foreach ($fallbacks as $candidate) {
        $candidate = str_replace('\\', '/', $candidate);
        if (is_file($candidate)) {
            $path = $candidate;
            break;
        }
    }
}

if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit('File not found');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime_map = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp'
];
$content_type = $mime_map[$ext] ?? 'application/octet-stream';

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $content_type);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
readfile($path);
exit;
