<?php
/**
 * Shared helpers for account/settings/profile (signature, photo, password).
 * Requires $config (from config.php) to be loaded before including.
 */

if (!isset($config)) {
    $config = require dirname(__DIR__) . '/config.php';
}

function getAccountManager() {
    global $config;
    return new MongoDB\Driver\Manager($config['uri']);
}

function getUserSignature($userId) {
    global $config;
    if ($userId === '') return '';
    $namespace = $config['database'] . '.users';
    try {
        $manager = getAccountManager();
        $query = new MongoDB\Driver\Query(['_id' => new MongoDB\BSON\ObjectId($userId)], ['projection' => ['signature' => 1]]);
        $cursor = $manager->executeQuery($namespace, $query);
        $users = $cursor->toArray();
        if (count($users) > 0) {
            $u = (array)$users[0];
            return trim($u['signature'] ?? '');
        }
    } catch (Exception $e) {}
    return '';
}

function updateUserSignature($userId, $signatureBase64) {
    global $config;
    if ($userId === '') return ['success' => false, 'message' => 'Not authenticated.'];
    $signatureBase64 = trim($signatureBase64);
    if ($signatureBase64 === '') return ['success' => false, 'message' => 'Signature is required.'];
    $namespace = $config['database'] . '.users';
    try {
        $manager = getAccountManager();
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->update(
            ['_id' => new MongoDB\BSON\ObjectId($userId)],
            ['$set' => ['signature' => $signatureBase64]],
            ['multi' => false]
        );
        $manager->executeBulkWrite($namespace, $bulk);
        $_SESSION['user_signature'] = $signatureBase64;
        return ['success' => true, 'message' => 'Signature updated successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function updateUserPhoto($userId, $photoBase64) {
    global $config;
    if ($userId === '') return ['success' => false, 'message' => 'Not authenticated.'];
    $photoBase64 = trim($photoBase64);
    if ($photoBase64 === '') return ['success' => false, 'message' => 'Photo is required.'];
    $namespace = $config['database'] . '.users';
    try {
        $manager = getAccountManager();
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->update(
            ['_id' => new MongoDB\BSON\ObjectId($userId)],
            ['$set' => ['photo' => $photoBase64]],
            ['multi' => false]
        );
        $manager->executeBulkWrite($namespace, $bulk);
        $_SESSION['user_photo'] = $photoBase64;
        return ['success' => true, 'message' => 'Profile photo updated successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

function changePassword($userId, $currentPassword, $newPassword, $confirmPassword) {
    global $config;
    $namespace = $config['database'] . '.users';
    if ($userId === '') {
        return ['success' => false, 'message' => 'Not authenticated.'];
    }
    $newPassword = trim($newPassword);
    $confirmPassword = trim($confirmPassword);
    if (strlen($newPassword) < 6) {
        return ['success' => false, 'message' => 'New password must be at least 6 characters.'];
    }
    if ($newPassword !== $confirmPassword) {
        return ['success' => false, 'message' => 'New password and confirmation do not match.'];
    }
    try {
        $manager = getAccountManager();
        $query = new MongoDB\Driver\Query(['_id' => new MongoDB\BSON\ObjectId($userId)]);
        $cursor = $manager->executeQuery($namespace, $query);
        $users = $cursor->toArray();
        if (count($users) === 0) {
            return ['success' => false, 'message' => 'User not found.'];
        }
        $user = (array)$users[0];
        $stored = $user['password'] ?? '';
        if (!password_verify($currentPassword, $stored) && $stored !== $currentPassword) {
            return ['success' => false, 'message' => 'Current password is incorrect.'];
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $bulk = new MongoDB\Driver\BulkWrite;
        $bulk->update(
            ['_id' => new MongoDB\BSON\ObjectId($userId)],
            ['$set' => ['password' => $hash]],
            ['multi' => false]
        );
        $manager->executeBulkWrite($namespace, $bulk);
        return ['success' => true, 'message' => 'Password updated successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}
