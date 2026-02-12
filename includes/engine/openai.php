<?php
/**
 * OpenAI calls (Responses + Images).
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_get_current_provider_key')) {
	function cbia_get_current_provider_key(): string {
		// CAMBIO: usa proveedor de texto (no imagen)
		if (function_exists('cbia_get_text_provider')) {
			$p = cbia_get_text_provider();
			return $p !== '' ? $p : 'openai';
		}
		if (function_exists('cbia_providers_get_settings') && function_exists('cbia_providers_get_current_provider')) {
			$provider_settings = cbia_providers_get_settings();
			$current_provider = cbia_providers_get_current_provider($provider_settings);
			return $current_provider ?: 'openai';
		}
		return 'openai';
	}
}

// CAMBIO: helpers Google Imagen/Gemini
if (!function_exists('cbia_base64url_encode')) {
	function cbia_base64url_encode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}

if (!function_exists('cbia_google_get_service_account_token')) {
	/**
	 * Obtiene access_token a partir de Service Account JSON (JWT OAuth).
	 * Retorna [token, err]
	 */
	function cbia_google_get_service_account_token(string $service_json): array {
		$service_json = trim($service_json);
		if ($service_json === '') return ['', 'Service Account JSON vacio'];

		$info = json_decode($service_json, true);
		if (!is_array($info)) return ['', 'Service Account JSON invalido'];

		$client_email = (string)($info['client_email'] ?? '');
		$private_key  = (string)($info['private_key'] ?? '');
		$token_uri    = (string)($info['token_uri'] ?? 'https://oauth2.googleapis.com/token');
		if ($client_email === '' || $private_key === '') {
			return ['', 'Service Account JSON incompleto'];
		}

		$cache_key = 'cbia_google_sa_token_' . md5($client_email);
		$cached = get_transient($cache_key);
		if (is_string($cached) && $cached !== '') {
			return [$cached, ''];
		}

		$iat = time();
		$exp = $iat + 3600;
		$header = cbia_base64url_encode(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
		$claims = cbia_base64url_encode(wp_json_encode([
			'iss' => $client_email,
			'scope' => 'https://www.googleapis.com/auth/cloud-platform',
			'aud' => $token_uri,
			'iat' => $iat,
			'exp' => $exp,
		]));
		$to_sign = $header . '.' . $claims;
		$signature = '';
		$ok = openssl_sign($to_sign, $signature, $private_key, 'sha256');
		if (!$ok) {
			return ['', 'No se pudo firmar JWT (Service Account)'];
		}
		$jwt = $to_sign . '.' . cbia_base64url_encode($signature);

		$resp = wp_remote_post($token_uri, [
			'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
			'body' => http_build_query([
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion' => $jwt,
			]),
			'timeout' => 30,
		]);
		if (is_wp_error($resp)) {
			return ['', $resp->get_error_message()];
		}
		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);
		$data = json_decode($body, true);
		if ($code < 200 || $code >= 300 || !is_array($data) || empty($data['access_token'])) {
			$msg = is_array($data) && !empty($data['error_description']) ? (string)$data['error_description'] : 'Error token OAuth';
			return ['', $msg . " (HTTP {$code})"];
		}

		$token = (string)$data['access_token'];
		set_transient($cache_key, $token, 3300); // ~55 min
		return [$token, ''];
	}
}

if (!function_exists('cbia_google_imagen_model_id')) {
	function cbia_google_imagen_model_id(string $model): string {
		// CAMBIO: mapeo legacy "imagen-2" a modelo Vertex AI
		if ($model === 'imagen-2') return 'imagegeneration@002';
		return $model;
	}
}

if (!function_exists('cbia_google_image_aspect_ratio_from_size')) {
	function cbia_google_image_aspect_ratio_from_size(string $size): string {
		$parts = explode('x', strtolower($size));
		if (count($parts) !== 2) return '';
		$w = (int)$parts[0];
		$h = (int)$parts[1];
		if ($w <= 0 || $h <= 0) return '';
		$ratio = $w / $h;
		if (abs($ratio - 1.0) < 0.1) return '1:1';
		if ($ratio >= 1.6) return '16:9';
		if ($ratio > 1.0) return '4:3';
		if ($ratio <= 0.65) return '9:16';
		return '3:4';
	}
}

if (!function_exists('cbia_google_generate_image_gemini')) {
	/**
	 * Google Gemini Image (gemini-3-pro-image-preview).
	 * Retorna [ok, attach_id, model, err]
	 */
	function cbia_google_generate_image_gemini($prompt, $section, $title, $alt_text, $idx, $model) {
		$cfg = cbia_get_provider_config('google');
		$api_key = function_exists('cbia_get_provider_api_key') ? cbia_get_provider_api_key('google') : (string)($cfg['api_key'] ?? '');
		if ($api_key === '') {
			cbia_log(('Falta la API key de Google para generar imagenes (Gemini).'), 'ERROR');
			return [false, 0, (string)$model, 'No hay API key (Google)'];
		}

		$base_url = rtrim((string)($cfg['base_url'] ?? 'https://generativelanguage.googleapis.com'), '/');
		$api_version = trim((string)($cfg['api_version'] ?? 'v1beta'), '/');
		$url = $base_url . '/' . $api_version . '/models/' . rawurlencode((string)$model) . ':generateContent';

		$size = cbia_image_size_for_section($section, $idx);
		$aspect = cbia_google_image_aspect_ratio_from_size($size);

		$payload = [
			'contents' => [
				[
					'role' => 'user',
					'parts' => [
						['text' => (string)$prompt],
					],
				],
			],
			'generationConfig' => [
				'responseModalities' => ['TEXT', 'IMAGE'],
			],
		];
		if ($aspect !== '') {
			$payload['generationConfig']['imageConfig'] = ['aspectRatio' => $aspect];
		}

		$resp = wp_remote_post($url, [
			'headers' => [
				'Content-Type' => 'application/json',
				'x-goog-api-key' => $api_key,
			],
			'body' => wp_json_encode($payload),
			'timeout' => 60,
		]);
		if (is_wp_error($resp)) {
			cbia_log(('Google Gemini Image HTTP error: ') . $resp->get_error_message(), 'ERROR');
			return [false, 0, (string)$model, $resp->get_error_message()];
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);
		$data = json_decode($body, true);
		if ($code < 200 || $code >= 300) {
			$msg = is_array($data) && !empty($data['error']['message']) ? (string)$data['error']['message'] : 'HTTP error';
			cbia_log(sprintf(('Google Gemini Image error HTTP %s | %s'), $code, $msg), 'ERROR');
			return [false, 0, (string)$model, $msg];
		}

		$bytes = '';
		if (!empty($data['candidates'][0]['content']['parts']) && is_array($data['candidates'][0]['content']['parts'])) {
			foreach ($data['candidates'][0]['content']['parts'] as $p) {
				if (!is_array($p)) continue;
				if (!empty($p['inlineData']['data'])) {
					$bytes = base64_decode((string)$p['inlineData']['data']);
					break;
				}
				if (!empty($p['inline_data']['data'])) {
					$bytes = base64_decode((string)$p['inline_data']['data']);
					break;
				}
			}
		}

		if ($bytes === '') {
			cbia_log(('Google Gemini Image: respuesta sin bytes.'), 'ERROR');
			return [false, 0, (string)$model, 'Respuesta sin imagen'];
		}

		$alt = $alt_text !== '' ? (string)$alt_text : cbia_build_img_alt($title, $section, $prompt);
		list($attach_id, $uerr) = cbia_upload_image_to_media($bytes, $title, $section, $alt);
		if (!$attach_id) {
			cbia_log(('Google Gemini Image: fallo subiendo a Media Library: ') . $uerr, 'ERROR');
			return [false, 0, (string)$model, $uerr];
		}

		return [true, (int)$attach_id, (string)$model, ''];
	}
}

if (!function_exists('cbia_google_generate_image_imagen')) {
	/**
	 * Google Imagen (Vertex AI) con Service Account.
	 * Retorna [ok, attach_id, model, err]
	 */
	function cbia_google_generate_image_imagen($prompt, $section, $title, $alt_text, $idx, $model) {
		$project_id = function_exists('cbia_get_google_project_id') ? cbia_get_google_project_id() : '';
		$location   = function_exists('cbia_get_google_location') ? cbia_get_google_location() : '';
		$sa_json    = function_exists('cbia_get_google_service_account_json') ? cbia_get_google_service_account_json() : '';
		if ($project_id === '' || $location === '' || trim($sa_json) === '') {
			cbia_log(('Faltan datos de Google Vertex AI (Project ID/Location/Service Account JSON).'), 'ERROR');
			return [false, 0, (string)$model, 'Faltan datos Vertex AI'];
		}

		list($token, $tok_err) = cbia_google_get_service_account_token($sa_json);
		if ($token === '') {
			cbia_log(('Google Vertex AI token error: ') . $tok_err, 'ERROR');
			return [false, 0, (string)$model, $tok_err];
		}

		$vertex_model = cbia_google_imagen_model_id((string)$model);
		$url = 'https://' . $location . '-aiplatform.googleapis.com/v1/projects/' . rawurlencode($project_id) . '/locations/' . rawurlencode($location) . '/publishers/google/models/' . rawurlencode($vertex_model) . ':predict';

		$payload = [
			'instances' => [
				['prompt' => (string)$prompt],
			],
			'parameters' => [
				'sampleCount' => 1,
			],
		];

		$resp = wp_remote_post($url, [
			'headers' => [
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			],
			'body' => wp_json_encode($payload),
			'timeout' => 60,
		]);
		if (is_wp_error($resp)) {
			cbia_log(('Google Imagen HTTP error: ') . $resp->get_error_message(), 'ERROR');
			return [false, 0, (string)$model, $resp->get_error_message()];
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);
		$data = json_decode($body, true);
		if ($code < 200 || $code >= 300) {
			$msg = is_array($data) && !empty($data['error']['message']) ? (string)$data['error']['message'] : 'HTTP error';
			cbia_log(sprintf(('Google Imagen error HTTP %s | %s'), $code, $msg), 'ERROR');
			return [false, 0, (string)$model, $msg];
		}

		$bytes = '';
		if (!empty($data['predictions'][0]['bytesBase64Encoded'])) {
			$bytes = base64_decode((string)$data['predictions'][0]['bytesBase64Encoded']);
		} elseif (!empty($data['predictions'][0]['image'])) {
			$bytes = base64_decode((string)$data['predictions'][0]['image']);
		}

		if ($bytes === '') {
			cbia_log(('Google Imagen: respuesta sin bytes.'), 'ERROR');
			return [false, 0, (string)$model, 'Respuesta sin imagen'];
		}

		$alt = $alt_text !== '' ? (string)$alt_text : cbia_build_img_alt($title, $section, $prompt);
		list($attach_id, $uerr) = cbia_upload_image_to_media($bytes, $title, $section, $alt);
		if (!$attach_id) {
			cbia_log(('Google Imagen: fallo subiendo a Media Library: ') . $uerr, 'ERROR');
			return [false, 0, (string)$model, $uerr];
		}

		return [true, (int)$attach_id, (string)$model, ''];
	}
}

if (!function_exists('cbia_google_generate_image_with_prompt')) {
	/**
	 * Google imagen (Gemini o Imagen). Retorna [ok, attach_id, model, err]
	 */
	function cbia_google_generate_image_with_prompt($prompt, $section, $title, $alt_text = '', $idx = 0) {
		$model = function_exists('cbia_get_image_model_for_provider')
			? cbia_get_image_model_for_provider('google', function_exists('cbia_providers_get_recommended_image_model') ? cbia_providers_get_recommended_image_model('google') : 'imagen-2')
			: 'imagen-2';

		if ($model === 'imagen-2') {
			return cbia_google_generate_image_imagen($prompt, $section, $title, $alt_text, $idx, $model);
		}
		return cbia_google_generate_image_gemini($prompt, $section, $title, $alt_text, $idx, $model);
	}
}

if (!function_exists('cbia_google_generate_image')) {
	function cbia_google_generate_image($desc, $section, $title, $idx = 0) {
		$prompt = cbia_build_image_prompt($desc, $section, $title);
		return cbia_google_generate_image_with_prompt($prompt, $section, $title, '', $idx);
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
			// CAMBIO: proveedor de texto
			$provider = cbia_get_current_provider_key();
		if (!cbia_openai_consent_ok()) {
			return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'Consentimiento OpenAI no aceptado', []];
		}

		$s = cbia_get_settings();
		// CAMBIO: modelo segun proveedor texto
		$model_preferred = function_exists('cbia_get_text_model_for_provider')
			? cbia_get_text_model_for_provider($provider, cbia_pick_model())
			: cbia_pick_model();
		$chain = cbia_model_fallback_chain($model_preferred);

		$system = "Eres un redactor editorial. Devuelve HTML simple con <h2>, <h3>, <p>, <ul>, <li>. NO uses <h1> ni envolturas <html>/<head>/<body>. No uses <table>, <iframe> ni <blockquote>.";
		$input = [
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => (string)$prompt],
		];

		if ($provider === 'google') {
			return cbia_google_generate_content_call($prompt, $system, $tries);
		}
		if ($provider === 'deepseek') {
			return cbia_deepseek_chat_call($prompt, $system, $tries);
		}
		// CAMBIO: key OpenAI desde settings por proveedor
		$api_key = function_exists('cbia_get_provider_api_key') ? cbia_get_provider_api_key('openai') : cbia_openai_api_key();
		if (!$api_key) {
			cbia_log(('Falta la API key de OpenAI para generar texto.'), 'ERROR');
			return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No hay API key', []];
		}

		foreach ($chain as $model) {
			if (!cbia_is_responses_model($model)) continue;

			for ($t = 1; $t <= max(1, (int)$tries); $t++) {
				if (cbia_is_stop_requested()) {
					return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'STOP activado', []];
				}

				cbia_log(("OpenAI Responses: modelo={$model} intento {$t}/{$tries} ") . ($title_for_log ? "| '{$title_for_log}'" : ''), 'INFO');

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
					'timeout' => 60,
				]);

				if (is_wp_error($resp)) {
					$err = $resp->get_error_message();
					cbia_log(("HTTP error: {$err}"), 'ERROR');
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code($resp);
				$body = (string) wp_remote_retrieve_body($resp);
				$data = json_decode($body, true);

				if ($code < 200 || $code >= 300) {
					$msg = '';
					if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
					$err = "HTTP {$code}" . ($msg ? " | {$msg}" : '');
					cbia_log(("OpenAI error: {$err}"), 'ERROR');
					continue;
				}

				if (is_array($data) && !empty($data['error']['message'])) {
					$err = (string)$data['error']['message'];
					cbia_log(("OpenAI error payload: {$err}"), 'ERROR');
					continue;
				}

				$text = cbia_extract_text_from_responses_payload($data);
				$usage = cbia_usage_from_responses_payload($data);

				if ($text === '') {
					cbia_log(("Respuesta sin texto (modelo={$model})"), 'ERROR');
					continue;
				}

				cbia_log(("OpenAI Responses OK: modelo={$model} tokens_in=") . (int)($usage['input_tokens'] ?? 0) . " tokens_out=" . (int)($usage['output_tokens'] ?? 0), 'INFO');

				return [true, $text, $usage, $model, '', $data];
			}
		}

		return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No se pudo obtener respuesta', []];
	}
}

if (!function_exists('cbia_openai_responses_stream_call')) {
	/**
	 * Streaming real con OpenAI Responses API.
	 * Devuelve: [ok, text, usage, model_used, err, raw]
	 */
	function cbia_openai_responses_stream_call($prompt, $title_for_log = '', $tries = 2, $on_delta = null) {
		cbia_try_unlimited_runtime();
		$provider = cbia_get_current_provider_key();
		if ($provider !== 'openai') {
			return cbia_openai_responses_call($prompt, $title_for_log, $tries);
		}
		if (!cbia_openai_consent_ok()) {
			return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'Consentimiento OpenAI no aceptado', []];
		}

		$s = cbia_get_settings();
		$model_preferred = function_exists('cbia_get_text_model_for_provider')
			? cbia_get_text_model_for_provider($provider, cbia_pick_model())
			: cbia_pick_model();
		$chain = cbia_model_fallback_chain($model_preferred);
		$api_key = function_exists('cbia_get_provider_api_key') ? cbia_get_provider_api_key('openai') : cbia_openai_api_key();
		if (!$api_key) {
			cbia_log(('Falta la API key de OpenAI para generar texto.'), 'ERROR');
			return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No hay API key', []];
		}

		$system = "Eres un redactor editorial. Devuelve HTML simple con <h2>, <h3>, <p>, <ul>, <li>. NO uses <h1> ni envolturas <html>/<head>/<body>. No uses <table>, <iframe> ni <blockquote>.";
		$input = [
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => (string)$prompt],
		];

		foreach ($chain as $model) {
			if (!cbia_is_responses_model($model)) continue;
			for ($t = 1; $t <= max(1, (int)$tries); $t++) {
				if (cbia_is_stop_requested()) {
					return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'STOP activado', []];
				}

				cbia_log(("OpenAI Responses STREAM: modelo={$model} intento {$t}/{$tries} ") . ($title_for_log ? "| '{$title_for_log}'" : ''), 'INFO');

				$max_out = (int)($s['responses_max_output_tokens'] ?? 6000);
				if ($max_out < 1500) $max_out = 1500;
				if ($max_out > 12000) $max_out = 12000;

				$payload = [
					'model' => $model,
					'input' => $input,
					'max_output_tokens' => $max_out,
					'stream' => true,
				];

				$acc_text = '';
				$last_usage = ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0];
				$last_error = '';
				$last_event = [];
				$line_buffer = '';

				$resp = wp_remote_post('https://api.openai.com/v1/responses', [
					'headers' => [
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					],
					'body'    => wp_json_encode($payload),
					'timeout' => 120,
				]);
				if (is_wp_error($resp)) {
					$last_error = $resp->get_error_message();
					cbia_log(sprintf('HTTP stream error: %s', $last_error), 'ERROR');
					continue;
				}
				$http_code = (int) wp_remote_retrieve_response_code($resp);
				$body = (string) wp_remote_retrieve_body($resp);
				$lines = preg_split("/\r\n|\n|\r/", $body);
				if (is_array($lines)) {
					foreach ($lines as $line) {
						$line = trim((string)$line);
						if ($line === '' || stripos($line, 'data:') !== 0) continue;
						$json = trim(substr($line, 5));
						if ($json === '[DONE]') continue;
						$evt = json_decode($json, true);
						if (!is_array($evt)) continue;
						$last_event = $evt;
						if (!empty($evt['error']['message'])) {
							$last_error = (string)$evt['error']['message'];
							continue;
						}
						$delta = '';
						if (isset($evt['delta']) && is_string($evt['delta'])) $delta = $evt['delta'];
						elseif (isset($evt['text']) && is_string($evt['text'])) $delta = $evt['text'];
						elseif (isset($evt['output_text']) && is_string($evt['output_text'])) $delta = $evt['output_text'];
						if ($delta !== '') {
							$acc_text .= $delta;
							if (is_callable($on_delta)) call_user_func($on_delta, $delta, $acc_text, $last_usage);
						}
						if (!empty($evt['response']) && is_array($evt['response'])) {
							$maybe_text = cbia_extract_text_from_responses_payload($evt['response']);
							if ($maybe_text !== '') $acc_text = $maybe_text;
							$last_usage = cbia_usage_from_responses_payload($evt['response']);
						} elseif (!empty($evt['usage']) && is_array($evt['usage'])) {
							$in = (int)($evt['usage']['input_tokens'] ?? 0);
							$out = (int)($evt['usage']['output_tokens'] ?? 0);
							$total = (int)($evt['usage']['total_tokens'] ?? ($in + $out));
							$last_usage = ['input_tokens' => $in, 'output_tokens' => $out, 'total_tokens' => $total];
						}
					}
				}
				if ($http_code < 200 || $http_code >= 300) {
					$last_error = 'HTTP ' . $http_code . ($last_error ? (' | ' . $last_error) : '');
					cbia_log(("OpenAI stream error: {$last_error}"), 'ERROR');
					continue;
				}
				if ($last_error !== '') {
					cbia_log(("OpenAI stream payload error: {$last_error}"), 'ERROR');
					continue;
				}
				if ($acc_text === '') {
					cbia_log(("Respuesta streaming sin texto (modelo={$model})"), 'ERROR');
					continue;
				}

				cbia_log(("OpenAI Responses STREAM OK: modelo={$model} tokens_in=") . (int)($last_usage['input_tokens'] ?? 0) . " tokens_out=" . (int)($last_usage['output_tokens'] ?? 0), 'INFO');
				return [true, $acc_text, $last_usage, $model, '', $last_event];
			}
		}
		return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No se pudo obtener respuesta en streaming', []];
	}
}
/* =========================================================
   ================== OPENAI: IMÃƒÂGENES ======================
   ========================================================= */

if (!function_exists('cbia_generate_image_openai')) {
	/**
	 * Retorna [ok(bool), attach_id(int), model_used(string), err(string)]
	 */
	function cbia_generate_image_openai($desc, $section, $title, $idx = 0) {
			cbia_try_unlimited_runtime();
			// CAMBIO: proveedor de imagen segun settings
			$img_provider = function_exists('cbia_get_image_provider') ? cbia_get_image_provider() : 'openai';
			if ($img_provider === 'google') {
				return cbia_google_generate_image($desc, $section, $title, $idx);
			}
			if ($img_provider !== 'openai') {
				cbia_log(sprintf(('Proveedor de imagen "%s" no soportado.'), (string)$img_provider), 'ERROR');
				return [false, 0, '', 'Proveedor de imagen no soportado'];
			}
			// PRO: provider selector (fallback to OpenAI if different provider selected)
			if (function_exists('cbia_providers_get_settings') && function_exists('cbia_providers_get_current_provider')) {
				$provider_settings = cbia_providers_get_settings();
				$current_provider = cbia_providers_get_current_provider($provider_settings);
				if ($current_provider !== 'openai') {
					cbia_log(sprintf(('Provider activo "%s" aun no soportado para imagenes. Usando OpenAI como fallback.'), (string)$current_provider), 'WARN');
				}
			}
			// CAMBIO: key OpenAI desde settings por proveedor
			$api_key = function_exists('cbia_get_provider_api_key') ? cbia_get_provider_api_key('openai') : cbia_openai_api_key();
			if (!$api_key) return [false, 0, '', 'No hay API key'];
		if (!cbia_openai_consent_ok()) return [false, 0, '', 'Consentimiento OpenAI no aceptado'];

		if (cbia_is_stop_requested()) return [false, 0, '', 'STOP activado'];

		$s = cbia_get_settings();
		$image_failover = isset($s['image_failover']) ? (string)$s['image_failover'] : 'continue';
		if (!in_array($image_failover, ['continue', 'stop'], true)) $image_failover = 'continue';

		$prompt = cbia_build_image_prompt($desc, $section, $title);
		$size = cbia_image_size_for_section($section, $idx);
		$alt  = cbia_build_img_alt($title, $section, $desc);
		$section_label = function_exists('cbia_section_label') ? cbia_section_label($section) : (string)$section;

		// CAMBIO: modelo preferido segun settings
		$preferred_model = function_exists('cbia_get_image_model_for_provider')
			? cbia_get_image_model_for_provider('openai', function_exists('cbia_providers_get_recommended_image_model') ? cbia_providers_get_recommended_image_model('openai') : 'gpt-image-1-mini')
			: 'gpt-image-1-mini';
		foreach (cbia_image_model_chain('openai', $preferred_model) as $model) {
			$tries = 2;
			for ($t = 1; $t <= $tries; $t++) {
				if (cbia_is_stop_requested()) return [false, 0, $model, 'STOP activado'];

				$delay = function_exists('cbia_get_image_request_delay') ? cbia_get_image_request_delay() : 0;
				if ($delay > 0) sleep($delay);

				cbia_log(("Imagen IA: modelo={$model} seccion={$section_label} intento {$t}/{$tries}"), 'INFO');

				$payload = [
					'model'  => $model,
					'prompt' => $prompt,
					'n'      => 1,
					'size'   => $size,
				];

				$resp = wp_remote_post('https://api.openai.com/v1/images/generations', [
					'headers' => cbia_http_headers_openai($api_key),
					'body'    => wp_json_encode($payload),
					'timeout' => 60,
				]);

				if (is_wp_error($resp)) {
					cbia_log(("Imagen IA HTTP error: ") . $resp->get_error_message(), 'ERROR');
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code($resp);
				$body = (string) wp_remote_retrieve_body($resp);
				$data = json_decode($body, true);

				if ($code < 200 || $code >= 300) {
					$msg = '';
					if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
					cbia_log(("Imagen IA error HTTP {$code}") . ($msg ? " | {$msg}" : ''), 'ERROR');
					continue;
				}

				if (is_array($data) && !empty($data['error']['message'])) {
					cbia_log(("Imagen IA error payload: ") . (string)$data['error']['message'], 'ERROR');
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
					cbia_log(("Imagen IA: respuesta sin bytes (modelo={$model})"), 'ERROR');
					continue;
				}

				list($attach_id, $uerr) = cbia_upload_image_to_media($bytes, $title, $section, $alt);
				if (!$attach_id) {
					cbia_log(("Imagen IA: fallo subiendo a Media Library: {$uerr}"), 'ERROR');
					continue;
				}

				cbia_log(("Imagen IA OK: seccion={$section_label} attach_id={$attach_id}"), 'INFO');
				return [true, (int)$attach_id, $model, ''];
			}
			if ($image_failover === 'stop') {
				cbia_log(sprintf(('Imagen IA: modelo=%s fallÃƒÂ³; proceso detenido por configuraciÃƒÂ³n.'), (string)$model), 'ERROR');
				return [false, 0, (string)$model, 'Detenido por configuraciÃƒÂ³n de fallo'];
			}
		}

		return [false, 0, '', 'No se pudo generar imagen tras reintentos'];
	}
}

if (!function_exists('cbia_generate_image_openai_with_prompt')) {
	/**
	 * Retorna [ok(bool), attach_id(int), model_used(string), err(string)]
	 */
	function cbia_generate_image_openai_with_prompt($prompt, $section, $title, $alt_text = '', $idx = 0) {
		cbia_try_unlimited_runtime();
		// CAMBIO: proveedor de imagen segun settings
		$img_provider = function_exists('cbia_get_image_provider') ? cbia_get_image_provider() : 'openai';
		if ($img_provider === 'google') {
			return cbia_google_generate_image_with_prompt($prompt, $section, $title, $alt_text, $idx);
		}
		if ($img_provider !== 'openai') {
			cbia_log(sprintf(('Proveedor de imagen "%s" no soportado.'), (string)$img_provider), 'ERROR');
			return [false, 0, '', 'Proveedor de imagen no soportado'];
		}
		// PRO: provider selector (fallback to OpenAI if different provider selected)
		if (function_exists('cbia_providers_get_settings') && function_exists('cbia_providers_get_current_provider')) {
			$provider_settings = cbia_providers_get_settings();
			$current_provider = cbia_providers_get_current_provider($provider_settings);
			if ($current_provider !== 'openai') {
				cbia_log(sprintf(('Provider activo "%s" aun no soportado para imagenes. Usando OpenAI como fallback.'), (string)$current_provider), 'WARN');
			}
		}
		// CAMBIO: key OpenAI desde settings por proveedor
		$api_key = function_exists('cbia_get_provider_api_key') ? cbia_get_provider_api_key('openai') : cbia_openai_api_key();
		if (!$api_key) return [false, 0, '', 'No hay API key'];
		if (!cbia_openai_consent_ok()) return [false, 0, '', 'Consentimiento OpenAI no aceptado'];
		if (cbia_is_stop_requested()) return [false, 0, '', 'STOP activado'];

		$s = cbia_get_settings();
		$image_failover = isset($s['image_failover']) ? (string)$s['image_failover'] : 'continue';
		if (!in_array($image_failover, ['continue', 'stop'], true)) $image_failover = 'continue';

		$size = cbia_image_size_for_section($section, $idx);
		$alt  = $alt_text !== '' ? (string)$alt_text : cbia_build_img_alt($title, $section, $prompt);
		$section_label = function_exists('cbia_section_label') ? cbia_section_label($section) : (string)$section;

		// CAMBIO: modelo preferido segun settings
		$preferred_model = function_exists('cbia_get_image_model_for_provider')
			? cbia_get_image_model_for_provider('openai', function_exists('cbia_providers_get_recommended_image_model') ? cbia_providers_get_recommended_image_model('openai') : 'gpt-image-1-mini')
			: 'gpt-image-1-mini';
		foreach (cbia_image_model_chain('openai', $preferred_model) as $model) {
			$tries = 2;
			for ($t = 1; $t <= $tries; $t++) {
				if (cbia_is_stop_requested()) return [false, 0, $model, 'STOP activado'];

				$delay = function_exists('cbia_get_image_request_delay') ? cbia_get_image_request_delay() : 0;
				if ($delay > 0) sleep($delay);

				cbia_log(("Imagen IA: modelo={$model} seccion={$section_label} intento {$t}/{$tries}"), 'INFO');

				$payload = [
					'model'  => $model,
					'prompt' => (string)$prompt,
					'n'      => 1,
					'size'   => $size,
				];

				$resp = wp_remote_post('https://api.openai.com/v1/images/generations', [
					'headers' => cbia_http_headers_openai($api_key),
					'body'    => wp_json_encode($payload),
					'timeout' => 60,
				]);

				if (is_wp_error($resp)) {
					cbia_log(("Imagen IA HTTP error: ") . $resp->get_error_message(), 'ERROR');
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code($resp);
				$body = (string) wp_remote_retrieve_body($resp);
				$data = json_decode($body, true);

				if ($code < 200 || $code >= 300) {
					$msg = '';
					if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
					cbia_log(("Imagen IA error HTTP {$code}") . ($msg ? " | {$msg}" : ''), 'ERROR');
					continue;
				}

				if (is_array($data) && !empty($data['error']['message'])) {
					cbia_log(("Imagen IA error payload: ") . (string)$data['error']['message'], 'ERROR');
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
					cbia_log(("Imagen IA: respuesta sin bytes (modelo={$model})"), 'ERROR');
					continue;
				}

				list($attach_id, $uerr) = cbia_upload_image_to_media($bytes, $title, $section, $alt);
				if (!$attach_id) {
					cbia_log(("Imagen IA: fallo subiendo a Media Library: {$uerr}"), 'ERROR');
					continue;
				}

				cbia_log(("Imagen IA OK: seccion={$section_label} attach_id={$attach_id}"), 'INFO');
				return [true, (int)$attach_id, $model, ''];
			}
			if ($image_failover === 'stop') {
				cbia_log(sprintf(('Imagen IA: modelo=%s fallÃƒÂ³; proceso detenido por configuraciÃƒÂ³n.'), (string)$model), 'ERROR');
				return [false, 0, (string)$model, 'Detenido por configuraciÃƒÂ³n de fallo'];
			}
		}

		return [false, 0, '', 'No se pudo generar imagen tras reintentos'];
	}
}

if (!function_exists('cbia_get_provider_config')) {
	function cbia_get_provider_config(string $provider): array {
		if (function_exists('cbia_providers_get_provider')) {
			return cbia_providers_get_provider($provider);
		}
		return [];
	}
}

if (!function_exists('cbia_get_provider_model')) {
	function cbia_get_provider_model(string $provider, string $fallback = ''): string {
		$cfg = cbia_get_provider_config($provider);
		$model = isset($cfg['model']) ? (string)$cfg['model'] : '';
		return $model !== '' ? $model : $fallback;
	}
}

if (!function_exists('cbia_google_generate_content_call')) {
	/**
	 * Google Gemini generateContent (REST).
	 * Returns [ok, text, usage, model, err, raw]
	 */
	function cbia_google_generate_content_call($prompt, $system = '', $tries = 2) {
		$cfg = cbia_get_provider_config('google');
		// CAMBIO: key y modelo segun settings de texto
		$api_key = function_exists('cbia_get_provider_api_key') ? cbia_get_provider_api_key('google') : (string)($cfg['api_key'] ?? '');
		if ($api_key === '') {
			cbia_log(('Falta la API key de Google para generar texto.'), 'ERROR');
			return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No hay API key (Google)', []];
		}

		$model = function_exists('cbia_get_text_model_for_provider')
			? cbia_get_text_model_for_provider('google', 'gemini-1.5-flash-latest')
			: cbia_get_provider_model('google', 'gemini-1.5-flash-latest');
		$base_url = rtrim((string)($cfg['base_url'] ?? 'https://generativelanguage.googleapis.com'), '/');
		$api_version = trim((string)($cfg['api_version'] ?? 'v1beta'), '/');

		$max_out = (int)(cbia_get_settings()['responses_max_output_tokens'] ?? 6000);
		if ($max_out < 256) $max_out = 256;
		if ($max_out > 12000) $max_out = 12000;

		$url = $base_url . '/' . $api_version . '/models/' . rawurlencode($model) . ':generateContent';

		$payload = [
			'contents' => [
				[
					'role' => 'user',
					'parts' => [
						['text' => (string)$prompt],
					],
				],
			],
			'generationConfig' => [
				'maxOutputTokens' => $max_out,
			],
		];
		if ($system !== '') {
			$payload['system_instruction'] = [
				'parts' => [
					['text' => (string)$system],
				],
			];
		}

		for ($t = 1; $t <= max(1, (int)$tries); $t++) {
			if (cbia_is_stop_requested()) {
				return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'STOP activado', []];
			}

			cbia_log(("Google Gemini: modelo={$model} intento {$t}/{$tries}"), 'INFO');

			$resp = wp_remote_post($url, [
				'headers' => [
					'Content-Type' => 'application/json',
					'x-goog-api-key' => $api_key,
				],
				'body'    => wp_json_encode($payload),
				'timeout' => 60,
			]);

			if (is_wp_error($resp)) {
				cbia_log(("Google Gemini HTTP error: ") . $resp->get_error_message(), 'ERROR');
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code($resp);
			$body = (string) wp_remote_retrieve_body($resp);
			$data = json_decode($body, true);

			if ($code < 200 || $code >= 300) {
				$msg = '';
				if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
				$err = "HTTP {$code}" . ($msg ? " | {$msg}" : '');
				cbia_log(("Google Gemini error: {$err}"), 'ERROR');
				continue;
			}

			if (!is_array($data)) {
				cbia_log(("Google Gemini: respuesta invalida"), 'ERROR');
				continue;
			}

			$text = '';
			if (!empty($data['candidates'][0]['content']['parts']) && is_array($data['candidates'][0]['content']['parts'])) {
				foreach ($data['candidates'][0]['content']['parts'] as $p) {
					if (is_array($p) && isset($p['text'])) {
						$text .= (string)$p['text'];
					}
				}
			}

			if ($text === '') {
				cbia_log(("Google Gemini: respuesta sin texto (modelo={$model})"), 'ERROR');
				continue;
			}

			$usage = ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0];
			if (!empty($data['usageMetadata'])) {
				$usage['input_tokens'] = (int)($data['usageMetadata']['promptTokenCount'] ?? 0);
				$usage['output_tokens'] = (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0);
				$usage['total_tokens'] = (int)($data['usageMetadata']['totalTokenCount'] ?? 0);
			}

			cbia_log(("Google Gemini OK: modelo={$model} tokens_in=") . (int)$usage['input_tokens'] . " tokens_out=" . (int)$usage['output_tokens'], 'INFO');
			return [true, $text, $usage, $model, '', $data];
		}

		return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'No se pudo obtener respuesta', []];
	}
}

if (!function_exists('cbia_deepseek_chat_call')) {
	/**
	 * DeepSeek chat completions (OpenAI-compatible).
	 * Returns [ok, text, usage, model, err, raw]
	 */
	function cbia_deepseek_chat_call($prompt, $system = '', $tries = 2) {
		$cfg = cbia_get_provider_config('deepseek');
		// CAMBIO: key y modelo segun settings de texto
		$api_key = function_exists('cbia_get_provider_api_key') ? cbia_get_provider_api_key('deepseek') : (string)($cfg['api_key'] ?? '');
		if ($api_key === '') {
			cbia_log(('Falta la API key de DeepSeek para generar texto.'), 'ERROR');
			return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No hay API key (DeepSeek)', []];
		}

		$model = function_exists('cbia_get_text_model_for_provider')
			? cbia_get_text_model_for_provider('deepseek', 'deepseek-chat')
			: cbia_get_provider_model('deepseek', 'deepseek-chat');
		$base_url = rtrim((string)($cfg['base_url'] ?? 'https://api.deepseek.com'), '/');
		$api_version = trim((string)($cfg['api_version'] ?? 'v1'), '/');
		$path = $api_version !== '' ? '/' . $api_version . '/chat/completions' : '/chat/completions';
		$url = $base_url . $path;

		$max_out = (int)(cbia_get_settings()['responses_max_output_tokens'] ?? 6000);
		if ($max_out < 256) $max_out = 256;
		if ($max_out > 12000) $max_out = 12000;

		$messages = [];
		if ($system !== '') {
			$messages[] = ['role' => 'system', 'content' => (string)$system];
		}
		$messages[] = ['role' => 'user', 'content' => (string)$prompt];

		$payload = [
			'model' => $model,
			'messages' => $messages,
			'stream' => false,
			'max_tokens' => $max_out,
			'temperature' => (float)(cbia_get_settings()['openai_temperature'] ?? 0.7),
		];

		for ($t = 1; $t <= max(1, (int)$tries); $t++) {
			if (cbia_is_stop_requested()) {
				return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'STOP activado', []];
			}

			cbia_log(("DeepSeek: modelo={$model} intento {$t}/{$tries}"), 'INFO');

			$resp = wp_remote_post($url, [
				'headers' => [
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'body'    => wp_json_encode($payload),
				'timeout' => 60,
			]);

			if (is_wp_error($resp)) {
				cbia_log(("DeepSeek HTTP error: ") . $resp->get_error_message(), 'ERROR');
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code($resp);
			$body = (string) wp_remote_retrieve_body($resp);
			$data = json_decode($body, true);

			if ($code < 200 || $code >= 300) {
				$msg = '';
				if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
				$err = "HTTP {$code}" . ($msg ? " | {$msg}" : '');
				cbia_log(("DeepSeek error: {$err}"), 'ERROR');
				continue;
			}

			if (!is_array($data) || empty($data['choices'][0]['message']['content'])) {
				cbia_log(("DeepSeek: respuesta sin texto (modelo={$model})"), 'ERROR');
				continue;
			}

			$text = (string)$data['choices'][0]['message']['content'];
			$usage = ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0];
			if (!empty($data['usage'])) {
				$usage['input_tokens'] = (int)($data['usage']['prompt_tokens'] ?? 0);
				$usage['output_tokens'] = (int)($data['usage']['completion_tokens'] ?? 0);
				$usage['total_tokens'] = (int)($data['usage']['total_tokens'] ?? 0);
			}

			cbia_log(("DeepSeek OK: modelo={$model} tokens_in=") . (int)$usage['input_tokens'] . " tokens_out=" . (int)$usage['output_tokens'], 'INFO');
			return [true, $text, $usage, $model, '', $data];
		}

		return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'No se pudo obtener respuesta', []];
	}
}

