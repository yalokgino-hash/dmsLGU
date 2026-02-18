<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Admin';

// Fetch document counts and recent docs from DB; fallback to placeholders
$totalDocuments = 0;
$pendingCount = 0;
$approvedCount = 0;
$completedCount = 0;
$recentDocuments = [];
$statusBreakdown = ['Archived' => 0, 'Pending Admin' => 0, 'Pending Department' => 0];

try {
    $config = require __DIR__ . '/../config.php';
    $manager = new MongoDB\Driver\Manager($config['uri']);
    $namespace = $config['database'] . '.documents';
    $query = new MongoDB\Driver\Query([], ['sort' => ['createdAt' => -1], 'limit' => 200]);
    $cursor = $manager->executeQuery($namespace, $query);
    $docs = $cursor->toArray();
    $totalDocuments = count($docs);
    foreach ($docs as $d) {
        $arr = (array)$d;
        $status = isset($arr['status']) ? (string)$arr['status'] : 'Pending';
        $s = strtolower($status);
        if (strpos($s, 'pending') !== false) $pendingCount++;
        elseif (strpos($s, 'approved') !== false) $approvedCount++;
        elseif (strpos($s, 'completed') !== false) $completedCount++;
        if (strpos($s, 'archived') !== false) {
            $statusBreakdown['Archived']++;
        } elseif (strpos($s, 'admin') !== false) {
            $statusBreakdown['Pending Admin']++;
        } else {
            $statusBreakdown['Pending Department']++;
        }
    }
    $recentDocuments = array_slice(array_map(function($d) { return (array)$d; }, $docs), 0, 5);
} catch (Exception $e) {
    $totalDocuments = 3;
    $pendingCount = 2;
    $approvedCount = 0;
    $completedCount = 0;
    $statusBreakdown = ['Archived' => 1, 'Pending Admin' => 2, 'Pending Department' => 0];
    $recentDocuments = [
        ['controlNo' => 'DOC-20260218-13981CA8', 'title' => 'Sample document', 'status' => 'Pending Admin Review', 'createdAt' => new DateTime()],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin-dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="admin-dashboard">
    <div class="admin-body">
        <aside class="admin-sidebar admin-sidebar-design">
            <div class="sidebar-header admin-sidebar-header">
                <div class="sidebar-logo admin-sidebar-logo">
                    <img src="../img/logo.png" alt="LGU Solano">
                </div>
                <div class="sidebar-title admin-sidebar-title">
                    <h2>LGU Solano</h2>
                    <span class="admin-sidebar-subtitle">Document Management</span>
                </div>
            </div>
            <nav class="sidebar-nav admin-sidebar-nav">
                <div class="sidebar-section">
                    <span class="sidebar-section-title">MAIN MENU</span>
                    <a href="admin_dashboard.php" class="sidebar-link active">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        Dashboard
                    </a>
                    <a href="documents.php" class="sidebar-link">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                        Documents
                    </a>
                    <a href="admin_offices.php" class="sidebar-link">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
                        Departments
                    </a>
                    <a href="document_history.php" class="sidebar-link">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l2 2"/><circle cx="12" cy="12" r="10"/></svg>
                        Documents History
                    </a>
                </div>
                <div class="sidebar-section sidebar-section-account">
                    <span class="sidebar-section-title">ACCOUNT</span>
                    <a href="../Front Desk Side/settings.php" class="sidebar-link sidebar-link-settings">
                        <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        Settings
                    </a>
                </div>
            </nav>
        </aside>

        <main class="admin-main">
            <div class="admin-content" id="admin-content">
                <div class="admin-content-header-row">
                    <header class="admin-content-header">
                        <div class="admin-header-text">
                            <h1 class="admin-content-title">Welcome back, <?php echo htmlspecialchars($userName); ?></h1>
                            <p class="admin-content-subtitle" id="dashboard-subtitle">Admin Dashboard • </p>
                        </div>
                    </header>
                    <div class="admin-content-actions">
                        <button type="button" class="admin-icon-btn" id="notif-btn" title="Notifications" aria-label="Notifications">
                            <svg class="icon-bell" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        </button>
                        <div class="admin-profile-wrap">
                            <button type="button" class="admin-icon-btn" id="profile-logout-btn" title="Profile and log out" aria-haspopup="true" aria-expanded="false" aria-label="Profile">
                                <svg class="icon-person" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
                            </button>
                            <div class="profile-dropdown" id="profile-dropdown" hidden>
                                <a href="#" class="dropdown-item">Profile</a>
                                <a href="../index.php?logout=1" class="dropdown-item dropdown-logout">Log out</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="admin-content-body" id="admin-content-body">
                <div class="dashboard-upload-row">
                    <a href="documents.php" class="btn-upload-document">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Upload Document
                    </a>
                </div>
                <div class="dashboard-metrics">
                    <div class="metric-card">
                        <span class="metric-label">TOTAL DOCUMENTS</span>
                        <span class="metric-value"><?php echo (int)$totalDocuments; ?></span>
                        <svg class="metric-icon metric-icon-doc" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">PENDING</span>
                        <span class="metric-value"><?php echo (int)$pendingCount; ?></span>
                        <svg class="metric-icon metric-icon-pending" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">APPROVED</span>
                        <span class="metric-value"><?php echo (int)$approvedCount; ?></span>
                        <svg class="metric-icon metric-icon-approved" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">COMPLETED</span>
                        <span class="metric-value"><?php echo (int)$completedCount; ?></span>
                        <svg class="metric-icon metric-icon-completed" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </div>
                </div>

                <div class="dashboard-middle">
                    <div class="dashboard-card my-tasks-card">
                        <div class="card-head">
                            <h3 class="card-title">My Tasks</h3>
                            <a href="documents.php" class="card-link">View All →</a>
                        </div>
                        <div class="my-tasks-empty">
                            <svg class="tasks-check-icon" viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <p class="tasks-empty-title">All caught up!</p>
                            <p class="tasks-empty-sub">No pending tasks at the moment</p>
                        </div>
                    </div>
                    <div class="dashboard-card status-breakdown-card">
                        <h3 class="card-title">Status Breakdown</h3>
                        <div class="status-chart-wrap">
                            <canvas id="chart-status-breakdown" width="280" height="280"></canvas>
                        </div>
                        <div class="status-legend">
                            <span class="legend-item"><i class="legend-dot" style="background:#2563eb"></i> Archived</span>
                            <span class="legend-item"><i class="legend-dot" style="background:#ea580c"></i> Pending Admin</span>
                            <span class="legend-item"><i class="legend-dot" style="background:#16a34a"></i> Pending Department</span>
                        </div>
                    </div>
                </div>

                <div class="dashboard-card recent-docs-card">
                    <div class="card-head">
                        <h3 class="card-title">Recent Documents</h3>
                        <a href="documents.php" class="card-link">View All →</a>
                    </div>
                    <div class="recent-docs-table-wrap">
                        <table class="recent-docs-table">
                            <thead>
                                <tr>
                                    <th>CONTROL NO.</th>
                                    <th>TITLE</th>
                                    <th>STATUS</th>
                                    <th>DATE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentDocuments)): ?>
                                <tr>
                                    <td colspan="4" class="recent-docs-empty">No recent documents</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($recentDocuments as $doc): ?>
                                <?php
                                    $controlNo = isset($doc['controlNo']) ? htmlspecialchars($doc['controlNo']) : (isset($doc['control_no']) ? htmlspecialchars($doc['control_no']) : '—');
                                    $title = isset($doc['title']) ? htmlspecialchars($doc['title']) : (isset($doc['subject']) ? htmlspecialchars($doc['subject']) : '—');
                                    $status = isset($doc['status']) ? htmlspecialchars($doc['status']) : 'Pending';
                                    $date = '—';
                                    if (isset($doc['createdAt'])) {
                                        $dt = $doc['createdAt'];
                                        if ($dt instanceof DateTimeInterface) {
                                            $date = $dt->format('M j, Y');
                                        } elseif (is_string($dt)) {
                                            $date = date('M j, Y', strtotime($dt));
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><a href="documents.php" class="control-no-link"><?php echo $controlNo; ?></a></td>
                                    <td><?php echo $title; ?></td>
                                    <td><span class="status-badge status-pending"><?php echo $status; ?></span></td>
                                    <td><?php echo $date; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
    (function() {
        function updateSubtitle() {
            var el = document.getElementById('dashboard-subtitle');
            if (el) {
                var now = new Date();
                var day = now.toLocaleDateString('en-US', { weekday: 'long' });
                var date = now.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                el.textContent = 'Admin Dashboard • ' + day + ', ' + date;
            }
        }
        updateSubtitle();
        setInterval(updateSubtitle, 60000);

        var profileBtn = document.getElementById('profile-logout-btn');
        var profileDropdown = document.getElementById('profile-dropdown');
        if (profileBtn && profileDropdown) {
            profileBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                var open = profileDropdown.hidden;
                profileDropdown.hidden = !open;
                profileBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function() {
                profileDropdown.hidden = true;
                profileBtn.setAttribute('aria-expanded', 'false');
            });
            profileDropdown.addEventListener('click', function(e) { e.stopPropagation(); });
        }

        var statusData = {
            labels: ['Archived', 'Pending Admin', 'Pending Department'],
            datasets: [{
                data: [<?php echo (int)($statusBreakdown['Archived'] ?? 0); ?>, <?php echo (int)($statusBreakdown['Pending Admin'] ?? 0); ?>, <?php echo (int)($statusBreakdown['Pending Department'] ?? 0); ?>],
                backgroundColor: ['#2563eb', '#ea580c', '#16a34a'],
                borderWidth: 0,
                hoverOffset: 4
            }]
        };
        var statusCtx = document.getElementById('chart-status-breakdown');
        if (statusCtx && typeof Chart !== 'undefined') {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: statusData,
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '60%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                    var pct = total ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                    return ctx.label + ': ' + ctx.raw + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }
    })();
    </script>
</body>
</html>
