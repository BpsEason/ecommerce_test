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
    // 錯誤訊息只在開發環境顯示，生產環境顯示通用錯誤
    $error = getenv('APP_ENV') === 'development' ? $e->getMessage() : '伺服器錯誤';
    http_response_code(500);
    echo json_encode(['error' => $error]);
    // 將錯誤寫入日誌
    file_put_contents('error.log', date('Y-m-d H:i:s') . ': ' . $error . PHP_EOL, FILE_APPEND);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = trim($_SERVER['PATH_INFO'] ?? '', '/');

// 訂單相關 API
if (preg_match('#^api/orders/?$#', $path)) {
    if ($method === 'GET') {
        // 設定每頁顯示數量，可從 GET 參數取得並驗證
        $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $limit;

        // 取得總訂單數
        $totalStmt = $pdo->query("SELECT COUNT(*) FROM orders");
        $total = $totalStmt->fetchColumn();
        $totalPages = ceil($total / $limit);

        // 驗證頁碼：如果請求頁碼大於總頁數且有資料存在，則返回 400 錯誤
        if ($total > 0 && $page > $totalPages) {
            http_response_code(400);
            echo json_encode(['error' => '請求的頁碼超出總頁數']);
            exit;
        }

        // 取得訂單資料
        $stmt = $pdo->prepare("SELECT id, user_id, number, status, total_amount, created_at FROM orders ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $response = [
            'data' => $stmt->fetchAll(),
            'page' => $page,
            'total_pages' => (int)$totalPages, // 確保為整數
            'total_items' => (int)$total,     // 確保為整數
            'items_per_page' => $limit
        ];
        echo json_encode($response);
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // 輸入數據基本驗證
        if (!isset($data['user_id']) || !is_int($data['user_id']) || !isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            http_response_code(400);
            echo json_encode(['error' => '無效的輸入數據或訂單項目為空']);
            exit;
        }

        $pdo->beginTransaction(); // 開始事務
        try {
            $userId = $data['user_id'];
            $orderNumber = 'ORD' . date('YmdHis') . mt_rand(100, 999);
            $createdAt = date('Y-m-d H:i:s');

            // 插入訂單主表
            $stmt = $pdo->prepare("INSERT INTO orders (user_id, number, status, created_at, updated_at) VALUES (?, ?, 'pending', ?, ?)");
            $stmt->execute([$userId, $orderNumber, $createdAt, $createdAt]);
            $orderId = $pdo->lastInsertId();

            $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
            $updateStockStmt = $pdo->prepare("UPDATE products SET stock = stock - ?, updated_at = ? WHERE id = ? AND stock >= ?");
            $totalAmount = 0;

            foreach ($data['items'] as $item) {
                // 檢查項目數據結構
                if (!isset($item['product_id']) || !is_int($item['product_id']) || !isset($item['quantity']) || !is_int($item['quantity']) || $item['quantity'] <= 0) {
                    throw new Exception('訂單項目數據無效或數量不正確');
                }

                $productId = $item['product_id'];
                $quantity = $item['quantity'];

                // 鎖定產品庫存並獲取價格 (FOR UPDATE 確保多個併發訂單時庫存的準確性)
                $stockStmt = $pdo->prepare("SELECT stock, price FROM products WHERE id = ? LIMIT 1 FOR UPDATE");
                $stockStmt->execute([$productId]);
                $product = $stockStmt->fetch();

                if (!$product || $product['stock'] < $quantity) {
                    throw new Exception('庫存不足或產品不存在，產品ID: ' . $productId);
                }

                $unitPrice = $product['price'];
                $subtotal = $unitPrice * $quantity;
                $totalAmount += $subtotal;

                // 更新產品庫存
                $updateStockStmt->execute([$quantity, $createdAt, $productId, $quantity]);
                if ($updateStockStmt->rowCount() === 0) {
                    // 如果 rowCount 為 0，表示更新失敗，可能原因：庫存不足或其他並發問題
                    throw new Exception('庫存更新失敗，產品ID: ' . $productId . ' (可能已不足)');
                }

                // 插入訂單項目
                $itemStmt->execute([$orderId, $productId, $quantity, $unitPrice, $subtotal]);
            }

            // 更新訂單總金額
            $updateStmt = $pdo->prepare("UPDATE orders SET total_amount = ? WHERE id = ?");
            $updateStmt->execute([$totalAmount, $orderId]);

            $pdo->commit(); // 提交事務
            http_response_code(201); // 返回 201 Created
            echo json_encode(['order_id' => (int)$orderId, 'order_number' => $orderNumber, 'total_amount' => (float)$totalAmount]);
        } catch (Exception $e) {
            $pdo->rollBack(); // 回滾事務
            http_response_code(400);
            $errorMessage = $e->getMessage();
            echo json_encode(['error' => $errorMessage]);
            file_put_contents('error.log', date('Y-m-d H:i:s') . ': ' . $errorMessage . PHP_EOL, FILE_APPEND);
        }
    } else {
        http_response_code(405); // Method Not Allowed
        echo json_encode(['error' => '不允許的方法']);
    }
} elseif (preg_match('#^api/orders/(\d+)$#', $path, $matches)) {
    $orderId = (int)$matches[1];
    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT id, user_id, number, status, total_amount, created_at, updated_at FROM orders WHERE id = ?");
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
        // 驗證狀態值
        $validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
        if (!isset($data['status']) || !in_array($data['status'], $validStatuses, true)) {
            http_response_code(400);
            echo json_encode(['error' => '無效的狀態值']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([$data['status'], date('Y-m-d H:i:s'), $orderId]);
        
        // 檢查是否有更新到資料 (影響的行數是否大於 0)
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => '訂單狀態更新成功']);
        } else {
            http_response_code(404); // 如果沒有更新到行，可能是訂單ID不存在
            echo json_encode(['success' => false, 'error' => '訂單不存在或狀態無變化']);
        }
    } elseif ($method === 'DELETE') {
        // 通常訂單不建議真刪除，而是更新狀態為 'cancelled' 或 'deleted'
        // 這裡提供一個物理刪除的範例，但實際應用中應謹慎
        $pdo->beginTransaction();
        try {
            // 先刪除訂單項目
            $stmtItems = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
            $stmtItems->execute([$orderId]);

            // 再刪除訂單
            $stmtOrder = $pdo->prepare("DELETE FROM orders WHERE id = ?");
            $stmtOrder->execute([$orderId]);

            if ($stmtOrder->rowCount() > 0) {
                $pdo->commit();
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => '訂單已成功刪除']);
            } else {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => '訂單不存在或無法刪除']);
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => '刪除訂單時發生錯誤: ' . $e->getMessage()]);
            file_put_contents('error.log', date('Y-m-d H:i:s') . ': ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => '不允許的方法']);
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
    } else {
        http_response_code(405);
        echo json_encode(['error' => '不允許的方法']);
    }
} elseif ($path === 'api/products') {
    if ($method === 'GET') {
        // 設定每頁顯示數量，可從 GET 參數取得並驗證
        $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $offset = ($page - 1) * $limit;

        // 取得總產品數 (未刪除的)
        $totalStmt = $pdo->query("SELECT COUNT(*) FROM products WHERE is_deleted = FALSE");
        $total = $totalStmt->fetchColumn();
        $totalPages = ceil($total / $limit);

        // 驗證頁碼：如果請求頁碼大於總頁數且有資料存在，則返回 400 錯誤
        if ($total > 0 && $page > $totalPages) {
            http_response_code(400);
            echo json_encode(['error' => '請求的頁碼超出總頁數']);
            exit;
        }

        // 取得產品資料
        $stmt = $pdo->prepare("SELECT id, name, price, stock FROM products WHERE is_deleted = FALSE ORDER BY id ASC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $response = [
            'data' => $stmt->fetchAll(),
            'page' => $page,
            'total_pages' => (int)$totalPages, // 確保為整數
            'total_items' => (int)$total,     // 確保為整數
            'items_per_page' => $limit
        ];
        echo json_encode($response);
    } else {
        http_response_code(405);
        echo json_encode(['error' => '不允許的方法']);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'API 路徑不存在']);
}

unset($pdo); // 釋放資料庫連線資源
?>
