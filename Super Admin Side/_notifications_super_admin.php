<?php
/**
 * Super Admin notifications – documents sent to Super Admin.
 * Requires $config (or pass as argument). Returns count and list for badge + dropdown.
 */

if (!isset($config)) {
    $config = require dirname(__DIR__) . '/config.php';
}

/**
 * Get notifications from sent_to_super_admin (documents sent to Super Admin).
 * @param array|null $config Optional config; uses global $config if not set.
 * @return array ['count' => int, 'items' => [ ['notificationId','documentTitle','sentByUserName','sentAtFormatted','documentId','isRead'], ... ]]
 */
function getSuperAdminNotifications($config = null) {
    $c = $config;
    if ($c === null) {
        global $config;
        $c = isset($config) ? $config : null;
    }
    if (!$c || empty($c['database'])) {
        return ['count' => 0, 'items' => []];
    }
    $namespace = $c['database'] . '.sent_to_super_admin';
    $items = [];
    $unreadCount = 0;
    try {
        $manager = new MongoDB\Driver\Manager($c['uri']);
        $query = new MongoDB\Driver\Query([], ['sort' => ['sentAt' => -1], 'limit' => 20]);
        $cursor = $manager->executeQuery($namespace, $query);
        foreach ($cursor as $row) {
            $arr = (array)$row;
            $dt = $arr['sentAt'] ?? null;
            $readAt = $arr['readAt'] ?? null;
            $isRead = $readAt instanceof MongoDB\BSON\UTCDateTime;
            if (!$isRead) {
                $unreadCount++;
            }
            if ($dt instanceof MongoDB\BSON\UTCDateTime) {
                $sentAtFormatted = $dt->toDateTime()->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A');
            } else {
                $sentAtFormatted = '—';
            }
            $items[] = [
                'notificationId'  => isset($arr['_id']) ? (string)$arr['_id'] : '',
                'documentId'       => (string)($arr['documentId'] ?? ''),
                'documentTitle'    => trim($arr['documentTitle'] ?? $arr['document_title'] ?? 'Document'),
                'documentCode'     => trim($arr['documentCode'] ?? $arr['document_code'] ?? ''),
                'sentByUserName'   => trim($arr['sentByUserName'] ?? $arr['sentBy'] ?? 'Someone'),
                'sentAtFormatted'  => $sentAtFormatted,
                'isRead'           => $isRead,
            ];
        }
    } catch (Exception $e) {
        return ['count' => 0, 'items' => []];
    }
    return ['count' => $unreadCount, 'items' => $items];
}
