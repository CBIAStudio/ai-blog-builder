<?php
/**
 * Simple container to share instances.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Container')) {
    class CBIA_Container {
        private $items = array();

        public function set($key, $value) {
            $this->items[$key] = $value;
        }

        public function get($key, $default = null) {
            return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
        }
    }
}


