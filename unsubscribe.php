<?php
require_once __DIR__ . '/auth.php';
requireAuth();

require_once __DIR__ . '/db_connection.php';

$content = trim(file_get_contents("php://input"));
$decoded = json_decode($content, true);

if (isset($decoded['endpoint'])) {
    $endpoint = $decoded['endpoint'];

    try {
        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
        $stmt->execute([$endpoint]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'not_found']);
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
