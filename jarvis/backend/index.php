<?php
// JARVIS backend: bosh sahifa. O'rnatilmagan bo'lsa installerga, aks holda admin panelga yo'naltiradi.
require __DIR__ . '/inc/bootstrap.php';
header('Location: ' . (jv_installed() ? 'admin/' : 'install/'));
exit;
