<?php
/**
 * Content helpers: markers, cleanup, FAQ heading.
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
   ================== MARCADORES DE IMAGEN ==================
   ========================================================= */

if (!function_exists('cbia_marker_regex')) {
    function cbia_marker_regex() {
        // soporta saltos de linea en la descripcion
        return '/\[(IMAGEN|IMAGE|IMMAGINE|IMAGEM|BILD|FOTO)\s*:\s*([\s\S]+?)\]/i';
    }
}

if (!function_exists('cbia_marker_pending_regex')) {
    function cbia_marker_pending_regex() {
        return '/\[IMAGEN_PENDIENTE\s*:\s*([^\]]+?)\]/i';
    }
}

if (!function_exists('cbia_extract_image_markers')) {
    function cbia_extract_image_markers($html) {
        $markers = [];
        if (preg_match_all(cbia_marker_regex(), (string)$html, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $idx => $fullCap) {
                $raw_desc = (string)$m[2][$idx][0];
                $markers[] = [
                    'full' => $fullCap[0],
                    'pos'  => (int)$fullCap[1],
                    'desc' => $raw_desc,
                    'short_desc' => cbia_sanitize_image_short_desc($raw_desc),
                ];
            }
        }
        return $markers;
    }
}

if (!function_exists('cbia_normalize_image_markers')) {
    /**
     * Normaliza variantes malformadas como [hIMAGEN: ...] => [IMAGEN: ...]
     */
    function cbia_normalize_image_markers($html) {
        $html = (string)$html;
        $html = preg_replace('/\\[h\\s*(IMAGEN|IMAGE|IMMAGINE|IMAGEM|BILD|FOTO)\\s*:/i', '[$1:', $html);
        return $html;
    }
}

if (!function_exists('cbia_extract_pending_markers')) {
    function cbia_extract_pending_markers($html) {
        $markers = [];
        if (preg_match_all(cbia_marker_pending_regex(), (string)$html, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $idx => $fullCap) {
                $markers[] = [
                    'full' => $fullCap[0],
                    'pos'  => (int)$fullCap[1],
                    'desc' => (string)$m[1][$idx][0],
                ];
            }
        }
        return $markers;
    }
}

if (!function_exists('cbia_sanitize_alt_from_desc')) {
    function cbia_sanitize_alt_from_desc($desc) {
        $alt = wp_strip_all_tags((string)$desc);
        $alt = preg_replace('/\s+/', ' ', $alt);
        $alt = trim($alt);
        return trim(mb_substr($alt, 0, 140));
    }
}

if (!function_exists('cbia_sanitize_image_short_desc')) {
    /**
     * Sanitiza descripcion corta para prompts.
     */
    function cbia_sanitize_image_short_desc($desc, $max_len = 280) {
        $out = wp_strip_all_tags((string)$desc);
        $out = str_replace(["\r", "\n", "\t"], ' ', $out);
        $out = str_replace(['"', "'"], '', $out);
        $out = preg_replace('/\s+/', ' ', $out);
        $out = trim($out);

        $max_len = (int)$max_len;
        if ($max_len < 120) $max_len = 120;
        if ($max_len > 300) $max_len = 300;

        if (mb_strlen($out) > $max_len) {
            $cut = mb_substr($out, 0, $max_len);
            $last_space = mb_strrpos($cut, ' ');
            if ($last_space !== false && $last_space > 40) {
                $cut = mb_substr($cut, 0, $last_space);
            }
            $out = trim($cut);
        }

        return $out;
    }
}

if (!function_exists('cbia_build_img_alt')) {
    function cbia_build_img_alt($title, $section_name, $summary_prompt) {
        $base = cbia_sanitize_alt_from_desc($summary_prompt);
        $parts = array_filter([$title, ucfirst((string)$section_name), $base]);
        $alt = implode(' - ', array_unique($parts));
        return trim(mb_substr($alt, 0, 140));
    }
}

if (!function_exists('cbia_section_label')) {
    /**
     * Etiqueta legible para logs/UX.
     */
    function cbia_section_label($section) {
        $section = strtolower((string)$section);
        if ($section === 'intro' || $section === 'featured' || $section === 'destacada') return 'destacada';
        if ($section === 'conclusion' || $section === 'cierre') return 'cierre';
        if ($section === 'faq') return 'faq';
        return 'cuerpo';
    }
}

if (!function_exists('cbia_yoast_faq_block_available')) {
    if (!function_exists('cbia_gutenberg_plugin_active')) {
        function cbia_gutenberg_plugin_active() {
            // CAMBIO: requisito explÃ­cito solicitado por UX: plugin Gutenberg activo.
            if (!function_exists('is_plugin_active')) {
                if (!defined('ABSPATH')) return false;
                $plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
                if (file_exists($plugin_file)) {
                    require_once $plugin_file;
                }
            }
            return function_exists('is_plugin_active') && is_plugin_active('gutenberg/gutenberg.php');
        }
    }

    if (!function_exists('cbia_yoast_faq_block_unavailable_reason')) {
        function cbia_yoast_faq_block_unavailable_reason() {
            if (!cbia_gutenberg_plugin_active()) return 'Plugin Gutenberg no activo';
            if (!defined('WPSEO_VERSION')) return 'Yoast no activo';
            if (!class_exists('WP_Block_Type_Registry')) return 'Registro de bloques no disponible';
            if (!function_exists('do_blocks') || !function_exists('parse_blocks')) return 'Motor de bloques no disponible';

            $registry = WP_Block_Type_Registry::get_instance();
            if (!is_object($registry) || !$registry->is_registered('yoast/faq-block')) return 'Bloque yoast/faq-block no registrado';

            $block = $registry->get_registered('yoast/faq-block');
            if (!is_object($block)) return 'Bloque yoast/faq-block invÃ¡lido';
            if (empty($block->render_callback) || !is_callable($block->render_callback)) return 'Bloque FAQ sin render callback';
            return '';
        }
    }

    function cbia_yoast_faq_block_available() {
        return cbia_yoast_faq_block_unavailable_reason() === '';
    }
}

if (!function_exists('cbia_extract_faq_items_from_html')) {
    function cbia_extract_faq_items_from_html($html) {
        $items = [];
        $html = (string)$html;

        // localizar encabezado FAQ
        if (!preg_match('/<h2[^>]*>\\s*(FAQ|Preguntas frecuentes|Preguntas Frecuentes|Questions? ?FAQs?|FAQs)\\s*<\\/h2>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $items;
        }
        $h2_pos = (int)$m[0][1];
        $h2_len = strlen($m[0][0]);

        // fin del bloque FAQ: siguiente h2 o final
        $next_h2_pos = false;
        if (preg_match('/<h2[^>]*>/i', $html, $m2, PREG_OFFSET_CAPTURE, $h2_pos + $h2_len)) {
            $next_h2_pos = (int)($m2[0][1] ?? 0);
        }
        $faq_end = $next_h2_pos !== false ? $next_h2_pos : strlen($html);

        $section = substr($html, $h2_pos + $h2_len, $faq_end - ($h2_pos + $h2_len));

        // encontrar cada h3 dentro de FAQ y su respuesta
        if (!preg_match_all('/<h3[^>]*>.*?<\\/h3>/is', $section, $h3s, PREG_OFFSET_CAPTURE)) {
            return $items;
        }

        $count = count($h3s[0]);
        for ($i = 0; $i < $count; $i++) {
            $h3_html = (string)$h3s[0][$i][0];
            $h3_pos  = (int)$h3s[0][$i][1];
            $h3_len  = strlen($h3_html);
            $next_pos = ($i + 1 < $count) ? (int)$h3s[0][$i + 1][1] : strlen($section);

            $question = trim(wp_strip_all_tags($h3_html));
            if ($question === '') continue;

            $answer_html = trim(substr($section, $h3_pos + $h3_len, $next_pos - ($h3_pos + $h3_len)));
            if ($answer_html === '') continue;

            // asegura al menos un <p>
            if (!preg_match('/<p\\b/i', $answer_html)) {
                $answer_html = '<p>' . esc_html($answer_html) . '</p>';
            }

            $items[] = [
                'question' => $question,
                'answer_html' => $answer_html,
            ];
        }

        return $items;
    }
}

if (!function_exists('cbia_build_yoast_faq_block')) {
    function cbia_build_yoast_faq_block($items) {
        if (empty($items)) return '';
        $out = "<!-- wp:yoast/faq-block -->\n";
        foreach ($items as $it) {
            $json = wp_json_encode(['question' => (string)$it['question']]);
            $out .= "<!-- wp:yoast/faq-block/faq-item {$json} -->\n";
            $out .= (string)$it['answer_html'] . "\n";
            $out .= "<!-- /wp:yoast/faq-block/faq-item -->\n";
        }
        $out .= "<!-- /wp:yoast/faq-block -->";
        return $out;
    }
}

if (!function_exists('cbia_convert_faq_to_yoast_block')) {
    /**
     * Devuelve [html, did, status]
     */
    function cbia_convert_faq_to_yoast_block($html) {
        $html = (string)$html;
        return [$html, false, 'Bloque FAQ Yoast desactivado (se mantiene HTML)'];
    }
}

if (!function_exists('cbia_detect_marker_section')) {
    function cbia_detect_marker_section($html, $marker_pos, $is_first) {
        $len = strlen((string)$html);
        $html = (string)$html;
        // Si hay FAQ y el marcador estÃƒÂ¡ despuÃƒÂ©s => faq
        if (preg_match('/<h2[^>]*>[^<]*(FAQ|Preguntas frecuentes|Questions|FAQs)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $faq_pos = (int)($m[0][1] ?? 0);
            if ($faq_pos > 0) {
                // Si estÃƒÂ¡ justo antes de FAQ (margen razonable), marcar como faq
                if ($marker_pos >= max(0, $faq_pos - 2000) && $marker_pos < $faq_pos) return 'faq';
                if ($marker_pos > $faq_pos) return 'faq';
            }
        }
        // Si hay ConclusiÃƒÂ³n/Cierre y el marcador estÃƒÂ¡ despuÃƒÂ©s => conclusion
        if (preg_match('/<h2[^>]*>[^<]*(ConclusiÃƒÂ³n|Conclusion|Cierre|Final)/i', $html, $m2, PREG_OFFSET_CAPTURE)) {
            $concl_pos = (int)($m2[0][1] ?? 0);
            if ($concl_pos > 0 && $marker_pos > $concl_pos) return 'conclusion';
        }
        // Si estÃƒÂ¡ a mitad o cerca del final => conclusion
        if ($marker_pos > (int)(0.50 * $len)) return 'conclusion';
        return 'body';
    }
}

if (!function_exists('cbia_remove_marker_from_html')) {
    function cbia_remove_marker_from_html($html, $marker_full) {
        return str_replace($marker_full, '', (string)$html);
    }
}

if (!function_exists('cbia_insert_marker_after_first_p')) {
    function cbia_insert_marker_after_first_p($html, $marker) {
        $html = (string)$html;
        $marker = (string)$marker;
        if (preg_match('/<p[^>]*>.*?<\/p>/is', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = (int)$m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . "\n\n" . $marker . "\n\n" . substr($html, $pos);
        }
        return $marker . "\n\n" . $html;
    }
}

if (!function_exists('cbia_insert_marker_before_faq')) {
    function cbia_insert_marker_before_faq($html, $marker) {
        $html = (string)$html;
        $marker = (string)$marker;
        if (preg_match('/<h2[^>]*>[^<]*(FAQ|Preguntas frecuentes|Questions|FAQs)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = (int)$m[0][1];
            return substr($html, 0, $pos) . "\n\n" . $marker . "\n\n" . substr($html, $pos);
        }
        return $html . "\n\n" . $marker;
    }
}

if (!function_exists('cbia_insert_marker_before_conclusion')) {
    function cbia_insert_marker_before_conclusion($html, $marker) {
        $html = (string)$html;
        $marker = (string)$marker;
        if (preg_match('/<h2[^>]*>[^<]*(ConclusiÃƒÂ³n|Conclusion|Cierre|Final)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = (int)$m[0][1];
            return substr($html, 0, $pos) . "\n\n" . $marker . "\n\n" . substr($html, $pos);
        }
        return $html . "\n\n" . $marker;
    }
}

if (!function_exists('cbia_fix_content_artifacts')) {
    function cbia_fix_content_artifacts($html) {
        $html = (string)$html;

        // Eliminar puntos sueltos tras spans pendientes o tags
        $html = preg_replace('/(<\/span>)\s*\./i', '$1', $html);
        $html = preg_replace('/(<\/p>)\s*\./i', '$1', $html);

        // Eliminar lÃƒÂ­neas con solo un punto
        $html = preg_replace('/^\s*\.\s*$/m', '', $html);

        // Colapsar mÃƒÂºltiples saltos de lÃƒÂ­nea
        $html = preg_replace("/\n{3,}/", "\n\n", $html);

        // Eliminar parrafos vacios
        $html = preg_replace('/<p>\s*<\/p>/i', '', $html);
        $html = preg_replace('/<p>\s*&nbsp;\s*<\/p>/i', '', $html);

        return $html;
    }
}

if (!function_exists('cbia_get_faq_heading')) {
    function cbia_get_faq_heading() {
        $s = cbia_get_settings();
        $custom = trim((string)($s['faq_heading_custom'] ?? ''));
        if ($custom !== '') return $custom;

        $lang = strtolower(trim((string)($s['post_language'] ?? 'espaÃƒÂ±ol')));
        if (strpos($lang, 'ingl') !== false || strpos($lang, 'english') !== false) return 'Frequently Asked Questions';
        if (strpos($lang, 'fran') !== false || strpos($lang, 'franÃƒÂ§ais') !== false || strpos($lang, 'franc') !== false) return 'Questions frÃƒÂ©quentes';
        if (strpos($lang, 'deut') !== false || strpos($lang, 'alem') !== false || strpos($lang, 'german') !== false) return 'HÃƒÂ¤ufige Fragen';
        if (strpos($lang, 'ital') !== false) return 'Domande frequenti';
        if (strpos($lang, 'port') !== false) return 'Perguntas frequentes';

        return 'Preguntas frecuentes';
    }
}

if (!function_exists('cbia_insert_faq_heading_if_missing')) {
    function cbia_insert_faq_heading_if_missing($html) {
        $html = (string)$html;
        if (preg_match('/<h2[^>]*>[^<]*(FAQ|Preguntas frecuentes|Questions|FAQs)/i', $html)) {
            return $html;
        }

        $heading = cbia_get_faq_heading();
        return $html . "\n\n<h2>" . esc_html($heading) . "</h2>\n";
    }
}

if (!function_exists('cbia_normalize_faq_heading')) {
    /**
     * Normaliza el tÃƒÂ­tulo de FAQ a la versiÃƒÂ³n configurada/idioma.
     */
    function cbia_normalize_faq_heading($html) {
        $html = (string)$html;
        $heading = cbia_get_faq_heading();
        if ($heading === '') return $html;

        // Reemplaza cualquier H2 de FAQ conocido por el heading deseado.
        $pattern = '/<h2[^>]*>\\s*(FAQ|Preguntas frecuentes|Preguntas Frecuentes|Questions? ?FAQs?|FAQs)\\s*<\\/h2>/i';
        $replacement = '<h2>' . esc_html($heading) . '</h2>';
        $html = preg_replace($pattern, $replacement, $html);

        return $html;
    }
}

if (!function_exists('cbia_cleanup_post_html')) {
    /**
     * Limpieza final del HTML del post (artefactos, puntos sueltos, saltos).
     */
    function cbia_cleanup_post_html($html) {
        $html = (string)$html;
        if (function_exists('cbia_fix_content_artifacts')) {
            $html = cbia_fix_content_artifacts($html);
        }
        return $html;
    }
}

