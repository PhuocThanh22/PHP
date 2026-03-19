<?php
// Redirect default project URL to the UI login page.
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$target = ($basePath !== '' ? $basePath : '') . '/Giao%20Di%E1%BB%87n/user/index.html';

header('Location: ' . $target);
exit;
