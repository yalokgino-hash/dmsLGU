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
function addUser($config, $username, $name, $email, $password, $role, $department = '') {
    $username = trim($username);
    $name = trim($name);
    $email = trim($email);
    $department = trim($department);
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
        if ($department !== '') {
            $doc['department'] = $department;
        }
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->insert($doc);
        $manager->executeBulkWrite($namespace, $bulk);
        return ['success' => true, 'message' => 'User added successfully.'];
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
            $_POST['role'] ?? 'user',
            $_POST['department'] ?? ''
        );
        if ($flash) {
            header('Location: users.php?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
            exit;
        }
    }
}

$usersList = getUsersList($config, $search);
if ($roleFilter !== '') {
    $usersList = array_filter($usersList, function ($u) use ($roleFilter) {
        return strtolower(trim($u['role'] ?? '')) === strtolower($roleFilter);
    });
    $usersList = array_values($usersList);
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
        .notif-dropdown { position: absolute; right: 0; top: 54px; background: white; color: #0b1720; min-width: 240px; border-radius: 8px; box-shadow: 0 8px 20px rgba(2,6,23,0.12); border: 1px solid #e6eef8; display: none; z-index: 1200; padding: 10px 0; }
        .notif-item { padding: 12px 14px; font-size: 1.05rem; color: #475569; }
        .notif-item-link { display: block; text-decoration: none; color: #1e293b; border-bottom: 1px solid #f1f5f9; }
        .notif-item-link:hover { background: #f8fafc; color: #0f172a; }
        .notif-item-link:last-child { border-bottom: none; }
        .main-content .admin-content-body { padding-top: 24px; }
        .offices-card .offices-tools.doc-filter-row select { height: 42px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0 12px; font-size: 14px; color: #1e293b; background: #fff; font-family: 'Poppins', sans-serif; }
        .users-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 1500; display: flex; align-items: center; gap: 12px; padding: 0.875rem 1rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); max-width: 360px; animation: users-toast-in 0.3s ease; }
        .users-toast.success { background: #22c55e; color: #fff; }
        .users-toast.error { background: #ef4444; color: #fff; }
        @keyframes users-toast-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
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
                            <button class="icon-btn" id="notif-btn" aria-label="Notifications" title="Notifications">
                                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0 1 18 14.158V11a6 6 0 1 0-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                                <span class="notif-badge" id="notif-count" aria-hidden="true" style="<?= $notifCount === 0 ? 'display:none' : '' ?>"><?= (int)$notifCount ?></span>
                            </button>
                            <div class="notif-dropdown" id="notif-dropdown" aria-hidden="true">
                                <?php if (count($notifItems) === 0): ?>
                                <div class="notif-item">No new notifications</div>
                                <?php else: ?>
                                <?php foreach ($notifItems as $ni): ?>
                                <a href="documents.php?highlight=<?= urlencode($ni['documentId']) ?>" class="notif-item notif-item-link"><?= htmlspecialchars($ni['documentTitle']) ?> — from <?= htmlspecialchars($ni['sentByUserName']) ?> (<?= htmlspecialchars($ni['sentAtFormatted']) ?>)</a>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="header-controls">
                            <button class="avatar-btn" id="profile-btn" aria-label="Profile" title="Profile">
                                <svg class="avatar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4z"/><path d="M6 20v-1c0-2.21 3.58-4 6-4s6 1.79 6 4v1"/></svg>
                            </button>
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
                    </div>

                    <div class="offices-table-frame">
                        <table class="offices-table">
                            <thead>
                                <tr>
                                    <th>NO.</th>
                                    <th>NAME</th>
                                    <th>EMAIL</th>
                                    <th>OFFICE/DEPARTMENT</th>
                                    <th>ACTION</th>
                                </tr>
                            </thead>
                            <tbody id="users-table-body">
                                <?php if (count($usersList) === 0): ?>
                                <tr>
                                    <td colspan="5" class="offices-empty" id="no-users-row">No users yet.</td>
                                </tr>
                                <?php else:
                                    $no = 1;
                                    foreach ($usersList as $u):
                                        $displayName = trim($u['username'] ?? '') !== '' ? trim($u['username']) : (trim($u['name'] ?? '') ?: trim($u['email'] ?? ''));
                                        if ($displayName === '') $displayName = '—';
                                        $dept = trim($u['department'] ?? $u['user_department'] ?? '');
                                        if ($dept === '') $dept = '—';
                                ?>
                                <tr>
                                    <td><?= (int)$no ?></td>
                                    <td><?= htmlspecialchars($displayName) ?></td>
                                    <td><?= htmlspecialchars(trim($u['email'] ?? '') ?: '—') ?></td>
                                    <td><?= htmlspecialchars($dept) ?></td>
                                    <td>—</td>
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
                    <select id="add-user-role" name="role" class="offices-select" style="height:40px;border:1px solid #e2e8f0;border-radius:10px;padding:0 10px;font-size:14px;">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                        <option value="superadmin">Super Admin</option>
                        <option value="staff">Staff</option>
                        <option value="departmenthead">Department Head</option>
                    </select>
                </div>
                <div class="doc-form-field">
                    <label for="add-user-department">Office / Department</label>
                    <input type="text" id="add-user-department" name="department" placeholder="Optional">
                </div>
                <p class="doc-form-error" id="add-user-form-error" hidden></p>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-add-user>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Add User</button>
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
    })();
    </script>
    <script>
    (function(){
        var notifBtn = document.getElementById('notif-btn');
        var notifDropdown = document.getElementById('notif-dropdown');
        function closeNotif(){ if (notifDropdown) notifDropdown.style.display = 'none'; }
        if (notifBtn) notifBtn.addEventListener('click', function(e){ e.stopPropagation(); if (!notifDropdown) return; var showing = notifDropdown.style.display === 'block'; closeNotif(); notifDropdown.style.display = showing ? 'none' : 'block'; });
        document.addEventListener('click', function(){ closeNotif(); });
    })();
    </script>
    <script src="sidebar_super_admin.js"></script>
    <script src="super_admin_notifications.js"></script>
</body>
</html>
