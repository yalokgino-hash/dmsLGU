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
    <title>DMS LGU – Super Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        /* global & layout */
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            background: #f8fafc;
            color: #0f172a;
        }
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            border-top: 3px solid #D4AF37;
        }

        /* ---------- SIDEBAR (fixed nav) ---------- */
        .sidebar {
            width: 260px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 100;
            background: #1b1548;
            color: #b8d4ee;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 12px rgba(0,0,0,0.08);
            flex-shrink: 0;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }
        .sidebar-header {
            padding: 1.2rem 1rem 1rem 1rem;
            border-bottom: 1px solid #1e2f46;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
        }
        .sidebar-logo img{
            width:80px;
            height:80px;
            object-fit:cover;
            border-radius:8px;
            padding:6px;
        }
        .sidebar-header .sidebar-title {
            text-align: center;
        }
        .sidebar-header .sidebar-title h2 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 0.8px;
            color: #ffffff;
            line-height: 1.2;
            text-transform: uppercase;
            text-shadow: 0 2px 6px rgba(0,0,0,0.35);
        }
        .sidebar-header .sidebar-title h2 span {
            font-size: 0.7rem;
            font-weight: 600;
            display: block;
            color: #e6f0ff;
            margin-top: 4px;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }
        .sidebar-nav {
            flex: 1;
            padding: 1rem 0 1rem 0.5rem;
        }
        .sidebar-nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar-nav li {
            margin: 0.35rem 0;
        }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 0.6rem 0.75rem;
            color: #b8d4ee;
            text-decoration: none;
            font-size: 0.98rem;
            font-weight: 450;
            border-left: 4px solid transparent;
            transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
            letter-spacing: 0.03em;
        }
        .sidebar-nav a:hover {
            background: rgba(255, 255, 255, 0.05);
            border-left-color: #D4AF37;
            color: #fff;
        }
        .sidebar-nav a .nav-icon{
            width: 24px;
            height: 24px;
            flex-shrink: 0;
            display: block;
        }
        .sidebar-nav a .nav-icon-placeholder{
            width: 24px;
            height: 24px;
            flex-shrink: 0;
            display: block;
        }
        .sidebar-nav li:first-child a {
            border-left-color: #D4AF37;
            background: rgba(212, 175, 55, 0.12);
            color: #D4AF37;
        }
        /* ----- main content area (right side) ----- */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 0;
            background: #f8fafc;
            overflow-x: auto;
            display: flex;
            flex-direction: column;
        }
        
        /* HEADER (Admin design – white) */
        .content-header {
            background: #fff;
            padding: 1.5rem 2.2rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dashboard-header h1 {
            font-size: 1.6rem;
            margin: 0 0 0.2rem 0;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dashboard-header h1 .welcome-icon {
            flex-shrink: 0;
            color: #1b1548;
        }
        .dashboard-header small {
            display: block;
            color: #64748b;
            font-size: 0.95rem;
            margin-top: 6px;
        }
        .badge-user {
            background: rgba(255,255,255,0.15);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .logout-link {
            font-size: 0.9rem;
            color: #fecaca;  /* Light red for better visibility on dark */
            text-decoration: none;
            font-weight: 500;
            margin-left: 0.75rem;
        }
        .logout-link:hover {
            text-decoration: underline;
            color: white;
        }
        
        .content-body {
            padding: 1rem 2.2rem 2rem 2.2rem;
        }
        
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.6rem;
            margin-top: 0;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 1.6rem 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            border: 1px solid #f1f5f9;
            transition: transform 0.1s ease;
        }
        .card:hover {
            border-color: #dbeafe;
            box-shadow: 0 8px 18px rgba(0,0,0,0.04);
        }
        .card h2 {
            font-size: 1.2rem;
            margin-top: 0;
            margin-bottom: 0.6rem;
            color: #0b1f33;
            font-weight: 600;
        }
        .card p {
            font-size: 0.92rem;
            color: #475569;
            margin-bottom: 1rem;
            line-height: 1.5;
        }
        .card a {
            font-size: 0.85rem;
            color: #2563eb;
            text-decoration: none;
            font-weight: 550;
            display: inline-flex;
            align-items: center;
        }
        .card a:hover {
            text-decoration: underline;
        }
        /* small utilities */
        .attribution {
            margin-top: 2.5rem;
            font-size: 0.75rem;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 1.5rem;
        }
        .spacer {
            margin-top: 1rem;
        }
        /* --- Workspace framed panel (matches attached wireframe) --- */
        .workspace-panel{
            background: #ffffff;
            border: 2px solid #0b0b0b; /* strong thin outline like wireframe */
            border-radius: 4px;
            padding: 1.25rem;
            color: #0b1720;
            max-width: 100%;
        }

        .workspace-header{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:12px;
            margin-bottom: 0.6rem;
        }

        .workspace-title{
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:1px;
            font-size:0.95rem;
        }

        .quick-actions-title{
            margin-top:0.6rem;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:0.8px;
            font-size:1.05rem;
            color:#0b1720;
            margin-bottom:0.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .quick-actions-title .quick-actions-icon{
            flex-shrink: 0;
            color: #1e293b;
        }

        .quick-actions{
            display:flex;
            gap:0.35rem;
            flex-wrap:wrap;
            margin:0.5rem 0 1.25rem 0;
            align-items:center;
        }

        .quick-actions a{
            text-decoration:none;
            color:#0b1720;
            font-weight:700;
            text-transform:uppercase;
            font-size:0.9rem;
            letter-spacing:0.9px;
        }

        /* Quick Actions + Date/Time on same row */
        .quick-actions-row{
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:1rem;
            margin-bottom:1rem;
        }
        .quick-actions-section{ margin-bottom:0; }
        .btn-quick{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:12px 20px;
            border-radius:10px;
            color: #fff !important;
            text-decoration:none;
            font-weight:600;
            font-size:0.95rem;
            letter-spacing:0.05em;
            border: none;
            box-shadow: none;
            transition: opacity 0.15s ease, transform 0.1s ease;
            min-width:70px;
            justify-content:center;
            white-space:nowrap;
        }
        .btn-quick:hover{ opacity: 0.9; transform: translateY(-1px); color: #fff !important; }
        .btn-quick:active{ transform: translateY(0); }
        /* Same colors as admin_dashboard.php quick actions */
        .btn-quick.quick-action-add { background: #2563eb; }
        .btn-quick.quick-action-logs { background: #0d9488; }
        .btn-quick.quick-action-offices { background: #475569; }
        .btn-quick.quick-action-property { background: #ea580c; }
        .btn-quick.quick-action-archived { background: #64748b; }

        .stats-panel{
            border:1px solid #0b0b0b;
            padding:1rem;
            margin-top:1rem;
            border-radius:4px;
            background:transparent;
        }

        .stats-title{
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:1px;
            margin-bottom:0.75rem;
            font-size:0.95rem;
        }

        .months-row{
            display:flex;
            gap:18px;
            flex-wrap:wrap;
            padding:0.6rem 0;
        }

        .months-row span{
            font-weight:700;
            text-transform:uppercase;
            font-size:0.85rem;
            letter-spacing:1px;
        }

        /* Statistics chart panel (Jan-Dec) */
        .stats-chart-panel{
            background:#fff;
            border:2px solid #0b0b0b;
            border-radius:4px;
            padding:1.5rem;
            margin-top:1.5rem;
        }
        .stats-chart-title{
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:0.8px;
            font-size:1.05rem;
            color:#0b1720;
            margin:0 0 1rem 0;
        }
        .stats-chart-wrap{
            position:relative;
            width:100%;
            height:320px;
            min-height:320px;
        }

        /* live date/time (right side of quick actions row) */
        .live-datetime-container{
            display:flex;
            align-items:center;
            gap:12px;
            padding:0;
            color:#0b1720;
            font-weight:700;
            text-transform:uppercase;
            font-size:0.95rem;
        }
        .live-datetime-container span{ background:transparent; padding:4px 8px; border-radius:4px; }

        /* Header controls (Admin design – light gray) */
        .header-controls{ position:relative; }
        .icon-btn, .avatar-btn{
            background: #f1f5f9;
            border: none;
            color: #475569;
            padding: 0;
            border-radius: 10px;
            cursor: pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }
        .icon-btn:hover, .avatar-btn:hover { background: #e2e8f0; color: #1e293b; }
        .icon-btn{ position:relative; width:40px; height:40px; }
        .icon-btn svg, .avatar-btn svg{ width:22px; height:22px; }
        .notif-badge{
            position:absolute;
            top:8px;
            right:8px;
            background:#ef4444;
            color:white;
            font-size:12px;
            padding:4px 8px;
            border-radius:999px;
            line-height:1;
        }

    .avatar-btn{ width:40px; height:40px; padding:0; border-radius:10px; }
    .avatar-initial{ display:inline-block; width:100%; height:100%; border-radius:999px; display:flex; align-items:center; justify-content:center; font-weight:800; color:white; font-size:1.15rem; }

        .notif-dropdown, .profile-dropdown{
            position:absolute;
            right:0;
            top:48px;
            background:white;
            color:#0b1720;
            min-width:180px;
            border-radius:6px;
            box-shadow:0 8px 20px rgba(2,6,23,0.12);
            border:1px solid #e6eef8;
            display:none;
            z-index:1200;
            padding:8px 0;
        }

        .notif-item{ padding:10px 12px; font-size:0.95rem; color:#475569; }
        .profile-link{ display:flex; align-items:center; gap:8px; padding:10px 12px; text-decoration:none; color:#0b1720; }
        .profile-link svg{ width:16px; height:16px; flex-shrink:0; }
        .profile-link:hover{ background:#f1f5f9; }

        /* Profile modal – matches dashboard (sidebar gold + purple) */
        .profile-modal-overlay {
            position: fixed; inset: 0; background: rgba(27, 21, 72, 0.5); z-index: 2000;
            display: none; align-items: center; justify-content: center; padding: 1rem;
        }
        .profile-modal-overlay.profile-modal-open { display: flex; }
        .profile-modal {
            background: #fff; border-radius: 16px; box-shadow: 0 24px 48px rgba(27, 21, 72, 0.2);
            border: 2px solid #D4AF37; max-width: 380px; width: 100%; overflow: hidden;
        }
        .profile-modal-header {
            background: linear-gradient(135deg, #1b1548 0%, #2a2160 100%);
            padding: 1.75rem 1.5rem; text-align: center; border-bottom: 3px solid #D4AF37;
        }
        .profile-modal-avatar {
            width: 96px; height: 96px; border-radius: 50%; margin: 0 auto 1rem;
            background: #D4AF37; color: #1b1548; font-size: 2.5rem; font-weight: 800;
            display: flex; align-items: center; justify-content: center; border: 3px solid rgba(255,255,255,0.4);
        }
        .profile-modal-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .profile-modal-title { color: #fff; font-size: 1.35rem; font-weight: 700; margin: 0; letter-spacing: 0.02em; }
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
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- ========== LEFT SIDEBAR – SUPER ADMIN DASHBOARD MENU ========== -->
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
                    <!-- menu items from the image – verbatim -->
                    <li><a href="dashboard.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 11.5L12 4l9 7.5"/><path d="M5 10.5v8a1 1 0 0 0 1 1h3v-6h6v6h3a1 1 0 0 0 1-1v-8"/></svg>DASHBOARD</a></li>
                    <li><a href="offices-department.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/></svg>OFFICES/DEPARTMENT</a></li>
                    <li><a href="users.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>USERS</a></li>
                    <li><a href="documents.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>DOCUMENTS</a></li>
                    <li><a href="document-history.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>DOCUMENTS HISTORY</a></li>
                    <li><a href="activitylogs.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>ACTIVITY LOGS</a></li>
                    <li><a href="archived.php"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>ARCHIVED</a></li>
                </ul>
            </nav>
            <!-- subtle footer in sidebar (optional, but keeps it tidy) -->
            <div style="padding: 1.2rem 1.5rem; font-size: 0.75rem; color: #6f8aa1; border-top: 1px solid #1e2f46;">
                DMS · LGU super admin
            </div>
        </div>

        <!-- ========== RIGHT CONTENT: WELCOME & CARDS ========== -->
        <div class="main-content">
            <!-- HEADER NOW MATCHES SIDEBAR COLOR EXACTLY -->
            <div class="content-header">
                <div class="dashboard-header">
                    <div>
                        <h1>
                            <svg class="welcome-icon" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            Welcome, <?php echo htmlspecialchars($userName); ?>!
                        </h1>
                        <small>Municipal Document Management System – Super Admin Dashboard</small>
                    </div>
                    <div style="display: flex; align-items: center; gap:12px;">
                        <!-- Notification bell -->
                        <div class="header-controls">
                            <button class="icon-btn" id="notif-btn" aria-label="Notifications" title="Notifications">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0 1 18 14.158V11a6 6 0 1 0-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                                <span class="notif-badge" id="notif-count" aria-hidden="true">3</span>
                            </button>
                            <div class="notif-dropdown" id="notif-dropdown" aria-hidden="true">
                                <div class="notif-item">No new notifications</div>
                            </div>
                        </div>

                        <!-- Profile avatar – opens modal -->
                        <div class="header-controls">
                            <button class="avatar-btn" id="profile-btn" aria-label="Profile" title="Profile">
                                <svg class="avatar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4z" />
                                    <path d="M6 20v-1c0-2.21 3.58-4 6-4s6 1.79 6 4v1" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="content-body">
                <!-- Quick Actions + Date/Time on same row -->
                <div class="quick-actions-row">
                    <div class="quick-actions-section">
                        <div class="quick-actions-title">
                            <svg class="quick-actions-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            Quick Actions:
                        </div>
                        <div class="quick-actions" role="navigation" aria-label="Quick actions">
                        <a href="offices-department.php" class="btn-quick quick-action-offices">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/></svg>
                        </a>
                        <a href="users.php" class="btn-quick quick-action-add">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            USERS/ACCOUNT
                        </a>
                        <a href="documents.php" class="btn-quick quick-action-property">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            DOCUMENTS
                        </a>
                        <a href="document-history.php" class="btn-quick quick-action-logs">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            DOCUMENT HISTORY
                        </a>
                        <a href="activitylogs.php" class="btn-quick quick-action-logs">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            LOGS
                        </a>
                        <a href="archived.php" class="btn-quick quick-action-archived">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                            ARCHIVED
                        </a>
                    </div>
                    </div>
                    <div class="live-datetime-container" aria-live="polite" aria-label="Current date and time">
                        <span id="dash-live-date">&nbsp;</span>
                        <span id="dash-live-time">&nbsp;</span>
                    </div>
                </div>

                <!-- Statistics chart: January to December -->
                <div class="stats-chart-panel">
                    <h3 class="stats-chart-title">Statistics (January – December)</h3>
                    <div class="stats-chart-wrap">
                        <canvas id="chart-monthly-stats" width="800" height="320"></canvas>
                    </div>
                </div>

                <!-- dashboard quick action cards (keep original structure) -->
                <div class="card-grid">
                    <div class="card">
                        <h2>Offices</h2>
                        <p>Manage municipal offices and departments used for routing and classification of documents.</p>
                        <a href="offices-department.php">Go to Offices →</a>
                    </div>

                    <div class="card">
                        <h2>Documents</h2>
                        <p>Create, track, and archive municipal documents across all participating departments.</p>
                        <a href="documents.php">Go to Documents →</a>
                    </div>

                    <div class="card">
                        <h2>Search & Tracking</h2>
                        <p>Find documents by code, subject, or department and monitor their current status.</p>
                        <a href="#">Open Search →</a>
                    </div>
                </div>
                
                <!-- optional extra info: could be quick stats or nothing -->
                <div class="attribution">
                    ⚙️ Super admin privileges — full access to all modules.
                </div>
            </div>
        </div>
    </div>

    <!-- Profile modal -->
    <div class="profile-modal-overlay" id="profile-modal-overlay" aria-hidden="true">
        <div class="profile-modal" id="profile-modal" role="dialog" aria-labelledby="profile-modal-title">
            <div class="profile-modal-header">
                <div class="profile-modal-avatar" id="profile-modal-avatar"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitial); ?><?php endif; ?></div>
                <h2 class="profile-modal-title" id="profile-modal-title"><?php echo htmlspecialchars($userName); ?></h2>
            </div>
            <div class="profile-modal-body">
                <div class="profile-modal-row">
                    <span class="profile-modal-label">Username</span>
                    <span class="profile-modal-value"><?php echo htmlspecialchars($userName); ?></span>
                </div>
                <div class="profile-modal-row">
                    <span class="profile-modal-label">Email</span>
                    <span class="profile-modal-value"><?php echo htmlspecialchars($userEmail); ?></span>
                </div>
                <div class="profile-modal-row">
                    <span class="profile-modal-label">Role</span>
                    <span class="profile-modal-value"><?php echo htmlspecialchars($userRole); ?></span>
                </div>
            </div>
            <div class="profile-modal-actions">
                <button type="button" class="profile-modal-btn profile-modal-btn-close" id="profile-modal-close">Close</button>
                <a href="../index.php?logout=1" class="profile-modal-btn profile-modal-btn-logout">Log out</a>
            </div>
        </div>
    </div>
</body>
</html>
<script>
// Notification dropdown
(function(){
    var notifBtn = document.getElementById('notif-btn');
    var notifDropdown = document.getElementById('notif-dropdown');
    function closeNotif(){
        if (notifDropdown) notifDropdown.style.display = 'none';
    }
    if (notifBtn) notifBtn.addEventListener('click', function(e){
        e.stopPropagation();
        if (!notifDropdown) return;
        var showing = notifDropdown.style.display === 'block';
        closeNotif();
        notifDropdown.style.display = showing ? 'none' : 'block';
    });
    document.addEventListener('click', function(){ closeNotif(); });
})();

// Profile modal
(function(){
    var profileBtn = document.getElementById('profile-btn');
    var overlay = document.getElementById('profile-modal-overlay');
    var modal = document.getElementById('profile-modal');
    var closeBtn = document.getElementById('profile-modal-close');
    function openModal(){
        if (overlay) { overlay.classList.add('profile-modal-open'); overlay.setAttribute('aria-hidden', 'false'); }
        if (modal) modal.setAttribute('aria-hidden', 'false');
    }
    function closeModal(){
        if (overlay) { overlay.classList.remove('profile-modal-open'); overlay.setAttribute('aria-hidden', 'true'); }
        if (modal) modal.setAttribute('aria-hidden', 'true');
    }
    if (profileBtn) profileBtn.addEventListener('click', function(e){ e.stopPropagation(); openModal(); });
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (overlay) overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
})();
// live date/time updater for the container
(function(){
    var dateEl = document.getElementById('dash-live-date');
    var timeEl = document.getElementById('dash-live-time');
    function update(){
        var now = new Date();
        if (dateEl) dateEl.textContent = now.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
        if (timeEl) timeEl.textContent = now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    update();
    setInterval(update, 1000);
})();

// Monthly statistics chart (Jan–Dec)
(function(){
    function renderMonthlyChart(){
        if (typeof Chart === 'undefined') return;
        var ctx = document.getElementById('chart-monthly-stats');
        if (!ctx) return;
        try {
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sept','Oct','Nov','Dec'],
                    datasets: [{
                        label: 'Documents',
                        data: [42, 58, 51, 72, 65, 88, 74, 61, 95, 82, 78, 91],
                        backgroundColor: '#2563eb',
                        borderColor: '#1e40af',
                        borderWidth: 1,
                        borderRadius: 6,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { backgroundColor: '#0b1f3a', titleColor: '#fff', bodyColor: '#b8d4ee', padding: 12 }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#e2e8f0' },
                            ticks: { color: '#64748b', font: { size: 12 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#64748b', font: { size: 12 } }
                        }
                    }
                }
            });
        } catch(e) { console.error('Chart error:', e); }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function(){ setTimeout(renderMonthlyChart, 300); });
    else setTimeout(renderMonthlyChart, 300);
})();
</script>