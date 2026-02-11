<?php
/**
 * Image marker helpers.
 */

if (!defined('ABSPATH')) exit;

// AÃ±adir la funciÃ³n cbia_force_insert_markers en el lugar correcto
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
            $desc = preg_replace('/\s+/', ' ', wp_strip_all_tags($p_full));
            $marker = "\n[IMAGEN: {$desc}]\n"; $html = substr($html, 0, $pos0 + $p_len) . $marker . substr($html, $pos0 + $p_len); $inserted++;
        }
        // FAQ
        $faq_pos = preg_match('/<h2[^>]*>.*?(preguntas\s+frecuentes|faq|frequently\s+asked\s+questions|perguntas\s+frequentes|questions\s+frÃ©quentes|domande\s+frequenti|hÃ¤ufig\s+gestellte\s+fragen|veelgestelde\s+vragen|vanliga\s+frÃ¥gor|ofte\s+stillede\s+spÃ¸rgsmÃ¥l|ofte\s+stilte\s+spÃ¸rsmÃ¥l|usein\s+kysytyt\s+kysymykset|najczÄ™Å›ciej\s+zadawane\s+pytania|Äasto\s+kladenÃ©\s+otÃ¡zky|gyakran\s+ismÃ©telt\s+kÃ©rdÃ©sek|Ã®ntrebÄƒri\s+frecvente|Ñ‡ÐµÑÑ‚Ð¾\s+Ð·Ð°Ð´Ð°Ð²Ð°Ð½Ð¸\s+Ð²ÑŠÐ¿Ñ€Ð¾ÑÐ¸|ÏƒÏ…Ï‡Î½Î­Ï‚\s+ÎµÏÏ‰Ï„Î®ÏƒÎµÎ¹Ï‚|Äesto\s+postavljana\s+pitanja|pogosta\s+vpraÅ¡anja|korduma\s+kippuvad\s+kÃ¼simused|bieÅ¾Äk\s+uzdotie\s+jautÄjumi|daÅ¾niausiai\s+uÅ¾duodami\s+klausimai|ceisteanna\s+coitianta|mistoqsijiet\s+frekwenti|dumondas\s+frequentas).*?<\/h2>/iu', $html, $mm2, PREG_OFFSET_CAPTURE) ? $mm2[0][1] : -1;
        if ($inserted < $faltan && $faq_pos >= 0) {
            $marker = "\n[IMAGEN: soporte visual para las preguntas frecuentes]\n";
            $html = substr($html, 0, $faq_pos) . $marker . substr($html, $faq_pos); $inserted++;
        }
        // Solo si aÃºn faltan, aÃ±ade uno al final
        if ($inserted < $faltan) {
            $desc = "cierre visual coherente con el tema de '{$title}'";
            $marker = "\n[IMAGEN: {$desc}]\n"; $html .= $marker; $inserted++;
        }
        return $html;
    }
}

