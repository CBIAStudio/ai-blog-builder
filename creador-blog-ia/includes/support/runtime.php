<?php
/**
 * Runtime helpers (v2.3)
 *
 * Keep execution time generous for long tasks.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_try_unlimited_runtime')) {
    /**
     * Best-effort to remove execution time limits.
     * Safe to call multiple times.
     */
    function cbia_try_unlimited_runtime() {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');
    }
}
