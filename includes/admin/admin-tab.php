<?php
/**
 * Admin tab interface.
 */

if (!defined('ABSPATH')) exit;

if (!interface_exists('CBIA_Admin_Tab')) {
    interface CBIA_Admin_Tab {
        public function get_key();
        public function get_label();
        public function render();
    }
}

