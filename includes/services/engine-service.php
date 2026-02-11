<?php
/**
 * Engine service (post generation pipeline).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Engine_Service')) {
    class CBIA_Engine_Service {
        private $openai;

        public function __construct($openai = null) {
            $this->openai = $openai;
        }

        public function create_post($payload) {
            if (function_exists('cbia_create_single_blog_post')) {
                $title = (string)($payload['title'] ?? '');
                $post_date = (string)($payload['post_date_mysql'] ?? '');
                return cbia_create_single_blog_post($title, $post_date);
            }
            return array('ok' => false, 'post_id' => 0, 'error' => 'not_available');
        }

        public function openai_responses_call($payload = array()) {
            if ($this->openai && method_exists($this->openai, 'responses_call')) {
                return $this->openai->responses_call($payload);
            }
            if (function_exists('cbia_openai_responses_call')) {
                return cbia_openai_responses_call($payload);
            }
            return array('ok' => false, 'error' => 'OpenAI client not available');
        }
    }
}

