<?php
include_once dirname(__FILE__, 2) . '/DB_connection.php';

if (isset($_GET['order_id']) && isset($_GET['item_id']) && isset($_GET['user_id'])) {
    $orderId = $_GET['order_id'];
    $itemId = $_GET['item_id'];
    $userId = $_GET['user_id'];

    try {
        $sql = "SELECT COUNT(*) FROM tasks t
                JOIN task_assignments ta ON t.id = ta.task_id
                WHERE t.order_id = :order_id AND t.item_id = :item_id AND ta.user_id = :user_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':order_id', $orderId, PDO::PARAM_INT);
        $stmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $count = $stmt->fetchColumn();

        echo ($count > 0) ? 'true' : 'false';
    } catch (PDOException $e) {
        error_log("PDOException in check_user_tasks.php: " . $e->getMessage());
        echo 'true'; // Or 'false' depending on how you want to handle errors
    }
} else {
    echo 'false'; // Or 'true' depending on how you want to handle missing parameters
}
?>