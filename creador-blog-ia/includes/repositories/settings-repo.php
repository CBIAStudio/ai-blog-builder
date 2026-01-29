<?php
/**
 * Settings repository (wrapper around legacy options).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Settings_Repository')) {
    class CBIA_Settings_Repository {
        public function get_defaults() {
            if (function_exists('cbia_get_default_settings')) {
                return cbia_get_default_settings();
            }
            return array();
        }

        public function get_settings() {
            if (function_exists('cbia_get_settings')) {
                return cbia_get_settings();
            }
            $settings = get_option('cbia_settings', array());
            return is_array($settings) ? $settings : array();
        }

        public function update_merge($partial) {
            if (function_exists('cbia_update_settings_merge')) {
                return cbia_update_settings_merge((array)$partial);
            }
            $current = get_option('cbia_settings', array());
            if (!is_array($current)) $current = array();
            $merged = array_replace_recursive($current, (array)$partial);
            update_option('cbia_settings', $merged, false);
            return $merged;
        }
    }
}
