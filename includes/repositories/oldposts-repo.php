<?php
/**
 * Oldposts repository (options/meta/log storage).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Oldposts_Repo')) {
    class CBIA_Oldposts_Repo {
        public function get_settings() {
            if (function_exists('cbia_oldposts_get_settings')) {
                return cbia_oldposts_get_settings();
            }
            $s = get_option('cbia_oldposts_settings', array());
            return is_array($s) ? $s : array();
        }

        public function save_settings($settings) {
            if (!is_array($settings)) return false;
            update_option('cbia_oldposts_settings', $settings);
            return true;
        }
    }
}

