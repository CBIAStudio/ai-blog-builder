<?php
/*
 * includes/cbia-engine.php
 * MOTOR REAL:
 * - Llama a OpenAI /v1/responses
 * - Captura usage (tokens)
 * - Genera post (publish/future)
 */
if (!defined('ABSPATH')) exit;


// Añadir la función cbia_force_insert_markers en el lugar correcto
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
            $desc = preg_replace('/\s+/', ' ', strip_tags($p_full));
            $marker = "\n[IMAGEN: {$desc}]\n"; $html = substr($html, 0, $pos0 + $p_len) . $marker . substr($html, $pos0 + $p_len); $inserted++;
        }
        // FAQ
        $faq_pos = preg_match('/<h2[^>]*>.*?(preguntas\s+frecuentes|faq).*?<\/h2>/i', $html, $mm2, PREG_OFFSET_CAPTURE) ? $mm2[0][1] : -1;
        if ($inserted < $faltan && $faq_pos >= 0) {
            $marker = "\n[IMAGEN: soporte visual para las preguntas frecuentes]\n";
            $html = substr($html, 0, $faq_pos) . $marker . substr($html, $faq_pos); $inserted++;
        }
        // Solo si aún faltan, añade uno al final
        if ($inserted < $faltan) {
            $desc = "cierre visual coherente con el tema de '{$title}'";
            $marker = "\n[IMAGEN: {$desc}]\n"; $html .= $marker; $inserted++;
        }
        return $html;
    }
}

/**
 * includes/cbia-engine.php
 * MOTOR REAL:
 * - Llama a OpenAI /v1/responses
 * - Captura usage (tokens)
 * - Genera post (publish/future)
 * - Procesa marcadores [IMAGEN: ...] => genera imágenes y las inserta
 * - Si falla => [IMAGEN_PENDIENTE: ...] + metas de pendientes
 * - Relleno de pendientes (manual / cron)
 * - Categorías por mapping + tags SOLO de lista permitida (default_tags)
 * - Hook cbia_after_post_created
 *
 * FIX v8/v9 (LOG TIEMPO REAL):
 * - Añade contador cbia_log_counter para evitar cache en polling
 * - Borra cache de options tras escribir log
 * - Endpoint AJAX wp_ajax_cbia_get_log (JSON) con nocache_headers()
 * - cbia_clear_log() borra también contador
 */


/* =========================================================
   ===================== AUTOR (POST) =======================
   ========================================================= */

if (!function_exists('cbia_pick_post_author_id')) {
	function cbia_pick_post_author_id() {
		$s = cbia_get_settings();

		// 1) Si defines un autor fijo en settings (recomendado para cron)
		$fixed = (int)($s['default_author_id'] ?? 0);
		if ($fixed > 0) return $fixed;

		// 2) Si hay usuario actual (cuando lanzas manual desde admin)
		$cur = (int)get_current_user_id();
		if ($cur > 0) return $cur;

		// 3) Fallback: primer administrador
		$admins = get_users([
			'role__in' => ['administrator'],
			'number'   => 1,
			'fields'   => 'ID',
		]);
		if (!empty($admins) && isset($admins[0])) return (int)$admins[0];

		// 4) Último fallback
		return 1;
	}
}



/* =========================================================
   =============== FALLBACKS / HELPERS BASE =================
   ========================================================= */

if (!function_exists('cbia_get_settings')) {
	function cbia_get_settings() {
		$s = get_option('cbia_settings', []);
		return is_array($s) ? $s : [];
	}
}

if (!function_exists('cbia_log')) {
	function cbia_log($message, $level = 'INFO') {
		$log = (string) get_option('cbia_activity_log', '');
		$ts  = current_time('mysql');
		$log .= "[{$ts}] [{$level}] {$message}\n";
		if (strlen($log) > 250000) $log = substr($log, -250000);

		update_option('cbia_activity_log', $log, false);

		// contador anti-cache para polling
		$cnt = (int) get_option('cbia_log_counter', 0);
		update_option('cbia_log_counter', $cnt + 1, false);

		// fuerza a no servir valores cacheados de options
		wp_cache_delete('cbia_activity_log', 'options');
		wp_cache_delete('cbia_log_counter', 'options');
	}
}

if (!function_exists('cbia_clear_log')) {
	function cbia_clear_log() {
		delete_option('cbia_activity_log');
		delete_option('cbia_log_counter');
		wp_cache_delete('cbia_activity_log', 'options');
		wp_cache_delete('cbia_log_counter', 'options');
	}
}

/**
 * Endpoint AJAX para leer log en tiempo real (polling desde admin)
 * Devuelve: { success:true, data:{ log:"...", counter:123 } }
 */
if (!function_exists('cbia_register_ajax_log_endpoint')) {
	function cbia_register_ajax_log_endpoint() {
		if (has_action('wp_ajax_cbia_get_log')) return;
		add_action('wp_ajax_cbia_get_log', function () {
			if (!current_user_can('manage_options')) {
				wp_send_json_error('No autorizado');
			}

			nocache_headers();
			header('Content-Type: application/json; charset=utf-8');

			$log = (string) get_option('cbia_activity_log', '');
			$cnt = (int) get_option('cbia_log_counter', 0);

			wp_send_json_success([
				'log'     => $log,
				'counter' => $cnt,
			]);
		});
	}
	cbia_register_ajax_log_endpoint();
}

if (!function_exists('cbia_set_stop_flag')) {
	function cbia_set_stop_flag($value = true) {
		update_option('cbia_stop_generation', $value ? 1 : 0, false);
	}
}

if (!function_exists('cbia_is_stop_requested')) {
	function cbia_is_stop_requested() {
		return (int) get_option('cbia_stop_generation', 0) === 1;
	}
}

if (!function_exists('cbia_try_unlimited_runtime')) {
	function cbia_try_unlimited_runtime() {
		// Evita que el proceso se corte por límite de ejecución cuando haces lotes largos desde admin.
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}
		if (function_exists('ignore_user_abort')) {
			@ignore_user_abort(true);
		}
	}
}

if (!function_exists('cbia_http_headers_openai')) {
	function cbia_http_headers_openai($api_key) {
		return [
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $api_key,
		];
	}
}

if (!function_exists('cbia_openai_api_key')) {
	function cbia_openai_api_key() {
		$s = cbia_get_settings();
		return trim((string)($s['openai_api_key'] ?? ''));
	}
}

/* =========================================================
   =================== MODELOS / BLOQUEO ====================
   ========================================================= */

if (!function_exists('cbia_model_fallback_chain')) {
	function cbia_model_fallback_chain($preferred) {
		$chain = [
			'gpt-5',
			'gpt-5-mini',
			'gpt-5-nano',
			'gpt-5.2',
			'gpt-4.1-mini',
			'gpt-4.1',
			'gpt-4.1-nano',
		];

		$preferred = trim((string)$preferred);
		if ($preferred !== '') {
			if (!in_array($preferred, $chain, true)) {
				array_unshift($chain, $preferred);
			} else {
				$chain = array_values(array_unique(array_merge([$preferred], $chain)));
			}
		}

		return $chain;
	}
}

if (!function_exists('cbia_is_responses_model')) {
	function cbia_is_responses_model($m) {
		$m = strtolower(trim((string)$m));
		if ($m === '') return false;

		if (preg_match('/^gpt-5(\.[0-9]+)?(\-|$)/', $m)) return true;
		if ($m === 'gpt-5-mini' || $m === 'gpt-5-nano') return true;

		if (strpos($m, 'gpt-4.1') === 0) return true;
		if ($m === 'gpt-4o-mini') return true;

		return false;
	}
}

if (!function_exists('cbia_pick_model')) {
	function cbia_pick_model() {
		$s = cbia_get_settings();
		$preferred = $s['openai_model'] ?? 'gpt-4.1-mini';
		$blocked = $s['blocked_models'] ?? [];
		if (!is_array($blocked)) $blocked = [];

		$chain = cbia_model_fallback_chain($preferred);
		foreach ($chain as $m) {
			if (!empty($blocked[$m])) continue;
			return $m;
		}
		// Si bloqueó todo, devolvemos preferido igualmente
		return $preferred ?: 'gpt-4.1-mini';
	}
}

/* =========================================================
   ===================== PROMPT ÚNICO =======================
   ========================================================= */

if (!function_exists('cbia_build_prompt_for_title')) {
	function cbia_build_prompt_for_title($title) {
		$s = cbia_get_settings();
		$idioma_post = trim((string)($s['post_language'] ?? 'español'));

		$prompt_unico = $s['prompt_single_all'] ?? '';
		$prompt_unico = is_string($prompt_unico) ? trim($prompt_unico) : '';

		if ($prompt_unico === '') {
			$prompt_unico =
				"Escribe un POST COMPLETO en {IDIOMA_POST} y en HTML para \"{title}\", optimizado para Google Discover, con una extensión aproximada de 1600–1800 palabras (±10%)."
				."\n\nREGLA DE IDIOMA (OBLIGATORIA)"
				."\n- TODO el contenido debe estar escrito EXCLUSIVAMENTE en {IDIOMA_POST}."
				."\n- Esto incluye encabezados, preguntas frecuentes y respuestas."
				."\n- Está PROHIBIDO usar cualquier otro idioma en el contenido (salvo el título {title} si viene en otro idioma)."
				."\n\nEl contenido debe priorizar interés humano, lectura fluida, contexto cultural y experiencia real. Evita el enfoque de SEO tradicional y no fuerces keywords exactas."
				."\n\nTONO Y ESTILO"
				."\n- Profesional, cercano y natural."
				."\n- Editorial y cultural, no enciclopédico."
				."\n- Narrativo cuando sea adecuado, con criterio y punto de vista."
				."\n- Pensado para lectores que no estaban buscando activamente el tema."
				."\n\nLa estructura debe ser EXACTA. No añadas ni elimines secciones:"
				."\n- Párrafo inicial en <p> (180–220 palabras). NO usar la palabra \"Introducción\" ni equivalentes."
				."\n- 3 bloques principales con <h2> y, SOLO si aporta claridad real, <h3> (250–300 palabras por bloque; usa listas <ul><li>…</li></ul> únicamente cuando ayuden a la comprensión)."
				."\n- Sección de preguntas frecuentes:"
				."\n  • Un <h2> cuyo texto debe estar en {IDIOMA_POST} y ser el equivalente natural a \"Preguntas frecuentes\" en ese idioma."
				."\n  • 6 FAQs en el formato exacto <h3>Pregunta</h3><p>Respuesta</p> (120–150 palabras por respuesta)."
				."\n\nINSTRUCCIÓN CRÍTICA: ninguna respuesta debe cortarse y TODAS las respuestas deben terminar en punto final."
				."\n\nIMÁGENES"
				."\nInserta marcadores de imagen SOLO donde aporten valor usando el formato EXACTO:"
				."\n[IMAGEN: descripción breve, concreta, sin texto ni marcas de agua, estilo realista/editorial]"
				."\n\nReglas de obligado cumplimiento:"
				."\n• NO usar <h1>."
				."\n• NO añadir sección de conclusión ni CTA final."
				."\n• NO incluir <!DOCTYPE>, <html>, <head>, <body>, <script>, <style>, <iframe>, <table> ni <blockquote>."
				."\n• NO enlazar a webs externas (usar el texto plano \"(enlace interno)\" si es necesario)."
				."\n• Evitar redundancias y muletillas."
				."\n• No escribir con enfoque SEO por keyword exacta.";
		}

		$prompt_unico = str_replace('{title}', $title, $prompt_unico);
		$prompt_unico = str_replace('{IDIOMA_POST}', $idioma_post, $prompt_unico);

		return $prompt_unico;
	}
}

/* =========================================================
   ===================== EXTRACTOR TEXT =====================
   ========================================================= */

if (!function_exists('cbia_extract_text_from_responses_payload')) {
	function cbia_extract_text_from_responses_payload($data) {
		if (!is_array($data)) return '';

		// 1) Campo directo (algunos modelos lo incluyen)
		if (isset($data['output_text']) && is_string($data['output_text'])) {
			$txt = trim($data['output_text']);
			if ($txt !== '') return $txt;
		}

		$parts = array();

		// 2) Estructura habitual Responses: output[] -> content[]
		if (!empty($data['output']) && is_array($data['output'])) {
			foreach ($data['output'] as $out) {
				if (!is_array($out)) continue;

				// Algunos payloads traen texto directo
				if (isset($out['output_text']) && is_string($out['output_text'])) {
					$ot = trim($out['output_text']);
					if ($ot !== '') $parts[] = $ot;
				}

				if (!empty($out['content']) && is_array($out['content'])) {
					foreach ($out['content'] as $seg) {
						if (is_string($seg)) {
							$st = trim($seg);
							if ($st !== '') $parts[] = $st;
							continue;
						}
						if (!is_array($seg)) continue;

						// Variantes típicas:
						// - {type:"output_text", text:"..."}
						// - {type:"output_text", text:{value:"..."}}
						// - {type:"message", content:[{type:"output_text", text:"..."}]}
						if (isset($seg['text'])) {
							if (is_string($seg['text'])) {
								$st = trim($seg['text']);
								if ($st !== '') $parts[] = $st;
							} elseif (is_array($seg['text']) && isset($seg['text']['value']) && is_string($seg['text']['value'])) {
								$st = trim($seg['text']['value']);
								if ($st !== '') $parts[] = $st;
							}
						}

						if (!empty($seg['content']) && is_array($seg['content'])) {
							foreach ($seg['content'] as $seg2) {
								if (is_string($seg2)) {
									$st = trim($seg2);
									if ($st !== '') $parts[] = $st;
									continue;
								}
								if (!is_array($seg2)) continue;
								if (isset($seg2['text'])) {
									if (is_string($seg2['text'])) {
										$st = trim($seg2['text']);
										if ($st !== '') $parts[] = $st;
									} elseif (is_array($seg2['text']) && isset($seg2['text']['value']) && is_string($seg2['text']['value'])) {
										$st = trim($seg2['text']['value']);
										if ($st !== '') $parts[] = $st;
									}
								}
							}
						}
					}
				}
			}
		}

		$txt = trim(implode("\n", array_filter(array_map('trim', $parts))));
		if ($txt !== '') return $txt;

		// 3) Fallback legacy (Chat Completions-style)
		if (!empty($data['choices'][0]['message']['content'])) {
			$c = $data['choices'][0]['message']['content'];
			if (is_string($c)) {
				$c = trim($c);
				if ($c !== '') return $c;
			}
		}

		// 4) Último recurso: búsqueda recursiva de strings con claves típicas
		$acc = array();
		$max_depth = 6;
		$max_chars = 20000;

		$walker = function($node, $depth) use (&$walker, &$acc, $max_depth, $max_chars) {
			if ($depth > $max_depth) return;
			if (count($acc) > 200) return;

			if (is_string($node)) {
				$st = trim($node);
				if ($st !== '') $acc[] = $st;
				return;
			}

			if (!is_array($node)) return;

			foreach ($node as $k => $v) {
				$kk = is_string($k) ? strtolower($k) : '';
				if ($kk === 'output_text' || $kk === 'text' || $kk === 'content' || $kk === 'value') {
					if (is_string($v)) {
						$st = trim($v);
						if ($st !== '') $acc[] = $st;
						continue;
					}
					if (is_array($v) && isset($v['value']) && is_string($v['value'])) {
						$st = trim($v['value']);
						if ($st !== '') $acc[] = $st;
						continue;
					}
				}

				$walker($v, $depth + 1);

				// evita acumular demasiado
				$joined = implode("\n", $acc);
				if (strlen($joined) > $max_chars) return;
			}
		};

		$walker($data, 0);

		$txt = trim(implode("\n", array_filter(array_map('trim', $acc))));
		return $txt;
	}
}

if (!function_exists('cbia_usage_from_responses_payload')) {
	function cbia_usage_from_responses_payload($data) {
		$u = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];

		if (!is_array($data)) return $u;

		if (!empty($data['usage']) && is_array($data['usage'])) {
			// OpenAI responses usage suele ser input_tokens / output_tokens / total_tokens
			$u['input_tokens']  = (int)($data['usage']['input_tokens'] ?? 0);
			$u['output_tokens'] = (int)($data['usage']['output_tokens'] ?? 0);
			$u['total_tokens']  = (int)($data['usage']['total_tokens'] ?? 0);

			// algunos payloads usan "total_tokens" solo
			if ($u['total_tokens'] <= 0) {
				$u['total_tokens'] = (int)($data['usage']['total_tokens'] ?? 0);
			}
		}

		return $u;
	}
}

/* =========================================================
   ================== USAGE: ACUMULACIÓN ====================
   ========================================================= */

if (!function_exists('cbia_usage_empty')) {
	function cbia_usage_empty() {
		return ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
	}
}

if (!function_exists('cbia_usage_normalize')) {
	function cbia_usage_normalize($usage) {
		$u = cbia_usage_empty();
		if (is_array($usage)) {
			$u['input_tokens']  = (int)($usage['input_tokens'] ?? 0);
			$u['output_tokens'] = (int)($usage['output_tokens'] ?? 0);
			$u['total_tokens']  = (int)($usage['total_tokens'] ?? 0);
		}
		if ($u['total_tokens'] <= 0) $u['total_tokens'] = $u['input_tokens'] + $u['output_tokens'];
		return $u;
	}
}

/**
 * Guarda cada llamada (texto) para poder calcular coste real luego.
 * - Meta: _cbia_usage_calls (JSON)
 * - Agregados: _cbia_tokens_input_sum / _cbia_tokens_output_sum / _cbia_tokens_total_sum
 */
if (!function_exists('cbia_usage_append_call')) {
	function cbia_usage_append_call($post_id, $context, $model, $usage, $extra = array()) {
		$post_id = (int)$post_id;
		if ($post_id <= 0) return false;

		$u = cbia_usage_normalize($usage);

		$ctx = sanitize_key((string)$context);
		$mdl = sanitize_text_field((string)$model);

		$raw = get_post_meta($post_id, '_cbia_usage_calls', true);
		$list = array();
		if ($raw) {
			$tmp = json_decode((string)$raw, true);
			if (is_array($tmp)) $list = $tmp;
		}

		$item = array_merge(array(
			'ts'           => current_time('mysql'),
			'context'      => $ctx,
			'model'        => $mdl,
			'input_tokens' => (int)$u['input_tokens'],
			'output_tokens'=> (int)$u['output_tokens'],
			'total_tokens' => (int)$u['total_tokens'],
		), is_array($extra) ? $extra : array());

		$list[] = $item;

		// Mantener tamaño razonable
		if (count($list) > 200) $list = array_slice($list, -200);

		update_post_meta($post_id, '_cbia_usage_calls', wp_json_encode($list));

		// Agregados globales
		$in_sum  = (int)get_post_meta($post_id, '_cbia_tokens_input_sum', true);
		$out_sum = (int)get_post_meta($post_id, '_cbia_tokens_output_sum', true);
		$tot_sum = (int)get_post_meta($post_id, '_cbia_tokens_total_sum', true);

		$in_sum  += (int)$u['input_tokens'];
		$out_sum += (int)$u['output_tokens'];
		$tot_sum += (int)$u['total_tokens'];

		update_post_meta($post_id, '_cbia_tokens_input_sum', (string)$in_sum);
		update_post_meta($post_id, '_cbia_tokens_output_sum', (string)$out_sum);
		update_post_meta($post_id, '_cbia_tokens_total_sum', (string)$tot_sum);

		return true;
	}
}

/**
 * Guarda llamadas a imágenes (para coste por imagen y trazabilidad).
 * - Meta: _cbia_image_calls (JSON)
 * - Agregados: _cbia_images_total / _cbia_images_ok / _cbia_images_fail
 */
if (!function_exists('cbia_image_append_call')) {
	function cbia_image_append_call($post_id, $section, $model, $ok, $attach_id = 0, $err = '') {
		$post_id = (int)$post_id;
		if ($post_id <= 0) return false;

		$raw = get_post_meta($post_id, '_cbia_image_calls', true);
		$list = array();
		if ($raw) {
			$tmp = json_decode((string)$raw, true);
			if (is_array($tmp)) $list = $tmp;
		}

		$list[] = array(
			'ts'        => current_time('mysql'),
			'section'   => sanitize_key((string)$section),
			'model'     => sanitize_text_field((string)$model),
			'ok'        => $ok ? 1 : 0,
			'attach_id' => (int)$attach_id,
			'error'     => sanitize_text_field((string)$err),
		);

		if (count($list) > 200) $list = array_slice($list, -200);

		update_post_meta($post_id, '_cbia_image_calls', wp_json_encode($list));

		$total = (int)get_post_meta($post_id, '_cbia_images_total', true);
		$okc   = (int)get_post_meta($post_id, '_cbia_images_ok', true);
		$fail  = (int)get_post_meta($post_id, '_cbia_images_fail', true);

		$total++;
		if ($ok) $okc++; else $fail++;

		update_post_meta($post_id, '_cbia_images_total', (string)$total);
		update_post_meta($post_id, '_cbia_images_ok', (string)$okc);
		update_post_meta($post_id, '_cbia_images_fail', (string)$fail);

		return true;
	}
}

/* =========================================================
   =============== OPENAI: RESPONSES CALL (6) ===============
   ========================================================= */

if (!function_exists('cbia_openai_responses_call')) {
	/**
	 * Devuelve 6 valores:
	 * [ok(bool), text(string), usage(array), model_used(string), err(string), raw(array|string)]
	 */
	function cbia_openai_responses_call($prompt, $title_for_log = '', $tries = 2) {
		cbia_try_unlimited_runtime();
		$api_key = cbia_openai_api_key();
		if (!$api_key) {
			return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No hay API key', []];
		}

		$s = cbia_get_settings();
		$model_preferred = cbia_pick_model();
		$chain = cbia_model_fallback_chain($model_preferred);
		$blocked = $s['blocked_models'] ?? [];
		if (!is_array($blocked)) $blocked = [];

		$system = "Eres un redactor editorial. Devuelve HTML simple con <h2>, <h3>, <p>, <ul>, <li>. NO uses <h1> ni envolturas <html>/<head>/<body>. No uses <table>, <iframe> ni <blockquote>.";
		$input = [
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => (string)$prompt],
		];

		foreach ($chain as $model) {
			if (!empty($blocked[$model])) continue;
			if (!cbia_is_responses_model($model)) continue;

			for ($t = 1; $t <= max(1, (int)$tries); $t++) {
				if (cbia_is_stop_requested()) {
					return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'STOP activado', []];
				}

				cbia_log("OpenAI Responses: modelo={$model} intento {$t}/{$tries} " . ($title_for_log ? "| '{$title_for_log}'" : ''), 'INFO');

				$payload = [
					'model' => $model,
					'input' => $input,
					// Max output prudente (luego el prompt manda)
					'max_output_tokens' => (int)($s['responses_max_output_tokens'] ?? 4000),
				];

				$resp = wp_remote_post('https://api.openai.com/v1/responses', [
					'headers' => cbia_http_headers_openai($api_key),
					'body'    => wp_json_encode($payload),
					'timeout' => 180,
				]);

				if (is_wp_error($resp)) {
					$err = $resp->get_error_message();
					cbia_log("HTTP error: {$err}", 'ERROR');
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code($resp);
				$body = (string) wp_remote_retrieve_body($resp);
				$data = json_decode($body, true);

				if ($code < 200 || $code >= 300) {
					$msg = '';
					if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
					$err = "HTTP {$code}" . ($msg ? " | {$msg}" : '');
					cbia_log("OpenAI error: {$err}", 'ERROR');
					continue;
				}

				if (is_array($data) && !empty($data['error']['message'])) {
					$err = (string)$data['error']['message'];
					cbia_log("OpenAI error payload: {$err}", 'ERROR');
					continue;
				}

				$text = cbia_extract_text_from_responses_payload(is_array($data) ? $data : []);
				$usage = cbia_usage_from_responses_payload(is_array($data) ? $data : []);

				if (trim($text) === '') {
					cbia_log("Respuesta vacía en modelo {$model}", 'ERROR');
					continue;
				}

				return [true, $text, $usage, $model, '', $data];
			}
		}

		return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model_preferred, 'No se pudo obtener contenido tras reintentos/fallbacks', []];
	}
}

/* =========================================================
   ===================== SEO (BÁSICO) =======================
   ========================================================= */

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

/* =========================================================
   ============== CATEGORÍAS Y TAGS (REGLAS) =================
   ========================================================= */

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

/* =========================================================
   ================== MARCADORES DE IMAGEN ==================
   ========================================================= */

if (!function_exists('cbia_marker_regex')) {
	function cbia_marker_regex() {
		return '/\[(IMAGEN|IMAGE|IMMAGINE|IMAGEM|BILD|FOTO)\s*:\s*([^\]]+?)\]/i';
	}
}

if (!function_exists('cbia_marker_pending_regex')) {
	function cbia_marker_pending_regex() {
		return '/\[IMAGEN_PENDIENTE\s*:\s*([^\]]+?)\]/i';
	}
}

if (!function_exists('cbia_extract_image_markers')) {
	function cbia_extract_image_markers($html) {
		$markers = [];
		if (preg_match_all(cbia_marker_regex(), (string)$html, $m, PREG_OFFSET_CAPTURE)) {
			foreach ($m[0] as $idx => $fullCap) {
				$markers[] = [
					'full' => $fullCap[0],
					'pos'  => (int)$fullCap[1],
					'desc' => (string)$m[2][$idx][0],
				];
			}
		}
		return $markers;
	}
}

if (!function_exists('cbia_extract_pending_markers')) {
	function cbia_extract_pending_markers($html) {
		$markers = [];
		if (preg_match_all(cbia_marker_pending_regex(), (string)$html, $m, PREG_OFFSET_CAPTURE)) {
			foreach ($m[0] as $idx => $fullCap) {
				$markers[] = [
					'full' => $fullCap[0],
					'pos'  => (int)$fullCap[1],
					'desc' => (string)$m[1][$idx][0],
				];
			}
		}
		return $markers;
	}
}

if (!function_exists('cbia_sanitize_alt_from_desc')) {
	function cbia_sanitize_alt_from_desc($desc) {
		$alt = wp_strip_all_tags((string)$desc);
		$alt = preg_replace('/\s+/', ' ', $alt);
		$alt = trim($alt);
		return trim(mb_substr($alt, 0, 140));
	}
}

if (!function_exists('cbia_build_img_alt')) {
	function cbia_build_img_alt($title, $section_name, $summary_prompt) {
		$base = cbia_sanitize_alt_from_desc($summary_prompt);
		$parts = array_filter([$title, ucfirst((string)$section_name), $base]);
		$alt = implode(' – ', array_unique($parts));
		return trim(mb_substr($alt, 0, 140));
	}
}

if (!function_exists('cbia_detect_marker_section')) {
	function cbia_detect_marker_section($html, $marker_pos, $is_first) {
		if ($is_first) return 'intro';
		$len = strlen((string)$html);
		// Si está cerca del final => conclusion
		if ($marker_pos > max((int)(0.85 * $len), $len - 2500)) return 'conclusion';
		// Si hay FAQ y el marcador está después => faq
		if (preg_match('/<h2[^>]*>\s*preguntas\s+frecuentes\s*<\/h2>/i', (string)$html, $mm, PREG_OFFSET_CAPTURE)) {
			$faq_pos = (int)$mm[0][1];
			if ($marker_pos > $faq_pos) return 'faq';
		}
		return 'body';
	}
}

if (!function_exists('cbia_replace_first_occurrence')) {
	function cbia_replace_first_occurrence(&$html, $search, $replace) {
		$pos = strpos($html, $search);
		if ($pos === false) return false;
		$html = substr($html, 0, $pos) . $replace . substr($html, $pos + strlen($search));
		return true;
	}
}

/* =========================================================
   ================ HELPERS: CLEANUP TEXTO =================
   ========================================================= */
if (!function_exists('cbia_remove_marker_ex')) {
	function cbia_remove_marker_ex(&$html, $marker_text) {
		$m = preg_quote((string)$marker_text, '/');
		// Elimina marcador + espacios + punto opcional
		$pattern = '/\s*' . $m . '\s*\.?\s*/u';
		$html = preg_replace($pattern, "\n", (string)$html, 1);
	}
}

if (!function_exists('cbia_cleanup_post_html')) {
	function cbia_cleanup_post_html(&$html) {
		$h = (string)$html;
		// 1) Quitar spans vacíos de pendientes
		$h = preg_replace('/\s*<span[^>]*class=("|\')cbia-img-pendiente\1[^>]*>\s*<\/span>\s*/iu', "\n", $h);
		// 2) Quitar punto huérfano justo después del span pendiente: </span>.
		$h = preg_replace('/(<\/span>)\s*\.\s*/iu', '$1', $h);
		// 3) Quitar punto en la línea siguiente si viene tras </span>\n.
		$h = preg_replace('/<\/span>\s*(\r?\n)\s*\.\s*(\r?\n|$)/iu', "</span>$1", $h);
			// 3.1) Quitar punto suelto justo después de cierres de bloque: </p>., </h2>. etc.
			$h = preg_replace('/(<\/(p|h2|h3|ul|ol|li|div|section|article|figure)>)\s*\.\s*(?=$|\r?\n)/iu', '$1', $h);
		// 4) Quitar líneas que solo contienen un punto
		$h = preg_replace('/(^|\r?\n)[ \t]*\.[ \t]*(?=\r?\n|$)/mu', "$1", $h);
		// 5) Colapsar 3+ saltos a 2
		$h = preg_replace('/(\r?\n){3,}/', "\n\n", $h);
		$html = trim($h);
	}
}

if (!function_exists('cbia_fix_bracket_headings')) {
	function cbia_fix_bracket_headings($html) {
		$h = (string)$html;
		// Patrón con cierre correspondiente
		$h = preg_replace('/\[(h2|h3)\](.*?)\[\/\1\]/is', '<$1>$2</$1>', $h);
		// Fallback sueltos
		$h = str_replace(array('[h2]','[/h2]','[h3]','[/h3]'), array('<h2>','</h2>','<h3>','</h3>'), $h);
		return $h;
	}
}

if (!function_exists('cbia_normalize_faq_heading')) {
	function cbia_normalize_faq_heading($html) {
		$s = function_exists('cbia_get_settings') ? cbia_get_settings() : array();
		$lang = trim((string)($s['post_language'] ?? 'español'));
		$custom = trim((string)($s['faq_heading_custom'] ?? ''));

		$target = $custom;
		if ($target === '') {
			$map = array(
				'español'   => 'Preguntas frecuentes',
				'portugués' => 'Perguntas frequentes',
				'inglés'    => 'Frequently Asked Questions',
				'francés'   => 'Questions fréquentes',
				'italiano'  => 'Domande frequenti',
				'alemán'    => 'Häufig gestellte Fragen',
			);
			$target = $map[$lang] ?? 'Preguntas frecuentes';
		}

		$pattern = '/<h2\b[^>]*>\s*(preguntas\s+frecuentes|faq|frequently\s+asked\s+questions|perguntas\s+frequentes|questions\s+fréquentes|domande\s+frequenti|häufig\s+gestellte\s+fragen)\s*<\/h2>/iu';
		$replacement = '<h2>' . esc_html($target) . '</h2>';

		$new = preg_replace($pattern, $replacement, (string)$html, 1);
		return $new !== null ? $new : $html;
	}
}

/* =========================================================
   =================== OPENAI: IMÁGENES =====================
   ========================================================= */

if (!function_exists('cbia_image_model_chain')) {
	function cbia_image_model_chain() {
		return ['gpt-image-1-mini', 'gpt-image-1'];
	}
}

/**
 * Catálogo (para UI y coherencia v8.4)
 */
if (!function_exists('cbia_image_formats_catalog')) {
	function cbia_image_formats_catalog(): array {
		return [
			'panoramic_1536x1024' => [
				'label' => 'Panorámica (1536x1024)',
				'size'  => '1536x1024',
				'type'  => 'panoramic',
			],
			'banner_1536x1024' => [
				'label' => 'Banner (1536x1024, encuadre amplio + headroom 25–35%)',
				'size'  => '1536x1024',
				'type'  => 'banner',
			],
		];
	}
}

if (!function_exists('cbia_get_image_format_for_section')) {
	/**
	 * IMPORTANTE (como en v8.4):
	 * - destacada/intro => panorámica
	 * - resto => banner
	 * Aunque haya settings guardados, aquí se fuerza por compatibilidad.
	 */
	function cbia_get_image_format_for_section($section): string {
		$section = sanitize_key((string)$section);
		return ($section === 'intro') ? 'panoramic_1536x1024' : 'banner_1536x1024';
	}
}

if (!function_exists('cbia_is_banner_format')) {
	function cbia_is_banner_format($format_key): bool {
		$catalog = cbia_image_formats_catalog();
		if (!isset($catalog[$format_key])) return false;
		return (($catalog[$format_key]['type'] ?? '') === 'banner');
	}
}

if (!function_exists('cbia_build_content_img_tag')) {
	/**
	 * Tag <img> en contenido:
	 * - decoding="async"
	 * - banner => class="cbia-banner lazyloaded" (como v8.4)
	 */
	function cbia_build_content_img_tag($url, $alt, $section): string {
		$url = (string)$url;
		$alt = (string)$alt;
		$section = sanitize_key((string)$section);

		$fmt = cbia_get_image_format_for_section($section);

		$classes = [];
		if (cbia_is_banner_format($fmt)) {
			$classes[] = 'cbia-banner';
			$classes[] = 'lazyloaded';
		}

		$class_attr = !empty($classes) ? ' class="' . esc_attr(implode(' ', $classes)) . '"' : '';
		return '<img decoding="async" loading="lazy"' . $class_attr . ' src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" style="display:block;width:100%;height:auto;margin:15px 0;" />';
	}
}

if (!function_exists('cbia_build_image_prompt')) {
	function cbia_build_image_prompt($desc, $section, $title) {
		$s = cbia_get_settings();

		$base = (string)($s['prompt_img_' . $section] ?? '');
		if (trim($base) === '') {
			$base = 'Imagen editorial realista que ilustre el concepto principal de "{title}". Sin texto ni logos. Sin marcas de agua. Estilo realista/editorial, natural, sin filtros fuertes. Composición limpia.';
		}

		$base = str_replace('{title}', $title, $base);

		// Ajuste por formato (v8.4): intro panorámica, resto banner
		$fmt = cbia_get_image_format_for_section($section);
		if (cbia_is_banner_format($fmt)) {
			$base = "Toma amplia (long shot), sujeto pequeño, headroom 25–35%, márgenes laterales generosos, sin primeros planos. " . $base;
		}

		$desc = trim((string)$desc);
		if ($desc !== '') $base .= "\n\nDescripción concreta de la escena: " . $desc;

		// Reglas duras
		$base .= "\n\nReglas: NO texto, NO logos, NO marcas de agua, NO tipografías, NO carteles legibles.";

		return trim($base);
	}
}

if (!function_exists('cbia_image_size_for_section')) {
	function cbia_image_size_for_section($section) {
		$fmt = cbia_get_image_format_for_section($section);
		$catalog = cbia_image_formats_catalog();
		if (!isset($catalog[$fmt])) return '1536x1024';
		return (string)($catalog[$fmt]['size'] ?? '1536x1024');
	}
}

if (!function_exists('cbia_upload_image_to_media')) {
	function cbia_upload_image_to_media($bytes, $title, $section, $alt_text) {
		$bytes = (string)$bytes;
		if ($bytes === '') return [false, 'bytes_vacios'];

		$filename = sanitize_title($title . '-' . $section) . '-' . wp_generate_password(6, false) . '.png';

		$upload = wp_upload_bits($filename, null, $bytes);
		if (!$upload || !empty($upload['error'])) {
			return [false, 'upload_error: ' . ($upload['error'] ?? 'desconocido')];
		}

		$wp_filetype = wp_check_filetype($filename, null);
		$attachment = [
			'post_mime_type' => $wp_filetype['type'] ?: 'image/png',
			'post_title'     => sanitize_text_field($title . ' - ' . $section),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$attach_id = wp_insert_attachment($attachment, $upload['file']);
		if (is_wp_error($attach_id) || !$attach_id) {
			return [false, 'wp_insert_attachment_error'];
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
		wp_update_attachment_metadata($attach_id, $attach_data);

		update_post_meta($attach_id, '_wp_attachment_image_alt', $alt_text);

		return [$attach_id, ''];
	}
}

if (!function_exists('cbia_generate_image_openai')) {
	/**
	 * Retorna [ok(bool), attach_id(int), model_used(string), err(string)]
	 */
	function cbia_generate_image_openai($desc, $section, $title) {
		cbia_try_unlimited_runtime();
		$api_key = cbia_openai_api_key();
		if (!$api_key) return [false, 0, '', 'No hay API key'];

		if (cbia_is_stop_requested()) return [false, 0, '', 'STOP activado'];

		$s = cbia_get_settings();
		$blocked = $s['blocked_models'] ?? [];
		if (!is_array($blocked)) $blocked = [];

		$prompt = cbia_build_image_prompt($desc, $section, $title);
		$size = cbia_image_size_for_section($section);
		$alt  = cbia_build_img_alt($title, $section, $desc);

		foreach (cbia_image_model_chain() as $model) {
			// Si el usuario bloquea también modelos imagen en el mismo array, lo respetamos
			if (!empty($blocked[$model])) continue;

			$tries = 2;
			for ($t = 1; $t <= $tries; $t++) {
				if (cbia_is_stop_requested()) return [false, 0, $model, 'STOP activado'];

				cbia_log("Imagen IA: modelo={$model} sección={$section} intento {$t}/{$tries}", 'INFO');

				$payload = [
					'model'  => $model,
					'prompt' => $prompt,
					'n'      => 1,
					'size'   => $size,
				];

				$resp = wp_remote_post('https://api.openai.com/v1/images/generations', [
					'headers' => cbia_http_headers_openai($api_key),
					'body'    => wp_json_encode($payload),
					'timeout' => 180,
				]);

				if (is_wp_error($resp)) {
					cbia_log("Imagen IA HTTP error: " . $resp->get_error_message(), 'ERROR');
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code($resp);
				$body = (string) wp_remote_retrieve_body($resp);
				$data = json_decode($body, true);

				if ($code < 200 || $code >= 300) {
					$msg = '';
					if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
					cbia_log("Imagen IA error HTTP {$code}" . ($msg ? " | {$msg}" : ''), 'ERROR');
					continue;
				}

				if (is_array($data) && !empty($data['error']['message'])) {
					cbia_log("Imagen IA error payload: " . (string)$data['error']['message'], 'ERROR');
					continue;
				}

				$bytes = '';
				if (!empty($data['data'][0]['b64_json'])) {
					$bytes = base64_decode((string)$data['data'][0]['b64_json']);
				} elseif (!empty($data['data'][0]['url'])) {
					$img = wp_remote_get((string)$data['data'][0]['url'], ['timeout' => 60]);
					if (!is_wp_error($img) && (int)wp_remote_retrieve_response_code($img) === 200) {
						$bytes = (string)wp_remote_retrieve_body($img);
					}
				}

				if ($bytes === '') {
					cbia_log("Imagen IA: respuesta sin bytes (modelo={$model})", 'ERROR');
					continue;
				}

				list($attach_id, $uerr) = cbia_upload_image_to_media($bytes, $title, $section, $alt);
				if (!$attach_id) {
					cbia_log("Imagen IA: fallo subiendo a Media Library: {$uerr}", 'ERROR');
					continue;
				}

				return [true, (int)$attach_id, $model, ''];
			}
		}

		return [false, 0, '', 'No se pudo generar imagen tras reintentos'];
	}
}

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

		// Yoast básico (luego en cbia-yoast.php mejoraremos con hook)
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
			return ['ok'=>false,'post_id'=>0,'error'=>$err ?: 'Fallo texto'];
		}

		$text_html = cbia_strip_document_wrappers($text_html);
		$text_html = cbia_strip_h1_to_h2($text_html);

		// Corrige encabezados escritos como [h2]...[/h2] / [h3]...[/h3] a HTML real
		$text_html = cbia_fix_bracket_headings($text_html);
		// Normaliza el título de FAQ según idioma/config
		$text_html = cbia_normalize_faq_heading($text_html);

		// 3) Procesar marcadores de imagen
		$internal_limit = max(0, $images_limit - 1);
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

			$is_first = ($i === 0);
			$section = cbia_detect_marker_section($text_html, (int)$mk['pos'], $is_first);

			list($img_ok, $attach_id, $img_model, $img_err) = cbia_generate_image_openai($mk['desc'], $section, $title);

			$image_calls[] = array(
				'section'   => (string)$section,
				'model'     => (string)$img_model,
				'ok'        => $img_ok ? 1 : 0,
				'attach_id' => (int)$attach_id,
				'error'     => (string)($img_err ?: ''),
			);

			if ($img_ok && $attach_id) {
				if ($is_first) {
					$featured_attach_id = (int)$attach_id;
					// Quitamos el marcador de la destacada (no insertamos dentro del contenido)
					cbia_remove_marker_ex($text_html, $mk['full']);
					cbia_log("Imagen destacada OK (attach_id={$attach_id}) '{$title}'", 'INFO');
				} else {
					$url = wp_get_attachment_url((int)$attach_id);
					$alt = cbia_build_img_alt($title, $section, $mk['desc']);
					$img_tag = cbia_build_content_img_tag($url, $alt, $section);
					cbia_replace_first_occurrence($text_html, $mk['full'], $img_tag);
					cbia_log("Imagen insertada OK (attach_id={$attach_id}) sección={$section} '{$title}'", 'INFO');
				}
			} else {
				$desc_clean = cbia_sanitize_alt_from_desc($mk['desc']);
				// Marcador pendiente oculto con CSS
				$placeholder = "<span class='cbia-img-pendiente' style='display:none'>[IMAGEN_PENDIENTE: {$desc_clean}]</span>";
				cbia_replace_first_occurrence($text_html, $mk['full'], $placeholder);

				$pending_list[] = [
					'desc' => $desc_clean,
					'section' => $section,
					'tries' => 1,
					'last_error' => $img_err ?: 'unknown',
					'status' => 'pending',
				];

				cbia_log("Imagen FALLÓ -> PENDIENTE sección={$section} '{$title}' | " . ($img_err ?: ''), 'ERROR');
			}
		}

		// Si quedaron marcadores normales por no procesar (por límite), ELIMÍNALOS del HTML
		$left = cbia_extract_image_markers($text_html);
		if (!empty($left)) {
			// Helper de eliminación robusta (si no existe aún)
			foreach ($left as $mk) {
				cbia_remove_marker_ex($text_html, $mk['full']);
			}
		}

		// Limpieza de artefactos: líneas con solo punto, spans vacíos, exceso de saltos de línea
		cbia_cleanup_post_html($text_html);

		// 4) Crear post
		list($wp_ok, $post_id, $wp_err) = cbia_create_post_in_wp_engine($title, $text_html, $featured_attach_id, $post_date_mysql);
		if (!$wp_ok) {
			cbia_log("Fallo creando post WP '{$title}': {$wp_err}", 'ERROR');
			return ['ok'=>false,'post_id'=>0,'error'=>$wp_err ?: 'Fallo WP'];
		}

		// 5) Guardar metas usage tokens
		$in  = (int)($usage['input_tokens'] ?? 0);
		$out = (int)($usage['output_tokens'] ?? 0);
		$tot = (int)($usage['total_tokens'] ?? 0);

		update_post_meta($post_id, '_cbia_openai_model_used', (string)$model_used);
		update_post_meta($post_id, '_cbia_tokens_input', (string)$in);
		update_post_meta($post_id, '_cbia_tokens_output', (string)$out);
		update_post_meta($post_id, '_cbia_tokens_total', (string)$tot);
		update_post_meta($post_id, '_cbia_usage_captured_at', current_time('mysql'));

		// 5.1) Registrar TEXTO en costes
		if (function_exists('cbia_costes_record_usage') && !empty($text_call)) {
			cbia_costes_record_usage($post_id, array(
				'type' => 'text',
				'model' => (string)($text_call['model'] ?? ''),
				'input_tokens' => (int)($text_call['usage']['input_tokens'] ?? 0),
				'output_tokens' => (int)($text_call['usage']['output_tokens'] ?? 0),
				'cached_input_tokens' => 0,
				'ok' => 1,
			));
		}

		// 5.2) Registrar IMÁGENES en costes
		if (function_exists('cbia_costes_record_usage') && !empty($image_calls)) {
			foreach ($image_calls as $ic) {
				if (!is_array($ic)) continue;
				cbia_costes_record_usage($post_id, array(
					'type' => 'image',
					'model' => (string)($ic['model'] ?? ''),
					'input_tokens' => 0,
					'output_tokens' => 0,
					'cached_input_tokens' => 0,
					'ok' => (int)(!empty($ic['ok'])),
					'error' => (string)($ic['error'] ?? ''),
				));
			}
		}

		// Guardar lista detallada de usage para cálculo de costes real (incluye output/input)
		if (!empty($text_call) && !empty($text_call['model'])) {
			cbia_usage_append_call($post_id, $text_call['context'] ?? 'blog_text', $text_call['model'], $text_call['usage'] ?? cbia_usage_empty(), array(
				'title' => $title,
			));
		}

		// Guardar llamadas de imágenes (para coste por imagen y auditoría)
		if (!empty($image_calls)) {
			foreach ($image_calls as $ic) {
				if (!is_array($ic)) continue;
				cbia_image_append_call($post_id, $ic['section'] ?? 'body', $ic['model'] ?? '', !empty($ic['ok']), (int)($ic['attach_id'] ?? 0), (string)($ic['error'] ?? ''));
			}
		}

		// Conteo palabras aproximado
		$wc = str_word_count(wp_strip_all_tags((string)$text_html));
		update_post_meta($post_id, '_cbia_word_count', (string)$wc);

		// 6) Pendientes
		$pending_count = count($pending_list);
		update_post_meta($post_id, '_cbia_pending_images', (string)$pending_count);
		update_post_meta($post_id, '_cbia_pending_images_list', wp_json_encode($pending_list));

		// 7) Hook para Yoast avanzado / semáforos / etc
		do_action('cbia_after_post_created', $post_id, $title, $text_html, $usage, $model_used);

		cbia_log("Post creado OK ID={$post_id} | status=" . ($post_date_mysql ? 'future' : 'publish') . " | tokens={$tot} | pendientes={$pending_count}", 'INFO');

		return ['ok'=>true,'post_id'=>$post_id,'error'=>''];
	}
}

/* =========================================================
   =========== RELLENAR IMÁGENES PENDIENTES (POST) ==========
   ========================================================= */

if (!function_exists('cbia_fill_pending_images_for_post')) {
	function cbia_fill_pending_images_for_post($post_id, $max_images = 4) {
		cbia_try_unlimited_runtime();
		$post = get_post($post_id);
		if (!$post) return 0;

		$html = (string)$post->post_content;
		$pending = cbia_extract_pending_markers($html);
		if (empty($pending)) {
			update_post_meta($post_id, '_cbia_pending_images', '0');
			return 0;
		}

		$list_raw = get_post_meta($post_id, '_cbia_pending_images_list', true);
		$list = [];
		if ($list_raw) {
			$tmp = json_decode((string)$list_raw, true);
			if (is_array($tmp)) $list = $tmp;
		}

		$title = get_the_title($post_id);
		$filled = 0;

		// Si no tiene destacada, intentamos crear una desde el primer pending como intro
		if (!has_post_thumbnail($post_id)) {
			$desc0 = cbia_sanitize_alt_from_desc($pending[0]['desc'] ?? $title);
			list($ok, $attach_id, $m, $e) = cbia_generate_image_openai($desc0, 'intro', $title);
			if ($ok && $attach_id) {
				set_post_thumbnail($post_id, (int)$attach_id);
				cbia_log("Pendientes: destacada creada en post {$post_id} (attach_id={$attach_id})", 'INFO');
			} else {
				cbia_log("Pendientes: no se pudo crear destacada en post {$post_id}: " . ($e ?: ''), 'ERROR');
			}
		}

		// Helper: reemplazar el token pendiente (dentro de su span) por la imagen generada
		if (!function_exists('cbia_replace_pending_token')) {
			function cbia_replace_pending_token(&$html, $pending_token, $replacement) {
				$token = (string)$pending_token;
				$pattern = '/<span[^>]*class=("|\')cbia-img-pendiente\1[^>]*>\s*' . preg_quote($token, '/') . '\s*<\/span>/iu';
				$count = 0;
				$new = preg_replace($pattern, (string)$replacement, (string)$html, 1, $count);
				if ($count > 0 && $new !== null) {
					$html = $new;
					return true;
				}
				cbia_replace_first_occurrence($html, $token, (string)$replacement);
				return true;
			}
		}

		foreach ($pending as $pk => $pm) {
			if (cbia_is_stop_requested()) break;
			if ($filled >= $max_images) break;

			$desc = (string)$pm['desc'];
			// Detectar sección nuevamente por posición actual del marcador (puede haber cambiado)
			$current_pos = strpos($html, $pm['full']);
			$section = ($current_pos !== false) ? cbia_detect_marker_section($html, (int)$current_pos, false) : 'body';

			list($ok, $attach_id, $m, $e) = cbia_generate_image_openai($desc, $section, $title);
			if ($ok && $attach_id) {
				// Registrar usage de imagen en costes (success)
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
				cbia_replace_pending_token($html, $pm['full'], $img_tag);
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
