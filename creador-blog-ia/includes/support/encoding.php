<?php
/**
 * Encoding helpers.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_fix_mojibake')) {
    function cbia_fix_mojibake($text) {
        $text = (string)$text;
        if ($text === '') return $text;
        $map = array(
            // Mojibake simple
            'tÃ­tulo' => 'título',
            'TÃ­tulo' => 'Título',
            'cambiÃ³' => 'cambió',
            'devolviÃ³' => 'devolvió',
            'invÃ¡lido' => 'inválido',
            'ImÃ¡genes' => 'Imágenes',
            'imÃ¡genes' => 'imágenes',
            'CategorÃ­as' => 'Categorías',
            'categorÃ­as' => 'categorías',
            'acciÃ³n' => 'acción',
            // Mojibake doble
            'tÃƒÂ­tulo' => 'título',
            'TÃƒÂ­tulo' => 'Título',
            'cambiÃƒÂ³' => 'cambió',
            'devolviÃƒÂ³' => 'devolvió',
            'invÃƒÂ¡lido' => 'inválido',
            'ImÃƒÂ¡genes' => 'Imágenes',
            'imÃƒÂ¡genes' => 'imágenes',
            'CategorÃƒÂ­as' => 'Categorías',
            'categorÃƒÂ­as' => 'categorías',
            'acciÃƒÂ³n' => 'acción',
            // Símbolos
            'Ã¢â‚¬Â¦' => '…',
            'Ã¢â‚¬Å“' => '“',
            'Ã¢â‚¬Â�' => '”',
            'Ã¢â‚¬â€œ' => '–',
            'Ã¢â‚¬â€�' => '—',
            'ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬' => '€',
            'ÃƒÂ¢Ã¢â‚¬Â°Ã‹â€ ' => '≈',
            // Acentos comunes
            'Ã¡' => 'á', 'Ã©' => 'é', 'Ã­' => 'í', 'Ã³' => 'ó', 'Ãº' => 'ú',
            'Ã' => 'Á', 'Ã‰' => 'É', 'Ã' => 'Í', 'Ã“' => 'Ó', 'Ãš' => 'Ú',
            'Ã±' => 'ñ', 'Ã‘' => 'Ñ',
        );

        $fixed = strtr($text, $map);

        if (function_exists('mb_convert_encoding') && preg_match('/[\x{00C3}\x{00C2}\x{00E2}]/u', $fixed)) {
            $try = @mb_convert_encoding($fixed, 'UTF-8', 'Windows-1252');
            if (is_string($try) && $try !== '') {
                $fixed = $try;
            }
        }

        return $fixed;
    }
}

if (!class_exists('CBIA_Encoding')) {
    class CBIA_Encoding {
        public static function fix_mojibake($text) {
            return cbia_fix_mojibake($text);
        }
    }
}
