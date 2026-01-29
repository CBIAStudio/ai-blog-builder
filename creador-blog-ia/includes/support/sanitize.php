<?php
/**
 * Sanitization helpers.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_sanitize_textarea_preserve_lines')) {
    function cbia_sanitize_textarea_preserve_lines($value): string {
        $value = is_string($value) ? $value : '';
        $value = wp_unslash($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return trim($value);
    }
}

if (!function_exists('cbia_sanitize_csv_tags')) {
    function cbia_sanitize_csv_tags($value): string {
        $value = cbia_sanitize_textarea_preserve_lines($value);
        $value = str_replace("\n", ",", $value);
        $value = preg_replace('/\s*,\s*/', ',', $value);
        $value = preg_replace('/,+/', ',', $value);
        $value = trim($value, " ,\t\n\r\0\x0B");
        return $value;
    }
}
