<?php
/**
 * CBIA - Old Posts (Actualización avanzada de posts antiguos)
 * v3 (UI limpia + acciones sin duplicidad + Yoast por campos)
 *
 * UX:
 * - Se guardan "Acciones por defecto" (presets).
 * - En "Ejecución", por defecto usa esos presets SIN duplicar checkboxes.
 * - Botón/checkbox "Personalizar esta ejecución" muestra overrides puntuales.
 *
 * Acciones soportadas:
 * - Nota "Actualizado el..." (sin tocar post_date)
 * - Yoast: metadesc / focuskw / title (por separado) + forzar
 * - Yoast reindex best-effort (si existe cbia_yoast_try_reindex_post)
 * - Título con IA (SEO/CTR) + forzar
 * - Contenido con IA + forzar
 * - Imágenes: reset pendientes + forzar + opcional quitar destacada
 * - Categorías (mapping plugin) + forzar
 * - Etiquetas (lista permitida plugin) + forzar
 *
 * Filtrado:
 * - Más antiguos que X días (post_date_gmt)
 * - Rango de fechas (post_date_gmt)
 *
 * Archivo: includes/engine/oldposts.php
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
   ===================== LOG INDEPENDIENTE ==================
   ========================================================= */
if (!function_exists('cbia_oldposts_log_key')) {
    function cbia_oldposts_log_key() { return 'cbia_oldposts_log'; }
}
if (!function_exists('cbia_oldposts_fix_mojibake')) {
    /**
     * Corrige mojibake común en mensajes de log sin tocar la lógica.
     */
    function cbia_oldposts_fix_mojibake($text) {
        $text = (string)$text;
        if ($text === '') return $text;

        $fixed = $text;
        if (function_exists('cbia_fix_mojibake')) {
            $fixed = cbia_fix_mojibake($fixed);
        }

        // Intento adicional: UTF-8 leído como Latin-1/Windows-1252.
        if (function_exists('mb_convert_encoding') && preg_match('/[\x{00C3}\x{00C2}\x{00E2}]/u', $fixed)) {
            $try = @mb_convert_encoding($fixed, 'UTF-8', 'Windows-1252');
            if (is_string($try) && $try !== '') {
                $fixed = $try;
            }
        }

        return $fixed;
    }
}
if (!function_exists('cbia_oldposts_log_message')) {
    function cbia_oldposts_log_message($message) {
        $message = cbia_oldposts_fix_mojibake($message);
        // Evita duplicados consecutivos (muy útil con mojibake/lineas repetidas).
        static $last_message = null;
        if ($last_message !== null && (string)$last_message === (string)$message) {
            return;
        }
        $last_message = (string)$message;
        if (function_exists('cbia_log')) {
            cbia_log('[OLDPOSTS] ' . (string)$message, 'INFO');
            return;
        }
        $log = get_option(cbia_oldposts_log_key(), '');
        $ts  = current_time('mysql');
        $log .= "[{$ts}] {$message}\n";
        if (strlen($log) > 250000) $log = substr($log, -250000);
        update_option(cbia_oldposts_log_key(), $log);
    }
}
if (!function_exists('cbia_oldposts_clear_log')) {
    function cbia_oldposts_clear_log() {
        if (function_exists('cbia_clear_log')) {
            cbia_clear_log();
            return;
        }
        delete_option(cbia_oldposts_log_key());
    }
}
if (!function_exists('cbia_oldposts_get_log')) {
    function cbia_oldposts_get_log() {
        if (function_exists('cbia_get_log')) {
            $payload = cbia_get_log();
            $text = is_array($payload) ? (string)($payload['log'] ?? '') : (string)$payload;
            return function_exists('cbia_fix_mojibake') ? cbia_fix_mojibake($text) : $text;
        }
        $text = (string)get_option(cbia_oldposts_log_key(), '');
        return function_exists('cbia_fix_mojibake') ? cbia_fix_mojibake($text) : $text;
    }
}

/* =========================================================
   =================== STOP FLAG (fallback) =================
   ========================================================= */
if (!function_exists('cbia_stop_flag_key')) {
    function cbia_stop_flag_key() {
        if (defined('CBIA_OPTION_STOP')) return CBIA_OPTION_STOP;
        return 'cbia_stop_generation';
    }
}
if (!function_exists('cbia_set_stop_flag')) {
    function cbia_set_stop_flag($value = true) {
        update_option(cbia_stop_flag_key(), $value ? 1 : 0, false);
        wp_cache_delete(cbia_stop_flag_key(), 'options');
    }
}
if (!function_exists('cbia_check_stop_flag')) {
    function cbia_check_stop_flag() { return get_option(cbia_stop_flag_key(), 0) == 1; }
}

/* =========================================================
   ================ SETTINGS (independientes) ===============
   ========================================================= */
if (!function_exists('cbia_oldposts_settings_key')) {
    function cbia_oldposts_settings_key() { return 'cbia_oldposts_settings'; }
}
if (!function_exists('cbia_oldposts_get_settings')) {
    function cbia_oldposts_get_settings() {
        $s = get_option(cbia_oldposts_settings_key(), array());
        return is_array($s) ? $s : array();
    }
}
if (!function_exists('cbia_oldposts_register_settings')) {
    function cbia_oldposts_register_settings() {
        register_setting('cbia_oldposts_settings_group', cbia_oldposts_settings_key());
    }
    add_action('admin_init', 'cbia_oldposts_register_settings');
}

/* =========================================================
   ==================== HELPERS VARIOS ======================
   ========================================================= */
if (!function_exists('cbia_oldposts_sanitize_ymd')) {
    function cbia_oldposts_sanitize_ymd($ymd) {
        $ymd = preg_replace('/[^0-9\-]/', '', (string)$ymd);
        if (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $ymd)) return $ymd;
        return '';
    }
}
if (!function_exists('cbia_oldposts_parse_ids_csv')) {
    /**
     * Convierte "1,2, 3\n4" en [1,2,3,4]
     */
    function cbia_oldposts_parse_ids_csv($raw) {
        $raw = (string)$raw;
        if ($raw === '') return array();
        $raw = str_replace(array("\r", "\n", "\t", ";"), ',', $raw);
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $ids = array();
        foreach ($parts as $p) {
            $id = (int)$p;
            if ($id > 0) $ids[$id] = $id;
        }
        return array_values($ids);
    }
}
if (!function_exists('cbia_oldposts_remove_h1')) {
    function cbia_oldposts_remove_h1($html) {
        $html = (string)$html;
        $html2 = preg_replace('/<h1\b[^>]*>.*?<\/h1>\s*/is', '', $html, 1);
        return (string)$html2;
    }
}
if (!function_exists('cbia_oldposts_has_any_image_marker')) {
    function cbia_oldposts_has_any_image_marker($html) {
        return (bool)preg_match('/\[(IMAGEN|IMAGEN_PENDIENTE)\s*:\s*[^\]]+\]/i', (string)$html);
    }
}
if (!function_exists('cbia_oldposts_extract_image_markers_any')) {
    function cbia_oldposts_extract_image_markers_any($html) {
        $html = (string)$html;
        $markers = array();
        if (preg_match_all('/\[(?:IMAGEN|IMAGEN_PENDIENTE)\s*:\s*([^\]]+)\]/i', $html, $m)) {
            foreach ((array)$m[1] as $desc) {
                $desc = trim((string)$desc);
                if ($desc === '') continue;
                $markers[] = $desc;
            }
        }
        $markers = array_values(array_unique($markers));
        return $markers;
    }
}
if (!function_exists('cbia_oldposts_mark_all_as_pending')) {
    function cbia_oldposts_mark_all_as_pending($html) {
        $html = (string)$html;
        $html = preg_replace('/\[IMAGEN\s*:\s*([^\]]+)\]/i', '[IMAGEN_PENDIENTE: $1]', $html);
        return $html;
    }
}
if (!function_exists('cbia_oldposts_set_pending_images_meta')) {
    function cbia_oldposts_set_pending_images_meta($post_id, $pending_list) {
        $post_id = (int)$post_id;
        if ($post_id <= 0) return;

        $pending_list = is_array($pending_list) ? $pending_list : array();
        $pending_list = array_values(array_unique(array_filter(array_map('trim', $pending_list))));

        $pending_count = count($pending_list);
        update_post_meta($post_id, '_cbia_pending_images', (string)$pending_count);
        update_post_meta($post_id, '_cbia_pending_images_list', wp_json_encode($pending_list));
        update_post_meta($post_id, '_cbia_oldposts_images_reset_at', current_time('mysql'));
    }
}

/* =========================================================
   ==================== HELPERS SEO/YOAST ===================
   ========================================================= */
if (!function_exists('cbia_oldposts_first_paragraph_text')) {
    function cbia_oldposts_first_paragraph_text($html) {
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', (string)$html, $m)) return wp_strip_all_tags($m[1]);
        return wp_strip_all_tags((string)$html);
    }
}
if (!function_exists('cbia_oldposts_generate_meta_description_fallback')) {
    function cbia_oldposts_generate_meta_description_fallback($title, $content) {
        $base = cbia_oldposts_first_paragraph_text((string)$content);
        $t = trim(wp_strip_all_tags((string)$title));
        if ($t !== '') {
            $pattern = '/^' . preg_quote($t, '/') . '\s*[:\-–—]?\s*/iu';
            $base = preg_replace($pattern, '', $base);
        }
        $desc = trim(mb_substr((string)$base, 0, 155));
        if ($desc !== '' && !preg_match('/[.!?]$/u', $desc)) $desc .= '...';
        return $desc;
    }
}
if (!function_exists('cbia_oldposts_generate_focus_keyphrase_fallback')) {
    function cbia_oldposts_generate_focus_keyphrase_fallback($title) {
        $words = preg_split('/\s+/', wp_strip_all_tags((string)$title));
        return trim(implode(' ', array_slice((array)$words, 0, 4)));
    }
}
if (!function_exists('cbia_oldposts_generate_yoast_title_fallback')) {
    function cbia_oldposts_generate_yoast_title_fallback($title) {
        $t = trim(wp_strip_all_tags((string)$title));
        // Yoast title suele aceptar variables, pero aquí dejamos un título simple.
        return $t;
    }
}

/**
 * Recalcular metas Yoast por campos:
 * - metadesc
 * - focuskw
 * - title (SEO title Yoast)
 */
if (!function_exists('cbia_oldposts_recalc_yoast_fields')) {
    function cbia_oldposts_recalc_yoast_fields($post_id, $do_metadesc=true, $do_focuskw=true, $do_title=true, $force=false) {
        $post = get_post($post_id);
        if (!$post) return false;

        $title   = get_the_title($post_id);
        $content = (string)$post->post_content;

        $did = false;

        if ($do_metadesc) {
            $metadesc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            if ($force || $metadesc === '' || $metadesc === null) {
                if (function_exists('cbia_generate_meta_description')) {
                    $md = cbia_generate_meta_description($title, $content);
                } else {
                    $md = cbia_oldposts_generate_meta_description_fallback($title, $content);
                }
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $md);
                $did = true;
            }
        }

        if ($do_focuskw) {
            $focuskw  = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
            if ($force || $focuskw === '' || $focuskw === null) {
                if (function_exists('cbia_generate_focus_keyphrase')) {
                    $fk = cbia_generate_focus_keyphrase($title, $content);
                } else {
                    $fk = cbia_oldposts_generate_focus_keyphrase_fallback($title);
                }
                update_post_meta($post_id, '_yoast_wpseo_focuskw', $fk);
                $did = true;
            }
        }

        if ($do_title) {
            $yt = get_post_meta($post_id, '_yoast_wpseo_title', true);
            if ($force || $yt === '' || $yt === null) {
                // Si tienes una función propia, úsala; si no, fallback
                if (function_exists('cbia_generate_yoast_title')) {
                    $new_yt = cbia_generate_yoast_title($title, $content);
                } else {
                    $new_yt = cbia_oldposts_generate_yoast_title_fallback($title);
                }
                update_post_meta($post_id, '_yoast_wpseo_title', $new_yt);
                $did = true;
            }
        }

        if ($did) {
            update_post_meta($post_id, '_cbia_oldposts_yoast_fields_refreshed', current_time('mysql'));
            clean_post_cache($post_id);

            // Best-effort hooks Yoast
            do_action('wpseo_save_postdata', $post_id);
            do_action('wpseo_save_post', $post_id);
        }

        return $did;
    }
}

/* =========================================================
   =================== NOTA "ACTUALIZADO" ===================
   ========================================================= */
if (!function_exists('cbia_oldposts_build_note_html')) {
    function cbia_oldposts_build_note_html($date_ymd) {
        $date_ymd = preg_replace('/[^0-9\-]/', '', (string)$date_ymd);
        if ($date_ymd === '') $date_ymd = current_time('Y-m-d');
        return '<p><em>Actualizado el ' . esc_html($date_ymd) . '</em></p>' . "\n";
    }
}
if (!function_exists('cbia_oldposts_has_note')) {
    function cbia_oldposts_has_note($content) {
        return (bool)preg_match('/<p>\s*<em>\s*actualizado\s+el\s+[0-9]{4}\-[0-9]{2}\-[0-9]{2}\s*<\/em>\s*<\/p>/i', (string)$content);
    }
}
if (!function_exists('cbia_oldposts_add_updated_note')) {
    function cbia_oldposts_add_updated_note($post_id, $date_ymd, $force=false) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') return false;

        $date_ymd = cbia_oldposts_sanitize_ymd($date_ymd);
        if ($date_ymd === '') $date_ymd = current_time('Y-m-d');

        $already = get_post_meta($post_id, '_cbia_updated_note_date', true);
        if (!$force && $already !== '') return 'skipped';

        $content = (string)$post->post_content;

        if (!$force && cbia_oldposts_has_note($content)) {
            update_post_meta($post_id, '_cbia_updated_note_date', $date_ymd);
            return 'skipped';
        }

        if ($force && cbia_oldposts_has_note($content)) {
            $new_note = cbia_oldposts_build_note_html($date_ymd);
            $new_content = preg_replace(
                '/<p>\s*<em>\s*actualizado\s+el\s+[0-9]{4}\-[0-9]{2}\-[0-9]{2}\s*<\/em>\s*<\/p>\s*/i',
                $new_note,
                $content,
                1
            );
            wp_update_post(array('ID'=>$post_id, 'post_content'=>$new_content));
            update_post_meta($post_id, '_cbia_updated_note_date', $date_ymd);
            update_post_meta($post_id, '_cbia_oldposts_note_refreshed', current_time('mysql'));
            cbia_oldposts_log_message("Nota (forzar) actualizada en post {$post_id} ({$date_ymd}).");
            return true;
        }

        $note = cbia_oldposts_build_note_html($date_ymd);
        $new  = $note . $content;

        wp_update_post(array(
            'ID'           => $post_id,
            'post_content' => $new,
        ));

        update_post_meta($post_id, '_cbia_updated_note_date', $date_ymd);
        update_post_meta($post_id, '_cbia_oldposts_note_added', current_time('mysql'));
        cbia_oldposts_log_message("Nota añadida en post {$post_id} ({$date_ymd}).");

        return true;
    }
}

/* =========================================================
   ================= CATEGORÍAS / ETIQUETAS =================
   ========================================================= */
if (!function_exists('cbia_oldposts_parse_keywords_to_categories')) {
    function cbia_oldposts_parse_keywords_to_categories($raw) {
        if (function_exists('cbia_parse_keywords_to_categories')) return cbia_parse_keywords_to_categories($raw);

        $raw = (string)$raw;
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $map = array();

        foreach ((array)$lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || strpos($line, ':') === false) continue;
            list($cat, $rest) = array_map('trim', explode(':', $line, 2));
            if ($cat === '' || $rest === '') continue;

            $kws = array();
            foreach (explode(',', $rest) as $kw) {
                $kw = trim((string)$kw);
                if ($kw !== '') $kws[] = mb_strtolower($kw);
            }
            if (!empty($kws)) $map[$cat] = $kws;
        }
        return $map;
    }
}
if (!function_exists('cbia_oldposts_ensure_category_id')) {
    function cbia_oldposts_ensure_category_id($name) {
        if (function_exists('cbia_ensure_category_id')) return cbia_ensure_category_id($name);

        $name = trim((string)$name);
        if ($name === '') return 0;

        $term = get_term_by('name', $name, 'category');
        if ($term && !is_wp_error($term)) return (int)$term->term_id;

        $new_id = wp_create_category($name);
        return is_wp_error($new_id) ? 0 : (int)$new_id;
    }
}
if (!function_exists('cbia_oldposts_assign_categories_only')) {
    function cbia_oldposts_assign_categories_only($post_id, $title, $content_html, $force=false) {
        if (!function_exists('cbia_get_settings')) {
            cbia_oldposts_log_message("[WARN] No existe cbia_get_settings(). No se pueden aplicar categorías dinámicas.");
            return false;
        }

        if (!$force) {
            $done = get_post_meta($post_id, '_cbia_oldposts_categories_done', true);
            if ($done !== '') return 'skipped';
        }

        $s = cbia_get_settings();
        $default_cat = trim((string)($s['default_category'] ?? 'Noticias'));
        if ($default_cat === '') $default_cat = 'Noticias';

        $map_raw = (string)($s['keywords_to_categories'] ?? '');
        $map = cbia_oldposts_parse_keywords_to_categories($map_raw);

        $hay = mb_strtolower(wp_strip_all_tags((string)$title . ' ' . (string)$content_html));

        $picked = array();
        foreach ($map as $cat => $kws) {
            foreach ((array)$kws as $kw) {
                if ($kw === '') continue;
                if (mb_strpos($hay, (string)$kw) !== false) {
                    $picked[] = (string)$cat;
                    break;
                }
            }
            if (count($picked) >= 3) break;
        }

        if (empty($picked)) $picked = array($default_cat);

        $cat_ids = array();
        foreach ($picked as $cname) {
            $cid = cbia_oldposts_ensure_category_id($cname);
            if ($cid > 0) $cat_ids[] = $cid;
        }
        if (empty($cat_ids)) {
            $cid = cbia_oldposts_ensure_category_id($default_cat);
            if ($cid > 0) $cat_ids[] = $cid;
        }

        if (!empty($cat_ids)) {
            wp_set_post_categories($post_id, $cat_ids, false);
            update_post_meta($post_id, '_cbia_oldposts_categories_done', current_time('mysql'));
            return true;
        }

        return false;
    }
}
if (!function_exists('cbia_oldposts_assign_tags_only')) {
    function cbia_oldposts_assign_tags_only($post_id, $title, $content_html, $force=false) {
        if (!function_exists('cbia_get_settings')) {
            cbia_oldposts_log_message("[WARN] No existe cbia_get_settings(). No se pueden aplicar etiquetas dinámicas.");
            return false;
        }

        if (!$force) {
            $done = get_post_meta($post_id, '_cbia_oldposts_tags_done', true);
            if ($done !== '') return 'skipped';
        }

        $s = cbia_get_settings();
        $allowed = trim((string)($s['default_tags'] ?? ''));
        $allowed_tags = array();
        if ($allowed !== '') {
            foreach (explode(',', $allowed) as $t) {
                $t = trim((string)$t);
                if ($t !== '') $allowed_tags[] = $t;
            }
        }

        $hay = mb_strtolower(wp_strip_all_tags((string)$title . ' ' . (string)$content_html));

        $chosen_tags = array();
        if (!empty($allowed_tags)) {
            foreach ($allowed_tags as $tag) {
                $needle = mb_strtolower($tag);
                if ($needle !== '' && mb_strpos($hay, $needle) !== false) {
                    $chosen_tags[] = $tag;
                }
                if (count($chosen_tags) >= 7) break;
            }
            if (empty($chosen_tags)) {
                $chosen_tags = array_slice($allowed_tags, 0, 5);
            }
        }

        if (!empty($chosen_tags)) {
            wp_set_post_tags($post_id, $chosen_tags, false);
            update_post_meta($post_id, '_cbia_oldposts_tags_done', current_time('mysql'));
            return true;
        }

        return false;
    }
}

/* =========================================================
   ======================= IA: TíTULO =======================
   ========================================================= */
if (!function_exists('cbia_oldposts_ai_optimize_title')) {
    function cbia_oldposts_ai_optimize_title($post_id, $force=false) {
        $post = get_post($post_id);
        if (!$post) return false;

        if (!$force) {
            $done = get_post_meta($post_id, '_cbia_oldposts_title_done', true);
            if ($done !== '') return 'skipped';
        }

        if (!function_exists('cbia_openai_responses_call') || !function_exists('cbia_pick_model')) {
            cbia_oldposts_log_message("[ERROR] Falta motor IA (cbia_openai_responses_call / cbia_pick_model). No puedo optimizar título.");
            return false;
        }

        $old_title = get_the_title($post_id);
        $content   = (string)$post->post_content;

        $prompt = "Optimiza este título para SEO y CTR manteniendo la misma intención de búsqueda y el tema.\n".
                  "Devuelve SOLO el título final, sin comillas, sin listas, sin explicaciones.\n\n".
                  "Título actual: {$old_title}\n\n".
                  "Contexto (extracto): ".mb_substr(wp_strip_all_tags($content), 0, 600);

        $model = cbia_pick_model();
        list($ok, $text, $usage, $model_used, $err) = cbia_openai_responses_call($prompt, 'OLDPOSTS_TITLE', 1);

        if (!$ok) {
            cbia_oldposts_log_message("[ERROR] IA título fallo post {$post_id}: {$err}");
            return false;
        }

        $new_title = trim(wp_strip_all_tags((string)$text));
        $new_title = preg_replace('/\s+/', ' ', $new_title);

        if ($new_title === '' || mb_strlen($new_title) < 12) {
            cbia_oldposts_log_message("[WARN] IA devolvió título inválido en post {$post_id}. Se omite.");
            return false;
        }

        if (mb_strtolower($new_title) === mb_strtolower($old_title)) {
            update_post_meta($post_id, '_cbia_oldposts_title_done', current_time('mysql'));
            cbia_oldposts_log_message("[INFO] IA título: no cambió (igual) en post {$post_id}.");
            return 'skipped';
        }

        wp_update_post(array(
            'ID'         => $post_id,
            'post_title' => $new_title,
        ));

        update_post_meta($post_id, '_cbia_oldposts_title_done', current_time('mysql'));
        update_post_meta($post_id, '_cbia_oldposts_title_old', $old_title);
        update_post_meta($post_id, '_cbia_oldposts_title_new', $new_title);

        if (function_exists('cbia_store_usage_meta')) {
            cbia_store_usage_meta($post_id, $usage, $model_used);
        }

        cbia_oldposts_log_message("[OK] Título actualizado post {$post_id}: '{$old_title}' => '{$new_title}'");
        return true;
    }
}

/* =========================================================
   ======================= IA: CONTENIDO ====================
   ========================================================= */
if (!function_exists('cbia_oldposts_ai_regenerate_content')) {
    function cbia_oldposts_ai_regenerate_content($post_id, $images_limit=3, $force=false, $skip_images=false) {
        $post = get_post($post_id);
        if (!$post) return false;

        if (!$force) {
            $done = get_post_meta($post_id, '_cbia_oldposts_content_done', true);
            if ($done !== '') return 'skipped';
        }

        if (!function_exists('cbia_openai_responses_call') || !function_exists('cbia_build_prompt_for_title') || !function_exists('cbia_pick_model')) {
            cbia_oldposts_log_message("[ERROR] Falta motor IA (cbia_openai_responses_call/cbia_build_prompt_for_title/cbia_pick_model). No puedo regenerar contenido.");
            return false;
        }

        $title = get_the_title($post_id);
        $s = function_exists('cbia_get_settings') ? cbia_get_settings() : (array)get_option('cbia_settings', array());
        $images_limit = max(0, min(10, (int)$images_limit));
        if ($images_limit <= 0) $images_limit = max(1, min(4, (int)($s['images_limit'] ?? 3)));

        if (cbia_check_stop_flag()) {
            cbia_oldposts_log_message("[INFO] Stop activo: no se regenera contenido en post {$post_id}.");
            return false;
        }

        $prompt = cbia_build_prompt_for_title($title);

        $model = cbia_pick_model();
        cbia_oldposts_log_message("[INFO] IA contenido: llamando OpenAI post {$post_id} model={$model} images_limit={$images_limit}…");

        list($ok, $text, $usage, $model_used, $err) = cbia_openai_responses_call($prompt, 'OLDPOSTS_CONTENT', 1);

        if (!$ok) {
            cbia_oldposts_log_message("[ERROR] IA contenido fallo post {$post_id}: {$err}");
            return false;
        }

        $html = (string)$text;
        $html = cbia_oldposts_remove_h1($html);

        $pending_list = array();
        if (!empty($skip_images)) {
            // Modo "solo contenido": elimina cualquier marcador de imagen.
            $final_html = preg_replace('/\[IMAGEN(?:_PENDIENTE)?\s*:\s*[^\]]+\]/i', '', $html);
            $pending_list = array();
        } elseif (function_exists('cbia_replace_markers_with_pending')) {
            $final_html = cbia_replace_markers_with_pending($html, $images_limit, $pending_list);
        } else {
            $final_html = cbia_oldposts_mark_all_as_pending($html);
            $pending_list = cbia_oldposts_extract_image_markers_any($final_html);
        }

        wp_update_post(array(
            'ID'           => $post_id,
            'post_content' => $final_html,
        ));
        clean_post_cache($post_id);

        cbia_oldposts_set_pending_images_meta($post_id, $pending_list);

        if (function_exists('cbia_store_usage_meta')) {
            cbia_store_usage_meta($post_id, $usage, $model_used);
        }

        update_post_meta($post_id, '_cbia_oldposts_content_done', current_time('mysql'));
        if (!empty($skip_images)) {
            update_post_meta($post_id, '_cbia_oldposts_content_noimg_done', current_time('mysql'));
            cbia_oldposts_log_message("[OK] Contenido regenerado (sin imágenes) en post {$post_id}.");
        } else {
            cbia_oldposts_log_message("[OK] Contenido regenerado en post {$post_id}. Pendientes imágenes=".count($pending_list));
        }

        return true;
    }
}

/* =========================================================
   =================== IMÁGENES: RESET ======================
   ========================================================= */
if (!function_exists('cbia_oldposts_images_reset_pending')) {
    function cbia_oldposts_images_reset_pending($post_id, $images_limit=3, $force=false, $clear_featured=false) {
        $post = get_post($post_id);
        if (!$post) return false;

        if (!$force) {
            $done = get_post_meta($post_id, '_cbia_oldposts_images_done', true);
            if ($done !== '') return 'skipped';
        }

        $s = function_exists('cbia_get_settings') ? cbia_get_settings() : (array)get_option('cbia_settings', array());
        $images_limit = max(1, min(10, (int)$images_limit));
        if ($images_limit <= 0) $images_limit = max(1, min(4, (int)($s['images_limit'] ?? 3)));

        $content = (string)$post->post_content;

        if (!cbia_oldposts_has_any_image_marker($content)) {
            if ($clear_featured) {
                delete_post_thumbnail($post_id);
                update_post_meta($post_id, '_cbia_oldposts_images_done', current_time('mysql'));
                cbia_oldposts_log_message("[OK] Imágenes reset: no había marcadores, pero se quitó destacada post {$post_id}.");
                return true;
            }
            cbia_oldposts_log_message("[INFO] Imágenes reset: no hay marcadores en post {$post_id}. SKIP.");
            return 'skipped';
        }

        $new_content = cbia_oldposts_mark_all_as_pending($content);

        $pending_list = cbia_oldposts_extract_image_markers_any($new_content);
        if (!empty($pending_list) && count($pending_list) > $images_limit) {
            $pending_list = array_slice($pending_list, 0, $images_limit);
        }

        wp_update_post(array(
            'ID'           => $post_id,
            'post_content' => $new_content,
        ));
        clean_post_cache($post_id);

        cbia_oldposts_set_pending_images_meta($post_id, $pending_list);

        if ($clear_featured) {
            delete_post_thumbnail($post_id);
        }

        update_post_meta($post_id, '_cbia_oldposts_images_done', current_time('mysql'));
        cbia_oldposts_log_message("[OK] Imágenes reset post {$post_id}. Pendientes=".count($pending_list).($clear_featured ? " | destacada quitada" : ""));

        return true;
    }
}

/* =========================================================
   ============ IMÁGENES: SOLO CONTENIDO (reset) ============
   ========================================================= */
if (!function_exists('cbia_oldposts_images_reset_content_only')) {
    function cbia_oldposts_images_reset_content_only($post_id, $images_limit=3, $force=false) {
        $post = get_post($post_id);
        if (!$post) return false;

        if (!$force) {
            $done = get_post_meta($post_id, '_cbia_oldposts_images_content_done', true);
            if ($done !== '') return 'skipped';
        }

        // Reutiliza el reset existente, pero sin tocar destacada.
        $r = cbia_oldposts_images_reset_pending($post_id, $images_limit, true, false);
        if ($r === true) {
            update_post_meta($post_id, '_cbia_oldposts_images_content_done', current_time('mysql'));
            cbia_oldposts_log_message("[OK] Imágenes (solo contenido) reseteadas en post {$post_id}.");
            return true;
        }
        return $r;
    }
}

/* =========================================================
   ============ IMAGEN DESTACADA: SOLO DESTACADA ============
   ========================================================= */
if (!function_exists('cbia_oldposts_regenerate_featured_image')) {
    function cbia_oldposts_regenerate_featured_image($post_id, $force=false, $remove_old=false) {
        $post = get_post($post_id);
        if (!$post) return false;

        if (!$force) {
            $done = get_post_meta($post_id, '_cbia_oldposts_featured_done', true);
            if ($done !== '') return 'skipped';
        }

        if (!function_exists('cbia_generate_image_openai')) {
            cbia_oldposts_log_message("[ERROR] No existe cbia_generate_image_openai(). No puedo regenerar destacada.");
            return false;
        }

        $title = get_the_title($post_id);
        $content = (string)$post->post_content;

        // Intentamos usar el primer marcador si existe; si no, usamos el título.
        $desc = $title;
        $markers = cbia_oldposts_extract_image_markers_any($content);
        if (!empty($markers) && !empty($markers[0]['desc'])) {
            $desc = (string)$markers[0]['desc'];
        }

        if ($remove_old) {
            delete_post_thumbnail($post_id);
        }

        list($ok, $attach_id, $model, $err) = cbia_generate_image_openai($desc, 'intro', $title);
        if ($ok && $attach_id) {
            set_post_thumbnail($post_id, (int)$attach_id);
            update_post_meta($post_id, '_cbia_oldposts_featured_done', current_time('mysql'));
            update_post_meta($post_id, '_cbia_oldposts_featured_attach_id', (int)$attach_id);
            cbia_oldposts_log_message("[OK] Destacada regenerada post {$post_id} (attach_id={$attach_id}).");

            if (function_exists('cbia_costes_record_usage')) {
                cbia_costes_record_usage($post_id, array(
                    'type' => 'image',
                    'model' => (string)$model,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'cached_input_tokens' => 0,
                    'ok' => 1,
                    'error' => '',
                ));
            }

            if (function_exists('cbia_image_append_call')) {
                cbia_image_append_call($post_id, 'intro', (string)$model, true, (int)$attach_id, '');
            }

            return true;
        }

        cbia_oldposts_log_message("[ERROR] Destacada fallo post {$post_id}: " . (string)($err ?: ''));
        if (function_exists('cbia_costes_record_usage')) {
            cbia_costes_record_usage($post_id, array(
                'type' => 'image',
                'model' => (string)$model,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cached_input_tokens' => 0,
                'ok' => 0,
                'error' => (string)($err ?: ''),
            ));
        }
        if (function_exists('cbia_image_append_call')) {
            cbia_image_append_call($post_id, 'intro', (string)$model, false, 0, (string)($err ?: ''));
        }
        return false;
    }
}

/* =========================================================
   =================== QUERY (por fechas) ===================
   ========================================================= */
if (!function_exists('cbia_oldposts_build_query_args')) {
    function cbia_oldposts_build_query_args($batch_size, $scope, $filter_mode, $older_than_days, $date_from, $date_to, $post_ids=array(), $category_id=0, $author_id=0, $dry_run=false) {
        $batch_size = max(1, min(200, (int)$batch_size));
        $scope = ($scope === 'plugin') ? 'plugin' : 'all';

        $args = array(
            'post_type'      => 'post',
            'posts_per_page' => $batch_size,
            'post_status'    => array('publish', 'future', 'draft', 'pending'),
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $post_ids = is_array($post_ids) ? array_values(array_filter(array_map('intval', $post_ids))) : array();
        $category_id = (int)$category_id;
        $author_id = (int)$author_id;
        $dry_run = !empty($dry_run);

        if ($dry_run) {
            $args['fields'] = 'ids';
            $args['no_found_rows'] = true;
        }

        if (!empty($post_ids)) {
            // Si hay IDs concretos, priorizamos eso y evitamos sorpresas con fechas.
            $args['post__in'] = $post_ids;
            $args['orderby'] = 'post__in';
        }

        $filter_mode = ($filter_mode === 'range') ? 'range' : 'older';

        if (empty($post_ids) && $filter_mode === 'range') {
            $from = cbia_oldposts_sanitize_ymd($date_from);
            $to   = cbia_oldposts_sanitize_ymd($date_to);

            $date_query = array();
            if ($from !== '') {
                $date_query[] = array(
                    'column'    => 'post_date_gmt',
                    'after'     => $from . ' 00:00:00',
                    'inclusive' => true,
                );
            }
            if ($to !== '') {
                $date_query[] = array(
                    'column'    => 'post_date_gmt',
                    'before'    => $to . ' 23:59:59',
                    'inclusive' => true,
                );
            }
            if (!empty($date_query)) $args['date_query'] = $date_query;

        } elseif (empty($post_ids)) {
            $older_than_days = max(1, (int)$older_than_days);
            $cutoff_gmt = gmdate('Y-m-d H:i:s', time() - ($older_than_days * DAY_IN_SECONDS));
            $args['date_query'] = array(
                array(
                    'column'    => 'post_date_gmt',
                    'before'    => $cutoff_gmt,
                    'inclusive' => true,
                )
            );
        }

        if ($scope === 'plugin') {
            $args['meta_query'] = array(
                array('key' => '_cbia_created', 'value' => '1', 'compare' => '='),
            );
        }

        if ($category_id > 0) {
            $args['cat'] = $category_id;
        }
        if ($author_id > 0) {
            $args['author'] = $author_id;
        }

        return $args;
    }
}

/* =========================================================
   ================== PROCESO POR LOTES (v3) =================
   ========================================================= */
if (!function_exists('cbia_oldposts_run_batch_v3')) {
    function cbia_oldposts_run_batch_v3($opts = array()) {
        $defaults = array(
            'batch_size'         => 20,
            'scope'              => 'all',
            'filter_mode'        => 'older',
            'older_than_days'    => 180,
            'date_from'          => '',
            'date_to'            => '',
            'images_limit'       => 3,
            'post_ids'           => array(),
            'category_id'        => 0,
            'author_id'          => 0,
            'dry_run'            => 0,

            'do_note'            => 1,
            'force_note'         => 0,

            // Yoast por campos
            'do_yoast_metadesc'  => 1,
            'do_yoast_focuskw'   => 1,
            'do_yoast_title'     => 0,
            'force_yoast'        => 0,

            'do_yoast_reindex'   => 0,

            'do_title'           => 0,
            'force_title'        => 0,

            'do_content'         => 0,
            'force_content'      => 0,
            // Variante: contenido sin tocar imágenes
            'do_content_no_images'    => 0,
            'force_content_no_images' => 0,

            'do_images_reset'    => 0,
            'force_images_reset' => 0,
            'clear_featured'     => 0,
            // Variante: solo imágenes del contenido (sin destacada)
            'do_images_content_only'    => 0,
            'force_images_content_only' => 0,

            // Solo imagen destacada
            'do_featured_only'   => 0,
            'force_featured_only'=> 0,
            'featured_remove_old'=> 0,

            'do_categories'      => 0,
            'force_categories'   => 0,

            'do_tags'            => 0,
            'force_tags'         => 0,
        );
        $opts = array_merge($defaults, is_array($opts) ? $opts : array());

        $batch_size      = max(1, min(200, (int)$opts['batch_size']));
        $scope           = ($opts['scope'] === 'plugin') ? 'plugin' : 'all';
        $filter_mode     = ($opts['filter_mode'] === 'range') ? 'range' : 'older';
        $older_than_days = max(1, (int)$opts['older_than_days']);
        $date_from       = (string)$opts['date_from'];
        $date_to         = (string)$opts['date_to'];
        $images_limit    = max(1, min(10, (int)$opts['images_limit']));
        $post_ids        = is_array($opts['post_ids']) ? $opts['post_ids'] : cbia_oldposts_parse_ids_csv($opts['post_ids'] ?? '');
        $post_ids        = array_values(array_filter(array_map('intval', $post_ids)));
        $category_id     = (int)($opts['category_id'] ?? 0);
        $author_id       = (int)($opts['author_id'] ?? 0);
        $dry_run         = !empty($opts['dry_run']) ? 1 : 0;

        $date_ymd = current_time('Y-m-d');

        $ids_txt = !empty($post_ids) ? implode(',', array_slice($post_ids, 0, 20)) : '';
        if ($ids_txt !== '' && count($post_ids) > 20) $ids_txt .= ',…';
        cbia_oldposts_log_message(
            "INICIO v3 | lote={$batch_size} | scope={$scope} | filtro={$filter_mode} | older_than_days={$older_than_days} | from={$date_from} | to={$date_to} | images_limit={$images_limit}" .
            " | ids=" . (!empty($post_ids) ? $ids_txt : '(auto)') .
            " | cat={$category_id} | author={$author_id} | dry_run=" . ($dry_run ? 'SI' : 'NO')
        );

        cbia_oldposts_log_message(
            "ACCIONES | note=".(!empty($opts['do_note'])?'SI':'NO')."(force=".(!empty($opts['force_note'])?'SI':'NO').")".
            " | yoast(metadesc=".(!empty($opts['do_yoast_metadesc'])?'SI':'NO').",focuskw=".(!empty($opts['do_yoast_focuskw'])?'SI':'NO').",title=".(!empty($opts['do_yoast_title'])?'SI':'NO').",force=".(!empty($opts['force_yoast'])?'SI':'NO').")".
            " | yoast_reindex=".(!empty($opts['do_yoast_reindex'])?'SI':'NO').
            " | titleIA=".(!empty($opts['do_title'])?'SI':'NO')."(force=".(!empty($opts['force_title'])?'SI':'NO').")".
            " | contentIA=".(!empty($opts['do_content'])?'SI':'NO')."(force=".(!empty($opts['force_content'])?'SI':'NO').")".
            " | contentIA_noimg=".(!empty($opts['do_content_no_images'])?'SI':'NO')."(force=".(!empty($opts['force_content_no_images'])?'SI':'NO').")".
            " | images_reset=".(!empty($opts['do_images_reset'])?'SI':'NO')."(force=".(!empty($opts['force_images_reset'])?'SI':'NO').",clear_featured=".(!empty($opts['clear_featured'])?'SI':'NO').")".
            " | images_content_only=".(!empty($opts['do_images_content_only'])?'SI':'NO')."(force=".(!empty($opts['force_images_content_only'])?'SI':'NO').")".
            " | featured_only=".(!empty($opts['do_featured_only'])?'SI':'NO')."(force=".(!empty($opts['force_featured_only'])?'SI':'NO').",remove_old=".(!empty($opts['featured_remove_old'])?'SI':'NO').")".
            " | categories=".(!empty($opts['do_categories'])?'SI':'NO')."(force=".(!empty($opts['force_categories'])?'SI':'NO').")".
            " | tags=".(!empty($opts['do_tags'])?'SI':'NO')."(force=".(!empty($opts['force_tags'])?'SI':'NO').")"
        );

        if (!empty($post_ids)) {
            cbia_oldposts_log_message("NOTA: Se han indicado IDs concretos. Se ignoran los filtros por fecha.");
        }

        $args = cbia_oldposts_build_query_args($batch_size, $scope, $filter_mode, $older_than_days, $date_from, $date_to, $post_ids, $category_id, $author_id, $dry_run);

        $q = new WP_Query($args);
        if (!$q->have_posts()) {
            cbia_oldposts_log_message("No hay posts que cumplan condiciones.");
            return array(0,0,0,0); // processed, ok, skipped, fail
        }

        if (!empty($dry_run)) {
            $ids = is_array($q->posts) ? $q->posts : array();
            $count = count($ids);
            cbia_oldposts_log_message("DRY RUN: se procesarían {$count} posts (sin cambios).");

            $max_list = min(20, $count);
            for ($i = 0; $i < $max_list; $i++) {
                $pid = (int)$ids[$i];
                $t = get_the_title($pid);
                cbia_oldposts_log_message("DRY RUN: post {$pid} | '" . (string)$t . "'");
            }

            // Coste aproximado si hay acciones IA
            $needs_ai = (!empty($opts['do_content']) || !empty($opts['do_title']) || !empty($opts['do_content_no_images']));
            if ($needs_ai && function_exists('cbia_costes_estimate_for_post')) {
                $cost_settings = function_exists('cbia_costes_get_settings') ? cbia_costes_get_settings() : array();
                $cbia_settings = function_exists('cbia_get_settings') ? cbia_get_settings() : array();
                $sum_est = 0.0;
                foreach ($ids as $pid) {
                    $est = cbia_costes_estimate_for_post((int)$pid, $cost_settings, $cbia_settings);
                    if ($est !== null) $sum_est += (float)$est;
                }
                cbia_oldposts_log_message("DRY RUN: coste IA estimado (aprox)≈ " . number_format((float)$sum_est, 4, ',', '.') . " €");
            }

            return array($count, 0, $count, 0);
        }

        $processed=0; $ok=0; $sk=0; $fail=0;

        while ($q->have_posts()) {
            $q->the_post();
            $pid = get_the_ID();
            $processed++;

            if (cbia_check_stop_flag()) {
                cbia_oldposts_log_message("Detenido por usuario durante el lote.");
                break;
            }

            $post = get_post($pid);
            if (!$post) { $fail++; continue; }

            $title   = get_the_title($pid);
            $content = (string)$post->post_content;

            cbia_oldposts_log_message("---- Post {$pid} | '{$title}' ----");

            $did_any = false;
            $did_fail = false;
            $did_skip_all = true;

            // 1) TíTULO (IA)
            if (!empty($opts['do_title'])) {
                $r = cbia_oldposts_ai_optimize_title($pid, !empty($opts['force_title']));
                if ($r === true) { $did_any = true; $did_skip_all=false; }
                elseif ($r === 'skipped') { /* */ }
                else { $did_fail = true; }
                $title = get_the_title($pid);
            }

            // 2) CONTENIDO (IA)
            if (!empty($opts['do_content'])) {
                $r = cbia_oldposts_ai_regenerate_content($pid, $images_limit, !empty($opts['force_content']));
                if ($r === true) {
                    $did_any = true; $did_skip_all=false;
                    $post = get_post($pid);
                    $content = $post ? (string)$post->post_content : $content;
                } elseif ($r === 'skipped') {
                    // no
                } else {
                    $did_fail = true;
                }
            }

            // 2.1) CONTENIDO (IA) SIN IMíGENES
            if (!empty($opts['do_content_no_images'])) {
                $r = cbia_oldposts_ai_regenerate_content($pid, $images_limit, !empty($opts['force_content_no_images']), true);
                if ($r === true) {
                    $did_any = true; $did_skip_all=false;
                    $post = get_post($pid);
                    $content = $post ? (string)$post->post_content : $content;
                } elseif ($r === 'skipped') {
                    // no
                } else {
                    $did_fail = true;
                }
            }

            // 3) NOTA
            if (!empty($opts['do_note'])) {
                $r = cbia_oldposts_add_updated_note($pid, $date_ymd, !empty($opts['force_note']));
                if ($r === true) {
                    $did_any = true; $did_skip_all=false;
                    $post = get_post($pid);
                    $content = $post ? (string)$post->post_content : $content;
                } elseif ($r === 'skipped') {
                    // no
                } else {
                    $did_fail = true;
                }
            }

            // 4) IMíGENES reset
            if (!empty($opts['do_images_reset'])) {
                $r = cbia_oldposts_images_reset_pending($pid, $images_limit, !empty($opts['force_images_reset']), !empty($opts['clear_featured']));
                if ($r === true) {
                    $did_any = true; $did_skip_all=false;
                    $post = get_post($pid);
                    $content = $post ? (string)$post->post_content : $content;
                } elseif ($r === 'skipped') {
                    // no
                } else {
                    $did_fail = true;
                }
            }

            // 4.1) IMÁGENES: solo contenido (sin tocar destacada)
            if (!empty($opts['do_images_content_only'])) {
                $r = cbia_oldposts_images_reset_content_only($pid, $images_limit, !empty($opts['force_images_content_only']));
                if ($r === true) {
                    $did_any = true; $did_skip_all=false;
                    $post = get_post($pid);
                    $content = $post ? (string)$post->post_content : $content;
                } elseif ($r === 'skipped') {
                    // no
                } else {
                    $did_fail = true;
                }
            }

            // 4.2) IMAGEN DESTACADA: solo destacada
            if (!empty($opts['do_featured_only'])) {
                $r = cbia_oldposts_regenerate_featured_image($pid, !empty($opts['force_featured_only']), !empty($opts['featured_remove_old']));
                if ($r === true) {
                    $did_any = true; $did_skip_all=false;
                } elseif ($r === 'skipped') {
                    // no
                } else {
                    $did_fail = true;
                }
            }
            // 5) CATEGORíAS
            if (!empty($opts['do_categories'])) {
                $r = cbia_oldposts_assign_categories_only($pid, $title, $content, !empty($opts['force_categories']));
                if ($r === true) { $did_any = true; $did_skip_all=false; cbia_oldposts_log_message("[OK] Categorías aplicadas en post {$pid}."); }
                elseif ($r === 'skipped') { /* */ }
                else { cbia_oldposts_log_message("[WARN] Categorías no aplicadas en post {$pid}."); }
            }

            // 6) ETIQUETAS
            if (!empty($opts['do_tags'])) {
                $r = cbia_oldposts_assign_tags_only($pid, $title, $content, !empty($opts['force_tags']));
                if ($r === true) { $did_any = true; $did_skip_all=false; cbia_oldposts_log_message("[OK] Etiquetas aplicadas en post {$pid}."); }
                elseif ($r === 'skipped') { /* */ }
                else { cbia_oldposts_log_message("[WARN] Etiquetas no aplicadas en post {$pid}."); }
            }

            // 7) YOAST CAMPOS
            $do_any_yoast = (!empty($opts['do_yoast_metadesc']) || !empty($opts['do_yoast_focuskw']) || !empty($opts['do_yoast_title']));
            if ($do_any_yoast) {
                $r = cbia_oldposts_recalc_yoast_fields(
                    $pid,
                    !empty($opts['do_yoast_metadesc']),
                    !empty($opts['do_yoast_focuskw']),
                    !empty($opts['do_yoast_title']),
                    !empty($opts['force_yoast'])
                );
                if ($r) { $did_any = true; $did_skip_all=false; cbia_oldposts_log_message("[OK] Yoast campos recalculados en post {$pid}."); }
                else { cbia_oldposts_log_message("[INFO] Yoast campos no cambiados en post {$pid}."); }
            }

            // 8) YOAST REINDEX best effort
            if (!empty($opts['do_yoast_reindex'])) {
                if (function_exists('cbia_yoast_try_reindex_post')) {
                    $r = cbia_yoast_try_reindex_post($pid);
                    if ($r) { $did_any = true; $did_skip_all=false; cbia_oldposts_log_message("[OK] Yoast reindex best-effort post {$pid}."); }
                    else { cbia_oldposts_log_message("[WARN] Yoast reindex no aplicado post {$pid}."); }
                } else {
                    cbia_oldposts_log_message("[WARN] No existe cbia_yoast_try_reindex_post(). Reindex no disponible.");
                }
            }

            if ($did_fail) {
                $fail++;
                cbia_oldposts_log_message("RESULTADO post {$pid}: FALLO (alguna acción falló).");
            } elseif ($did_skip_all && !$did_any) {
                $sk++;
                cbia_oldposts_log_message("RESULTADO post {$pid}: SKIP (nada que hacer / ya hecho).");
            } else {
                $ok++;
                cbia_oldposts_log_message("RESULTADO post {$pid}: OK (hubo cambios).");
            }
        }

        wp_reset_postdata();
        cbia_oldposts_log_message("FIN v3 | processed={$processed} | ok={$ok} | skipped={$sk} | fail={$fail}");

        return array($processed, $ok, $sk, $fail);
    }
}

/* =========================================================
   ===================== UI TAB: OLDPOSTS ===================
   ========================================================= */
if (!function_exists('cbia_oldposts_handle_post')) {
    function cbia_oldposts_handle_post($settings) {
        if (function_exists('cbia_container')) {
            $container = cbia_container();
            if ($container) {
                $service = $container->get('oldposts_service');
                if ($service && method_exists($service, 'handle_post')) {
                    return $service->handle_post($settings);
                }
            }
        }
        if (!is_admin() || !current_user_can('manage_options')) return $settings;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
            // Guardar presets
            if (isset($_POST['cbia_form']) && $_POST['cbia_form'] === 'oldposts_settings') {
                if (check_admin_referer('cbia_oldposts_settings_nonce')) {
                    $u = wp_unslash($_POST);
    
                    $settings['batch_size']      = isset($u['batch_size']) ? max(1, min(200, (int)$u['batch_size'])) : (int)$settings['batch_size'];
                    $settings['scope']           = (!empty($u['scope']) && $u['scope'] === 'plugin') ? 'plugin' : 'all';
    
                    $settings['filter_mode']     = (!empty($u['filter_mode']) && $u['filter_mode'] === 'range') ? 'range' : 'older';
                    $settings['older_than_days'] = isset($u['older_than_days']) ? max(1, (int)$u['older_than_days']) : (int)$settings['older_than_days'];
                    $settings['date_from']       = cbia_oldposts_sanitize_ymd($u['date_from'] ?? '');
                    $settings['date_to']         = cbia_oldposts_sanitize_ymd($u['date_to'] ?? '');
    
                    $settings['images_limit']    = isset($u['images_limit']) ? max(1, min(10, (int)$u['images_limit'])) : (int)$settings['images_limit'];
                    $settings['post_ids']        = isset($u['post_ids']) ? implode(',', cbia_oldposts_parse_ids_csv($u['post_ids'])) : (string)$settings['post_ids'];
                    $settings['category_id']     = isset($u['category_id']) ? (int)$u['category_id'] : (int)$settings['category_id'];
                    $settings['author_id']       = isset($u['author_id']) ? (int)$u['author_id'] : (int)$settings['author_id'];
                    $settings['dry_run']         = !empty($u['dry_run']) ? 1 : 0;
    
                    $settings['do_note']         = !empty($u['do_note']) ? 1 : 0;
                    $settings['force_note']      = !empty($u['force_note']) ? 1 : 0;
    
                    $settings['do_yoast_metadesc'] = !empty($u['do_yoast_metadesc']) ? 1 : 0;
                    $settings['do_yoast_focuskw']  = !empty($u['do_yoast_focuskw']) ? 1 : 0;
                    $settings['do_yoast_title']    = !empty($u['do_yoast_title']) ? 1 : 0;
                    $settings['force_yoast']       = !empty($u['force_yoast']) ? 1 : 0;
    
                    $settings['do_yoast_reindex']  = !empty($u['do_yoast_reindex']) ? 1 : 0;
    
                    $settings['do_title']        = !empty($u['do_title']) ? 1 : 0;
                    $settings['force_title']     = !empty($u['force_title']) ? 1 : 0;
    
                    $settings['do_content']      = !empty($u['do_content']) ? 1 : 0;
                    $settings['force_content']   = !empty($u['force_content']) ? 1 : 0;
                    $settings['do_content_no_images']    = !empty($u['do_content_no_images']) ? 1 : 0;
                    $settings['force_content_no_images'] = !empty($u['force_content_no_images']) ? 1 : 0;
    
                    $settings['do_images_reset']    = !empty($u['do_images_reset']) ? 1 : 0;
                    $settings['force_images_reset'] = !empty($u['force_images_reset']) ? 1 : 0;
                    $settings['clear_featured']     = !empty($u['clear_featured']) ? 1 : 0;
                    $settings['do_images_content_only']    = !empty($u['do_images_content_only']) ? 1 : 0;
                    $settings['force_images_content_only'] = !empty($u['force_images_content_only']) ? 1 : 0;
                    $settings['do_featured_only']          = !empty($u['do_featured_only']) ? 1 : 0;
                    $settings['force_featured_only']       = !empty($u['force_featured_only']) ? 1 : 0;
                    $settings['featured_remove_old']       = !empty($u['featured_remove_old']) ? 1 : 0;
    
                    $settings['do_categories']   = !empty($u['do_categories']) ? 1 : 0;
                    $settings['force_categories']= !empty($u['force_categories']) ? 1 : 0;
    
                    $settings['do_tags']         = !empty($u['do_tags']) ? 1 : 0;
                    $settings['force_tags']      = !empty($u['force_tags']) ? 1 : 0;
    
                    update_option(cbia_oldposts_settings_key(), $settings);
                    echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
                }
            }
    
            // Acciones
            if (isset($_POST['cbia_form']) && $_POST['cbia_form'] === 'oldposts_actions') {
                if (check_admin_referer('cbia_oldposts_actions_nonce')) {
                    $u = wp_unslash($_POST);
                    $action = sanitize_text_field($u['cbia_action'] ?? '');
    
                    $run_actions = array(
                        'run_oldposts',
                        'run_quick_yoast_metas',
                        'run_quick_yoast_reindex',
                        'run_quick_featured',
                        'run_quick_images_only',
                        'run_quick_content_only',
                    );
    
                    // Base común para ejecuciones (normal o rápida)
                    $run_base = $settings;
                    if (in_array($action, $run_actions, true)) {
                        cbia_set_stop_flag(false);
                        $run_base['batch_size']      = isset($u['run_batch_size']) ? max(1, min(200, (int)$u['run_batch_size'])) : (int)$settings['batch_size'];
                        $run_base['scope']           = !empty($u['run_scope_plugin']) ? 'plugin' : 'all';
    
                        $run_base['filter_mode']     = (!empty($u['run_filter_mode']) && $u['run_filter_mode'] === 'range') ? 'range' : 'older';
                        $run_base['older_than_days'] = isset($u['run_older_than_days']) ? max(1, (int)$u['run_older_than_days']) : (int)$settings['older_than_days'];
                        $run_base['date_from']       = cbia_oldposts_sanitize_ymd($u['run_date_from'] ?? $settings['date_from']);
                        $run_base['date_to']         = cbia_oldposts_sanitize_ymd($u['run_date_to'] ?? $settings['date_to']);
    
                        $run_base['images_limit']    = isset($u['run_images_limit']) ? max(1, min(10, (int)$u['run_images_limit'])) : (int)$settings['images_limit'];
    
                        // Filtros avanzados (acepta run_* y nombres simples)
                        $run_base['post_ids'] = cbia_oldposts_parse_ids_csv(
                            $u['run_post_ids']
                                ?? $u['post_ids']
                                ?? ($settings['post_ids'] ?? '')
                        );
                        $run_base['category_id'] = isset($u['run_category_id'])
                            ? (int)$u['run_category_id']
                            : (isset($u['category_id']) ? (int)$u['category_id'] : (int)($settings['category_id'] ?? 0));
                        $run_base['author_id'] = isset($u['run_author_id'])
                            ? (int)$u['run_author_id']
                            : (isset($u['author_id']) ? (int)$u['author_id'] : (int)($settings['author_id'] ?? 0));
                        $run_base['dry_run'] = !empty($u['run_dry_run']) || !empty($u['dry_run']) ? 1 : 0;
                    }
    
                    if ($action === 'run_oldposts') {
                        cbia_set_stop_flag(false);
    
                        // Base: presets
                        $run = $run_base;
    
                        // Overrides básicos siempre visibles
                        $run['batch_size']      = isset($u['run_batch_size']) ? max(1, min(200, (int)$u['run_batch_size'])) : (int)$settings['batch_size'];
                        $run['scope']           = !empty($u['run_scope_plugin']) ? 'plugin' : 'all';
    
                        $run['filter_mode']     = (!empty($u['run_filter_mode']) && $u['run_filter_mode'] === 'range') ? 'range' : 'older';
                        $run['older_than_days'] = isset($u['run_older_than_days']) ? max(1, (int)$u['run_older_than_days']) : (int)$settings['older_than_days'];
                        $run['date_from']       = cbia_oldposts_sanitize_ymd($u['run_date_from'] ?? $settings['date_from']);
                        $run['date_to']         = cbia_oldposts_sanitize_ymd($u['run_date_to'] ?? $settings['date_to']);
    
                        $run['images_limit']    = isset($u['run_images_limit']) ? max(1, min(10, (int)$u['run_images_limit'])) : (int)$settings['images_limit'];
    
                        // Si el usuario activa personalización, entonces sí aplicamos overrides de acciones.
                        $custom = !empty($u['run_custom_actions']) ? true : false;
    
                        if ($custom) {
                            $run['do_note']           = !empty($u['run_do_note']) ? 1 : 0;
                            $run['force_note']        = !empty($u['run_force_note']) ? 1 : 0;
    
                            $run['do_yoast_metadesc'] = !empty($u['run_do_yoast_metadesc']) ? 1 : 0;
                            $run['do_yoast_focuskw']  = !empty($u['run_do_yoast_focuskw']) ? 1 : 0;
                            $run['do_yoast_title']    = !empty($u['run_do_yoast_title']) ? 1 : 0;
                            $run['force_yoast']       = !empty($u['run_force_yoast']) ? 1 : 0;
    
                            $run['do_yoast_reindex']  = !empty($u['run_do_yoast_reindex']) ? 1 : 0;
    
                            $run['do_title']          = !empty($u['run_do_title']) ? 1 : 0;
                            $run['force_title']       = !empty($u['run_force_title']) ? 1 : 0;
    
                            $run['do_content']        = !empty($u['run_do_content']) ? 1 : 0;
                            $run['force_content']     = !empty($u['run_force_content']) ? 1 : 0;
                            $run['do_content_no_images']    = !empty($u['run_do_content_no_images']) ? 1 : 0;
                            $run['force_content_no_images'] = !empty($u['run_force_content_no_images']) ? 1 : 0;
    
                            $run['do_images_reset']    = !empty($u['run_do_images_reset']) ? 1 : 0;
                            $run['force_images_reset'] = !empty($u['run_force_images_reset']) ? 1 : 0;
                            $run['clear_featured']     = !empty($u['run_clear_featured']) ? 1 : 0;
                            $run['do_images_content_only']    = !empty($u['run_do_images_content_only']) ? 1 : 0;
                            $run['force_images_content_only'] = !empty($u['run_force_images_content_only']) ? 1 : 0;
                            $run['do_featured_only']          = !empty($u['run_do_featured_only']) ? 1 : 0;
                            $run['force_featured_only']       = !empty($u['run_force_featured_only']) ? 1 : 0;
                            $run['featured_remove_old']       = !empty($u['run_featured_remove_old']) ? 1 : 0;
    
                            $run['do_categories']     = !empty($u['run_do_categories']) ? 1 : 0;
                            $run['force_categories']  = !empty($u['run_force_categories']) ? 1 : 0;
    
                            $run['do_tags']           = !empty($u['run_do_tags']) ? 1 : 0;
                            $run['force_tags']        = !empty($u['run_force_tags']) ? 1 : 0;
                        }
    
                        cbia_oldposts_run_batch_v3($run);
    
                        echo '<div class="notice notice-success is-dismissible"><p>Lote ejecutado. Revisa el log.</p></div>';
                    }
    
                    // Acciones rápidas (sobrescriben acciones, respetan filtros)
                    if (in_array($action, $run_actions, true) && $action !== 'run_oldposts') {
                        $run = $run_base;
    
                        $action_keys = array(
                            'do_note','force_note',
                            'do_yoast_metadesc','do_yoast_focuskw','do_yoast_title','force_yoast','do_yoast_reindex',
                            'do_title','force_title',
                            'do_content','force_content',
                            'do_content_no_images','force_content_no_images',
                            'do_images_reset','force_images_reset','clear_featured',
                            'do_images_content_only','force_images_content_only',
                            'do_featured_only','force_featured_only','featured_remove_old',
                            'do_categories','force_categories',
                            'do_tags','force_tags',
                        );
                        foreach ($action_keys as $k) { $run[$k] = 0; }
    
                        if ($action === 'run_quick_yoast_metas') {
                            $run['do_yoast_metadesc'] = 1;
                            $run['do_yoast_focuskw']  = 1;
                            $run['do_yoast_title']    = 1;
                        } elseif ($action === 'run_quick_yoast_reindex') {
                            $run['do_yoast_reindex'] = 1;
                        } elseif ($action === 'run_quick_featured') {
                            $run['do_featured_only']    = 1;
                            $run['force_featured_only'] = 1;
                            $run['featured_remove_old'] = !empty($u['run_featured_remove_old']) ? 1 : 0;
                        } elseif ($action === 'run_quick_images_only') {
                            $run['do_images_content_only']    = 1;
                            $run['force_images_content_only'] = !empty($u['run_force_images_content_only']) ? 1 : 0;
                        } elseif ($action === 'run_quick_content_only') {
                            $run['do_content_no_images']    = 1;
                            $run['force_content_no_images'] = !empty($u['run_force_content_no_images']) ? 1 : 0;
                        }
    
                        cbia_oldposts_run_batch_v3($run);
                        echo '<div class="notice notice-success is-dismissible"><p>Acción rápida ejecutada. Revisa el log.</p></div>';
                    }
    
                    if ($action === 'stop') {
                        cbia_set_stop_flag(true);
                        echo '<div class="notice notice-warning is-dismissible"><p>Stop activado.</p></div>';
                    }
    
                    if ($action === 'clear_log') {
                        cbia_oldposts_clear_log();
                        cbia_oldposts_log_message("Log limpiado manualmente.");
                        echo '<div class="notice notice-success is-dismissible"><p>Log limpiado.</p></div>';
                    }
                }
            }
        }

        return $settings;
    }
}


/* ------------------------- FIN includes/engine/oldposts.php ------------------------- */
