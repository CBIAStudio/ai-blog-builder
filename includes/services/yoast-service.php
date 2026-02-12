<?php
/**
 * Yoast service (wrapper around legacy helpers).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Yoast_Service')) {
    class CBIA_Yoast_Service {
        private $client;

        public function __construct($client = null) {
            $this->client = $client;
        }

        public function reindex_post($post_id) {
            if ($this->client && method_exists($this->client, 'reindex_post')) {
                return $this->client->reindex_post($post_id);
            }
            if (function_exists('cbia_yoast_try_reindex_post')) {
                return cbia_yoast_try_reindex_post($post_id);
            }
            return false;
        }

        public function handle_post($batch = 50, $offset = 0, $force = false, $only_cbia = true) {
            if (function_exists('cbia_yoast_handle_post')) {
                return cbia_yoast_handle_post($batch, $offset, $force, $only_cbia);
            }
            return array($batch, $offset, $force, $only_cbia);
        }

        public function get_log() {
            if (function_exists('cbia_yoast_log_get')) {
                return cbia_yoast_log_get();
            }
            return '';
        }
    }
}

