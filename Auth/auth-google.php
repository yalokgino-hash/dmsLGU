<?php
/**
 * Google OAuth 2.0 login.
 * Configure GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in config.php or environment.
 */
session_start();

$config = require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once dirname(__DIR__) . '/Super Admin Side/_activity_logger.php';
$clientId = $config['google_client_id'] ?? '';
$clientSecret = $config['google_client_secret'] ?? '';

if ($clientId === '' || $clientSecret === '') {
    header('Location: ../index.php?error=google_not_configured');
    exit;
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script = $_SERVER['SCRIPT_NAME'] ?? '/Auth/auth-google.php';
$redirectUri = $scheme . '://' . $host . $script;

function redirectByRole($role) {
    if ($role === 'superadmin') {
        header('Location: ../Super%20Admin%20Side/dashboard.php');
    } elseif ($role === 'admin') {
        header('Location: ../Admin%20Side/admin_dashboard.php');
    } elseif (in_array($role, ['departmenthead', 'department_head', 'dept_head'])) {
        header('Location: ../Department%20Heads%20Side/department_dashboard.php');
    } else {
        header('Location: ../Front%20Desk%20Side/staff_dashboard.php');
    }
    exit;
}

function sendOtpEmail($toEmail, $otp, $config, $displayName = '') {
    $expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
    if ($expiryMinutes <= 0) {
        $expiryMinutes = 5;
    }

    $subject = 'DMS LGU Solano - Your verification code';
    $nameLine = trim($displayName) !== '' ? trim($displayName) : 'User';
    $message = "Good day, {$nameLine}.\n\n"
        . "You requested to sign in to the DMS LGU Solano system using Google.\n"
        . "Your one-time verification code is: {$otp}\n\n"
        . "This code will expire in {$expiryMinutes} minute(s).\n"
        . "If you did not request this sign-in, please ignore this message.\n\n"
        . "Regards,\n"
        . "DMS LGU Solano";

    return sendEmailViaSmtp($toEmail, $subject, $message, $config);
}

function getAccountRestrictionMeta($user) {
    $state = strtolower(trim((string)($user['account_state'] ?? 'active')));
    if ($state === '' || $state === 'active') {
        return null;
    }
    if ($state === 'disabled') {
        return [
            'type' => 'disabled',
            'reason' => trim((string)($user['disabled_reason'] ?? '')),
            'days' => 0,
        ];
    }
    if ($state === 'suspended') {
        $until = $user['suspended_until'] ?? null;
        $untilTs = null;
        if ($until instanceof MongoDB\BSON\UTCDateTime) {
            $untilTs = $until->toDateTime()->getTimestamp();
        } elseif (is_numeric($until)) {
            $untilTs = (int)$until;
        }
        if ($untilTs !== null && $untilTs <= time()) {
            return null;
        }
        $days = 1;
        if ($untilTs !== null) {
            $days = (int)ceil(max(1, $untilTs - time()) / 86400);
        }
        return [
            'type' => 'suspended',
            'reason' => trim((string)($user['suspend_reason'] ?? '')),
            'days' => max(1, $days),
        ];
    }
    return null;
}

// Step 1: No code yet — redirect to Google consent
if (empty($_GET['code'])) {
    $params = [
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ];
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    header('Location: ' . $url);
    exit;
}

// Step 2: Exchange code for tokens
$code = $_GET['code'];
$tokenUrl = 'https://oauth2.googleapis.com/token';
$tokenBody = http_build_query([
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]);

$tokenContext = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $tokenBody,
    ],
]);
$tokenResponse = @file_get_contents($tokenUrl, false, $tokenContext);
if ($tokenResponse === false) {
    header('Location: ../index.php?error=google_token_failed');
    exit;
}

$tokenData = json_decode($tokenResponse, true);
$accessToken = $tokenData['access_token'] ?? null;
if (!$accessToken) {
    header('Location: ../index.php?error=google_token_failed');
    exit;
}

// Step 3: Get user info
$userInfoContext = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer " . $accessToken . "\r\n",
    ],
]);
$userInfoResponse = @file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false, $userInfoContext);
if ($userInfoResponse === false) {
    header('Location: ../index.php?error=google_userinfo_failed');
    exit;
}

$userInfo = json_decode($userInfoResponse, true);
$email = strtolower(trim($userInfo['email'] ?? ''));
$name = trim($userInfo['name'] ?? $email);
$picture = trim($userInfo['picture'] ?? '');

if ($email === '') {
    header('Location: ../index.php?error=google_no_email');
    exit;
}

// Step 4: Only allow login if this email already exists in the database (no auto-create)
$namespace = $config['database'] . '.users';
try {
    $manager = new MongoDB\Driver\Manager($config['uri']);
    // Case-insensitive email match (DB may store different casing)
    $filter = ['email' => new MongoDB\BSON\Regex('^' . preg_quote($email, '/') . '$', 'i')];
    $query = new MongoDB\Driver\Query($filter);
    $cursor = $manager->executeQuery($namespace, $query);
    $users = $cursor->toArray();

    if (count($users) === 0) {
        // Email not in database — pass email to no-access page via session
        activityLog($config, 'login_blocked', [
            'module' => 'auth',
            'login_type' => 'google_sso',
            'reason' => 'google_email_not_authorized',
            'target_email' => $email,
        ], 'blocked', ['email' => $email, 'name' => $name, 'role' => 'guest']);
        $_SESSION['unauthorized_email'] = $email;
        header('Location: no-access.php?google=1');
        exit;
    }

    $user = (array)$users[0];
    $resolvedActorName = trim((string)($user['name'] ?? ''));
    if ($resolvedActorName === '') {
        $resolvedActorName = trim((string)($user['username'] ?? ''));
    }
    if ($resolvedActorName === '') {
        $resolvedActorName = $email;
    }
    $resolvedActorRole = trim((string)($user['role'] ?? 'user'));

    $restriction = getAccountRestrictionMeta($user);
    if (is_array($restriction)) {
        if ($restriction['type'] === 'disabled') {
            activityLog($config, 'login_blocked', [
                'module' => 'auth',
                'login_type' => 'google_sso',
                'reason' => 'account_disabled',
                'target_email' => $email,
            ], 'blocked', ['id' => (string)($user['_id'] ?? ''), 'email' => $email, 'name' => $resolvedActorName, 'role' => $resolvedActorRole]);
            $url = '../index.php?error=google_account_disabled';
            if (!empty($restriction['reason'])) {
                $url .= '&reason=' . urlencode($restriction['reason']);
            }
            header('Location: ' . $url);
            exit;
        }
        if ($restriction['type'] === 'suspended') {
            activityLog($config, 'login_blocked', [
                'module' => 'auth',
                'login_type' => 'google_sso',
                'reason' => 'account_suspended',
                'days' => (string)($restriction['days'] ?? ''),
                'target_email' => $email,
            ], 'blocked', ['id' => (string)($user['_id'] ?? ''), 'email' => $email, 'name' => $resolvedActorName, 'role' => $resolvedActorRole]);
            $url = '../index.php?error=google_account_suspended&days=' . (int)$restriction['days'];
            if (!empty($restriction['reason'])) {
                $url .= '&reason=' . urlencode($restriction['reason']);
            }
            header('Location: ' . $url);
            exit;
        }
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
    if ($expiryMinutes <= 0) {
        $expiryMinutes = 5;
    }

    $_SESSION['google_otp_pending_user'] = [
        'user_id' => (string)$user['_id'],
        'user_email' => $user['email'] ?? $email,
        'user_name' => $user['name'] ?? $name,
        'user_username' => $user['username'] ?? '',
        'user_role' => $user['role'] ?? 'user',
        'user_photo' => $user['photo'] ?? $picture,
        'user_signature' => $user['signature'] ?? '',
    ];
    $_SESSION['google_otp_code_hash'] = password_hash($otp, PASSWORD_DEFAULT);
    $_SESSION['google_otp_expires_at'] = time() + ($expiryMinutes * 60);
    $_SESSION['google_otp_resend_at'] = time() + 30;
    $otpName = trim($user['name'] ?? '') ?: (trim($user['username'] ?? '') ?: $email);
    if (!sendOtpEmail($email, $otp, $config, $otpName)) {
        unset(
            $_SESSION['google_otp_pending_user'],
            $_SESSION['google_otp_code_hash'],
            $_SESSION['google_otp_expires_at'],
            $_SESSION['google_otp_resend_at']
        );
        header('Location: ../index.php?error=google_otp_send_failed');
        exit;
    }

    header('Location: verify-google-otp.php');
    exit;
} catch (Exception $e) {
    header('Location: ../index.php?error=google_login_failed');
    exit;
}
