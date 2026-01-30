<?php
/**
 * Post creation pipeline.
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
   ============== CREAR POST (WP) + METAS/SEO ===============
   ========================================================= */

if (!function_exists('cbia_post_exists_by_title')) {
	function cbia_post_exists_by_title($title) {
		global $wpdb;
		$title = (string)$title;
		$found = $wpdb->get_var($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_title=%s AND post_status IN ('publish','future','draft','pending','private') LIMIT 1",
			$title
		));
		return !empty($found);
	}
}

if (!function_exists('cbia_create_post_in_wp_engine')) {
	/**
	 * Crea el post y asigna:
	 * - featured (si se pasa)
	 * - yoast metadesc + focuskw (básico)
	 * - categorías y tags (reglas plugin)
	 */
	function cbia_create_post_in_wp_engine($title, $final_html, $featured_attach_id, $post_date_mysql) {
		$s = cbia_get_settings();

		$final_html = cbia_strip_document_wrappers($final_html);
		$final_html = cbia_strip_h1_to_h2($final_html);

		$postarr = [
			'post_type'    => 'post',
			'post_title'   => $title,
			'post_content' => $final_html,
			'post_author'  => cbia_pick_post_author_id(),
		];

		if ($post_date_mysql) {
			$postarr['post_status']   = 'future';
			$postarr['post_date']     = $post_date_mysql;
			$postarr['post_date_gmt'] = get_gmt_from_date($post_date_mysql);
		} else {
			$postarr['post_status'] = 'publish';
		}

		$post_id = wp_insert_post($postarr, true);
		if (is_wp_error($post_id) || !$post_id) {
			$err = is_wp_error($post_id) ? $post_id->get_error_message() : 'wp_insert_post_fallo';
			return [false, 0, $err];
		}

		$post_id = (int)$post_id;

		// Categorías
		$cats = cbia_determine_categories_by_mapping($title, $final_html);
		if (empty($cats)) {
			$default_cat = trim((string)($s['default_category'] ?? 'Noticias'));
			if ($default_cat !== '') $cats = [$default_cat];
		}

		$cat_ids = [];
		foreach ($cats as $c) {
			$id = cbia_ensure_category_exists($c);
			if ($id) $cat_ids[] = $id;
		}
		if (!empty($cat_ids)) {
			wp_set_post_categories($post_id, $cat_ids, false);
		}

		// Tags (solo permitidas)
		$tags = cbia_pick_tags_from_content_allowed($title, $final_html, 7);
		if (!empty($tags)) {
			wp_set_post_tags($post_id, $tags, false);
		}

		// Featured
		if ($featured_attach_id) {
			set_post_thumbnail($post_id, (int)$featured_attach_id);
		}

		// Yoast básico (luego en módulo Yoast se mejora con hook)
		$metad = cbia_generate_meta_description($title, $final_html);
		$focus = cbia_generate_focus_keyphrase($title, $final_html);
		update_post_meta($post_id, '_yoast_wpseo_metadesc', $metad);
		update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus);

		// Marcador plugin
		update_post_meta($post_id, '_cbia_created', '1');
		update_post_meta($post_id, '_cbia_created_at', current_time('mysql'));

		return [true, $post_id, ''];
	}
}

/* =========================================================
   =============== MAIN: CREATE SINGLE BLOG POST ============
   ========================================================= */

if (!function_exists('cbia_create_single_blog_post')) {
	/**
	 * Devuelve array:
	 * ['ok'=>bool,'post_id'=>int,'error'=>string]
	 */
	function cbia_create_single_blog_post($title, $post_date_mysql = '') {
		cbia_try_unlimited_runtime();
		$title = trim((string)$title);
		if ($title === '') return ['ok'=>false,'post_id'=>0,'error'=>'Título vacío'];

		if (cbia_is_stop_requested()) {
			return ['ok'=>false,'post_id'=>0,'error'=>'STOP activado'];
		}

		if (cbia_post_exists_by_title($title)) {
			cbia_log("El post '{$title}' ya existe. Omitido.", 'INFO');
			return ['ok'=>false,'post_id'=>0,'error'=>'Ya existe'];
		}

		$s = cbia_get_settings();
		$images_limit = (int)($s['images_limit'] ?? 3);
		if ($images_limit < 1) $images_limit = 1;
		if ($images_limit > 4) $images_limit = 4;

		// Tracking para costes reales (texto + imágenes)
		$image_calls = array();
		$text_call = array();

		// 1) Prompt
		$prompt = cbia_build_prompt_for_title($title);

		// 2) OpenAI texto (6 valores)
		list($ok, $text_html, $usage, $model_used, $err, $raw) = cbia_openai_responses_call($prompt, $title, 2);
		$text_call = array(
			'context' => 'blog_text',
			'model'   => (string)$model_used,
			'usage'   => is_array($usage) ? $usage : cbia_usage_empty(),
		);
		if (!$ok) {
			cbia_log("Fallo texto '{$title}': " . ($err ?: 'desconocido'), 'ERROR');
			// Si OpenAI devolvió usage pero no hay post, deja rastro en el log de costes.
			if (function_exists('cbia_costes_log')) {
				$uin  = (int)($usage['input_tokens'] ?? 0);
				$uout = (int)($usage['output_tokens'] ?? 0);
				$umod = (string)($model_used ?? '');
				if ($uin > 0 || $uout > 0) {
					cbia_costes_log("Uso sin post (fallo texto) title='{$title}' model={$umod} in={$uin} out={$uout} err=" . (string)($err ?: ''));
				}
			}
			return ['ok'=>false,'post_id'=>0,'error'=>$err ?: 'Fallo texto'];
		}

		$text_html = cbia_strip_document_wrappers($text_html);
		$text_html = cbia_strip_h1_to_h2($text_html);

		// Corrige encabezados escritos como [h2]...[/h2] / [h3]...[/h3] a HTML real
		$text_html = cbia_fix_bracket_headings($text_html);
		// Normaliza el título de FAQ según idioma/config
		$text_html = cbia_normalize_faq_heading($text_html);
		cbia_log("Texto IA OK: generado HTML para '{$title}'", 'INFO');

        // 3) Procesar marcadores de imagen
        $internal_limit = max(0, $images_limit - 1);
        if (function_exists('cbia_normalize_image_markers')) {
            $text_html = cbia_normalize_image_markers($text_html);
        }
        $markers = cbia_extract_image_markers($text_html);
		if (!empty($markers)) $markers = array_slice($markers, 0, $internal_limit);

		// Si hay menos marcadores que el límite interno, autoinserta los que falten
		if (count($markers) < $internal_limit) {
			$text_html = cbia_force_insert_markers($text_html, $title, $internal_limit);
			$markers = cbia_extract_image_markers($text_html);
			if (!empty($markers)) $markers = array_slice($markers, 0, $internal_limit);
		}

		$pending_list = [];
		$featured_attach_id = 0;

		foreach ($markers as $i => $mk) {
			if (cbia_is_stop_requested()) {
				cbia_log("STOP durante imágenes en '{$title}'.", 'INFO');
				break;
			}

			$desc = trim((string)($mk['desc'] ?? ''));
			$section = cbia_detect_marker_section($text_html, (int)$mk['pos'], $i === 0);

			list($img_ok, $attach_id, $img_model, $img_err) = cbia_generate_image_openai($desc, $section, $title);
			$image_calls[] = [
				'context' => 'blog_image',
				'section' => $section,
				'model'   => (string)$img_model,
				'ok'      => $img_ok ? 1 : 0,
				'error'   => (string)($img_err ?: ''),
				'attach_id' => (int)$attach_id,
			];

			if ($img_ok && $attach_id) {
				$url = wp_get_attachment_url((int)$attach_id);
				$alt = cbia_build_img_alt($title, $section, $desc);
				$img_tag = cbia_build_content_img_tag($url, $alt, $section);

				$text_html = cbia_replace_first_occurrence($text_html, $mk['full'], $img_tag);
				cbia_log("Imagen insertada en contenido: secciÃ³n={$section}", 'INFO');
			} else {
				$desc_clean = cbia_sanitize_alt_from_desc($desc);
				$pending_list[] = [
					'desc' => $desc_clean,
					'section' => $section,
					'model' => (string)$img_model,
					'status' => 'pending',
					'tries' => 0,
					'last_error' => (string)($img_err ?: ''),
					'attach_id' => 0,
				];
				$placeholder = "<span class='cbia-img-pendiente' style='display:none'>[IMAGEN_PENDIENTE: {$desc_clean}]</span>";
				$text_html = cbia_replace_first_occurrence($text_html, $mk['full'], $placeholder);
				cbia_log("Imagen pendiente en contenido: secciÃ³n={$section} err=" . ($img_err ?: 'unknown'), 'WARN');
			}
		}

		// Si no hay featured (porque no hay markers o fallaron), intenta crear destacada manual
		if (!$featured_attach_id) {
			$desc0 = $title;
			list($ok, $attach_id, $m, $e) = cbia_generate_image_openai($desc0, 'intro', $title);
			$image_calls[] = [
				'context' => 'blog_image',
				'section' => 'intro',
				'model'   => (string)$m,
				'ok'      => $ok ? 1 : 0,
				'error'   => (string)($e ?: ''),
				'attach_id' => (int)$attach_id,
			];
			if ($ok && $attach_id) {
				$featured_attach_id = (int)$attach_id;
				cbia_log("Imagen destacada OK: attach_id={$featured_attach_id}", 'INFO');
			} else {
				// no ponemos placeholder aquí porque es destacada, no va en contenido
				cbia_log("No se pudo generar destacada para '{$title}': " . ($e ?: ''), 'ERROR');
			}
		}

		// Limpieza de artefactos antes de guardar
        $text_html = cbia_cleanup_post_html($text_html);

		// Crear post en WP
		list($ok_post, $post_id, $post_err) = cbia_create_post_in_wp_engine($title, $text_html, $featured_attach_id, $post_date_mysql);
		if (!$ok_post) {
			cbia_log("No se pudo crear post '{$title}': {$post_err}", 'ERROR');
			return ['ok'=>false,'post_id'=>0,'error'=>$post_err ?: 'Fallo insert'];
		}

		// Guardar lista de pendientes
		if (!empty($pending_list)) {
			update_post_meta($post_id, '_cbia_pending_images_list', wp_json_encode($pending_list));
			update_post_meta($post_id, '_cbia_pending_images', (string)count($pending_list));
		} else {
			update_post_meta($post_id, '_cbia_pending_images', '0');
		}

		// Guardar uso real (texto + imágenes) en sistema de costes
		if (function_exists('cbia_costes_record_usage')) {
			// Texto
			cbia_costes_record_usage($post_id, array(
				'type' => 'text',
				'model' => (string)($text_call['model'] ?? ''),
				'input_tokens' => (int)($text_call['usage']['input_tokens'] ?? 0),
				'output_tokens' => (int)($text_call['usage']['output_tokens'] ?? 0),
				'cached_input_tokens' => 0,
				'ok' => 1,
			));
			// Imágenes
			foreach ($image_calls as $ic) {
				cbia_costes_record_usage($post_id, array(
					'type' => 'image',
					'model' => (string)($ic['model'] ?? ''),
					'input_tokens' => 0,
					'output_tokens' => 0,
					'cached_input_tokens' => 0,
					'ok' => !empty($ic['ok']) ? 1 : 0,
					'error' => (string)($ic['error'] ?? ''),
				));
			}
		}

		// Hook final
		do_action('cbia_after_post_created', $post_id);

		// Registro en uso legacy (no eliminar por compatibilidad)
		cbia_usage_append_call($post_id, 'blog_text', (string)$text_call['model'], (array)$text_call['usage'], [
			'ok' => 1,
			'err'=> '',
		]);
		foreach ($image_calls as $ic) {
			cbia_image_append_call($post_id, (string)($ic['section'] ?? ''), (string)($ic['model'] ?? ''), !empty($ic['ok']), (int)($ic['attach_id'] ?? 0), (string)($ic['error'] ?? ''));
		}

		cbia_log("Post creado OK: ID {$post_id} | '{$title}'", 'INFO');

		return ['ok'=>true,'post_id'=>(int)$post_id,'error'=>''];
	}
}
