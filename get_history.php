<?php
// Database connection (same as before)
include 'db_connection.php';

if (isset($_GET['product_id'])) {
    $productId = (int)$_GET['product_id'];
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $daysRange = isset($_GET['days']) ? (int)$_GET['days'] : null;
    
    try {
        // Build query based on date parameters
        $query = "SELECT timestamp, title, price, stock 
                  FROM scrape_log 
                  WHERE product_id = ?";
        
        $params = [$productId];
        
        // Add date filtering
        if ($daysRange && $daysRange > 0) {
            $query .= " AND timestamp >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params[] = $daysRange;
        } elseif ($startDate && $endDate) {
            $query .= " AND timestamp BETWEEN ? AND ?";
            $params[] = $startDate . ' 00:00:00';
            $params[] = $endDate . ' 23:59:59';
        } elseif ($startDate) {
            $query .= " AND timestamp >= ?";
            $params[] = $startDate . ' 00:00:00';
        } elseif ($endDate) {
            $query .= " AND timestamp <= ?";
            $params[] = $endDate . ' 23:59:59';
        }
        
        $query .= " ORDER BY timestamp ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'price' => (int)$row['price'],
                'stock' => (int)$row['stock'],
                'title' => $row['title'],
                'timestamp' => $row['timestamp']
            ];
        }
        header('Content-Type: application/json');
        echo json_encode($history);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
}
