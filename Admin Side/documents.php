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

if (!function_exists('getUserPhoto')) require_once __DIR__ . '/../Super Admin Side/_account_helpers.php';
if (function_exists('getUserPhoto') && !empty($_SESSION['user_id'])) { $fp = getUserPhoto($_SESSION['user_id']); if ($fp !== '') $_SESSION['user_photo'] = $fp; }

$config = require __DIR__ . '/../config.php';
$documentsNamespace = $config['database'] . '.documents';
$documentsList = [];
$addMessage = null;
$addError = null;

// View document (inline – open in browser/viewer, do not download)
if (!empty($_GET['view']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['view'])) {
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query(['_id' => new MongoDB\BSON\ObjectId($_GET['view'])]);
        $cursor = $manager->executeQuery($documentsNamespace, $query);
        $docs = $cursor->toArray();
        if (count($docs) > 0) {
            $doc = (array)$docs[0];
            $fileName = $doc['fileName'] ?? 'document.docx';
            $fileContent = $doc['fileContent'] ?? '';
            if ($fileContent !== '') {
                header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName) . '"');
                echo base64_decode($fileContent, true) ?: $fileContent;
                exit;
            }
        }
    } catch (Exception $e) {}
    header('HTTP/1.1 404 Not Found');
    exit;
}

// Download document file (attachment)
if (!empty($_GET['download']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['download'])) {
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query(['_id' => new MongoDB\BSON\ObjectId($_GET['download'])]);
        $cursor = $manager->executeQuery($documentsNamespace, $query);
        $docs = $cursor->toArray();
        if (count($docs) > 0) {
            $doc = (array)$docs[0];
            $fileName = $doc['fileName'] ?? 'document.docx';
            $fileContent = $doc['fileContent'] ?? '';
            if ($fileContent !== '') {
                header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName) . '"');
                echo base64_decode($fileContent, true) ?: $fileContent;
                exit;
            }
        }
    } catch (Exception $e) {}
    header('HTTP/1.1 404 Not Found');
    exit;
}

// Archive document and log to document history
if (!empty($_GET['archive']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['archive'])) {
    $archiveId = $_GET['archive'];
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query(['_id' => new MongoDB\BSON\ObjectId($archiveId)]);
        $cursor = $manager->executeQuery($documentsNamespace, $query);
        $docs = $cursor->toArray();
        if (count($docs) > 0) {
            $doc = (array)$docs[0];
            $docCode = $doc['documentCode'] ?? $doc['document_code'] ?? '';
            $docTitle = $doc['documentTitle'] ?? $doc['document_title'] ?? '';
            $bulk = new MongoDB\Driver\BulkWrite;
            $bulk->update(
                ['_id' => new MongoDB\BSON\ObjectId($archiveId)],
                ['$set' => ['status' => 'archived']],
                ['multi' => false]
            );
            $manager->executeBulkWrite($documentsNamespace, $bulk);
            $historyNamespace = $config['database'] . '.document_history';
            $historyBulk = new MongoDB\Driver\BulkWrite;
            $historyBulk->insert([
                'documentId'    => $archiveId,
                'documentCode'  => $docCode,
                'documentTitle' => $docTitle,
                'action'        => 'Archived',
                'dateTime'      => new MongoDB\BSON\UTCDateTime(),
                'userId'        => $_SESSION['user_id'] ?? '',
                'userName'      => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
            ]);
            $manager->executeBulkWrite($historyNamespace, $historyBulk);
            // Remove from "Received from Super Admin" list if it was there
            $sentToAdminNamespace = $config['database'] . '.sent_to_admin';
            $deleteBulk = new MongoDB\Driver\BulkWrite;
            $deleteBulk->delete(['documentId' => $archiveId], ['limit' => 0]);
            $manager->executeBulkWrite($sentToAdminNamespace, $deleteBulk);
        }
    } catch (Exception $e) {}
    header('Location: documents.php');
    exit;
}

// Send document to department head(s) (POST from Send modal – multiple allowed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_to_head') {
    $docId = trim($_POST['document_id'] ?? '');
    $officeIds = isset($_POST['office_id']) ? (is_array($_POST['office_id']) ? $_POST['office_id'] : [$_POST['office_id']]) : [];
    $officeIds = array_filter(array_map('trim', $officeIds));
    $officeIds = array_values(array_unique($officeIds));
    if (preg_match('/^[a-f0-9]{24}$/i', $docId) && count($officeIds) > 0) {
        try {
            $manager = new MongoDB\Driver\Manager($config['uri']);
            $query = new MongoDB\Driver\Query(['_id' => new MongoDB\BSON\ObjectId($docId)]);
            $cursor = $manager->executeQuery($documentsNamespace, $query);
            $docs = $cursor->toArray();
            if (count($docs) > 0) {
                $doc = (array)$docs[0];
                $officesNamespace = $config['database'] . '.' . ($config['collection'] ?? 'offices');
                $sentNamespace = $config['database'] . '.sent_to_department_heads';
                $bulk = new MongoDB\Driver\BulkWrite;
                $sentCount = 0;
                foreach ($officeIds as $officeId) {
                    if (!preg_match('/^[a-f0-9]{24}$/i', $officeId)) continue;
                    $oq = new MongoDB\Driver\Query(['_id' => new MongoDB\BSON\ObjectId($officeId)]);
                    $oCursor = $manager->executeQuery($officesNamespace, $oq);
                    $offices = $oCursor->toArray();
                    if (count($offices) > 0) {
                        $office = (array)$offices[0];
                        $officeHeadId = $office['office_head_id'] ?? '';
                        $officeHeadName = $office['office_head'] ?? '';
                        $officeName = $office['office_name'] ?? $office['department'] ?? $office['office_code'] ?? 'Department';
                        if ($officeHeadId !== '' || $officeHeadName !== '') {
                            $bulk->insert([
                                'documentId'      => $docId,
                                'officeId'        => $officeId,
                                'officeName'      => $officeName,
                                'officeHeadId'    => $officeHeadId,
                                'officeHeadName'  => $officeHeadName,
                                'sentAt'          => new MongoDB\BSON\UTCDateTime(),
                                'sentByUserId'    => $_SESSION['user_id'] ?? '',
                                'sentByUserName'  => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
                            ]);
                            $sentCount++;
                        }
                    }
                }
                if ($sentCount > 0) {
                    $manager->executeBulkWrite($sentNamespace, $bulk);
                    header('Location: documents.php?sent_head=1&count=' . (int)$sentCount);
                    exit;
                }
            }
        } catch (Exception $e) {}
    }
    header('Location: documents.php?send_error=1');
    exit;
}

// Send document to Super Admin Side (legacy GET – Send button now opens modal for department heads)
if (!empty($_GET['send']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['send'])) {
    $sendId = $_GET['send'];
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query(['_id' => new MongoDB\BSON\ObjectId($sendId)]);
        $cursor = $manager->executeQuery($documentsNamespace, $query);
        $docs = $cursor->toArray();
        if (count($docs) > 0) {
            $doc = (array)$docs[0];
            $docCode = $doc['documentCode'] ?? $doc['document_code'] ?? '';
            $docTitle = $doc['documentTitle'] ?? $doc['document_title'] ?? '';
            $fileName = $doc['fileName'] ?? $doc['file_name'] ?? 'document.docx';
            $sentNamespace = $config['database'] . '.sent_to_super_admin';
            $bulk = new MongoDB\Driver\BulkWrite;
            $bulk->insert([
                'documentId'     => $sendId,
                'documentCode'   => $docCode,
                'documentTitle'  => $docTitle,
                'fileName'       => $fileName,
                'sentAt'         => new MongoDB\BSON\UTCDateTime(),
                'sentByUserId'   => $_SESSION['user_id'] ?? '',
                'sentByUserName' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
            ]);
            $manager->executeBulkWrite($sentNamespace, $bulk);
        }
    } catch (Exception $e) {}
    header('Location: documents.php?sent=1');
    exit;
}

// Add document (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_document') {
    $documentCode = trim($_POST['document_code'] ?? '');
    $documentTitle = trim($_POST['document_title'] ?? '');
    if ($documentCode === '' || $documentTitle === '') {
        $addError = 'Document code and title are required.';
    } elseif (empty($_FILES['document_file']['tmp_name']) || !is_uploaded_file($_FILES['document_file']['tmp_name'])) {
        $addError = 'Please select a DOCX file to upload.';
    } else {
        $file = $_FILES['document_file'];
        $fname = $file['name'] ?? '';
        if (!preg_match('/\.docx$/i', $fname)) {
            $addError = 'Only .docx files are allowed.';
        } else {
            $fileContent = base64_encode(file_get_contents($file['tmp_name']));
            if ($fileContent === false) {
                $addError = 'Could not read the uploaded file.';
            } else {
                try {
                    $manager = new MongoDB\Driver\Manager($config['uri']);
                    $newId = new MongoDB\BSON\ObjectId();
                    $now = new MongoDB\BSON\UTCDateTime();
                    $bulk = new MongoDB\Driver\BulkWrite;
                    $bulk->insert([
                        '_id'           => $newId,
                        'documentCode'  => $documentCode,
                        'documentTitle' => $documentTitle,
                        'fileName'      => $fname,
                        'fileContent'   => $fileContent,
                        'createdAt'     => $now,
                        'createdBy'     => $_SESSION['user_id'] ?? '',
                        'status'        => 'active',
                    ]);
                    $manager->executeBulkWrite($documentsNamespace, $bulk);
                    $historyNamespace = $config['database'] . '.document_history';
                    $historyBulk = new MongoDB\Driver\BulkWrite;
                    $historyBulk->insert([
                        'documentId'    => (string)$newId,
                        'documentCode'  => $documentCode,
                        'documentTitle' => $documentTitle,
                        'action'        => 'Added',
                        'dateTime'      => $now,
                        'userId'        => $_SESSION['user_id'] ?? '',
                        'userName'      => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
                    ]);
                    $manager->executeBulkWrite($historyNamespace, $historyBulk);
                    header('Location: documents.php?added=1');
                    exit;
} catch (Exception $e) {
    $addError = 'Failed to save document: ' . $e->getMessage();
                }
            }
        }
    }
    if ($addError) {
        $_SESSION['documents_add_error'] = $addError;
        header('Location: documents.php?add_error=1');
        exit;
    }
}

// Fetch documents from database (active only; exclude archived)
try {
    $manager = new MongoDB\Driver\Manager($config['uri']);
    $filter = ['status' => ['$ne' => 'archived']];
    $query = new MongoDB\Driver\Query($filter, ['sort' => ['createdAt' => -1], 'limit' => 500]);
    $cursor = $manager->executeQuery($documentsNamespace, $query);
    foreach ($cursor as $doc) {
        $arr = (array)$doc;
        $arr['_id'] = (string)($arr['_id'] ?? '');
        $documentsList[] = $arr;
    }
} catch (Exception $e) {
    $documentsList = [];
}

// Department heads (offices with assigned head) for Send document modal
$departmentHeadsList = [];
try {
    $officesNamespace = $config['database'] . '.' . ($config['collection'] ?? 'offices');
    $manager = new MongoDB\Driver\Manager($config['uri']);
    $query = new MongoDB\Driver\Query([], ['sort' => ['office_name' => 1]]);
    $cursor = $manager->executeQuery($officesNamespace, $query);
    foreach ($cursor as $doc) {
        $d = (array)$doc;
        $headId = trim($d['office_head_id'] ?? '');
        $headName = trim($d['office_head'] ?? '');
        if ($headId !== '' || $headName !== '') {
            $departmentHeadsList[] = [
                'id'             => (string)($d['_id'] ?? ''),
                'office_name'    => $d['office_name'] ?? $d['department'] ?? $d['name'] ?? $d['office_code'] ?? '—',
                'office_head'    => $headName !== '' ? $headName : '—',
                'office_head_id' => $headId,
            ];
        }
    }
} catch (Exception $e) {
    $departmentHeadsList = [];
}

// Merge in documents sent from Super Admin: show them in the same Documents table
$sentToAdminNamespace = $config['database'] . '.sent_to_admin';
$idsInList = array_column($documentsList, '_id');
$idsInList = array_flip(array_filter($idsInList));
try {
    $query = new MongoDB\Driver\Query([], ['sort' => ['sentAt' => -1], 'limit' => 500]);
    $cursor = $manager->executeQuery($sentToAdminNamespace, $query);
    foreach ($cursor as $row) {
        $arr = (array)$row;
        $docId = (string)($arr['documentId'] ?? '');
        if ($docId === '' || isset($idsInList[$docId])) continue;
        $idsInList[$docId] = true;
        $documentsList[] = [
            '_id'            => $docId,
            'documentCode'  => $arr['documentCode'] ?? $arr['document_code'] ?? '—',
            'documentTitle' => $arr['documentTitle'] ?? $arr['document_title'] ?? '—',
            'fileName'       => $arr['fileName'] ?? $arr['file_name'] ?? 'document.docx',
        ];
    }
} catch (Exception $e) {}

$added = isset($_GET['added']) && $_GET['added'] === '1';
$sent = isset($_GET['sent']) && $_GET['sent'] === '1';
$sentHead = isset($_GET['sent_head']) && $_GET['sent_head'] === '1';
$sentHeadCount = isset($_GET['count']) ? (int)$_GET['count'] : 0;
if (isset($_GET['add_error']) && isset($_SESSION['documents_add_error'])) {
    $addError = $_SESSION['documents_add_error'];
    unset($_SESSION['documents_add_error']);
}
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
    <link rel="stylesheet" href="profile_modal_admin.css">
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
    .document-status { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; text-transform: capitalize; }
    .document-status-active { background: #d1fae5; color: #047857; }
    .document-status-archived { background: #f3f4f6; color: #6b7280; }
    .document-status-received { background: #dbeafe; color: #1d4ed8; }
    .documents-actions-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .documents-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit; transition: background 0.15s, color 0.15s; }
    .documents-action-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
    .documents-action-open { background: #dbeafe; color: #1d4ed8; }
    .documents-action-open:hover { background: #bfdbfe; color: #1d4ed8; }
    .documents-action-archive { background: #fef3c7; color: #b45309; }
    .documents-action-archive:hover { background: #fde68a; color: #b45309; }
    .documents-action-send { background: #d1fae5; color: #047857; }
    .documents-action-send:hover { background: #a7f3d0; color: #047857; }
    #send-document-modal .doc-modal-dialog { max-width: 440px; }
    .send-modal-subtitle { margin: 0 0 1rem 0; font-size: 0.9rem; color: #64748b; line-height: 1.45; }
    .send-heads-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; }
    .send-heads-toolbar-links { display: flex; gap: 12px; font-size: 13px; }
    .send-heads-toolbar-links button { background: none; border: none; color: #3B82F6; cursor: pointer; padding: 0; font-family: inherit; font-size: inherit; font-weight: 500; }
    .send-heads-toolbar-links button:hover { text-decoration: underline; color: #2563eb; }
    .send-heads-toolbar-count { font-size: 13px; color: #64748b; font-weight: 500; }
    .send-heads-list { max-height: 320px; overflow-y: auto; padding-right: 4px; }
    .send-heads-list::-webkit-scrollbar { width: 6px; }
    .send-heads-list::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
    .send-heads-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    .send-head-row { display: flex; align-items: center; gap: 14px; padding: 12px 14px; border: 1.5px solid #e2e8f0; border-radius: 10px; margin-bottom: 8px; cursor: pointer; transition: background 0.2s, border-color 0.2s, box-shadow 0.2s; }
    .send-head-row:hover { background: #f8fafc; border-color: #cbd5e1; }
    .send-head-row.selected { background: #ecfdf5; border-color: #10b981; box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.2); }
    .send-head-row input[type="checkbox"] { width: 18px; height: 18px; flex-shrink: 0; accent-color: #10b981; cursor: pointer; }
    .send-head-row-content { flex: 1; min-width: 0; }
    .send-head-office { display: block; font-weight: 600; color: #1e293b; font-size: 0.95rem; margin-bottom: 2px; }
    .send-head-name { display: block; color: #64748b; font-size: 0.875rem; }
    .send-modal-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #e2e8f0; }
    .send-modal-actions .doc-btn-save { min-width: 120px; }
    @media (max-width: 980px) { .documents-tools { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body<?php if (!empty($addError)): ?> data-add-error="1"<?php endif; ?><?php if (!empty($added)): ?> data-added="1"<?php endif; ?><?php if (!empty($sent)): ?> data-sent="1"<?php endif; ?><?php if (!empty($sentHead)): ?> data-sent-head="1" data-sent-head-count="<?php echo (int)$sentHeadCount; ?>"<?php endif; ?>>
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
                                    <th>STATUS</th>
                                    <th>ACTION</th>
                                </tr>
                            </thead>
                            <tbody id="documents-table-body">
                                <?php if (empty($documentsList)): ?>
                                <tr>
                                    <td colspan="6" class="documents-empty" id="no-documents-row">No documents yet.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($documentsList as $idx => $doc): ?>
                                <?php
                                    $docId = $doc['_id'] ?? '';
                                    $docCode = htmlspecialchars($doc['documentCode'] ?? $doc['document_code'] ?? '—');
                                    $docTitle = htmlspecialchars($doc['documentTitle'] ?? $doc['document_title'] ?? '—');
                                    $docFileName = htmlspecialchars($doc['fileName'] ?? $doc['file_name'] ?? '—');
                                    $docStatus = isset($doc['status']) ? ucfirst(strtolower($doc['status'])) : 'Active';
                                ?>
                                <tr data-document-row data-document-id="<?php echo htmlspecialchars($docId); ?>">
                                    <td><?php echo (int)($idx + 1); ?></td>
                                    <td><?php echo $docCode; ?></td>
                                    <td><?php echo $docTitle; ?></td>
                                    <td><a href="documents.php?download=<?php echo urlencode($docId); ?>" class="doc-file-link"><?php echo $docFileName; ?></a></td>
                                    <td><span class="document-status document-status-<?php echo strtolower(htmlspecialchars($docStatus)); ?>"><?php echo htmlspecialchars($docStatus); ?></span></td>
                                    <td>
                                        <div class="documents-actions-row">
                                            <a href="documents.php?download=<?php echo urlencode($docId); ?>" class="documents-action-btn documents-action-open" title="Download document"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download</a>
                                            <button type="button" class="documents-action-btn documents-action-send" data-document-id="<?php echo htmlspecialchars($docId); ?>" data-open-send-modal title="Send document"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send</button>
                                            <a href="documents.php?archive=<?php echo urlencode($docId); ?>" class="documents-action-btn documents-action-archive" title="Archive document" onclick="return confirm('Archive this document?');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><path d="M1 3h22v5H1z"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archive</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
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
            <form id="add-document-form" class="doc-modal-form" method="post" action="documents.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_document">
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
                <p class="doc-form-error" id="document-form-error" <?php if (empty($addError)): ?>hidden<?php endif; ?>><?php if (!empty($addError)): echo htmlspecialchars($addError); endif; ?></p>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-add-document>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Save Document</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="send-document-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-send-document aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="send-document-title">
            <div class="doc-modal-header">
                <h2 id="send-document-title">Send document</h2>
                <button type="button" class="doc-modal-close" data-close-send-document aria-label="Close">&times;</button>
            </div>
            <p class="send-modal-subtitle">Select one or more department heads to send this document to.</p>
            <form method="post" action="documents.php" id="send-document-form" class="doc-modal-form">
                <input type="hidden" name="action" value="send_to_head">
                <input type="hidden" name="document_id" id="send-document-id" value="">
                <?php if (!empty($departmentHeadsList)): ?>
                <div class="send-heads-toolbar">
                    <span class="send-heads-toolbar-count" id="send-selection-count">0 selected</span>
                    <div class="send-heads-toolbar-links">
                        <button type="button" id="send-select-all" aria-label="Select all">Select all</button>
                        <button type="button" id="send-clear-all" aria-label="Clear selection">Clear</button>
                    </div>
                </div>
                <?php endif; ?>
                <div class="send-heads-list" id="send-heads-list">
                    <?php if (empty($departmentHeadsList)): ?>
                    <p class="documents-empty" style="padding: 1.25rem; color: #64748b; text-align: center; margin: 0;">No department heads assigned yet. Assign heads in <strong>Departments</strong> first.</p>
                    <?php else: ?>
                    <?php foreach ($departmentHeadsList as $head): ?>
                    <label class="send-head-row" data-send-head>
                        <input type="checkbox" name="office_id[]" value="<?php echo htmlspecialchars($head['id']); ?>" class="send-head-cb">
                        <span class="send-head-row-content">
                            <span class="send-head-office"><?php echo htmlspecialchars($head['office_name']); ?></span>
                            <span class="send-head-name"><?php echo htmlspecialchars($head['office_head']); ?></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="send-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-send-document>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save" id="send-submit-btn" <?php if (empty($departmentHeadsList)): ?>disabled<?php endif; ?>>Send</button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/_profile_modal_admin.php'; ?>
    <script src="sidebar_admin.js"></script>
    <script>
    (function() {
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

        // Open modal on load when there was an add error (after POST redirect)
        if (addModal && document.body.getAttribute('data-add-error') === '1') {
            addModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        // Show success message when document was added
        if (document.body.getAttribute('data-added') === '1') {
            var toast = document.createElement('div');
            toast.className = 'documents-toast documents-toast-success';
            toast.setAttribute('role', 'status');
            toast.textContent = 'Document saved successfully.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }
        // Show success message when document was sent to Super Admin
        if (document.body.getAttribute('data-sent') === '1') {
            var toast = document.createElement('div');
            toast.className = 'documents-toast documents-toast-success';
            toast.setAttribute('role', 'status');
            toast.textContent = 'Document sent to Super Admin.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }
        // Show success when document was sent to department head(s)
        if (document.body.getAttribute('data-sent-head') === '1') {
            var count = parseInt(document.body.getAttribute('data-sent-head-count') || '1', 10);
            var toast = document.createElement('div');
            toast.className = 'documents-toast documents-toast-success';
            toast.setAttribute('role', 'status');
            toast.textContent = count === 1 ? 'Document sent to 1 department head.' : 'Document sent to ' + count + ' department heads.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }

        // Send document modal: open, multi-select, select all / clear
        var sendModal = document.getElementById('send-document-modal');
        var sendDocumentIdInput = document.getElementById('send-document-id');
        var sendHeadsList = document.getElementById('send-heads-list');
        var sendSelectionCount = document.getElementById('send-selection-count');
        var sendSubmitBtn = document.getElementById('send-submit-btn');
        var sendSelectAllBtn = document.getElementById('send-select-all');
        var sendClearAllBtn = document.getElementById('send-clear-all');

        function updateSendSelection() {
            if (!sendHeadsList) return;
            var cbs = sendHeadsList.querySelectorAll('.send-head-cb');
            var count = 0;
            cbs.forEach(function(cb) {
                if (cb.checked) count++;
                var row = cb.closest('.send-head-row');
                if (row) row.classList.toggle('selected', cb.checked);
            });
            if (sendSelectionCount) sendSelectionCount.textContent = count === 0 ? '0 selected' : count + ' selected';
            if (sendSubmitBtn) {
                sendSubmitBtn.disabled = count === 0;
                sendSubmitBtn.textContent = count === 0 ? 'Send' : (count === 1 ? 'Send to 1 head' : 'Send to ' + count + ' heads');
            }
        }

        document.querySelectorAll('[data-open-send-modal]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var docId = this.getAttribute('data-document-id') || '';
                if (sendDocumentIdInput) sendDocumentIdInput.value = docId;
                if (sendHeadsList) sendHeadsList.querySelectorAll('.send-head-cb').forEach(function(cb) { cb.checked = false; });
                updateSendSelection();
                if (sendModal) {
                    sendModal.hidden = false;
                    document.body.classList.add('modal-open');
                }
            });
        });

        if (sendHeadsList) {
            sendHeadsList.addEventListener('change', function(e) {
                if (e.target.classList.contains('send-head-cb')) updateSendSelection();
            });
        }
        if (sendSelectAllBtn && sendHeadsList) {
            sendSelectAllBtn.addEventListener('click', function() {
                sendHeadsList.querySelectorAll('.send-head-cb').forEach(function(cb) { cb.checked = true; });
                updateSendSelection();
            });
        }
        if (sendClearAllBtn && sendHeadsList) {
            sendClearAllBtn.addEventListener('click', function() {
                sendHeadsList.querySelectorAll('.send-head-cb').forEach(function(cb) { cb.checked = false; });
                updateSendSelection();
            });
        }

        function closeSendDocumentModal() {
            if (sendModal) {
                sendModal.hidden = true;
                document.body.classList.remove('modal-open');
                if (sendDocumentIdInput) sendDocumentIdInput.value = '';
            }
        }
        document.querySelectorAll('[data-close-send-document]').forEach(function(btn) {
            btn.addEventListener('click', closeSendDocumentModal);
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sendModal && !sendModal.hidden) closeSendDocumentModal();
        });
    })();
    </script>
</body>
</html>
