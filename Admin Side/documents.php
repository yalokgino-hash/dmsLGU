<?php
session_start();

$role = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'staff', 'departmenthead', 'department_head', 'dept_head'])) {
    header('Location: ../index.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'Admin';
$userDepartment = $_SESSION['user_department'] ?? 'Not Assigned';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'documents';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Documents</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin-dashboard.css">
    <link rel="stylesheet" href="admin-offices.css">
    <style>
    body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
    .dashboard-container { display: flex; min-height: 100vh; border-top: 3px solid #D4AF37; }
    .sidebar { width: 260px; height: 100vh; position: fixed; left: 0; top: 0; z-index: 100; background: #1A202C; color: #fff; display: flex; flex-direction: column; box-shadow: 2px 0 12px rgba(0,0,0,0.08); border-right: 1px solid rgba(255, 255, 255, 0.06); }
    .sidebar-header { padding: 1.25rem 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.06); display: flex; flex-direction: row; align-items: center; gap: 0.75rem; text-align: left; }
    .sidebar-logo { flex-shrink: 0; width: 44px; height: 44px; background: #63B3ED; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
    .sidebar-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }
    .sidebar-header .sidebar-title { text-align: left; }
    .sidebar-header .sidebar-title h2 { margin: 0; font-size: 1.1rem; font-weight: 700; color: #fff; line-height: 1.3; text-transform: none; letter-spacing: 0.02em; }
    .sidebar-header .sidebar-title h2 span { font-size: 0.75rem; font-weight: 500; display: block; color: #A0AEC0; margin-top: 2px; letter-spacing: 0.02em; }
    .sidebar-nav { flex: 1; padding: 1rem 0.75rem; overflow-y: auto; }
    .sidebar-nav .nav-section-title { font-size: 0.7rem; font-weight: 600; letter-spacing: 0.08em; color: #718096; padding: 0.75rem 0.75rem 0.35rem; text-transform: uppercase; }
    .sidebar-nav ul { list-style: none; padding: 0; margin: 0; }
    .sidebar-nav li { margin: 0.2rem 0; }
    .sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 0.6rem 0.75rem; color: #fff; text-decoration: none; font-size: 0.95rem; font-weight: 500; border-radius: 8px; transition: background 0.15s ease, color 0.15s ease; letter-spacing: 0.02em; }
    .sidebar-nav a .nav-icon { width: 22px; height: 22px; flex-shrink: 0; color: #A0AEC0; transition: color 0.15s ease; }
    .sidebar-nav a:hover { background: rgba(255, 255, 255, 0.06); color: #fff; }
    .sidebar-nav a:hover .nav-icon { color: #fff; }
    .sidebar-nav a.active { background: #3B82F6; color: #fff; }
    .sidebar-nav a.active .nav-icon { color: #fff; }
    .sidebar-user-wrap { position: relative; padding: 0 1rem 1.25rem 1rem; border-top: 1px solid rgba(255, 255, 255, 0.06); }
    .sidebar-user { padding: 0.75rem; border-radius: 8px; display: flex; align-items: center; gap: 0.75rem; cursor: pointer; transition: background 0.2s ease, transform 0.2s ease; }
    .sidebar-user:hover { background: rgba(255, 255, 255, 0.08); }
    .sidebar-user:active { transform: scale(0.98); }
    .sidebar-user-avatar { width: 40px; height: 40px; border-radius: 50%; background: #63B3ED; color: #fff; font-size: 0.9rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .sidebar-user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
    .sidebar-user-info { min-width: 0; }
    .sidebar-user-name { font-size: 0.95rem; font-weight: 600; color: #fff; margin: 0; }
    .sidebar-user-role { font-size: 0.8rem; color: #A0AEC0; margin: 2px 0 0 0; }
    .account-dropdown { position: absolute; left: 1rem; right: 1rem; bottom: 0; transform: translateY(calc(-100% - 10px)); background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); padding: 6px 0; min-width: 160px; z-index: 1100; display: none; overflow: hidden; }
    .account-dropdown.open { display: block; animation: account-dropdown-in 0.2s ease; }
    @keyframes account-dropdown-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(calc(-100% - 10px)); } }
    .account-dropdown-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 14px; border: none; background: none; color: #1e293b; font-size: 0.9rem; cursor: pointer; text-align: left; text-decoration: none; font-family: inherit; transition: background 0.15s ease, color 0.15s ease; box-sizing: border-box; }
    .account-dropdown-item:hover { background: #f1f5f9; }
    .account-dropdown-item.account-dropdown-profile:hover { background: rgba(59, 130, 246, 0.1); color: #3B82F6; }
    .account-dropdown-item.account-dropdown-profile:hover svg { color: #3B82F6; }
    .account-dropdown-item.account-dropdown-signout:hover { background: #dc2626; color: #fff; }
    .account-dropdown-item.account-dropdown-signout:hover svg { color: #fff; }
    .account-dropdown-item svg { width: 18px; height: 18px; flex-shrink: 0; color: #64748b; transition: color 0.15s ease; }
    .account-dropdown-item:hover svg { color: #3B82F6; }
    .main-content { flex: 1; margin-left: 260px; padding: 0; background: #f8fafc; overflow-x: auto; display: flex; flex-direction: column; }
    .content-header { background: #fff; padding: 1.5rem 2.2rem; border-bottom: 1px solid #e2e8f0; }
    .dashboard-header { display: flex; justify-content: space-between; align-items: center; }
    .header-controls { position: relative; }
    .icon-btn { background: #f1f5f9; border: none; color: #475569; padding: 0; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; position: relative; width: 40px; height: 40px; }
    .icon-btn:hover { background: #e2e8f0; color: #1e293b; }
    .icon-btn svg { width: 22px; height: 22px; }
    .notif-badge { position: absolute; top: 8px; right: 8px; background: #ef4444; color: white; font-size: 12px; padding: 4px 8px; border-radius: 999px; line-height: 1; }
    .notif-dropdown { position: absolute; right: 0; top: 48px; background: white; color: #0b1720; min-width: 180px; border-radius: 6px; box-shadow: 0 8px 20px rgba(2,6,23,0.12); border: 1px solid #e6eef8; display: none; z-index: 1200; padding: 8px 0; }
    .notif-item { padding: 10px 12px; font-size: 0.95rem; color: #475569; }
    .content-body { padding: 2rem 2.2rem; }
    .dept-page-title { margin: 0; font-size: 1.75rem; font-weight: 700; color: #1e293b; }
    .dept-page-subtitle { margin: 0.25rem 0 0 0; font-size: 0.95rem; color: #64748b; }
    /* Documents section – light container to match other admin pages */
    .documents-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .documents-title { font-weight: 700; font-size: 1.15rem; color: #1e293b; margin: 0 0 1rem 0; }
    .documents-tools { display: grid; grid-template-columns: 1.4fr 1fr 1fr auto auto; gap: 12px; margin-bottom: 16px; }
    .documents-tools input { height: 42px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0 12px; font-size: 14px; color: #1e293b; background: #fff; outline: none; font-family: inherit; }
    .documents-tools input:focus { border-color: #1A202C; box-shadow: 0 0 0 3px rgba(26,32,44,0.12); }
    .documents-btn { height: 42px; border: none; border-radius: 10px; padding: 0 16px; background: #1A202C; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-family: inherit; transition: background 0.2s ease; }
    .documents-btn:hover { background: #2d3748; color: #fff; }
    .documents-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
    .documents-btn-secondary { background: #f1f5f9; color: #475569; }
    .documents-btn-secondary:hover { background: #e2e8f0; color: #1e293b; }
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
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="../img/logo.png" alt="LGU Solano">
                </div>
                <div class="sidebar-title">
                    <h2>LGU Solano<span>Document Management</span></h2>
                </div>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-section-title">Main Menu</div>
                <ul>
                    <li><a href="admin_dashboard.php" class="<?php echo $sidebar_active === 'dashboard' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard</a></li>
                    <li><a href="documents.php" class="<?php echo $sidebar_active === 'documents' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>Documents</a></li>
                    <li><a href="admin_offices.php" class="<?php echo $sidebar_active === 'offices' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/></svg>Departments</a></li>
                    <li><a href="document_history.php" class="<?php echo $sidebar_active === 'document-history' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Document History</a></li>
                </ul>
                <div class="nav-section-title">Account</div>
                <ul>
                    <li><a href="admin_settings.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Settings</a></li>
                </ul>
            </nav>
            <div class="sidebar-user-wrap">
                <div class="sidebar-user" id="sidebar-account-btn" role="button" tabindex="0" aria-label="Account menu" aria-haspopup="true" aria-expanded="false">
                    <div class="sidebar-user-avatar"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitial); ?><?php endif; ?></div>
                    <div class="sidebar-user-info">
                        <p class="sidebar-user-name"><?php echo htmlspecialchars($userName); ?></p>
                        <p class="sidebar-user-role"><?php echo htmlspecialchars($userRole); ?></p>
                    </div>
                </div>
                <div class="account-dropdown" id="account-dropdown" role="menu" aria-label="Account menu">
                    <button type="button" class="account-dropdown-item account-dropdown-profile" id="account-dropdown-profile" role="menuitem"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Profile</button>
                    <a href="../index.php?logout=1" class="account-dropdown-item account-dropdown-signout" role="menuitem"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sign Out</a>
                </div>
            </div>
        </aside>

        <div class="main-content">
            <div class="content-header">
                <div class="dashboard-header">
                    <div class="dept-page-header" style="flex: 1; margin-bottom: 0;">
                        <div>
                            <h1 class="dept-page-title">Documents</h1>
                            <p class="dept-page-subtitle">Create, track, and manage municipal documents across all departments</p>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <div class="header-controls">
                            <button class="icon-btn" id="notif-btn" aria-label="Notifications" title="Notifications">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0 1 18 14.158V11a6 6 0 1 0-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                                <span class="notif-badge" id="notif-count" aria-hidden="true">3</span>
                            </button>
                            <div class="notif-dropdown" id="notif-dropdown" aria-hidden="true">
                                <div class="notif-item">No new notifications</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="content-body">
                <section class="documents-card">
                    <h2 class="documents-title">Documents</h2>
                    <div class="documents-tools">
                        <input type="text" id="search-documents" placeholder="Search by code or title" aria-label="Search by code or title">
                        <input type="date" id="documents-date-from" aria-label="From date">
                        <input type="date" id="documents-date-to" aria-label="To date">
                        <button type="button" class="documents-btn" id="open-add-document-modal">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                            Add Document
                        </button>
                        <button type="button" class="documents-btn documents-btn-secondary" id="edit-document-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                            Edit
                        </button>
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
                            <tbody id="documents-table-body">
                                <tr>
                                    <td colspan="5" class="documents-empty" id="no-documents-row">No documents yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <div class="doc-modal" id="add-document-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-add-document aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="add-document-title">
            <div class="doc-modal-header">
                <h2 id="add-document-title">Add Document</h2>
                <button type="button" class="doc-modal-close" data-close-add-document aria-label="Close">&times;</button>
            </div>
            <form id="add-document-form" class="doc-modal-form">
                <div class="doc-form-field">
                    <label for="document-code">Document Code</label>
                    <input type="text" id="document-code" name="document_code" placeholder="e.g. DOC-001" required>
                </div>
                <div class="doc-form-field">
                    <label for="document-title">Document Title</label>
                    <input type="text" id="document-title" name="document_title" placeholder="Enter document title" required>
                </div>
                <div class="doc-form-field">
                    <label for="document-file">DOCX File</label>
                    <input type="file" id="document-file" name="document_file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                </div>
                <p class="doc-form-error" id="document-form-error" hidden></p>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-add-document>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Save Document</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function() {
        var accountBtn = document.getElementById('sidebar-account-btn');
        var accountDropdown = document.getElementById('account-dropdown');
        function closeAccountDropdown() {
            if (accountDropdown) {
                accountDropdown.classList.remove('open');
                if (accountBtn) accountBtn.setAttribute('aria-expanded', 'false');
            }
        }
        if (accountBtn && accountDropdown) {
            accountBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                accountDropdown.classList.toggle('open');
                accountBtn.setAttribute('aria-expanded', accountDropdown.classList.contains('open'));
            });
            accountBtn.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    accountDropdown.classList.add('open');
                    accountBtn.setAttribute('aria-expanded', 'true');
                }
            });
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.sidebar-user-wrap')) closeAccountDropdown();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeAccountDropdown();
            });
        }

        var notifBtn = document.getElementById('notif-btn');
        var notifDropdown = document.getElementById('notif-dropdown');
        function closeNotif() {
            if (notifDropdown) notifDropdown.style.display = 'none';
        }
        if (notifBtn) {
            notifBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (!notifDropdown) return;
                var showing = notifDropdown.style.display === 'block';
                closeNotif();
                notifDropdown.style.display = showing ? 'none' : 'block';
            });
            document.addEventListener('click', function() { closeNotif(); });
        }

        var openAddModalBtn = document.getElementById('open-add-document-modal');
        var addModal = document.getElementById('add-document-modal');
        var addForm = document.getElementById('add-document-form');
        var errorEl = document.getElementById('document-form-error');
        var documentsTableBody = document.getElementById('documents-table-body');
        var editBtn = document.getElementById('edit-document-btn');

        function setFormError(message) {
            if (!errorEl) return;
            if (!message) {
                errorEl.hidden = true;
                errorEl.textContent = '';
                return;
            }
            errorEl.hidden = false;
            errorEl.textContent = message;
        }

        function openAddDocumentModal() {
            if (!addModal) return;
            addModal.hidden = false;
            document.body.classList.add('modal-open');
            setFormError('');
        }

        function closeAddDocumentModal() {
            if (!addModal) return;
            addModal.hidden = true;
            document.body.classList.remove('modal-open');
            setFormError('');
            if (addForm) addForm.reset();
        }

        if (openAddModalBtn) {
            openAddModalBtn.addEventListener('click', openAddDocumentModal);
        }

        document.querySelectorAll('[data-close-add-document]').forEach(function(closeBtn) {
            closeBtn.addEventListener('click', closeAddDocumentModal);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && addModal && !addModal.hidden) {
                closeAddDocumentModal();
            }
        });

        if (editBtn) {
            editBtn.addEventListener('click', function() {
                alert('Select a document row to edit. (Edit function can be added next.)');
            });
        }

        if (addForm && documentsTableBody) {
            addForm.addEventListener('submit', function(e) {
                e.preventDefault();

                var codeInput = document.getElementById('document-code');
                var titleInput = document.getElementById('document-title');
                var fileInput = document.getElementById('document-file');

                var documentCode = (codeInput && codeInput.value || '').trim();
                var documentTitle = (titleInput && titleInput.value || '').trim();
                var selectedFile = fileInput && fileInput.files ? fileInput.files[0] : null;

                if (!documentCode || !documentTitle || !selectedFile) {
                    setFormError('Please complete all required fields.');
                    return;
                }

                if (!selectedFile.name.toLowerCase().endsWith('.docx')) {
                    setFormError('Only .docx files are allowed.');
                    return;
                }

                var emptyRow = document.getElementById('no-documents-row');
                if (emptyRow) emptyRow.remove();

                var rowNumber = documentsTableBody.querySelectorAll('tr[data-document-row]').length + 1;

                var row = document.createElement('tr');
                row.setAttribute('data-document-row', 'true');

                var numberCell = document.createElement('td');
                numberCell.textContent = String(rowNumber);
                row.appendChild(numberCell);

                var codeCell = document.createElement('td');
                codeCell.textContent = documentCode;
                row.appendChild(codeCell);

                var titleCell = document.createElement('td');
                titleCell.textContent = documentTitle;
                row.appendChild(titleCell);

                var fileCell = document.createElement('td');
                var fileLink = document.createElement('a');
                fileLink.className = 'doc-file-link';
                fileLink.textContent = selectedFile.name;
                try {
                    fileLink.href = URL.createObjectURL(selectedFile);
                    fileLink.download = selectedFile.name;
                } catch (err) {
                    fileLink.href = '#';
                }
                fileCell.appendChild(fileLink);
                row.appendChild(fileCell);

                var actionCell = document.createElement('td');
                actionCell.textContent = '—';
                row.appendChild(actionCell);

                documentsTableBody.appendChild(row);
                closeAddDocumentModal();
            });
        }
    })();
    </script>
</body>
</html>
