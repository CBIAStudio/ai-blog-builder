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
        return '/\[(IMAGEN|IMAGE|IMMAGINE|IMAGEM|BILD|FOTO)\s*:\s*([^\]]+?)\]/i';
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
                $markers[] = [
                    'full' => $fullCap[0],
                    'pos'  => (int)$fullCap[1],
                    'desc' => (string)$m[2][$idx][0],
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

if (!function_exists('cbia_build_img_alt')) {
    function cbia_build_img_alt($title, $section_name, $summary_prompt) {
        $base = cbia_sanitize_alt_from_desc($summary_prompt);
        $parts = array_filter([$title, ucfirst((string)$section_name), $base]);
        $alt = implode(' – ', array_unique($parts));
        return trim(mb_substr($alt, 0, 140));
    }
}

if (!function_exists('cbia_detect_marker_section')) {
    function cbia_detect_marker_section($html, $marker_pos, $is_first) {
        $len = strlen((string)$html);
        $html = (string)$html;
        // Si hay FAQ y el marcador está después => faq
        if (preg_match('/<h2[^>]*>[^<]*(FAQ|Preguntas frecuentes|Questions|FAQs)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $faq_pos = (int)($m[0][1] ?? 0);
            if ($faq_pos > 0) {
                // Si está justo antes de FAQ (margen razonable), marcar como faq
                if ($marker_pos >= max(0, $faq_pos - 2000) && $marker_pos < $faq_pos) return 'faq';
                if ($marker_pos > $faq_pos) return 'faq';
            }
        }
        // Si hay Conclusión/Cierre y el marcador está después => conclusion
        if (preg_match('/<h2[^>]*>[^<]*(Conclusión|Conclusion|Cierre|Final)/i', $html, $m2, PREG_OFFSET_CAPTURE)) {
            $concl_pos = (int)($m2[0][1] ?? 0);
            if ($concl_pos > 0 && $marker_pos > $concl_pos) return 'conclusion';
        }
        // Si está a mitad o cerca del final => conclusion
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
        if (preg_match('/<h2[^>]*>[^<]*(Conclusión|Conclusion|Cierre|Final)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
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

        // Eliminar líneas con solo un punto
        $html = preg_replace('/^\s*\.\s*$/m', '', $html);

        // Colapsar múltiples saltos de línea
        $html = preg_replace("/\n{3,}/", "\n\n", $html);

        return $html;
    }
}

if (!function_exists('cbia_get_faq_heading')) {
    function cbia_get_faq_heading() {
        $s = cbia_get_settings();
        $custom = trim((string)($s['faq_heading_custom'] ?? ''));
        if ($custom !== '') return $custom;

        $lang = strtolower(trim((string)($s['post_language'] ?? 'español')));
        if (strpos($lang, 'ingl') !== false || strpos($lang, 'english') !== false) return 'Frequently Asked Questions';
        if (strpos($lang, 'fran') !== false || strpos($lang, 'français') !== false || strpos($lang, 'franc') !== false) return 'Questions fréquentes';
        if (strpos($lang, 'deut') !== false || strpos($lang, 'alem') !== false || strpos($lang, 'german') !== false) return 'Häufige Fragen';
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
     * Normaliza el título de FAQ a la versión configurada/idioma.
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
