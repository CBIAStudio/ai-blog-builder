<?php
/**
 * Pending images processing.
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
   =========== RELLENAR IMÃGENES PENDIENTES (POST) ==========
   ========================================================= */

if (!function_exists('cbia_fill_pending_images_for_post')) {
	function cbia_fill_pending_images_for_post($post_id, $max_images = 4) {
		$post_id = (int)$post_id;
		if ($post_id <= 0) return 0;

		$post = get_post($post_id);
		if (!$post) return 0;

		$html = (string)$post->post_content;
		$pending = cbia_extract_pending_markers($html);
		if (empty($pending)) return 0;

		$list_raw = get_post_meta($post_id, '_cbia_pending_images_list', true);
		$list = [];
		if ($list_raw) {
			$tmp = json_decode((string)$list_raw, true);
			if (is_array($tmp)) $list = $tmp;
		}

		$img_descs = function_exists('cbia_get_post_image_descs')
			? cbia_get_post_image_descs($post_id)
			: array('featured' => array('desc' => '', 'section' => 'intro', 'attach_id' => 0), 'internal' => array());

		$title = get_the_title($post_id);
		$filled = 0;

		// Destacada si no hay thumbnail y hay pendientes
		if (!has_post_thumbnail($post_id) && !empty($pending)) {
			$desc0 = (string)($img_descs['featured']['desc'] ?? '');
			if ($desc0 === '') $desc0 = (string)($pending[0]['desc'] ?? $title);
			if ($desc0 === '') $desc0 = $title;

			$prompt = cbia_build_image_prompt_for_post($post_id, 'featured', $desc0, 0);
			$alt = cbia_sanitize_alt_from_desc($desc0);
			if ($alt === '') $alt = cbia_sanitize_alt_from_desc($title);

			list($ok, $attach_id, $m, $e) = cbia_generate_image_openai_with_prompt($prompt, 'intro', $title, $alt, 0);

			if ($ok && $attach_id) {
				set_post_thumbnail($post_id, (int)$attach_id);
				$img_descs['featured']['desc'] = $desc0;
				$img_descs['featured']['section'] = 'intro';
				$img_descs['featured']['attach_id'] = (int)$attach_id;
				cbia_log(sprintf('Pendientes: destacada creada en post %d (attach_id=%d)', (int)$post_id, (int)$attach_id), 'INFO');
				cbia_image_append_call($post_id, 'intro', $m, true, (int)$attach_id, '');
			} else {
				cbia_log('Pendientes: fallo destacada post ' . (int)$post_id . ': ' . ($e ?: ''), 'ERROR');
				cbia_image_append_call($post_id, 'intro', $m, false, 0, (string)($e ?: ''));
			}
		}

		foreach ($pending as $pk => $pm) {
			if ($filled >= $max_images) break;

			$desc = (string)$pm['desc'];
			$short_desc = cbia_sanitize_image_short_desc($desc);
			if ($short_desc === '') {
				$short_desc = $title;
				cbia_log('Pendientes: SHORT_DESC vacio, usando titulo', 'INFO');
			}

			$idx = function_exists('cbia_find_internal_index_by_desc')
				? cbia_find_internal_index_by_desc((array)($img_descs['internal'] ?? array()), $short_desc)
				: 0;

			$section = 'body';
			if ($idx > 0 && !empty($img_descs['internal'][$idx - 1]['section'])) {
				$section = (string)$img_descs['internal'][$idx - 1]['section'];
			} else {
				$current_pos = strpos($html, $pm['full']);
				$section = ($current_pos !== false) ? cbia_detect_marker_section($html, (int)$current_pos, false) : 'body';
			}

			$prompt = cbia_build_image_prompt_for_post($post_id, 'internal', $short_desc, $idx ?: ($pk + 1));
			$alt = cbia_sanitize_alt_from_desc($short_desc);
			if ($alt === '') $alt = cbia_sanitize_alt_from_desc($title);

			$use_idx = $idx ?: ($pk + 1);
			list($ok, $attach_id, $m, $e) = cbia_generate_image_openai_with_prompt($prompt, $section, $title, $alt, $use_idx);
			if ($ok && $attach_id) {
				// Registrar usage de imagen en costes
				if (function_exists('cbia_costes_record_usage')) {
					cbia_costes_record_usage($post_id, array(
						'type' => 'image',
						'model' => (string)$m,
						'input_tokens' => 0,
						'output_tokens' => 0,
						'cached_input_tokens' => 0,
						'ok' => 1,
						'error' => '',
					));
				}

				$url = wp_get_attachment_url((int)$attach_id);
				$img_tag = cbia_build_content_img_tag_with_meta($url, $alt, $section, (int)$attach_id, $use_idx);
				cbia_replace_pending_marker($html, $pm['full'], $img_tag);
				$filled++;

				// actualiza descs
				if ($idx > 0 && isset($img_descs['internal'][$idx - 1])) {
					$img_descs['internal'][$idx - 1]['desc'] = $short_desc;
					$img_descs['internal'][$idx - 1]['section'] = (string)$section;
					$img_descs['internal'][$idx - 1]['attach_id'] = (int)$attach_id;
				} else {
					$img_descs['internal'][] = array(
						'desc' => $short_desc,
						'section' => (string)$section,
						'attach_id' => (int)$attach_id,
					);
				}

				// marca en lista
				foreach ($list as &$it) {
					if (!is_array($it)) continue;
					if (($it['desc'] ?? '') === cbia_sanitize_alt_from_desc($desc) && ($it['status'] ?? '') === 'pending') {
						$it['status'] = 'done';
						$it['attach_id'] = (int)$attach_id;
						$it['last_error'] = '';
						break;
					}
				}
				unset($it);

				cbia_log(sprintf('Pendientes: imagen insertada post %d attach_id=%d', (int)$post_id, (int)$attach_id), 'INFO');
				cbia_image_append_call($post_id, $section, $m, true, (int)$attach_id, '');
			} else {
				// Registrar usage de imagen en costes (error)
				if (function_exists('cbia_costes_record_usage')) {
					cbia_costes_record_usage($post_id, array(
						'type' => 'image',
						'model' => (string)$m,
						'input_tokens' => 0,
						'output_tokens' => 0,
						'cached_input_tokens' => 0,
						'ok' => 0,
						'error' => (string)($e ?: ''),
					));
				}
				// incrementa tries en lista
				foreach ($list as &$it) {
					if (!is_array($it)) continue;
					if (($it['desc'] ?? '') === cbia_sanitize_alt_from_desc($desc) && ($it['status'] ?? '') === 'pending') {
						$it['tries'] = (int)($it['tries'] ?? 0) + 1;
						$it['last_error'] = $e ?: 'unknown';
						break;
					}
				}
				unset($it);

				cbia_log('Pendientes: fallo generando imagen post ' . (int)$post_id . ': ' . ($e ?: ''), 'ERROR');
				cbia_image_append_call($post_id, $section, $m, false, 0, (string)($e ?: ''));
			}
		}

		if ($filled > 0) {
			// Limpieza de artefactos antes de guardar
			$html = cbia_cleanup_post_html($html);
			wp_update_post(['ID' => $post_id, 'post_content' => $html]);
		}

		$left = cbia_extract_pending_markers($html);
		$left_count = count($left);
		update_post_meta($post_id, '_cbia_pending_images', (string)$left_count);
		update_post_meta($post_id, '_cbia_pending_images_list', wp_json_encode($list));
		cbia_set_post_image_descs($post_id, $img_descs);

		cbia_log(sprintf('Pendientes: post %d rellenadas=%d restantes=%d', (int)$post_id, (int)$filled, (int)$left_count), 'INFO');

		return $filled;
	}
}

if (!function_exists('cbia_run_fill_pending_images')) {
	function cbia_run_fill_pending_images($limit_posts = 10) {
		cbia_try_unlimited_runtime();
		$limit_posts = max(1, (int)$limit_posts);

		cbia_log(sprintf('Rellenar pendientes: buscando posts (limit=%d)', (int)$limit_posts), 'INFO');

		$q = new WP_Query([
			'post_type'      => 'post',
			'posts_per_page' => $limit_posts,
			'post_status'    => ['publish','future','draft','pending','private'],
			'meta_key'       => '_cbia_pending_images',
			'meta_value'     => 0,
			'meta_compare'   => '>',
			'meta_type'      => 'NUMERIC',
			'orderby' => 'date',
			'order'   => 'DESC',
			'fields'  => 'ids',
		]);

		if (empty($q->posts)) {
			cbia_log('Rellenar pendientes: no hay posts con pendientes.', 'INFO');
			return 0;
		}

		$total_filled = 0;
		foreach ($q->posts as $pid) {
			if (cbia_is_stop_requested()) break;
			$pend = (int)get_post_meta((int)$pid, '_cbia_pending_images', true);
			$created = (string)get_post_meta((int)$pid, '_cbia_created', true) === '1';
			if (!$created) continue;
			cbia_log(sprintf('Rellenar pendientes: post %d pendientes=%d', (int)$pid, (int)$pend), 'INFO');
			$total_filled += (int)cbia_fill_pending_images_for_post((int)$pid, 4);
		}

		wp_reset_postdata();
		cbia_log(sprintf('Rellenar pendientes: finalizado total_rellenadas=%d', (int)$total_filled), 'INFO');

		return $total_filled;
	}
}

