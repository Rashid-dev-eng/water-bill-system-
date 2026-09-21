<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/config.php';
if (!empty($_SESSION['user_id'])) {
    $logoutEvent = ($_GET['reason'] ?? '') === 'timeout' ? 'session_expired' : 'logout';
    $logoutDescription = $logoutEvent === 'session_expired' ? 'Worker was logged out because the session timed out.' : 'Worker logged out.';
    logWorkerActivity($_SESSION['user_id'], $logoutEvent, $logoutDescription);
}
session_destroy();
header('Location: login.php');
exit;
?>
