<?php
// Local adapter endpoint for admin iframe; delegates to the single backend controller.
$_GET['api'] = 'get_services';
require dirname(__DIR__, 2) . '/index.php';
