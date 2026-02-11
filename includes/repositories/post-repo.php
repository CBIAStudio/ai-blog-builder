<?php
/**
 * WP Post repository.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Post_Repo')) {
    class CBIA_Post_Repo {
        public function get_post($post_id) {
            return get_post($post_id);
        }
    }
}

