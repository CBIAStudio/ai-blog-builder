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
            if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') return '';

            $post_unslashed = wp_unslash($_POST);
            $saved_notice = '';

            $settings = $this->get_settings();

            if (!empty($post_unslashed['cbia_form']) && $post_unslashed['cbia_form'] === 'blog_save' && check_admin_referer('cbia_blog_save_nonce')) {
                $prompt_warnings = array();
                if (array_key_exists('title_input_mode', $post_unslashed)) {
                    $mode = (string)($post_unslashed['title_input_mode'] ?? 'manual');
                    $settings['title_input_mode'] = in_array($mode, array('manual','csv'), true) ? $mode : 'manual';
                }

                if (array_key_exists('manual_titles', $post_unslashed)) {
                    $settings['manual_titles'] = (string)($post_unslashed['manual_titles'] ?? '');
                }
                if (array_key_exists('csv_url', $post_unslashed)) {
                    $settings['csv_url'] = trim((string)($post_unslashed['csv_url'] ?? ''));
                }

                if (array_key_exists('first_publication_datetime_local', $post_unslashed)) {
                    $dt_local = trim((string)($post_unslashed['first_publication_datetime_local'] ?? ''));
                    if ($dt_local !== '') {
                        $dt_local = str_replace('T',' ', $dt_local);
                        if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $dt_local)) $dt_local .= ':00';
                        $settings['first_publication_datetime'] = $dt_local;
                    } else {
                        $settings['first_publication_datetime'] = '';
                    }
                }

                if (array_key_exists('publication_interval', $post_unslashed)) {
                    $settings['publication_interval'] = max(1, intval($post_unslashed['publication_interval'] ?? 5));
                }
                if (array_key_exists('enable_cron_fill', $post_unslashed)) {
                    $settings['enable_cron_fill'] = !empty($post_unslashed['enable_cron_fill']) ? 1 : 0;
                } elseif (array_key_exists('publication_interval', $post_unslashed) || array_key_exists('first_publication_datetime_local', $post_unslashed)) {
                    // Solo cuando se envia el formulario de programacion, el checkbox ausente significa desactivado.
                    $settings['enable_cron_fill'] = 0;
                }

                // Publicacion y clasificacion (migrado desde Configuracion)
                if (array_key_exists('default_author_id', $post_unslashed)) {
                    $settings['default_author_id'] = absint($post_unslashed['default_author_id'] ?? 0);
                }
                if (array_key_exists('post_language', $post_unslashed)) {
                    $settings['post_language'] = sanitize_text_field((string)($post_unslashed['post_language'] ?? ($settings['post_language'] ?? 'Espanol')));
                }
                if (array_key_exists('default_category', $post_unslashed)) {
                    $settings['default_category'] = sanitize_text_field((string)($post_unslashed['default_category'] ?? ($settings['default_category'] ?? 'Noticias')));
                }
                if (array_key_exists('keywords_to_categories', $post_unslashed)) {
                    $settings['keywords_to_categories'] = sanitize_textarea_field((string)($post_unslashed['keywords_to_categories'] ?? ($settings['keywords_to_categories'] ?? '')));
                }
                if (array_key_exists('default_tags', $post_unslashed)) {
                    $settings['default_tags'] = sanitize_text_field((string)($post_unslashed['default_tags'] ?? ($settings['default_tags'] ?? '')));
                }

                // CAMBIO: Prompt del blog (recommended/legacy) con edicion parcial.
                $prompt_post_mode = sanitize_key((string)($post_unslashed['blog_prompt_mode'] ?? ($settings['blog_prompt_mode'] ?? 'recommended')));
                if (!in_array($prompt_post_mode, array('recommended', 'legacy'), true)) $prompt_post_mode = 'recommended';
                if (array_key_exists('blog_prompt_mode', $post_unslashed)) {
                    $settings['blog_prompt_mode'] = $prompt_post_mode;
                } else {
                    $prompt_post_mode = sanitize_key((string)($settings['blog_prompt_mode'] ?? 'recommended'));
                }

                // Compatibilidad: preservar prompt historico como legacy_full_prompt.
                if (empty($settings['legacy_full_prompt'])) {
                    $legacy_seed = trim((string)($settings['prompt_single_all'] ?? ''));
                    if ($legacy_seed !== '') {
                        $settings['legacy_full_prompt'] = $legacy_seed;
                    }
                }

                if (array_key_exists('blog_prompt_editable', $post_unslashed) || !empty($post_unslashed['blog_prompt_restore'])) {
                    $editable_raw = (string)($post_unslashed['blog_prompt_editable'] ?? ($settings['blog_prompt_editable'] ?? ''));
                    if (!empty($post_unslashed['blog_prompt_restore'])) {
                        $editable_raw = function_exists('cbia_prompt_recommended_editable_default')
                            ? cbia_prompt_recommended_editable_default()
                            : $editable_raw;
                    }
                    if (function_exists('cbia_prompt_sanitize_editable_block')) {
                        $editable_raw = cbia_prompt_sanitize_editable_block($editable_raw);
                    } else {
                        $editable_raw = sanitize_textarea_field($editable_raw);
                    }
                    $settings['blog_prompt_editable'] = $editable_raw;
                }

                $legacy_input = (string)($post_unslashed['legacy_full_prompt'] ?? '');
                if ($legacy_input !== '') {
                    $settings['legacy_full_prompt'] = sanitize_textarea_field($legacy_input);
                } elseif (empty($settings['legacy_full_prompt'])) {
                    $settings['legacy_full_prompt'] = '';
                }

                // CAMBIO: mantener compatibilidad con prompt_single_all.
                if ($prompt_post_mode === 'legacy') {
                    $settings['prompt_single_all'] = (string)($settings['legacy_full_prompt'] ?? '');
                    $legacy_effective = (string)($settings['legacy_full_prompt'] ?? '');
                    if (strpos($legacy_effective, '{title}') === false) {
                        $prompt_warnings[] = 'Prompt avanzado: falta la variable {title}.';
                    }
                    if (stripos($legacy_effective, '[IMAGEN:') === false) {
                        $prompt_warnings[] = 'Prompt avanzado: no contiene marcadores [IMAGEN: ...].';
                    }
                } else {
                    if (function_exists('cbia_prompt_build_recommended_template')) {
                        $settings['prompt_single_all'] = cbia_prompt_build_recommended_template($settings['blog_prompt_editable']);
                    }
                }

                update_option('cbia_settings', $settings, false);

                if (function_exists('cbia_log_message')) {
                    cbia_log_message("[INFO] Blog: configuraciÃ³n guardada (tÃ­tulos + automatizaciÃ³n).");
                }
                if (!empty($prompt_warnings)) {
                    set_transient('cbia_blog_prompt_warnings', $prompt_warnings, 120);
                    $saved_notice = 'guardado_warn';
                } else {
                    delete_transient('cbia_blog_prompt_warnings');
                    $saved_notice = 'guardado';
                }
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

