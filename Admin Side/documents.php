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
    <title>Admin - Documents</title>
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
                <a href="documents.php" class="sidebar-link active" data-section="documents">
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
                            <h1 class="admin-content-title">Documents</h1>
                            <p class="admin-content-subtitle">Create, track, and manage municipal documents across all departments</p>
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
                                    </tr>
                                </thead>
                                <tbody id="documents-table-body">
                                    <tr>
                                        <td colspan="4" class="offices-empty" id="no-documents-row">No documents yet.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </main>
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

                documentsTableBody.appendChild(row);
                closeAddDocumentModal();
            });
        }
    })();
    </script>
</body>
</html>
