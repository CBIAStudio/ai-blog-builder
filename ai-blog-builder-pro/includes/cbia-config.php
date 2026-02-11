<?php
if (!defined('ABSPATH')) exit;
if (function_exists('cbia_legacy_mark_used')) {
    cbia_legacy_mark_used(__FILE__);
}
// Legacy wrapper: map to current Pro implementation.
$cbia_config_files = [
    __DIR__ . '/admin/config.php',
];
foreach ($cbia_config_files as $cbia_file) {
    if (file_exists($cbia_file)) {
        require_once $cbia_file;
    }
}

