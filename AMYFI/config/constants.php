<?php
// Existing constants (BASE_URL, ROOT_PATH, etc.)
// Add a random token for securing cron URLs
define('CRON_TOKEN', 'b9e7a1f2c3d4e5f6');

/* Global constants – works on localhost AND InfinityFree */
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
define('BASE_URL', $protocol . $_SERVER['HTTP_HOST'] . '/');
define('ROOT_PATH', __DIR__ . '/../'); // points to AMYFI_FYP/AMYFI/
define('ASSET_URL', BASE_URL . 'assets/');
?>
