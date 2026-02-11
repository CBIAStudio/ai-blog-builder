<?php
/**
 * CBIA Loader (v2.3 scaffolding)
 *
 * Central point to register hooks and admin tabs.
 * Keep this light; real logic lives in services/admin classes.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Loader')) {
    class CBIA_Loader {
        private $admin_router;
        private $scheduler;

        public function __construct($admin_router = null, $scheduler = null) {
            $this->admin_router = $admin_router;
            $this->scheduler = $scheduler;
        }

        public function register() {
            if ($this->admin_router) {
                $this->admin_router->register();
            }
            if ($this->scheduler) {
                $this->scheduler->register();
            }
        }
    }
}

