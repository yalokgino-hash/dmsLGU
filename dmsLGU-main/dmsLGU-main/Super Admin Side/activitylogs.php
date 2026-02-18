<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'Super Admin';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Activity Logs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Admin design – header & sidebar */
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
        .dashboard-container { display: flex; min-height: 100vh; border-top: 3px solid #D4AF37; }
        .sidebar { width: 260px; height: 100vh; position: fixed; left: 0; top: 0; z-index: 100; background: #1b1548; color: #b8d4ee; display: flex; flex-direction: column; box-shadow: 2px 0 12px rgba(0,0,0,0.08); border-right: 1px solid rgba(255, 255, 255, 0.08); }
        .sidebar-header { padding: 1.2rem 1rem 1rem 1rem; border-bottom: 1px solid #1e2f46; display: flex; flex-direction: column; align-items: center; gap: 0.25rem; }
        .sidebar-header .sidebar-title { text-align: center; }
        .sidebar-logo img { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; padding: 6px; }
        .sidebar-header .sidebar-title h2 { margin: 0; font-size: 1.2rem; font-weight: 700; letter-spacing: 0.8px; color: #ffffff; line-height: 1.2; text-transform: uppercase; text-shadow: 0 2px 6px rgba(0,0,0,0.35); }
        .sidebar-header .sidebar-title h2 span { font-size: 0.7rem; font-weight: 600; display: block; color: #e6f0ff; margin-top: 4px; letter-spacing: 0.4px; white-space: nowrap; }
        .sidebar-nav { flex: 1; padding: 1rem 0 1rem 0.5rem; }
        .sidebar-nav ul { list-style: none; padding: 0; margin: 0; }
        .sidebar-nav li { margin: 0.35rem 0; }
        .sidebar-nav a { display: flex; align-items: center; gap: 6px; padding: 0.6rem 0.75rem; color: #b8d4ee; text-decoration: none; font-size: 0.98rem; font-weight: 450; border-left: 4px solid transparent; transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease; letter-spacing: 0.03em; }
        .sidebar-nav a:hover { background: rgba(255, 255, 255, 0.05); border-left-color: #D4AF37; color: #fff; }
        .sidebar-nav li:nth-child(6) a { border-left-color: #D4AF37; background: rgba(212, 175, 55, 0.12); color: #D4AF37; }
        .sidebar-nav a .nav-icon { width: 24px; height: 24px; flex-shrink: 0; display: block; }
        .sidebar-nav a .nav-icon-placeholder { width: 24px; height: 24px; flex-shrink: 0; display: block; }
        .main-content { flex: 1; margin-left: 260px; padding: 0; background: #f8fafc; overflow-x: auto; display: flex; flex-direction: column; }
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
        .profile-modal-overlay { position: fixed; inset: 0; background: rgba(27, 21, 72, 0.5); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 1rem; }
        .profile-modal-overlay.profile-modal-open { display: flex; }
        .profile-modal { background: #fff; border-radius: 16px; box-shadow: 0 24px 48px rgba(27, 21, 72, 0.2); border: 2px solid #D4AF37; max-width: 380px; width: 100%; overflow: hidden; }
        .profile-modal-header { background: linear-gradient(135deg, #1b1548 0%, #2a2160 100%); padding: 1.75rem 1.5rem; text-align: center; border-bottom: 3px solid #D4AF37; }
        .profile-modal-title { color: #fff; font-size: 1.35rem; font-weight: 700; margin: 0; letter-spacing: 0.02em; }
        .profile-modal-avatar { width: 96px; height: 96px; border-radius: 50%; margin: 0 auto 1rem; background: #D4AF37; color: #1b1548; font-size: 2.5rem; font-weight: 800; display: flex; align-items: center; justify-content: center; border: 3px solid rgba(255,255,255,0.4); }
        .profile-modal-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .profile-modal-body { padding: 1.5rem 1.5rem 1.25rem; }
        .profile-modal-row { display: flex; align-items: center; gap: 12px; padding: 0.75rem 0; border-bottom: 1px solid #e2e8f0; }
        .profile-modal-row:last-of-type { border-bottom: none; }
        .profile-modal-label { font-size: 0.8rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; min-width: 72px; }
        .profile-modal-value { font-size: 0.95rem; color: #1e293b; font-weight: 500; }
        .profile-modal-actions { display: flex; gap: 10px; padding: 1rem 1.5rem; background: #f8fafc; border-top: 1px solid #e2e8f0; }
        .profile-modal-btn { flex: 1; padding: 12px 16px; border: none; border-radius: 10px; font-size: 0.95rem; font-weight: 600; cursor: pointer; text-align: center; text-decoration: none; display: inline-block; }
        .profile-modal-btn-close { background: #e2e8f0; color: #475569; }
        .profile-modal-btn-close:hover { background: #cbd5e1; }
        .profile-modal-btn-logout { background: #1b1548; color: #fff; }
        .profile-modal-btn-logout:hover { background: #2a2160; color: #fff; }
        /* Activity logs section */
        .logs-card { background: #fff; border: 2px solid #0b0b0b; border-radius: 4px; padding: 1.5rem; }
        .logs-title { font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; font-size: 1.05rem; color: #0b1720; margin: 0 0 1rem 0; }
        .logs-tools { display: grid; grid-template-columns: 1.4fr 1fr 1fr auto; gap: 12px; margin-bottom: 16px; }
        .logs-tools input { height: 42px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0 12px; font-size: 14px; color: #1e293b; background: #fff; outline: none; }
        .logs-tools input:focus { border-color: #1e3a5f; box-shadow: 0 0 0 3px rgba(30,58,95,0.15); }
        .logs-btn { height: 42px; border: none; border-radius: 10px; padding: 0 16px; background: #1e3a5f; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .logs-btn:hover { background: #2d4a6f; }
        .logs-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
        .logs-table-frame { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; overflow: hidden; margin-top: 1rem; }
        .logs-table { width: 100%; border-collapse: collapse; }
        .logs-table thead th { text-align: left; padding: 14px 16px; font-size: 13px; font-weight: 600; letter-spacing: 0.03em; color: #475569; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .logs-table tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 14px; }
        .logs-empty { text-align: center; height: 200px; color: #64748b; vertical-align: middle; }
        @media (max-width: 980px) { .logs-tools { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="../img/logo.png" alt="Municipal Logo">
                </div>
                <div class="sidebar-title">
                    <h2>LGU SOLANO<br><span>SUPER ADMIN DASHBOARD</span></h2>
                </div>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="dashboard.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5L12 4l9 7.5"/><path d="M5 10.5v8a1 1 0 0 0 1 1h3v-6h6v6h3a1 1 0 0 0 1-1v-8"/></svg>DASHBOARD</a></li>
                    <li><a href="offices-department.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/></svg>OFFICES/DEPARTMENT</a></li>
                    <li><a href="users.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>USERS</a></li>
                    <li><a href="documents.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>DOCUMENTS</a></li>
                    <li><a href="document-history.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>DOCUMENTS HISTORY</a></li>
                    <li><a href="activitylogs.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>ACTIVITY LOGS</a></li>
                    <li><a href="archived.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>ARCHIVED</a></li>
                </ul>
            </nav>
            <div style="padding: 1.2rem 1.5rem; font-size: 0.75rem; color: #6f8aa1; border-top: 1px solid #1e2f46;">
                DMS · LGU super admin
            </div>
        </div>

        <div class="main-content">
            <div class="content-header">
                <div class="dashboard-header">
                    <div>
                        <h1>Welcome, <?php echo htmlspecialchars($userName); ?>!</h1>
                        <small>Municipal Document Management System – Activity Logs</small>
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
                <section class="logs-card">
                    <h2 class="logs-title">Activity Logs</h2>
                    <div class="logs-tools">
                        <input type="text" placeholder="Search by user or action" aria-label="Search">
                        <input type="date" aria-label="From date">
                        <input type="date" aria-label="To date">
                        <button type="button" class="logs-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>Search</button>
                    </div>

                    <div class="logs-table-frame">
                        <table class="logs-table">
                            <thead>
                                <tr>
                                    <th>NO.</th>
                                    <th>USER</th>
                                    <th>ACTION</th>
                                    <th>DATE/TIME</th>
                                    <th>IP ADDRESS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="5" class="logs-empty">No activity logs yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>
    <div class="profile-modal-overlay" id="profile-modal-overlay" aria-hidden="true">
        <div class="profile-modal" id="profile-modal" role="dialog" aria-labelledby="profile-modal-title">
            <div class="profile-modal-header">
                <div class="profile-modal-avatar"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitial); ?><?php endif; ?></div>
                <h2 class="profile-modal-title" id="profile-modal-title"><?php echo htmlspecialchars($userName); ?></h2>
            </div>
            <div class="profile-modal-body">
                <div class="profile-modal-row"><span class="profile-modal-label">Username</span><span class="profile-modal-value"><?php echo htmlspecialchars($userName); ?></span></div>
                <div class="profile-modal-row"><span class="profile-modal-label">Email</span><span class="profile-modal-value"><?php echo htmlspecialchars($userEmail); ?></span></div>
                <div class="profile-modal-row"><span class="profile-modal-label">Role</span><span class="profile-modal-value"><?php echo htmlspecialchars($userRole); ?></span></div>
            </div>
            <div class="profile-modal-actions">
                <button type="button" class="profile-modal-btn profile-modal-btn-close" id="profile-modal-close">Close</button>
                <a href="../index.php?logout=1" class="profile-modal-btn profile-modal-btn-logout">Log out</a>
            </div>
        </div>
    </div>
<script>
(function(){
    var notifBtn = document.getElementById('notif-btn');
    var notifDropdown = document.getElementById('notif-dropdown');
    function closeNotif(){ if (notifDropdown) notifDropdown.style.display = 'none'; }
    if (notifBtn) notifBtn.addEventListener('click', function(e){ e.stopPropagation(); if (!notifDropdown) return; var showing = notifDropdown.style.display === 'block'; closeNotif(); notifDropdown.style.display = showing ? 'none' : 'block'; });
    document.addEventListener('click', function(){ closeNotif(); });
})();
(function(){
    var profileBtn = document.getElementById('profile-btn');
    var overlay = document.getElementById('profile-modal-overlay');
    var closeBtn = document.getElementById('profile-modal-close');
    function openModal(){ if (overlay) { overlay.classList.add('profile-modal-open'); overlay.setAttribute('aria-hidden', 'false'); } }
    function closeModal(){ if (overlay) { overlay.classList.remove('profile-modal-open'); overlay.setAttribute('aria-hidden', 'true'); } }
    if (profileBtn) profileBtn.addEventListener('click', function(e){ e.stopPropagation(); openModal(); });
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (overlay) overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
})();
</script>
</body>
</html>
