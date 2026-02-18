<?php
session_start();

$config = require __DIR__ . '/../config.php';

/**
 * Check if the current user has access to the admin side.
 * @return bool True if user is logged in and has an allowed role (admin, staff, department head)
 */
function isAdminSide() {
    $role = $_SESSION['user_role'] ?? '';
    $allowedRoles = ['admin', 'staff', 'departmenthead', 'department_head', 'dept_head'];
    return isset($_SESSION['user_id']) && in_array($role, $allowedRoles);
}

/**
 * Fetch all offices from the database.
 * @return array List of offices with office_code and office_name
 */
function getOffices($config) {
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query([]);
        $namespace = $config['database'] . '.' . $config['collection'];
        $cursor = $manager->executeQuery($namespace, $query);
        $docs = $cursor->toArray();
        $offices = [];
        foreach ($docs as $doc) {
            $d = (array) $doc;
            $offices[] = [
                'id' => (string) ($d['_id'] ?? ''),
                'office_code' => $d['office_code'] ?? $d['code'] ?? '',
                'office_name' => $d['office_name'] ?? $d['department'] ?? $d['name'] ?? '',
            ];
        }
        return $offices;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Update an existing office in the database.
 * @param array $config Database config
 * @param string $id Office MongoDB _id
 * @param string $officeCode Office code
 * @param string $officeName Office name
 * @return array ['success' => bool, 'error' => string|null]
 */
function updateOffice($config, $id, $officeCode, $officeName) {
    $officeCode = trim($officeCode);
    $officeName = trim($officeName);
    if ($id === '' || $officeCode === '' || $officeName === '') {
        return ['success' => false, 'error' => 'Office ID, code and name are required.'];
    }
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->update(
            ['_id' => new MongoDB\BSON\ObjectId($id)],
            ['$set' => ['office_code' => $officeCode, 'office_name' => $officeName]]
        );
        $namespace = $config['database'] . '.' . $config['collection'];
        $manager->executeBulkWrite($namespace, $bulk);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Add a new office to the database.
 * @param array $config Database config
 * @param string $officeCode Office code
 * @param string $officeName Office name
 * @return array ['success' => bool, 'error' => string|null]
 */
function addOffice($config, $officeCode, $officeName) {
    $officeCode = trim($officeCode);
    $officeName = trim($officeName);
    if ($officeCode === '' || $officeName === '') {
        return ['success' => false, 'error' => 'Office code and name are required.'];
    }
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->insert([
            'office_code' => $officeCode,
            'office_name' => $officeName,
        ]);
        $namespace = $config['database'] . '.' . $config['collection'];
        $manager->executeBulkWrite($namespace, $bulk);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

if (!isAdminSide()) {
    header('Location: ../index.php');
    exit;
}

$addError = '';
$addSuccess = false;
$editError = '';
$editSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_office'])) {
    $result = addOffice($config, $_POST['office_code'] ?? '', $_POST['office_name'] ?? '');
    if ($result['success']) {
        header('Location: admin_offices.php?added=1');
        exit;
    }
    $addError = $result['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_office'])) {
    $result = updateOffice($config, $_POST['office_id'] ?? '', $_POST['office_code'] ?? '', $_POST['office_name'] ?? '');
    if ($result['success']) {
        header('Location: admin_offices.php?edited=1');
        exit;
    }
    $editError = $result['error'];
}

$addSuccess = isset($_GET['added']);
$editSuccess = isset($_GET['edited']);
$offices = getOffices($config);

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $offices = array_values(array_filter($offices, function ($o) use ($search) {
        $q = mb_strtolower($search);
        $code = mb_strtolower($o['office_code'] ?? '');
        $name = mb_strtolower($o['office_name'] ?? '');
        return (strpos($code, $q) !== false || strpos($name, $q) !== false);
    }));
}

$limit = 10;
$totalOffices = count($offices);
$totalPages = max(1, (int) ceil($totalOffices / $limit));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $limit;
$officesPage = array_slice($offices, $offset, $limit);

$filterQuery = $search !== '' ? 'search=' . rawurlencode($search) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Offices</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin-offices.css?v=3">
    <style>
    /* Force dark text in Select Office modal - override body's white inheritance */
    body.admin-dashboard #select-office-modal .doc-modal-body-list,
    body.admin-dashboard #select-office-modal .doc-modal-body-list * {
        color: #1e293b !important;
    }
    body.admin-dashboard #select-office-modal .offices-list-name {
        color: #475569 !important;
    }
    body.admin-dashboard #select-office-modal .doc-modal-body-list {
        background: #ffffff !important;
    }
    body.admin-dashboard #select-office-modal .offices-list-item {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
    }
    body.admin-dashboard #select-office-modal .offices-modal-pagination .offices-page-btn {
        color: #fff !important;
    }
    body.admin-dashboard #select-office-modal .offices-modal-pagination .offices-page-btn:disabled {
        color: #94a3b8 !important;
    }
    </style>
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
                <a href="admin_offices.php" class="sidebar-link active" data-section="offices">
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
                            <h1 class="admin-content-title">Offices</h1>
                            <p class="admin-content-subtitle">Manage municipal offices and departments for document routing and classification</p>
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
                        <div class="offices-tools">
                            <form method="get" action="admin_offices.php" class="offices-tools-search" id="offices-filter-form">
                                <input type="text" name="search" placeholder="Search by code or name" aria-label="Search office" value="<?= htmlspecialchars($search) ?>">
                                <button type="submit" class="offices-btn offices-btn-filter" id="offices-filter-btn" title="Apply filter">
                                    <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    Filter
                                </button>
                            </form>
                            <div class="offices-tools-actions">
                                <button type="button" class="offices-btn" id="open-add-office-modal" title="Add a new office">
                                    <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                                    Add Office
                                </button>
                                <button type="button" class="offices-btn offices-btn-secondary" id="open-edit-office-modal" title="Edit an existing office">
                                    <svg class="offices-btn-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Edit
                                </button>
                            </div>
                        </div>

                        <div class="offices-table-frame">
                            <table class="offices-table">
                            <thead>
                                <tr>
                                    <th>NO.</th>
                                    <th>OFFICE CODE</th>
                                    <th>OFFICE NAME</th>
                                </tr>
                            </thead>
                            <tbody id="offices-table-body">
                                <?php if (empty($officesPage)): ?>
                                <tr id="no-offices-row">
                                    <td colspan="3" class="offices-empty"><?= $search !== '' ? 'No offices match your search.' : 'No offices yet.' ?></td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($officesPage as $i => $office): ?>
                                <tr data-office-id="<?= htmlspecialchars($office['id']) ?>">
                                    <td><?= $offset + $i + 1 ?></td>
                                    <td><?= htmlspecialchars($office['office_code']) ?></td>
                                    <td><?= htmlspecialchars($office['office_name']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php
                                $placeholderCount = $limit - count($officesPage);
                                for ($p = 0; $p < $placeholderCount; $p++):
                                ?>
                                <tr class="offices-placeholder-row"><td>&nbsp;</td><td></td><td></td></tr>
                                <?php endfor; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php if ($totalPages > 1): ?>
                        <div class="offices-pagination">
                            <?php $pagePrefix = $filterQuery ? $filterQuery . '&' : ''; ?>
                            <?php if ($page > 1): ?>
                            <a href="?<?= $pagePrefix ?>page=<?= $page - 1 ?>" class="offices-page-btn">Previous</a>
                            <?php else: ?>
                            <span class="offices-page-btn disabled" aria-disabled="true">Previous</span>
                            <?php endif; ?>
                            <span class="offices-page-info">Page <?= $page ?> of <?= $totalPages ?><?= $search !== '' ? ' (filtered)' : '' ?></span>
                            <?php if ($page < $totalPages): ?>
                            <a href="?<?= $pagePrefix ?>page=<?= $page + 1 ?>" class="offices-page-btn">Next</a>
                            <?php else: ?>
                            <span class="offices-page-btn disabled" aria-disabled="true">Next</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </main>
    </div>

    <?php if ($addSuccess): ?>
    <div class="offices-toast" id="offices-success-toast">Office added successfully.</div>
    <?php endif; ?>
    <?php if ($editSuccess): ?>
    <div class="offices-toast" id="offices-edit-toast">Office updated successfully.</div>
    <?php endif; ?>

    <div class="doc-modal" id="select-office-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-select-office aria-label="Close"></button>
        <div class="doc-modal-dialog doc-modal-dialog-list" role="dialog" aria-modal="true" aria-labelledby="select-office-title">
            <div class="doc-modal-header">
                <h2 id="select-office-title">Select Office to Edit</h2>
                <button type="button" class="doc-modal-close" data-close-select-office aria-label="Close">&times;</button>
            </div>
            <div class="doc-modal-body-list" style="color:#1e293b;">
                <?php if (empty($offices)): ?>
                <p class="offices-list-empty" style="color:#475569;">No offices to edit.</p>
                <?php else: ?>
                <ul class="offices-list" id="offices-list" style="color:#1e293b;">
                    <?php 
                    $modalLimit = 10;
                    $modalTotalPages = max(1, (int) ceil(count($offices) / $modalLimit));
                    foreach ($offices as $idx => $office): 
                        $modalPage = (int) floor($idx / $modalLimit) + 1;
                    ?>
                    <li class="offices-list-item" data-office-id="<?= htmlspecialchars($office['id']) ?>" data-office-code="<?= htmlspecialchars($office['office_code']) ?>" data-office-name="<?= htmlspecialchars($office['office_name']) ?>" data-modal-page="<?= $modalPage ?>" style="color:#1e293b;<?= $modalPage > 1 ? ' display:none;' : '' ?>">
                        <span class="offices-list-code" style="color:#1e293b;"><?= htmlspecialchars($office['office_code']) ?></span>
                        <span class="offices-list-name" style="color:#475569;"><?= htmlspecialchars($office['office_name']) ?></span>
                        <svg class="offices-list-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#64748b" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($modalTotalPages > 1): ?>
                <div class="offices-modal-pagination">
                    <button type="button" class="offices-page-btn" id="modal-prev-btn" aria-label="Previous page">Previous</button>
                    <span class="offices-page-info" id="modal-page-info">Page 1 of <?= $modalTotalPages ?></span>
                    <button type="button" class="offices-page-btn" id="modal-next-btn" aria-label="Next page">Next</button>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="doc-modal" id="edit-office-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-edit-office aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="edit-office-title">
            <div class="doc-modal-header">
                <h2 id="edit-office-title">Edit Office</h2>
                <button type="button" class="doc-modal-close" data-close-edit-office aria-label="Close">&times;</button>
            </div>
            <form id="edit-office-form" class="doc-modal-form" method="post" action="admin_offices.php">
                <input type="hidden" name="edit_office" value="1">
                <input type="hidden" name="office_id" id="edit-office-id" value="<?= isset($_POST['office_id']) ? htmlspecialchars($_POST['office_id']) : '' ?>">
                <div class="doc-form-field">
                    <label for="edit-office-code">Office Code</label>
                    <input type="text" id="edit-office-code" name="office_code" placeholder="Enter office or department code" required value="<?= isset($_POST['edit_office'], $_POST['office_code']) ? htmlspecialchars($_POST['office_code']) : '' ?>">
                </div>
                <div class="doc-form-field">
                    <label for="edit-office-name">Office Name</label>
                    <input type="text" id="edit-office-name" name="office_name" placeholder="Enter office or department name" required value="<?= isset($_POST['edit_office'], $_POST['office_name']) ? htmlspecialchars($_POST['office_name']) : '' ?>">
                </div>
                <p class="doc-form-error" id="edit-office-form-error" <?= $editError ? '' : 'hidden' ?>><?= $editError ? htmlspecialchars($editError) : '' ?></p>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-edit-office>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Update Office</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="add-office-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-add-office aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="add-office-title">
            <div class="doc-modal-header">
                <h2 id="add-office-title">Add Office</h2>
                <button type="button" class="doc-modal-close" data-close-add-office aria-label="Close">&times;</button>
            </div>
            <form id="add-office-form" class="doc-modal-form" method="post" action="admin_offices.php">
                <input type="hidden" name="add_office" value="1">
                <div class="doc-form-field">
                    <label for="office-code">Office Code</label>
                    <input type="text" id="office-code" name="office_code" placeholder="Enter office or department code" required value="<?= isset($_POST['office_code']) ? htmlspecialchars($_POST['office_code']) : '' ?>">
                </div>
                <div class="doc-form-field">
                    <label for="office-name">Office Name</label>
                    <input type="text" id="office-name" name="office_name" placeholder="Enter office or department name" required value="<?= isset($_POST['office_name']) ? htmlspecialchars($_POST['office_name']) : '' ?>">
                </div>
                <p class="doc-form-error" id="office-form-error" <?= $addError ? '' : 'hidden' ?>><?= $addError ? htmlspecialchars($addError) : '' ?></p>
                <div class="doc-modal-actions">
                    <button type="button" class="doc-btn doc-btn-cancel" data-close-add-office>Cancel</button>
                    <button type="submit" class="doc-btn doc-btn-save">Save Office</button>
                </div>
            </form>
        </div>
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

        var openAddOfficeBtn = document.getElementById('open-add-office-modal');
        var addOfficeModal = document.getElementById('add-office-modal');
        var addOfficeForm = document.getElementById('add-office-form');
        var officeFormError = document.getElementById('office-form-error');
        var successToast = document.getElementById('offices-success-toast');

        function openAddOfficeModal() {
            if (!addOfficeModal) return;
            addOfficeModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeAddOfficeModal() {
            if (!addOfficeModal) return;
            addOfficeModal.hidden = true;
            document.body.classList.remove('modal-open');
            if (addOfficeForm) addOfficeForm.reset();
        }

        if (openAddOfficeBtn) openAddOfficeBtn.addEventListener('click', openAddOfficeModal);
        document.querySelectorAll('[data-close-add-office]').forEach(function(el) {
            el.addEventListener('click', closeAddOfficeModal);
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && addOfficeModal && !addOfficeModal.hidden) closeAddOfficeModal();
        });

        if (addOfficeModal && officeFormError && officeFormError.textContent.trim()) {
            openAddOfficeModal();
        }

        if (successToast) {
            setTimeout(function() { successToast.classList.add('offices-toast-hide'); }, 3000);
        }

        var openEditBtn = document.getElementById('open-edit-office-modal');
        var selectOfficeModal = document.getElementById('select-office-modal');
        var editOfficeModal = document.getElementById('edit-office-modal');
        var editOfficeForm = document.getElementById('edit-office-form');
        var editOfficeId = document.getElementById('edit-office-id');
        var editOfficeCode = document.getElementById('edit-office-code');
        var editOfficeName = document.getElementById('edit-office-name');
        var editOfficeFormError = document.getElementById('edit-office-form-error');
        var editToast = document.getElementById('offices-edit-toast');

        var modalCurrentPage = 1;
        var modalTotalPages = 1;
        var modalPrevBtn = document.getElementById('modal-prev-btn');
        var modalNextBtn = document.getElementById('modal-next-btn');
        var modalPageInfo = document.getElementById('modal-page-info');

        function updateModalPage() {
            var list = document.getElementById('offices-list');
            if (!list) return;
            var items = list.querySelectorAll('.offices-list-item:not(.offices-list-placeholder)');
            items.forEach(function(item) {
                item.style.display = parseInt(item.getAttribute('data-modal-page'), 10) === modalCurrentPage ? '' : 'none';
            });
            list.querySelectorAll('.offices-list-placeholder').forEach(function(p) { p.remove(); });
            var visibleCount = 0;
            items.forEach(function(item) {
                if (parseInt(item.getAttribute('data-modal-page'), 10) === modalCurrentPage) visibleCount++;
            });
            var placeholderCount = 10 - visibleCount;
            for (var i = 0; i < placeholderCount; i++) {
                var placeholder = document.createElement('li');
                placeholder.className = 'offices-list-item offices-list-placeholder';
                placeholder.style.pointerEvents = 'none';
                placeholder.innerHTML = '<span class="offices-list-code">&nbsp;</span><span class="offices-list-name"></span>';
                list.appendChild(placeholder);
            }
            if (modalPrevBtn) modalPrevBtn.disabled = modalCurrentPage <= 1;
            if (modalNextBtn) modalNextBtn.disabled = modalCurrentPage >= modalTotalPages;
            if (modalPageInfo) modalPageInfo.textContent = 'Page ' + modalCurrentPage + ' of ' + modalTotalPages;
        }

        function openSelectOfficeModal() {
            if (!selectOfficeModal) return;
            modalCurrentPage = 1;
            modalTotalPages = Math.max(1, Math.ceil(document.querySelectorAll('#offices-list .offices-list-item').length / 10));
            updateModalPage();
            selectOfficeModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeSelectOfficeModal() {
            if (!selectOfficeModal) return;
            selectOfficeModal.hidden = true;
            document.body.classList.remove('modal-open');
        }

        function openEditOfficeModal(id, code, name, clearError) {
            if (!editOfficeModal || !editOfficeId || !editOfficeCode || !editOfficeName) return;
            editOfficeId.value = id || '';
            editOfficeCode.value = code || '';
            editOfficeName.value = name || '';
            if (editOfficeFormError && (clearError !== false)) { editOfficeFormError.hidden = true; editOfficeFormError.textContent = ''; }
            editOfficeModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeEditOfficeModal() {
            if (!editOfficeModal) return;
            editOfficeModal.hidden = true;
            document.body.classList.remove('modal-open');
            if (editOfficeForm) { editOfficeForm.reset(); if (editOfficeId) editOfficeId.value = ''; }
        }

        if (openEditBtn) {
            openEditBtn.addEventListener('click', function() {
                openSelectOfficeModal();
            });
        }

        document.querySelectorAll('[data-close-select-office]').forEach(function(el) {
            el.addEventListener('click', function() {
                closeSelectOfficeModal();
                document.body.classList.remove('modal-open');
            });
        });

        document.querySelectorAll('[data-close-edit-office]').forEach(function(el) {
            el.addEventListener('click', function() {
                closeEditOfficeModal();
            });
        });

        document.getElementById('offices-list') && document.getElementById('offices-list').addEventListener('click', function(e) {
            var item = e.target.closest('.offices-list-item');
            if (!item || item.classList.contains('offices-list-placeholder')) return;
            var id = item.getAttribute('data-office-id');
            var code = item.getAttribute('data-office-code');
            var name = item.getAttribute('data-office-name');
            closeSelectOfficeModal();
            openEditOfficeModal(id, code, name);
        });

        if (modalPrevBtn) {
            modalPrevBtn.addEventListener('click', function() {
                if (modalCurrentPage > 1) { modalCurrentPage--; updateModalPage(); }
            });
        }
        if (modalNextBtn) {
            modalNextBtn.addEventListener('click', function() {
                if (modalCurrentPage < modalTotalPages) { modalCurrentPage++; updateModalPage(); }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (editOfficeModal && !editOfficeModal.hidden) {
                    closeEditOfficeModal();
                } else if (selectOfficeModal && !selectOfficeModal.hidden) {
                    closeSelectOfficeModal();
                }
            }
        });

        if (editOfficeModal && editOfficeFormError && editOfficeFormError.textContent.trim()) {
            openEditOfficeModal(editOfficeId ? editOfficeId.value : '', editOfficeCode ? editOfficeCode.value : '', editOfficeName ? editOfficeName.value : '', false);
        }

        if (editToast) {
            setTimeout(function() { editToast.classList.add('offices-toast-hide'); }, 3000);
        }
    })();
    </script>
</body>
</html>
