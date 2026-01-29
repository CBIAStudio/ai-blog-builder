<?php
/**
 * OpenAI calls (Responses + Images).
 */

if (!defined('ABSPATH')) exit;

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

				$max_out = (int)($s['responses_max_output_tokens'] ?? 6000);
				if ($max_out < 1500) $max_out = 1500;
				if ($max_out > 12000) $max_out = 12000;

				$payload = [
					'model' => $model,
					'input' => $input,
					// Max output prudente (luego el prompt manda)
					'max_output_tokens' => $max_out,
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

				$text = cbia_extract_text_from_responses_payload($data);
				$usage = cbia_usage_from_responses_payload($data);

				if ($text === '') {
					cbia_log("Respuesta sin texto (modelo={$model})", 'ERROR');
					continue;
				}

				return [true, $text, $usage, $model, '', $data];
			}
		}

		return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No se pudo obtener respuesta', []];
	}
}

/* =========================================================
   ================== OPENAI: IMÁGENES ======================
   ========================================================= */

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
