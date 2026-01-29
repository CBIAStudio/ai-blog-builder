<?php
/**
 * Costs service (business logic).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Costs_Service')) {
    class CBIA_Costs_Service {
        private $repo;

        public function __construct($repo = null) {
            $this->repo = $repo;
        }

        public function get_settings() {
            if ($this->repo && method_exists($this->repo, 'get_settings')) {
                return $this->repo->get_settings();
            }
            if (function_exists('cbia_costes_get_settings')) return cbia_costes_get_settings();
            return array();
        }

        public function save_settings($settings) {
            if ($this->repo && method_exists($this->repo, 'save_settings')) {
                return $this->repo->save_settings($settings);
            }
            if (is_array($settings)) {
                update_option('cbia_costes_settings', $settings);
                return true;
            }
            return false;
        }

        public function estimate_post_cost($post_id, $cost_settings, $cbia_settings) {
            if (function_exists('cbia_costes_estimate_for_post')) {
                return cbia_costes_estimate_for_post($post_id, $cost_settings, $cbia_settings);
            }
            return null;
        }

        public function calc_real_for_post($post_id, $cost_settings, $cbia_settings = null) {
            if (function_exists('cbia_costes_calc_real_for_post')) {
                if ($cbia_settings === null && function_exists('cbia_get_settings')) {
                    $cbia_settings = cbia_get_settings();
                }
                return cbia_costes_calc_real_for_post($post_id, $cost_settings, is_array($cbia_settings) ? $cbia_settings : array());
            }
            return null;
        }

        public function calc_last_posts($n, $only_cbia, $use_est_if_missing, $cost_settings, $cbia_settings) {
            if (function_exists('cbia_costes_calc_last_posts')) {
                return cbia_costes_calc_last_posts($n, $only_cbia, $use_est_if_missing, $cost_settings, $cbia_settings);
            }
            return null;
        }

        public function get_log() {
            if (function_exists('cbia_costes_log_get')) {
                return cbia_costes_log_get();
            }
            return (string)get_option('cbia_costes_log', '');
        }

        public function handle_post($cost, $cbia, $defaults, $table, $model_text_current) {
            $notice = '';
            $calibration_info = null;

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                return array($cost, $notice, $calibration_info);
            }

            $u = wp_unslash($_POST);

            if (!empty($u['cbia_form']) && $u['cbia_form'] === 'costes_settings' && check_admin_referer('cbia_costes_settings_nonce')) {
                $cost['usd_to_eur'] = isset($u['usd_to_eur']) ? (float)str_replace(',', '.', (string)$u['usd_to_eur']) : $cost['usd_to_eur'];
                if ($cost['usd_to_eur'] <= 0) $cost['usd_to_eur'] = 0.92;

                $cost['tokens_per_word'] = isset($u['tokens_per_word']) ? (float)str_replace(',', '.', (string)$u['tokens_per_word']) : $cost['tokens_per_word'];
                if ($cost['tokens_per_word'] < 0.5) $cost['tokens_per_word'] = 0.5;
                if ($cost['tokens_per_word'] > 2.0) $cost['tokens_per_word'] = 2.0;

                $cost['input_overhead_tokens'] = isset($u['input_overhead_tokens']) ? (int)$u['input_overhead_tokens'] : (int)$cost['input_overhead_tokens'];
                if ($cost['input_overhead_tokens'] < 0) $cost['input_overhead_tokens'] = 0;
                if ($cost['input_overhead_tokens'] > 5000) $cost['input_overhead_tokens'] = 5000;

                $cost['per_image_overhead_words'] = isset($u['per_image_overhead_words']) ? (int)$u['per_image_overhead_words'] : (int)$cost['per_image_overhead_words'];
                if ($cost['per_image_overhead_words'] < 0) $cost['per_image_overhead_words'] = 0;
                if ($cost['per_image_overhead_words'] > 300) $cost['per_image_overhead_words'] = 300;

                $cost['cached_input_ratio'] = isset($u['cached_input_ratio']) ? (float)str_replace(',', '.', (string)$u['cached_input_ratio']) : (float)$cost['cached_input_ratio'];
                if ($cost['cached_input_ratio'] < 0) $cost['cached_input_ratio'] = 0;
                if ($cost['cached_input_ratio'] > 1) $cost['cached_input_ratio'] = 1;

                // Tarifa fija por imagen
                $cost['use_image_flat_pricing'] = !empty($u['use_image_flat_pricing']) ? 1 : 0;
                $cost['image_flat_usd_mini'] = isset($u['image_flat_usd_mini']) ? (float)str_replace(',', '.', (string)$u['image_flat_usd_mini']) : (float)$cost['image_flat_usd_mini'];
                if ($cost['image_flat_usd_mini'] < 0) $cost['image_flat_usd_mini'] = 0.0;
                $cost['image_flat_usd_full'] = isset($u['image_flat_usd_full']) ? (float)str_replace(',', '.', (string)$u['image_flat_usd_full']) : (float)$cost['image_flat_usd_full'];
                if ($cost['image_flat_usd_full'] < 0) $cost['image_flat_usd_full'] = 0.0;

                $cost['mult_text'] = isset($u['mult_text']) ? (float)str_replace(',', '.', (string)$u['mult_text']) : (float)$cost['mult_text'];
                if ($cost['mult_text'] < 1.0) $cost['mult_text'] = 1.0;
                if ($cost['mult_text'] > 5.0) $cost['mult_text'] = 5.0;

                $cost['mult_image'] = isset($u['mult_image']) ? (float)str_replace(',', '.', (string)$u['mult_image']) : (float)$cost['mult_image'];
                if ($cost['mult_image'] < 1.0) $cost['mult_image'] = 1.0;
                if ($cost['mult_image'] > 5.0) $cost['mult_image'] = 5.0;

                $cost['mult_seo'] = isset($u['mult_seo']) ? (float)str_replace(',', '.', (string)$u['mult_seo']) : (float)$cost['mult_seo'];
                if ($cost['mult_seo'] < 1.0) $cost['mult_seo'] = 1.0;
                if ($cost['mult_seo'] > 5.0) $cost['mult_seo'] = 5.0;

                // Ajustes finos
                $cost['responses_fixed_usd_per_call'] = isset($u['responses_fixed_usd_per_call']) ? (float)str_replace(',', '.', (string)$u['responses_fixed_usd_per_call']) : (float)$cost['responses_fixed_usd_per_call'];
                if ($cost['responses_fixed_usd_per_call'] < 0) $cost['responses_fixed_usd_per_call'] = 0.0;
                $cost['real_adjust_multiplier'] = isset($u['real_adjust_multiplier']) ? (float)str_replace(',', '.', (string)$u['real_adjust_multiplier']) : (float)$cost['real_adjust_multiplier'];
                if ($cost['real_adjust_multiplier'] < 0.5) $cost['real_adjust_multiplier'] = 0.5;
                if ($cost['real_adjust_multiplier'] > 1.5) $cost['real_adjust_multiplier'] = 1.5;

                // nº llamadas texto/imagen
                $cost['text_calls_per_post'] = isset($u['text_calls_per_post']) ? (int)$u['text_calls_per_post'] : (int)$cost['text_calls_per_post'];
                if ($cost['text_calls_per_post'] < 1) $cost['text_calls_per_post'] = 1;
                if ($cost['text_calls_per_post'] > 20) $cost['text_calls_per_post'] = 20;

                $cost['image_calls_per_post'] = isset($u['image_calls_per_post']) ? (int)$u['image_calls_per_post'] : (int)$cost['image_calls_per_post'];
                if ($cost['image_calls_per_post'] < 0) $cost['image_calls_per_post'] = 0;
                if ($cost['image_calls_per_post'] > 20) $cost['image_calls_per_post'] = 20;

                // modelo imagen (solo 2)
                $im = isset($u['image_model']) ? sanitize_text_field((string)$u['image_model']) : (string)$cost['image_model'];
                if (!isset($table[$im]) || ($im !== 'gpt-image-1' && $im !== 'gpt-image-1-mini')) {
                    $im = 'gpt-image-1-mini';
                }
                $cost['image_model'] = $im;

                // output tokens por imagen
                $cost['image_output_tokens_per_call'] = isset($u['image_output_tokens_per_call']) ? (int)$u['image_output_tokens_per_call'] : (int)$cost['image_output_tokens_per_call'];
                if ($cost['image_output_tokens_per_call'] < 0) $cost['image_output_tokens_per_call'] = 0;
                if ($cost['image_output_tokens_per_call'] > 50000) $cost['image_output_tokens_per_call'] = 50000;

                // SEO settings
                $cost['seo_calls_per_post'] = isset($u['seo_calls_per_post']) ? (int)$u['seo_calls_per_post'] : (int)$cost['seo_calls_per_post'];
                if ($cost['seo_calls_per_post'] < 0) $cost['seo_calls_per_post'] = 0;
                if ($cost['seo_calls_per_post'] > 20) $cost['seo_calls_per_post'] = 20;

                $seo_model = isset($u['seo_model']) ? sanitize_text_field((string)$u['seo_model']) : (string)$cost['seo_model'];
                if ($seo_model === '' || !isset($table[$seo_model])) $seo_model = $model_text_current;
                $cost['seo_model'] = $seo_model;

                $cost['seo_input_tokens_per_call'] = isset($u['seo_input_tokens_per_call']) ? (int)$u['seo_input_tokens_per_call'] : (int)$cost['seo_input_tokens_per_call'];
                if ($cost['seo_input_tokens_per_call'] < 0) $cost['seo_input_tokens_per_call'] = 0;
                if ($cost['seo_input_tokens_per_call'] > 50000) $cost['seo_input_tokens_per_call'] = 50000;

                $cost['seo_output_tokens_per_call'] = isset($u['seo_output_tokens_per_call']) ? (int)$u['seo_output_tokens_per_call'] : (int)$cost['seo_output_tokens_per_call'];
                if ($cost['seo_output_tokens_per_call'] < 0) $cost['seo_output_tokens_per_call'] = 0;
                if ($cost['seo_output_tokens_per_call'] > 50000) $cost['seo_output_tokens_per_call'] = 50000;

                update_option(cbia_costes_settings_key(), $cost);
                $notice = 'saved';
                cbia_costes_log("Configuración guardada.");
            }

            if (!empty($u['cbia_form']) && $u['cbia_form'] === 'costes_actions' && check_admin_referer('cbia_costes_actions_nonce')) {
                $action = isset($u['cbia_action']) ? sanitize_text_field((string)$u['cbia_action']) : '';

                if ($action === 'clear_log') {
                    cbia_costes_log_clear();
                    cbia_costes_log("Log limpiado manualmente.");
                    $notice = 'log';
                }

                if ($action === 'calc_last') {
                    $n = isset($u['calc_last_n']) ? (int)$u['calc_last_n'] : 20;
                    $n = max(1, min(200, $n));

                    $only_cbia = !empty($u['calc_only_cbia']) ? true : false;
                    $use_est_if_missing = !empty($u['calc_estimate_if_missing']) ? true : false;

                    $sum = cbia_costes_calc_last_posts($n, $only_cbia, $use_est_if_missing, $cost, $cbia);
                    if ($sum) {
                        cbia_costes_log("Cálculo últimos {$n}: posts={$sum['posts']} real={$sum['real_posts']} est={$sum['est_posts']} real_calls={$sum['real_calls']} real_fails={$sum['real_fails']} tokens_in={$sum['tokens_in']} tokens_out={$sum['tokens_out']} total€=" . number_format((float)$sum['eur_total'], 4, ',', '.'));
                        $calibration_info = $sum;
                    } else {
                        cbia_costes_log("Cálculo últimos {$n}: sin resultados.");
                    }
                    $notice = 'calc';
                }

                if ($action === 'calc_last_real') {
                    $n = isset($u['calc_last_n']) ? (int)$u['calc_last_n'] : 20;
                    $n = max(1, min(200, $n));
                    $only_cbia = !empty($u['calc_only_cbia']) ? true : false;
                    $sum = cbia_costes_calc_last_posts($n, $only_cbia, false, $cost, $cbia);
                    if ($sum) {
                        cbia_costes_log("Cálculo SOLO REAL últimos {$n}: posts={$sum['posts']} real={$sum['real_posts']} real_calls={$sum['real_calls']} real_fails={$sum['real_fails']} tokens_in={$sum['tokens_in']} tokens_out={$sum['tokens_out']} total€=" . number_format((float)$sum['eur_total'], 4, ',', '.'));
                        $calibration_info = $sum;
                    } else {
                        cbia_costes_log("Cálculo SOLO REAL últimos {$n}: sin resultados.");
                    }
                    $notice = 'calc';
                }
            }

            return array($cost, $notice, $calibration_info);
        }
    }
}
