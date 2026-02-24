<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
$sidebar_active = 'users';
$config = require dirname(__DIR__) . '/config.php';
$userId = trim($_GET['id'] ?? '');
if ($userId === '') {
    header('Location: users.php');
    exit;
}
require_once __DIR__ . '/_account_helpers.php';
$welcomeUsername = getUserUsername($_SESSION['user_id'] ?? '') ?: ($_SESSION['user_username'] ?? $_SESSION['user_name'] ?? 'User');

$user = null;
try {
    $manager = new MongoDB\Driver\Manager($config['uri']);
    $namespace = $config['database'] . '.users';
    $filter = ['_id' => new MongoDB\BSON\ObjectId($userId)];
    $query = new MongoDB\Driver\Query($filter);
    $cursor = $manager->executeQuery($namespace, $query);
    $users = $cursor->toArray();
    if (count($users) > 0) {
        $user = (array)$users[0];
        $user['_id'] = (string)$user['_id'];
    }
} catch (Exception $e) {
    $user = null;
}
if (!$user) {
    header('Location: users.php?msg=' . urlencode('User not found') . '&ok=0');
    exit;
}
$displayName = trim($user['name'] ?? '') ?: (trim($user['username'] ?? '') ?: trim($user['email'] ?? 'Unknown'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User – DMS LGU</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="sidebar_super_admin.css">
    <link rel="stylesheet" href="../Admin Side/admin-dashboard.css">
    <style>
        body { margin: 0; background: #f8fafc; }
        .main-content { padding: 1.5rem 2rem; }
        .edit-user-card { background: #fff; border-radius: 12px; padding: 1.5rem 2rem; max-width: 560px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
        .edit-user-card h2 { margin: 0 0 1rem 0; font-size: 1.25rem; color: #1e293b; }
        .edit-user-card p { color: #64748b; margin-bottom: 1rem; font-size: 0.95rem; }
        .edit-user-card a { display: inline-block; margin-top: 0.5rem; color: #2563eb; text-decoration: none; font-weight: 500; }
        .edit-user-card a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/_sidebar_super_admin.php'; ?>
        <div class="main-content">
            <div class="edit-user-card">
                <h2>Edit user: <?= htmlspecialchars($displayName) ?></h2>
                <p><strong>Email:</strong> <?= htmlspecialchars($user['email'] ?? '—') ?></p>
                <p><strong>Role:</strong> <?= htmlspecialchars(ucfirst($user['role'] ?? '—')) ?></p>
                <p>Edit form can be added here. For now, manage users from the <a href="users.php">Users</a> page.</p>
                <a href="users.php">&larr; Back to Users</a>
            </div>
        </div>
    </div>
    <script src="sidebar_super_admin.js"></script>
</body>
</html>
