<?php
/**
 * API: Super Admin notifications (JSON). Used for real-time polling.
 * Requires Super Admin session.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'superadmin') {
    echo json_encode(['count' => 0, 'items' => []]);
    exit;
}

$config = require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/_notifications_super_admin.php';

$data = getSuperAdminNotifications($config);
echo json_encode($data);
