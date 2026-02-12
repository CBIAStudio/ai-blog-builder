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
		$title = (string)$title;
		$normalized = trim(preg_replace('/\s+/', ' ', $title));
		$slug = sanitize_title($normalized);

		$existing = get_page_by_title($title, OBJECT, 'post');
		if ($existing instanceof WP_Post) return true;

		if ($normalized !== '' && $normalized !== $title) {
			$existing_norm = get_page_by_title($normalized, OBJECT, 'post');
			if ($existing_norm instanceof WP_Post) return true;
		}

		$by_slug = get_posts(array(
			'name'           => $slug,
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		));
		return !empty($by_slug);
	}
}

if (!function_exists('cbia_create_post_in_wp_engine')) {
	/**
	 * Crea el post y asigna:
	 * - featured (si se pasa)
	 * - yoast metadesc + focuskw (básico)
	 * - categorías y tags (reglas plugin)
	 */
	function cbia_create_post_in_wp_engine($title, $final_html, $featured_attach_id, $post_date_mysql, $force_status = '') {
		$s = cbia_get_settings();

		$final_html = cbia_strip_document_wrappers($final_html);
		$final_html = cbia_strip_h1_to_h2($final_html);

		$postarr = [
			'post_type'    => 'post',
			'post_title'   => $title,
			'post_content' => $final_html,
			'post_author'  => cbia_pick_post_author_id(),
		];

		if ($force_status === 'draft') {
			$postarr['post_status'] = 'draft';
		} elseif ($force_status === 'publish') {
			$postarr['post_status'] = 'publish';
		} elseif ($force_status === 'future') {
			if (!$post_date_mysql) {
				return [false, 0, 'post_date_missing'];
			}
			$postarr['post_status']   = 'future';
			$postarr['post_date']     = $post_date_mysql;
			$postarr['post_date_gmt'] = get_gmt_from_date($post_date_mysql);
		} elseif ($post_date_mysql) {
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
	function cbia_create_single_blog_post($title, $post_date_mysql = '', $force_status = '') {
		cbia_try_unlimited_runtime();
		$title = trim((string)$title);
		if ($title === '') return ['ok'=>false,'post_id'=>0,'error'=>'Título vacío'];

		if (cbia_is_stop_requested()) {
			return ['ok'=>false,'post_id'=>0,'error'=>'STOP activado'];
		}

		if (cbia_post_exists_by_title($title)) {
			cbia_log(sprintf("El post '%s' ya existe. Omitido.", (string)$title), 'INFO');
			return ['ok'=>false,'post_id'=>0,'error'=>'Ya existe'];
		}

		$s = cbia_get_settings();
		// Normal: only featured image (no in-content)
		$images_limit = 1;

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
		// Si Yoast FAQ Block está disponible, convierte FAQs a bloque
		if (function_exists('cbia_convert_faq_to_yoast_block')) {
			list($text_html, $faq_block_ok, $faq_block_status) = cbia_convert_faq_to_yoast_block($text_html);
			if ($faq_block_ok) {
				cbia_log('FAQ Yoast: bloque insertado correctamente', 'INFO');
			} elseif (!empty($faq_block_status)) {
				cbia_log('FAQ Yoast: ' . (string)$faq_block_status, 'INFO');
			}
		}
		cbia_log("Texto IA OK: generado HTML para '{$title}'", 'INFO');
        // 3) Sin imagenes internas: limpiar cualquier marcador y seguir.
        if (function_exists('cbia_normalize_image_markers')) {
            $text_html = cbia_normalize_image_markers($text_html);
        }
        $markers_all = function_exists('cbia_extract_image_markers') ? cbia_extract_image_markers($text_html) : [];
        if (!empty($markers_all)) {
            foreach ($markers_all as $mk) {
                $text_html = cbia_remove_marker_from_html($text_html, $mk['full']);
            }
            $text_html = cbia_cleanup_post_html($text_html);
        }

        $pending_list = [];
        $featured_attach_id = 0;
        $img_descs = array(
            'featured' => array('desc' => '', 'section' => 'intro', 'attach_id' => 0),
            'internal' => array(),
        );

        $GLOBALS['cbia_current_post_title_for_prompt'] = $title;

        $skip_images = !empty($GLOBALS['cbia_preview_skip_images']);
        if ($skip_images) {
            cbia_log("Preview: saltando generacion de imagen destacada para '{$title}'.", 'INFO');
        } else {
            // Destacada siempre
            $featured_desc = $title;
            $img_descs['featured'] = array(
                'desc' => $featured_desc,
                'section' => 'intro',
                'attach_id' => 0,
            );
            $prompt_featured = cbia_build_image_prompt_for_post(0, 'featured', $featured_desc, 0);
            $alt_featured = cbia_sanitize_alt_from_desc($featured_desc);
            if ($alt_featured === '') $alt_featured = cbia_sanitize_alt_from_desc($title);

            list($ok, $attach_id, $m, $e) = cbia_generate_image_openai_with_prompt($prompt_featured, 'intro', $title, $alt_featured);
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
                $img_descs['featured']['attach_id'] = (int)$featured_attach_id;
                cbia_log('Imagen destacada OK: attach_id=' . (int)$featured_attach_id, 'INFO');
            } else {
                cbia_log("No se pudo generar destacada para '{$title}': " . ($e ?: ''), 'ERROR');
            }
        }

		// Limpieza de artefactos antes de guardar
        $text_html = cbia_cleanup_post_html($text_html);

		// Crear post en WP
		list($ok_post, $post_id, $post_err) = cbia_create_post_in_wp_engine($title, $text_html, $featured_attach_id, $post_date_mysql, $force_status);
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

        // Guardar descripciones usadas para prompts (featured + internas)
        update_post_meta($post_id, '_cbia_img_descs', wp_json_encode($img_descs));

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


