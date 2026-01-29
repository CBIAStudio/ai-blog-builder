<?php
/**
 * Categories and tags helpers.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_normalize_for_match')) {
	function cbia_normalize_for_match($str) {
		$str = remove_accents((string)$str);
		$str = mb_strtolower($str);
		return $str;
	}
}

if (!function_exists('cbia_slugify')) {
	function cbia_slugify($text) {
		$text = remove_accents((string)$text);
		$text = strtolower($text);
		$text = preg_replace('/[^a-z0-9]+/', '-', $text);
		$text = preg_replace('/-+/', '-', $text);
		return trim(mb_substr($text, 0, 190), '-');
	}
}

if (!function_exists('cbia_ensure_category_exists')) {
	function cbia_ensure_category_exists($cat_name) {
		$cat_name = trim((string)$cat_name);
		if ($cat_name === '') return 0;

		$existing = term_exists($cat_name, 'category');
		if ($existing) return is_array($existing) ? (int)$existing['term_id'] : (int)$existing;

		$slug = cbia_slugify($cat_name);
		if ($slug === '') $slug = 'cat-' . wp_generate_password(6, false);

		$created = wp_insert_term(mb_substr($cat_name, 0, 180), 'category', ['slug' => $slug]);
		if (is_wp_error($created)) {
			cbia_log("Error creando categoría '{$cat_name}': " . $created->get_error_message(), 'ERROR');
			return 0;
		}
		return (int)$created['term_id'];
	}
}

if (!function_exists('cbia_determine_categories_by_mapping')) {
	function cbia_determine_categories_by_mapping($title, $content_html) {
		$s = cbia_get_settings();
		$mapping = (string)($s['keywords_to_categories'] ?? '');
		$lines = array_filter(array_map('trim', explode("\n", $mapping)));

		$norm_title = cbia_normalize_for_match($title);
		$norm_content = cbia_normalize_for_match(wp_strip_all_tags(mb_substr((string)$content_html, 0, 4000)));

		$found = [];
		foreach ($lines as $line) {
			$parts = explode(':', $line, 2);
			if (count($parts) !== 2) continue;

			$cat = trim($parts[0]);
			$keywords = array_filter(array_map('trim', explode(',', $parts[1])));

			$matched = false;
			foreach ($keywords as $kw) {
				$kw_norm = preg_quote(cbia_normalize_for_match($kw), '/');
				$pattern = '/(?<![a-z0-9])' . $kw_norm . '(?![a-z0-9])/i';
				if (preg_match($pattern, $norm_title) || preg_match($pattern, $norm_content)) {
					$matched = true;
					break;
				}
			}

			if ($matched && $cat !== '') $found[] = $cat;
		}

		$found = array_values(array_unique($found));
		return array_slice($found, 0, 3);
	}
}

if (!function_exists('cbia_get_allowed_tags_list')) {
	function cbia_get_allowed_tags_list() {
		$s = cbia_get_settings();
		$tags_string = (string)($s['default_tags'] ?? '');
		$arr = array_filter(array_map('trim', explode(',', $tags_string)));
		$arr = array_values(array_unique($arr));
		return array_slice($arr, 0, 50); // lista permitida (luego asignamos máx 7)
	}
}

if (!function_exists('cbia_pick_tags_from_content_allowed')) {
	function cbia_pick_tags_from_content_allowed($title, $content_html, $max = 7) {
		$allowed = cbia_get_allowed_tags_list();
		if (empty($allowed)) return [];

		$hay = cbia_normalize_for_match($title . ' ' . wp_strip_all_tags((string)$content_html));
		$matched = [];

		foreach ($allowed as $tag) {
			$tn = cbia_normalize_for_match($tag);
			if ($tn === '') continue;
			// match simple por substring
			if (mb_strpos($hay, $tn) !== false) {
				$matched[] = $tag;
			}
			if (count($matched) >= $max) break;
		}

		// fallback si no matchea: primeras (pero máximo 7)
		if (empty($matched)) {
			$matched = array_slice($allowed, 0, $max);
		}

		return array_slice(array_values(array_unique($matched)), 0, $max);
	}
}
