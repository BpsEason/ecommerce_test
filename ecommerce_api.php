<?php
declare(strict_types=1); // 啟用嚴格類型檢查
date_default_timezone_set('Asia/Taipei');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // 需根據環境調整 (生產環境設具體域名)

// 資料庫連線設定 (建議使用環境變數)
$host = 'localhost';
$dbname = 'ecommerce_test';
$username = 'root';
$password = ''; // 建議用 $_ENV['DB_PASSWORD']

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, false);
} catch (PDOException $e) {
    $error = getenv('APP_ENV') === 'development' ? $e->getMessage() : '伺服器錯誤';
    http_response_code(500);
    echo json_encode(['error' => $error]);
    file_put_contents('error.log', date('Y-m-d H:i:s') . ': ' . $error . PHP_EOL, FILE_APPEND);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = trim($_SERVER['PATH_INFO'] ?? '', '/');

// 訂單相關 API
if (preg_match('#^api/orders/?$#', $path)) {
    if ($method === 'GET') {
        $limit = 20;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $limit;
        $totalStmt = $pdo->query("SELECT COUNT(*) FROM orders");
        $total = $totalStmt->fetchColumn();
        $totalPages = ceil($total / $limit);

        // Validate page number
        if ($page > $totalPages && $total > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Requested page exceeds total pages']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, user_id, number, status, total_amount, created_at FROM orders ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $response = [
            'data' => $stmt->fetchAll(),
            'page' => $page,
            'total_pages' => $totalPages,
            'total_items' => $total
        ];
        echo json_encode($response);
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['user_id']) || !is_int($data['user_id']) || !isset($data['items']) || !is_array($data['items'])) {
            http_response_code(400);
            echo json_encode(['error' => '無效的輸入數據']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $userId = $data['user_id'];
            $orderNumber = 'ORD' . date('YmdHis') . mt_rand(100, 999);
            $createdAt = date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("INSERT INTO orders (user_id, number, status, created_at, updated_at) VALUES (?, ?, 'pending', ?, ?)");
            $stmt->execute([$userId, $orderNumber, $createdAt, $createdAt]);
            $orderId = $pdo->lastInsertId();

            $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
            $updateStockStmt = $pdo->prepare("UPDATE products SET stock = stock - ?, updated_at = ? WHERE id = ? AND stock >= ?");
            $totalAmount = 0;
            foreach ($data['items'] as $item) {
                $productId = $item['product_id'];
                $quantity = $item['quantity'];

                $stockStmt = $pdo->prepare("SELECT stock, price FROM products WHERE id = ? LIMIT 1 FOR UPDATE");
                $stockStmt->execute([$productId]);
                $product = $stockStmt->fetch();
                if (!$product || $product['stock'] < $quantity) {
                    throw new Exception(['error' => '庫存不足', 'product_id' => $productId]);
                }

                $unitPrice = $product['price'];
                $subtotal = $unitPrice * $quantity;
                $totalAmount += $subtotal;

                $updateStockStmt->execute([$quantity, $createdAt, $productId, $quantity]);
                if ($updateStockStmt->rowCount() === 0) {
                    throw new Exception(['error' => '庫存更新失敗', 'product_id' => $productId]);
                }

                $itemStmt->execute([$orderId, $productId, $quantity, $unitPrice, $subtotal]);
            }

            $updateStmt = $pdo->prepare("UPDATE orders SET total_amount = ? WHERE id = ?");
            $updateStmt->execute([$totalAmount, $orderId]);

            $pdo->commit();
            echo json_encode(['order_id' => $orderId, 'order_number' => $orderNumber]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(400);
            $error = is_array($e->getMessage()) ? $e->getMessage() : ['error' => $e->getMessage()];
            echo json_encode($error);
            file_put_contents('error.log', date('Y-m-d H:i:s') . ': ' . json_encode($error) . PHP_EOL, FILE_APPEND);
        }
    }
} elseif (preg_match('#^api/orders/(\d+)$#', $path, $matches)) {
    $orderId = $matches[1];
    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order) {
            echo json_encode($order);
        } else {
            http_response_code(404);
            echo json_encode(['error' => '訂單不存在']);
        }
    } elseif ($method === 'PUT') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['status']) || !in_array($data['status'], ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
            http_response_code(400);
            echo json_encode(['error' => '無效的狀態值']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([$data['status'], date('Y-m-d H:i:s'), $orderId]);
        echo json_encode(['success' => true]);
    }
} elseif ($path === 'api/orders/stats') {
    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT 
            (SELECT COUNT(*) FROM orders) as total_orders,
            (SELECT COALESCE(SUM(total_amount), 0) FROM orders) as total_amount,
            (SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()) as today_orders,
            (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE DATE(created_at) = CURDATE()) as today_amount");
        $stats = $stmt->fetch();
        echo json_encode([
            'total_orders' => (int)$stats['total_orders'],
            'total_amount' => (float)$stats['total_amount'],
            'today_orders' => (int)$stats['today_orders'],
            'today_amount' => (float)$stats['today_amount']
        ]);
    }
} elseif ($path === 'api/products') {
    if ($method === 'GET') {
        $limit = 50;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $limit;
        $totalStmt = $pdo->query("SELECT COUNT(*) FROM products WHERE is_deleted = FALSE");
        $total = $totalStmt->fetchColumn();
        $totalPages = ceil($total / $limit);

        // Validate page number
        if ($page > $totalPages && $total > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Requested page exceeds total pages']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, name, price, stock FROM products WHERE is_deleted = FALSE ORDER BY id ASC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $response = [
            'data' => $stmt->fetchAll(),
            'page' => $page,
            'total_pages' => $totalPages,
            'total_items' => $total
        ];
        echo json_encode($response);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'API 路徑不存在']);
}

unset($pdo); // 釋放資源
?>
