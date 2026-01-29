<?php
/**
 * Base helpers for engine.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_get_settings')) {
    function cbia_get_settings() {
        if (defined('CBIA_OPTION_SETTINGS')) {
            $stored = get_option(CBIA_OPTION_SETTINGS, []);
            if (!is_array($stored)) $stored = [];

            if (function_exists('cbia_get_default_settings')) {
                $defaults = cbia_get_default_settings();
                return array_replace_recursive($defaults, $stored);
            }

            return $stored;
        }

        $s = get_option('cbia_settings', []);
        return is_array($s) ? $s : [];
    }
}

if (!function_exists('cbia_log_counter_key')) {
    function cbia_log_counter_key(){
        if (defined('CBIA_OPTION_LOG_COUNTER')) return CBIA_OPTION_LOG_COUNTER;
        return 'cbia_log_counter';
    }
}

if (!function_exists('cbia_log_key')) {
    function cbia_log_key(){
        if (defined('CBIA_OPTION_LOG')) return CBIA_OPTION_LOG;
        return 'cbia_activity_log';
    }
}

if (!function_exists('cbia_log')) {
    function cbia_log($message, $level = 'INFO') {
        if (function_exists('cbia_fix_mojibake')) {
            $message = cbia_fix_mojibake($message);
        }
        if (defined('CBIA_OPTION_LOG') && defined('CBIA_OPTION_LOG_COUNTER')) {
            $level = strtoupper(trim((string)$level ?: 'INFO'));
            $ts = function_exists('cbia_now_mysql') ? cbia_now_mysql() : current_time('mysql');
            $line = '[' . $ts . '][' . $level . '] ' . (string)$message;
            $log = (string) get_option(CBIA_OPTION_LOG, '');
            $log = $log ? ($log . "\n" . $line) : $line;

            if (strlen($log) > 250000) {
                $lines = explode("\n", $log);
                if (count($lines) > 2000) {
                    $lines = array_slice($lines, -2000);
                    $log = implode("\n", $lines);
                }
            }

            update_option(CBIA_OPTION_LOG, $log, false);

            $cnt = (int) get_option(CBIA_OPTION_LOG_COUNTER, 0);
            update_option(CBIA_OPTION_LOG_COUNTER, $cnt + 1, false);

            wp_cache_delete(CBIA_OPTION_LOG, 'options');
            wp_cache_delete(CBIA_OPTION_LOG_COUNTER, 'options');
            return;
        }

        $log = (string) get_option(cbia_log_key(), '');
        $ts  = current_time('mysql');
        $log .= "[{$ts}] [{$level}] {$message}\n";
        if (strlen($log) > 250000) $log = substr($log, -250000);

        update_option(cbia_log_key(), $log, false);

        // contador anti-cache para polling
        $cnt = (int) get_option(cbia_log_counter_key(), 0);
        update_option(cbia_log_counter_key(), $cnt + 1, false);

        // fuerza a no servir valores cacheados de options
        wp_cache_delete(cbia_log_key(), 'options');
        wp_cache_delete(cbia_log_counter_key(), 'options');
    }
}

if (!function_exists('cbia_get_log')) {
    function cbia_get_log() {
        if (defined('CBIA_OPTION_LOG') && defined('CBIA_OPTION_LOG_COUNTER')) {
            $log = (string) get_option(CBIA_OPTION_LOG, '');
            if (function_exists('cbia_fix_mojibake')) {
                $log = cbia_fix_mojibake($log);
            }
            $counter = (int) get_option(CBIA_OPTION_LOG_COUNTER, 0);
            return array('log' => $log, 'counter' => $counter);
        }

        $log = (string) get_option(cbia_log_key(), '');
        if (function_exists('cbia_fix_mojibake')) {
            $log = cbia_fix_mojibake($log);
        }
        $counter = (int) get_option(cbia_log_counter_key(), 0);
        return array('log' => $log, 'counter' => $counter);
    }
}

if (!function_exists('cbia_clear_log')) {
    function cbia_clear_log() {
        if (defined('CBIA_OPTION_LOG') && defined('CBIA_OPTION_LOG_COUNTER')) {
            delete_option(CBIA_OPTION_LOG);
            delete_option(CBIA_OPTION_LOG_COUNTER);
            wp_cache_delete(CBIA_OPTION_LOG, 'options');
            wp_cache_delete(CBIA_OPTION_LOG_COUNTER, 'options');
            return;
        }

        delete_option(cbia_log_key());
        delete_option(cbia_log_counter_key());
        wp_cache_delete(cbia_log_key(), 'options');
        wp_cache_delete(cbia_log_counter_key(), 'options');
    }
}
