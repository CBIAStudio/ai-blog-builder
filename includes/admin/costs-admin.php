<?php
/**
 * Admin UI for Costs tab.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Costs_Admin')) {
    class CBIA_Costs_Admin implements CBIA_Admin_Tab {
        private $service;

        public function __construct($service) {
            $this->service = $service;
        }

        public function get_key() {
            return 'costes';
        }

        public function get_label() {
            return 'Costes';
        }

        public function get_priority() {
            return 40;
        }

        public function render() {
            $view = CBIA_INCLUDES_DIR . 'admin/views/costs.php';
            if (file_exists($view)) {
                // Expose service to the view (gradual migration).
                $cbia_costs_service = $this->service;
                include $view;
                return;
            }
            if (function_exists('cbia_render_tab_costes')) {
                cbia_render_tab_costes();
                return;
            }
            echo '<p>No se pudo cargar Costes.</p>';
        }
    }
}

