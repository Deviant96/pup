<?php
require 'db_connection.php';

$content = trim(file_get_contents("php://input"));
$decoded = json_decode($content, true);

if (isset($decoded['endpoint'])) {
    $endpoint = $decoded['endpoint'];
    $p256dh = $decoded['keys']['p256dh'];
    $auth = $decoded['keys']['auth'];

    try {
        // Check if subscription already exists
        $stmt = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ?");
        $stmt->execute([$endpoint]);
        
        if ($stmt->rowCount() == 0) {
            $stmt = $pdo->prepare("INSERT INTO push_subscriptions (endpoint, p256dh, auth) VALUES (?, ?, ?)");
            $stmt->execute([$endpoint, $p256dh, $auth]);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'exists']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
}
?>
