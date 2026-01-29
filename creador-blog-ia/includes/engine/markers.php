<?php
/**
 * Image marker helpers.
 */

if (!defined('ABSPATH')) exit;

// Añadir la función cbia_force_insert_markers en el lugar correcto
if (!function_exists('cbia_force_insert_markers')) {
    function cbia_force_insert_markers($html, $title, $internal_limit) {
        $markers_actuales = 0;
        if (preg_match_all('/\[IMAGEN\s*:[^\]]+\]/i', $html, $mm)) {
            $markers_actuales = count($mm[0]);
        }
        $faltan = $internal_limit - $markers_actuales;
        if ($faltan <= 0) return $html;
        $inserted = 0;
        // Intro
        if ($inserted < $faltan && preg_match('/<p[^>]*>.*?<\/p>/is', $html, $m, PREG_OFFSET_CAPTURE)) {
            $p_full = $m[0][0]; $p_len = strlen($p_full); $pos0 = $m[0][1];
            $desc = preg_replace('/\s+/', ' ', strip_tags($p_full));
            $marker = "\n[IMAGEN: {$desc}]\n"; $html = substr($html, 0, $pos0 + $p_len) . $marker . substr($html, $pos0 + $p_len); $inserted++;
        }
        // FAQ
        $faq_pos = preg_match('/<h2[^>]*>.*?(preguntas\s+frecuentes|faq|frequently\s+asked\s+questions|perguntas\s+frequentes|questions\s+fréquentes|domande\s+frequenti|häufig\s+gestellte\s+fragen|veelgestelde\s+vragen|vanliga\s+frågor|ofte\s+stillede\s+spørgsmål|ofte\s+stilte\s+spørsmål|usein\s+kysytyt\s+kysymykset|najczęściej\s+zadawane\s+pytania|často\s+kladené\s+otázky|gyakran\s+ismételt\s+kérdések|întrebări\s+frecvente|често\s+задавани\s+въпроси|συχνές\s+ερωτήσεις|često\s+postavljana\s+pitanja|pogosta\s+vprašanja|korduma\s+kippuvad\s+küsimused|biežāk\s+uzdotie\s+jautājumi|dažniausiai\s+užduodami\s+klausimai|ceisteanna\s+coitianta|mistoqsijiet\s+frekwenti|dumondas\s+frequentas).*?<\/h2>/iu', $html, $mm2, PREG_OFFSET_CAPTURE) ? $mm2[0][1] : -1;
        if ($inserted < $faltan && $faq_pos >= 0) {
            $marker = "\n[IMAGEN: soporte visual para las preguntas frecuentes]\n";
            $html = substr($html, 0, $faq_pos) . $marker . substr($html, $faq_pos); $inserted++;
        }
        // Solo si aún faltan, añade uno al final
        if ($inserted < $faltan) {
            $desc = "cierre visual coherente con el tema de '{$title}'";
            $marker = "\n[IMAGEN: {$desc}]\n"; $html .= $marker; $inserted++;
        }
        return $html;
    }
}
