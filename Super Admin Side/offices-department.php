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
$userDepartment = $_SESSION['user_department'] ?? 'Not Assigned';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$sidebar_active = 'offices';

// Load config
$config = require dirname(__DIR__) . '/config.php';
$namespace = $config['database'] . '.offices';
require_once __DIR__ . '/_account_helpers.php';

/**
 * Get MongoDB Manager instance.
 * @return \MongoDB\Driver\Manager
 */
function getOfficeManager() {
    global $config;
    return new MongoDB\Driver\Manager($config['uri']);
}

/**
 * Fetch all user accounts for dropdowns (e.g. assign department head).
 * @return array List of user records with _id (string), name, email
 */
function getUsers() {
    global $config;
    $namespace = $config['database'] . '.users';
    try {
        $manager = getOfficeManager();
        $query = new MongoDB\Driver\Query([], ['sort' => ['username' => 1]]);
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
 * Fetch offices from MongoDB with optional search filter.
 * @param string $search Search term for office name or code
 * @return array List of office documents (with _id as string)
 */
function getOffices($search = '') {
    global $namespace;
    $filter = [];
    if ($search !== '') {
        $filter['$or'] = [
            ['office_name' => new MongoDB\BSON\Regex($search, 'i')],
            ['office_code' => new MongoDB\BSON\Regex($search, 'i')],
        ];
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
 * @param string $description
 * @return array ['success' => bool, 'message' => string]
 */
function addOffice($officeCode, $officeName, $officeHead, $description = '') {
    global $namespace;
    $officeCode = trim($officeCode);
    $officeName = trim($officeName);
    $officeHead = trim($officeHead);
    $description = trim($description);
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
            'description' => $description,
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]);
        $manager->executeBulkWrite($namespace, $bulk);
        return ['success' => true, 'message' => 'Department added successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Update an existing office.
 */
function updateOffice($id, $officeCode, $officeName, $officeHead, $description = '') {
    global $namespace;
    $officeCode = trim($officeCode);
    $officeName = trim($officeName);
    $officeHead = trim($officeHead);
    $description = trim($description);
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
                'description' => $description,
                'updated_at' => new MongoDB\BSON\UTCDateTime(),
            ]],
            ['multi' => false]
        );
        $manager->executeBulkWrite($namespace, $bulk);
        return ['success' => true, 'message' => 'Department updated successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Assign or update department head by user account ID.
 * Looks up the user and stores office_head_id and office_head (display name).
 */
function assignHead($id, $officeHeadUserId) {
    global $namespace, $config;
    if ($id === '') {
        return ['success' => false, 'message' => 'Invalid department ID.'];
    }
    $officeHeadUserId = trim($officeHeadUserId);
    $officeHead = '';
    $officeHeadId = null;
    if ($officeHeadUserId !== '') {
        try {
            $usersNs = $config['database'] . '.users';
            $manager = getOfficeManager();
            $query = new MongoDB\Driver\Query(['_id' => new MongoDB\BSON\ObjectId($officeHeadUserId)]);
            $cursor = $manager->executeQuery($usersNs, $query);
            $users = $cursor->toArray();
            if (count($users) > 0) {
                $u = (array)$users[0];
                $officeHead = trim($u['username'] ?? $u['name'] ?? $u['email'] ?? '');
                $officeHeadId = $officeHeadUserId;
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Invalid user selected.'];
        }
    }
    try {
        $manager = getOfficeManager();
        $bulk = new MongoDB\Driver\BulkWrite;
        $set = ['updated_at' => new MongoDB\BSON\UTCDateTime(), 'office_head' => $officeHead];
        if ($officeHeadId !== null) {
            $set['office_head_id'] = $officeHeadId;
        } else {
            $set['office_head_id'] = '';
        }
        $bulk->update(
            ['_id' => new MongoDB\BSON\ObjectId($id)],
            ['$set' => $set],
            ['multi' => false]
        );
        $manager->executeBulkWrite($namespace, $bulk);
        return ['success' => true, 'message' => 'Department head assigned successfully.'];
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
            $_POST['office_head'] ?? '',
            $_POST['description'] ?? ''
        );
    } elseif ($action === 'update' && !empty($_POST['office_id'])) {
        $flash = updateOffice(
            $_POST['office_id'],
            $_POST['office_code'] ?? '',
            $_POST['office_name'] ?? '',
            $_POST['office_head'] ?? '',
            $_POST['description'] ?? ''
        );
    } elseif ($action === 'assign_head' && !empty($_POST['office_id'])) {
        $flash = assignHead($_POST['office_id'], $_POST['office_head_id'] ?? '');
    } elseif ($action === 'delete' && !empty($_POST['office_id'])) {
        $flash = deleteOffice($_POST['office_id']);
    } elseif ($action === 'change_password' && !empty($_SESSION['user_id'])) {
        $flash = changePassword(
            $_SESSION['user_id'],
            $_POST['current_password'] ?? '',
            $_POST['new_password'] ?? '',
            $_POST['confirm_password'] ?? ''
        );
    } elseif ($action === 'update_signature' && !empty($_SESSION['user_id']) && isset($_POST['signature'])) {
        $flash = updateUserSignature($_SESSION['user_id'], $_POST['signature']);
    } elseif ($action === 'update_photo' && !empty($_SESSION['user_id']) && isset($_POST['photo'])) {
        $flash = updateUserPhoto($_SESSION['user_id'], $_POST['photo']);
    }
    if ($flash) {
        header('Location: offices-department.php?msg=' . urlencode($flash['message']) . '&ok=' . ($flash['success'] ? '1' : '0'));
        exit;
    }
}

// Query params for filters
$search = trim($_GET['search'] ?? '');
$msg = $_GET['msg'] ?? null;
$msgOk = isset($_GET['ok']) && $_GET['ok'] === '1';
$offices = getOffices($search);
$usersList = getUsers();
$userSignature = isset($_SESSION['user_signature']) ? $_SESSION['user_signature'] : getUserSignature($_SESSION['user_id'] ?? '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Offices/Department</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="styles.css">
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
        .dashboard-header h1 { font-size: 1.6rem; margin: 0 0 0.2rem 0; font-weight: 700; color: #1e293b; }
        .dashboard-header small { display: block; color: #64748b; font-size: 0.95rem; margin-top: 6px; }
        .content-body { padding: 2rem 2.2rem; }
        .header-controls { position: relative; }
        .icon-btn { background: #f1f5f9; border: none; color: #475569; padding: 0; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .icon-btn:hover { background: #e2e8f0; color: #1e293b; }
        .icon-btn { position: relative; width: 40px; height: 40px; }
        .icon-btn svg { width: 22px; height: 22px; }
        .notif-badge { position: absolute; top: 8px; right: 8px; background: #ef4444; color: white; font-size: 12px; padding: 4px 8px; border-radius: 999px; line-height: 1; }
        .notif-dropdown, .profile-dropdown { position: absolute; right: 0; top: 48px; background: white; color: #0b1720; min-width: 180px; border-radius: 6px; box-shadow: 0 8px 20px rgba(2,6,23,0.12); border: 1px solid #e6eef8; display: none; z-index: 1200; padding: 8px 0; }
        .notif-item { padding: 10px 12px; font-size: 0.95rem; color: #475569; }
        .profile-link { display: flex; align-items: center; gap: 8px; padding: 10px 12px; text-decoration: none; color: #0b1720; }
        .profile-link svg { width: 16px; height: 16px; flex-shrink: 0; }
        .profile-link:hover { background: #f1f5f9; }
        .profile-modal-overlay, .settings-modal-overlay { position: fixed; inset: 0; background: rgba(27, 21, 72, 0.5); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 1rem; overflow-y: auto; }
        .profile-modal-overlay.profile-modal-open, .settings-modal-overlay.settings-modal-open { display: flex; }
        .profile-modal { background: #fff; border-radius: 16px; box-shadow: 0 24px 48px rgba(27, 21, 72, 0.2); border: 2px solid #D4AF37; width: 50vw; height: 70vh; min-width: 320px; max-width: 50vw; overflow: hidden; margin: auto; display: flex; flex-direction: column; }
        .profile-modal-header { padding: 1.5rem 1.5rem; border-bottom: 1px solid #e2e8f0; position: relative; }
        .profile-modal-title { color: #1e293b; font-size: 1.35rem; font-weight: 700; margin: 0 0 0.25rem 0; }
        .profile-modal-subtitle { font-size: 0.9rem; color: #64748b; margin: 0; }
        .profile-modal-close-btn { position: absolute; top: 1rem; right: 1rem; width: 32px; height: 32px; border: none; background: transparent; color: #64748b; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s, color 0.15s; }
        .profile-modal-close-btn:hover { background: #f1f5f9; color: #1e293b; }
        .profile-modal-close-btn svg { width: 20px; height: 20px; }
        .profile-modal-body { padding: 1.75rem 1.5rem; overflow-y: auto; flex: 1; min-height: 0; }
        .profile-info-card { background: #f8fafc; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; border: 1px solid #e2e8f0; }
        .profile-info-card h3 { font-size: 0.95rem; font-weight: 700; color: #1e293b; margin: 0 0 0.35rem 0; }
        .profile-info-card p.profile-info-desc { font-size: 0.8rem; color: #64748b; margin: 0 0 1rem 0; }
        .profile-info-grid { display: grid; grid-template-columns: auto 1fr; gap: 0.5rem 1.25rem; align-items: start; }
        .profile-info-avatar { width: 64px; height: 64px; border-radius: 50%; background: #63B3ED; color: #fff; font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; justify-content: center; grid-row: span 4; }
        .profile-info-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .profile-info-label { font-size: 0.7rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
        .profile-info-value { font-size: 0.95rem; color: #1e293b; font-weight: 500; margin: 0; }
        .profile-password-card { background: #f8fafc; border-radius: 12px; padding: 1.25rem; margin-bottom: 0; border: 1px solid #e2e8f0; box-sizing: border-box; overflow: hidden; }
        .profile-password-card h3 { font-size: 0.95rem; font-weight: 700; color: #1e293b; margin: 0 0 0.35rem 0; display: flex; align-items: center; gap: 8px; }
        .profile-password-card h3 svg { width: 18px; height: 18px; color: #64748b; }
        .profile-password-card p.profile-info-desc { font-size: 0.8rem; color: #64748b; margin: 0 0 1rem 0; }
        .profile-password-card .offices-field { margin-bottom: 1rem; }
        .profile-password-card .offices-field:last-of-type { margin-bottom: 0; }
        .profile-password-card .offices-field input { width: 100%; height: 42px; padding: 0 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem; box-sizing: border-box; max-width: 100%; }
        .profile-modal-btn-update { width: 100%; padding: 12px 16px; height: auto; min-height: 44px; background: #3B82F6; color: #fff; border: none; border-radius: 10px; font-size: 0.95rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: background 0.15s; box-sizing: border-box; max-width: 100%; }
        .profile-modal-btn-update:hover { background: #2563eb; color: #fff; }
        .profile-modal-btn-update svg { width: 18px; height: 18px; flex-shrink: 0; }
        .profile-photo-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; }
        .profile-photo-card h3 { margin: 0 0 0.25rem 0; font-size: 1.1rem; font-weight: 700; color: #1e293b; }
        .profile-photo-card .profile-info-desc { margin: 0 0 1rem 0; font-size: 0.9rem; color: #64748b; }
        .profile-photo-row { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
        .profile-photo-avatar { width: 80px; height: 80px; border-radius: 50%; background: #63B3ED; color: #fff; font-size: 2rem; font-weight: 700; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .profile-photo-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-photo-actions { flex: 1; min-width: 0; }
        .profile-signature-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; }
        .profile-signature-card h3 { margin: 0 0 0.25rem 0; font-size: 1.1rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px; }
        .profile-signature-card h3 svg { width: 20px; height: 20px; color: #3B82F6; flex-shrink: 0; }
        .profile-signature-card .profile-info-desc { margin: 0 0 1rem 0; font-size: 0.9rem; color: #64748b; }
        .profile-signature-current { margin-bottom: 1rem; }
        .profile-signature-current-label { font-size: 0.8rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem; display: block; }
        .profile-signature-box { width: 100%; max-width: 320px; height: 120px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .profile-signature-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .profile-signature-box.empty { color: #94a3b8; font-size: 0.9rem; }
        .profile-signature-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: #3B82F6; color: #fff; border: none; border-radius: 10px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
        .profile-signature-btn:hover { background: #2563eb; color: #fff; }
        .profile-signature-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
        .signature-modal-overlay { position: fixed; inset: 0; z-index: 300; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(0,0,0,0.4); }
        .signature-modal-overlay.signature-modal-open { display: flex; }
        .signature-modal-overlay[aria-hidden="true"] { display: none; }
        .signature-modal { background: #fff; border-radius: 12px; width: 100%; max-width: 480px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }
        .signature-modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
        .signature-modal-header h3 { margin: 0; font-size: 1.2rem; font-weight: 700; color: #1e293b; }
        .signature-modal-close { width: 36px; height: 36px; border: none; background: transparent; color: #64748b; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .signature-modal-close:hover { background: #f1f5f9; color: #1e293b; }
        .signature-modal-close svg { width: 20px; height: 20px; }
        .signature-tabs { display: flex; border-bottom: 1px solid #e5e7eb; padding: 0 1rem; gap: 0; }
        .signature-tab { padding: 12px 20px; border: none; background: none; font-size: 0.95rem; font-weight: 500; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: inherit; }
        .signature-tab:hover { color: #1e293b; }
        .signature-tab.active { color: #3B82F6; border-bottom-color: #3B82F6; }
        .signature-modal-body { padding: 1.25rem; overflow-y: auto; flex: 1; min-height: 0; }
        .signature-pane { display: none; }
        .signature-pane.active { display: block; }
        .signature-upload-zone { border: 2px dashed #cbd5e1; border-radius: 10px; padding: 2rem; text-align: center; background: #f8fafc; cursor: pointer; transition: border-color 0.2s, background 0.2s; }
        .signature-upload-zone:hover, .signature-upload-zone.dragover { border-color: #3B82F6; background: rgba(59, 130, 246, 0.05); }
        .signature-upload-zone input[type="file"] { display: none; }
        .signature-upload-preview { max-width: 100%; max-height: 180px; margin-top: 1rem; display: none; }
        .signature-upload-preview.show { display: block; }
        .signature-canvas-wrap { border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; background: #fff; }
        #signature-pad { display: block; width: 100%; height: 200px; cursor: crosshair; touch-action: none; }
        .signature-actions { display: flex; gap: 10px; margin-top: 1rem; flex-wrap: wrap; }
        .signature-actions .btn-clear { background: #64748b; color: #fff; }
        .signature-actions .btn-clear:hover { background: #475569; color: #fff; }
        .signature-modal-footer { padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; }
        .main-content { background: #f1f5f9; }
        .dept-page-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
        .dept-page-title { margin: 0; font-size: 1.75rem; font-weight: 700; color: #1e293b; }
        .dept-page-subtitle { margin: 0.25rem 0 0 0; font-size: 0.95rem; color: #64748b; }
        .dept-add-btn { display: inline-flex; align-items: center; gap: 8px; padding: 0.6rem 1.25rem; background: #1A202C; color: #fff; border: none; border-radius: 10px; font-size: 0.95rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: background 0.15s; }
        .dept-add-btn:hover { background: #2d3748; color: #fff; }
        .dept-add-btn svg { width: 20px; height: 20px; flex-shrink: 0; }
        .dept-search-row { display: flex; align-items: stretch; gap: 0; margin-bottom: 1.5rem; max-width: 100%; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
        .dept-search-wrap { flex: 1; position: relative; min-width: 0; display: flex; align-items: center; }
        .dept-search-wrap svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; color: #94a3b8; pointer-events: none; flex-shrink: 0; }
        .dept-search { width: 100%; height: 44px; padding: 0 16px 0 44px; border: none; border-radius: 0; font-size: 0.95rem; color: #1e293b; background: transparent; outline: none; }
        .dept-search::placeholder { color: #94a3b8; }
        .dept-search:focus { outline: none; }
        .dept-search-row:focus-within { border-color: #3B82F6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
        .dept-filter-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 44px; padding: 0 20px; border: none; border-left: 1px solid rgba(255,255,255,0.08); border-radius: 0; background: #1A202C; color: #fff; font-size: 0.95rem; font-weight: 600; cursor: pointer; outline: none; transition: background 0.15s, color 0.15s; flex-shrink: 0; font-family: inherit; -webkit-appearance: none; appearance: none; }
        .dept-filter-btn:hover { background: #2d3748; color: #fff; border-left-color: rgba(255,255,255,0.08); }
        .dept-filter-btn:focus { outline: none; }
        .dept-filter-btn:focus-visible { box-shadow: inset 0 0 0 2px rgba(255,255,255,0.3); }
        .dept-filter-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
        .dept-cards-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; align-items: stretch; }
        .dept-card { background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.08); transition: box-shadow 0.2s ease; min-height: 320px; display: flex; flex-direction: column; overflow: hidden; }
        .dept-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .dept-card-header { padding: 1.5rem; display: flex; flex-direction: row; align-items: flex-start; justify-content: space-between; padding-bottom: 0.5rem; }
        .dept-card-header-left { display: flex; align-items: center; gap: 0.75rem; min-width: 0; flex: 1; }
        .dept-card-icon { width: 48px; height: 48px; border-radius: 0.5rem; background: #dbeafe; color: #2563eb; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .dept-card-icon svg { width: 24px; height: 24px; }
        .dept-card-title-wrap { min-width: 0; }
        .dept-card-name { font-size: 1.125rem; font-weight: 700; color: #1e293b; margin: 0; line-height: 1.3; letter-spacing: -0.01em; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .dept-card-code { font-size: 0.875rem; color: #64748b; margin: 0; font-family: ui-monospace, 'JetBrains Mono', monospace; }
        .dept-card-content { padding: 0 1.5rem 1.5rem; padding-top: 0; flex: 1; display: flex; flex-direction: column; min-height: 0; }
        .dept-card-desc { font-size: 0.875rem; color: #475569; margin: 0 0 1rem 0; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .dept-card-head-section { padding-top: 1rem; border-top: 1px solid #e5e7eb; }
        .dept-card-head-label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 0.25rem 0; }
        .dept-card-head-value { font-size: 0.9rem; font-weight: 500; color: #0f172a; margin: 0; }
        .dept-card-head-value.not-assigned { color: #94a3b8; font-weight: 500; }
        .dept-card-created-section { margin-top: auto; padding-top: 0.75rem; border-top: 1px solid #e5e7eb; }
        .dept-card-created { font-size: 0.75rem; color: #94a3b8; margin: 0; font-family: ui-monospace, 'JetBrains Mono', monospace; }
        .dept-card-menu { position: relative; flex-shrink: 0; }
        .dept-card-menu-btn { width: 36px; height: 36px; border: none; background: transparent; color: #64748b; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s ease, color 0.15s ease; }
        .dept-card-menu-btn:hover { background: #FEF3C7; color: #92400e; }
        .dept-card-menu-btn svg { width: 20px; height: 20px; }
        .dept-card-dropdown { position: absolute; right: 0; top: 100%; margin-top: 4px; background: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border: 1px solid #e5e7eb; padding: 6px 0; min-width: 180px; z-index: 50; display: none; }
        .dept-card-dropdown.show { display: block; }
        .dept-empty { text-align: center; padding: 3rem 1rem; color: #64748b; font-size: 1rem; }
        .dept-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 1500; display: flex; align-items: center; gap: 12px; padding: 0.875rem 1rem 0.875rem 1rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0,0,0,0.15); max-width: 360px; animation: dept-toast-in 0.3s ease; }
        .dept-toast.success { background: #22c55e; color: #fff; }
        .dept-toast.error { background: #ef4444; color: #fff; }
        .dept-toast-icon { width: 24px; height: 24px; border-radius: 50%; background: rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .dept-toast-icon svg { width: 14px; height: 14px; }
        .dept-toast-text { flex: 1; font-size: 0.95rem; font-weight: 500; }
        @keyframes dept-toast-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .offices-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 200; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .offices-modal-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.4); cursor: pointer; }
        .offices-modal-content { position: relative; background: #fff; padding: 1.5rem 1.75rem; border-radius: 12px; width: 100%; max-width: 440px; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
        .offices-modal-content h3 { margin: 0 0 0.25rem 0; font-size: 1.35rem; font-weight: 700; color: #1e293b; }
        .offices-modal-subtitle { margin: 0 0 1.25rem 0; font-size: 0.9rem; color: #64748b; }
        .offices-modal-close { position: absolute; top: 1rem; right: 1rem; width: 32px; height: 32px; border: none; background: transparent; color: #64748b; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .offices-modal-close:hover { background: #f1f5f9; color: #1e293b; }
        .offices-modal-close svg { width: 20px; height: 20px; }
        .offices-field { margin-bottom: 1rem; }
        .offices-field label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .offices-field label .required { color: #dc2626; }
        .offices-field input, .offices-field textarea, .offices-field select { width: 100%; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; font-family: inherit; box-sizing: border-box; max-width: 100%; }
        .offices-field input, .offices-field select { height: 40px; }
        .offices-field select.offices-select { cursor: pointer; background: #fff; }
        .offices-field textarea { min-height: 80px; padding: 10px 12px; resize: vertical; }
        .offices-modal-actions { display: flex; gap: 10px; margin-top: 1.5rem; justify-content: flex-end; }
        .offices-modal-actions .offices-btn-create { display: inline-flex; align-items: center; gap: 8px; }
        .dept-card-dropdown .dept-dropdown-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 14px; border: none; background: none; color: #1e293b; font-size: 0.9rem; cursor: pointer; text-align: left; white-space: nowrap; transition: background 0.15s ease, color 0.15s ease; }
        .dept-card-dropdown .dept-dropdown-item:hover { background: #FEF3C7; color: #92400e; }
        .dept-card-dropdown .dept-dropdown-item.dept-dropdown-delete:hover { background: #fee2e2; color: #dc2626; }
        .dept-card-dropdown .dept-dropdown-item svg { width: 16px; height: 16px; flex-shrink: 0; }
        .offices-btn { height: 42px; padding: 0 16px; border: none; border-radius: 10px; background: #3B82F6; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .offices-btn:hover { background: #2563eb; }
        .offices-btn svg { width: 18px; height: 18px; flex-shrink: 0; }
        .offices-btn-secondary { background: #64748b; color: #fff; }
        .offices-btn-secondary:hover { background: #475569; color: #fff; }
        @media (max-width: 1024px) { .dept-cards-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) { .dept-cards-grid { grid-template-columns: 1fr; } .dept-page-header { flex-direction: column; align-items: stretch; } }
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
        </div>

        <div class="main-content">
            <div class="content-header">
                <div class="dashboard-header">
                    <div class="dept-page-header" style="flex: 1; margin-bottom: 0;">
                        <div>
                            <h1 class="dept-page-title">Departments</h1>
                            <p class="dept-page-subtitle">Manage municipal departments and their heads</p>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <button type="button" class="dept-add-btn" onclick="openAddModal()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>Add Department</button>
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
                <?php if ($msg !== null): ?>
                <div id="dept-toast" class="dept-toast <?= $msgOk ? 'success' : 'error' ?>" role="alert">
                    <div class="dept-toast-icon">
                        <?php if ($msgOk): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        <?php endif; ?>
                    </div>
                    <span class="dept-toast-text"><?= htmlspecialchars($msg) ?></span>
                </div>
                <?php endif; ?>

                <form method="get" id="dept-search-form" class="dept-search-row">
                    <div class="dept-search-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" name="search" class="dept-search" placeholder="Search departments..." aria-label="Search departments" value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <button type="submit" class="dept-filter-btn" aria-label="Filter">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        Filter
                    </button>
                </form>

                <div class="dept-cards-grid" id="dept-cards-grid">
                    <?php if (count($offices) === 0): ?>
                    <p class="dept-empty" style="grid-column: 1 / -1;">No departments yet. Click &ldquo;Add Department&rdquo; to create one.</p>
                    <?php else: ?>
                    <?php foreach ($offices as $o):
                        $createdAt = $o['created_at'] ?? null;
                        if ($createdAt instanceof \MongoDB\BSON\UTCDateTime) {
                            $createdAt = $createdAt->toDateTime()->format('M j, Y');
                        } else {
                            $createdAt = is_numeric($createdAt) ? date('M j, Y', (int)$createdAt) : '—';
                        }
                        $head = trim($o['office_head'] ?? '');
                        $desc = trim($o['description'] ?? '');
                        $descDisplay = $desc !== '' ? $desc : 'Municipal department';
                    ?>
                    <article class="dept-card">
                        <div class="dept-card-header">
                            <div class="dept-card-header-left">
                                <div class="dept-card-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
                                </div>
                                <div class="dept-card-title-wrap">
                                    <h3 class="dept-card-name"><?= htmlspecialchars($o['office_name'] ?? '') ?></h3>
                                    <p class="dept-card-code"><?= htmlspecialchars($o['office_code'] ?? '') ?></p>
                                </div>
                            </div>
                            <div class="dept-card-menu">
                                <button type="button" class="dept-card-menu-btn" aria-label="Options" onclick="toggleCardMenu(this)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                                </button>
                                <div class="dept-card-dropdown" role="menu">
                                    <button type="button" class="dept-dropdown-item dept-dropdown-edit" role="menuitem" data-id="<?= htmlspecialchars($o['_id']) ?>" data-code="<?= htmlspecialchars($o['office_code'] ?? '') ?>" data-name="<?= htmlspecialchars($o['office_name'] ?? '') ?>" data-head="<?= htmlspecialchars($o['office_head'] ?? '') ?>" data-desc="<?= htmlspecialchars($desc) ?>" onclick="openEditModal(this); closeCardMenu(this);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Edit Department</button>
                                    <button type="button" class="dept-dropdown-item" role="menuitem" data-id="<?= htmlspecialchars($o['_id']) ?>" data-name="<?= htmlspecialchars($o['office_name'] ?? '') ?>" data-head-id="<?= htmlspecialchars($o['office_head_id'] ?? '') ?>" onclick="openAssignHeadModal(this); closeCardMenu(this);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Assign Head</button>
                                    <button type="button" class="dept-dropdown-item dept-dropdown-delete" role="menuitem" data-id="<?= htmlspecialchars($o['_id']) ?>" data-name="<?= htmlspecialchars($o['office_name'] ?? '') ?>" onclick="confirmDeleteOffice(this); closeCardMenu(this);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>Delete</button>
                                </div>
                            </div>
                        </div>
                        <div class="dept-card-content">
                            <p class="dept-card-desc"><?= htmlspecialchars($descDisplay) ?></p>
                            <div class="dept-card-head-section">
                                <p class="dept-card-head-label">Department Head</p>
                                <p class="dept-card-head-value <?= $head === '' ? 'not-assigned' : '' ?>"><?= $head !== '' ? htmlspecialchars($head) : 'Not assigned' ?></p>
                            </div>
                            <div class="dept-card-created-section">
                                <p class="dept-card-created">Created: <?= $createdAt ?></p>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div id="modal-add" class="offices-modal" style="display:none;">
                <div class="offices-modal-overlay" onclick="closeAddModal()"></div>
                <div class="offices-modal-content">
                    <button type="button" class="offices-modal-close" onclick="closeAddModal()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                    <h3>Create New Department</h3>
                    <p class="offices-modal-subtitle">Add a new department to the system.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="add">
                        <div class="offices-field">
                            <label>Department Name <span class="required">*</span></label>
                            <input type="text" name="office_name" required placeholder="e.g., Municipal Treasurer's Office.">
                        </div>
                        <div class="offices-field">
                            <label>Department Code <span class="required">*</span></label>
                            <input type="text" name="office_code" required placeholder="e.g., MTO.">
                        </div>
                        <div class="offices-field">
                            <label>Description</label>
                            <textarea name="description" placeholder="Brief description of the department..." rows="3"></textarea>
                        </div>
                        <div class="offices-modal-actions">
                            <button type="button" class="offices-btn offices-btn-secondary" onclick="closeAddModal()">Cancel</button>
                            <button type="submit" class="offices-btn offices-btn-create"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" width="18" height="18"><path d="M12 5v14"/><path d="M5 12h14"/></svg>Create Department</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Department Modal -->
            <div id="modal-edit" class="offices-modal" style="display:none;">
                <div class="offices-modal-overlay" onclick="closeEditModal()"></div>
                <div class="offices-modal-content">
                    <button type="button" class="offices-modal-close" onclick="closeEditModal()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                    <h3>Edit Department</h3>
                    <p class="offices-modal-subtitle">Update department details.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="office_id" id="edit-office-id" value="">
                        <div class="offices-field"><label>Department Code <span class="required">*</span></label><input type="text" name="office_code" id="edit-office-code" required></div>
                        <div class="offices-field"><label>Department Name <span class="required">*</span></label><input type="text" name="office_name" id="edit-office-name" required></div>
                        <div class="offices-field"><label>Description</label><textarea name="description" id="edit-office-desc" rows="3" placeholder="Brief description of the department..."></textarea></div>
                        <div class="offices-field"><label>Department Head</label><input type="text" name="office_head" id="edit-office-head" placeholder="e.g., Juan Dela Cruz"></div>
                        <div class="offices-modal-actions">
                            <button type="button" class="offices-btn offices-btn-secondary" onclick="closeEditModal()">Cancel</button>
                            <button type="submit" class="offices-btn">Update</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Assign Head Modal -->
            <div id="modal-assign-head" class="offices-modal" style="display:none;">
                <div class="offices-modal-overlay" onclick="closeAssignHeadModal()"></div>
                <div class="offices-modal-content">
                    <button type="button" class="offices-modal-close" onclick="closeAssignHeadModal()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                    <h3>Assign Head</h3>
                    <p class="offices-modal-subtitle">Set or change the head for this department.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="assign_head">
                        <input type="hidden" name="office_id" id="assign-office-id" value="">
                        <div class="offices-field">
                            <label>Department</label>
                            <input type="text" id="assign-office-name" readonly style="background:#f8fafc; color:#64748b;">
                        </div>
                        <div class="offices-field">
                            <label>Department Head</label>
                            <select name="office_head_id" id="assign-office-head" class="offices-select">
                                <option value="">— Select user —</option>
                                <?php foreach ($usersList as $u):
                                    $username = trim($u['username'] ?? '');
                                    $label = $username !== '' ? $username : (trim($u['name'] ?? '') ?: trim($u['email'] ?? ''));
                                    if ($label === '') $label = (string)$u['_id'];
                                ?>
                                <option value="<?= htmlspecialchars($u['_id']) ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="offices-modal-actions">
                            <button type="button" class="offices-btn offices-btn-secondary" onclick="closeAssignHeadModal()">Cancel</button>
                            <button type="submit" class="offices-btn">Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            (function(){
                var searchInput = document.querySelector('.dept-search');
                if (searchInput) {
                    searchInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            document.getElementById('dept-search-form').submit();
                        }
                    });
                }
            })();
            function openAddModal() { document.getElementById('modal-add').style.display = 'flex'; }
            function closeAddModal() { document.getElementById('modal-add').style.display = 'none'; }
            function openEditModal(btn) {
                var d = btn.dataset || {};
                document.getElementById('edit-office-id').value = d.id || '';
                document.getElementById('edit-office-code').value = d.code || '';
                document.getElementById('edit-office-name').value = d.name || '';
                document.getElementById('edit-office-head').value = d.head || '';
                document.getElementById('edit-office-desc').value = d.desc || '';
                document.getElementById('modal-edit').style.display = 'flex';
            }
            function closeEditModal() { document.getElementById('modal-edit').style.display = 'none'; }
            function openAssignHeadModal(btn) {
                var d = btn.dataset || {};
                document.getElementById('assign-office-id').value = d.id || '';
                document.getElementById('assign-office-name').value = d.name || '';
                var headSelect = document.getElementById('assign-office-head');
                if (headSelect) headSelect.value = d.headId || '';
                document.getElementById('modal-assign-head').style.display = 'flex';
            }
            function closeAssignHeadModal() { document.getElementById('modal-assign-head').style.display = 'none'; }
            function confirmDeleteOffice(btn) {
                var d = btn.dataset || {};
                var id = d.id || '';
                var name = d.name || 'this department';
                if (!id) return;
                if (confirm('Are you sure you want to delete "' + name + '"? This action cannot be undone.')) {
                    var form = document.createElement('form');
                    form.method = 'post';
                    form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="office_id" value="' + id + '">';
                    document.body.appendChild(form);
                    form.submit();
                }
            }
            function toggleCardMenu(menuBtn) {
                var dropdown = menuBtn.closest('.dept-card-menu').querySelector('.dept-card-dropdown');
                var open = dropdown.classList.contains('show');
                document.querySelectorAll('.dept-card-dropdown.show').forEach(function(el) { el.classList.remove('show'); });
                if (!open) dropdown.classList.add('show');
            }
            function closeCardMenu(insideEl) {
                var dropdown = insideEl.closest('.dept-card-dropdown');
                if (dropdown) dropdown.classList.remove('show');
            }
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.dept-card-menu')) document.querySelectorAll('.dept-card-dropdown.show').forEach(function(el) { el.classList.remove('show'); });
            });
            function closeDeptToast() {
                var toast = document.getElementById('dept-toast');
                if (toast) toast.remove();
            }
            (function(){
                var toast = document.getElementById('dept-toast');
                if (toast) setTimeout(closeDeptToast, 5000);
            })();
            </script>
    <div class="profile-modal-overlay" id="profile-modal-overlay" aria-hidden="true">
        <div class="profile-modal" id="profile-modal" role="dialog" aria-labelledby="profile-modal-title">
            <div class="profile-modal-header">
                <button type="button" class="profile-modal-close-btn" id="profile-modal-close" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                <h2 class="profile-modal-title" id="profile-modal-title">Profile</h2>
                <p class="profile-modal-subtitle">Your account information and password</p>
            </div>
            <div class="profile-modal-body">
                <div class="profile-info-card">
                    <h3>Profile Information</h3>
                    <p class="profile-info-desc">Your account details and role in the system</p>
                    <div class="profile-info-grid">
                        <div class="profile-info-avatar"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitial); ?><?php endif; ?></div>
                        <span class="profile-info-label">Full Name</span>
                        <p class="profile-info-value"><?php echo htmlspecialchars($userName); ?></p>
                        <span class="profile-info-label">Role</span>
                        <p class="profile-info-value"><?php echo htmlspecialchars($userRole); ?></p>
                        <span class="profile-info-label">Email</span>
                        <p class="profile-info-value"><?php echo htmlspecialchars($userEmail); ?></p>
                        <span class="profile-info-label">Department</span>
                        <p class="profile-info-value"><?php echo htmlspecialchars($userDepartment); ?></p>
                    </div>
                </div>
                <div class="profile-password-card">
                    <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Change Password</h3>
                    <p class="profile-info-desc">Update your account password</p>
                    <form method="post" id="profile-change-password-form" action="offices-department.php">
                        <input type="hidden" name="action" value="change_password">
                        <div class="offices-field">
                            <label for="profile-current-password">Current Password</label>
                            <input type="password" name="current_password" id="profile-current-password" placeholder="Enter current password" autocomplete="current-password">
                        </div>
                        <div class="offices-field">
                            <label for="profile-new-password">New Password</label>
                            <input type="password" name="new_password" id="profile-new-password" placeholder="Enter new password" autocomplete="new-password">
                        </div>
                        <div class="offices-field">
                            <label for="profile-confirm-password">Confirm New Password</label>
                            <input type="password" name="confirm_password" id="profile-confirm-password" placeholder="Confirm new password" autocomplete="new-password">
                        </div>
                        <button type="submit" class="profile-modal-btn-update" style="margin-top:0.75rem;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="settings-modal-overlay" id="settings-modal-overlay" aria-hidden="true">
        <div class="profile-modal" id="settings-modal" role="dialog" aria-labelledby="settings-modal-title">
            <div class="profile-modal-header">
                <button type="button" class="profile-modal-close-btn" id="settings-modal-close" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
                <h2 class="profile-modal-title" id="settings-modal-title">Settings</h2>
                <p class="profile-modal-subtitle">E-signature and profile photo</p>
            </div>
            <div class="profile-modal-body">
                <div class="profile-photo-card">
                    <h3>Profile Photo</h3>
                    <p class="profile-info-desc">Your avatar shown in the sidebar and across the app</p>
                    <div class="profile-photo-row">
                        <div class="profile-photo-avatar"><?php if (!empty($_SESSION['user_photo'])): ?><img src="<?php echo htmlspecialchars($_SESSION['user_photo']); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitial); ?><?php endif; ?></div>
                        <div class="profile-photo-actions">
                            <label class="profile-signature-btn" for="profile-photo-file-input"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Upload Photo</label>
                            <input type="file" id="profile-photo-file-input" accept="image/png,image/jpeg,image/jpg,image/gif" style="display:none;">
                        </div>
                    </div>
                </div>
                <div class="profile-signature-card">
                    <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/></svg>E-Signature</h3>
                    <p class="profile-info-desc">Your digital signature for document approvals</p>
                    <div class="profile-signature-current">
                        <span class="profile-signature-current-label">Current Signature:</span>
                        <div class="profile-signature-box <?php echo $userSignature === '' ? 'empty' : ''; ?>">
                            <?php if ($userSignature !== ''): ?><img src="<?php echo htmlspecialchars($userSignature); ?>" alt="Your signature"><?php else: ?><span>No signature set</span><?php endif; ?>
                        </div>
                    </div>
                    <button type="button" class="profile-signature-btn" id="profile-update-signature-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Update Signature</button>
                </div>
            </div>
        </div>
    </div>
    <div class="signature-modal-overlay" id="signature-modal-overlay">
        <div class="signature-modal">
            <div class="signature-modal-header">
                <h3>Update Signature</h3>
                <button type="button" class="signature-modal-close" id="signature-modal-close" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg></button>
            </div>
            <div class="signature-tabs">
                <button type="button" class="signature-tab active" data-pane="upload">Upload Picture</button>
                <button type="button" class="signature-tab" data-pane="draw">Draw Signature</button>
            </div>
            <div class="signature-modal-body">
                <div class="signature-pane active" id="signature-pane-upload">
                    <label class="signature-upload-zone" id="signature-upload-zone" for="signature-file-input">
                        <span>Click or drag an image here (PNG, JPG)</span>
                        <input type="file" id="signature-file-input" accept="image/png,image/jpeg,image/jpg,image/gif">
                        <img class="signature-upload-preview" id="signature-upload-preview" alt="Preview">
                    </label>
                </div>
                <div class="signature-pane" id="signature-pane-draw">
                    <div class="signature-canvas-wrap">
                        <canvas id="signature-pad" width="428" height="200"></canvas>
                    </div>
                    <div class="signature-actions">
                        <button type="button" class="profile-signature-btn btn-clear" id="signature-clear-btn">Clear</button>
                    </div>
                </div>
            </div>
            <div class="signature-modal-footer">
                <button type="button" class="offices-btn offices-btn-secondary" id="signature-modal-cancel">Cancel</button>
                <button type="button" class="offices-btn" id="signature-save-btn">Save Signature</button>
            </div>
        </div>
    </div>
    <form method="post" id="signature-update-form" action="offices-department.php" style="display:none;">
        <input type="hidden" name="action" value="update_signature">
        <input type="hidden" name="signature" id="signature-hidden-input">
    </form>
    <form method="post" id="profile-photo-form" action="offices-department.php" style="display:none;">
        <input type="hidden" name="action" value="update_photo">
        <input type="hidden" name="photo" id="profile-photo-hidden-input">
    </form>
<script>
(function(){
    var notifBtn = document.getElementById('notif-btn');
    var notifDropdown = document.getElementById('notif-dropdown');
    function closeNotif(){ if (notifDropdown) notifDropdown.style.display = 'none'; }
    if (notifBtn) notifBtn.addEventListener('click', function(e){ e.stopPropagation(); if (!notifDropdown) return; var showing = notifDropdown.style.display === 'block'; closeNotif(); notifDropdown.style.display = showing ? 'none' : 'block'; });
    document.addEventListener('click', function(){ closeNotif(); });
})();
(function(){
    var accountBtn = document.getElementById('sidebar-account-btn');
    var accountDropdown = document.getElementById('account-dropdown');
    var profileOverlay = document.getElementById('profile-modal-overlay');
    var profileCloseBtn = document.getElementById('profile-modal-close');
    var profileTrigger = document.getElementById('account-dropdown-profile');
    function openProfileModal(){ if (profileOverlay) { profileOverlay.classList.add('profile-modal-open'); profileOverlay.setAttribute('aria-hidden', 'false'); } }
    function closeProfileModal(){ if (profileOverlay) { profileOverlay.classList.remove('profile-modal-open'); profileOverlay.setAttribute('aria-hidden', 'true'); } }
    function closeAccountDropdown(){ if (accountDropdown) { accountDropdown.classList.remove('open'); if (accountBtn) accountBtn.setAttribute('aria-expanded', 'false'); } }
    if (accountBtn && accountDropdown) {
        accountBtn.addEventListener('click', function(e){ e.stopPropagation(); accountDropdown.classList.toggle('open'); accountBtn.setAttribute('aria-expanded', accountDropdown.classList.contains('open')); });
        accountBtn.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); accountDropdown.classList.add('open'); accountBtn.setAttribute('aria-expanded', 'true'); } });
        document.addEventListener('click', function(e){ if (!e.target.closest('.sidebar-user-wrap')) closeAccountDropdown(); });
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeAccountDropdown(); });
    }
    if (profileTrigger) profileTrigger.addEventListener('click', function(){ closeAccountDropdown(); openProfileModal(); });
    if (profileCloseBtn) profileCloseBtn.addEventListener('click', closeProfileModal);
    if (window.location.search.indexOf('open=profile') !== -1) openProfileModal();
    var settingsOverlay = document.getElementById('settings-modal-overlay');
    var settingsCloseBtn = document.getElementById('settings-modal-close');
    var sidebarSettingsBtn = document.getElementById('sidebar-settings-btn');
    function openSettingsModal(){ if (settingsOverlay) { settingsOverlay.classList.add('settings-modal-open'); settingsOverlay.setAttribute('aria-hidden', 'false'); } }
    function closeSettingsModal(){ if (settingsOverlay) { settingsOverlay.classList.remove('settings-modal-open'); settingsOverlay.setAttribute('aria-hidden', 'true'); } }
    if (sidebarSettingsBtn) sidebarSettingsBtn.addEventListener('click', function(e){ e.preventDefault(); openSettingsModal(); });
    if (settingsCloseBtn) settingsCloseBtn.addEventListener('click', closeSettingsModal);
})();
(function(){
    var settingsOverlay = document.getElementById('settings-modal-overlay');
    var sigOverlay = document.getElementById('signature-modal-overlay');
    var openSigBtn = document.getElementById('profile-update-signature-btn');
    var closeSigBtn = document.getElementById('signature-modal-close');
    var cancelSigBtn = document.getElementById('signature-modal-cancel');
    var tabs = document.querySelectorAll('.signature-tab');
    var paneUpload = document.getElementById('signature-pane-upload');
    var paneDraw = document.getElementById('signature-pane-draw');
    var fileInput = document.getElementById('signature-file-input');
    var uploadZone = document.getElementById('signature-upload-zone');
    var uploadPreview = document.getElementById('signature-upload-preview');
    var canvas = document.getElementById('signature-pad');
    var clearBtn = document.getElementById('signature-clear-btn');
    var saveBtn = document.getElementById('signature-save-btn');
    var form = document.getElementById('signature-update-form');
    var hiddenInput = document.getElementById('signature-hidden-input');
    var currentSignatureData = '';
    function openSignatureModal() {
        if (settingsOverlay) settingsOverlay.classList.remove('settings-modal-open');
        currentSignatureData = '';
        if (uploadPreview) { uploadPreview.src = ''; uploadPreview.classList.remove('show'); }
        if (fileInput) fileInput.value = '';
        if (canvas) { var ctx = canvas.getContext('2d'); if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height); }
        tabs.forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-pane') === 'upload'); });
        if (paneUpload) paneUpload.classList.add('active');
        if (paneDraw) paneDraw.classList.remove('active');
        if (sigOverlay) sigOverlay.classList.add('signature-modal-open');
    }
    function closeSignatureModal() {
        if (sigOverlay) sigOverlay.classList.remove('signature-modal-open');
        if (settingsOverlay) settingsOverlay.classList.add('settings-modal-open');
    }
    if (openSigBtn) openSigBtn.addEventListener('click', openSignatureModal);
    if (closeSigBtn) closeSigBtn.addEventListener('click', closeSignatureModal);
    if (cancelSigBtn) cancelSigBtn.addEventListener('click', closeSignatureModal);
    if (sigOverlay) sigOverlay.addEventListener('click', function(e){ if (e.target === sigOverlay) closeSignatureModal(); });
    tabs.forEach(function(tab){
        tab.addEventListener('click', function(){
            var pane = tab.getAttribute('data-pane');
            tabs.forEach(function(t){ t.classList.toggle('active', t.getAttribute('data-pane') === pane); });
            if (pane === 'upload') { paneUpload.classList.add('active'); paneDraw.classList.remove('active'); }
            else { paneUpload.classList.remove('active'); paneDraw.classList.add('active'); }
        });
    });
    function setSignatureFromDataUrl(dataUrl) { currentSignatureData = dataUrl || ''; }
    if (uploadZone && fileInput) {
        uploadZone.addEventListener('click', function(e){ if (e.target !== fileInput) fileInput.click(); });
        uploadZone.addEventListener('dragover', function(e){ e.preventDefault(); uploadZone.classList.add('dragover'); });
        uploadZone.addEventListener('dragleave', function(){ uploadZone.classList.remove('dragover'); });
        uploadZone.addEventListener('drop', function(e){ e.preventDefault(); uploadZone.classList.remove('dragover'); if (e.dataTransfer.files.length && e.dataTransfer.files[0].type.indexOf('image/') === 0) { var r = new FileReader(); r.onload = function(){ setSignatureFromDataUrl(r.result); uploadPreview.src = r.result; uploadPreview.classList.add('show'); }; r.readAsDataURL(e.dataTransfer.files[0]); } });
        fileInput.addEventListener('change', function(){ if (fileInput.files.length) { var r = new FileReader(); r.onload = function(){ setSignatureFromDataUrl(r.result); uploadPreview.src = r.result; uploadPreview.classList.add('show'); }; r.readAsDataURL(fileInput.files[0]); } });
    }
    if (canvas) {
        var ctx = canvas.getContext('2d');
        var drawing = false;
        function getPos(e){ var rect = canvas.getBoundingClientRect(); var scaleX = canvas.width/rect.width, scaleY = canvas.height/rect.height; var x = e.touches ? e.touches[0].clientX : e.clientX; var y = e.touches ? e.touches[0].clientY : e.clientY; return { x: (x - rect.left)*scaleX, y: (y - rect.top)*scaleY }; }
        function start(e){ e.preventDefault(); drawing = true; var p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
        function move(e){ e.preventDefault(); if (!drawing) return; var p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
        function end(e){ e.preventDefault(); drawing = false; setSignatureFromDataUrl(canvas.toDataURL('image/png')); }
        ctx.strokeStyle = '#1e293b'; ctx.lineWidth = 2; ctx.lineCap = 'round';
        canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); canvas.addEventListener('mouseup', end); canvas.addEventListener('mouseleave', end);
        canvas.addEventListener('touchstart', start, { passive: false }); canvas.addEventListener('touchmove', move, { passive: false }); canvas.addEventListener('touchend', end, { passive: false });
    }
    if (clearBtn && canvas) clearBtn.addEventListener('click', function(){ canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height); setSignatureFromDataUrl(''); });
    if (saveBtn && form && hiddenInput) saveBtn.addEventListener('click', function(){
        var data = (paneDraw && paneDraw.classList.contains('active') && canvas) ? canvas.toDataURL('image/png') : currentSignatureData;
        if (!data) { alert('Please upload an image or draw your signature.'); return; }
        hiddenInput.value = data;
        form.submit();
    });
})();
(function(){
    var fileInput = document.getElementById('profile-photo-file-input');
    var form = document.getElementById('profile-photo-form');
    var hiddenInput = document.getElementById('profile-photo-hidden-input');
    if (fileInput && form && hiddenInput) fileInput.addEventListener('change', function(){
        if (!fileInput.files || !fileInput.files.length) return;
        if (fileInput.files[0].type.indexOf('image/') !== 0) { alert('Please choose an image file.'); return; }
        var r = new FileReader();
        r.onload = function(){ hiddenInput.value = r.result; form.submit(); };
        r.readAsDataURL(fileInput.files[0]);
    });
})();
</script>
        </div>
    </div>
</body>
</html>
