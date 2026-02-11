<?php
/**
 * Encoding helpers.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_mojibake_score')) {
    function cbia_mojibake_score(string $text): int {
        $score = 0;
        $patterns = array(
            '/Ãƒ./u',
            '/Ã‚./u',
            '/Ã¢..?/u',
            '/Ã°Å¸/u',
            '/ï¿½/u',
        );
        foreach ($patterns as $rx) {
            if (preg_match_all($rx, $text, $m)) {
                $score += (int)count($m[0]);
            }
        }
        return $score;
    }
}

if (!function_exists('cbia_fix_mojibake')) {
    function cbia_fix_mojibake($text) {
        $text = (string)$text;
        if ($text === '') return $text;

        $candidates = array($text);
        if (function_exists('mb_convert_encoding')) {
            $try1252 = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            if (is_string($try1252) && $try1252 !== '') $candidates[] = $try1252;

            $tryLatin1 = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
            if (is_string($tryLatin1) && $tryLatin1 !== '') $candidates[] = $tryLatin1;

            if (!empty($try1252) && is_string($try1252)) {
                $tryDouble = @mb_convert_encoding($try1252, 'UTF-8', 'Windows-1252');
                if (is_string($tryDouble) && $tryDouble !== '') $candidates[] = $tryDouble;
            }
        }

        $best = $text;
        $bestScore = cbia_mojibake_score($best);
        foreach ($candidates as $cand) {
            $score = cbia_mojibake_score((string)$cand);
            if ($score < $bestScore) {
                $best = (string)$cand;
                $bestScore = $score;
            }
        }

        $best = strtr($best, array(
            'Ã¢â‚¬Â¦' => '...',
            'Ã¢â‚¬Å“' => '"',
            'Ã¢â‚¬Â' => '"',
            'Ã¢â‚¬Ëœ' => "'",
            'Ã¢â‚¬â„¢' => "'",
            'Ã¢â‚¬â€œ' => '-',
            'Ã¢â‚¬â€' => '-',
            'Ã‚Â '  => ' ',
        ));

        if (function_exists('iconv')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $best);
            if (is_string($clean) && $clean !== '') $best = $clean;
        }

        return $best;
    }
}

if (!class_exists('CBIA_Encoding')) {
    class CBIA_Encoding {
        public static function fix_mojibake($text) {
            return cbia_fix_mojibake($text);
        }
    }
}

