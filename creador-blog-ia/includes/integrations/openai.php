<?php
/**
 * OpenAI integration (wrapper around legacy helpers).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_OpenAI_Client')) {
    class CBIA_OpenAI_Client {
        public function responses_call($payload = array()) {
            if (function_exists('cbia_openai_responses_call')) {
                return cbia_openai_responses_call($payload);
            }
            return array('ok' => false, 'error' => 'OpenAI client not available');
        }

        public function pick_model($preferred = '', $fallback = '') {
            if (function_exists('cbia_pick_model')) {
                return cbia_pick_model($preferred, $fallback);
            }
            return $preferred ?: $fallback;
        }
    }
}
