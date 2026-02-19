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
$sidebar_active = 'documents';
$welcomeUsername = getUserUsername($_SESSION['user_id'] ?? '') ?: ($_SESSION['user_username'] ?? $userName) ?: 'User';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Documents</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="sidebar_super_admin.css">
    <link rel="stylesheet" href="profile_modal_super_admin.css">
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
        .main-content { background: #f8fafc; }
        .content-header { background: #fff; padding: 1.5rem 2.2rem; border-bottom: 1px solid #e2e8f0; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; }
        .dashboard-header h1 { font-size: 1.6rem; margin: 0 0 0.2rem 0; font-weight: 700; color: #1e293b; }
        .dashboard-header small { display: block; color: #64748b; font-size: 0.95rem; margin-top: 6px; }
        .content-body { padding: 2rem 2.2rem; }
        .header-controls { position: relative; }
        .icon-btn, .avatar-btn { background: #f1f5f9; border: none; color: #475569; padding: 0; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .icon-btn:hover, .avatar-btn:hover { background: #e2e8f0; color: #1e293b; }
        .icon-btn { position: relative; width: 40px; height: 40px; }
        .icon-btn svg, .avatar-btn svg { width: 22px; height: 22px; }
        .notif-badge { position: absolute; top: 8px; right: 8px; background: #ef4444; color: white; font-size: 12px; padding: 4px 8px; border-radius: 999px; line-height: 1; }
        .avatar-btn { width: 40px; height: 40px; padding: 0; border-radius: 10px; }
        .notif-dropdown, .profile-dropdown { position: absolute; right: 0; top: 48px; background: white; color: #0b1720; min-width: 180px; border-radius: 6px; box-shadow: 0 8px 20px rgba(2,6,23,0.12); border: 1px solid #e6eef8; display: none; z-index: 1200; padding: 8px 0; }
        .notif-item { padding: 10px 12px; font-size: 0.95rem; color: #475569; }
        .profile-link { display: flex; align-items: center; gap: 8px; padding: 10px 12px; text-decoration: none; color: #0b1720; }
        .profile-link svg { width: 16px; height: 16px; flex-shrink: 0; }
        .profile-link:hover { background: #f1f5f9; }
        /* Documents section */
        .documents-card { background: #fff; border: 2px solid #0b0b0b; border-radius: 4px; padding: 1.5rem; }
        .documents-title { font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; font-size: 1.05rem; color: #0b1720; margin: 0 0 1rem 0; }
        .documents-tools { display: grid; grid-template-columns: 1.4fr 1fr 1fr auto auto; gap: 12px; margin-bottom: 16px; }
        .documents-tools input { height: 42px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0 12px; font-size: 14px; color: #1e293b; background: #fff; outline: none; }
        .documents-tools input:focus { border-color: #1e3a5f; box-shadow: 0 0 0 3px rgba(30,58,95,0.15); }
        .documents-btn { height: 42px; border: none; border-radius: 10px; padding: 0 16px; background: #1e3a5f; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .documents-btn:hover { background: #2d4a6f; }
        .documents-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
        .documents-btn-secondary { background: #64748b; }
        .documents-btn-secondary:hover { background: #475569; }
        .documents-table-frame { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; overflow: hidden; margin-top: 1rem; }
        .documents-table { width: 100%; border-collapse: collapse; }
        .documents-table thead th { text-align: left; padding: 14px 16px; font-size: 13px; font-weight: 600; letter-spacing: 0.03em; color: #475569; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .documents-table tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 14px; }
        .documents-empty { text-align: center; height: 200px; color: #64748b; vertical-align: middle; }
        @media (max-width: 980px) { .documents-tools { grid-template-columns: 1fr 1fr; } }
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
                        <small>Municipal Document Management System – Documents</small>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="header-controls">
                            <button class="icon-btn" id="notif-btn" aria-label="Notifications" title="Notifications">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0 1 18 14.158V11a6 6 0 1 0-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                                <span class="notif-badge" id="notif-count" aria-hidden="true">3</span>
                            </button>
                            <div class="notif-dropdown" id="notif-dropdown" aria-hidden="true">
                                <div class="notif-item">No new notifications</div>
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

            <div class="content-body">
                <section class="documents-card">
                    <h2 class="documents-title">Documents</h2>
                    <div class="documents-tools">
                        <input type="text" placeholder="Search by code or title" aria-label="Search">
                        <input type="date" aria-label="From date">
                        <input type="date" aria-label="To date">
                        <button type="button" class="documents-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>Add Document</button>
                        <button type="button" class="documents-btn documents-btn-secondary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Edit</button>
                    </div>

                    <div class="documents-table-frame">
                        <table class="documents-table">
                            <thead>
                                <tr>
                                    <th>NO.</th>
                                    <th>DOCUMENT CODE</th>
                                    <th>DOCUMENT TITLE</th>
                                    <th>DOCX FILE</th>
                                    <th>ACTION</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="5" class="documents-empty">No documents yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>
    <?php include __DIR__ . '/_profile_modal_super_admin.php'; ?>
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
</body>
</html>
