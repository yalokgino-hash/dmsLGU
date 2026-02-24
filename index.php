<?php
session_start();

// Check MongoDB extension
if (!class_exists('MongoDB\Driver\Manager')) {
    $phpVer = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $isNts = (PHP_ZTS === 0);
    $threadSafe = $isNts ? 'NTS' : 'TS';
    $arch = (PHP_INT_SIZE === 8) ? 'x64' : 'x86';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MongoDB extension required</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 560px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        h1 { color: #991b1b; font-size: 1.25rem; }
        code { background: #f1f5f9; padding: 0.15em 0.4em; border-radius: 4px; }
        ol { padding-left: 1.25rem; }
        li { margin-bottom: 0.5rem; }
        a { color: #2563eb; }
    </style>
</head>
<body>
    <h1>MongoDB PHP extension is not installed</h1>
    <p>This app needs the MongoDB PHP extension. Do this in Laragon:</p>
    <ol>
        <li><strong>Download the extension</strong><br>
            Get the DLL for PHP <strong><?= $phpVer ?></strong>, <strong><?= $arch ?></strong>, <strong><?= $threadSafe ?></strong> from:<br>
            <a href="https://windows.php.net/downloads/pecl/releases/mongodb/" target="_blank" rel="noopener">windows.php.net/downloads/pecl/releases/mongodb/</a><br>
            Pick the folder that matches your PHP version (e.g. 1.20.1), then download <code>php_mongodb-…-<?= $phpVer ?>-<?= $threadSafe ?>-<?= $arch ?>.zip</code>.
        </li>
        <li><strong>Install it</strong><br>
            Unzip and copy <code>php_mongodb.dll</code> into Laragon's PHP <code>ext</code> folder<br>
            (e.g. <code>C:\laragon\bin\php\php-<?= $phpVer ?>-*-<?= $arch ?>\ext\</code>).
        </li>
        <li><strong>Enable it in php.ini</strong><br>
            Right‑click Laragon tray → PHP → php.ini. Find the extensions section and add (or uncomment):<br>
            <code>extension=php_mongodb</code>
        </li>
        <li><strong>Restart Laragon</strong> (or Apache), then reload this page.</li>
    </ol>
</body>
</html>
    <?php
    exit;
}

$config = require __DIR__ . '/config.php';
$error = '';
$success = '';
$emailError = false;
$passwordError = false;
$adminError = '';
$adminUsernameError = false;
$adminPasswordError = false;

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . str_replace('?logout=1', '', $_SERVER['REQUEST_URI']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'admin') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $adminError = 'Username and password are required.';
        if ($username === '') $adminUsernameError = true;
        if ($password === '') $adminPasswordError = true;
    } else {
        try {
            $manager = new MongoDB\Driver\Manager($config['uri']);
            $desiredRole = trim($_POST['role'] ?? 'admin');
            if (!in_array($desiredRole, ['admin', 'superadmin'])) $desiredRole = 'admin';
            $filter = [
                '$or' => [
                    ['email' => $username],
                    ['username' => $username]
                ],
                'role' => $desiredRole
            ];
            $query = new MongoDB\Driver\Query($filter);
            $namespace = $config['database'] . '.users';
            $cursor = $manager->executeQuery($namespace, $query);
            $users = $cursor->toArray();
            if (count($users) === 0) {
                $adminError = 'Invalid admin credentials.';
                $adminUsernameError = true;
            } else {
                $user = (array)$users[0];
                $storedPassword = $user['password'] ?? '';
                $passwordMatch = (isset($user['password']) && password_verify($password, $storedPassword)) || $storedPassword === $password;
                if ($passwordMatch) {
                    $_SESSION['user_id'] = (string)$user['_id'];
                    $_SESSION['user_email'] = $user['email'] ?? $username;
                    $_SESSION['user_name'] = $user['name'] ?? $username;
                    $_SESSION['user_username'] = $user['username'] ?? '';
                    $sessionRole = $user['role'] ?? $desiredRole;
                    $_SESSION['user_role'] = $sessionRole;
                    if ($sessionRole === 'superadmin') {
                        header('Location: Super%20Admin%20Side/dashboard.php');
                    } else {
                        header('Location: Admin%20Side/admin_dashboard.php');
                    }
                    exit;
                } else {
                    $adminError = 'Invalid admin credentials.';
                    $adminPasswordError = true;
                }
            }
        } catch (Exception $e) {
            $adminError = 'Login error: ' . $e->getMessage();
        }
    }
}

// Handle staff login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['login_type']) || $_POST['login_type'] === 'staff') && isset($_POST['email']) && isset($_POST['password'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
        if ($email === '') $emailError = true;
        if ($password === '') $passwordError = true;
    } else {
        try {
            $manager = new MongoDB\Driver\Manager($config['uri']);
            $filter = ['email' => $email];
            $options = [];
            $query = new MongoDB\Driver\Query($filter, $options);
            $namespace = $config['database'] . '.users';
            $cursor = $manager->executeQuery($namespace, $query);
            $users = $cursor->toArray();
            
            if (count($users) === 0) {
                $error = 'Invalid email or password.';
                $emailError = true;
            } else {
                $user = $users[0];
                $userArray = (array)$user;
                
                // Check password - try password_verify if hash exists, otherwise direct comparison
                $storedPassword = $userArray['password'] ?? '';
                $passwordMatch = false;
                
                if (isset($userArray['password']) && password_verify($password, $storedPassword)) {
                    $passwordMatch = true;
                } elseif ($storedPassword === $password) {
                    // Plain text password (not recommended but might be existing data)
                    $passwordMatch = true;
                }
                
                if ($passwordMatch) {
                    $_SESSION['user_id'] = (string)$userArray['_id'];
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_name'] = $userArray['name'] ?? $email;
                    $_SESSION['user_username'] = $userArray['username'] ?? '';
                    $_SESSION['user_role'] = $userArray['role'] ?? 'user';
                    $_SESSION['user_photo'] = $userArray['photo'] ?? '';
                    $_SESSION['user_signature'] = $userArray['signature'] ?? '';
                    $role = $_SESSION['user_role'] ?? '';
                    if ($role === 'superadmin') {
                        header('Location: Super%20Admin%20Side/dashboard.php');
                    } elseif ($role === 'admin') {
                        header('Location: Admin%20Side/admin_dashboard.php');
                    } elseif (in_array($role, ['departmenthead', 'department_head', 'dept_head'])) {
                        header('Location: Department%20Heads%20Side/department_dashboard.php');
                    } else {
                        header('Location: Front%20Desk%20Side/staff_dashboard.php');
                    }
                    exit;
                } else {
                    $error = 'Invalid email or password.';
                    $passwordError = true;
                }
            }
        } catch (Exception $e) {
            $error = 'Login error: ' . $e->getMessage();
        }
    }
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Municipal Document Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
<!-- <link rel="stylesheet" href="styles.css"> -->
 <style>
    *{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Poppins',sans-serif;
}

body{
    background:#081b2e;
    color:#fff;
}

header{
    padding:18px 60px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    position:sticky; 
    top:0;
    z-index:1002;
    width:100%;
    background:rgba(8,27,46,0.85);
    backdrop-filter:blur(6px);
    box-shadow:0 2px 8px rgba(0,0,0,0.12);
}

.logo{
    display:flex;
    align-items:center;
    gap:12px;
}

.logo img{
    width:70px;      
    height:70px;   
    object-fit:contain;
    border-radius:8px;
}


.logo-text{
    display:flex;
    flex-direction:column;
    line-height:1.1;
}

.logo-text strong{
    font-size:18px;
}

.logo-text small{
    font-size:11px;
    color:#9ec6ef;
}

nav a{
    color:#cfe6ff;
    text-decoration:none;
    margin:0 15px;
    font-size:14px;
}

nav a:hover{
    color:#ffd400;
}

.nav-btn{
    background:#ffd400;
    color:#000;
    padding:10px 20px;
    border-radius:20px;
    font-weight:600;
    text-decoration:none;
}

.nav-btn:hover{
    color:#000;
    background:#fbbf24;
}


.hero{
    min-height:95vh;
    padding:70px;
    display:grid;
    grid-template-columns:1.1fr 0.9fr;
    gap:40px;
    align-items:center;
    background-image: linear-gradient(rgba(0,0,0,0.35), rgba(0,0,0,0.35)), url('img/solano.jpg');
    background-position: center;
    background-size: cover;
    background-repeat: no-repeat;
    color:#ffffff; 
    position:relative;
}

.hero-text .badge{
    display:inline-block;
    background:#2b3f66;
    padding:6px 14px;
    border-radius:20px;
    font-size:12px;
    color:#f4f8fc;
    margin-bottom:20px;
}

.hero-text h1{
    font-size:64px;
    line-height:1.05;
    margin-bottom:18px;
    font-weight:700;
    text-shadow: 0 6px 18px rgba(2,6,23,0.6);
}

.hero-text h1 span{
    color:#D4AF37;
}

.hero-text p{
    max-width:720px;
    color:#f6fbff; 
    margin-bottom:30px;
    font-size:18px;
    line-height:1.6;
    text-shadow: 0 2px 8px rgba(2,6,23,0.45);
}

.hero-actions{
    display:flex;
    gap:15px;
}

.btn-primary{
    background:#D4AF37;
    color:#0b2545;
    padding:14px 28px;
    border-radius:30px;
    font-weight:600;
    text-decoration:none;
}

.btn-secondary{
    border:1px solid #D4AF37;
    color:#D4AF37;
    padding:14px 28px;
    border-radius:30px;
    text-decoration:none;
}

.login-panels{
    display:flex;
    gap:20px;
    align-items:stretch;
    max-width:700px;
}

.login-panel{
    flex:1;
    border-radius:18px;
    padding:40px 36px;
    box-shadow:0 20px 40px rgba(0,0,0,0.3);
}

.login-panel-admin{
    background:rgba(255,255,255,0.95);
    color:#1e293b;
}

.login-panel-admin .field-group label,
.login-panel-admin .login-subtitle{
    color:#475569;
}

.login-panel-admin .field-group input{
    background:#f1f5f9;
    color:#0f172a;
    border:1px solid #e2e8f0;
}

.login-panel-admin .field-group input::placeholder{
    color:#94a3b8;
}

.login-panel-admin .login-link{
    color:#166534;
}

.login-panel-admin button{
    background:#166534;
    color:#fff;
}

.login-panel-staff{
    background:rgba(11,31,58,0.9);
    backdrop-filter:blur(15px);
    border:1px solid rgba(212,175,55,0.3);
}

.login-panel-staff .field-group label,
.login-panel-staff .login-subtitle{
    color:#b8d4ee;
}

.login-panel-staff .field-group input{
    background:#081b2e;
    color:#fff;
}

.login-panel-staff .login-link{
    color:#ffd400;
}

.login-panel-staff button{
    background:#ffd400;
    color:#0b2545;
}

.login-panel h3{
    margin-bottom:8px;
    font-size:20px;
}

.login-subtitle{
    font-size:13px;
    margin-bottom:24px;
}

.login-panel .field-group{
    margin-bottom:18px;
}

.login-panel .field-group label{
    font-size:14px;
    display:block;
    margin-bottom:8px;
}

.login-panel .field-group input{
    width:100%;
    padding:12px 14px;
    margin-bottom:0;
    border-radius:10px;
    border:none;
    font-size:15px;
}

.login-panel .field-error-slot{
    min-height:22px;
    margin-top:6px;
}

.login-panel .field-error-slot .field-error{
    font-size:13px;
    color:#ef4444;
}

.login-panel input.input-error{
    border:2px solid #ef4444;
}

.login-panel .login-link{
    display:block;
    font-size:13px;
    margin-bottom:20px;
    text-decoration:none;
}

.login-panel .login-link:hover{
    text-decoration:underline;
}

.login-panel button{
    width:100%;
    padding:14px;
    border:none;
    border-radius:12px;
    font-weight:600;
    cursor:pointer;
    font-size:15px;
}

.login-card{
    position:relative;
    background:rgba(255,255,255,0.06);
    backdrop-filter:blur(15px);
    border-radius:18px;
    padding:48px;
    width:100%;
    max-width:600px;
    margin:auto;
    box-shadow:0 20px 40px rgba(0,0,0,0.4);
}

.login-card h3{
    margin-bottom:24px;
    text-align:center;
    font-size:22px;
}

.login-card .field-group{
    margin-bottom:20px;
}

.login-card .field-group label{
    font-size:14px;
    color:#b8d4ee;
    display:block;
    margin-bottom:8px;
}

.login-card .field-group input{
    width:100%;
    padding:14px;
    margin-bottom:0;
    border-radius:10px;
    border:none;
    background:#081b2e;
    color:#fff;
    font-size:15px;
}

.login-card .password-wrap{
    position:relative;
    display:block;
}

.login-card .password-wrap input{
    padding-right:48px;
}

.login-card .password-toggle{
    position:absolute;
    top:50%;
    right:10px;
    transform:translateY(-50%);
    width:36px;
    height:36px;
    padding:0;
    border:none;
    background:transparent;
    color:#9ec6ef;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:8px;
    transition:color 0.2s, background 0.2s;
}

.login-card .password-toggle:hover{
    color:#b8d4ee;
    background:rgba(255,255,255,0.06);
}

.login-card .password-toggle svg{
    flex-shrink:0;
}

.login-card .field-error-slot{
    min-height:22px;
    margin-top:6px;
}

.login-card input.input-error{
    border:2px solid #ef4444;
}

.login-card input.input-error::placeholder{
    color:#fecaca;
}

.login-card .field-error-slot .field-error{
    font-size:13px;
    color:#ef4444;
}

.login-card .btn-signin{
    width:100%;
    padding:14px;
    margin-bottom:0;
    background:#ffd400;
    color:#000;
    border:none;
    border-radius:12px;
    font-weight:600;
    cursor:pointer;
    font-size:15px;
}
.login-card .btn-signin:hover{
    background:#fbbf24;
}

.login-card .hint{
    text-align:center;
    font-size:13px;
    margin-top:20px;
    color:#9ec6ef;
}

#login-modal .login-divider{
    display:flex;
    justify-content:center;
    align-items:center;
    width:100%;
    font-size:13px;
    margin:14px 0 14px;
    color:#9ec6ef;
    text-align:center;
}

#login-modal .btn-google{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    width:100%;
    padding:12px 14px;
    margin-top:0;
    background:#fff;
    color:#3c4043;
    border:1px solid #dadce0;
    border-radius:12px;
    font-family:inherit;
    font-size:15px;
    font-weight:500;
    cursor:pointer;
    transition:background 0.2s, box-shadow 0.2s;
    margin-top:0;
}
.btn-google:hover{
    background:#f8f9fa;
    box-shadow:0 1px 3px rgba(0,0,0,0.1);
}
.btn-google .google-icon{
    flex-shrink:0;
}

.field-row{
    display:flex;
    align-items:flex-start;
    gap:12px;
}

.field-main{
    flex:1;
}

.field-error{
    font-size:12px;
    color:#fca5a5;
    white-space:nowrap;
    padding-top:32px;
}

html {
    scroll-behavior: smooth;
}

.features{
    background:#0b1f3a;
    padding:120px 60px;
    text-align:center;
}

.features .section-intro {
    max-width: 640px;
    margin: -40px auto 50px;
    font-size: 16px;
    color: #cfd9eb;
    line-height: 1.6;
}

.features h2{
    color:#D4AF37;
    font-size:32px;
    margin-bottom:70px;
}

/* Departments section – compact spacing, no excess */
.departments-section{
    padding:40px 40px 48px;
}

.departments-section h2{
    margin-bottom:16px;
}

.departments-section .section-intro{
    margin:0 auto 28px;
}

.feature-grid{
    max-width:1100px;
    margin:auto;
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
    gap:30px;
}

.department-grid{
    max-width:1400px;
    grid-template-columns:repeat(6,1fr);
    gap:16px;
}

.feature{
    background:#1a2b4d;
    padding:30px;
    border-radius:18px;
    border:1px solid #2b3f66;
    transition:0.3s;
}

.feature:hover{
    transform:translateY(-5px);
    box-shadow:0 10px 20px rgba(0,0,0,0.2);
}

.feature h3{
    margin-bottom:12px;
    color:#D4AF37;
}

.feature p{
    font-size:14px;
    color:#cfd9eb;
}

/* Department boxes – reference style: rounded, dark blue, soft shadow, golden title, light text */
.departments-section .feature{
    background:#1B2956;
    padding:28px 24px;
    border-radius:24px;
    border:none;
    box-shadow:0 4px 24px rgba(0,0,0,0.25), 0 0 0 1px rgba(255,255,255,0.04);
}

.departments-section .feature:hover{
    box-shadow:0 8px 32px rgba(0,0,0,0.3), 0 0 0 1px rgba(255,255,255,0.06);
}

.departments-section .feature h3{
    color:#FFD700;
    font-weight:700;
    text-align:center;
    margin-bottom:14px;
    font-size:1rem;
    line-height:1.3;
}

.departments-section .feature p{
    color:#E8EEF4;
    font-size:13px;
    line-height:1.55;
    text-align:left;
}

footer{
    padding:20px;
    text-align:center;
    background:#081625;
    font-size:13px;
    color:#8fb6dd;
}

.modal{
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    z-index:1000;
    display:flex;
    align-items:center;
    justify-content:center;
}

.modal-overlay{
    position:absolute;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.5);
    backdrop-filter:blur(2px);
}

.modal-content{
    position:relative;
    z-index:1001;
    width:100%;
    max-width:680px;
    margin:0 16px;
}

.modal-close{
    position:absolute;
    top:16px;
    right:16px;
    background:rgba(255,255,255,0.1);
    border:none;
    color:#b8d4ee;
    font-size:24px;
    cursor:pointer;
    line-height:1;
    padding:0;
    width:36px;
    height:36px;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
    transition:background 0.2s, color 0.2s;
}

.modal-close:hover{
    background:rgba(255,255,255,0.15);
    color:#fff;
}

@media(max-width:1200px){
    .department-grid{
        grid-template-columns:repeat(3,1fr);
    }
}

@media(max-width:768px){
    .department-grid{
        grid-template-columns:repeat(2,1fr);
    }
}

@media(max-width:500px){
    .department-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:900px){
    .hero{
        grid-template-columns:1fr;
        text-align:center;
        padding: clamp(20px, 4vh, 40px) 24px;
    }
    .hero-actions{
        justify-content:center;
    }
    .login-panels{
        flex-direction:column;
        max-width:400px;
        margin:0 auto;
    }
    .hero-text h1{ font-size:36px; }
    .hero-text p{ font-size:15px; max-width:100%; }
}

 </style>
</head>
<body>

<header>
    <div class="logo">
        <img src="img/logo.png" alt="Municipal Logo">
        <div class="logo-text">
            <strong>Municipality of Solano</strong>
            <small>Municipal Document Management System</small>
        </div>
    </div>

    <nav>
        <a href="#top">Home</a>
        <a href="#features">Features</a>
        <a href="#departments">Departments</a>
        <a href="#about">About</a>
        <?php if ($isLoggedIn): ?>
            <a href="#" class="nav-btn"><?= htmlspecialchars($_SESSION['user_name']) ?></a>
            <a href="?logout=1" class="nav-btn">Logout</a>
        <?php else: ?>
            <a href="#" class="nav-btn" onclick="openLoginModal(); return false;"> Login</a>
        <?php endif; ?>
    </nav>
</header>

<section id="top" class="hero">

    <div class="hero-text">
        <!-- <div class="badge">Municipal Government Digital Solution</div> -->

        <h1>Solano Document <span>Management System</span></h1>

        <p>
            A centralized and secure digital platform developed for the Municipality of Solano, Nueva Vizcaya to efficiently manage, 
            archive, monitor, and retrieve official documents. The system is designed to enhance transparency, minimize paperwork, 
            streamline records management, and strengthen coordination among municipal offices and departments.
        </p>

    </div>

    <?php if (!$isLoggedIn): ?>
    <!-- Login Modal -->
    <div id="login-modal" class="modal" style="display: none;">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <div class="login-card">
                <button type="button" class="modal-close" onclick="closeLoginModal();" aria-label="Close">&times;</button>
                <h3>Login</h3>
                <form method="post">
                    <div class="field-group">
                        <label>Email</label>
                        <input type="email" name="email" placeholder="Enter your email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" class="<?= $emailError ? 'input-error' : '' ?>" required>
                        <div class="field-error-slot">
                            <?php if ($emailError): ?><span class="field-error">Invalid email or account not found.</span><?php endif; ?>
                        </div>
                    </div>

                    <div class="field-group">
                        <label>Password</label>
                        <div class="password-wrap">
                            <input type="password" name="password" id="login-password" placeholder="Enter your password" class="<?= $passwordError ? 'input-error' : '' ?>" required>
                            <button type="button" class="password-toggle" onclick="togglePassword(this)" aria-label="Show password" title="Show password">
                                <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="icon-eye-off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                        <div class="field-error-slot">
                            <?php if ($passwordError): ?><span class="field-error">Wrong password.</span><?php endif; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn-signin">Sign In</button>

                    <div class="login-divider">Or</div>

                    <button type="button" class="btn-google" title="Sign in with Google" data-google-login-url="<?= htmlspecialchars($config['google_login_url'] ?? 'auth-google.php') ?>">
                        <svg class="google-icon" viewBox="0 0 24 24" width="20" height="20">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                        </svg>
                        Sign In with Google
                    </button>
                </form>
                <?php if ($success): ?>
                    <div style="background: #dcfce7; color: #166534; padding: 0.75rem; border-radius: 6px; margin-top: 1rem; font-size: 0.9rem;">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                <div class="hint">Authorized personnel access only</div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="login-card" style="background: #dcfce7; padding: 2rem; text-align: center;">
        <h3 style="color: #166534; margin-bottom: 1rem;">Welcome, <?= htmlspecialchars($_SESSION['user_name']) ?>!</h3>
        <p style="color: #166534; margin-bottom: 1.5rem;">You are successfully logged in.</p>
        <a href="?logout=1" style="display: inline-block; padding: 0.75rem 1.5rem; background: #2563eb; color: white; text-decoration: none; border-radius: 6px;">Logout</a>
    </div>
    <?php endif; ?>

</section>

<section id="features" class="features">
    <h2>System Features</h2>

    <div class="feature-grid">
        <div class="feature">
            <h3>Centralized Document Repository</h3>
            <p>Store and manage municipal records in one secure digital archive.</p>
        </div>

        <div class="feature">
            <h3>Department-Based Access Control</h3>
            <p>Ensure data privacy with role-based permissions for different offices.</p>
        </div>

        <div class="feature">
            <h3>Document Tracking & Monitoring</h3>
            <p>Track incoming, outgoing, and archived documents with real-time status updates.</p>
        </div>

        <div class="feature">
            <h3>Secure Digital Archiving</h3>
            <p>Protect important municipal records with encryption and secure backups.</p>
        </div>

        <div class="feature">
            <h3>Advanced Search & Retrieval</h3>
            <p>Quickly locate documents using filters, categories, and reference numbers.</p>
        </div>

        <div class="feature">
            <h3>Audit Logs & Transparency</h3>
            <p>Maintain accountability with detailed activity logs and document history tracking.</p>
        </div>
    </div>
</section>

<section id="departments" class="features departments-section">
    <h2>Departments</h2>
    <p class="section-intro">Municipal offices and departments of the Municipality of Solano, Nueva Vizcaya use the system for secure document management and coordination.</p>
    <div class="feature-grid department-grid">
        <div class="feature">
            <h3>Municipal Mayor's Office – Business Permits & Licensing (MMO-BPLS)</h3>
            <p>Business permits, licenses, and related documents.</p>
        </div>
        <div class="feature">
            <h3>Municipal Mayor's Office – Human Resource Management (MMO-HRMS)</h3>
            <p>HR records, appointments, and personnel documents.</p>
        </div>
        <div class="feature">
            <h3>Municipal Accounting Office (MACCO)</h3>
            <p>Accounting records, financial reports, and audit-related documents.</p>
        </div>
        <div class="feature">
            <h3>Municipal Budget Office (MBO)</h3>
            <p>Budget proposals, appropriations, and budget execution documents.</p>
        </div>
        <div class="feature">
            <h3>Municipal Treasurer's Office (MTO)</h3>
            <p>Collection records, disbursements, and treasury reports.</p>
        </div>
        <div class="feature">
            <h3>Municipal Assessor's Office (MAO)</h3>
            <p>Property assessments, tax declarations, and valuation records.</p>
        </div>
        <div class="feature">
            <h3>Municipal Planning and Development Office (MPDO)</h3>
            <p>Development plans, permits, and planning-related records.</p>
        </div>
        <div class="feature">
            <h3>Solano Economic Enterprise and Development Office (SEEDO)</h3>
            <p>Public market, slaughterhouse, and economic enterprise documents.</p>
        </div>
        <div class="feature">
            <h3>Municipal Agriculture Office (MAGRO)</h3>
            <p>Agricultural programs, extension services, and related records.</p>
        </div>
        <div class="feature">
            <h3>Municipal Engineering Office (MEO)</h3>
            <p>Infrastructure projects, permits, and engineering documents.</p>
        </div>
        
            <h3>Municipal Health Office (MHO)</h3>
            <p>Health programs, medical records, and sanitation documents.</p>
        </div>
        <div class="feature">
            <h3>Municipal Social Welfare and Development Office (MSWDO)</h3>
            <p>Social services, assistance programs, and welfare records.</p>
        </div>
        <div class="feature">
            <h3>Municipal General Services Office (MGSO)</h3>
            <p>General administration, procurement, and property records.</p>
        </div>
        <div class="feature">
            <h3>Municipal Civil Registrar's Office (MCRO)</h3>
            <p>Birth, marriage, death, and other civil registry documents.</p>
        </div>
        <div class="feature">
            <h3>Municipal Disaster Risk Reduction and Management Office (MDRRMO)</h3>
            <p>Disaster preparedness, response plans, and emergency management records.</p>
        </div>
    </div>
</section>

<section id="about" class="features about-section">
    <h2>About</h2>
    <p class="section-intro">The Municipal Document Management System (DMS) is a centralized digital platform for the Municipality of Solano, Nueva Vizcaya.</p>
    <div class="feature-grid about-grid">
        <div class="feature">
            <h3>Purpose</h3>
            <p>To efficiently manage, archive, monitor, and retrieve official municipal documents while enhancing transparency and inter-office coordination.</p>
        </div>
        <div class="feature">
            <h3>Who Uses It</h3>
            <p>Super Admin, Admin, Department Heads, and Front Desk staff—each with role-based access to documents and workflows.</p>
        </div>
        <div class="feature">
            <h3>Contact</h3>
            <p>For access or support, contact your department head or the municipal IT office.</p>
        </div>
    </div>
</section>

<footer>
    © <?php echo date("Y"); ?> Municipal Government Document Management System. All rights reserved.
</footer>

<script>
function openLoginModal() {
    var modal = document.getElementById('login-modal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

document.querySelectorAll('.btn-google').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var url = this.getAttribute('data-google-login-url') || 'auth-google.php';
        if (url) window.location.href = url;
    });
});

function togglePassword(btn) {
    var wrap = btn.closest('.password-wrap');
    var input = wrap && wrap.querySelector('input');
    var eye = wrap && wrap.querySelector('.icon-eye');
    var eyeOff = wrap && wrap.querySelector('.icon-eye-off');
    if (!input || !eye || !eyeOff) return;
    if (input.type === 'password') {
        input.type = 'text';
        eye.style.display = 'none';
        eyeOff.style.display = 'block';
        btn.setAttribute('aria-label', 'Hide password');
        btn.setAttribute('title', 'Hide password');
    } else {
        input.type = 'password';
        eye.style.display = 'block';
        eyeOff.style.display = 'none';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('title', 'Show password');
    }
}

function closeLoginModal() {
    var modal = document.getElementById('login-modal');
    if (modal) {
        modal.style.display = 'none';
        var form = modal.querySelector('form');
        if (form) {
            var emailInput = form.querySelector('input[name="email"]');
            var passwordInput = form.querySelector('input[name="password"]');
            if (emailInput) { emailInput.value = ''; emailInput.classList.remove('input-error'); }
            if (passwordInput) {
                passwordInput.value = '';
                passwordInput.classList.remove('input-error');
                passwordInput.type = 'password';
                var wrap = passwordInput.closest('.password-wrap');
                if (wrap) {
                    var eye = wrap.querySelector('.icon-eye');
                    var eyeOff = wrap.querySelector('.icon-eye-off');
                    if (eye) eye.style.display = 'block';
                    if (eyeOff) eyeOff.style.display = 'none';
                    var toggle = wrap.querySelector('.password-toggle');
                    if (toggle) toggle.style.display = 'none';
                }
            }
            form.querySelectorAll('.field-error').forEach(function(el) { el.style.display = 'none'; });
        }
    }
}

// Show the password-eye toggle only when the user types something into the password field.
(function() {
    var modalForm = document.querySelector('#login-modal form');
    if (!modalForm) return;
    var passwordInput = modalForm.querySelector('input[name="password"]');
    if (!passwordInput) return;
    var wrap = passwordInput.closest('.password-wrap');
    if (!wrap) return;
    var toggleBtn = wrap.querySelector('.password-toggle');
    var eye = wrap.querySelector('.icon-eye');
    var eyeOff = wrap.querySelector('.icon-eye-off');

    function updateToggleVisibility() {
        if (passwordInput.value && passwordInput.value.length > 0) {
            // show toggle button
            if (toggleBtn) toggleBtn.style.display = '';
        } else {
            // hide toggle button and reset to password mode
            if (toggleBtn) toggleBtn.style.display = 'none';
            passwordInput.type = 'password';
            if (eye) eye.style.display = 'block';
            if (eyeOff) eyeOff.style.display = 'none';
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-label', 'Show password');
                toggleBtn.setAttribute('title', 'Show password');
            }
        }
    }

    // update on input (typing/paste/backspace)
    passwordInput.addEventListener('input', updateToggleVisibility);

    // initialize visibility on load
    updateToggleVisibility();
})();

<?php if ($error): ?>
openLoginModal();
setTimeout(function () {
    var form = document.querySelector('#login-modal form');
    if (form) {
        var emailInput = form.querySelector('input[name="email"]');
        var passwordInput = form.querySelector('input[name="password"]');
        if (emailInput) emailInput.classList.remove('input-error');
        if (passwordInput) passwordInput.classList.remove('input-error');
        form.querySelectorAll('.field-error').forEach(function(el) { el.style.display = 'none'; });
    }
}, 3000);
<?php endif; ?>
</script>

</body>
</html>
    