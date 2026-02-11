<?php
if (!defined('ABSPATH')) exit;

/**
 * TAB: Configuracion
 * Guarda en cbia_settings:
 * - openai_api_key, openai_model, openai_temperature
 * - post_length_variant, images_limit
 * - prompt_single_all (+ prompts de imagen por seccion)
 * - default_category, keywords_to_categories, default_tags
 * - default_author_id (autor fijo para posts, ÃƒÂºtil para cron/evento)
 *
 * Sanitiza y MERGEA sin borrar campos de otros tabs.
 */

/* Helpers moved to includes/support/* (sanitize + config-catalog). */

/**
 * Guardado settings (POST)
 */
if (!function_exists('cbia_config_handle_post')) {
	function cbia_config_handle_post(): void {
		if (!is_admin()) return;
		if (!current_user_can('manage_options')) return;

		if (!isset($_POST['cbia_config_save'])) return;

		check_admin_referer('cbia_config_save_action', 'cbia_config_nonce');

		$settings = cbia_get_settings();
		// Sanitizar arrays de entrada primero
		$provider_api_key_post = isset($_POST['provider_api_key']) && is_array($_POST['provider_api_key'])
			? wp_unslash($_POST['provider_api_key'])
			: [];
		$text_models_post = isset($_POST['text_model']) && is_array($_POST['text_model'])
			? wp_unslash($_POST['text_model'])
			: [];
		$image_models_post = isset($_POST['image_model_by_provider']) && is_array($_POST['image_model_by_provider'])
			? wp_unslash($_POST['image_model_by_provider'])
			: [];
		$provider_base_url_post = isset($_POST['provider_base_url']) && is_array($_POST['provider_base_url'])
			? wp_unslash($_POST['provider_base_url'])
			: [];

		// CAMBIO: providers disponibles (texto/imagen)
		$providers_all = function_exists('cbia_providers_get_all') ? cbia_providers_get_all() : [];
		$providers_list = isset($providers_all['providers']) && is_array($providers_all['providers']) ? $providers_all['providers'] : [];

		// CAMBIO: API keys por proveedor (con fallback legacy)
		$api_key = isset($provider_api_key_post['openai'])
			? sanitize_text_field($provider_api_key_post['openai'])
			: (isset($_POST['openai_api_key']) ? sanitize_text_field(wp_unslash($_POST['openai_api_key'])) : (string)($settings['openai_api_key'] ?? ''));
		$google_api_key = isset($provider_api_key_post['google'])
			? sanitize_text_field($provider_api_key_post['google'])
			: (isset($_POST['google_api_key']) ? sanitize_text_field(wp_unslash($_POST['google_api_key'])) : (string)($settings['google_api_key'] ?? ''));
		$deepseek_api_key = isset($provider_api_key_post['deepseek'])
			? sanitize_text_field($provider_api_key_post['deepseek'])
			: (isset($_POST['deepseek_api_key']) ? sanitize_text_field(wp_unslash($_POST['deepseek_api_key'])) : (string)($settings['deepseek_api_key'] ?? ''));
		$openai_consent = 1;
		// CAMBIO: Google Imagen (Vertex AI) settings
		$google_project_id = isset($_POST['google_project_id'])
			? sanitize_text_field(wp_unslash($_POST['google_project_id']))
			: (string)($settings['google_project_id'] ?? '');
		$google_location = isset($_POST['google_location'])
			? sanitize_text_field(wp_unslash($_POST['google_location']))
			: (string)($settings['google_location'] ?? '');
		$google_service_account_json = isset($_POST['google_service_account_json'])
			? sanitize_textarea_field(wp_unslash($_POST['google_service_account_json']))
			: (string)($settings['google_service_account_json'] ?? '');

		// CAMBIO: proveedores de texto e imagen
		$text_provider = isset($_POST['text_provider']) ? sanitize_key((string) wp_unslash($_POST['text_provider'])) : (string)($settings['text_provider'] ?? '');
		if ($text_provider === '' && function_exists('cbia_providers_get_current_provider')) {
			$text_provider = cbia_providers_get_current_provider();
		}
		if ($text_provider === '' || !isset($providers_list[$text_provider])) $text_provider = 'openai';

		$image_provider = isset($_POST['image_provider']) ? sanitize_key((string) wp_unslash($_POST['image_provider'])) : (string)($settings['image_provider'] ?? '');
		if ($image_provider === '' || !isset($providers_list[$image_provider])) $image_provider = 'openai';

		// CAMBIO: modelos por proveedor (texto)
		$text_model = isset($text_models_post[$text_provider]) ? sanitize_text_field((string)$text_models_post[$text_provider]) : '';
		if ($text_model === '' && $text_provider === 'openai' && isset($_POST['openai_model'])) {
			$text_model = sanitize_text_field(wp_unslash($_POST['openai_model']));
		}
		if ($text_model === '') {
			$text_model = function_exists('cbia_providers_get_recommended_text_model')
				? cbia_providers_get_recommended_text_model($text_provider)
				: 'gpt-4.1-mini';
		}
		if ($text_provider === 'openai') {
			$text_model = cbia_config_safe_model($text_model);
		}

		// CAMBIO: modelos por proveedor (imagen)
		$image_model = isset($image_models_post[$image_provider]) ? sanitize_text_field((string)$image_models_post[$image_provider]) : '';
		if ($image_model === '' && isset($_POST['image_model'])) {
			$image_model = sanitize_text_field(wp_unslash($_POST['image_model']));
		}
		if ($image_model === '') {
			$image_model = function_exists('cbia_providers_get_recommended_image_model')
				? cbia_providers_get_recommended_image_model($image_provider)
				: 'gpt-image-1-mini';
		}

		// CAMBIO: compatibilidad con campo legacy openai_model
		$model = ($text_provider === 'openai') ? $text_model : (string)($settings['openai_model'] ?? 'gpt-4.1-mini');

		// CAMBIO: provider settings (texto + imagen)
		if (function_exists('cbia_providers_get_settings') && function_exists('cbia_providers_get_all')) {
			$provider_settings = cbia_providers_get_settings();
			$providers_all = cbia_providers_get_all();
			$providers_list = isset($providers_all['providers']) && is_array($providers_all['providers']) ? $providers_all['providers'] : [];
			$current_provider = $text_provider ?: ($provider_settings['provider'] ?? 'openai');
			if (!isset($providers_list[$current_provider])) $current_provider = 'openai';

			$providers_new = is_array($provider_settings['providers'] ?? null) ? $provider_settings['providers'] : [];
			foreach ($providers_list as $pkey => $pdef) {
				$api = isset($provider_api_key_post[$pkey]) ? sanitize_text_field((string)$provider_api_key_post[$pkey]) : (string)($providers_new[$pkey]['api_key'] ?? '');
				$mdl = isset($text_models_post[$pkey]) ? sanitize_text_field((string)$text_models_post[$pkey]) : (string)($providers_new[$pkey]['model'] ?? ($pdef['models'][0] ?? ''));
				$img = isset($image_models_post[$pkey]) ? sanitize_text_field((string)$image_models_post[$pkey]) : (string)($providers_new[$pkey]['image_model'] ?? '');
				$base = isset($provider_base_url_post[$pkey]) ? sanitize_text_field((string)$provider_base_url_post[$pkey]) : (string)($providers_new[$pkey]['base_url'] ?? ($pdef['base_url'] ?? ''));
				$providers_new[$pkey] = [
					'api_key'     => $api,
					'model'       => $mdl,
					'image_model' => $img,
					'base_url'    => $base,
				];
			}
			if (isset($providers_new['openai'])) {
				$providers_new['openai']['api_key'] = $api_key;
			}
			if (isset($providers_new['google'])) {
				$providers_new['google']['api_key'] = $google_api_key;
			}
			if (isset($providers_new['deepseek'])) {
				$providers_new['deepseek']['api_key'] = $deepseek_api_key;
			}

			if (function_exists('cbia_providers_save_settings')) {
				cbia_providers_save_settings([
					'provider'  => $current_provider,
					'current_provider' => $current_provider,
					'providers' => $providers_new,
				]);
			}
		}

		$temp = isset($_POST['openai_temperature'])
			? (float) str_replace(',', '.', (string) wp_unslash($_POST['openai_temperature']))
			: (float)($settings['openai_temperature'] ?? 0.7);

		if ($temp < 0) $temp = 0;
		if ($temp > 2) $temp = 2;

		$post_length_variant = isset($_POST['post_length_variant'])
			? sanitize_key((string) wp_unslash($_POST['post_length_variant']))
			: (string)($settings['post_length_variant'] ?? 'medium');

		if (!in_array($post_length_variant, ['short','medium','long'], true)) $post_length_variant = 'medium';

		$images_limit = isset($_POST['images_limit']) ? absint(wp_unslash($_POST['images_limit'])) : (int)($settings['images_limit'] ?? 3);
		if ($images_limit < 1) $images_limit = 1;
		if ($images_limit > 4) $images_limit = 4;

		$prompt_single_all = isset($_POST['prompt_single_all'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_single_all'])))
			: (string)($settings['prompt_single_all'] ?? '');

		$prompt_img_intro = isset($_POST['prompt_img_intro'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_img_intro'])))
			: (string)($settings['prompt_img_intro'] ?? '');

		$prompt_img_body = isset($_POST['prompt_img_body'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_img_body'])))
			: (string)($settings['prompt_img_body'] ?? '');

		$prompt_img_conclusion = isset($_POST['prompt_img_conclusion'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_img_conclusion'])))
			: (string)($settings['prompt_img_conclusion'] ?? '');

		$prompt_img_faq = isset($_POST['prompt_img_faq'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_img_faq'])))
			: (string)($settings['prompt_img_faq'] ?? '');

		$prompt_img_global = isset($_POST['prompt_img_global'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_img_global'])))
			: (string)($settings['prompt_img_global'] ?? '');

		$responses_max_output_tokens = isset($_POST['responses_max_output_tokens'])
			? absint(wp_unslash($_POST['responses_max_output_tokens']))
			: (int)($settings['responses_max_output_tokens'] ?? 6000);
		if ($responses_max_output_tokens < 1500) $responses_max_output_tokens = 1500;
		if ($responses_max_output_tokens > 12000) $responses_max_output_tokens = 12000;

		// Preset rapido por modelo (si viene del boton de preset, manda sobre el resto)
		$preset_key = isset($_POST['cbia_preset_model']) ? sanitize_text_field(wp_unslash($_POST['cbia_preset_model'])) : '';
		if ($preset_key !== '' && function_exists('cbia_config_Presets_catalog')) {
			$Presets = cbia_config_Presets_catalog();
			if (isset($Presets[$preset_key])) {
				$p = $Presets[$preset_key];
				$model = cbia_config_safe_model($p['openai_model'] ?? $model);
				// CAMBIO: aplicar preset tambien a text_model si proveedor texto es openai
				if ($text_provider === 'openai') {
					$text_model = $model;
				}
				$temp = isset($p['openai_temperature']) ? (float)$p['openai_temperature'] : (float)$temp;
				$responses_max_output_tokens = isset($p['responses_max_output_tokens']) ? (int)$p['responses_max_output_tokens'] : (int)$responses_max_output_tokens;
				/* translators: %s: preset key */
				cbia_log(sprintf('Preset aplicado en Config: %1$s', (string)$preset_key), 'INFO');
			}
		}

		$post_language = isset($_POST['post_language'])
			? sanitize_text_field(wp_unslash($_POST['post_language']))
			: (string)($settings['post_language'] ?? 'espanol');
		if ($post_language === '') $post_language = 'espanol';

		// Banner CSS en Contenido (no destacada)
		$content_images_banner_enabled = 1;
		$content_images_banner_css = isset($_POST['content_images_banner_css'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['content_images_banner_css'])))
			: (string)($settings['content_images_banner_css'] ?? '');
		if (trim($content_images_banner_css) === '' && function_exists('cbia_get_default_settings')) {
			$defaults = cbia_get_default_settings();
			$content_images_banner_css = (string)($defaults['content_images_banner_css'] ?? '');
		}

		// Preset rÃƒÆ’Ã‚Â¡pido de CSS de banner (selector)
		$banner_preset_key = 'forced';

		// Formato de imagen por seccion (UI) - nota: el engine fuerza intro=panorÃƒÂ¡mica y resto=banner (como en v8.4)
		$image_format_intro = isset($_POST['image_format_intro'])
			? cbia_config_sanitize_image_format(wp_unslash($_POST['image_format_intro']), 'panoramic_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_intro'] ?? ''), 'panoramic_1536x1024');

		$image_format_body = isset($_POST['image_format_body'])
			? cbia_config_sanitize_image_format(wp_unslash($_POST['image_format_body']), 'banner_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_body'] ?? ''), 'banner_1536x1024');

		$image_format_conclusion = isset($_POST['image_format_conclusion'])
			? cbia_config_sanitize_image_format(wp_unslash($_POST['image_format_conclusion']), 'banner_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_conclusion'] ?? ''), 'banner_1536x1024');

		$image_format_faq = isset($_POST['image_format_faq'])
			? cbia_config_sanitize_image_format(wp_unslash($_POST['image_format_faq']), 'banner_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_faq'] ?? ''), 'banner_1536x1024');

		$image_format_internal_1 = isset($_POST['image_format_internal_1'])
			? cbia_config_sanitize_image_format(wp_unslash($_POST['image_format_internal_1']), $image_format_body)
			: cbia_config_sanitize_image_format((string)($settings['image_format_internal_1'] ?? ''), $image_format_body);

		$image_format_internal_2 = isset($_POST['image_format_internal_2'])
			? cbia_config_sanitize_image_format(wp_unslash($_POST['image_format_internal_2']), $image_format_body)
			: cbia_config_sanitize_image_format((string)($settings['image_format_internal_2'] ?? ''), $image_format_body);

		$image_format_internal_3 = isset($_POST['image_format_internal_3'])
			? cbia_config_sanitize_image_format(wp_unslash($_POST['image_format_internal_3']), $image_format_body)
			: cbia_config_sanitize_image_format((string)($settings['image_format_internal_3'] ?? ''), $image_format_body);

		$image_failover = isset($_POST['image_failover'])
			? sanitize_key((string) wp_unslash($_POST['image_failover']))
			: (string)($settings['image_failover'] ?? 'continue');
		if (!in_array($image_failover, ['continue', 'stop'], true)) $image_failover = 'continue';

		$default_category = isset($_POST['default_category'])
			? sanitize_text_field(wp_unslash($_POST['default_category']))
			: (string)($settings['default_category'] ?? 'Noticias');

		if ($default_category === '') $default_category = 'Noticias';

		$keywords_to_categories = isset($_POST['keywords_to_categories'])
			? cbia_sanitize_textarea_preserve_lines(wp_unslash($_POST['keywords_to_categories']))
			: (string)($settings['keywords_to_categories'] ?? '');

		$default_tags = isset($_POST['default_tags'])
			? cbia_sanitize_csv_tags(sanitize_text_field(wp_unslash($_POST['default_tags'])))
			: (string)($settings['default_tags'] ?? '');

		// Autor por defecto (para cron/evento): 0 = automatico (usuario actual o admin)
		$default_author_id = isset($_POST['default_author_id']) ? absint(wp_unslash($_POST['default_author_id'])) : (int)($settings['default_author_id'] ?? 0);
		if ($default_author_id < 0) $default_author_id = 0;

		$partial = [
			// CAMBIO: keys por proveedor
			'openai_api_key'         => $api_key,
			'google_api_key'         => $google_api_key,
			'deepseek_api_key'       => $deepseek_api_key,
			'openai_consent'         => $openai_consent,
			// CAMBIO: Google Imagen (Vertex AI)
			'google_project_id'      => $google_project_id,
			'google_location'        => $google_location,
			'google_service_account_json' => $google_service_account_json,
			// CAMBIO: provider/model texto e imagen
			'text_provider'          => $text_provider,
			'text_model'             => $text_model,
			'image_provider'         => $image_provider,
			'image_model'            => $image_model,
			// CAMBIO: compatibilidad legacy OpenAI
			'openai_model'           => $model,
			'openai_temperature'     => $temp,
			'post_length_variant'    => $post_length_variant,
			'images_limit'           => $images_limit,
			'prompt_single_all'      => $prompt_single_all,
			'prompt_img_intro'       => $prompt_img_intro,
			'prompt_img_body'        => $prompt_img_body,
			'prompt_img_conclusion'  => $prompt_img_conclusion,
			'prompt_img_faq'         => $prompt_img_faq,
			'prompt_img_global'      => $prompt_img_global,
			'responses_max_output_tokens' => $responses_max_output_tokens,
			'post_language'          => $post_language,
			'content_images_banner_enabled' => $content_images_banner_enabled,
			'content_images_banner_css' => $content_images_banner_css,
			'image_format_intro'     => $image_format_intro,
			'image_format_body'      => $image_format_internal_1,
			'image_format_conclusion'=> $image_format_conclusion,
			'image_format_faq'       => $image_format_faq,
			'image_format_internal_1' => $image_format_internal_1,
			'image_format_internal_2' => $image_format_internal_2,
			'image_format_internal_3' => $image_format_internal_3,
			'image_failover'         => $image_failover,
			'default_category'       => $default_category,
			'keywords_to_categories' => $keywords_to_categories,
			'default_tags'           => $default_tags,
			'default_author_id'      => $default_author_id,
		];

		// CAMBIO: avisos por API key faltante (sin bloquear guardado)
		$warnings = [];
		$key_map = [
			'openai'  => $api_key,
			'google'  => $google_api_key,
			'deepseek'=> $deepseek_api_key,
		];
		if (empty($key_map[$text_provider] ?? '')) {
			/* translators: %s: provider name */
				$warnings[] = sprintf('Falta la API key de %s para generar texto. AÃ±Ã¡dela para poder usar ese proveedor.', ucfirst($text_provider));
		}
		if ($image_provider === 'google') {
			// CAMBIO: Google Imagen vs Gemini Image Preview
			if ($image_model === 'imagen-2') {
				if ($google_project_id === '' || $google_location === '' || trim($google_service_account_json) === '') {
					$warnings[] = 'Faltan datos de Google Vertex AI para usar Imagen 2 (Project ID, Location y Service Account JSON). AÃ±Ã¡delos para generar imÃ¡genes con Imagen.';
				}
			} else {
				if (empty($google_api_key)) {
					$warnings[] = 'Falta la API key de Google para generar imÃ¡genes con Gemini. AÃ±Ã¡dela para poder usar ese modelo.';
				}
			}
		} else {
			if (empty($key_map[$image_provider] ?? '')) {
				/* translators: 1: provider name, 2: provider name */
				$warnings[] = sprintf('Falta la API key de %1$s para generar imÃ¡genes. AÃ±Ã¡dela para poder usar modelos de imagen de %2$s.', ucfirst($image_provider), ucfirst($image_provider));
			}
		}
		if (!empty($warnings)) {
			set_transient('cbia_config_warnings', $warnings, 60);
		} else {
			delete_transient('cbia_config_warnings');
		}

		cbia_update_settings_merge($partial);
		cbia_log('Configuracion guardada correctamente.', 'INFO');

		wp_safe_redirect(admin_url('admin.php?page=cbia&tab=config&saved=1'));
		exit;
	}
}

add_action('admin_init', 'cbia_config_handle_post');

/**
 * Render tab
 */
if (!function_exists('cbia_render_tab_config')) {
    function cbia_render_tab_config(){
        if (!current_user_can('manage_options')) return;

        $view = (defined('CBIA_INCLUDES_DIR') ? CBIA_INCLUDES_DIR . 'admin/views/config.php' : __DIR__ . '/views/config.php');
        if (file_exists($view)) {
            include $view;
            return;
        }

        echo '<p>No se pudo cargar Configuracion.</p>';
    }
}


