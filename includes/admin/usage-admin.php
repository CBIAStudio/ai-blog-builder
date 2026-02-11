<?php
/**
 * Admin UI for Usage tab (wrapper).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Usage_Admin')) {
    class CBIA_Usage_Admin implements CBIA_Admin_Tab {
        public function get_key() {
            return 'usage';
        }

        public function get_label() {
            return 'Usage';
        }

        public function get_priority() {
            return 45;
        }

        public function render() {
            $view = CBIA_INCLUDES_DIR . 'admin/views/usage.php';
            if (file_exists($view)) {
                include $view;
                return;
            }
            echo '<p>No se pudo cargar Usage.</p>';
        }
    }
}

