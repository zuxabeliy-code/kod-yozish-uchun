<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';
require_installed();
security_headers(false);
header('Cache-Control: no-cache');
$brand = setting('brand_name') ?: 'ADI BURGER';
?><!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= e($brand) ?></title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<link rel="stylesheet" href="app.css?v=<?= ADI_VERSION ?>">
</head>
<body>
<div id="app"><div class="loading"><img src="../../pos/icons/logo.png" alt="<?= e($brand) ?>"><div class="spin"></div></div></div>
<div id="toast"></div>
<script src="app.js?v=<?= ADI_VERSION ?>"></script>
</body>
</html>
