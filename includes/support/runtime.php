<?php
/**
 * Runtime helpers (v2.3)
 *
 * Keep execution time generous for long tasks.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_try_unlimited_runtime')) {
    /**
     * Best-effort runtime handler (no-op to avoid ini_set/set_time_limit warnings).
     */
    function cbia_try_unlimited_runtime() {
        return;
    }
}

