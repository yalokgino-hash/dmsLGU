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

$config = require __DIR__ . '/../config.php';
$documentsNamespace = $config['database'] . '.documents';
$sentNamespace = $config['database'] . '.sent_to_super_admin';
$notificationsNamespace = $config['database'] . '.notifications';

// Mark notifications as read (redirect back)
if (!empty($_GET['mark_notifications_read'])) {
    $uid = $_SESSION['user_id'] ?? '';
    if ($uid !== '') {
        try {
            $manager = new MongoDB\Driver\Manager($config['uri']);
            $bulk = new MongoDB\Driver\BulkWrite;
            $bulk->update(
                ['userId' => $uid],
                ['$set' => ['read' => true]],
                ['multi' => true]
            );
            $manager->executeBulkWrite($notificationsNamespace, $bulk);
        } catch (Exception $e) {}
    }
    header('Location: documents.php');
    exit;
}

// View document (open in browser/new tab – inline)
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

// Download document (file stored in documents collection)
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

// Send document to Admin Side (record in sent_to_admin)
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
            $sentToAdminNamespace = $config['database'] . '.sent_to_admin';
            $bulk = new MongoDB\Driver\BulkWrite;
            $bulk->insert([
                'documentId'     => $sendId,
                'documentCode'   => $docCode,
                'documentTitle'  => $docTitle,
                'fileName'       => $fileName,
                'sentAt'         => new MongoDB\BSON\UTCDateTime(),
                'sentByUserId'   => $_SESSION['user_id'] ?? '',
                'sentByUserName'  => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
            ]);
            $manager->executeBulkWrite($sentToAdminNamespace, $bulk);
        }
    } catch (Exception $e) {}
    header('Location: documents.php?sent=1');
    exit;
}

// Case Close: same as archive – set status to archived, log to document history, remove from sent list
if (!empty($_GET['case_close']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['case_close'])) {
    $archiveId = $_GET['case_close'];
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
                'action'        => 'Case Close',
                'dateTime'      => new MongoDB\BSON\UTCDateTime(),
                'userId'        => $_SESSION['user_id'] ?? '',
                'userName'      => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
            ]);
            $manager->executeBulkWrite($historyNamespace, $historyBulk);
            $deleteBulk = new MongoDB\Driver\BulkWrite;
            $deleteBulk->delete(['documentId' => $archiveId], ['limit' => 0]);
            $manager->executeBulkWrite($sentNamespace, $deleteBulk);
        }
    } catch (Exception $e) {}
    header('Location: documents.php?case_closed=1');
    exit;
}

// Add document (POST) – save to database
$addError = null;
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
                    $bulk = new MongoDB\Driver\BulkWrite;
                    $bulk->insert([
                        'documentCode'  => $documentCode,
                        'documentTitle' => $documentTitle,
                        'fileName'      => $fname,
                        'fileContent'   => $fileContent,
                        'createdAt'     => new MongoDB\BSON\UTCDateTime(),
                        'createdBy'     => $_SESSION['user_id'] ?? '',
                        'status'        => 'active',
                    ]);
                    $manager->executeBulkWrite($documentsNamespace, $bulk);
                    // Notify: document added
                    $notifBulk = new MongoDB\Driver\BulkWrite;
                    $notifBulk->insert([
                        'userId'    => $_SESSION['user_id'] ?? '',
                        'message'   => 'Document "' . $documentTitle . '" was added.',
                        'read'      => false,
                        'createdAt' => new MongoDB\BSON\UTCDateTime(),
                    ]);
                    $manager->executeBulkWrite($notificationsNamespace, $notifBulk);
                    header('Location: documents.php?added=1');
                    exit;
                } catch (Exception $e) {
                    $addError = 'Failed to save document: ' . $e->getMessage();
                }
            }
        }
    }
    if ($addError) {
        $_SESSION['super_admin_doc_add_error'] = $addError;
        header('Location: documents.php?add_error=1');
        exit;
    }
}

if (isset($_GET['add_error']) && isset($_SESSION['super_admin_doc_add_error'])) {
    $addError = $_SESSION['super_admin_doc_add_error'];
    unset($_SESSION['super_admin_doc_add_error']);
}

$sentList = [];
$idsInList = [];
try {
    $manager = new MongoDB\Driver\Manager($config['uri']);
    $query = new MongoDB\Driver\Query([], ['sort' => ['sentAt' => -1], 'limit' => 500]);
    $cursor = $manager->executeQuery($sentNamespace, $query);
    foreach ($cursor as $row) {
        $arr = (array)$row;
        $arr['documentId'] = (string)($arr['documentId'] ?? '');
        $idsInList[$arr['documentId']] = true;
        $dt = $arr['sentAt'] ?? null;
        if ($dt instanceof MongoDB\BSON\UTCDateTime) {
            $arr['sentAtFormatted'] = $dt->toDateTime()->setTimezone(new DateTimeZone(date_default_timezone_get() ?: 'UTC'))->format('M j, Y g:i A');
        } else {
            $arr['sentAtFormatted'] = '—';
        }
        $sentList[] = $arr;
    }
    // Add documents created by this Super Admin (saved via Add Document)
    $currentUserId = $_SESSION['user_id'] ?? '';
    if ($currentUserId !== '') {
        $docQuery = new MongoDB\Driver\Query(
            ['createdBy' => $currentUserId, 'status' => ['$ne' => 'archived']],
            ['sort' => ['createdAt' => -1], 'limit' => 500]
        );
        $docCursor = $manager->executeQuery($documentsNamespace, $docQuery);
        foreach ($docCursor as $doc) {
            $d = (array)$doc;
            $docId = (string)($d['_id'] ?? '');
            if ($docId === '' || isset($idsInList[$docId])) continue;
            $idsInList[$docId] = true;
            $sentList[] = [
                'documentId'       => $docId,
                'documentCode'    => $d['documentCode'] ?? $d['document_code'] ?? '—',
                'documentTitle'   => $d['documentTitle'] ?? $d['document_title'] ?? '—',
                'fileName'        => $d['fileName'] ?? $d['file_name'] ?? 'document.docx',
                'status'          => $d['status'] ?? 'active',
                'sentAtFormatted' => '—',
            ];
        }
    }
} catch (Exception $e) {
    $sentList = [];
}

$notifications = [];
$notifCount = 0;
$currentUserId = $_SESSION['user_id'] ?? '';
if ($currentUserId !== '') {
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $notifQuery = new MongoDB\Driver\Query(
            ['userId' => $currentUserId],
            ['sort' => ['createdAt' => -1], 'limit' => 20]
        );
        $notifCursor = $manager->executeQuery($notificationsNamespace, $notifQuery);
        foreach ($notifCursor as $n) {
            $arr = (array)$n;
            $notifications[] = [
                'message'   => $arr['message'] ?? '',
                'read'      => !empty($arr['read']),
                'createdAt' => $arr['createdAt'] ?? null,
            ];
        }
        foreach ($notifications as $n) {
            if (empty($n['read'])) $notifCount++;
        }
    } catch (Exception $e) {}
}

$showSentToast = isset($_GET['sent']) && $_GET['sent'] === '1';
$showAddedToast = isset($_GET['added']) && $_GET['added'] === '1';
$showCaseClosedToast = isset($_GET['case_closed']) && $_GET['case_closed'] === '1';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMS LGU – Documents</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="profile_modal_super_admin.css">
    <link rel="stylesheet" href="../Admin Side/admin-dashboard.css">
    <link rel="stylesheet" href="../Admin Side/admin-offices.css">
    <link rel="stylesheet" href="sidebar_super_admin.css">
    <style>
        body { margin: 0; background: #f8fafc; color: #0f172a; }
        .main-content { display: flex; flex-direction: column; flex: 1; min-height: 0; background: #fff; }
        .content-header { background: #fff; padding: 1.5rem 2.2rem; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; }
        .dashboard-header h1 { font-size: 1.6rem; margin: 0 0 0.2rem 0; font-weight: 700; color: #1e293b; }
        .dashboard-header small { display: block; color: #64748b; font-size: 0.95rem; margin-top: 6px; }
        .header-controls { position: relative; }
        .icon-btn, .avatar-btn { background: #f1f5f9; border: none; color: #475569; padding: 0; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .icon-btn:hover, .avatar-btn:hover { background: #e2e8f0; color: #1e293b; }
        .icon-btn { position: relative; width: 40px; height: 40px; }
        .icon-btn svg, .avatar-btn svg { width: 22px; height: 22px; }
        .notif-badge { position: absolute; top: 8px; right: 8px; background: #ef4444; color: white; font-size: 12px; padding: 4px 8px; border-radius: 999px; line-height: 1; }
        .avatar-btn { width: 40px; height: 40px; padding: 0; border-radius: 10px; }
        .notif-dropdown { position: absolute; right: 0; top: 48px; background: white; color: #0b1720; min-width: 220px; max-width: 320px; max-height: 360px; overflow-y: auto; border-radius: 8px; box-shadow: 0 8px 20px rgba(2,6,23,0.12); border: 1px solid #e6eef8; display: none; z-index: 1200; padding: 0; }
        .notif-dropdown-header { padding: 10px 12px; font-size: 0.85rem; font-weight: 600; color: #0b1720; border-bottom: 1px solid #e6eef8; }
        .notif-item { padding: 10px 12px; font-size: 0.95rem; color: #475569; border-bottom: 1px solid #f1f5f9; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item.notif-mark-read { display: block; color: #3B82F6; text-decoration: none; font-weight: 500; }
        .notif-item.notif-mark-read:hover { background: rgba(59, 130, 246, 0.08); }
        .main-content .admin-content-body { padding-top: 24px; }
        .documents-actions-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .documents-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit; transition: background 0.15s, color 0.15s; text-decoration: none; color: inherit; }
        .documents-action-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
        .documents-action-open { background: #dbeafe; color: #1d4ed8; }
        .documents-action-open:hover { background: #bfdbfe; color: #1d4ed8; }
        .documents-action-archive { background: #fef3c7; color: #b45309; }
        .documents-action-archive:hover { background: #fde68a; color: #b45309; }
        .documents-action-send { background: #d1fae5; color: #047857; }
        .documents-action-send:hover { background: #a7f3d0; color: #047857; }
        .documents-action-view { background: #e0e7ff; color: #4338ca; }
        .documents-action-view:hover { background: #c7d2fe; color: #4338ca; }
        .send-dropdown-btn { cursor: pointer; }
        /* Send choices dropdown: rendered outside table, fixed position */
        #send-choices-dropdown { position: fixed; background: #fff; border-radius: 8px; box-shadow: 0 8px 20px rgba(0,0,0,0.12); border: 1px solid #e6eef8; min-width: 160px; padding: 6px 0; z-index: 2000; }
        #send-choices-dropdown[hidden] { display: none !important; }
        .send-dropdown-item { display: flex; align-items: center; width: 100%; padding: 10px 14px; border: none; background: none; color: #1e293b; font-size: 0.9rem; cursor: pointer; text-align: left; font-family: inherit; box-sizing: border-box; }
        .send-dropdown-item:hover { background: #f1f5f9; }
        .document-status { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; text-transform: capitalize; }
        .document-status-active { background: #d1fae5; color: #047857; }
        .document-status-archived { background: #f3f4f6; color: #6b7280; }
    </style>
</head>
<body<?php if (!empty($showSentToast)): ?> data-sent="1"<?php endif; ?><?php if (!empty($showAddedToast)): ?> data-added="1"<?php endif; ?><?php if (!empty($showCaseClosedToast)): ?> data-case-closed="1"<?php endif; ?><?php if (!empty($addError)): ?> data-add-error="1"<?php endif; ?>>
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
                                <?php if ($notifCount > 0): ?><span class="notif-badge" id="notif-count" aria-hidden="true"><?= (int)$notifCount ?></span><?php else: ?><span class="notif-badge" id="notif-count" aria-hidden="true" hidden>0</span><?php endif; ?>
                            </button>
                            <div class="notif-dropdown" id="notif-dropdown" aria-hidden="true" role="menu" aria-label="Notifications">
                                <div class="notif-dropdown-header">Notifications</div>
                                <?php if (empty($notifications)): ?>
                                <div class="notif-item">No new notifications</div>
                                <?php else: ?>
                                <?php foreach ($notifications as $notif): ?>
                                <div class="notif-item"><?= htmlspecialchars($notif['message']) ?></div>
                                <?php endforeach; ?>
                                <?php if ($notifCount > 0): ?>
                                <a href="documents.php?mark_notifications_read=1" class="notif-item notif-mark-read">Mark all as read</a>
                                <?php endif; ?>
                                <?php endif; ?>
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

            <div class="admin-content-body">
                <section class="chart-card chart-card-wide offices-card">
                    <div class="offices-tools doc-filter-row">
                        <input type="text" placeholder="Search" aria-label="Search document">
                        <input type="date" aria-label="From date">
                        <input type="date" aria-label="To date">
                        <button type="button" class="offices-btn" id="open-add-document-modal">
                            <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                            Add Document
                        </button>
                        <button type="button" class="offices-btn offices-btn-secondary" id="edit-document-btn">
                            <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            Edit
                        </button>
                    </div>

                    <div class="offices-table-frame">
                        <table class="offices-table">
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
                                <?php if (empty($sentList)): ?>
                                <tr>
                                    <td colspan="6" class="offices-empty" id="no-documents-row">No documents yet.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($sentList as $idx => $sent):
                                    $docId = $sent['documentId'];
                                    $sentStatus = isset($sent['status']) ? ucfirst(strtolower($sent['status'])) : 'Active';
                                ?>
                                <tr data-document-row>
                                    <td><?= (int)($idx + 1) ?></td>
                                    <td><?= htmlspecialchars($sent['documentCode'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($sent['documentTitle'] ?? '—') ?></td>
                                    <td><a href="documents.php?download=<?= urlencode($docId) ?>" class="doc-file-link"><?= htmlspecialchars($sent['fileName'] ?? 'document.docx') ?></a></td>
                                    <td><span class="document-status document-status-<?= strtolower(htmlspecialchars($sentStatus)) ?>"><?= htmlspecialchars($sentStatus) ?></span></td>
                                    <td>
                                        <div class="documents-actions-row">
                                            <a href="documents.php?download=<?= urlencode($docId) ?>" class="documents-action-btn documents-action-open" title="Download document"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download</a>
                                            <button type="button" class="documents-action-btn documents-action-send send-dropdown-btn" title="Send" data-document-id="<?= htmlspecialchars($docId) ?>" aria-haspopup="true" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send</button>
                                            <a href="documents.php?view=<?= urlencode($docId) ?>" target="_blank" class="documents-action-btn documents-action-view" title="View file"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</a>
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

    <?php include __DIR__ . '/_profile_modal_super_admin.php'; ?>

    <!-- Send choices: outside table/modal, appended to body so not clipped -->
    <div id="send-choices-dropdown" role="menu" aria-label="Send options" hidden>
        <button type="button" class="send-dropdown-item" data-action="endorse">Endorse</button>
        <button type="button" class="send-dropdown-item" data-action="table">Table</button>
        <button type="button" class="send-dropdown-item" data-action="close">Case Close</button>
    </div>

    <script>
    (function() {
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

        if (document.body.getAttribute('data-sent') === '1') {
            var toast = document.createElement('div');
            toast.setAttribute('role', 'status');
            toast.textContent = 'Document sent to Admin.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }

        if (document.body.getAttribute('data-added') === '1') {
            var toast = document.createElement('div');
            toast.setAttribute('role', 'status');
            toast.textContent = 'Document saved successfully.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }

        if (document.body.getAttribute('data-case-closed') === '1') {
            var toast = document.createElement('div');
            toast.setAttribute('role', 'status');
            toast.textContent = 'Document case closed.';
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:#22c55e;color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        }

        if (addModal && document.body.getAttribute('data-add-error') === '1') {
            addModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        // Send choices: dropdown is outside table (body-level), positioned next to Send button
        var sendChoicesDropdown = document.getElementById('send-choices-dropdown');
        var activeSendDocId = null;
        function closeSendChoices() {
            if (sendChoicesDropdown) sendChoicesDropdown.hidden = true;
            document.querySelectorAll('.send-dropdown-btn').forEach(function(btn) { btn.setAttribute('aria-expanded', 'false'); });
            activeSendDocId = null;
        }
        document.querySelectorAll('.send-dropdown-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (!sendChoicesDropdown) return;
                var wasOpen = this.getAttribute('aria-expanded') === 'true';
                closeSendChoices();
                if (wasOpen) return;
                var rect = this.getBoundingClientRect();
                activeSendDocId = this.getAttribute('data-document-id') || '';
                sendChoicesDropdown.style.left = rect.left + 'px';
                sendChoicesDropdown.style.top = (rect.bottom + 4) + 'px';
                sendChoicesDropdown.hidden = false;
                this.setAttribute('aria-expanded', 'true');
            });
        });
        if (sendChoicesDropdown) {
            sendChoicesDropdown.querySelectorAll('.send-dropdown-item').forEach(function(item) {
                item.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var action = this.getAttribute('data-action');
                    if (action === 'close') {
                        if (activeSendDocId) {
                            window.location = 'documents.php?case_close=' + encodeURIComponent(activeSendDocId);
                        } else {
                            closeSendChoices();
                        }
                    } else if (action === 'endorse' || action === 'table') {
                        closeSendChoices();
                    }
                });
            });
        }
        document.addEventListener('click', function() { closeSendChoices(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSendChoices();
        });
    })();
    </script>
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
