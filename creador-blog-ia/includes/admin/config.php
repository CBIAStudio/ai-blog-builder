<?php
if (!defined('ABSPATH')) exit;

/**
 * TAB: Configuración
 * Guarda en cbia_settings:
 * - openai_api_key, openai_model, openai_temperature
 * - post_length_variant, images_limit
 * - prompt_single_all (+ prompts de imagen por sección)
 * - default_category, keywords_to_categories, default_tags
 * - blocked_models (checkbox por modelo)
 * - default_author_id (autor fijo para posts, útil para cron/evento)
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

		$api_key = isset($_POST['openai_api_key']) ? sanitize_text_field(wp_unslash($_POST['openai_api_key'])) : '';
		$model   = isset($_POST['openai_model']) ? sanitize_text_field(wp_unslash($_POST['openai_model'])) : ($settings['openai_model'] ?? '');
		$model   = cbia_config_safe_model($model);

		$temp = isset($_POST['openai_temperature'])
			? (float) str_replace(',', '.', (string) wp_unslash($_POST['openai_temperature']))
			: (float)($settings['openai_temperature'] ?? 0.7);

		if ($temp < 0) $temp = 0;
		if ($temp > 2) $temp = 2;

		$post_length_variant = isset($_POST['post_length_variant'])
			? sanitize_key((string) wp_unslash($_POST['post_length_variant']))
			: (string)($settings['post_length_variant'] ?? 'medium');

		if (!in_array($post_length_variant, ['short','medium','long'], true)) $post_length_variant = 'medium';

		$images_limit = isset($_POST['images_limit']) ? (int) $_POST['images_limit'] : (int)($settings['images_limit'] ?? 3);
		if ($images_limit < 1) $images_limit = 1;
		if ($images_limit > 4) $images_limit = 4;

		$prompt_single_all = isset($_POST['prompt_single_all'])
			? cbia_sanitize_textarea_preserve_lines($_POST['prompt_single_all'])
			: (string)($settings['prompt_single_all'] ?? '');

		$prompt_img_intro = isset($_POST['prompt_img_intro'])
			? cbia_sanitize_textarea_preserve_lines($_POST['prompt_img_intro'])
			: (string)($settings['prompt_img_intro'] ?? '');

		$prompt_img_body = isset($_POST['prompt_img_body'])
			? cbia_sanitize_textarea_preserve_lines($_POST['prompt_img_body'])
			: (string)($settings['prompt_img_body'] ?? '');

		$prompt_img_conclusion = isset($_POST['prompt_img_conclusion'])
			? cbia_sanitize_textarea_preserve_lines($_POST['prompt_img_conclusion'])
			: (string)($settings['prompt_img_conclusion'] ?? '');

		$prompt_img_faq = isset($_POST['prompt_img_faq'])
			? cbia_sanitize_textarea_preserve_lines($_POST['prompt_img_faq'])
			: (string)($settings['prompt_img_faq'] ?? '');

		$responses_max_output_tokens = isset($_POST['responses_max_output_tokens'])
			? (int)$_POST['responses_max_output_tokens']
			: (int)($settings['responses_max_output_tokens'] ?? 6000);
		if ($responses_max_output_tokens < 1500) $responses_max_output_tokens = 1500;
		if ($responses_max_output_tokens > 12000) $responses_max_output_tokens = 12000;

		// Preset rápido por modelo (si viene del botón de preset, manda sobre el resto)
		$preset_key = isset($_POST['cbia_preset_model']) ? sanitize_text_field(wp_unslash($_POST['cbia_preset_model'])) : '';
		if ($preset_key !== '' && function_exists('cbia_config_presets_catalog')) {
			$presets = cbia_config_presets_catalog();
			if (isset($presets[$preset_key])) {
				$p = $presets[$preset_key];
				$model = cbia_config_safe_model($p['openai_model'] ?? $model);
				$temp = isset($p['openai_temperature']) ? (float)$p['openai_temperature'] : (float)$temp;
				$responses_max_output_tokens = isset($p['responses_max_output_tokens']) ? (int)$p['responses_max_output_tokens'] : (int)$responses_max_output_tokens;
				cbia_log('Preset aplicado en Config: ' . $preset_key, 'INFO');
			}
		}

		$post_language = isset($_POST['post_language'])
			? sanitize_text_field(wp_unslash($_POST['post_language']))
			: (string)($settings['post_language'] ?? 'español');
		if ($post_language === '') $post_language = 'español';

		$faq_heading_custom = isset($_POST['faq_heading_custom'])
			? sanitize_text_field(wp_unslash($_POST['faq_heading_custom']))
			: (string)($settings['faq_heading_custom'] ?? '');

		// Banner CSS en contenido (no destacada)
		$content_images_banner_enabled = !empty($_POST['content_images_banner_enabled']) ? 1 : 0;
		$content_images_banner_css = isset($_POST['content_images_banner_css'])
			? cbia_sanitize_textarea_preserve_lines($_POST['content_images_banner_css'])
			: (string)($settings['content_images_banner_css'] ?? '');

		// Preset rÃ¡pido de CSS de banner (selector)
		$banner_preset_key = isset($_POST['content_images_banner_preset'])
			? sanitize_text_field(wp_unslash($_POST['content_images_banner_preset']))
			: 'custom';
		if ($banner_preset_key !== '' && $banner_preset_key !== 'custom' && function_exists('cbia_config_banner_css_presets')) {
			$presets = cbia_config_banner_css_presets();
			if (isset($presets[$banner_preset_key])) {
				$css = (string)($presets[$banner_preset_key]['css'] ?? '');
				$content_images_banner_css = $css;
				$content_images_banner_enabled = ($banner_preset_key === 'none') ? 0 : 1;
				cbia_log('Preset CSS de banner aplicado: ' . $banner_preset_key, 'INFO');
			}
		}

		// Formato de imagen por sección (UI) - nota: el engine fuerza intro=panorámica y resto=banner (como en v8.4)
		$image_format_intro = isset($_POST['image_format_intro'])
			? cbia_config_sanitize_image_format($_POST['image_format_intro'], 'panoramic_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_intro'] ?? ''), 'panoramic_1536x1024');

		$image_format_body = isset($_POST['image_format_body'])
			? cbia_config_sanitize_image_format($_POST['image_format_body'], 'banner_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_body'] ?? ''), 'banner_1536x1024');

		$image_format_conclusion = isset($_POST['image_format_conclusion'])
			? cbia_config_sanitize_image_format($_POST['image_format_conclusion'], 'banner_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_conclusion'] ?? ''), 'banner_1536x1024');

		$image_format_faq = isset($_POST['image_format_faq'])
			? cbia_config_sanitize_image_format($_POST['image_format_faq'], 'banner_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_faq'] ?? ''), 'banner_1536x1024');

		$default_category = isset($_POST['default_category'])
			? sanitize_text_field(wp_unslash($_POST['default_category']))
			: (string)($settings['default_category'] ?? 'Noticias');

		if ($default_category === '') $default_category = 'Noticias';

		$keywords_to_categories = isset($_POST['keywords_to_categories'])
			? cbia_sanitize_textarea_preserve_lines($_POST['keywords_to_categories'])
			: (string)($settings['keywords_to_categories'] ?? '');

		$default_tags = isset($_POST['default_tags'])
			? cbia_sanitize_csv_tags($_POST['default_tags'])
			: (string)($settings['default_tags'] ?? '');

		// Autor por defecto (para cron/evento): 0 = automático (usuario actual o admin)
		$default_author_id = isset($_POST['default_author_id']) ? (int)$_POST['default_author_id'] : (int)($settings['default_author_id'] ?? 0);
		if ($default_author_id < 0) $default_author_id = 0;

		// Bloqueo modelos
		$blocked_models = [];
		if (!empty($_POST['blocked_models']) && is_array($_POST['blocked_models'])) {
			foreach ($_POST['blocked_models'] as $m => $v) {
				$m = sanitize_text_field((string)$m);
				$blocked_models[$m] = 1;
			}
		}

		$partial = [
			'openai_api_key'         => $api_key,
			'openai_model'           => $model,
			'openai_temperature'     => $temp,
			'post_length_variant'    => $post_length_variant,
			'images_limit'           => $images_limit,
			'prompt_single_all'      => $prompt_single_all,
			'prompt_img_intro'       => $prompt_img_intro,
			'prompt_img_body'        => $prompt_img_body,
			'prompt_img_conclusion'  => $prompt_img_conclusion,
			'prompt_img_faq'         => $prompt_img_faq,
			'responses_max_output_tokens' => $responses_max_output_tokens,
			'post_language'          => $post_language,
			'faq_heading_custom'     => $faq_heading_custom,
			'content_images_banner_enabled' => $content_images_banner_enabled,
			'content_images_banner_css' => $content_images_banner_css,
			'image_format_intro'     => $image_format_intro,
			'image_format_body'      => $image_format_body,
			'image_format_conclusion'=> $image_format_conclusion,
			'image_format_faq'       => $image_format_faq,
			'default_category'       => $default_category,
			'keywords_to_categories' => $keywords_to_categories,
			'default_tags'           => $default_tags,
			'blocked_models'         => $blocked_models,
			'default_author_id'      => $default_author_id,
		];

		cbia_update_settings_merge($partial);
		cbia_log('Configuración guardada correctamente.', 'INFO');

		wp_redirect(admin_url('admin.php?page=cbia&tab=config&saved=1'));
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

        echo '<p>No se pudo cargar Configuración.</p>';
    }
}
