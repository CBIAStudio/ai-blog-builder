<?php
/**
 * Oldposts job runner (wrapper).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Oldposts_Job')) {
    class CBIA_Oldposts_Job {
        public function run_batch($opts = array()) {
            if (function_exists('cbia_oldposts_run_batch_v3')) {
                return cbia_oldposts_run_batch_v3($opts);
            }
            return array(0, 0, 0, 0);
        }
    }
}
