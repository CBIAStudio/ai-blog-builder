<?php
/**
 * Admin UI for Yoast tab (wrapper).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Yoast_Admin')) {
    class CBIA_Yoast_Admin implements CBIA_Admin_Tab {
        private $service;

        public function __construct($service = null) {
            $this->service = $service;
        }
        public function get_key() {
            return 'yoast';
        }

        public function get_label() {
            return 'Yoast';
        }

        public function get_priority() {
            return 50;
        }

        public function render() {
            $view = CBIA_INCLUDES_DIR . 'admin/views/yoast.php';
            if (file_exists($view)) {
                $cbia_yoast_service = $this->service;
                include $view;
                return;
            }
            if (function_exists('cbia_render_tab_yoast')) {
                cbia_render_tab_yoast();
                return;
            }
            echo '<p>No se pudo cargar Yoast.</p>';
        }
    }
}
