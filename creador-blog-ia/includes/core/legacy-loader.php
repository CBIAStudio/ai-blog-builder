<?php
/**
 * Legacy module loader (keeps current behavior).
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_load_legacy_modules')) {
    function cbia_load_legacy_modules() {
        if (defined('CBIA_DISABLE_LEGACY_WRAPPERS') && CBIA_DISABLE_LEGACY_WRAPPERS) {
            if (function_exists('cbia_log')) {
                cbia_log('Legacy wrappers deshabilitados por CBIA_DISABLE_LEGACY_WRAPPERS.', 'WARN');
            }
            return;
        }
        $modules = array(
            CBIA_INCLUDES_DIR . 'legacy/cbia-config.php',
            CBIA_INCLUDES_DIR . 'engine/engine.php',
            CBIA_INCLUDES_DIR . 'legacy/cbia-blog.php',
            CBIA_INCLUDES_DIR . 'legacy/cbia-oldposts.php',
            CBIA_INCLUDES_DIR . 'legacy/cbia-costes.php',
            CBIA_INCLUDES_DIR . 'legacy/cbia-yoast.php',
        );

        foreach ($modules as $f) {
            if (file_exists($f)) {
                require_once $f;
            } else {
                if (function_exists('cbia_log')) {
                    cbia_log('No se encontró el módulo requerido: ' . basename($f), 'ERROR');
                }
            }
        }
    }
}
