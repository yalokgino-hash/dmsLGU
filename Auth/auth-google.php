<?php
/**
 * Google OAuth 2.0 login.
 * Configure GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in config.php or environment.
 */
session_start();

$config = require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/smtp_mailer.php';
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
        $_SESSION['unauthorized_email'] = $email;
        header('Location: no-access.php?google=1');
        exit;
    }

    $user = (array)$users[0];
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
