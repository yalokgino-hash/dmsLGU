<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/_account_helpers.php';

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'Super Admin';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'users';
$welcomeUsername = getUserUsername($_SESSION['user_id'] ?? '') ?: ($_SESSION['user_username'] ?? $userName) ?: 'User';

if (!isset($config)) {
    $config = require dirname(__DIR__) . '/config.php';
}
require_once __DIR__ . '/_notifications_super_admin.php';
require_once __DIR__ . '/_activity_logger.php';
$notifData = getSuperAdminNotifications($config);
$notifCount = $notifData['count'];
$notifItems = $notifData['items'];

/**
 * Get users list.
 * @return array
 */
function getUsersList($config, $search = '') {
    $namespace = $config['database'] . '.users';
    $filter = [];
    if ($search !== '') {
        $filter['$or'] = [
            ['username' => new MongoDB\BSON\Regex(preg_quote($search, '/'), 'i')],
            ['name' => new MongoDB\BSON\Regex(preg_quote($search, '/'), 'i')],
            ['email' => new MongoDB\BSON\Regex(preg_quote($search, '/'), 'i')],
        ];
    }
    if (empty($filter)) {
        $filter = (object)[];
    }
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query($filter, ['sort' => ['username' => 1]]);
        $cursor = $manager->executeQuery($namespace, $query);
        $rows = [];
        foreach ($cursor as $doc) {
            $arr = (array)$doc;
            $arr['_id'] = (string)$arr['_id'];
            $rows[] = $arr;
        }
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Add a new user to the database.
 * @return array ['success' => bool, 'message' => string]
 */
function addUser($config, $username, $name, $email, $password, $role) {
    $username = trim($username);
    $name = trim($name);
    $email = trim($email);
    if ($username === '' || $email === '') {
        return ['success' => false, 'message' => 'Username and email are required.'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
    }
    $allowedRoles = ['superadmin', 'admin', 'user', 'staff', 'departmenthead', 'department_head', 'dept_head'];
    $role = strtolower(trim($role));
    if (!in_array($role, $allowedRoles)) {
        $role = 'user';
    }
    $namespace = $config['database'] . '.users';
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query(['$or' => [['username' => $username], ['email' => $email]]]);
        $cursor = $manager->executeQuery($namespace, $query);
        if (count($cursor->toArray()) > 0) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }
        $doc = [
            'username'   => $username,
            'name'      => $name,
            'email'     => $email,
            'password'  => password_hash($password, PASSWORD_DEFAULT),
            'role'      => $role,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ];
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->insert($doc);
        $manager->executeBulkWrite($namespace, $bulk);
        return ['success' => true, 'message' => 'User added successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Disable or suspend a user account.
 * @return array ['success' => bool, 'message' => string]
 */
function updateUserAccountState($config, $targetUserId, $mode, $reason, $durationValue = 0, $durationUnit = 'hours') {
    $targetUserId = trim((string)$targetUserId);
    $mode = strtolower(trim((string)$mode));
    $reason = trim((string)$reason);
    $durationValue = (int)$durationValue;
    $durationUnit = strtolower(trim((string)$durationUnit));

    if (!preg_match('/^[a-f0-9]{24}$/i', $targetUserId)) {
        return ['success' => false, 'message' => 'Invalid user ID.'];
    }
    if (!in_array($mode, ['disable', 'suspend', 'enable'], true)) {
        return ['success' => false, 'message' => 'Invalid account action.'];
    }
    if (in_array($mode, ['disable', 'suspend'], true) && $reason === '') {
        return ['success' => false, 'message' => 'Reason is required.'];
    }
    if (!empty($_SESSION['user_id']) && $targetUserId === (string)$_SESSION['user_id']) {
        return ['success' => false, 'message' => 'You cannot apply this action to your own account.'];
    }

    $namespace = $config['database'] . '.users';
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $filter = ['_id' => new MongoDB\BSON\ObjectId($targetUserId)];
        $update = ['$set' => []];

        if ($mode === 'disable') {
            $update['$set'] = [
                'account_state' => 'disabled',
                'disabled_reason' => $reason,
                'disabled_at' => new MongoDB\BSON\UTCDateTime(),
            ];
            $update['$unset'] = [
                'suspend_reason' => 1,
                'suspended_at' => 1,
                'suspended_until' => 1,
                'suspend_duration_value' => 1,
                'suspend_duration_unit' => 1,
            ];
        } elseif ($mode === 'suspend') {
            $allowedUnits = ['hours', 'days', 'weeks', 'months', 'years'];
            if ($durationValue <= 0) {
                return ['success' => false, 'message' => 'Suspend duration must be greater than zero.'];
            }
            if (!in_array($durationUnit, $allowedUnits, true)) {
                return ['success' => false, 'message' => 'Invalid suspend duration unit.'];
            }
            $map = ['hours' => 'hour', 'days' => 'day', 'weeks' => 'week', 'months' => 'month', 'years' => 'year'];
            $untilDt = new DateTime('now', new DateTimeZone('UTC'));
            $untilDt->modify('+' . $durationValue . ' ' . $map[$durationUnit]);
            $untilMs = ((int)$untilDt->format('U')) * 1000;

            $update['$set'] = [
                'account_state' => 'suspended',
                'suspend_reason' => $reason,
                'suspended_at' => new MongoDB\BSON\UTCDateTime(),
                'suspended_until' => new MongoDB\BSON\UTCDateTime($untilMs),
                'suspend_duration_value' => $durationValue,
                'suspend_duration_unit' => $durationUnit,
            ];
            $update['$unset'] = [
                'disabled_reason' => 1,
                'disabled_at' => 1,
            ];
        } else {
            $update['$set'] = [
                'account_state' => 'active',
                'enabled_at' => new MongoDB\BSON\UTCDateTime(),
            ];
            $update['$unset'] = [
                'disabled_reason' => 1,
                'disabled_at' => 1,
                'suspend_reason' => 1,
                'suspended_at' => 1,
                'suspended_until' => 1,
                'suspend_duration_value' => 1,
                'suspend_duration_unit' => 1,
            ];
        }

        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->update($filter, $update, ['multi' => false, 'upsert' => false]);
        $result = $manager->executeBulkWrite($namespace, $bulk);
        if ($result->getModifiedCount() < 1) {
            return ['success' => false, 'message' => 'No changes applied. User may not exist.'];
        }
        if ($mode === 'disable') {
            return ['success' => true, 'message' => 'User disabled successfully.'];
        }
        if ($mode === 'suspend') {
            return ['success' => true, 'message' => 'User suspended successfully.'];
        }
        return ['success' => true, 'message' => 'User enabled successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

$msg = $_GET['msg'] ?? null;
$msgOk = isset($_GET['ok']) && $_GET['ok'] === '1';
$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_user') {
        $flash = addUser(
            $config,
            $_POST['username'] ?? '',
            $_POST['name'] ?? '',
            $_POST['email'] ?? '',
            $_POST['password'] ?? '',
            $_POST['role'] ?? 'user'
        );
        if ($flash) {
            if (!empty($flash['success'])) {
                activityLog($config, 'user_add', [
                    'module' => 'super_admin_users',
                    'target_name' => trim((string)($_POST['name'] ?? $_POST['username'] ?? $_POST['email'] ?? '')),
                    'target_username' => trim((string)($_POST['username'] ?? '')),
                    'target_email' => trim((string)($_POST['email'] ?? '')),
                    'target_role' => trim((string)($_POST['role'] ?? 'user')),
                ]);
            }
            header('Location: users.php?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
            exit;
        }
    } elseif ($action === 'disable_user') {
        $flash = updateUserAccountState(
            $config,
            $_POST['user_id'] ?? '',
            'disable',
            $_POST['reason'] ?? ''
        );
        if (!empty($flash['success'])) {
            activityLog($config, 'user_disable', [
                'module' => 'super_admin_users',
                'target_user_id' => trim((string)($_POST['user_id'] ?? '')),
                'target_name' => trim((string)($_POST['target_name'] ?? '')),
                'reason' => trim((string)($_POST['reason'] ?? '')),
            ]);
        }
        header('Location: users.php?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
        exit;
    } elseif ($action === 'suspend_user') {
        $flash = updateUserAccountState(
            $config,
            $_POST['user_id'] ?? '',
            'suspend',
            $_POST['reason'] ?? '',
            (int)($_POST['duration_value'] ?? 0),
            $_POST['duration_unit'] ?? 'hours'
        );
        if (!empty($flash['success'])) {
            activityLog($config, 'user_suspend', [
                'module' => 'super_admin_users',
                'target_user_id' => trim((string)($_POST['user_id'] ?? '')),
                'target_name' => trim((string)($_POST['target_name'] ?? '')),
                'duration_value' => (string)((int)($_POST['duration_value'] ?? 0)),
                'duration_unit' => trim((string)($_POST['duration_unit'] ?? '')),
                'reason' => trim((string)($_POST['reason'] ?? '')),
            ]);
        }
        header('Location: users.php?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
        exit;
    } elseif ($action === 'enable_user') {
        $flash = updateUserAccountState(
            $config,
            $_POST['user_id'] ?? '',
            'enable',
            ''
        );
        if (!empty($flash['success'])) {
            activityLog($config, 'user_enable', [
                'module' => 'super_admin_users',
                'target_user_id' => trim((string)($_POST['user_id'] ?? '')),
                'target_name' => trim((string)($_POST['target_name'] ?? '')),
            ]);
        }
        header('Location: users.php?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
        exit;
    }
}

$usersList = getUsersList($config, $search);
if ($roleFilter !== '') {
    $usersList = array_filter($usersList, function ($u) use ($roleFilter) {
        return strtolower(trim($u['role'] ?? '')) === strtolower($roleFilter);
    });
    $usersList = array_values($usersList);
}

function formatRoleLabel($role) {
    $r = strtolower(trim($role ?? ''));
    $labels = [
        'superadmin' => 'Super Admin',
        'admin' => 'Admin',
        'user' => 'User',
        'staff' => 'Staff',
        'departmenthead' => 'Department Head',
        'department_head' => 'Department Head',
        'dept_head' => 'Department Head',
    ];
    return $labels[$r] ?? ucfirst($r) ?: '—';
}

function getUserAccountStatusMeta($u) {
    $state = strtolower(trim((string)($u['account_state'] ?? 'active')));
    if ($state === '') $state = 'active';
    if ($state === 'suspended') {
        $until = $u['suspended_until'] ?? null;
        if ($until instanceof MongoDB\BSON\UTCDateTime) {
            $untilDt = $until->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'));
            if ($untilDt->getTimestamp() <= time()) {
                return ['label' => 'Active', 'class' => 'active', 'hint' => ''];
            }
            return ['label' => 'Suspended', 'class' => 'suspended', 'hint' => 'Until ' . $untilDt->format('M j, Y g:i A')];
        }
        return ['label' => 'Suspended', 'class' => 'suspended', 'hint' => ''];
    }
    if ($state === 'disabled') {
        return ['label' => 'Disabled', 'class' => 'disabled', 'hint' => ''];
    }
    return ['label' => 'Active', 'class' => 'active', 'hint' => ''];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Users / Accounts</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="profile_modal_super_admin.css">
    <link rel="stylesheet" href="../Admin Side/admin-dashboard.css">
    <link rel="stylesheet" href="../Admin Side/admin-offices.css">
    <link rel="stylesheet" href="sidebar_super_admin.css">
    <style>
        body { margin: 0; background: #f8fafc; color: #0f172a; }
        .main-content { display: flex; flex-direction: column; flex: 1; min-height: 0; background: #fff; }
        .content-header { background: #fff; padding: 1.5rem 2.2rem; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; }
        .dashboard-header h1 { font-size: 1.6rem; margin: 0 0 0.2rem 0; font-weight: 700; color: #1e293b; }
        .dashboard-header small { display: block; color: #64748b; font-size: 0.95rem; margin-top: 6px; }
        .header-controls { position: relative; }
        .icon-btn, .avatar-btn { background: #f1f5f9; border: none; color: #475569; padding: 0; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .icon-btn:hover, .avatar-btn:hover { background: #e2e8f0; color: #1e293b; }
        .icon-btn { position: relative; width: 48px; height: 48px; }
        .icon-btn svg, .avatar-btn svg { width: 26px; height: 26px; }
        .notif-badge { position: absolute; top: 6px; right: 6px; background: #ef4444; color: white; font-size: 13px; padding: 4px 8px; border-radius: 999px; line-height: 1; }
        .avatar-btn { width: 48px; height: 48px; padding: 0; border-radius: 10px; }
        .main-content .admin-content-body { padding-top: 24px; }
        .offices-card .offices-tools.doc-filter-row select { height: 42px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0 12px; font-size: 14px; color: #1e293b; background: #fff; font-family: 'Poppins', sans-serif; }
        .users-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 1500; display: flex; align-items: center; gap: 12px; padding: 0.875rem 1rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); max-width: 360px; animation: users-toast-in 0.3s ease; }
        .users-toast.success { background: #22c55e; color: #fff; }
        .users-toast.error { background: #ef4444; color: #fff; }
        @keyframes users-toast-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        /* Users table UX */
        .offices-card .offices-table-frame { border-radius: 12px; overflow: hidden; }
        .offices-card .offices-table thead th { background: #f8fafc; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.03em; padding: 14px 16px; border-bottom: 2px solid #e2e8f0; }
        .offices-card .offices-table tbody td { padding: 14px 16px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
        .offices-card .offices-table tbody tr:hover { background: #f8fafc; }
        .offices-card .offices-table tbody tr:last-child td { border-bottom: none; }
        .users-role-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 500; }
        .users-role-badge.superadmin { background: #fef3c7; color: #92400e; }
        .users-role-badge.admin { background: #dbeafe; color: #1e40af; }
        .users-role-badge.departmenthead, .users-role-badge.department_head, .users-role-badge.dept_head { background: #e0e7ff; color: #3730a3; }
        .users-role-badge.user, .users-role-badge.staff { background: #f1f5f9; color: #475569; }
        .users-status-badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 600; line-height: 1.2; }
        .users-status-badge.active { background: #dcfce7; color: #166534; }
        .users-status-badge.disabled { background: #fee2e2; color: #991b1b; }
        .users-status-badge.suspended { background: #fef3c7; color: #92400e; }
        .users-status-hint { display: block; margin-top: 4px; font-size: 0.72rem; color: #64748b; }
        .users-action-cell { white-space: nowrap; }
        .users-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem; font-weight: 500; text-decoration: none; color: #475569; background: #f1f5f9; border: 1px solid #e2e8f0; cursor: pointer; transition: background 0.2s, color 0.2s; font-family: inherit; }
        .users-action-btn:hover { background: #e2e8f0; color: #1e293b; }
        .users-action-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
        .users-action-btn.disable { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .users-action-btn.disable:hover { background: #fecaca; color: #991b1b; }
        .users-action-btn.suspend { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .users-action-btn.suspend:hover { background: #fde68a; color: #78350f; }
        .users-action-btn.enable { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .users-action-btn.enable:hover { background: #bbf7d0; color: #14532d; }
        .users-action-stack { display: inline-flex; gap: 8px; flex-wrap: wrap; }
        .offices-empty { padding: 2rem; text-align: center; color: #64748b; font-size: 0.95rem; }
        #add-user-modal .doc-modal-dialog { width: min(560px, calc(100vw - 24px)); border-radius: 14px; overflow: hidden; }
        #add-user-modal .doc-modal-header { padding: 16px 18px; border-bottom: 1px solid #e2e8f0; background: #ffffff; }
        #add-user-modal .doc-modal-header h2 { margin: 0; font-size: 1.3rem; color: #1e293b; }
        #add-user-modal .doc-modal-form { padding: 16px 18px 18px; display: grid; gap: 12px; }
        #add-user-modal .doc-form-field { display: grid; gap: 6px; }
        #add-user-modal .doc-form-field label { font-size: 13px; color: #334155; font-weight: 600; }
        #add-user-modal .doc-form-field input,
        #add-user-modal .doc-form-field select {
            width: 100%;
            height: 40px;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 0 12px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            color: #0f172a;
            background: #fff;
            box-sizing: border-box;
        }
        #add-user-modal .doc-form-field input:focus,
        #add-user-modal .doc-form-field select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }
        #add-user-modal .doc-modal-actions { margin-top: 6px; display: flex; justify-content: flex-end; gap: 10px; }
        #add-user-modal .doc-btn { min-height: 38px; padding: 0 14px; border-radius: 10px; font-weight: 600; }
        #add-user-modal .doc-form-error { margin: 0; font-size: 13px; color: #dc2626; }
        @media (max-width: 640px) {
            #add-user-modal .doc-modal-dialog { width: calc(100vw - 16px); }
            #add-user-modal .doc-modal-form { padding: 14px; gap: 10px; }
            #add-user-modal .doc-modal-actions { gap: 8px; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/_sidebar_super_admin.php'; ?>

        <div class="main-content">
            <div class="content-header">
                <div class="dashboard-header">
                    <div>
                        <h1>Welcome, <?php echo htmlspecialchars($welcomeUsername); ?>!</h1>
                        <small>Municipal Document Management System – Users / Accounts</small>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="header-controls">
                            <?php include __DIR__ . '/_notif_dropdown_super_admin.php'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-content-body">
                <section class="chart-card chart-card-wide offices-card">
                    <?php if ($msg !== null): ?>
                    <div id="users-toast" class="users-toast <?= $msgOk ? 'success' : 'error' ?>" role="alert">
                        <span class="users-toast-text"><?= htmlspecialchars($msg) ?></span>
                    </div>
                    <?php endif; ?>
                    <form method="get" class="offices-tools doc-filter-row" id="users-filter-form">
                        <input type="text" name="search" placeholder="Search by name or email" aria-label="Search" value="<?= htmlspecialchars($search) ?>">
                        <select name="role" aria-label="Filter by role">
                            <option value="">All Roles</option>
                            <option value="superadmin" <?= $roleFilter === 'superadmin' ? 'selected' : '' ?>>Super Admin</option>
                            <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>User</option>
                            <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>Staff</option>
                            <option value="departmenthead" <?= $roleFilter === 'departmenthead' ? 'selected' : '' ?>>Department Head</option>
                        </select>
                        <button type="submit" class="offices-btn offices-btn-secondary">Filter</button>
                        <button type="button" class="offices-btn" id="add-user-btn">
                            <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                            Add User
                        </button>
                        <button type="button" class="offices-btn offices-btn-secondary" id="edit-user-btn">
                            <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            Edit
                        </button>
                    </form>

                    <div class="offices-table-frame">
                        <table class="offices-table">
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Office / Department</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="users-table-body">
                                <?php if (count($usersList) === 0): ?>
                                <tr>
                                    <td colspan="7" class="offices-empty" id="no-users-row">No users found. Try adjusting the search or filter, or add a new user.</td>
                                </tr>
                                <?php else:
                                    $no = 1;
                                    foreach ($usersList as $u):
                                        $displayName = trim($u['name'] ?? '') ?: (trim($u['username'] ?? '') ?: trim($u['email'] ?? ''));
                                        if ($displayName === '') $displayName = '—';
                                        $dept = trim($u['department'] ?? $u['user_department'] ?? '');
                                        if ($dept === '') $dept = '—';
                                        $rawRole = strtolower(trim($u['role'] ?? ''));
                                        $roleClass = $rawRole ?: 'user';
                                        $statusMeta = getUserAccountStatusMeta($u);
                                        $showEnableBtn = in_array($statusMeta['class'], ['disabled', 'suspended'], true);
                                ?>
                                <tr>
                                    <td><?= (int)$no ?></td>
                                    <td><?= htmlspecialchars($displayName) ?></td>
                                    <td><?= htmlspecialchars(trim($u['email'] ?? '') ?: '—') ?></td>
                                    <td><span class="users-role-badge <?= htmlspecialchars($roleClass) ?>"><?= htmlspecialchars(formatRoleLabel($u['role'] ?? '')) ?></span></td>
                                    <td>
                                        <span class="users-status-badge <?= htmlspecialchars($statusMeta['class']) ?>"><?= htmlspecialchars($statusMeta['label']) ?></span>
                                        <?php if (!empty($statusMeta['hint'])): ?>
                                        <small class="users-status-hint"><?= htmlspecialchars($statusMeta['hint']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($dept) ?></td>
                                    <td class="users-action-cell">
                                        <div class="users-action-stack">
                                            <a href="edit_user.php?id=<?= htmlspecialchars($u['_id'] ?? '') ?>" class="users-action-btn" title="Edit user">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                Edit
                                            </a>
                                            <?php if ($showEnableBtn): ?>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Enable this account? The user will be able to login again.');">
                                                <input type="hidden" name="action" value="enable_user">
                                                <input type="hidden" name="user_id" value="<?= htmlspecialchars($u['_id'] ?? '') ?>">
                                                <input type="hidden" name="target_name" value="<?= htmlspecialchars($displayName) ?>">
                                                <button type="submit" class="users-action-btn enable" title="Enable user">Enable</button>
                                            </form>
                                            <?php else: ?>
                                            <button type="button" class="users-action-btn disable js-disable-user-btn" data-user-id="<?= htmlspecialchars($u['_id'] ?? '') ?>" data-user-name="<?= htmlspecialchars($displayName) ?>" title="Disable user">Disable</button>
                                            <button type="button" class="users-action-btn suspend js-suspend-user-btn" data-user-id="<?= htmlspecialchars($u['_id'] ?? '') ?>" data-user-name="<?= htmlspecialchars($displayName) ?>" title="Suspend user">Suspend</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                                    $no++;
                                    endforeach;
                                endif;
                                ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/_profile_modal_super_admin.php'; ?>

    <div class="doc-modal" id="add-user-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-add-user aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="add-user-title">
            <div class="doc-modal-header">
                <h2 id="add-user-title">Add User</h2>
                <button type="button" class="doc-modal-close" data-close-add-user aria-label="Close">&times;</button>
            </div>
            <form method="post" id="add-user-form" class="doc-modal-form">
                <input type="hidden" name="action" value="add_user">
                <div class="doc-form-field">
                    <label for="add-user-username">Username <span class="required">*</span></label>
                    <input type="text" id="add-user-username" name="username" placeholder="e.g. jdelacruz" required>
                </div>
                <div class="doc-form-field">
                    <label for="add-user-name">Name</label>
                    <input type="text" id="add-user-name" name="name" placeholder="e.g. Juan Dela Cruz">
                </div>
                <div class="doc-form-field">
                    <label for="add-user-email">Email <span class="required">*</span></label>
                    <input type="email" id="add-user-email" name="email" placeholder="e.g. juan@example.com" required>
                </div>
                <div class="doc-form-field">
                    <label for="add-user-password">Password <span class="required">*</span></label>
                    <input type="password" id="add-user-password" name="password" placeholder="At least 6 characters" required minlength="6">
                </div>
                <div class="doc-form-field">
                    <label for="add-user-role">Role</label>
                    <select id="add-user-role" name="role" class="offices-select">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                        <option value="superadmin">Super Admin</option>
                        <option value="staff">Staff</option>
                        <option value="departmenthead">Department Head</option>
                    </select>
                </div>
                <p class="doc-form-error" id="add-user-form-error" hidden></p>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-add-user>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Add User</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="disable-user-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-disable-user aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="disable-user-title">
            <div class="doc-modal-header">
                <h2 id="disable-user-title">Disable User</h2>
                <button type="button" class="doc-modal-close" data-close-disable-user aria-label="Close">&times;</button>
            </div>
            <form method="post" id="disable-user-form" class="doc-modal-form">
                <input type="hidden" name="action" value="disable_user">
                <input type="hidden" name="user_id" id="disable-user-id" value="">
                <input type="hidden" name="target_name" id="disable-target-name" value="">
                <div class="doc-form-field">
                    <label>User</label>
                    <input type="text" id="disable-user-name" value="" readonly>
                </div>
                <div class="doc-form-field">
                    <label for="disable-user-reason">Reason <span class="required">*</span></label>
                    <input type="text" id="disable-user-reason" name="reason" placeholder="Reason for disabling this account" required>
                </div>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-disable-user>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Disable User</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="suspend-user-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-suspend-user aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="suspend-user-title">
            <div class="doc-modal-header">
                <h2 id="suspend-user-title">Suspend User</h2>
                <button type="button" class="doc-modal-close" data-close-suspend-user aria-label="Close">&times;</button>
            </div>
            <form method="post" id="suspend-user-form" class="doc-modal-form">
                <input type="hidden" name="action" value="suspend_user">
                <input type="hidden" name="user_id" id="suspend-user-id" value="">
                <input type="hidden" name="target_name" id="suspend-target-name" value="">
                <div class="doc-form-field">
                    <label>User</label>
                    <input type="text" id="suspend-user-name" value="" readonly>
                </div>
                <div class="doc-form-field">
                    <label>Suspend for <span class="required">*</span></label>
                    <div style="display:flex;gap:8px;">
                        <input type="number" min="1" step="1" id="suspend-duration-value" name="duration_value" placeholder="Value" required style="max-width:130px;">
                        <select id="suspend-duration-unit" name="duration_unit" required>
                            <option value="hours">Hours</option>
                            <option value="days">Days</option>
                            <option value="weeks">Weeks</option>
                            <option value="months">Months</option>
                            <option value="years">Years</option>
                        </select>
                    </div>
                </div>
                <div class="doc-form-field">
                    <label for="suspend-user-reason">Reason <span class="required">*</span></label>
                    <input type="text" id="suspend-user-reason" name="reason" placeholder="Reason for suspension" required>
                </div>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-suspend-user>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Suspend User</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function() {
        var addBtn = document.getElementById('add-user-btn');
        var modal = document.getElementById('add-user-modal');
        var form = document.getElementById('add-user-form');
        var errorEl = document.getElementById('add-user-form-error');
        function showError(msg) {
            if (!errorEl) return;
            if (!msg) { errorEl.hidden = true; errorEl.textContent = ''; return; }
            errorEl.hidden = false; errorEl.textContent = msg;
        }
        function openAddUserModal() {
            if (modal) { modal.hidden = false; document.body.classList.add('modal-open'); showError(''); }
        }
        function closeAddUserModal() {
            if (modal) { modal.hidden = true; document.body.classList.remove('modal-open'); showError(''); if (form) form.reset(); }
        }
        if (addBtn) addBtn.addEventListener('click', openAddUserModal);
        document.querySelectorAll('[data-close-add-user]').forEach(function(btn) { btn.addEventListener('click', closeAddUserModal); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal && !modal.hidden) closeAddUserModal(); });
        if (form) {
            form.addEventListener('submit', function(e) {
                var pwd = document.getElementById('add-user-password');
                if (pwd && pwd.value.length < 6) { e.preventDefault(); showError('Password must be at least 6 characters.'); return; }
                showError('');
            });
        }
        var editBtn = document.getElementById('edit-user-btn');
        if (editBtn) editBtn.addEventListener('click', function() { alert('Select a user row to edit.'); });

        var disableModal = document.getElementById('disable-user-modal');
        var disableForm = document.getElementById('disable-user-form');
        var disableUserId = document.getElementById('disable-user-id');
        var disableTargetName = document.getElementById('disable-target-name');
        var disableUserName = document.getElementById('disable-user-name');
        var disableReason = document.getElementById('disable-user-reason');
        function openDisableModal(userId, userName) {
            if (!disableModal) return;
            if (disableUserId) disableUserId.value = userId || '';
            if (disableTargetName) disableTargetName.value = userName || '';
            if (disableUserName) disableUserName.value = userName || '';
            if (disableReason) disableReason.value = '';
            disableModal.hidden = false;
            document.body.classList.add('modal-open');
        }
        function closeDisableModal() {
            if (!disableModal) return;
            disableModal.hidden = true;
            document.body.classList.remove('modal-open');
            if (disableForm) disableForm.reset();
        }
        document.querySelectorAll('.js-disable-user-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openDisableModal(btn.getAttribute('data-user-id') || '', btn.getAttribute('data-user-name') || 'User');
            });
        });
        document.querySelectorAll('[data-close-disable-user]').forEach(function(btn) { btn.addEventListener('click', closeDisableModal); });
        if (disableForm) {
            disableForm.addEventListener('submit', function(e) {
                if (!disableUserId || disableUserId.value.trim() === '') {
                    e.preventDefault();
                    alert('No target user selected.');
                }
            });
        }

        var suspendModal = document.getElementById('suspend-user-modal');
        var suspendForm = document.getElementById('suspend-user-form');
        var suspendUserId = document.getElementById('suspend-user-id');
        var suspendTargetName = document.getElementById('suspend-target-name');
        var suspendUserName = document.getElementById('suspend-user-name');
        var suspendDurationValue = document.getElementById('suspend-duration-value');
        function openSuspendModal(userId, userName) {
            if (!suspendModal) return;
            if (suspendUserId) suspendUserId.value = userId || '';
            if (suspendTargetName) suspendTargetName.value = userName || '';
            if (suspendUserName) suspendUserName.value = userName || '';
            if (suspendDurationValue) suspendDurationValue.value = '';
            suspendModal.hidden = false;
            document.body.classList.add('modal-open');
        }
        function closeSuspendModal() {
            if (!suspendModal) return;
            suspendModal.hidden = true;
            document.body.classList.remove('modal-open');
            if (suspendForm) suspendForm.reset();
        }
        document.querySelectorAll('.js-suspend-user-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openSuspendModal(btn.getAttribute('data-user-id') || '', btn.getAttribute('data-user-name') || 'User');
            });
        });
        document.querySelectorAll('[data-close-suspend-user]').forEach(function(btn) { btn.addEventListener('click', closeSuspendModal); });
        if (suspendForm) {
            suspendForm.addEventListener('submit', function(e) {
                var value = parseInt((suspendDurationValue && suspendDurationValue.value) || '0', 10);
                if (value < 1) {
                    e.preventDefault();
                    alert('Suspend duration must be at least 1.');
                    return;
                }
                if (!suspendUserId || suspendUserId.value.trim() === '') {
                    e.preventDefault();
                    alert('No target user selected.');
                }
            });
        }
    })();
    </script>
    <?php $notifJsVer = @filemtime(__DIR__ . '/super_admin_notifications.js') ?: time(); ?>
    <script src="sidebar_super_admin.js"></script>
    <script src="super_admin_notifications.js?v=<?= (int)$notifJsVer ?>"></script>
</body>
</html>
