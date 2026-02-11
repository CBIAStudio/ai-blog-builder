<?php
/**
 * Admin UI for Diagnostics tab.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Diagnostics_Admin')) {
    class CBIA_Diagnostics_Admin implements CBIA_Admin_Tab {
        public function get_key() {
            return 'diagnostics';
        }

        public function get_label() {
            return 'DiagnÃ³stico';
        }

        public function get_priority() {
            return 70;
        }

        public function render() {
            $view = CBIA_INCLUDES_DIR . 'admin/views/diagnostics.php';
            if (file_exists($view)) {
                include $view;
                return;
            }

            echo '<p>No se pudo cargar DiagnÃ³stico.</p>';
        }
    }
}

