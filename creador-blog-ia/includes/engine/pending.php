<?php
/**
 * Pending images processing.
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
   =========== RELLENAR IMÁGENES PENDIENTES (POST) ==========
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

		$title = get_the_title($post_id);
		$filled = 0;

		// Destacada si no hay thumbnail y hay pendientes
		if (!has_post_thumbnail($post_id) && !empty($pending)) {
			$desc0 = cbia_sanitize_alt_from_desc($pending[0]['desc'] ?? $title);
			list($ok, $attach_id, $m, $e) = cbia_generate_image_openai($desc0, 'intro', $title);

			if ($ok && $attach_id) {
				set_post_thumbnail($post_id, (int)$attach_id);
				cbia_log("Pendientes: destacada creada en post {$post_id} (attach_id={$attach_id})", 'INFO');
				cbia_image_append_call($post_id, 'intro', $m, true, (int)$attach_id, '');
			} else {
				cbia_log("Pendientes: fallo destacada post {$post_id}: " . ($e ?: ''), 'ERROR');
				cbia_image_append_call($post_id, 'intro', $m, false, 0, (string)($e ?: ''));
			}
		}

		// Función interna para reemplazar un token pendiente concreto
		$replace_pending = function(&$html, $pending_token, $replacement) {
			$token = (string)$pending_token;
			$pattern = '/<span[^>]*class=("|\')cbia-img-pendiente\1[^>]*>\s*' . preg_quote($token, '/') . '\s*<\/span>/iu';
			$count = 0;
			$new = preg_replace($pattern, (string)$replacement, (string)$html, 1, $count);
			if ($count > 0 && $new !== null) {
				$html = $new;
			}
		};

		foreach ($pending as $pk => $pm) {
			if ($filled >= $max_images) break;

			$desc = (string)$pm['desc'];
			$current_pos = strpos($html, $pm['full']);
			$section = ($current_pos !== false) ? cbia_detect_marker_section($html, (int)$current_pos, false) : 'body';

			list($ok, $attach_id, $m, $e) = cbia_generate_image_openai($desc, $section, $title);
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
				$alt = cbia_build_img_alt($title, $section, $desc);
				$img_tag = cbia_build_content_img_tag($url, $alt, $section);
				$replace_pending($html, $pm['full'], $img_tag);
				$filled++;

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

				cbia_log("Pendientes: imagen insertada post {$post_id} attach_id={$attach_id}", 'INFO');
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

				cbia_log("Pendientes: fallo generando imagen post {$post_id}: " . ($e ?: ''), 'ERROR');
				cbia_image_append_call($post_id, $section, $m, false, 0, (string)($e ?: ''));
			}
		}

		if ($filled > 0) {
			// Limpieza de artefactos antes de guardar
			cbia_cleanup_post_html($html);
			wp_update_post(['ID' => $post_id, 'post_content' => $html]);
		}

		$left = cbia_extract_pending_markers($html);
		$left_count = count($left);
		update_post_meta($post_id, '_cbia_pending_images', (string)$left_count);
		update_post_meta($post_id, '_cbia_pending_images_list', wp_json_encode($list));

		cbia_log("Pendientes: post {$post_id} rellenadas={$filled} restantes={$left_count}", 'INFO');

		return $filled;
	}
}

if (!function_exists('cbia_run_fill_pending_images')) {
	function cbia_run_fill_pending_images($limit_posts = 10) {
		cbia_try_unlimited_runtime();
		$limit_posts = max(1, (int)$limit_posts);

		cbia_log("Rellenar pendientes: buscando posts (limit={$limit_posts})", 'INFO');

		$q = new WP_Query([
			'post_type'      => 'post',
			'posts_per_page' => $limit_posts,
			'post_status'    => ['publish','future','draft','pending','private'],
			'meta_query'     => [
				[
					'key'     => '_cbia_created',
					'value'   => '1',
					'compare' => '=',
				],
				[
					'key'     => '_cbia_pending_images',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				],
			],
			'orderby' => 'date',
			'order'   => 'DESC',
			'fields'  => 'ids',
		]);

		if (empty($q->posts)) {
			cbia_log("Rellenar pendientes: no hay posts con pendientes.", 'INFO');
			return 0;
		}

		$total_filled = 0;
		foreach ($q->posts as $pid) {
			if (cbia_is_stop_requested()) break;
			$pend = (int)get_post_meta((int)$pid, '_cbia_pending_images', true);
			cbia_log("Rellenar pendientes: post {$pid} pendientes={$pend}", 'INFO');
			$total_filled += (int)cbia_fill_pending_images_for_post((int)$pid, 4);
		}

		wp_reset_postdata();
		cbia_log("Rellenar pendientes: finalizado total_rellenadas={$total_filled}", 'INFO');

		return $total_filled;
	}
}
