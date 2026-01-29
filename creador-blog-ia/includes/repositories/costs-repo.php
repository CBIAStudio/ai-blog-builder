<?php
/**
 * Costs repository (options/meta/log storage).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Costs_Repo')) {
    class CBIA_Costs_Repo {
        public function get_settings() {
            if (function_exists('cbia_costes_get_settings')) {
                return cbia_costes_get_settings();
            }
            $s = get_option('cbia_costes_settings', array());
            return is_array($s) ? $s : array();
        }

        public function save_settings($settings) {
            if (!is_array($settings)) return false;
            update_option('cbia_costes_settings', $settings);
            return true;
        }
    }
}
