<?php
/**
 * Legacy engine loader.
 *
 * This file is kept for backwards compatibility.
 * The engine implementation now lives in includes/engine/engine.php.
 */
if (!defined('ABSPATH')) exit;
if (function_exists('cbia_legacy_mark_used')) {
    cbia_legacy_mark_used(__FILE__);
}

require_once __DIR__ . '/engine/engine.php';
