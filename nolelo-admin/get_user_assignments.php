<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    sendJsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

if (isset($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
    
    try {
        global $pdo;
        
        // Get user categories
        $stmt = $pdo->prepare("SELECT category_id FROM user_categories WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get user sites
        $stmt = $pdo->prepare("SELECT site_id FROM user_sites WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $sites = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        sendJsonResponse([
            'success' => true,
            'categories' => $categories,
            'sites' => $sites
        ]);
    } catch (Exception $e) {
        sendJsonResponse([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    sendJsonResponse([
        'success' => false,
        'message' => 'User ID not provided'
    ]);
}
?>
