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
$sidebar_active = 'activitylogs';
$welcomeUsername = getUserUsername($_SESSION['user_id'] ?? '') ?: ($_SESSION['user_username'] ?? $userName) ?: 'User';

$config = require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/_notifications_super_admin.php';
require_once __DIR__ . '/_activity_logger.php';
$notifData = getSuperAdminNotifications($config);
$notifCount = $notifData['count'];
$notifItems = $notifData['items'];

$search = trim((string)($_GET['search'] ?? ''));
$fromDate = trim((string)($_GET['from'] ?? ''));
$toDate = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, (int)($_GET['per_page'] ?? 20));
if (!in_array($perPage, [10, 20, 50, 100], true)) {
    $perPage = 20;
}
$activityPage = getActivityLogsPage($config, $search, $fromDate, $toDate, $page, $perPage);
$activityRows = $activityPage['rows'];
$totalLogs = (int)($activityPage['total'] ?? 0);
$currentPage = max(1, (int)($activityPage['page'] ?? 1));
$totalPages = max(1, (int)($activityPage['total_pages'] ?? 1));
$rowStart = (($currentPage - 1) * $perPage) + 1;

$buildLogsUrl = function ($override = []) use ($search, $fromDate, $toDate, $currentPage, $perPage) {
    $params = [
        'search' => $search,
        'from' => $fromDate,
        'to' => $toDate,
        'page' => $currentPage,
        'per_page' => $perPage,
    ];
    foreach ($override as $key => $value) {
        $params[$key] = $value;
    }
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    $query = http_build_query($params);
    return $query !== '' ? ('?' . $query) : '?';
};

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Activity Logs</title>
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
        .logs-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 12px 0; flex-wrap: wrap; }
        .logs-meta { color: #64748b; font-size: 0.92rem; }
        .logs-pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 14px; flex-wrap: wrap; }
        .logs-pages { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .logs-page-link, .logs-page-current {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 10px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 0.92rem;
            text-decoration: none;
            color: #334155;
            background: #fff;
        }
        .logs-page-link:hover { background: #f8fafc; border-color: #94a3b8; }
        .logs-page-current { background: #0f172a; color: #fff; border-color: #0f172a; }
        .logs-per-page { display: inline-flex; align-items: center; gap: 8px; }
        .logs-per-page select {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 0.92rem;
            color: #334155;
            background: #fff;
        }
        @media print {
            .sidebar, .content-header, .doc-filter-row, .logs-actions, .logs-pagination, .offices-btn { display: none !important; }
            .main-content { width: 100% !important; }
            .admin-content-body { padding: 0 !important; }
            .offices-table-frame { border: none !important; box-shadow: none !important; }
            .offices-table th, .offices-table td { font-size: 12px; }
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
                        <small>Municipal Document Management System – Activity Logs</small>
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
                    <form method="get" class="offices-tools doc-filter-row">
                        <input type="text" id="search-logs" name="search" placeholder="Search by user, action, role, reason" aria-label="Search" value="<?= htmlspecialchars($search) ?>">
                        <input type="date" name="from" aria-label="From date" value="<?= htmlspecialchars($fromDate) ?>">
                        <input type="date" name="to" aria-label="To date" value="<?= htmlspecialchars($toDate) ?>">
                        <input type="hidden" name="page" value="1">
                        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                        <button type="submit" class="offices-btn offices-btn-secondary" id="search-logs-btn">
                            <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            Search
                        </button>
                        <a href="activitylogs.php" class="offices-btn offices-btn-secondary">Reset</a>
                    </form>

                    <div class="logs-actions">
                        <div class="logs-meta">
                            Showing <?= count($activityRows) ?> of <?= (int)$totalLogs ?> log(s)
                        </div>
                        <button type="button" class="offices-btn offices-btn-secondary" onclick="window.print()">
                            Print
                        </button>
                    </div>

                    <div class="offices-table-frame">
                        <table class="offices-table">
                            <thead>
                                <tr>
                                    <th>NO.</th>
                                    <th>USER</th>
                                    <th>ROLE</th>
                                    <th>ACTION</th>
                                    <th>RESULT</th>
                                    <th>DATE/TIME</th>
                                    <th>IP ADDRESS</th>
                                </tr>
                            </thead>
                            <tbody id="logs-table-body">
                                <?php if (count($activityRows) === 0): ?>
                                <tr><td colspan="7" class="offices-empty" id="no-logs-row">No activity logs yet.</td></tr>
                                <?php else: ?>
                                <?php foreach ($activityRows as $idx => $row): ?>
                                <tr>
                                    <td><?= (int)($rowStart + $idx) ?></td>
                                    <td><?= htmlspecialchars($row['actor_name'] !== '' ? $row['actor_name'] : 'Unknown') ?></td>
                                    <td><?= htmlspecialchars($row['actor_role_text'] !== '' ? $row['actor_role_text'] : '—') ?></td>
                                    <td><?= htmlspecialchars($row['action_text'] !== '' ? $row['action_text'] : ($row['action'] !== '' ? $row['action'] : '—')) ?></td>
                                    <td><?= htmlspecialchars($row['status_text'] !== '' ? $row['status_text'] : 'Success') ?></td>
                                    <td><?= htmlspecialchars($row['created_at_formatted']) ?></td>
                                    <td><?= htmlspecialchars($row['ip_address'] !== '' ? $row['ip_address'] : '—') ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="logs-pagination">
                        <div class="logs-pages">
                            <?php if ($currentPage > 1): ?>
                                <a class="logs-page-link" href="<?= htmlspecialchars($buildLogsUrl(['page' => $currentPage - 1])) ?>">Prev</a>
                            <?php endif; ?>
                            <?php
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                                if ($startPage > 1):
                            ?>
                                <a class="logs-page-link" href="<?= htmlspecialchars($buildLogsUrl(['page' => 1])) ?>">1</a>
                                <?php if ($startPage > 2): ?>
                                    <span class="logs-page-link">...</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                <?php if ($p === $currentPage): ?>
                                    <span class="logs-page-current"><?= (int)$p ?></span>
                                <?php else: ?>
                                    <a class="logs-page-link" href="<?= htmlspecialchars($buildLogsUrl(['page' => $p])) ?>"><?= (int)$p ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < ($totalPages - 1)): ?>
                                    <span class="logs-page-link">...</span>
                                <?php endif; ?>
                                <a class="logs-page-link" href="<?= htmlspecialchars($buildLogsUrl(['page' => $totalPages])) ?>"><?= (int)$totalPages ?></a>
                            <?php endif; ?>

                            <?php if ($currentPage < $totalPages): ?>
                                <a class="logs-page-link" href="<?= htmlspecialchars($buildLogsUrl(['page' => $currentPage + 1])) ?>">Next</a>
                            <?php endif; ?>
                        </div>

                        <form method="get" class="logs-per-page">
                            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="from" value="<?= htmlspecialchars($fromDate) ?>">
                            <input type="hidden" name="to" value="<?= htmlspecialchars($toDate) ?>">
                            <input type="hidden" name="page" value="1">
                            <label for="logs-per-page">Rows:</label>
                            <select id="logs-per-page" name="per_page" onchange="this.form.submit()">
                                <option value="10" <?= $perPage === 10 ? 'selected' : '' ?>>10</option>
                                <option value="20" <?= $perPage === 20 ? 'selected' : '' ?>>20</option>
                                <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                            </select>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/_profile_modal_super_admin.php'; ?>

    <script src="sidebar_super_admin.js"></script>
    <?php $notifJsVer = @filemtime(__DIR__ . '/super_admin_notifications.js') ?: time(); ?>
    <script src="super_admin_notifications.js?v=<?= (int)$notifJsVer ?>"></script>
</body>
</html>
