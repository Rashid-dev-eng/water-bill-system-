<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    sendJsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$payload = null;
$rawInput = file_get_contents('php://input');

if ($rawInput) {
    $decoded = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($decoded['bill']) && is_array($decoded['bill'])) {
        $payload = $decoded['bill'];
    }
}

if ($payload === null && isset($_POST['bill'])) {
    if (is_string($_POST['bill'])) {
        $decoded = json_decode($_POST['bill'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $payload = $decoded;
        }
    } elseif (is_array($_POST['bill'])) {
        $payload = $_POST['bill'];
    }
}

if (!$payload) {
    sendJsonResponse(['success' => false, 'message' => 'Bill data not provided.'], 400);
}

$_SESSION['bill_data'] = $payload;

sendJsonResponse(['success' => true, 'message' => 'Bill session saved.']);
