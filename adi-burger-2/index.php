<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_installed();
// Bosh sahifa: kassaga yo'naltiradi
header('Location: ' . base_path() . '/pos/');
