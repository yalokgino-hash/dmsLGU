<?php
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            Unzip and copy <code>php_mongodb.dll</code> into Laragon’s PHP <code>ext</code> folder<br>
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
$message = isset($_GET['added']) ? 'Office added successfully.' : '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if ($code === '') {
        $error = 'Code is required.';
    } else {
        try {
            $manager = new MongoDB\Driver\Manager($config['uri']);
            $bulk = new MongoDB\Driver\BulkWrite;
            $bulk->insert([
                'code' => $code,
                'department' => $department,
            ]);
            $namespace = $config['database'] . '.' . $config['collection'];
            $manager->executeBulkWrite($namespace, $bulk);
            header('Location: ?added=1');
            exit;
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMS LGU – Add Office</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; max-width: 420px; margin: 2rem auto; padding: 0 1rem; }
        h1 { font-size: 1.25rem; margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.25rem; font-weight: 500; }
        input, textarea { width: 100%; padding: 0.5rem; margin-bottom: 1rem; border: 1px solid #ccc; border-radius: 4px; }
        textarea { min-height: 80px; resize: vertical; }
        button { background: #2563eb; color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .message { padding: 0.5rem; margin-bottom: 1rem; border-radius: 4px; }
        .message.success { background: #dcfce7; color: #166534; }
        .message.error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <h1>Add Office</h1>

    <?php if ($message): ?>
        <p class="message success"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="message error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" action="">
        <label for="code">Code *</label>
        <input type="text" id="code" name="code" required placeholder="e.g. MMO"
               value="<?= htmlspecialchars($_POST['code'] ?? '') ?>">

        <label for="department">Department</label>
        <input type="text" id="department" name="department" placeholder="e.g. Municipal Mayors Office"
               value="<?= htmlspecialchars($_POST['department'] ?? '') ?>">

        <button type="submit">Add</button>
    </form>
</body>
</html>
