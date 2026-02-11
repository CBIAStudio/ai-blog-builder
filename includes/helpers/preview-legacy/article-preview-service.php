<?php
/**
 * Article preview service (no post creation).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Article_Preview_Service')) {
    class CBIA_Article_Preview_Service {
        public function generate(array $payload) {
            return $this->generate_internal($payload, null);
        }

        public function generate_stream(array $payload, callable $emit) {
            return $this->generate_internal($payload, $emit);
        }

        private function generate_internal(array $payload, $emit = null) {
            $prev_ignore_stop = !empty($GLOBALS['cbia_ignore_stop']);
            $GLOBALS['cbia_ignore_stop'] = true;
            try {
            $title = trim((string)($payload['title'] ?? ''));
            if ($title === '') {
                if (function_exists('cbia_log')) {
                    cbia_log('Preview: titulo vacio.', 'WARN');
                }
                return new WP_Error('missing_title', 'Debes indicar un titulo para previsualizar.');
            }
            if (function_exists('cbia_log')) {
                cbia_log("Preview: generando '{$title}'.", 'INFO');
            }
            $this->emit($emit, 'cbia_status', array('message' => 'Preparando prompt...'));

            $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : array();
            $preview_mode = sanitize_key((string)($payload['preview_mode'] ?? 'fast'));
            if (!in_array($preview_mode, array('fast', 'full'), true)) {
                $preview_mode = 'fast';
            }
            $this->emit($emit, 'preview_start', array(
                'title' => $title,
                'preview_mode' => $preview_mode,
            ));

            $images_limit = isset($payload['images_limit']) ? (int)$payload['images_limit'] : (int)($settings['images_limit'] ?? 3);
            if ($images_limit < 1) $images_limit = 1;
            if ($images_limit > 4) $images_limit = 4;
            $user_id = get_current_user_id();
            $cleanup_warnings = $this->cleanup_previous_preview_media($user_id);

            if (isset($payload['post_language'])) {
                $settings['post_language'] = sanitize_text_field((string)$payload['post_language']);
            }
            if (isset($payload['blog_prompt_mode'])) {
                $mode = sanitize_key((string)$payload['blog_prompt_mode']);
                if (in_array($mode, array('recommended', 'legacy'), true)) {
                    $settings['blog_prompt_mode'] = $mode;
                }
            }
            if (array_key_exists('blog_prompt_editable', $payload)) {
                $editable = (string)$payload['blog_prompt_editable'];
                $settings['blog_prompt_editable'] = function_exists('cbia_prompt_sanitize_editable_block')
                    ? cbia_prompt_sanitize_editable_block($editable)
                    : sanitize_textarea_field($editable);
            }
            if (array_key_exists('legacy_full_prompt', $payload)) {
                $settings['legacy_full_prompt'] = sanitize_textarea_field((string)$payload['legacy_full_prompt']);
            }

            $prompt = $this->build_prompt($title, $settings);
            if (function_exists('cbia_log')) {
                cbia_log("Preview: prompt listo para '{$title}'.", 'INFO');
            }
            $this->emit($emit, 'cbia_status', array('message' => 'Generando contenido...'));
            // Nota: el proveedor no streamea token a token. Emitimos progreso por bloques HTML tras recibir el texto completo.
            list($ok, $text_html, $usage, $model_used, $err) = cbia_openai_responses_call($prompt, $title, 2);
            if (!$ok) {
                if (function_exists('cbia_log')) {
                    cbia_log("Preview: fallo generacion '{$title}': " . (string)($err ?: 'error desconocido'), 'ERROR');
                }
                return new WP_Error('preview_generation_failed', $err ?: 'No se pudo generar la previsualizacion.');
            }
            if (function_exists('cbia_log')) {
                cbia_log("Preview: texto OK '{$title}' modelo=" . (string)$model_used, 'INFO');
            }

            $text_html = cbia_strip_document_wrappers((string)$text_html);
            $text_html = cbia_strip_h1_to_h2($text_html);
            $text_html = cbia_fix_bracket_headings($text_html);
            $text_html = cbia_normalize_faq_heading($text_html);
            $this->emit($emit, 'word_count', array('count' => $this->word_count($text_html)));
            if (is_callable($emit)) {
                $this->emit_text_progress($emit, $text_html);
            }

            $this->emit($emit, 'cbia_status', array('message' => 'Renderizando imagenes del preview...'));
            $this->emit($emit, 'featured_image_status', array(
                'status' => 'pending',
                'message' => 'Generando imagen destacada...',
            ));
            if (function_exists('cbia_log')) {
                cbia_log("Preview: renderizando imagenes '{$title}'.", 'INFO');
            }
            $rendered = $this->render_markers($text_html, $title, $images_limit, $preview_mode, $emit);
            $final_html = cbia_cleanup_post_html($rendered['html']);
            $this->emit($emit, 'word_count', array('count' => $this->word_count($final_html)));
            $featured_image = $this->pick_featured_image((array)($rendered['images'] ?? array()));
            if (!empty($featured_image['url'])) {
                $this->emit($emit, 'featured_image_status', array(
                    'status' => 'done',
                    'url' => (string)$featured_image['url'],
                    'message' => 'Imagen destacada lista.',
                ));
                if (function_exists('cbia_log')) {
                    cbia_log("Preview: imagen destacada OK '{$title}'.", 'INFO');
                }
            } else {
                $this->emit($emit, 'featured_image_status', array(
                    'status' => $preview_mode === 'full' ? 'error' : 'placeholder',
                    'message' => $preview_mode === 'full'
                        ? 'No se pudo generar imagen destacada.'
                        : 'Preview rapido: imagen destacada en modo placeholder.',
                ));
                if (function_exists('cbia_log')) {
                    cbia_log("Preview: imagen destacada no disponible '{$title}'.", $preview_mode === 'full' ? 'ERROR' : 'INFO');
                }
            }
            if ($preview_mode === 'full') {
                $this->remember_preview_media($user_id, (array)($rendered['temp_attachment_ids'] ?? array()));
            }
            $this->emit($emit, 'cbia_content', array('html' => $final_html));
            $this->emit($emit, 'cbia_status', array('message' => 'Calculando metadatos...'));
            if (function_exists('cbia_log')) {
                cbia_log("Preview: calculando SEO '{$title}'.", 'INFO');
            }

            $excerpt = wp_trim_words(wp_strip_all_tags($final_html), 35, '...');
            $meta = function_exists('cbia_generate_meta_description')
                ? cbia_generate_meta_description($title, $final_html)
                : '';
            $focus = function_exists('cbia_generate_focus_keyphrase')
                ? cbia_generate_focus_keyphrase($title, $final_html)
                : '';
            $tags = function_exists('cbia_pick_tags_from_content_allowed')
                ? cbia_pick_tags_from_content_allowed($title, $final_html, 7)
                : array();
            $this->emit($emit, 'seo_payload', array(
                'excerpt' => $excerpt,
                'meta_description' => $meta,
                'focus_keyphrase' => $focus,
                'tags' => $tags,
            ));
            $preview_token = $this->store_preview_payload($user_id, array(
                'title' => $title,
                'html' => $final_html,
                'featured_attach_id' => (int)($featured_image['attach_id'] ?? 0),
            ));
            if (function_exists('cbia_log')) {
                cbia_log("Preview: listo '{$title}' token=" . (string)$preview_token, 'INFO');
            }

            return array(
                'title' => $title,
                'preview_html' => $final_html,
                'excerpt' => $excerpt,
                'meta_description' => $meta,
                'focus_keyphrase' => $focus,
                'tags' => $tags,
                'images' => $rendered['images'],
                'featured_attach_id' => (int)($featured_image['attach_id'] ?? 0),
                'warnings' => array_values(array_filter(array_merge($cleanup_warnings, (array)$rendered['warnings']))),
                'preview_mode' => $preview_mode,
                'preview_token' => $preview_token,
                'word_count' => $this->word_count($final_html),
                'text_model' => (string)$model_used,
                'usage' => is_array($usage) ? $usage : array(),
            );
            } finally {
                $GLOBALS['cbia_ignore_stop'] = $prev_ignore_stop;
            }
        }

        public function create_post_from_token($token, array $overrides = array()) {
            $token = trim((string)$token);
            if ($token === '') {
                return new WP_Error('missing_preview_token', 'Falta token de preview.');
            }
            $user_id = get_current_user_id();
            $payload = $this->get_preview_payload($user_id, $token);
            if (empty($payload) || !is_array($payload)) {
                return new WP_Error('invalid_preview_token', 'El preview ya no esta disponible. Genera uno nuevo.');
            }

            $title = trim((string)($payload['title'] ?? ''));
            $html = (string)($payload['html'] ?? '');
            $featured_attach_id = (int)($payload['featured_attach_id'] ?? 0);
            if (!empty($overrides)) {
                if (array_key_exists('title', $overrides)) {
                    $title = trim((string)$overrides['title']);
                }
                if (array_key_exists('html', $overrides)) {
                    $html = (string)$overrides['html'];
                }
            }
            if ($title === '' || $html === '') {
                return new WP_Error('invalid_preview_payload', 'Preview incompleto para crear post.');
            }
            if (function_exists('cbia_post_exists_by_title') && cbia_post_exists_by_title($title)) {
                return new WP_Error('duplicate_title', "El post '{$title}' ya existe.");
            }
            if (!function_exists('cbia_create_post_in_wp_engine')) {
                return new WP_Error('missing_create_engine', 'No esta disponible el motor de creacion de posts.');
            }

            $post_status = sanitize_key((string)($overrides['post_status'] ?? 'publish'));
            if (!in_array($post_status, array('publish', 'draft', 'future'), true)) {
                $post_status = 'publish';
            }
            $post_date_mysql = '';
            if ($post_status === 'future') {
                $raw_date = trim((string)($overrides['post_date_local'] ?? ''));
                if ($raw_date === '') {
                    return new WP_Error('missing_schedule_date', 'Indica fecha/hora para programar.');
                }
                $raw_date = str_replace('T', ' ', $raw_date);
                $ts = strtotime($raw_date);
                if (!$ts) {
                    return new WP_Error('invalid_schedule_date', 'Fecha/hora de programacion invalida.');
                }
                $post_date_mysql = gmdate('Y-m-d H:i:s', $ts + (int)get_option('gmt_offset') * HOUR_IN_SECONDS);
            }

            list($ok_post, $post_id, $post_err) = cbia_create_post_in_wp_engine($title, $html, $featured_attach_id, $post_date_mysql);
            if (!$ok_post || !$post_id) {
                return new WP_Error('create_post_failed', $post_err ?: 'No se pudo crear el post desde preview.');
            }
            if ($post_status === 'draft') {
                wp_update_post(array(
                    'ID' => (int)$post_id,
                    'post_status' => 'draft',
                ));
            }
            $this->delete_preview_payload($user_id, $token);
            return array(
                'post_id' => (int)$post_id,
                'edit_url' => get_edit_post_link((int)$post_id, ''),
                'message' => $post_status === 'future'
                    ? 'Post programado desde preview correctamente.'
                    : ($post_status === 'draft'
                        ? 'Borrador creado desde preview correctamente.'
                        : 'Post creado desde preview correctamente.'),
            );
        }

        private function preview_media_meta_key(): string {
            return '_cbia_preview_temp_media_ids';
        }

        private function cleanup_previous_preview_media($user_id): array {
            $warnings = array();
            $user_id = (int)$user_id;
            if ($user_id <= 0) return $warnings;

            $stored = get_user_meta($user_id, $this->preview_media_meta_key(), true);
            $ids = is_array($stored) ? $stored : array();
            if (empty($ids)) return $warnings;

            foreach ($ids as $raw_id) {
                $attach_id = (int)$raw_id;
                if ($attach_id <= 0) continue;
                if (get_post_type($attach_id) !== 'attachment') continue;
                $deleted = wp_delete_attachment($attach_id, true);
                if (!$deleted) {
                    $warnings[] = 'No se pudo limpiar adjunto temporal de preview ID ' . $attach_id . '.';
                }
            }
            delete_user_meta($user_id, $this->preview_media_meta_key());
            return $warnings;
        }

        private function remember_preview_media($user_id, array $ids): void {
            $user_id = (int)$user_id;
            if ($user_id <= 0) return;
            $clean = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if (empty($clean)) {
                delete_user_meta($user_id, $this->preview_media_meta_key());
                return;
            }
            update_user_meta($user_id, $this->preview_media_meta_key(), $clean);
        }

        private function preview_transient_key($user_id, $token): string {
            return 'cbia_preview_payload_' . (int)$user_id . '_' . sanitize_key((string)$token);
        }

        private function store_preview_payload($user_id, array $payload): string {
            $user_id = (int)$user_id;
            if ($user_id <= 0) return '';
            $token = wp_generate_password(20, false, false);
            $key = $this->preview_transient_key($user_id, $token);
            set_transient($key, $payload, 2 * HOUR_IN_SECONDS);
            return $token;
        }

        private function get_preview_payload($user_id, $token) {
            $user_id = (int)$user_id;
            if ($user_id <= 0) return array();
            $key = $this->preview_transient_key($user_id, $token);
            $data = get_transient($key);
            return is_array($data) ? $data : array();
        }

        private function delete_preview_payload($user_id, $token): void {
            $user_id = (int)$user_id;
            if ($user_id <= 0) return;
            $key = $this->preview_transient_key($user_id, $token);
            delete_transient($key);
        }

        private function build_prompt($title, array $settings) {
            $idioma_post = trim((string)($settings['post_language'] ?? 'espanol'));
            $mode = function_exists('cbia_prompt_get_mode')
                ? cbia_prompt_get_mode($settings)
                : sanitize_key((string)($settings['blog_prompt_mode'] ?? 'recommended'));

            if ($mode === 'legacy') {
                $template = function_exists('cbia_prompt_get_legacy_template')
                    ? cbia_prompt_get_legacy_template($settings)
                    : (string)($settings['legacy_full_prompt'] ?? ($settings['prompt_single_all'] ?? ''));
            } else {
                $editable = (string)($settings['blog_prompt_editable'] ?? '');
                if ($editable === '' && function_exists('cbia_prompt_recommended_editable_default')) {
                    $editable = cbia_prompt_recommended_editable_default();
                }
                $template = function_exists('cbia_prompt_build_recommended_template')
                    ? cbia_prompt_build_recommended_template($editable)
                    : (string)($settings['prompt_single_all'] ?? '');
            }

            $template = str_replace('{title}', (string)$title, (string)$template);
            $template = str_replace('{IDIOMA_POST}', (string)$idioma_post, (string)$template);
            return (string)$template;
        }

        private function render_markers($html, $title, $images_limit, $preview_mode, $emit = null) {
            $warnings = array();
            $images = array();
            $temp_attachment_ids = array();
            $internal_limit = max(0, (int)$images_limit - 1);

            if (function_exists('cbia_normalize_image_markers')) {
                $html = cbia_normalize_image_markers((string)$html);
            }

            $markers_all = cbia_extract_image_markers((string)$html);
            if ($internal_limit <= 0) {
                foreach ($markers_all as $mk) {
                    $html = cbia_remove_marker_from_html($html, $mk['full']);
                }
                return array('html' => $html, 'images' => $images, 'warnings' => $warnings, 'temp_attachment_ids' => $temp_attachment_ids);
            }

            if (count($markers_all) < $internal_limit && function_exists('cbia_force_insert_markers')) {
                $html = cbia_force_insert_markers($html, (string)$title, $internal_limit);
                $markers_all = cbia_extract_image_markers($html);
            }
            if (count($markers_all) > $internal_limit) {
                $extra = array_slice($markers_all, $internal_limit);
                foreach ($extra as $mk) {
                    $html = cbia_remove_marker_from_html($html, $mk['full']);
                }
                $markers_all = cbia_extract_image_markers($html);
            }

            $markers = array_slice($markers_all, 0, $internal_limit);
            foreach ($markers as $idx => $mk) {
                $i = $idx + 1;
                $desc = (string)($mk['short_desc'] ?? '');
                if ($desc === '') $desc = cbia_sanitize_image_short_desc((string)($mk['desc'] ?? ''));
                if ($desc === '') $desc = (string)$title;
                $section = cbia_detect_marker_section($html, (int)$mk['pos'], false);
                $this->emit($emit, 'cbia_image', array(
                    'idx' => $i,
                    'section' => $section,
                    'desc' => $desc,
                    'status' => 'processing',
                ));

                if ($preview_mode === 'full') {
                    $prompt = cbia_build_image_prompt_for_post(0, 'internal', $desc, $i);
                    $alt = cbia_sanitize_alt_from_desc($desc);
                    list($ok_img, $attach_id, $img_model, $img_err) = cbia_generate_image_openai_with_prompt($prompt, $section, $title, $alt, $i);
                    if ($ok_img && $attach_id) {
                        $url = wp_get_attachment_url((int)$attach_id);
                        $img_tag = cbia_build_content_img_tag($url, $alt, $section, $i);
                        $html = cbia_replace_first_occurrence($html, $mk['full'], $img_tag);
                        $images[] = array(
                            'idx' => $i,
                            'section' => $section,
                            'desc' => $desc,
                            'ok' => 1,
                            'model' => (string)$img_model,
                            'url' => (string)$url,
                            'attach_id' => (int)$attach_id,
                        );
                        $temp_attachment_ids[] = (int)$attach_id;
                        $this->emit($emit, 'cbia_content', array('html' => $html));
                        $this->emit($emit, 'cbia_image', array(
                            'idx' => $i,
                            'section' => $section,
                            'desc' => $desc,
                            'status' => 'done',
                            'ok' => 1,
                            'url' => (string)$url,
                        ));
                        continue;
                    }
                    $warnings[] = 'No se pudo generar imagen interna ' . $i . ': ' . (string)($img_err ?: 'error desconocido');
                    $images[] = array(
                        'idx' => $i,
                        'section' => $section,
                        'desc' => $desc,
                        'ok' => 0,
                        'model' => (string)$img_model,
                        'error' => (string)($img_err ?: ''),
                    );
                    $this->emit($emit, 'cbia_image', array(
                        'idx' => $i,
                        'section' => $section,
                        'desc' => $desc,
                        'status' => 'error',
                        'ok' => 0,
                        'error' => (string)($img_err ?: 'error desconocido'),
                    ));
                }

                $placeholder = '<div class="cbia-preview-image" data-section="' . esc_attr($section) . '"><strong>[Preview imagen interna ' . $i . ']</strong> ' . esc_html($desc) . '</div>';
                $html = cbia_replace_first_occurrence($html, $mk['full'], $placeholder);
                $images[] = array(
                    'idx' => $i,
                    'section' => $section,
                    'desc' => $desc,
                    'ok' => 1,
                    'mode' => 'placeholder',
                );
                $this->emit($emit, 'cbia_content', array('html' => $html));
                $this->emit($emit, 'cbia_image', array(
                    'idx' => $i,
                    'section' => $section,
                    'desc' => $desc,
                    'status' => 'done',
                    'ok' => 1,
                    'mode' => 'placeholder',
                ));
            }

            return array('html' => $html, 'images' => $images, 'warnings' => $warnings, 'temp_attachment_ids' => $temp_attachment_ids);
        }

        private function emit($emit, $event, array $payload): void {
            if (!is_callable($emit)) return;
            call_user_func($emit, (string)$event, $payload);
        }

        private function emit_text_progress($emit, string $html): void {
            if (!is_callable($emit)) return;
            $parts = preg_split('/(<\/p>|<\/h2>|<\/h3>|<\/ul>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (!is_array($parts) || empty($parts)) return;
            $buffer = '';
            $idx = 0;
            foreach ($parts as $part) {
                $buffer .= $part;
                if (trim($buffer) === '') continue;
                $this->emit($emit, 'text_progress', array(
                    'html' => $buffer,
                    'word_count' => $this->word_count($buffer),
                ));
                if ($idx % 2 === 0) {
                    $this->emit($emit, 'word_count', array('count' => $this->word_count($buffer)));
                }
                $idx++;
                usleep(60000);
            }
        }

        private function word_count(string $html): int {
            $plain = trim(wp_strip_all_tags($html));
            if ($plain === '') return 0;
            if (!preg_match_all('/[\p{L}\p{N}]+/u', $plain, $matches)) {
                return 0;
            }
            return count($matches[0]);
        }

        private function pick_featured_image(array $images): array {
            foreach ($images as $row) {
                if (!is_array($row)) continue;
                if (!empty($row['url'])) return $row;
            }
            return array();
        }
    }
}

