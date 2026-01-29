<?php
/**
 * Yoast integration (wrapper around legacy helpers).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Yoast_Client')) {
    class CBIA_Yoast_Client {
        public function reindex_post($post_id) {
            if (function_exists('cbia_yoast_try_reindex_post')) {
                return cbia_yoast_try_reindex_post($post_id);
            }
            return false;
        }
    }
}
