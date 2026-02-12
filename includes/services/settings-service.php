<?php
/**
 * Settings service.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Settings_Service')) {
    class CBIA_Settings_Service {
        private $repo;

        public function __construct($repo = null) {
            $this->repo = $repo;
        }

        public function get_defaults() {
            if ($this->repo && method_exists($this->repo, 'get_defaults')) {
                return $this->repo->get_defaults();
            }
            if (function_exists('cbia_get_default_settings')) {
                return cbia_get_default_settings();
            }
            return array();
        }

        public function get_settings() {
            if ($this->repo && method_exists($this->repo, 'get_settings')) {
                return $this->repo->get_settings();
            }
            if (function_exists('cbia_get_settings')) {
                return cbia_get_settings();
            }
            $settings = get_option('cbia_settings', array());
            return is_array($settings) ? $settings : array();
        }

        public function update_merge($partial) {
            if ($this->repo && method_exists($this->repo, 'update_merge')) {
                return $this->repo->update_merge($partial);
            }
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

