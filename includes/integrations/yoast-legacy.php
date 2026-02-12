<?php
// File: includes/integrations/yoast-legacy.php
if (!defined('ABSPATH')) exit;

/**
 * CBIA - YOAST (v9+ combinado)
 *
 * Incluye:
 * - Hook cbia_after_post_created: recalcula metas + actualiza semÃ¡foro + reindex best-effort
 * - Mantenimiento desde pestaÃ±a Yoast (batch/offset):
 *   (1) Recalcular metas
 *   (2) Actualizar semÃ¡foro (scores SEO/Legibilidad)
 *   (3) Ambos
 * - Marcar posts antiguos como CBIA (_cbia_created=1) con filtros opcionales
 * - Log propio (no confundir con cbia_activity_log)
 *
 * Nota:
 * - El â€œsemÃ¡foroâ€ aquÃ­ se rellena guardando:
 *   _yoast_wpseo_linkdex (SEO score)
 *   _yoast_wpseo_content_score (Legibilidad)
 *   con una heurÃ­stica razonable para que deje de verse en gris.
 * - Si existe Yoast, se dispara save_postdata / reindex best-effort.
 */

/* =========================================================
   ============================ LOG =========================
   ========================================================= */

if (!function_exists('cbia_yoast_log_key')) {
	function cbia_yoast_log_key() { return 'cbia_yoast_log'; }
}

if (!function_exists('cbia_yoast_log')) {
	function cbia_yoast_log($msg) {
		if (function_exists('cbia_log')) {
			cbia_log((string)$msg, 'INFO');
			return;
		}
		$log = (string)get_option(cbia_yoast_log_key(), '');
		$ts  = current_time('mysql');
		$log .= "[{$ts}] {$msg}\n";
		if (strlen($log) > 250000) $log = substr($log, -250000);
		update_option(cbia_yoast_log_key(), $log, false);

	}
}

if (!function_exists('cbia_yoast_log_get')) {
	function cbia_yoast_log_get() {
		if (function_exists('cbia_get_log')) {
			$payload = cbia_get_log();
			return is_array($payload) ? (string)($payload['log'] ?? '') : (string)$payload;
		}
		return (string)get_option(cbia_yoast_log_key(), '');
	}
}

if (!function_exists('cbia_yoast_log_clear')) {
	function cbia_yoast_log_clear() {
		if (function_exists('cbia_clear_log')) {
			cbia_clear_log();
			return;
		}
		delete_option(cbia_yoast_log_key());
	}
}

/* =========================================================
   ====================== HELPERS (safe) ====================
   ========================================================= */

if (!function_exists('cbia_yoast_first_paragraph_text')) {
	function cbia_yoast_first_paragraph_text($html) {
		if (preg_match('/<p[^>]*>(.*?)<\/p>/is', (string)$html, $m)) return wp_strip_all_tags($m[1]);
		return wp_strip_all_tags((string)$html);
	}
}

if (!function_exists('cbia_yoast_generate_focus_keyphrase')) {
	function cbia_yoast_generate_focus_keyphrase($title, $content) {
		if (function_exists('cbia_generate_focus_keyphrase')) return cbia_generate_focus_keyphrase($title, $content);
		$words = preg_split('/\s+/', wp_strip_all_tags((string)$title));
		$words = array_values(array_filter(array_map('trim', (array)$words)));
		return trim(implode(' ', array_slice($words, 0, 4)));
	}
}

if (!function_exists('cbia_yoast_generate_meta_description')) {
	function cbia_yoast_generate_meta_description($title, $content) {
		if (function_exists('cbia_generate_meta_description')) return cbia_generate_meta_description($title, $content);

		$base = cbia_yoast_first_paragraph_text((string)$content);
		$t = trim(wp_strip_all_tags((string)$title));
		if ($t !== '') {
			$pattern = '/^' . preg_quote($t, '/') . '\s*[:\-â€“â€”]?\s*/iu';
			$base = preg_replace($pattern, '', $base);
		}
		$desc = trim(mb_substr((string)$base, 0, 155));
		if ($desc !== '' && !preg_match('/[.!?]$/u', $desc)) $desc .= '...';
		return $desc;
	}
}

if (!function_exists('cbia_yoast_word_count')) {
	function cbia_yoast_word_count($html) {
		$txt = wp_strip_all_tags((string)$html);
		$txt = preg_replace('/\s+/', ' ', $txt);
		$txt = trim($txt);
		if ($txt === '') return 0;
		return count(preg_split('/\s+/', $txt));
	}
}

if (!function_exists('cbia_yoast_sentence_count')) {
	function cbia_yoast_sentence_count($html) {
		$txt = wp_strip_all_tags((string)$html);
		$txt = trim($txt);
		if ($txt === '') return 0;
		$parts = preg_split('/[.!?]+/u', $txt);
		$parts = array_filter(array_map('trim', (array)$parts));
		return count($parts);
	}
}

if (!function_exists('cbia_yoast_has_h2')) {
	function cbia_yoast_has_h2($html) {
		return (bool)preg_match('/<h2\b/i', (string)$html);
	}
}

if (!function_exists('cbia_yoast_has_lists')) {
	function cbia_yoast_has_lists($html) {
		return (bool)preg_match('/<(ul|ol)\b/i', (string)$html);
	}
}

/* =========================================================
   ============ YOAST: REINDEX best-effort ==================
   ========================================================= */

if (!function_exists('cbia_yoast_try_reindex_post')) {
	function cbia_yoast_try_reindex_post($post_id) {
		$post_id = (int)$post_id;
		if ($post_id <= 0) return false;

		// Disparos base WP
		do_action('save_post', $post_id, get_post($post_id), true);
		do_action('wp_insert_post', $post_id, get_post($post_id), true);

		// Si no hay YoastSEO(), intentamos hooks clÃ¡sicos
		if (!function_exists('YoastSEO')) {
			$yoast_hook_postdata = 'wpseo_save_postdata';
			$yoast_hook_post = 'wpseo_save_post';
			do_action($yoast_hook_postdata, $post_id);
			do_action($yoast_hook_post, $post_id);
			clean_post_cache($post_id);
			return false;
		}

		try {
			$yoast = YoastSEO();
			if (is_object($yoast) && isset($yoast->classes) && is_object($yoast->classes) && method_exists($yoast->classes, 'get')) {
				$candidates = array(
					'\\Yoast\\WP\\SEO\\Actions\\Indexing\\Indexable_Post_Indexing_Action',
					'\\Yoast\\WP\\SEO\\Actions\\Indexing\\Indexable_Indexing_Action',
					'\\Yoast\\WP\\SEO\\Actions\\Indexing\\Indexing_Action',
				);

				foreach ($candidates as $class) {
					if (class_exists($class)) {
						$obj = $yoast->classes->get($class);
						if (is_object($obj)) {
							if (method_exists($obj, 'index')) {
								try { $obj->index($post_id); clean_post_cache($post_id); return true; } catch (Throwable $e) {}
								try { $obj->index();         clean_post_cache($post_id); return true; } catch (Throwable $e2) {}
							}
							if (method_exists($obj, 'indexables')) {
								try { $obj->indexables(array($post_id)); clean_post_cache($post_id); return true; } catch (Throwable $e3) {}
							}
						}
					}
				}
			}
		} catch (Throwable $e) {
			cbia_yoast_log("Reindex error post {$post_id}: " . $e->getMessage());
		}

		// Fallback final
		$yoast_hook_postdata = 'wpseo_save_postdata';
		$yoast_hook_post = 'wpseo_save_post';
		do_action($yoast_hook_postdata, $post_id);
		do_action($yoast_hook_post, $post_id);
		clean_post_cache($post_id);
		return false;
	}
}

/* =========================================================
   ============== SEMÃFORO: heurÃ­stica scores ===============
   ========================================================= */

if (!function_exists('cbia_yoast_compute_scores_heuristic')) {
	function cbia_yoast_compute_scores_heuristic($post_id) {
		$post = get_post((int)$post_id);
		if (!$post) return array(null, null);

		$content = (string)$post->post_content;
		$title   = get_the_title((int)$post_id);

		$wc = cbia_yoast_word_count($content);
		$sc = cbia_yoast_sentence_count($content);
		$has_h2    = cbia_yoast_has_h2($content);
		$has_lists = cbia_yoast_has_lists($content);

		$focus = (string)get_post_meta((int)$post_id, '_yoast_wpseo_focuskw', true);
		$metad = (string)get_post_meta((int)$post_id, '_yoast_wpseo_metadesc', true);

		// SEO score 0..100 (aprox)
		$seo = 10;

		if ($wc >= 900) $seo += 25;
		elseif ($wc >= 600) $seo += 18;
		elseif ($wc >= 350) $seo += 10;

		if ($has_h2) $seo += 15;
		if ($has_lists) $seo += 8;

		$md_len = mb_strlen(trim($metad));
		if ($md_len >= 110 && $md_len <= 170) $seo += 12;
		elseif ($md_len >= 70) $seo += 6;

		if (trim($focus) !== '') $seo += 15;

		// focus dentro de contenido (simple)
		if (trim($focus) !== '') {
			$needle = mb_strtolower(trim($focus));
			$hay = mb_strtolower(wp_strip_all_tags($content));
			if ($needle !== '' && mb_strpos($hay, $needle) !== false) $seo += 10;
		}

		$seo = max(0, min(100, (int)$seo));

		// Legibilidad 0..100 (aprox)
		$read = 10;

		if ($wc >= 900) $read += 10;
		if ($has_h2) $read += 15;
		if ($has_lists) $read += 10;

		$avg = ($sc > 0) ? ($wc / $sc) : 0;
		if ($avg > 0 && $avg <= 18) $read += 25;
		elseif ($avg > 0 && $avg <= 24) $read += 15;
		elseif ($avg > 0 && $avg <= 30) $read += 8;

		$p_count = preg_match_all('/<p\b/i', $content, $mm);
		if ($p_count >= 6) $read += 10;
		elseif ($p_count >= 3) $read += 5;

		$read = max(0, min(100, (int)$read));

		return array($seo, $read);
	}
}

if (!function_exists('cbia_yoast_update_semaphore_scores')) {
	/**
	 * Guarda:
	 * - _yoast_wpseo_linkdex
	 * - _yoast_wpseo_content_score
	 *
	 * Retorna: [did(bool), seo(int|null), read(int|null)]
	 */
	function cbia_yoast_update_semaphore_scores($post_id, $force = false) {
		$post_id = (int)$post_id;
		if ($post_id <= 0) return array(false, null, null);

		$linkdex = get_post_meta($post_id, '_yoast_wpseo_linkdex', true);
		$readsc  = get_post_meta($post_id, '_yoast_wpseo_content_score', true);

		if (!$force && $linkdex !== '' && $readsc !== '') {
			return array(false, (int)$linkdex, (int)$readsc);
		}

		list($seo, $read) = cbia_yoast_compute_scores_heuristic($post_id);
		if ($seo === null || $read === null) return array(false, null, null);

		update_post_meta($post_id, '_yoast_wpseo_linkdex', (string)$seo);
		update_post_meta($post_id, '_yoast_wpseo_content_score', (string)$read);

		// Disparar guardado Yoast si existe
		try {
			if (class_exists('WPSEO_Meta') && method_exists('WPSEO_Meta', 'save_postdata')) {
				WPSEO_Meta::save_postdata($post_id);
			}
		} catch (Throwable $e) {
			cbia_yoast_log("WPSEO_Meta::save_postdata fallÃ³ en {$post_id}: " . $e->getMessage());
		}

		// Forzar update + reindex best-effort
		try { wp_update_post(array('ID' => $post_id)); } catch (Throwable $e2) {}
		cbia_yoast_try_reindex_post($post_id);

		clean_post_cache($post_id);
		return array(true, $seo, $read);
	}
}

/* =========================================================
   ==================== METAS: metadesc/focus ===============
   ========================================================= */

if (!function_exists('cbia_yoast_recalc_metas')) {
	/**
	 * Retorna bool didChange
	 */
	function cbia_yoast_recalc_metas($post_id, $force = false) {
		$post_id = (int)$post_id;
		$post = get_post($post_id);
		if (!$post) return false;

		$title   = get_the_title($post_id);
		$content = (string)$post->post_content;

		$metadesc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
		$focuskw  = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);

		$did = false;

		if ($force || $metadesc === '' || $metadesc === null) {
			$md = cbia_yoast_generate_meta_description($title, $content);
			update_post_meta($post_id, '_yoast_wpseo_metadesc', $md);
			$did = true;
		}

		if ($force || $focuskw === '' || $focuskw === null) {
			$fk = cbia_yoast_generate_focus_keyphrase($title, $content);
			update_post_meta($post_id, '_yoast_wpseo_focuskw', $fk);
			$did = true;
		}

		return $did;
	}
}

/* =========================================================
   =================== QUERY POSTS (batch) ==================
   ========================================================= */

if (!function_exists('cbia_yoast_query_posts')) {
	function cbia_yoast_query_posts($batch, $only_cbia = true, $offset = 0) {
		$batch  = max(1, min(500, (int)$batch));
		$offset = max(0, (int)$offset);

		$args = array(
			'post_type'      => 'post',
			'posts_per_page' => $batch,
			'post_status'    => array('publish','future','draft','pending','private'),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'offset'         => $offset,
		);

		if ($only_cbia) {
			$args['meta_key'] = '_cbia_created';
			$args['meta_value'] = '1';
		}

		$q = new WP_Query($args);
		return !empty($q->posts) ? $q->posts : array();
	}
}

/* =========================================================
   ============== MARCAR ANTIGUOS COMO CBIA =================
   ========================================================= */

if (!function_exists('cbia_yoast_mark_legacy_cbia')) {
	/**
	 * Retorna [marked, checked]
	 */
	function cbia_yoast_mark_legacy_cbia($batch = 200, $from = '', $to = '', $only_signals = false, $offset = 0) {
		$batch  = max(1, min(500, (int)$batch));
		$offset = max(0, (int)$offset);

		$args = array(
			'post_type'      => 'post',
			'posts_per_page' => $batch,
			'post_status'    => array('publish','future','draft','pending','private'),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'offset'         => $offset,
			'meta_key'       => '_cbia_created',
			'meta_compare'   => 'NOT EXISTS',
		);

		$date_query = array();
		if ($from !== '') $date_query[] = array('after' => $from, 'inclusive' => true);
		if ($to   !== '') $date_query[] = array('before'=> $to,   'inclusive' => true);
		if (!empty($date_query)) $args['date_query'] = $date_query;

		$q = new WP_Query($args);
		$ids = !empty($q->posts) ? $q->posts : array();
		if (empty($ids)) return array(0, 0);

		$marked = 0; $checked = 0;

		foreach ($ids as $post_id) {
			$checked++;
			$post_id = (int)$post_id;

			if ($only_signals) {
				$has = false;

				$faq  = get_post_meta($post_id, '_cbia_faq_json_ld', true);
				$pend = get_post_meta($post_id, '_cbia_pending_images', true);
				if ($faq !== '' || (int)$pend > 0) $has = true;

				if (!$has) {
					$p = get_post($post_id);
					if ($p && is_string($p->post_content)) {
						if (stripos($p->post_content, '[IMAGEN') !== false || stripos($p->post_content, '[IMAGEN_PENDIENTE') !== false) $has = true;
					}
				}

				if (!$has) continue;
			}

			update_post_meta($post_id, '_cbia_created', '1');
			$marked++;
		}

		return array($marked, $checked);
	}
}

/* =========================================================
   ======================= RUN BATCH =======================
   actions: metas | semaphore | both
   ========================================================= */

if (!function_exists('cbia_yoast_run_batch')) {
	/**
	 * Retorna [processed, metas_changed, scores_changed, reindex_ok]
	 */
	function cbia_yoast_run_batch($action, $batch = 50, $force = false, $only_cbia = true, $offset = 0) {
		$ids = cbia_yoast_query_posts($batch, $only_cbia, $offset);

		if (empty($ids)) {
			cbia_yoast_log("No hay posts para procesar (action={$action}, only_cbia=" . ($only_cbia ? 'sÃ­' : 'no') . ", offset={$offset}).");
			return array(0, 0, 0, 0);
		}

		$processed = 0; $metas_changed = 0; $scores_changed = 0; $reindex_ok = 0;

		foreach ($ids as $post_id) {
			$processed++;
			$post_id = (int)$post_id;
			$title = get_the_title($post_id);

			if ($action === 'metas' || $action === 'both') {
				cbia_yoast_log("METAS: post {$post_id} '{$title}' (force=" . ($force ? 'sÃ­' : 'no') . ")");
				$did = cbia_yoast_recalc_metas($post_id, $force);
				if ($did) $metas_changed++;
			}

			if ($action === 'semaphore' || $action === 'both') {
				cbia_yoast_log("SEMÃFORO: post {$post_id} '{$title}' (force=" . ($force ? 'sÃ­' : 'no') . ")");
				list($did_scores, $seo, $read) = cbia_yoast_update_semaphore_scores($post_id, $force);
				if ($did_scores) {
					$scores_changed++;
					cbia_yoast_log("SEMÃFORO: scores guardados => SEO={$seo} | LEG={$read}");
				} else {
					cbia_yoast_log("SEMÃFORO: ya tenÃ­a scores (o no se pudo calcular).");
				}
			}

			if ($action === 'semaphore' || $action === 'both') {
				$re = cbia_yoast_try_reindex_post($post_id);
				if ($re) $reindex_ok++;
			}

			update_post_meta($post_id, '_cbia_yoast_refreshed', current_time('mysql'));
		}

		cbia_yoast_log("FIN LOTE: action={$action} processed={$processed} metas_changed={$metas_changed} scores_changed={$scores_changed} reindex_ok={$reindex_ok}");
		return array($processed, $metas_changed, $scores_changed, $reindex_ok);
	}
}

/* =========================================================
   ================= HOOK: POST CREADO ======================
   ========================================================= */

if (!function_exists('cbia_yoast_on_post_created')) {
	function cbia_yoast_on_post_created($post_id, $title = '', $content_html = '', $usage = array(), $model_used = '') {
		$post_id = (int)$post_id;
		if ($post_id <= 0) return;

		// Esto es lo que te faltaba: al crear, actualizar semÃ¡foro inmediato
		cbia_yoast_log("HOOK: post creado {$post_id}. Recalculando metas + semÃ¡foro...");

		$did_metas = cbia_yoast_recalc_metas($post_id, false);
		list($did_scores, $seo, $read) = cbia_yoast_update_semaphore_scores($post_id, true); // true para que lo rellene siempre al crear

		$re = cbia_yoast_try_reindex_post($post_id);

		cbia_yoast_log("HOOK: post {$post_id} metas=" . ($did_metas ? 'actualizadas' : 'ok') .
			" | semÃ¡foro=" . ($did_scores ? "OK (SEO={$seo}, LEG={$read})" : 'sin cambios') .
			" | reindex=" . ($re ? 'ok' : 'best-effort'));
	}
}

add_action('cbia_after_post_created', 'cbia_yoast_on_post_created', 20, 5);

/* =========================================================
   ======================= TAB YOAST UI =====================
   ========================================================= */

if (!function_exists('cbia_yoast_handle_post')) {
    function cbia_yoast_handle_post($batch, $offset, $force, $only_cbia) {
        if (!is_admin() || !current_user_can('manage_options')) {
            return array($batch, $offset, $force, $only_cbia);
        }

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $post_unslashed = wp_unslash($_POST);

            if (!empty($post_unslashed['cbia_yoast_action']) && check_admin_referer('cbia_yoast_nonce_action', 'cbia_yoast_nonce')) {
                $action = sanitize_text_field((string)$post_unslashed['cbia_yoast_action']);

                $batch  = isset($post_unslashed['cbia_yoast_batch']) ? (int)$post_unslashed['cbia_yoast_batch'] : 50;
                $batch  = max(1, min(500, $batch));

                $offset = isset($post_unslashed['cbia_yoast_offset']) ? (int)$post_unslashed['cbia_yoast_offset'] : 0;
                $offset = max(0, $offset);

                $force = !empty($post_unslashed['cbia_yoast_force']) ? true : false;
                $only_cbia = empty($post_unslashed['cbia_yoast_include_unmarked']) ? true : false;

                if ($action === 'clear_log') {
                    cbia_yoast_log_clear();
                    cbia_yoast_log("Log limpiado.");
                } elseif ($action === 'metas' || $action === 'semaphore' || $action === 'both') {
                    cbia_yoast_run_batch($action, $batch, $force, $only_cbia, $offset);
                } elseif ($action === 'mark_legacy') {
                    $from = isset($post_unslashed['cbia_yoast_date_from']) ? sanitize_text_field((string)$post_unslashed['cbia_yoast_date_from']) : '';
                    $to   = isset($post_unslashed['cbia_yoast_date_to'])   ? sanitize_text_field((string)$post_unslashed['cbia_yoast_date_to'])   : '';
                    $sig  = !empty($post_unslashed['cbia_yoast_only_signals']) ? true : false;

                    if ($from !== '') { $from = str_replace('T',' ', $from); if (strlen($from) === 16) $from .= ':00'; }
                    if ($to   !== '') { $to   = str_replace('T',' ', $to);   if (strlen($to) === 16) $to   .= ':00'; }

                    cbia_yoast_log("MARCAR legacy: batch={$batch} offset={$offset} from=" . ($from ?: '(none)') . " to=" . ($to ?: '(none)') . " only_signals=" . ($sig ? 'sÃ­' : 'no'));
                    list($marked, $checked) = cbia_yoast_mark_legacy_cbia($batch, $from, $to, $sig, $offset);
                    cbia_yoast_log("MARCAR legacy: marcados={$marked} revisados={$checked}");
                }
            }
        }

        return array($batch, $offset, $force, $only_cbia);
    }
}

if (!function_exists('cbia_render_tab_yoast')) {
    function cbia_render_tab_yoast(){
        if (!current_user_can('manage_options')) return;

        $view = (defined('CBIA_INCLUDES_DIR') ? CBIA_INCLUDES_DIR . 'admin/views/yoast.php' : __DIR__ . '/../admin/views/yoast.php');
        if (file_exists($view)) {
            include $view;
            return;
        }

        echo '<p>No se pudo cargar Yoast.</p>';
    }
}

/* =========================================================
   ================== AJAX opcional (si quieres) ============
   ========================================================= */

// Si en algÃºn punto quieres refresco tipo "log en vivo" por JS, puedes usar este endpoint:
add_action('wp_ajax_cbia_get_yoast_log', function () {
	if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
	if (!isset($_REQUEST['_ajax_nonce']) || !function_exists('wp_verify_nonce')) {
		wp_send_json_error('bad_nonce', 403);
	}
	$nonce = sanitize_text_field(wp_unslash((string)$_REQUEST['_ajax_nonce']));
	if ($nonce === '' || !wp_verify_nonce($nonce, 'cbia_ajax_nonce')) {
		wp_send_json_error('bad_nonce', 403);
	}
	nocache_headers();
	if (function_exists('cbia_get_log')) {
		wp_send_json_success(cbia_get_log());
	}
	wp_send_json_success(cbia_yoast_log_get());
});



