<?php
session_start();
ob_start();

$role = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'staff', 'departmenthead', 'department_head', 'dept_head'])) {
    header('Location: ../index.php');
    exit;
}

$config = require __DIR__ . '/../config.php';
$documentsNamespace = $config['database'] . '.documents';
if (!function_exists('getUserSignature')) require_once __DIR__ . '/../Super Admin Side/_account_helpers.php';

function dmsEnsureDocxDrawingNamespaces($documentXml) {
    $extra = [];
    if (strpos($documentXml, 'xmlns:r=') === false) $extra[] = ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    if (strpos($documentXml, 'xmlns:wp=') === false) $extra[] = ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"';
    if (strpos($documentXml, 'xmlns:a=') === false) $extra[] = ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';
    if (strpos($documentXml, 'xmlns:pic=') === false) $extra[] = ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
    if (empty($extra)) return $documentXml;
    return preg_replace('/<w:document\b([^>]*)>/', '<w:document$1' . implode('', $extra) . '>', $documentXml, 1);
}

function dmsFormatActorName($value) {
    $text = trim((string)$value);
    if ($text === '') return '—';
    $lower = strtolower($text);
    if ($lower === 'super admin' || strpos($lower, 'superadmin@') === 0) return 'Super Admin';
    if (strpos($lower, '@') !== false && filter_var($text, FILTER_VALIDATE_EMAIL)) {
        $local = preg_replace('/@.*$/', '', $text);
        $local = preg_replace('/[._-]+/', ' ', (string)$local);
        $local = trim((string)$local);
        if ($local === '') return '—';
        if (strtolower($local) === 'superadmin') return 'Super Admin';
        if (strtolower($local) === 'admin') return 'Admin';
        return mb_convert_case($local, MB_CASE_TITLE, 'UTF-8');
    }
    return $text;
}

function dmsInsertDrawingBlockByPosY($documentXml, $blockXml, $posY, &$paragraphOffsetYEmu = 0) {
    if (!is_string($documentXml) || $documentXml === '' || !is_string($blockXml) || $blockXml === '') return false;
    if (!preg_match('/<w:body\b([^>]*)>([\s\S]*?)<\/w:body>/', $documentXml, $bodyMatch, PREG_OFFSET_CAPTURE)) {
        return false;
    }

    $bodyAttrs = $bodyMatch[1][0];
    $bodyInner = $bodyMatch[2][0];
    $bodyStart = $bodyMatch[0][1];
    $bodyLen = strlen($bodyMatch[0][0]);

    $contentInner = $bodyInner;
    $sectPrTail = '';
    if (preg_match('/^(.*?)(<w:sectPr\b[\s\S]*<\/w:sectPr>\s*)$/', $bodyInner, $sectMatch)) {
        $contentInner = $sectMatch[1];
        $sectPrTail = $sectMatch[2];
    }

    $paragraphOffsetYEmu = 0;
    $updatedContent = $contentInner;
    if (preg_match_all('/<(w:p|w:tbl)\b[^>]*>/', $contentInner, $blockMatches, PREG_OFFSET_CAPTURE) && !empty($blockMatches[0])) {
        // Keep the anchor paragraph near the top of the page.
        // Vertical placement in-file is controlled by wp:positionV (page offset),
        // which maps better to the drag position than paragraph-based anchoring.
        $insertAt = $blockMatches[0][0][1];
        $updatedContent = substr($contentInner, 0, $insertAt) . $blockXml . substr($contentInner, $insertAt);
    } else {
        $updatedContent = $blockXml . $contentInner;
    }

    $newBody = '<w:body' . $bodyAttrs . '>' . $updatedContent . $sectPrTail . '</w:body>';
    return substr($documentXml, 0, $bodyStart) . $newBody . substr($documentXml, $bodyStart + $bodyLen);
}

function dmsApplySignatureToDocx($sourceFileContent, $signatureDataUri, $signedBy, $signedAtText, $posX = 0.72, $posY = 0.06) {
    if ($sourceFileContent === '' || $signatureDataUri === '') return false;
    if (!class_exists('ZipArchive')) return false;

    $sourceText = (string)$sourceFileContent;
    $sourceBinary = false;
    $sourceClean = preg_replace('/\s+/', '', $sourceText);
    if ($sourceClean !== null && $sourceClean !== '') {
        $sourceBinary = base64_decode($sourceClean, true);
        if ($sourceBinary === false) {
            $sourceBinary = base64_decode($sourceClean, false);
        }
    }
    if ($sourceBinary === false || $sourceBinary === '') {
        $sourceBinary = $sourceText;
    }
    if (strpos($sourceBinary, "PK") !== 0) {
        return false;
    }

    $sigText = trim((string)$signatureDataUri);
    $imgExt = 'png';
    $imgBytes = false;
    if (preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,([a-zA-Z0-9+\/=\s]+)$/', $sigText, $m)) {
        $rawExt = strtolower($m[1]);
        $imgExt = ($rawExt === 'jpg') ? 'jpeg' : $rawExt;
        if (!in_array($imgExt, ['png', 'jpeg', 'gif', 'webp', 'bmp'], true)) $imgExt = 'png';
        $imgBytes = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    } else {
        // Backward-compat fallback if stored value is raw base64.
        $imgBytes = base64_decode(preg_replace('/\s+/', '', $sigText), true);
    }
    if ($imgBytes === false || $imgBytes === '') return false;

    $tmpDocx = tempnam(sys_get_temp_dir(), 'dms_docx_');
    if ($tmpDocx === false) return false;
    if (file_put_contents($tmpDocx, $sourceBinary) === false) {
        @unlink($tmpDocx);
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpDocx) !== true) {
        @unlink($tmpDocx);
        return false;
    }

    $documentXml = $zip->getFromName('word/document.xml');
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    if ($documentXml === false) {
        $zip->close();
        @unlink($tmpDocx);
        return false;
    }
    if ($relsXml === false || trim($relsXml) === '') {
        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
    }

    $documentXml = dmsEnsureDocxDrawingNamespaces($documentXml);

    preg_match_all('/Id="rId(\d+)"/', $relsXml, $ridMatches);
    $nextRid = 1;
    if (!empty($ridMatches[1])) {
        $nextRid = max(array_map('intval', $ridMatches[1])) + 1;
    }
    $rid = 'rId' . $nextRid;
    $imgName = 'signature_' . time() . '_' . mt_rand(1000, 9999) . '.' . $imgExt;
    if ($zip->addFromString('word/media/' . $imgName, $imgBytes) === false) {
        $zip->close();
        @unlink($tmpDocx);
        return false;
    }

    $relTag = '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $imgName . '"/>';
    $relsXmlUpdated = preg_replace('/<\/Relationships>/', $relTag . '</Relationships>', $relsXml, 1);
    if ($relsXmlUpdated === null || $relsXmlUpdated === $relsXml) {
        $relsXml = rtrim($relsXml) . $relTag;
        if (strpos($relsXml, '</Relationships>') === false) {
            $relsXml .= '</Relationships>';
        }
    } else {
        $relsXml = $relsXmlUpdated;
    }

    $cx = 2286000; // ~240px
    $cy = 857250;  // ~90px
    $pageWidthTwips = 11906;   // A4 defaults
    $pageHeightTwips = 16838;  // A4 defaults
    $marginLeftTwips = 1800;
    $marginRightTwips = 1800;
    $marginTopTwips = 1440;
    $marginBottomTwips = 1440;
    if (preg_match('/<w:pgSz\b[^>]*\bw:w="(\d+)"/', $documentXml, $mPgW)) $pageWidthTwips = (int)$mPgW[1];
    if (preg_match('/<w:pgSz\b[^>]*\bw:h="(\d+)"/', $documentXml, $mPgH)) $pageHeightTwips = (int)$mPgH[1];
    if (preg_match('/<w:pgMar\b[^>]*\bw:left="(\d+)"/', $documentXml, $mMarL)) $marginLeftTwips = (int)$mMarL[1];
    if (preg_match('/<w:pgMar\b[^>]*\bw:right="(\d+)"/', $documentXml, $mMarR)) $marginRightTwips = (int)$mMarR[1];
    if (preg_match('/<w:pgMar\b[^>]*\bw:top="(\d+)"/', $documentXml, $mMarT)) $marginTopTwips = (int)$mMarT[1];
    if (preg_match('/<w:pgMar\b[^>]*\bw:bottom="(\d+)"/', $documentXml, $mMarB)) $marginBottomTwips = (int)$mMarB[1];
    $pageWidth = max(1, $pageWidthTwips * 635);
    $pageHeight = max(1, $pageHeightTwips * 635);
    $contentWidth = max(1, ($pageWidthTwips - $marginLeftTwips - $marginRightTwips) * 635);
    $contentHeight = max(1, ($pageHeightTwips - $marginTopTwips - $marginBottomTwips) * 635);
    $safePosX = max(0.0, min(1.0, (float)$posX));
    $safePosY = max(0.0, min(1.0, (float)$posY));
    $maxX = max(0, $contentWidth - $cx);
    $maxY = max(0, $contentHeight - $cy);
    $offsetX = (int)round($safePosX * $maxX);
    $offsetY = (int)round($safePosY * $maxY);
    $docPrId = (string)(10000 + mt_rand(1, 89999));
    $docPrName = htmlspecialchars(trim((string)$signedBy) !== '' ? ('Signature - ' . $signedBy) : 'Signature', ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $signatureBlock =
        '<w:p><w:r><w:drawing><wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="251658240" behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1">' .
        '<wp:simplePos x="0" y="0"/>' .
        '<wp:positionH relativeFrom="margin"><wp:posOffset>' . $offsetX . '</wp:posOffset></wp:positionH>' .
        '<wp:positionV relativeFrom="margin"><wp:posOffset>' . $offsetY . '</wp:posOffset></wp:positionV>' .
        '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/>' .
        '<wp:wrapNone/><wp:docPr id="' . $docPrId . '" name="' . $docPrName . '"/><wp:cNvGraphicFramePr/>' .
        '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">' .
        '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="' . htmlspecialchars($imgName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/><pic:cNvPicPr/></pic:nvPicPr>' .
        '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>' .
        '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>' .
        '</pic:pic></a:graphicData></a:graphic></wp:anchor></w:drawing></w:r></w:p>';

    $inlineSignatureBlock =
        '<w:p><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">' .
        '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/>' .
        '<wp:docPr id="' . $docPrId . '" name="' . $docPrName . '"/><wp:cNvGraphicFramePr/>' .
        '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">' .
        '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="' . htmlspecialchars($imgName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/><pic:cNvPicPr/></pic:nvPicPr>' .
        '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>' .
        '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>' .
        '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

    $paragraphOffsetYEmu = 0;
    $documentXmlUpdated = dmsInsertDrawingBlockByPosY($documentXml, $signatureBlock, $safePosY, $paragraphOffsetYEmu);
    if ($documentXmlUpdated === false && preg_match('/<w:body\s*\/>/', $documentXml)) {
        $expanded = preg_replace('/<w:body\s*\/>/', '<w:body></w:body>', $documentXml, 1);
        if (is_string($expanded) && $expanded !== '') {
            $documentXmlUpdated = dmsInsertDrawingBlockByPosY($expanded, $signatureBlock, $safePosY, $paragraphOffsetYEmu);
        }
    }

    if (!is_string($documentXmlUpdated) || $documentXmlUpdated === '' || $documentXmlUpdated === $documentXml) {
        // Fallback: use inline drawing insertion if anchored insertion did not match this DOCX shape.
        $documentXmlUpdated = dmsInsertDrawingBlockByPosY($documentXml, $inlineSignatureBlock, $safePosY, $paragraphOffsetYEmu);
        if ($documentXmlUpdated === false && preg_match('/<w:body\s*\/>/', $documentXml)) {
            $expanded = preg_replace('/<w:body\s*\/>/', '<w:body></w:body>', $documentXml, 1);
            if (is_string($expanded) && $expanded !== '') {
                $documentXmlUpdated = dmsInsertDrawingBlockByPosY($expanded, $inlineSignatureBlock, $safePosY, $paragraphOffsetYEmu);
            }
        }
    }

    if (!is_string($documentXmlUpdated) || $documentXmlUpdated === '' || $documentXmlUpdated === $documentXml) {
        $zip->close();
        @unlink($tmpDocx);
        return false;
    }

    if ($zip->addFromString('word/document.xml', $documentXmlUpdated) === false) {
        $zip->close();
        @unlink($tmpDocx);
        return false;
    }
    if ($zip->addFromString('word/_rels/document.xml.rels', $relsXml) === false) {
        $zip->close();
        @unlink($tmpDocx);
        return false;
    }
    $zip->close();

    $updatedBinary = file_get_contents($tmpDocx);
    @unlink($tmpDocx);
    if ($updatedBinary === false) return false;
    return base64_encode($updatedBinary);
}
// View document (inline – open in browser/viewer); must run before any includes that could output
if (!empty($_GET['view']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['view'])) {
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query(['_id' => new MongoDB\BSON\ObjectId($_GET['view'])]);
        $cursor = $manager->executeQuery($documentsNamespace, $query);
        $docs = $cursor->toArray();
        if (count($docs) > 0) {
            $doc = (array)$docs[0];
            $fileName = $doc['fileName'] ?? $doc['file_name'] ?? 'document.docx';
            $fileContent = $doc['fileContent'] ?? $doc['file_content'] ?? '';
            if ($fileContent !== '') {
                if (ob_get_level()) ob_end_clean();
                header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                header('Content-Disposition: inline; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName) . '"');
                $decoded = base64_decode($fileContent, true);
                echo ($decoded !== false) ? $decoded : $fileContent;
                exit;
            }
        }
    } catch (Exception $e) {}
    if (ob_get_level()) ob_end_clean();
    header('HTTP/1.1 404 Not Found');
    exit;
}

// Download document (attachment); must run before any includes that could output
if (!empty($_GET['download']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['download'])) {
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query(['_id' => new MongoDB\BSON\ObjectId($_GET['download'])]);
        $cursor = $manager->executeQuery($documentsNamespace, $query);
        $docs = $cursor->toArray();
        if (count($docs) > 0) {
            $doc = (array)$docs[0];
            $fileName = $doc['fileName'] ?? $doc['file_name'] ?? 'document.docx';
            $fileContent = $doc['fileContent'] ?? $doc['file_content'] ?? '';
            if ($fileContent !== '') {
                if (ob_get_level()) ob_end_clean();
                header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName) . '"');
                $decoded = base64_decode($fileContent, true);
                echo ($decoded !== false) ? $decoded : $fileContent;
                exit;
            }
        }
    } catch (Exception $e) {}
    if (ob_get_level()) ob_end_clean();
    header('HTTP/1.1 404 Not Found');
    exit;
}

// Return signature metadata for a document (AJAX)
if (!empty($_GET['signature_meta']) && preg_match('/^[a-f0-9]{24}$/i', $_GET['signature_meta'])) {
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query(
            ['_id' => new MongoDB\BSON\ObjectId($_GET['signature_meta'])],
            ['projection' => ['signedSignature' => 1, 'signedByUserName' => 1, 'signedAt' => 1, 'signedPosX' => 1, 'signedPosY' => 1]]
        );
        $cursor = $manager->executeQuery($documentsNamespace, $query);
        $docs = $cursor->toArray();
        $payload = ['success' => false];
        if (count($docs) > 0) {
            $d = (array)$docs[0];
            $signedAtText = '';
            if (!empty($d['signedAt']) && $d['signedAt'] instanceof MongoDB\BSON\UTCDateTime) {
                $signedAtText = $d['signedAt']->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y h:i A');
            }
            $payload = [
                'success' => true,
                'signedSignature' => (string)($d['signedSignature'] ?? ''),
                'signedByUserName' => (string)($d['signedByUserName'] ?? ''),
                'signedAtText' => $signedAtText,
                'signedPosX' => isset($d['signedPosX']) ? (float)$d['signedPosX'] : 0.72,
                'signedPosY' => isset($d['signedPosY']) ? (float)$d['signedPosY'] : 0.06,
            ];
        }
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    } catch (Exception $e) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to load metadata.']);
        exit;
    }
}

$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'Admin';
$userDepartment = $_SESSION['user_department'] ?? 'Not Assigned';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
$documentsScope = (string)($_GET['scope'] ?? '');
$showArchived = $documentsScope === 'archived';
$showSignedOnly = $documentsScope === 'signed';
$isArchiveView = ($showArchived || $showSignedOnly);
$sidebar_active = $isArchiveView ? 'archived' : 'documents';
$pageHeading = $isArchiveView ? 'Archived Documents' : 'Documents';
$pageSubtitle = $isArchiveView
    ? 'Review signed and archived document records.'
    : 'Create, track, and manage municipal documents across all departments';
$cardHeading = $isArchiveView ? 'Archived Documents' : 'Documents';
$searchPlaceholder = $isArchiveView ? 'Search archived document by code or title' : 'Search by code or title';
$emptyRowText = $isArchiveView ? 'No archived documents yet.' : 'No documents yet.';
$documentsList = [];
$sentToByDocId = [];
$receivedByDocId = [];
$sentByAdminByDocId = [];
$addMessage = null;
$addError = null;

if (!function_exists('getUserPhoto')) require_once __DIR__ . '/../Super Admin Side/_account_helpers.php';
if (function_exists('getUserPhoto') && !empty($_SESSION['user_id'])) { $fp = getUserPhoto($_SESSION['user_id']); if ($fp !== '') $_SESSION['user_photo'] = $fp; }
$currentUserSignature = (function_exists('getUserSignature') && !empty($_SESSION['user_id'])) ? (getUserSignature($_SESSION['user_id']) ?: ($_SESSION['user_signature'] ?? '')) : ($_SESSION['user_signature'] ?? '');

// Sign document in-system (AJAX) using current user's saved signature
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sign_document') {
    $docId = trim($_POST['document_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/i', $docId)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid document id.']);
        exit;
    }
    $sig = trim((string)$currentUserSignature);
    if ($sig === '') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No profile signature found. Please set your signature first in Settings.']);
        exit;
    }
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $docQuery = new MongoDB\Driver\Query(
            ['_id' => new MongoDB\BSON\ObjectId($docId)],
            ['projection' => ['fileContent' => 1, 'originalFileContent' => 1]]
        );
        $docCursor = $manager->executeQuery($documentsNamespace, $docQuery);
        $docRows = $docCursor->toArray();
        if (count($docRows) === 0) {
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Document not found.']);
            exit;
        }
        $docData = (array)$docRows[0];
        $now = new MongoDB\BSON\UTCDateTime();
        $setData = [
            'signedSignature' => $sig,
            'signedByUserId' => (string)($_SESSION['user_id'] ?? ''),
            'signedByUserName' => (string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User'),
            'signedAt' => $now,
            'signedPosX' => 0.72,
            'signedPosY' => 0.06,
            'status' => 'signed',
        ];
        $currentOriginal = (string)($docData['originalFileContent'] ?? '');
        $currentFile = (string)($docData['fileContent'] ?? '');
        if ($currentOriginal === '' && $currentFile !== '') {
            $setData['originalFileContent'] = $currentFile;
        }
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->update(
            ['_id' => new MongoDB\BSON\ObjectId($docId)],
            ['$set' => $setData],
            ['multi' => false]
        );
        $manager->executeBulkWrite($documentsNamespace, $bulk);
        $signedAtText = $now->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y h:i A');
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Signature inserted and saved.',
            'signedSignature' => $sig,
            'signedByUserName' => (string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User'),
            'signedAtText' => $signedAtText,
            'signedPosX' => 0.72,
            'signedPosY' => 0.06,
        ]);
        exit;
    } catch (Exception $e) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to save signature.']);
        exit;
    }
}

// Delete signature from document (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_signature') {
    $docId = trim($_POST['document_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/i', $docId)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid document id.']);
        exit;
    }
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query(
            ['_id' => new MongoDB\BSON\ObjectId($docId)],
            ['projection' => ['fileContent' => 1, 'originalFileContent' => 1]]
        );
        $cursor = $manager->executeQuery($documentsNamespace, $query);
        $rows = $cursor->toArray();
        if (count($rows) === 0) {
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Document not found.']);
            exit;
        }
        $doc = (array)$rows[0];

        $updateDoc = [
            '$unset' => [
                'signedSignature' => '',
                'signedByUserId' => '',
                'signedByUserName' => '',
                'signedAt' => '',
                'signedPosX' => '',
                'signedPosY' => '',
            ],
        ];
        $nextStatus = 'added';
        try {
            $sentToAdminNamespace = $config['database'] . '.sent_to_admin';
            $sentQuery = new MongoDB\Driver\Query(['documentId' => $docId], ['limit' => 1]);
            $sentCursor = $manager->executeQuery($sentToAdminNamespace, $sentQuery);
            if (count($sentCursor->toArray()) > 0) {
                $nextStatus = 'received';
            }
        } catch (Exception $e) {}
        $originalFileContent = (string)($doc['originalFileContent'] ?? '');
        if ($originalFileContent !== '') {
            $updateDoc['$set'] = ['fileContent' => $originalFileContent, 'status' => $nextStatus];
        } else {
            $updateDoc['$set'] = ['status' => $nextStatus];
        }

        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->update(
            ['_id' => new MongoDB\BSON\ObjectId($docId)],
            $updateDoc,
            ['multi' => false]
        );
        $manager->executeBulkWrite($documentsNamespace, $bulk);
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Signature deleted.']);
        exit;
    } catch (Exception $e) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to delete signature.']);
        exit;
    }
}

// Save signature position (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_signature_position') {
    $docId = trim($_POST['document_id'] ?? '');
    $posX = isset($_POST['pos_x']) ? (float)$_POST['pos_x'] : -1;
    $posY = isset($_POST['pos_y']) ? (float)$_POST['pos_y'] : -1;
    if (!preg_match('/^[a-f0-9]{24}$/i', $docId) || $posX < 0 || $posX > 1 || $posY < 0 || $posY > 1) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid save data.']);
        exit;
    }
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $query = new MongoDB\Driver\Query(
            ['_id' => new MongoDB\BSON\ObjectId($docId)],
            ['projection' => ['fileContent' => 1, 'originalFileContent' => 1, 'signedSignature' => 1, 'signedByUserName' => 1, 'signedAt' => 1]]
        );
        $cursor = $manager->executeQuery($documentsNamespace, $query);
        $rows = $cursor->toArray();
        if (count($rows) === 0) {
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Document not found.']);
            exit;
        }

        $doc = (array)$rows[0];
        $signatureData = (string)($doc['signedSignature'] ?? '');
        if ($signatureData === '') {
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Insert signature first before saving.']);
            exit;
        }

        $sourceFileContent = (string)($doc['originalFileContent'] ?? $doc['fileContent'] ?? '');
        if ($sourceFileContent === '') {
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Document file is empty.']);
            exit;
        }

        $signedByName = (string)($doc['signedByUserName'] ?? ($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User'));
        $signedAtText = '';
        if (!empty($doc['signedAt']) && $doc['signedAt'] instanceof MongoDB\BSON\UTCDateTime) {
            $signedAtText = $doc['signedAt']->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y h:i A');
        }
        if (!class_exists('ZipArchive')) {
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Server configuration issue: PHP ZIP extension is not enabled. Enable php_zip and restart Laragon/Apache.',
            ]);
            exit;
        }

        $updatedFileContent = dmsApplySignatureToDocx($sourceFileContent, $signatureData, $signedByName, $signedAtText, $posX, $posY);
        if ($updatedFileContent === false) {
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Could not write signature into the file.']);
            exit;
        }

        $bulk = new MongoDB\Driver\BulkWrite;
        $setData = [
            'signedPosX' => $posX,
            'signedPosY' => $posY,
            'fileContent' => $updatedFileContent,
            'status' => 'signed',
        ];
        if (!isset($doc['originalFileContent']) || $doc['originalFileContent'] === '') {
            $setData['originalFileContent'] = $sourceFileContent;
        }
        $bulk->update(
            ['_id' => new MongoDB\BSON\ObjectId($docId)],
            ['$set' => $setData],
            ['multi' => false]
        );
        $manager->executeBulkWrite($documentsNamespace, $bulk);
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Signature saved in database and document file.']);
        exit;
    } catch (Exception $e) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to save position.']);
        exit;
    }
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
                $docIsSignedAtSend = (strtolower(trim((string)($doc['status'] ?? ''))) === 'signed') || !empty($doc['signedSignature']);
                $docStatusAtSend = strtolower(trim((string)($doc['status'] ?? '')));
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
                                'signedAtSend'    => $docIsSignedAtSend,
                            ]);
                            $sentCount++;
                        }
                    }
                }
                if ($sentCount > 0) {
                    $manager->executeBulkWrite($sentNamespace, $bulk);
                    $docBulk = new MongoDB\Driver\BulkWrite;
                    $setDocData = [
                        'isSent' => true,
                        'sentAt' => new MongoDB\BSON\UTCDateTime(),
                        'sentVia' => 'heads',
                    ];
                    if ($docStatusAtSend === 'added' || $docStatusAtSend === 'sent') {
                        $setDocData['status'] = 'received';
                    }
                    $docBulk->update(
                        ['_id' => new MongoDB\BSON\ObjectId($docId)],
                        ['$set' => $setDocData],
                        ['multi' => false]
                    );
                    $manager->executeBulkWrite($documentsNamespace, $docBulk);
                    $historyNamespace = $config['database'] . '.document_history';
                    $historyBulk = new MongoDB\Driver\BulkWrite;
                    $historyBulk->insert([
                        'documentId'    => $docId,
                        'documentCode'  => $doc['documentCode'] ?? $doc['document_code'] ?? '',
                        'documentTitle' => $doc['documentTitle'] ?? $doc['document_title'] ?? '',
                        'action'        => 'Sent to Heads',
                        'dateTime'      => new MongoDB\BSON\UTCDateTime(),
                        'userId'        => $_SESSION['user_id'] ?? '',
                        'userName'      => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
                    ]);
                    $manager->executeBulkWrite($historyNamespace, $historyBulk);
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
            $docIsSignedAtSend = (strtolower(trim((string)($doc['status'] ?? ''))) === 'signed') || !empty($doc['signedSignature']);
            $docStatusAtSend = strtolower(trim((string)($doc['status'] ?? '')));
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
                'signedAtSend'   => $docIsSignedAtSend,
            ]);
            $manager->executeBulkWrite($sentNamespace, $bulk);
            $docBulk = new MongoDB\Driver\BulkWrite;
            $setDocData = [
                'isSent' => true,
                'sentAt' => new MongoDB\BSON\UTCDateTime(),
                'sentVia' => 'super_admin',
            ];
            if ($docStatusAtSend === 'added' || $docStatusAtSend === 'sent') {
                $setDocData['status'] = 'received';
            }
            $docBulk->update(
                ['_id' => new MongoDB\BSON\ObjectId($sendId)],
                ['$set' => $setDocData],
                ['multi' => false]
            );
            $manager->executeBulkWrite($documentsNamespace, $docBulk);
            $historyNamespace = $config['database'] . '.document_history';
            $historyBulk = new MongoDB\Driver\BulkWrite;
            $historyBulk->insert([
                'documentId'    => $sendId,
                'documentCode'  => $docCode,
                'documentTitle' => $docTitle,
                'action'        => 'Sent to Super Admin',
                'dateTime'      => new MongoDB\BSON\UTCDateTime(),
                'userId'        => $_SESSION['user_id'] ?? '',
                'userName'      => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
            ]);
            $manager->executeBulkWrite($historyNamespace, $historyBulk);
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
                        'createdByName' => $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'User',
                        'status'        => 'added',
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
    if ($showArchived) {
        $filter = ['status' => 'archived'];
    } elseif ($showSignedOnly) {
        $filter = ['status' => 'signed', 'isSent' => true];
    } else {
        // Active Documents view excludes any doc that is already both signed and sent,
        // so those records "move" to admin_archive.php automatically.
        $filter = [
            'status' => ['$ne' => 'archived'],
            '$or' => [
                ['status' => ['$ne' => 'signed']],
                ['isSent' => ['$ne' => true]],
            ],
        ];
    }
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

// Do not auto-show documents that were directly ADDED by Super Admin.
// (Sent-to-admin records are handled separately below.)
if (!$showArchived && !$showSignedOnly && !empty($documentsList)) {
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $usersNamespace = $config['database'] . '.users';
        $currentUserId = (string)($_SESSION['user_id'] ?? '');
        $currentUserName = trim((string)($_SESSION['user_name'] ?? ''));
        $currentUserEmail = trim((string)($_SESSION['user_email'] ?? ''));
        $creatorIdsMap = [];
        foreach ($documentsList as $doc) {
            $creatorId = (string)($doc['createdBy'] ?? '');
            if ($creatorId !== '' && preg_match('/^[a-f0-9]{24}$/i', $creatorId)) {
                $creatorIdsMap[$creatorId] = new MongoDB\BSON\ObjectId($creatorId);
            }
        }
        if (!empty($creatorIdsMap)) {
            $uq = new MongoDB\Driver\Query(
                [
                    '_id' => ['$in' => array_values($creatorIdsMap)],
                    'role' => ['$in' => ['superadmin', 'super_admin', 'super admin']],
                ],
                ['projection' => ['_id' => 1]]
            );
            $uc = $manager->executeQuery($usersNamespace, $uq);
            $superAdminCreatorIds = [];
            foreach ($uc as $u) {
                $ua = (array)$u;
                $uid = (string)($ua['_id'] ?? '');
                if ($uid !== '') $superAdminCreatorIds[$uid] = true;
            }
            if (!empty($superAdminCreatorIds)) {
                $documentsList = array_values(array_filter($documentsList, function ($doc) use ($superAdminCreatorIds, $currentUserId, $currentUserName, $currentUserEmail) {
                    $creatorId = (string)($doc['createdBy'] ?? '');
                    // Always keep documents created by the current admin user.
                    if ($currentUserId !== '' && $creatorId === $currentUserId) return true;
                    $creatorName = trim((string)($doc['createdByName'] ?? ''));
                    if ($currentUserName !== '' && strcasecmp($creatorName, $currentUserName) === 0) return true;
                    if ($currentUserEmail !== '' && strcasecmp($creatorName, $currentUserEmail) === 0) return true;
                    return !isset($superAdminCreatorIds[$creatorId]);
                }));
            }
        }
    } catch (Exception $e) {}
}

// Archive page rule: show only docs that are BOTH signed and sent.
// Also supports older records by checking send collections if isSent is missing.
if ($showSignedOnly && !empty($documentsList)) {
    $sentIds = [];
    try {
        $sentSuperNs = $config['database'] . '.sent_to_super_admin';
        $sentHeadsNs = $config['database'] . '.sent_to_department_heads';
        $signedDocIds = array_values(array_filter(array_map(function ($d) {
            return (string)($d['_id'] ?? '');
        }, $documentsList)));
        $qSuper = new MongoDB\Driver\Query(
            ['documentId' => ['$in' => $signedDocIds], 'signedAtSend' => true],
            ['projection' => ['documentId' => 1], 'limit' => 3000]
        );
        foreach ($manager->executeQuery($sentSuperNs, $qSuper) as $r) {
            $a = (array)$r;
            $id = (string)($a['documentId'] ?? '');
            if ($id === '') continue;
            $sentIds[$id] = true;
            if (!isset($sentToByDocId[$id])) $sentToByDocId[$id] = [];
            $sentToByDocId[$id]['Super Admin'] = true;
            $sentByName = trim((string)($a['sentByUserName'] ?? ''));
            if ($sentByName !== '') $sentByAdminByDocId[$id] = $sentByName;
        }
        $qHeads = new MongoDB\Driver\Query(
            ['documentId' => ['$in' => $signedDocIds], 'signedAtSend' => true],
            ['projection' => ['documentId' => 1, 'officeHeadName' => 1], 'limit' => 3000]
        );
        foreach ($manager->executeQuery($sentHeadsNs, $qHeads) as $r) {
            $a = (array)$r;
            $id = (string)($a['documentId'] ?? '');
            if ($id === '') continue;
            $sentIds[$id] = true;
            $headName = trim((string)($a['officeHeadName'] ?? ''));
            $recipient = $headName;
            if (!isset($sentToByDocId[$id])) $sentToByDocId[$id] = [];
            if ($recipient !== '') $sentToByDocId[$id][$recipient] = true;
            $sentByName = trim((string)($a['sentByUserName'] ?? ''));
            if ($sentByName !== '') $sentByAdminByDocId[$id] = $sentByName;
        }
    } catch (Exception $e) {}

    $documentsList = array_values(array_filter($documentsList, function ($doc) use ($sentIds) {
        $status = strtolower(trim((string)($doc['status'] ?? '')));
        $isSigned = $status === 'signed' || !empty($doc['signedSignature']);
        $id = (string)($doc['_id'] ?? '');
        $hasSentFlag = !empty($doc['isSent']);
        $isSent = $hasSentFlag || ($id !== '' && isset($sentIds[$id]));
        return $isSigned && $isSent;
    }));

    foreach ($documentsList as &$doc) {
        $id = (string)($doc['_id'] ?? '');
        $labels = [];
        $docStatusKey = strtolower(trim((string)($doc['status'] ?? '')));
        $isSignedDoc = ($docStatusKey === 'signed' || !empty($doc['signedSignature']));

        if (!$isSignedDoc) {
            $doc['sentToDisplay'] = '—';
            continue;
        }

        if ($id !== '' && isset($sentToByDocId[$id])) {
            $labels = array_keys($sentToByDocId[$id]);
        }
        if (empty($labels) && $docStatusKey === 'signed') {
            $sentVia = strtolower(trim((string)($doc['sentVia'] ?? '')));
            if ($sentVia === 'super_admin') $labels[] = 'Super Admin';
        }
        $doc['sentToDisplay'] = empty($labels) ? '—' : implode(', ', $labels);
        $doc['byDisplay'] = $sentByAdminByDocId[$id] ?? ((string)($doc['signedByUserName'] ?? $doc['createdByName'] ?? '—'));
    }
    unset($doc);
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

// Merge in documents sent from Super Admin (received flow) in active documents view.
if (!$showArchived && !$showSignedOnly) {
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $sentToAdminNamespace = $config['database'] . '.sent_to_admin';
        $idsInList = array_column($documentsList, '_id');
        $idsInList = array_flip(array_filter($idsInList));
        $query = new MongoDB\Driver\Query([], ['sort' => ['sentAt' => -1], 'limit' => 500]);
        $cursor = $manager->executeQuery($sentToAdminNamespace, $query);
        foreach ($cursor as $row) {
            $arr = (array)$row;
            $docId = (string)($arr['documentId'] ?? '');
            if ($docId === '') continue;
            $receivedByDocId[$docId] = (string)($arr['sentByUserName'] ?? 'Super Admin');
            if (isset($idsInList[$docId])) continue;
            $idsInList[$docId] = true;
            $documentsList[] = [
                '_id'            => $docId,
                'documentCode'   => $arr['documentCode'] ?? $arr['document_code'] ?? '—',
                'documentTitle'  => $arr['documentTitle'] ?? $arr['document_title'] ?? '—',
                'fileName'       => $arr['fileName'] ?? $arr['file_name'] ?? 'document.docx',
                'status'         => 'received',
                'byDisplay'      => (string)($arr['sentByUserName'] ?? 'Super Admin'),
            ];
        }
    } catch (Exception $e) {}
}

// For active view, map sent recipients so "By" can show who admin sent to.
if (!$showArchived && !$showSignedOnly && !empty($documentsList)) {
    try {
        $manager = new MongoDB\Driver\Manager($config['uri']);
        $sentSuperNs = $config['database'] . '.sent_to_super_admin';
        $sentHeadsNs = $config['database'] . '.sent_to_department_heads';
        $docIds = array_values(array_filter(array_map(function ($d) {
            return (string)($d['_id'] ?? '');
        }, $documentsList)));
        if (!empty($docIds)) {
            $qSuper = new MongoDB\Driver\Query(
                ['documentId' => ['$in' => $docIds]],
                ['projection' => ['documentId' => 1], 'limit' => 3000]
            );
            foreach ($manager->executeQuery($sentSuperNs, $qSuper) as $r) {
                $a = (array)$r;
                $id = (string)($a['documentId'] ?? '');
                if ($id === '') continue;
                if (!isset($sentToByDocId[$id])) $sentToByDocId[$id] = [];
                $sentToByDocId[$id]['Super Admin'] = true;
            }

            $qHeads = new MongoDB\Driver\Query(
                ['documentId' => ['$in' => $docIds]],
                ['projection' => ['documentId' => 1, 'officeHeadName' => 1], 'limit' => 3000]
            );
            foreach ($manager->executeQuery($sentHeadsNs, $qHeads) as $r) {
                $a = (array)$r;
                $id = (string)($a['documentId'] ?? '');
                if ($id === '') continue;
                $headName = trim((string)($a['officeHeadName'] ?? ''));
                if ($headName === '') continue;
                if (!isset($sentToByDocId[$id])) $sentToByDocId[$id] = [];
                $sentToByDocId[$id][$headName] = true;
            }
        }
    } catch (Exception $e) {}
}

// Keep sender display for listed docs based on their own fields.
foreach ($documentsList as &$doc) {
    $id = (string)($doc['_id'] ?? '');
    if ($id !== '' && isset($receivedByDocId[$id]) && (strtolower(trim((string)($doc['status'] ?? ''))) === 'received')) {
        $doc['byDisplay'] = $receivedByDocId[$id];
    } elseif (!empty($doc['isSent']) && $id !== '' && isset($sentToByDocId[$id])) {
        $doc['byDisplay'] = implode(', ', array_keys($sentToByDocId[$id]));
    }
    if (empty($doc['byDisplay'])) {
        $doc['byDisplay'] = (string)($doc['createdByName'] ?? $doc['signedByUserName'] ?? '—');
    }
}
unset($doc);

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
    <title>Admin - <?php echo htmlspecialchars($pageHeading); ?></title>
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
    .document-status-approved { background: #dcfce7; color: #166534; }
    .document-status-added { background: #dcfce7; color: #166534; }
    .document-status-active { background: #d1fae5; color: #047857; }
    .document-status-archived { background: #f3f4f6; color: #6b7280; }
    .document-status-received { background: #dbeafe; color: #1d4ed8; }
    .document-status-sent { background: #dbeafe; color: #1d4ed8; }
    .document-status-signed { background: #ede9fe; color: #5b21b6; }
    .documents-actions-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .documents-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit; transition: background 0.15s, color 0.15s; }
    .documents-action-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
    .documents-action-open { background: #dbeafe; color: #1d4ed8; }
    .documents-action-open:hover { background: #bfdbfe; color: #1d4ed8; }
    .documents-action-archive { background: #fef3c7; color: #b45309; }
    .documents-action-archive:hover { background: #fde68a; color: #b45309; }
    .documents-action-delete { background: #fee2e2; color: #b91c1c; }
    .documents-action-delete:hover { background: #fecaca; color: #991b1b; }
    .documents-action-send { background: #d1fae5; color: #047857; }
    .documents-action-send:hover { background: #a7f3d0; color: #047857; }
    .documents-action-send-super { background: #dbeafe; color: #1d4ed8; text-decoration: none; }
    .documents-action-send-super:hover { background: #bfdbfe; color: #1d4ed8; }
    .documents-send-wrap { position: relative; display: inline-block; }
    .documents-send-trigger { text-decoration: underline; }
    .documents-send-trigger:hover { text-decoration: underline; }
    .documents-send-dropdown { position: fixed; min-width: 200px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 8px 20px rgba(0,0,0,0.12); padding: 6px 0; z-index: 1600; display: none; }
    .documents-send-dropdown.show { display: block; }
    .documents-send-dropdown-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 10px 14px; border: none; background: none; color: #1e293b; font-size: 13px; font-weight: 500; cursor: pointer; text-align: left; text-decoration: none; font-family: inherit; box-sizing: border-box; transition: background 0.15s; }
    .documents-send-dropdown-item:hover { background: #f1f5f9; }
    .documents-send-dropdown-item svg { width: 16px; height: 16px; flex-shrink: 0; }
    /* Send to Heads modal – match system design (doc-modal, admin-offices.css) */
    #send-document-modal .doc-modal-dialog { max-width: 480px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04); }
    #send-document-modal .doc-modal-header { padding: 14px 18px; border-bottom: 1px solid #e2e8f0; }
    #send-document-modal .doc-modal-header h2 { font-size: 1.35rem; font-weight: 700; color: #1e293b; }
    #send-document-modal .doc-modal-close { color: #475569; }
    #send-document-modal .doc-modal-close:hover { color: #1e293b; }
    .send-modal-subtitle { margin: 0; padding: 16px 18px 12px 18px; font-size: 14px; color: #64748b; line-height: 1.5; border-bottom: 1px solid #f1f5f9; }
    #send-document-modal .doc-modal-form { padding: 16px 18px 18px; gap: 12px; }
    .send-heads-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .send-heads-toolbar-links { display: flex; gap: 12px; font-size: 14px; }
    .send-heads-toolbar-links button { background: none; border: none; color: #2563eb; cursor: pointer; padding: 0; font-family: inherit; font-size: inherit; font-weight: 600; }
    .send-heads-toolbar-links button:hover { color: #1d4ed8; text-decoration: underline; }
    .send-heads-toolbar-count { font-size: 14px; color: #475569; font-weight: 500; }
    .send-heads-list { max-height: 280px; overflow-y: auto; padding-right: 4px; }
    .send-heads-list::-webkit-scrollbar { width: 6px; }
    .send-heads-list::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
    .send-heads-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    .send-head-row { display: flex; align-items: center; gap: 14px; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 8px; cursor: pointer; transition: background 0.2s, border-color 0.2s, box-shadow 0.2s; background: #fff; }
    .send-head-row:hover { background: #f8fafc; border-color: #cbd5e1; }
    .send-head-row.selected { background: #eff6ff; border-color: #2563eb; box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.2); }
    .send-head-row input[type="checkbox"] { width: 18px; height: 18px; flex-shrink: 0; accent-color: #2563eb; cursor: pointer; }
    .send-head-row-content { flex: 1; min-width: 0; }
    .send-head-office { display: block; font-weight: 600; color: #1e293b; font-size: 14px; margin-bottom: 2px; }
    .send-head-name { display: block; color: #64748b; font-size: 13px; }
    .send-modal-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 6px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
    .send-modal-actions .documents-action-btn { min-height: 38px; padding: 8px 14px; font-size: 13px; font-weight: 600; border-radius: 10px; text-decoration: none; }
    .send-modal-actions .documents-action-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .send-modal-actions .send-submit-btn:disabled:hover { background: #fef3c7; color: #b45309; }
    #send-document-modal .documents-empty { font-size: 14px; color: #64748b; }
    #edit-document-modal .doc-modal-dialog { max-width: 620px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04); }
    .edit-modal-subtitle { margin: 0; padding: 16px 18px 12px 18px; font-size: 14px; color: #64748b; line-height: 1.5; border-bottom: 1px solid #f1f5f9; }
    .edit-documents-body { padding: 16px 18px 18px; }
    .edit-documents-list { display: grid; gap: 10px; max-height: 360px; overflow-y: auto; }
    .edit-documents-list::-webkit-scrollbar { width: 6px; }
    .edit-documents-list::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
    .edit-documents-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    .edit-document-item { width: 100%; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 12px 14px; text-align: left; cursor: pointer; transition: border-color 0.15s, box-shadow 0.15s, background 0.15s; }
    .edit-document-item:hover { border-color: #93c5fd; box-shadow: 0 0 0 1px rgba(37,99,235,0.2); background: #f8fafc; }
    .edit-document-item-title { display: block; font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 4px; }
    .edit-document-item-file { display: block; font-size: 13px; color: #64748b; }
    .edit-documents-empty { margin: 0; padding: 1rem 0; text-align: center; color: #64748b; font-size: 14px; }
    .doc-modal-dialog-view { max-width: 90%; width: 900px; max-height: 90vh; display: flex; flex-direction: column; }
    .document-view-body { flex: 1; min-height: 0; overflow: auto; padding: 1rem; background: #f8fafc; border-top: 1px solid #e2e8f0; }
    .document-view-container { overflow: auto; max-height: 65vh; padding: 1rem; background: #fff; border-radius: 8px; }
    .document-view-loading, .document-view-error { padding: 2rem; text-align: center; color: #64748b; }
    .document-view-error { color: #dc2626; }
    .document-view-container { position: relative; }
    .document-signature-overlay { position: absolute; width: 240px; height: 90px; background: transparent; border: none; border-radius: 0; padding: 0; text-align: center; z-index: 20; cursor: grab; user-select: none; box-shadow: none; }
    .document-signature-overlay.dragging { cursor: grabbing; filter: drop-shadow(0 6px 12px rgba(15,23,42,0.28)); }
    .document-signature-overlay img { width: 240px; height: 90px; object-fit: contain; pointer-events: none; display: block; }
    .document-signature-meta { position: absolute; top: calc(100% + 6px); left: 0; min-width: 240px; background: rgba(248,250,252,0.96); border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 8px; font-size: 11px; color: #64748b; line-height: 1.35; pointer-events: none; box-shadow: 0 4px 12px rgba(15,23,42,0.14); }
    .doc-modal-footer { flex-shrink: 0; padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; gap: 10px; display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; }
    .doc-modal-footer a.documents-action-btn { text-decoration: none; }
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
                    <li><a href="admin_archive.php" class="<?php echo $sidebar_active === 'archived' ? 'active' : ''; ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archived</a></li>
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
                            <h1 class="dept-page-title"><?php echo htmlspecialchars($pageHeading); ?></h1>
                            <p class="dept-page-subtitle"><?php echo htmlspecialchars($pageSubtitle); ?></p>
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
                    <h2 class="documents-title"><?php echo htmlspecialchars($cardHeading); ?></h2>
                    <div class="documents-tools">
                        <input type="text" id="search-documents" placeholder="<?php echo htmlspecialchars($searchPlaceholder); ?>" aria-label="<?php echo htmlspecialchars($searchPlaceholder); ?>">
                        <input type="date" id="documents-date-from" aria-label="From date">
                        <input type="date" id="documents-date-to" aria-label="To date">
                        <?php if (!$isArchiveView): ?>
                        <button type="button" class="documents-btn" id="open-add-document-modal">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                            Add Document
                        </button>
                        <button type="button" class="documents-btn documents-btn-secondary" id="edit-document-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                            Edit
                        </button>
                        <?php endif; ?>
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
                                    <?php if (!$showSignedOnly): ?><th>BY</th><?php endif; ?>
                                    <?php if ($showSignedOnly): ?><th>SENT TO</th><?php endif; ?>
                                    <th>ACTION</th>
                                </tr>
                            </thead>
                            <tbody id="documents-table-body">
                                <?php if (empty($documentsList)): ?>
                                <tr>
                                    <td colspan="<?php echo $showSignedOnly ? '7' : '7'; ?>" class="documents-empty" id="no-documents-row"><?php echo htmlspecialchars($emptyRowText); ?></td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($documentsList as $idx => $doc): ?>
                                <?php
                                    $docId = $doc['_id'] ?? '';
                                    $docCode = htmlspecialchars($doc['documentCode'] ?? $doc['document_code'] ?? '—');
                                    $docTitle = htmlspecialchars($doc['documentTitle'] ?? $doc['document_title'] ?? '—');
                                    $docFileName = htmlspecialchars($doc['fileName'] ?? $doc['file_name'] ?? '—');
                                    $rawStatus = strtolower(trim((string)($doc['status'] ?? '')));
                                    $hasSigned = ($rawStatus === 'signed' || !empty($doc['signedSignature']));
                                    $isSentDoc = !empty($doc['isSent']);
                                    $docStatus = 'Added';
                                    if ($showSignedOnly && $hasSigned && $isSentDoc) {
                                        $docStatus = 'Archived';
                                    } elseif ($hasSigned) {
                                        $docStatus = 'Signed';
                                    } elseif ($rawStatus === 'received') {
                                        $docStatus = 'Received';
                                    } elseif ($rawStatus === 'sent') {
                                        $docStatus = 'Sent';
                                    } elseif ($rawStatus === 'archived') {
                                        $docStatus = 'Archived';
                                    }
                                    $byDisplay = (string)($doc['byDisplay'] ?? '—');
                                    if ($docStatus === 'Added') {
                                        $byDisplay = trim((string)($doc['createdByName'] ?? '')) !== '' ? (string)$doc['createdByName'] : 'Admin';
                                    }
                                ?>
                                <tr data-document-row data-document-id="<?php echo htmlspecialchars($docId); ?>">
                                    <td><?php echo (int)($idx + 1); ?></td>
                                    <td><?php echo $docCode; ?></td>
                                    <td><?php echo $docTitle; ?></td>
                                    <td><a href="documents.php?view=<?php echo urlencode($docId); ?>" class="doc-file-link document-view-trigger" data-doc-id="<?php echo htmlspecialchars($docId); ?>" data-doc-name="<?php echo htmlspecialchars($docFileName); ?>"><?php echo $docFileName; ?></a></td>
                                    <td><span class="document-status document-status-<?php echo strtolower(htmlspecialchars($docStatus)); ?>"><?php echo htmlspecialchars($docStatus); ?></span></td>
                                    <?php if (!$showSignedOnly): ?><td><?php echo htmlspecialchars(dmsFormatActorName($byDisplay)); ?></td><?php endif; ?>
                                    <?php if ($showSignedOnly): ?><td><?php echo htmlspecialchars((string)($doc['sentToDisplay'] ?? '—')); ?></td><?php endif; ?>
                                    <td>
                                        <div class="documents-actions-row">
                                            <a href="documents.php?view=<?php echo urlencode($docId); ?>" class="documents-action-btn documents-action-open document-view-trigger" data-doc-id="<?php echo htmlspecialchars($docId); ?>" data-doc-name="<?php echo htmlspecialchars($docFileName); ?>" title="View document"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</a>
                                            <?php if (!$isArchiveView): ?>
                                            <div class="documents-send-wrap">
                                                <button type="button" class="documents-action-btn documents-action-send documents-send-trigger" data-document-id="<?php echo htmlspecialchars($docId); ?>" title="Send" aria-haspopup="true" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send</button>
                                                <div class="documents-send-dropdown" role="menu" aria-label="Send options">
                                                    <a href="documents.php?send=<?php echo urlencode($docId); ?>" class="documents-send-dropdown-item" data-send-action="super" onclick="return confirm('Send this document to Super Admin?');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send to Super Admin</a>
                                                    <button type="button" class="documents-send-dropdown-item" data-send-action="heads" data-document-id="<?php echo htmlspecialchars($docId); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Send to Heads</button>
                                                </div>
                                            </div>
                                            <?php endif; ?>
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
                <h2 id="send-document-title">Send to Heads</h2>
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
                    <p class="documents-empty" style="padding: 1rem 0; text-align: center; margin: 0;">No department heads assigned yet. Assign heads in <strong>Departments</strong> first.</p>
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
                    <button type="button" class="documents-action-btn documents-action-open" data-close-send-document><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel</button>
                    <button type="submit" class="documents-action-btn documents-action-archive send-submit-btn" id="send-submit-btn" <?php if (empty($departmentHeadsList)): ?>disabled<?php endif; ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send</button>
                </div>
            </form>
        </div>
    </div>

    <div class="doc-modal" id="edit-document-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-edit-document aria-label="Close"></button>
        <div class="doc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="edit-document-title">
            <div class="doc-modal-header">
                <h2 id="edit-document-title">Edit Document</h2>
                <button type="button" class="doc-modal-close" data-close-edit-document aria-label="Close">&times;</button>
            </div>
            <p class="edit-modal-subtitle">Choose a document to open it in the viewer. You can then update your profile signature before approving/signing.</p>
            <div class="edit-documents-body">
                <div class="edit-documents-list" id="edit-documents-list">
                    <?php if (empty($documentsList)): ?>
                    <p class="edit-documents-empty">No documents available to edit.</p>
                    <?php else: ?>
                    <?php foreach ($documentsList as $doc): ?>
                    <?php
                        $editDocId = $doc['_id'] ?? '';
                        $editDocCode = $doc['documentCode'] ?? $doc['document_code'] ?? '—';
                        $editDocTitle = $doc['documentTitle'] ?? $doc['document_title'] ?? 'Untitled';
                        $editDocFile = $doc['fileName'] ?? $doc['file_name'] ?? 'document.docx';
                    ?>
                    <button type="button" class="edit-document-item" data-edit-doc-id="<?php echo htmlspecialchars($editDocId); ?>" data-edit-doc-name="<?php echo htmlspecialchars($editDocFile); ?>">
                        <span class="edit-document-item-title"><?php echo htmlspecialchars($editDocCode . ' — ' . $editDocTitle); ?></span>
                        <span class="edit-document-item-file"><?php echo htmlspecialchars($editDocFile); ?></span>
                    </button>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="doc-modal" id="document-view-modal" hidden>
        <button type="button" class="doc-modal-overlay" data-close-document-view aria-label="Close"></button>
        <div class="doc-modal-dialog doc-modal-dialog-view" role="dialog" aria-modal="true" aria-labelledby="document-view-title">
            <div class="doc-modal-header">
                <h2 id="document-view-title" class="document-view-title">Document</h2>
                <button type="button" class="doc-modal-close" data-close-document-view aria-label="Close">&times;</button>
            </div>
            <div class="document-view-body">
                <div id="document-view-loading" class="document-view-loading">Loading document…</div>
                <div id="document-view-container" class="document-view-container" style="display:none;"></div>
                <div id="document-view-error" class="document-view-error" style="display:none;">Could not load document.</div>
            </div>
            <div class="doc-modal-footer doc-modal-actions">
                <a id="document-view-download-link" href="#" class="documents-action-btn documents-action-open" target="_blank" rel="noopener" download style="display:none;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download</a>
                <button type="button" id="document-sign-btn" class="documents-action-btn documents-action-send" title="Insert your profile signature and save" style="display:none;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>Insert Signature</button>
                <button type="button" id="document-sign-save-btn" class="documents-action-btn documents-action-send" title="Save signature location" style="display:none;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>Save</button>
                <button type="button" id="document-sign-delete-btn" class="documents-action-btn documents-action-delete" title="Delete signature" style="display:none;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>Delete Signature</button>
                <button type="button" class="documents-action-btn documents-action-open" data-close-document-view><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Close</button>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/_profile_modal_admin.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/jszip@3/dist/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.0/dist/docx-preview.min.js"></script>
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
        var editDocumentModal = document.getElementById('edit-document-modal');

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
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && editDocumentModal && !editDocumentModal.hidden) {
                closeEditDocumentModal();
            }
        });

        function openEditDocumentModal() {
            if (!editDocumentModal) return;
            editDocumentModal.hidden = false;
            document.body.classList.add('modal-open');
        }

        function closeEditDocumentModal() {
            if (!editDocumentModal) return;
            editDocumentModal.hidden = true;
            document.body.classList.remove('modal-open');
        }

        if (editBtn) {
            editBtn.addEventListener('click', function() {
                openEditDocumentModal();
            });
        }

        document.querySelectorAll('[data-close-edit-document]').forEach(function(btn) {
            btn.addEventListener('click', closeEditDocumentModal);
        });

        document.querySelectorAll('.edit-document-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var docId = this.getAttribute('data-edit-doc-id') || '';
                var docName = this.getAttribute('data-edit-doc-name') || 'document.docx';
                closeEditDocumentModal();
                if (docId) openDocumentViewModal(docId, docName, true);
            });
        });

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

        function openSendModalForDoc(docId) {
            if (sendDocumentIdInput) sendDocumentIdInput.value = docId;
            if (sendHeadsList) sendHeadsList.querySelectorAll('.send-head-cb').forEach(function(cb) { cb.checked = false; });
            updateSendSelection();
            if (sendModal) {
                sendModal.hidden = false;
                document.body.classList.add('modal-open');
            }
        }

        function closeAllSendDropdowns() {
            document.querySelectorAll('.documents-send-wrap').forEach(function(wrap) {
                wrap.classList.remove('open');
                var dd = wrap.querySelector('.documents-send-dropdown');
                if (dd) dd.classList.remove('show');
                var trigger = wrap.querySelector('.documents-send-trigger');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            });
        }

        document.querySelectorAll('.documents-send-trigger').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var wrap = this.closest('.documents-send-wrap');
                var dropdown = wrap ? wrap.querySelector('.documents-send-dropdown') : null;
                var isOpen = wrap && wrap.classList.contains('open');
                closeAllSendDropdowns();
                if (!isOpen && wrap && dropdown) {
                    var rect = this.getBoundingClientRect();
                    dropdown.style.left = rect.left + 'px';
                    dropdown.style.top = (rect.bottom + 4) + 'px';
                    wrap.classList.add('open');
                    dropdown.classList.add('show');
                    this.setAttribute('aria-expanded', 'true');
                }
            });
        });

        document.querySelectorAll('.documents-send-dropdown').forEach(function(dd) {
            dd.addEventListener('click', function(e) { e.stopPropagation(); });
        });

        document.querySelectorAll('.documents-send-dropdown-item[data-send-action="heads"]').forEach(function(item) {
            item.addEventListener('click', function(e) {
                var docId = this.getAttribute('data-document-id') || '';
                closeAllSendDropdowns();
                openSendModalForDoc(docId);
            });
        });

        document.addEventListener('click', function() {
            closeAllSendDropdowns();
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

        // Document view modal (same as Super Admin)
        var documentViewModal = document.getElementById('document-view-modal');
        var documentViewTitle = document.getElementById('document-view-title');
        var documentViewContainer = document.getElementById('document-view-container');
        var documentViewLoading = document.getElementById('document-view-loading');
        var documentViewError = document.getElementById('document-view-error');
        var documentViewDownloadLink = document.getElementById('document-view-download-link');
        var documentSignBtn = document.getElementById('document-sign-btn');
        var documentSignSaveBtn = document.getElementById('document-sign-save-btn');
        var documentSignDeleteBtn = document.getElementById('document-sign-delete-btn');
        var currentUserSignature = <?php echo json_encode((string)$currentUserSignature); ?>;
        var currentViewDocId = '';
        var currentViewDocName = '';
        var currentViewFromEditFlow = false;
        var currentSignaturePosX = 0.72;
        var currentSignaturePosY = 0.06;
        var signatureOverlayEl = null;

        function showToast(message, bgColor) {
            var toast = document.createElement('div');
            toast.setAttribute('role', 'status');
            toast.textContent = message;
            toast.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:1600;padding:0.75rem 1.25rem;background:' + (bgColor || '#22c55e') + ';color:#fff;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,0.15);';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 3500);
        }

        function removeSignatureOverlay() {
            if (signatureOverlayEl && signatureOverlayEl.parentNode) {
                signatureOverlayEl.parentNode.removeChild(signatureOverlayEl);
            }
            signatureOverlayEl = null;
        }

        function clamp(n, min, max) {
            return Math.max(min, Math.min(max, n));
        }

        function getPrimaryRenderSurface() {
            if (!documentViewContainer) return null;
            var selectors = ['.docx-wrapper', '.docx', '.docx-page', 'section'];
            for (var i = 0; i < selectors.length; i += 1) {
                var el = documentViewContainer.querySelector(selectors[i]);
                if (el && el.clientWidth > 200 && el.clientHeight > 200) return el;
            }
            var fallback = documentViewContainer.firstElementChild;
            return fallback || documentViewContainer;
        }

        function getSignatureBounds(el) {
            if (!documentViewContainer || !el) return null;
            var surface = getPrimaryRenderSurface();
            if (!surface) surface = documentViewContainer;
            var containerRect = documentViewContainer.getBoundingClientRect();
            var surfaceRect = surface.getBoundingClientRect();
            var cs = window.getComputedStyle(surface);
            var padLeft = parseFloat(cs.paddingLeft || '0') || 0;
            var padRight = parseFloat(cs.paddingRight || '0') || 0;
            var padTop = parseFloat(cs.paddingTop || '0') || 0;
            var padBottom = parseFloat(cs.paddingBottom || '0') || 0;
            var contentWidth = Math.max(0, surfaceRect.width - padLeft - padRight);
            var contentHeight = Math.max(0, surfaceRect.height - padTop - padBottom);
            var minLeft = (surfaceRect.left - containerRect.left) + documentViewContainer.scrollLeft + padLeft;
            var minTop = (surfaceRect.top - containerRect.top) + documentViewContainer.scrollTop + padTop;
            var maxLeft = minLeft + Math.max(0, contentWidth - el.offsetWidth);
            var maxTop = minTop + Math.max(0, contentHeight - el.offsetHeight);
            return {
                minLeft: minLeft,
                minTop: minTop,
                maxLeft: maxLeft,
                maxTop: maxTop
            };
        }

        function applySignaturePosition(el, posX, posY) {
            if (!documentViewContainer || !el) return;
            var bounds = getSignatureBounds(el);
            if (!bounds) return;
            var spanX = Math.max(0, bounds.maxLeft - bounds.minLeft);
            var spanY = Math.max(0, bounds.maxTop - bounds.minTop);
            var left = bounds.minLeft + (clamp(posX, 0, 1) * spanX);
            var top = bounds.minTop + (clamp(posY, 0, 1) * spanY);
            el.style.left = left + 'px';
            el.style.top = top + 'px';
            currentSignaturePosX = spanX > 0 ? ((left - bounds.minLeft) / spanX) : 0;
            currentSignaturePosY = spanY > 0 ? ((top - bounds.minTop) / spanY) : 0;
        }

        function buildSignatureOverlay(signatureData, signedByName, signedAtText, posX, posY, draggable) {
            if (!documentViewContainer || !signatureData) return;
            removeSignatureOverlay();
            var overlay = document.createElement('div');
            overlay.className = 'document-signature-overlay';
            overlay.style.cursor = draggable ? 'grab' : 'default';
            var signedBy = signedByName || 'User';
            var signedAt = signedAtText ? ('Signed at: ' + signedAtText) : '';
            overlay.innerHTML =
                '<img src="' + signatureData + '" alt="Document signature">' +
                '<div class="document-signature-meta">' +
                'Signed by: ' + signedBy + (signedAt ? '<br>' + signedAt : '') +
                '</div>';
            documentViewContainer.appendChild(overlay);
            signatureOverlayEl = overlay;
            requestAnimationFrame(function() {
                applySignaturePosition(overlay, posX, posY);
            });

            if (!draggable) return;

            var dragging = false;
            var startX = 0, startY = 0, startLeft = 0, startTop = 0;
            function onMove(e) {
                if (!dragging || !signatureOverlayEl) return;
                var dx = e.clientX - startX;
                var dy = e.clientY - startY;
                var bounds = getSignatureBounds(signatureOverlayEl);
                if (!bounds) return;
                var spanX = Math.max(0, bounds.maxLeft - bounds.minLeft);
                var spanY = Math.max(0, bounds.maxTop - bounds.minTop);
                var nextLeft = clamp(startLeft + dx, bounds.minLeft, bounds.maxLeft);
                var nextTop = clamp(startTop + dy, bounds.minTop, bounds.maxTop);
                signatureOverlayEl.style.left = nextLeft + 'px';
                signatureOverlayEl.style.top = nextTop + 'px';
                currentSignaturePosX = spanX > 0 ? ((nextLeft - bounds.minLeft) / spanX) : 0;
                currentSignaturePosY = spanY > 0 ? ((nextTop - bounds.minTop) / spanY) : 0;
            }
            function onUp() {
                dragging = false;
                if (signatureOverlayEl) signatureOverlayEl.classList.remove('dragging');
                window.removeEventListener('mousemove', onMove);
                window.removeEventListener('mouseup', onUp);
            }
            overlay.addEventListener('mousedown', function(e) {
                e.preventDefault();
                dragging = true;
                overlay.classList.add('dragging');
                startX = e.clientX;
                startY = e.clientY;
                startLeft = parseFloat(overlay.style.left || '0');
                startTop = parseFloat(overlay.style.top || '0');
                window.addEventListener('mousemove', onMove);
                window.addEventListener('mouseup', onUp);
            });
        }

        function loadSignatureMeta(docId) {
            if (!currentViewFromEditFlow) {
                removeSignatureOverlay();
                if (documentSignSaveBtn) documentSignSaveBtn.style.display = 'none';
                if (documentSignDeleteBtn) documentSignDeleteBtn.style.display = 'none';
                return Promise.resolve();
            }
            return fetch('documents.php?signature_meta=' + encodeURIComponent(docId))
                .then(function(res) { return res.json(); })
                .then(function(meta) {
                    if (meta && meta.success && meta.signedSignature) {
                        buildSignatureOverlay(
                            meta.signedSignature,
                            meta.signedByUserName,
                            meta.signedAtText,
                            typeof meta.signedPosX === 'number' ? meta.signedPosX : 0.72,
                            typeof meta.signedPosY === 'number' ? meta.signedPosY : 0.06,
                            currentViewFromEditFlow
                        );
                        if (documentSignSaveBtn) documentSignSaveBtn.style.display = currentViewFromEditFlow ? 'inline-flex' : 'none';
                        if (documentSignDeleteBtn) documentSignDeleteBtn.style.display = currentViewFromEditFlow ? 'inline-flex' : 'none';
                    } else {
                        if (documentSignSaveBtn) documentSignSaveBtn.style.display = 'none';
                        if (documentSignDeleteBtn) documentSignDeleteBtn.style.display = 'none';
                    }
                })
                .catch(function() {});
        }

        function openDocumentViewModal(docId, docName, fromEditFlow) {
            if (!documentViewModal || !documentViewContainer) return;
            currentViewDocId = docId;
            currentViewDocName = docName || 'document.docx';
            currentViewFromEditFlow = !!fromEditFlow;
            documentViewModal.hidden = false;
            document.body.classList.add('modal-open');
            documentViewTitle.textContent = currentViewDocName;
            documentViewLoading.style.display = 'block';
            documentViewContainer.style.display = 'none';
            documentViewContainer.innerHTML = '';
            removeSignatureOverlay();
            documentViewError.style.display = 'none';
            if (documentViewDownloadLink) {
                documentViewDownloadLink.href = 'documents.php?download=' + encodeURIComponent(docId);
                documentViewDownloadLink.style.display = currentViewFromEditFlow ? 'none' : 'inline-flex';
            }
            if (documentSignBtn) {
                documentSignBtn.style.display = currentViewFromEditFlow ? 'inline-flex' : 'none';
            }
            if (documentSignSaveBtn) {
                documentSignSaveBtn.style.display = 'none';
            }
            if (documentSignDeleteBtn) {
                documentSignDeleteBtn.style.display = 'none';
            }

            var viewUrl = 'documents.php?view=' + encodeURIComponent(docId);
            fetch(viewUrl)
                .then(function(res) {
                    if (!res.ok) throw new Error('Load failed');
                    var ct = (res.headers.get('Content-Type') || '').toLowerCase();
                    if (ct.indexOf('wordprocessingml') === -1 && ct.indexOf('octet-stream') === -1) {
                        return res.text().then(function() { throw new Error('Invalid response'); });
                    }
                    return res.blob();
                })
                .then(function(blob) {
                    documentViewLoading.style.display = 'none';
                    if (typeof docx !== 'undefined' && docx.renderAsync) {
                        return docx.renderAsync(blob, documentViewContainer).then(function() {
                            documentViewContainer.style.display = 'block';
                            return loadSignatureMeta(docId);
                        }).catch(function(err) {
                            documentViewError.textContent = 'Could not render document.';
                            documentViewError.style.display = 'block';
                        });
                    }
                    documentViewError.textContent = 'Document viewer not available.';
                    documentViewError.style.display = 'block';
                })
                .catch(function() {
                    documentViewLoading.style.display = 'none';
                    documentViewError.textContent = 'Could not load document.';
                    documentViewError.style.display = 'block';
                });
        }

        function closeDocumentViewModal() {
            if (!documentViewModal) return;
            documentViewModal.hidden = true;
            document.body.classList.remove('modal-open');
            currentViewDocId = '';
            currentViewDocName = '';
            currentViewFromEditFlow = false;
            if (documentViewContainer) {
                documentViewContainer.innerHTML = '';
                documentViewContainer.style.display = 'none';
            }
            removeSignatureOverlay();
            if (documentViewLoading) documentViewLoading.style.display = 'block';
            if (documentViewError) documentViewError.style.display = 'none';
            if (documentViewDownloadLink) documentViewDownloadLink.style.display = 'none';
            if (documentSignBtn) documentSignBtn.style.display = 'none';
            if (documentSignSaveBtn) documentSignSaveBtn.style.display = 'none';
            if (documentSignDeleteBtn) documentSignDeleteBtn.style.display = 'none';
        }

        if (documentSignBtn) {
            var signBtnDefaultHtml = documentSignBtn.innerHTML;
            documentSignBtn.addEventListener('click', function() {
                if (!currentViewDocId) return;
                if (!currentUserSignature) {
                    alert('No saved profile signature found. Please set your signature first in Settings.');
                    return;
                }
                documentSignBtn.disabled = true;
                documentSignBtn.textContent = 'Saving...';
                var payload = new URLSearchParams();
                payload.set('action', 'sign_document');
                payload.set('document_id', currentViewDocId);
                fetch('documents.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: payload.toString()
                })
                .then(function(res) { return res.json(); })
                .then(function(resp) {
                    if (!resp || !resp.success) {
                        throw new Error((resp && resp.message) || 'Failed to save signature.');
                    }
                    buildSignatureOverlay(
                        resp.signedSignature,
                        resp.signedByUserName,
                        resp.signedAtText,
                        typeof resp.signedPosX === 'number' ? resp.signedPosX : 0.72,
                        typeof resp.signedPosY === 'number' ? resp.signedPosY : 0.06,
                        currentViewFromEditFlow
                    );
                    if (documentSignSaveBtn) documentSignSaveBtn.style.display = currentViewFromEditFlow ? 'inline-flex' : 'none';
                    if (documentSignDeleteBtn) documentSignDeleteBtn.style.display = currentViewFromEditFlow ? 'inline-flex' : 'none';
                    showToast(resp.message || 'Signature inserted.');
                })
                .catch(function(err) {
                    alert(err.message || 'Could not save signature.');
                })
                .finally(function() {
                    documentSignBtn.disabled = false;
                    documentSignBtn.innerHTML = signBtnDefaultHtml;
                });
            });
        }

        if (documentSignSaveBtn) {
            documentSignSaveBtn.addEventListener('click', function() {
                if (!currentViewDocId || !signatureOverlayEl) return;
                documentSignSaveBtn.disabled = true;
                var payload = new URLSearchParams();
                payload.set('action', 'save_signature_position');
                payload.set('document_id', currentViewDocId);
                payload.set('pos_x', String(currentSignaturePosX));
                payload.set('pos_y', String(currentSignaturePosY));
                fetch('documents.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: payload.toString()
                })
                .then(function(res) { return res.json(); })
                .then(function(resp) {
                    if (!resp || !resp.success) throw new Error((resp && resp.message) || 'Failed to save.');
                    showToast(resp.message || 'Saved signature position.');
                })
                .catch(function(err) {
                    alert(err.message || 'Could not save signature position.');
                })
                .finally(function() {
                    documentSignSaveBtn.disabled = false;
                });
            });
        }

        if (documentSignDeleteBtn) {
            documentSignDeleteBtn.addEventListener('click', function() {
                if (!currentViewDocId) return;
                if (!confirm('Delete signature from this document?')) return;
                documentSignDeleteBtn.disabled = true;
                var payload = new URLSearchParams();
                payload.set('action', 'delete_signature');
                payload.set('document_id', currentViewDocId);
                fetch('documents.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: payload.toString()
                })
                .then(function(res) { return res.json(); })
                .then(function(resp) {
                    if (!resp || !resp.success) throw new Error((resp && resp.message) || 'Failed to delete signature.');
                    removeSignatureOverlay();
                    if (documentSignSaveBtn) documentSignSaveBtn.style.display = 'none';
                    if (documentSignDeleteBtn) documentSignDeleteBtn.style.display = 'none';
                    showToast(resp.message || 'Signature deleted.');
                    if (currentViewDocId) {
                        openDocumentViewModal(currentViewDocId, currentViewDocName, currentViewFromEditFlow);
                    }
                })
                .catch(function(err) {
                    alert(err.message || 'Could not delete signature.');
                })
                .finally(function() {
                    documentSignDeleteBtn.disabled = false;
                });
            });
        }

        document.querySelectorAll('.document-view-trigger').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                var docId = el.getAttribute('data-doc-id');
                var docName = el.getAttribute('data-doc-name') || 'document.docx';
                if (docId) openDocumentViewModal(docId, docName, false);
            });
        });

        document.querySelectorAll('[data-close-document-view]').forEach(function(btn) {
            btn.addEventListener('click', closeDocumentViewModal);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && documentViewModal && !documentViewModal.hidden) {
                closeDocumentViewModal();
            }
        });
    })();
    </script>
</body>
</html>
