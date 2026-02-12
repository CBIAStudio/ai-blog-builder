<?php
/**
 * Admin UI for Old Posts tab.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Oldposts_Admin')) {
    class CBIA_Oldposts_Admin implements CBIA_Admin_Tab {
        private $service;

        public function __construct($service) {
            $this->service = $service;
        }

        public function get_key() {
            return 'oldposts';
        }

        public function get_label() {
            return 'Actualizar antiguos';
        }

        public function get_priority() {
            return 30;
        }

        public function render() {
            $view = CBIA_INCLUDES_DIR . 'admin/views/oldposts.php';
            if (file_exists($view)) {
                // Expose service to the view (gradual migration).
                $cbia_oldposts_service = $this->service;
                include $view;
                return;
            }
            if (function_exists('cbia_render_tab_oldposts')) {
                cbia_render_tab_oldposts();
                return;
            }
            echo '<p>No se pudo cargar Actualizar antiguos.</p>';
        }
    }
}

