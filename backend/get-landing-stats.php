<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit();
}

try {
    require_once __DIR__ . '/db.php';

    $categoryCountStmt = dbPrepare($conn, 'SELECT COUNT(*) AS total_categories FROM categories_tbl');
    $categoryCountStmt->execute();
    $categoryCount = intval(($categoryCountStmt->fetch(PDO::FETCH_ASSOC)['total_categories'] ?? 0));

    $completedOrdersStmt = dbPrepare(
        $conn,
        "SELECT COUNT(*) AS completed_orders FROM orders_tbl WHERE order_status_fld = 'completed'"
    );
    $completedOrdersStmt->execute();
    $completedOrders = intval(($completedOrdersStmt->fetch(PDO::FETCH_ASSOC)['completed_orders'] ?? 0));

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'stats' => [
            'category_count' => $categoryCount,
            'completed_orders' => $completedOrders,
        ],
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load landing stats',
        'error' => $exception->getMessage(),
    ]);
}
