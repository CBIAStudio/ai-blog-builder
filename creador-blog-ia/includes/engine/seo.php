<?php
/**
 * SEO helpers.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_strip_document_wrappers')) {
	function cbia_strip_document_wrappers($html) {
		$html = (string)$html;
		if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) $html = $m[1];
		$html = preg_replace('/<!DOCTYPE.*?>/is', '', $html);
		$html = preg_replace('/<\/?(html|head|body|meta|title|script|style)[^>]*>/is', '', $html);
		return trim($html);
	}
}

if (!function_exists('cbia_strip_h1_to_h2')) {
	function cbia_strip_h1_to_h2($html) {
		$html = preg_replace('/<h1\b([^>]*)>/i', '<h2$1>', (string)$html);
		$html = preg_replace('/<\/h1>/i', '</h2>', (string)$html);
		return $html;
	}
}

if (!function_exists('cbia_first_paragraph_text')) {
	function cbia_first_paragraph_text($html) {
		$html = (string)$html;
		if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) return wp_strip_all_tags($m[1]);
		return wp_strip_all_tags($html);
	}
}

if (!function_exists('cbia_generate_focus_keyphrase')) {
	function cbia_generate_focus_keyphrase($title, $content) {
		$words = preg_split('/\s+/', wp_strip_all_tags((string)$title));
		$words = array_values(array_filter(array_map('trim', (array)$words)));
		return trim(implode(' ', array_slice($words, 0, 4)));
	}
}

if (!function_exists('cbia_generate_meta_description')) {
	function cbia_generate_meta_description($title, $content) {
		$clean = cbia_strip_document_wrappers((string)$content);
		$base = cbia_first_paragraph_text($clean);
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
