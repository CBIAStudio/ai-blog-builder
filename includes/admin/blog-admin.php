<?php
/**
 * Admin UI for Blog tab (wrapper).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Blog_Admin')) {
    class CBIA_Blog_Admin implements CBIA_Admin_Tab {
        private $service;

        public function __construct($service = null) {
            $this->service = $service;
        }

        public function get_key() {
            return 'blog';
        }

        public function get_label() {
            return 'Blog';
        }

        public function get_priority() {
            return 20;
        }

        public function render() {
            $view = CBIA_INCLUDES_DIR . 'admin/views/blog.php';
            if (file_exists($view)) {
                // Expose service to the view (gradual migration).
                $cbia_blog_service = $this->service;
                include $view;
                return;
            }
            if (function_exists('cbia_render_tab_blog')) {
                cbia_render_tab_blog();
                return;
            }
            echo '<p>No se pudo cargar Blog.</p>';
        }
    }
}

