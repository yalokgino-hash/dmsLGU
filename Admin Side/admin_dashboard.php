<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin-dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
                <a href="admin_dashboard.php" class="sidebar-link active" data-section="home">
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
                <a href="document_history.php" class="sidebar-link" data-section="history">
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
                            <h1 class="admin-content-title" id="admin-content-title">Admin Dashboard</h1>
                            <p class="admin-content-subtitle">Welcome to the administrative control panel for managing documents and system operations</p>
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
            <div class="admin-content-body" id="admin-content-body">
                    <div class="dashboard-welcome-card">
                        <div class="dashboard-welcome-header">
                            <div class="dashboard-welcome-text">
                                <h2 class="dashboard-welcome-title">Welcome, <?php echo htmlspecialchars($userName); ?>!</h2>
                                <p class="dashboard-welcome-quote">"Lead with clarity, manage with confidence."</p>
                            </div>
                        </div>
                        <div class="dashboard-datetime" id="dashboard-datetime">Feb 13, 2026, 8:51 AM</div>
                    </div>

                    <div class="dashboard-quick-actions">
                        <h3 class="quick-actions-title">
                            <svg class="quick-actions-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            Quick Actions
                        </h3>
                        <div class="quick-actions-buttons">
                            <a href="documents.php" class="quick-action-btn quick-action-add">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                                Add Document
                            </a>
                            <a href="document_history.php" class="quick-action-btn quick-action-logs">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                                View Logs
                            </a>
                            <a href="admin_offices.php" class="quick-action-btn quick-action-offices">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
                                Office Codes
                            </a>
                            <a href="documents.php" class="quick-action-btn quick-action-property">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                                Documents
                            </a>
                        </div>
                    </div>

                    <div class="charts-dashboard" id="charts-dashboard">
                        <div class="chart-card chart-card-wide">
                            <h3 class="chart-title">Document volume over time</h3>
                            <div class="chart-wrap">
                                <canvas id="chart-trend" width="800" height="320"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h3 class="chart-title">Documents by status</h3>
                            <div class="chart-wrap chart-wrap-center">
                                <canvas id="chart-status" width="320" height="320"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h3 class="chart-title">Documents by office</h3>
                            <div class="chart-wrap">
                                <canvas id="chart-offices" width="400" height="280"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
        </main>
    </div>

    <script>
    (function() {
        function updateDateTime() {
            var el = document.getElementById('dashboard-datetime');
            if (el) {
                var now = new Date();
                el.textContent = now.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
            }
        }
        updateDateTime();
        setInterval(updateDateTime, 60000);

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

        var chartInstances = [];

        function renderHomeCharts() {
            if (typeof Chart === 'undefined') {
                console.error('Chart.js is not available');
                return;
            }
            var trendCtx = document.getElementById('chart-trend');
            var statusCtx = document.getElementById('chart-status');
            var officesCtx = document.getElementById('chart-offices');
            if (!trendCtx) console.error('chart-trend canvas not found');
            if (!statusCtx) console.error('chart-status canvas not found');
            if (!officesCtx) console.error('chart-offices canvas not found');
            if (!trendCtx || !statusCtx || !officesCtx) {
                console.error('One or more chart canvases not found');
                return;
            }
            chartInstances.forEach(function(c) { if (c && c.destroy) c.destroy(); });
            chartInstances = [];
            
            try {

            chartInstances.push(new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [{
                        label: 'Documents processed',
                        data: [42, 58, 51, 72, 65, 88],
                        borderColor: '#D4AF37',
                        backgroundColor: 'rgba(212, 175, 55, 0.15)',
                        borderWidth: 3,
                        pointBackgroundColor: '#D4AF37',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    aspectRatio: 2.5,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0b1f3a',
                            titleColor: '#fff',
                            bodyColor: '#b8d4ee',
                            borderColor: '#D4AF37',
                            borderWidth: 1,
                            padding: 12,
                            displayColors: false,
                            titleFont: { size: 14, weight: '600' },
                            bodyFont: { size: 13 }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#e2e8f0', drawBorder: false },
                            ticks: { color: '#64748b', font: { size: 12 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#64748b', font: { size: 12, weight: '500' } }
                        }
                    }
                }
            }));

            chartInstances.push(new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Incoming', 'Outgoing', 'Archived', 'Pending'],
                    datasets: [{
                        data: [85, 62, 120, 34],
                        backgroundColor: ['#0b1f3a', '#D4AF37', '#2563eb', '#64748b'],
                        borderWidth: 3,
                        borderColor: '#fff',
                        hoverBorderWidth: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    aspectRatio: 1,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 12,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                font: { size: 12, weight: '500' },
                                color: '#1e293b'
                            }
                        },
                        tooltip: {
                            backgroundColor: '#0b1f3a',
                            titleColor: '#fff',
                            bodyColor: '#b8d4ee',
                            borderColor: '#D4AF37',
                            borderWidth: 1,
                            padding: 12,
                            callbacks: {
                                label: function(context) {
                                    var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    var percentage = ((context.parsed / total) * 100).toFixed(1);
                                    return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                }
                            }
                        }
                    },
                    cutout: '65%'
                }
            }));

            chartInstances.push(new Chart(officesCtx, {
                type: 'bar',
                data: {
                    labels: ['Mayor', 'Treasury', 'Planning', 'Health', 'Engineering'],
                    datasets: [{
                        label: 'Documents',
                        data: [45, 38, 52, 28, 41],
                        backgroundColor: function(context) {
                            var max = Math.max(...context.dataset.data);
                            var value = context.dataset.data[context.dataIndex];
                            if (value === max) return '#D4AF37';
                            return '#0b1f3a';
                        },
                        borderRadius: 8,
                        borderSkipped: false
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    aspectRatio: 1.2,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0b1f3a',
                            titleColor: '#fff',
                            bodyColor: '#b8d4ee',
                            borderColor: '#D4AF37',
                            borderWidth: 1,
                            padding: 12,
                            displayColors: false,
                            titleFont: { size: 14, weight: '600' },
                            bodyFont: { size: 13 }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { color: '#e2e8f0', drawBorder: false },
                            ticks: { color: '#64748b', font: { size: 12 } }
                        },
                        y: {
                            grid: { display: false },
                            ticks: { color: '#64748b', font: { size: 12, weight: '500' } }
                        }
                    }
                }
            }));
            } catch (error) {
                console.error('Error rendering charts:', error);
            }
        }

        document.querySelectorAll('.sidebar-link').forEach(function(link) {
            link.addEventListener('click', function(e) {
                var linkHref = this.getAttribute('href');
                if (linkHref && linkHref !== '#') {
                    return;
                }
                e.preventDefault();
                document.querySelectorAll('.sidebar-link').forEach(function(l) { l.classList.remove('active'); });
                this.classList.add('active');
                var section = this.getAttribute('data-section');
                var titleEl = document.getElementById('admin-content-title');
                var bodyEl = document.getElementById('admin-content-body');
                var labels = { home: 'Admin Dashboard', offices: 'Offices', documents: 'Documents', history: 'Documents History' };
                if (titleEl && section && labels[section]) titleEl.textContent = labels[section];
                if (section === 'home' && bodyEl) {
                    window.location.href = 'admin_dashboard.php';
                } else if (bodyEl) {
                    bodyEl.innerHTML = '';
                }
            });
        });

        // Initialize charts when page loads
        function initCharts() {
            console.log('initCharts called');
            console.log('Chart available:', typeof Chart !== 'undefined');
            
            if (typeof Chart === 'undefined') {
                console.error('Chart.js is not loaded - retrying...');
                setTimeout(initCharts, 100);
                return;
            }
            
            var dashboard = document.getElementById('charts-dashboard');
            console.log('Dashboard element:', dashboard);
            
            if (dashboard) {
                console.log('Calling renderHomeCharts...');
                renderHomeCharts();
                console.log('Charts rendered, instances:', chartInstances.length);
            } else {
                console.error('charts-dashboard element not found');
            }
        }
        
        // Wait for everything to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                console.log('DOMContentLoaded fired');
                setTimeout(initCharts, 500);
            });
        } else {
            console.log('DOM already loaded');
            setTimeout(initCharts, 500);
        }
        
        // Also try on window load
        window.addEventListener('load', function() {
            console.log('Window load fired');
            setTimeout(initCharts, 300);
        });
    })();
    </script>
</body>
</html>
