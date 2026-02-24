<?php
session_start();

$config = require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/smtp_mailer.php';
$pending = $_SESSION['google_otp_pending_user'] ?? null;
if (!$pending || !is_array($pending)) {
    header('Location: ../index.php');
    exit;
}

$expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
if ($expiryMinutes <= 0) {
    $expiryMinutes = 5;
}

function sendOtpEmail($toEmail, $otp, $config, $displayName = '') {
    $expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
    if ($expiryMinutes <= 0) {
        $expiryMinutes = 5;
    }

    $subject = 'DMS LGU Solano verification code';
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

function finalizeLoginFromPending($pending) {
    $_SESSION['user_id'] = $pending['user_id'] ?? '';
    $_SESSION['user_email'] = $pending['user_email'] ?? '';
    $_SESSION['user_name'] = $pending['user_name'] ?? '';
    $_SESSION['user_username'] = $pending['user_username'] ?? '';
    $_SESSION['user_role'] = $pending['user_role'] ?? 'user';
    $_SESSION['user_photo'] = $pending['user_photo'] ?? '';
    $_SESSION['user_signature'] = $pending['user_signature'] ?? '';

    unset(
        $_SESSION['google_otp_pending_user'],
        $_SESSION['google_otp_code_hash'],
        $_SESSION['google_otp_expires_at'],
        $_SESSION['google_otp_resend_at']
    );
}

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

$error = '';
$notice = '';

if (isset($_POST['action']) && $_POST['action'] === 'resend') {
    $nextResendAt = (int)($_SESSION['google_otp_resend_at'] ?? 0);
    if ($nextResendAt > time()) {
        $wait = $nextResendAt - time();
        $error = 'Please wait ' . $wait . ' second(s) before requesting another code.';
    } else {
        $newOtp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['google_otp_code_hash'] = password_hash($newOtp, PASSWORD_DEFAULT);
        $_SESSION['google_otp_expires_at'] = time() + ($expiryMinutes * 60);
        $_SESSION['google_otp_resend_at'] = time() + 30;
        $otpName = trim($pending['user_name'] ?? '') ?: (trim($pending['user_username'] ?? '') ?: ($pending['user_email'] ?? 'User'));
        if (sendOtpEmail($pending['user_email'] ?? '', $newOtp, $config, $otpName)) {
            $notice = 'A new verification code was sent to your Gmail.';
        } else {
            $error = 'Failed to resend verification code. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'verify')) {
    $otpInput = trim($_POST['otp'] ?? '');
    $otpHash = $_SESSION['google_otp_code_hash'] ?? '';
    $otpExpiresAt = (int)($_SESSION['google_otp_expires_at'] ?? 0);

    if (!preg_match('/^\d{6}$/', $otpInput)) {
        $error = 'Enter a valid 6-digit code.';
    } elseif ($otpExpiresAt <= time()) {
        $error = 'Verification code expired. Please request a new code.';
    } elseif ($otpHash === '' || !password_verify($otpInput, $otpHash)) {
        $error = 'Incorrect verification code.';
    } else {
        finalizeLoginFromPending($pending);
        redirectByRole($_SESSION['user_role'] ?? 'user');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Gmail Code - DMS LGU</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body {
            margin: 0;
            min-height: 100vh;
            background: #081b2e;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .card {
            width: 100%;
            max-width: 460px;
            background: rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.35);
        }
        h1 { margin: 0 0 0.5rem; font-size: 1.45rem; }
        p { margin: 0 0 1rem; color: #cfe6ff; font-size: 0.95rem; }
        .email { color: #ffd400; font-weight: 600; }
        .msg {
            border-radius: 10px;
            padding: 0.75rem 0.9rem;
            margin-bottom: 0.9rem;
            font-size: 0.9rem;
        }
        .msg.error { background: rgba(239, 68, 68, 0.2); color: #fecaca; border: 1px solid rgba(248, 113, 113, 0.45); }
        .msg.ok { background: rgba(34, 197, 94, 0.18); color: #bbf7d0; border: 1px solid rgba(74, 222, 128, 0.4); }
        label { display: block; font-size: 0.9rem; color: #b8d4ee; margin: 0.35rem 0; }
        input[type="text"] {
            width: 100%;
            height: 46px;
            border-radius: 10px;
            border: 1px solid #1d3d62;
            background: #0a2036;
            color: #fff;
            padding: 0 12px;
            font-size: 1rem;
            letter-spacing: 0.22em;
            text-align: center;
        }
        .actions { display: flex; gap: 10px; margin-top: 1rem; }
        button {
            border: 0;
            border-radius: 10px;
            padding: 12px 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-primary { flex: 1; background: #ffd400; color: #0b2545; }
        .btn-primary:hover { background: #facc15; transform: translateY(-1px); }
        .secondary-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 0.8rem;
        }
        .btn-secondary {
            width: 100%;
            background: transparent;
            color: #cfe6ff;
            border: 1px solid #38587b;
        }
        .btn-secondary:hover {
            background: #16314f;
            border-color: #4f77a3;
            color: #ffffff;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            border-radius: 10px;
            border: 1px solid #38587b;
            color: #cfe6ff;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            padding: 12px 14px;
            background: rgba(10, 32, 54, 0.35);
            transition: all 0.2s ease;
        }
        .back-link:hover {
            background: #16314f;
            border-color: #4f77a3;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Verify Your Sign-In</h1>
        <p>We sent a 6-digit code to <span class="email"><?= htmlspecialchars($pending['user_email'] ?? '') ?></span>. Enter the code to continue.</p>

        <?php if ($error !== ''): ?>
            <div class="msg error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <div class="msg ok"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="verify">
            <label for="otp">Verification code</label>
            <input type="text" id="otp" name="otp" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="000000" required>
            <div class="actions">
                <button type="submit" class="btn-primary">Verify and Continue</button>
            </div>
        </form>

        <div class="secondary-actions">
            <form method="post">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="btn-secondary">Resend Code</button>
            </form>
            <a class="back-link" href="../index.php">Back to Login</a>
        </div>
    </div>
</body>
</html>
