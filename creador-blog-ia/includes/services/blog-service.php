<?php
/**
 * Blog generation service (wrapper around legacy helpers).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Blog_Service')) {
    class CBIA_Blog_Service {
        public function get_settings() {
            if (function_exists('cbia_get_settings')) {
                return cbia_get_settings();
            }
            $settings = get_option('cbia_settings', array());
            return is_array($settings) ? $settings : array();
        }

        public function handle_post(): string {
            if (!is_admin() || !current_user_can('manage_options')) return '';
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') return '';

            $post_unslashed = wp_unslash($_POST);
            $saved_notice = '';

            $settings = $this->get_settings();

            if (!empty($post_unslashed['cbia_form']) && $post_unslashed['cbia_form'] === 'blog_save' && check_admin_referer('cbia_blog_save_nonce')) {
                $mode = (string)($post_unslashed['title_input_mode'] ?? 'manual');
                $settings['title_input_mode'] = in_array($mode, array('manual','csv'), true) ? $mode : 'manual';

                $settings['manual_titles'] = (string)($post_unslashed['manual_titles'] ?? '');
                $settings['csv_url'] = trim((string)($post_unslashed['csv_url'] ?? ''));

                $dt_local = trim((string)($post_unslashed['first_publication_datetime_local'] ?? ''));
                if ($dt_local !== '') {
                    $dt_local = str_replace('T',' ', $dt_local);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $dt_local)) $dt_local .= ':00';
                    $settings['first_publication_datetime'] = $dt_local;
                } else {
                    $settings['first_publication_datetime'] = '';
                }

                $settings['publication_interval'] = max(1, intval($post_unslashed['publication_interval'] ?? 5));
                $settings['enable_cron_fill'] = !empty($post_unslashed['enable_cron_fill']) ? 1 : 0;

                update_option('cbia_settings', $settings, false);

                if (function_exists('cbia_log_message')) {
                    cbia_log_message("[INFO] Blog: configuración guardada (títulos + automatización).");
                }
                $saved_notice = 'guardado';
            }

            if (!empty($post_unslashed['cbia_form']) && $post_unslashed['cbia_form'] === 'blog_actions' && check_admin_referer('cbia_blog_actions_nonce')) {
                $action = sanitize_text_field((string)($post_unslashed['cbia_action'] ?? ''));

                if ($action === 'test_config') {
                    if (function_exists('cbia_run_test_configuration')) cbia_run_test_configuration();
                    else if (function_exists('cbia_log_message')) cbia_log_message('[WARN] Falta cbia_run_test_configuration().');
                    $saved_notice = 'test';

                } elseif ($action === 'stop_generation') {
                    if (function_exists('cbia_set_stop_flag')) cbia_set_stop_flag(true);
                    if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Stop activado por usuario.");
                    $saved_notice = 'stop';

                } elseif ($action === 'fill_pending_imgs') {
                    if (function_exists('cbia_set_stop_flag')) cbia_set_stop_flag(false);
                    if (function_exists('cbia_run_fill_pending_images')) cbia_run_fill_pending_images(10);
                    else if (function_exists('cbia_log_message')) cbia_log_message('[WARN] Falta cbia_run_fill_pending_images().');
                    $saved_notice = 'pending';

                } elseif ($action === 'clear_checkpoint') {
                    if (function_exists('cbia_checkpoint_clear')) cbia_checkpoint_clear();
                    delete_option('_cbia_last_scheduled_at');
                    if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Checkpoint limpiado + _cbia_last_scheduled_at reseteado.");
                    $saved_notice = 'checkpoint';

                } elseif ($action === 'clear_log') {
                    if (function_exists('cbia_clear_log')) cbia_clear_log();
                    if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Log limpiado manualmente.");
                    $saved_notice = 'log';
                }
            }

            return $saved_notice;
        }

        public function schedule_generation_event($delay_seconds = 5, $force = false) {
            if (function_exists('cbia_schedule_generation_event')) {
                return cbia_schedule_generation_event($delay_seconds, $force);
            }
            return false;
        }

        public function run_generate_blogs($max_per_run = 1) {
            if (function_exists('cbia_run_generate_blogs')) {
                return cbia_run_generate_blogs($max_per_run);
            }
            return array('done' => true);
        }

        public function get_last_scheduled_at() {
            if (function_exists('cbia_get_last_scheduled_at')) {
                return cbia_get_last_scheduled_at();
            }
            return (string)get_option('_cbia_last_scheduled_at', '');
        }

        public function set_last_scheduled_at($datetime) {
            if (function_exists('cbia_set_last_scheduled_at')) {
                return cbia_set_last_scheduled_at($datetime);
            }
            if ($datetime) {
                update_option('_cbia_last_scheduled_at', (string)$datetime, false);
            }
            return true;
        }

        public function get_log() {
            if (function_exists('cbia_get_log')) {
                return cbia_get_log();
            }
            return array('log' => '', 'counter' => 0);
        }

        public function get_checkpoint_status() {
            if (!function_exists('cbia_checkpoint_get')) {
                return array('status' => 'inactivo', 'last' => '(sin registros)');
            }
            $cp = cbia_checkpoint_get();
            $status = (!empty($cp) && !empty($cp['running']))
                ? ('EN CURSO | idx ' . intval($cp['idx'] ?? 0) . ' de ' . count((array)($cp['queue'] ?? array())))
                : 'inactivo';
            $last = $this->get_last_scheduled_at();
            $last = $last ?: '(sin registros)';
            return array('status' => $status, 'last' => $last);
        }
    }
}
