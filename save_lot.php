<?php
// save_lot.php
include 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $block_no = $_POST['block_no'] ?? '';
    $lot_no = $_POST['lot_no'] ?? '';
    $area = $_POST['area'] ?? 0;
    $status = strtoupper($_POST['status'] ?? 'AVAILABLE');
    $coordinates = trim($_POST['points'] ?? '');

    if ($id > 0) {
        // Update the database
        $stmt = $conn->prepare("UPDATE lots SET block_no = ?, lot_no = ?, area = ?, status = ?, coordinates = ? WHERE id = ?");
        $stmt->bind_param("ssdssi", $block_no, $lot_no, $area, $status, $coordinates, $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Property updated successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid Property ID.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>