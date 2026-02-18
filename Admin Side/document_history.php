<?php
session_start();

$role = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'staff', 'departmenthead', 'department_head', 'dept_head'])) {
    header('Location: ../index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Documents History</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin-offices.css">
</head>
<body class="admin-dashboard">
    <div class="admin-body">
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="../img/logo.png" alt="Municipal Logo">
                </div>
                <div class="sidebar-title">
                    <h2>LGU SOLANO<br><span>ADMIN DASHBOARD</span></h2>
                </div>
            </div>
            <nav class="sidebar-nav">
                <a href="admin_dashboard.php" class="sidebar-link" data-section="home">
                    <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
                    Home
                </a>
                <a href="admin_offices.php" class="sidebar-link" data-section="offices">
                    <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
                    Offices
                </a>
                <a href="documents.php" class="sidebar-link" data-section="documents">
                    <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                    Documents
                </a>
                <a href="document_history.php" class="sidebar-link active" data-section="history">
                    <svg class="sidebar-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l2 2"/><circle cx="12" cy="12" r="10"/></svg>
                    Documents History
                </a>
            </nav>
        </aside>

        <main class="admin-main" style="background:#fff;">
            <div class="admin-content" id="admin-content" style="background:#fff; color:#1e293b;">
                <div class="admin-content-header-row">
                    <header class="admin-content-header">
                        <div class="admin-header-icon">
                            <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            </svg>
                        </div>
                        <div class="admin-header-text">
                            <h1 class="admin-content-title">Documents History</h1>
                            <p class="admin-content-subtitle">View and track document activity, changes, and audit history</p>
                        </div>
                    </header>
                    <div class="admin-content-icons">
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

                <div class="admin-content-body">
                    <section class="chart-card chart-card-wide offices-card">
                        <div class="offices-tools doc-filter-row">
                            <input type="text" id="search-history" placeholder="Search" aria-label="Search history">
                            <input type="date" aria-label="From date">
                            <input type="date" aria-label="To date">
                            <button type="button" class="offices-btn offices-btn-secondary" id="export-history-btn">
                                <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Export
                            </button>
                        </div>

                        <div class="offices-table-frame">
                            <table class="offices-table">
                                <thead>
                                    <tr>
                                        <th>NO.</th>
                                        <th>DATE & TIME</th>
                                        <th>ACTION</th>
                                        <th>DOCUMENT CODE</th>
                                        <th>DOCUMENT TITLE</th>
                                        <th>USER</th>
                                    </tr>
                                </thead>
                                <tbody id="history-table-body">
                                    <tr>
                                        <td colspan="6" class="offices-empty" id="no-history-row">No history yet.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>

    <script>
    (function() {
        var btn = document.getElementById('profile-logout-btn');
        var dropdown = document.getElementById('profile-dropdown');
        if (btn && dropdown) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var open = dropdown.hidden;
                dropdown.hidden = !open;
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function() {
                dropdown.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            });
            dropdown.addEventListener('click', function(e) { e.stopPropagation(); });
        }

        var exportBtn = document.getElementById('export-history-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', function() {
                alert('Export history. (Export function can be wired to backend later.)');
            });
        }

        var searchInput = document.getElementById('search-history');
        var historyTableBody = document.getElementById('history-table-body');
        var noHistoryRow = document.getElementById('no-history-row');

        function filterHistory() {
            var query = (searchInput && searchInput.value || '').trim().toLowerCase();
            var dataRows = historyTableBody ? historyTableBody.querySelectorAll('tr[data-history-row]') : [];
            var hasDataRows = dataRows.length > 0;

            if (!hasDataRows) {
                if (noHistoryRow) noHistoryRow.style.display = '';
                return;
            }

            var visibleCount = 0;
            dataRows.forEach(function(row) {
                var text = row.textContent || '';
                var match = !query || text.toLowerCase().indexOf(query) !== -1;
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });

            if (noHistoryRow) {
                noHistoryRow.style.display = visibleCount === 0 ? '' : 'none';
                noHistoryRow.textContent = visibleCount === 0 && query ? 'No matching results.' : 'No history yet.';
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterHistory);
            searchInput.addEventListener('keyup', filterHistory);
        }
    })();
    </script>
</body>
</html>
