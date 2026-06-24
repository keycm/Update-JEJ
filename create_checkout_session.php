<?php
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized request.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$res_id = isset($payload['reservation_id']) ? (int)$payload['reservation_id'] : 0;
$input_method = strtoupper(trim($payload['payment_method'] ?? ''));
$input_amount = isset($payload['amount']) ? (float)$payload['amount'] : 0;

$allowed_methods = ['GCASH', 'INSTAPAY', 'BANK TRANSFER'];
if ($res_id <= 0 || !in_array($input_method, $allowed_methods, true) || $input_amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payment details.']);
    exit;
}

$stmt = $conn->prepare(
    "SELECT r.id, r.status, r.email, r.contact_number,
            u.fullname,
            l.block_no, l.lot_no, l.total_price
     FROM reservations r
     JOIN users u ON u.id = r.user_id
     JOIN lots l ON l.id = r.lot_id
     WHERE r.id = ? AND r.user_id = ? LIMIT 1"
);
$stmt->bind_param('ii', $res_id, $user_id);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation || $reservation['status'] !== 'APPROVED') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Reservation is not approved or not found.']);
    exit;
}

$required_dp = round((float)$reservation['total_price'] * 0.20, 2);
if (abs($required_dp - $input_amount) > 0.009) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Amount mismatch. Please use exact required down payment.']);
    exit;
}

// PAYMONGO CONFIGURATION
// Replace this with your real secret key from PayMongo dashboard.
$paymongo_secret_key = 'sk_test_5GnEf2ZmmMerh7U2z9DDyLrF';

if ($paymongo_secret_key === '' || stripos($paymongo_secret_key, 'REPLACE_WITH_YOUR_KEY') !== false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Payment API is not configured yet. Add your PayMongo secret key in create_checkout_session.php.'
    ]);
    exit;
}

$method_map = [
    'GCASH' => 'gcash',
    'INSTAPAY' => 'dob',
    'BANK TRANSFER' => 'dob'
];
$api_method = $method_map[$input_method] ?? 'gcash';

$amount_centavos = (int)round($required_dp * 100);
$description = 'Down Payment for Lot (Block ' . $reservation['block_no'] . ' Lot ' . $reservation['lot_no'] . ') - Res#' . $res_id;

$host = (!empty($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url = $scheme . '://' . $host . '/lots_reservation';

$body = [
    'data' => [
        'attributes' => [
            'billing' => [
                'name' => (string)($reservation['fullname'] ?? 'Buyer'),
                'email' => (string)($reservation['email'] ?? ''),
                'phone' => (string)($reservation['contact_number'] ?? '')
            ],
            'send_email_receipt' => false,
            'show_description' => true,
            'show_line_items' => true,
            'description' => $description,
            'line_items' => [[
                'currency' => 'PHP',
                'amount' => $amount_centavos,
                'name' => 'Lot Down Payment',
                'quantity' => 1,
                'description' => $description
            ]],
            'payment_method_types' => [$api_method],
            'success_url' => $base_url . '/my_reservations.php?checkout=success&res_id=' . $res_id,
            'cancel_url' => $base_url . '/my_reservations.php?checkout=cancel&res_id=' . $res_id,
            'metadata' => [
                'reservation_id' => (string)$res_id,
                'user_id' => (string)$user_id,
                'payment_method' => $input_method,
                'required_dp' => number_format($required_dp, 2, '.', '')
            ]
        ]
    ]
];

$ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Basic ' . base64_encode($paymongo_secret_key . ':')
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($response === false || $curl_error) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Payment provider connection failed.']);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Invalid response from payment provider.']);
    exit;
}

$checkout_url = $data['data']['attributes']['checkout_url'] ?? '';
if ($http_code >= 200 && $http_code < 300 && $checkout_url !== '') {
    echo json_encode(['success' => true, 'checkout_url' => $checkout_url]);
    exit;
}

$error_message = 'Unable to create checkout session.';
if (!empty($data['errors'][0]['detail'])) {
    $error_message = (string)$data['errors'][0]['detail'];
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => $error_message]);
