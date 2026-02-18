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
$sidebar_active = 'offices';

// Load config
$config = require dirname(__DIR__) . '/config.php';
$namespace = $config['database'] . '.offices';

/**
 * Get MongoDB Manager instance.
 * @return \MongoDB\Driver\Manager
 */
function getOfficeManager() {
    global $config;
    return new MongoDB\Driver\Manager($config['uri']);
}

/**
 * Fetch offices from MongoDB with optional search and date filters.
 * @param string $search Search term for office name or code
 * @param string|null $dateFrom Filter by created_at >= date
 * @param string|null $dateTo Filter by created_at <= date
 * @return array List of office documents (with _id as string)
 */
function getOffices($search = '', $dateFrom = null, $dateTo = null) {
    global $namespace;
    $filter = [];
    if ($search !== '') {
        $filter['$or'] = [
            ['office_name' => new MongoDB\BSON\Regex($search, 'i')],
            ['office_code' => new MongoDB\BSON\Regex($search, 'i')],
        ];
    }
    if (($dateFrom !== null && $dateFrom !== '') || ($dateTo !== null && $dateTo !== '')) {
        $filter['created_at'] = [];
        if ($dateFrom !== null && $dateFrom !== '') {
            $filter['created_at']['$gte'] = new MongoDB\BSON\UTCDateTime(strtotime($dateFrom . ' 00:00:00') * 1000);
        }
        if ($dateTo !== null && $dateTo !== '') {
            $filter['created_at']['$lte'] = new MongoDB\BSON\UTCDateTime(strtotime($dateTo . ' 23:59:59') * 1000);
        }
    }
    if (empty($filter)) {
        $filter = (object)[];
    }
    try {
        $manager = getOfficeManager();
        $query = new MongoDB\Driver\Query($filter, ['sort' => ['office_name' => 1]]);
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
 * Add a new office.
 * @param string $officeCode
 * @param string $officeName
 * @param string $officeHead
 * @return array ['success' => bool, 'message' => string]
 */
function addOffice($officeCode, $officeName, $officeHead) {
    global $namespace;
    $officeCode = trim($officeCode);
    $officeName = trim($officeName);
    $officeHead = trim($officeHead);
    if ($officeCode === '' || $officeName === '') {
        return ['success' => false, 'message' => 'Office code and name are required.'];
    }
    try {
        $manager = getOfficeManager();
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->insert([
            'office_code' => $officeCode,
            'office_name' => $officeName,
            'office_head' => $officeHead,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]);
        $manager->executeBulkWrite($namespace, $bulk);
        return ['success' => true, 'message' => 'Office added successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Update an existing office.
 * @param string $id MongoDB _id (string)
 * @param string $officeCode
 * @param string $officeName
 * @param string $officeHead
 * @return array ['success' => bool, 'message' => string]
 */
function updateOffice($id, $officeCode, $officeName, $officeHead) {
    global $namespace;
    $officeCode = trim($officeCode);
    $officeName = trim($officeName);
    $officeHead = trim($officeHead);
    if ($officeCode === '' || $officeName === '') {
        return ['success' => false, 'message' => 'Office code and name are required.'];
    }
    try {
        $manager = getOfficeManager();
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->update(
            ['_id' => new MongoDB\BSON\ObjectId($id)],
            ['$set' => [
                'office_code' => $officeCode,
                'office_name' => $officeName,
                'office_head' => $officeHead,
                'updated_at' => new MongoDB\BSON\UTCDateTime(),
            ]],
            ['multi' => false]
        );
        $manager->executeBulkWrite($namespace, $bulk);
        return ['success' => true, 'message' => 'Office updated successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Delete an office by ID.
 * @param string $id MongoDB _id (string)
 * @return array ['success' => bool, 'message' => string]
 */
function deleteOffice($id) {
    global $namespace;
    if ($id === '') {
        return ['success' => false, 'message' => 'Invalid office ID.'];
    }
    try {
        $manager = getOfficeManager();
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->delete(['_id' => new MongoDB\BSON\ObjectId($id)], ['limit' => 1]);
        $manager->executeBulkWrite($namespace, $bulk);
        return ['success' => true, 'message' => 'Office deleted successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// Handle POST actions
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $flash = addOffice(
            $_POST['office_code'] ?? '',
            $_POST['office_name'] ?? '',
            $_POST['office_head'] ?? ''
        );
    } elseif ($action === 'update' && !empty($_POST['office_id'])) {
        $flash = updateOffice(
            $_POST['office_id'],
            $_POST['office_code'] ?? '',
            $_POST['office_name'] ?? '',
            $_POST['office_head'] ?? ''
        );
    } elseif ($action === 'delete' && !empty($_POST['office_id'])) {
        $flash = deleteOffice($_POST['office_id']);
    }
    if ($flash) {
        header('Location: offices-department.php?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
        exit;
    }
}

// Query params for filters
$search = trim($_GET['search'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$msg = $_GET['msg'] ?? null;
$msgOk = isset($_GET['ok']) && $_GET['ok'] === '1';
$offices = getOffices($search, $dateFrom ?: null, $dateTo ?: null);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Offices/Department</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Admin design – header & sidebar */
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
        .sidebar-user { padding: 1rem 1rem 1.25rem; border-top: 1px solid rgba(255, 255, 255, 0.06); display: flex; align-items: center; gap: 0.75rem; }
        .sidebar-user-avatar { width: 40px; height: 40px; border-radius: 50%; background: #63B3ED; color: #fff; font-size: 0.9rem; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .sidebar-user-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .sidebar-user-info { min-width: 0; }
        .sidebar-user-name { font-size: 0.95rem; font-weight: 600; color: #fff; margin: 0; }
        .sidebar-user-role { font-size: 0.8rem; color: #A0AEC0; margin: 2px 0 0 0; }
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
        /* Offices section */
        .offices-card { background: #fff; border: 2px solid #0b0b0b; border-radius: 4px; padding: 1.5rem; }
        .offices-title { font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; font-size: 1.05rem; color: #0b1720; margin: 0 0 1rem 0; }
        .offices-tools { display: grid; grid-template-columns: 1.4fr 1fr 1fr auto auto; gap: 12px; margin-bottom: 16px; }
        .offices-tools input { height: 42px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0 12px; font-size: 14px; color: #1e293b; background: #fff; outline: none; }
        .offices-tools input:focus { border-color: #1e3a5f; box-shadow: 0 0 0 3px rgba(30,58,95,0.15); }
        .offices-btn { height: 42px; border: none; border-radius: 10px; padding: 0 16px; background: #1e3a5f; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .offices-btn:hover { background: #2d4a6f; }
        .offices-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
        .offices-btn-secondary { background: #64748b; }
        .offices-btn-secondary:hover { background: #475569; }
        .offices-table-frame { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; overflow: hidden; margin-top: 1rem; }
        .offices-table { width: 100%; border-collapse: collapse; }
        .offices-table thead th { text-align: left; padding: 14px 16px; font-size: 13px; font-weight: 600; letter-spacing: 0.03em; color: #475569; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .offices-table tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 14px; }
        .offices-empty { text-align: center; height: 200px; color: #64748b; vertical-align: middle; }
        .offices-msg { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.95rem; }
        .offices-msg-ok { background: #dcfce7; color: #166534; }
        .offices-msg-err { background: #fee2e2; color: #991b1b; }
        .offices-btn-off, .offices-btn-del { height: 34px; padding: 0 12px; font-size: 13px; border-radius: 6px; cursor: pointer; border: none; margin-right: 6px; display: inline-flex; align-items: center; gap: 6px; }
        .offices-btn-off { background: #1e3a5f; color: #fff; }
        .offices-btn-off svg, .offices-btn-del svg { width: 16px; height: 16px; flex-shrink: 0; }
        .offices-btn-off:hover { background: #2d4a6f; }
        .offices-btn-del { background: #dc2626; color: #fff; }
        .offices-btn-del:hover { background: #b91c1c; }
        .offices-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 200; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .offices-modal-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.4); cursor: pointer; }
        .offices-modal-content { position: relative; background: #fff; padding: 1.5rem; border-radius: 12px; width: 100%; max-width: 420px; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .offices-modal-content h3 { margin: 0 0 1rem 0; font-size: 1.2rem; }
        .offices-field { margin-bottom: 1rem; }
        .offices-field label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .offices-field input { width: 100%; height: 40px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        .offices-modal-actions { display: flex; gap: 10px; margin-top: 1.25rem; }
        @media (max-width: 980px) { .offices-tools { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
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
                    <li><a href="dashboard.php" class="<?php echo $sidebar_active === 'dashboard' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard</a></li>
                    <li><a href="documents.php" class="<?php echo $sidebar_active === 'documents' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>Documents</a></li>
                    <li><a href="document-history.php" class="<?php echo $sidebar_active === 'document-history' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Document History</a></li>
                </ul>
                <div class="nav-section-title">Administration</div>
                <ul>
                    <li><a href="users.php" class="<?php echo $sidebar_active === 'users' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>User Management</a></li>
                    <li><a href="offices-department.php" class="<?php echo $sidebar_active === 'offices' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/></svg>Departments</a></li>
                    <li><a href="activitylogs.php" class="<?php echo $sidebar_active === 'activitylogs' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>Activity Logs</a></li>
                    <li><a href="archived.php" class="<?php echo $sidebar_active === 'archived' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archived</a></li>
                </ul>
                <div class="nav-section-title">Account</div>
                <ul>
                    <li><a href="#" id="sidebar-settings-btn"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>Settings</a></li>
                </ul>
            </nav>
            <div class="sidebar-user">
                <div class="sidebar-user-avatar"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitial); ?><?php endif; ?></div>
                <div class="sidebar-user-info">
                    <p class="sidebar-user-name"><?php echo htmlspecialchars($userName); ?></p>
                    <p class="sidebar-user-role"><?php echo htmlspecialchars($userRole); ?></p>
                </div>
            </div>
        </div>

        <div class="main-content">
            <div class="content-header">
                <div class="dashboard-header">
                    <div>
                        <h1>Welcome, <?php echo htmlspecialchars($userName); ?>!</h1>
                        <small>Municipal Document Management System – Offices / Department</small>
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
                <?php if ($msg !== null): ?>
                <div class="offices-msg <?= $msgOk ? 'offices-msg-ok' : 'offices-msg-err' ?>"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>
                <section class="offices-card">
                    <h2 class="offices-title">Offices &amp; Departments</h2>
                    <form method="get" class="offices-tools">
                        <input type="text" name="search" placeholder="Search office or department" aria-label="Search" value="<?= htmlspecialchars($search) ?>">
                        <input type="date" name="date_from" aria-label="From date" value="<?= htmlspecialchars($dateFrom) ?>">
                        <input type="date" name="date_to" aria-label="To date" value="<?= htmlspecialchars($dateTo) ?>">
                        <button type="submit" class="offices-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>Search</button>
                        <button type="button" class="offices-btn offices-btn-secondary" onclick="openAddModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>Add Office</button>
                    </form>

                    <div class="offices-table-frame">
                        <table class="offices-table">
                            <thead>
                                <tr>
                                    <th>OFFICE CODE</th>
                                    <th>OFFICE NAME</th>
                                    <th>OFFICE HEAD</th>
                                    <th>ACTION</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($offices) === 0): ?>
                                <tr>
                                    <td colspan="4" class="offices-empty">No offices yet.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($offices as $o): ?>
                                <tr>
                                    <td><?= htmlspecialchars($o['office_code'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($o['office_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($o['office_head'] ?? '') ?></td>
                                    <td>
                                        <button type="button" class="offices-btn-off" data-id="<?= htmlspecialchars($o['_id']) ?>" data-code="<?= htmlspecialchars($o['office_code'] ?? '') ?>" data-name="<?= htmlspecialchars($o['office_name'] ?? '') ?>" data-head="<?= htmlspecialchars($o['office_head'] ?? '') ?>" onclick="openEditModal(this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Edit</button>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this office?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="office_id" value="<?= htmlspecialchars($o['_id']) ?>">
                                            <button type="submit" class="offices-btn-del"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- Add Office Modal -->
            <div id="modal-add" class="offices-modal" style="display:none;">
                <div class="offices-modal-overlay" onclick="closeAddModal()"></div>
                <div class="offices-modal-content">
                    <h3>Add Office</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="add">
                        <div class="offices-field"><label>Office Code</label><input type="text" name="office_code" required placeholder="e.g. OPD"></div>
                        <div class="offices-field"><label>Office Name</label><input type="text" name="office_name" required placeholder="e.g. Office of the Planning & Development"></div>
                        <div class="offices-field"><label>Office Head</label><input type="text" name="office_head" placeholder="e.g. John Doe"></div>
                        <div class="offices-modal-actions">
                            <button type="submit" class="offices-btn">Save</button>
                            <button type="button" class="offices-btn offices-btn-secondary" onclick="closeAddModal()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Office Modal -->
            <div id="modal-edit" class="offices-modal" style="display:none;">
                <div class="offices-modal-overlay" onclick="closeEditModal()"></div>
                <div class="offices-modal-content">
                    <h3>Edit Office</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="office_id" id="edit-office-id" value="">
                        <div class="offices-field"><label>Office Code</label><input type="text" name="office_code" id="edit-office-code" required></div>
                        <div class="offices-field"><label>Office Name</label><input type="text" name="office_name" id="edit-office-name" required></div>
                        <div class="offices-field"><label>Office Head</label><input type="text" name="office_head" id="edit-office-head"></div>
                        <div class="offices-modal-actions">
                            <button type="submit" class="offices-btn">Update</button>
                            <button type="button" class="offices-btn offices-btn-secondary" onclick="closeEditModal()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            function openAddModal() { document.getElementById('modal-add').style.display = 'flex'; }
            function closeAddModal() { document.getElementById('modal-add').style.display = 'none'; }
            function openEditModal(btn) {
                var d = btn.dataset || {};
                document.getElementById('edit-office-id').value = d.id || '';
                document.getElementById('edit-office-code').value = d.code || '';
                document.getElementById('edit-office-name').value = d.name || '';
                document.getElementById('edit-office-head').value = d.head || '';
                document.getElementById('modal-edit').style.display = 'flex';
            }
            function closeEditModal() { document.getElementById('modal-edit').style.display = 'none'; }
            </script>
    <div class="profile-modal-overlay" id="profile-modal-overlay" aria-hidden="true">
        <div class="profile-modal" id="profile-modal" role="dialog" aria-labelledby="profile-modal-title">
            <div class="profile-modal-header">
                <div class="profile-modal-avatar" id="profile-modal-avatar"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitial); ?><?php endif; ?></div>
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
    var sidebarSettings = document.getElementById('sidebar-settings-btn');
    if (sidebarSettings) sidebarSettings.addEventListener('click', function(e){ e.preventDefault(); openModal(); });
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (overlay) overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
})();
</script>
        </div>
    </div>
</body>
</html>
