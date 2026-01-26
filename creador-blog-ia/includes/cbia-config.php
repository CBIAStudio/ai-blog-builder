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

if (!function_exists('cbia_sanitize_textarea_preserve_lines')) {
	function cbia_sanitize_textarea_preserve_lines($value): string {
		$value = is_string($value) ? $value : '';
		$value = wp_unslash($value);
		$value = str_replace(["\r\n", "\r"], "\n", $value);
		return trim($value);
	}
}

if (!function_exists('cbia_sanitize_csv_tags')) {
	function cbia_sanitize_csv_tags($value): string {
		$value = cbia_sanitize_textarea_preserve_lines($value);
		$value = str_replace("\n", ",", $value);
		$value = preg_replace('/\s*,\s*/', ',', $value);
		$value = preg_replace('/,+/', ',', $value);
		$value = trim($value, " ,\t\n\r\0\x0B");
		return $value;
	}
}

if (!function_exists('cbia_get_allowed_models_for_ui')) {
	/**
	 * Lista UI (selector + bloqueo)
	 * Reducida: solo GPT-4.1 y GPT-5 (recomendado: gpt-4.1-mini)
	 */
	function cbia_get_allowed_models_for_ui(): array {
		return [
			// Recomendados (texto)
			'gpt-4.1-mini',   // RECOMENDADO
			'gpt-4.1',
			'gpt-4.1-nano',

			// GPT-5.x (texto)
			'gpt-5',
			'gpt-5-mini',
			'gpt-5-nano',

			'gpt-5.1',
			
			

			'gpt-5.2',
			
			
		];
	}
}

/* =========================================================
   =================== IMAGEN IA: FORMATOS =================
   ========================================================= */

if (!function_exists('cbia_config_image_formats_catalog')) {
	function cbia_config_image_formats_catalog(): array {
		return [
			'panoramic_1536x1024' => 'Panorámica (1536x1024)',
			'banner_1536x1024'    => 'Banner (1536x1024, encuadre amplio + headroom 25–35%)',
		];
	}
}

if (!function_exists('cbia_config_sanitize_image_format')) {
	function cbia_config_sanitize_image_format($value, $fallback_key): string {
		$value = sanitize_key((string)$value);
		$formats = cbia_config_image_formats_catalog();
		if (isset($formats[$value])) return $value;
		return sanitize_key((string)$fallback_key);
	}
}

if (!function_exists('cbia_get_recommended_text_model')) {
	function cbia_get_recommended_text_model(): string {
		return 'gpt-4.1-mini';
	}
}

if (!function_exists('cbia_config_safe_model')) {
	/**
	 * Si el modelo guardado ya no está en la lista UI, cae al recomendado.
	 */
	function cbia_config_safe_model($model): string {
		$model = sanitize_text_field((string)$model);
		$models = cbia_get_allowed_models_for_ui();
		if (in_array($model, $models, true)) return $model;
		return cbia_get_recommended_text_model();
	}
}

/**
 * Guardado settings (POST)
 */
add_action('admin_init', function () {
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

	$post_language = isset($_POST['post_language'])
		? sanitize_text_field(wp_unslash($_POST['post_language']))
		: (string)($settings['post_language'] ?? 'español');
	if ($post_language === '') $post_language = 'español';

	$faq_heading_custom = isset($_POST['faq_heading_custom'])
		? sanitize_text_field(wp_unslash($_POST['faq_heading_custom']))
		: (string)($settings['faq_heading_custom'] ?? '');

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
});

/**
 * Render tab
 */
if (!function_exists('cbia_render_tab_config')) {
	function cbia_render_tab_config(): void {
		$s = cbia_get_settings();

		// Defaults seguros
		$recommended = cbia_get_recommended_text_model();
		$s['openai_model'] = cbia_config_safe_model($s['openai_model'] ?? $recommended);
		if (!isset($s['openai_temperature'])) $s['openai_temperature'] = 0.7;
		if (!isset($s['post_length_variant'])) $s['post_length_variant'] = 'medium';
		if (!isset($s['images_limit'])) $s['images_limit'] = 3;
		if (!isset($s['default_category'])) $s['default_category'] = 'Noticias';
		if (!isset($s['post_language'])) $s['post_language'] = 'español';
		if (!isset($s['faq_heading_custom'])) $s['faq_heading_custom'] = '';
		if (!isset($s['responses_max_output_tokens'])) $s['responses_max_output_tokens'] = 6000;
		// Formatos (UI). Nota: el engine fuerza intro=panorámica, resto=banner.
		if (!isset($s['image_format_intro'])) $s['image_format_intro'] = 'panoramic_1536x1024';
		if (!isset($s['image_format_body'])) $s['image_format_body'] = 'banner_1536x1024';
		if (!isset($s['image_format_conclusion'])) $s['image_format_conclusion'] = 'banner_1536x1024';
		if (!isset($s['image_format_faq'])) $s['image_format_faq'] = 'banner_1536x1024';
		if (!isset($s['blocked_models']) || !is_array($s['blocked_models'])) $s['blocked_models'] = [];
		if (!isset($s['default_author_id'])) $s['default_author_id'] = 0;

		echo '<div style="margin-top:12px;">';
		if (isset($_GET['saved'])) {
			echo '<div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>';
		}

		echo '<form method="post">';
		wp_nonce_field('cbia_config_save_action', 'cbia_config_nonce');

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label>OpenAI API Key</label></th><td>';
		echo '<input type="password" name="openai_api_key" value="' . esc_attr((string)($s['openai_api_key'] ?? '')) . '" style="width:420px;" autocomplete="off" />';
		echo '<p class="description">Se guarda en la base de datos. Recomendado usar una key con permisos mínimos.</p>';
		echo '</td></tr>';

		// AUTOR POR DEFECTO
		echo '<tr><th scope="row"><label>Autor por defecto</label></th><td>';
		echo '<p class="description">Recomendado para ejecuciones por evento/cron. Si lo dejas en “Automático”, WordPress puede mostrar “—” si no hay usuario actual.</p>';

		// Dropdown autores
		$args = [
			'name'             => 'default_author_id',
			'selected'         => (int)$s['default_author_id'],
			'show_option_none' => '— Automático (usuario actual / admin) —',
			'option_none_value'=> 0,
			'who'              => 'authors',
			'class'            => 'regular-text',
		];

		// wp_dropdown_users imprime directamente
		ob_start();
		wp_dropdown_users($args);
		$dd = ob_get_clean();
		// Ajuste ancho
		$dd = str_replace('class=\'', 'style="width:420px;" class=\'', $dd);
		$dd = str_replace('class="', 'style="width:420px;" class="', $dd);
		echo $dd;

		echo '</td></tr>';

		$models = cbia_get_allowed_models_for_ui();
		echo '<tr><th scope="row"><label>Modelo (texto)</label></th><td>';
		echo '<select name="openai_model" style="width:420px;">';
		foreach ($models as $m) {
			$label = $m;
			if ($m === $recommended) $label .= ' (RECOMENDADO)';
			echo '<option value="' . esc_attr($m) . '" ' . selected($s['openai_model'], $m, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">Recomendado: <strong>' . esc_html($recommended) . '</strong>. El motor rechazará modelos marcados abajo (aunque estén seleccionados).</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label>Temperature</label></th><td>';
		echo '<input type="text" name="openai_temperature" value="' . esc_attr((string)$s['openai_temperature']) . '" style="width:120px;" />';
		echo '<p class="description">Rango recomendado: 0.0 a 1.0 (máx 2.0).</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label>Max output tokens</label></th><td>';
		echo '<input type="number" min="1500" max="12000" name="responses_max_output_tokens" value="' . esc_attr((string)$s['responses_max_output_tokens']) . '" style="width:120px;" />';
		echo '<p class="description">Sube este valor si el texto sale cortado. Recomendado 6000–8000.</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label>Longitud de post</label></th><td>';
		$variants = [
			'short'  => 'Short (~1000 palabras)',
			'medium' => 'Medium (~1700 palabras)',
			'long'   => 'Long (~2200 palabras)',
		];
		foreach ($variants as $k => $label) {
			echo '<label style="display:block;margin:4px 0;">';
			echo '<input type="radio" name="post_length_variant" value="' . esc_attr($k) . '" ' . checked($s['post_length_variant'], $k, false) . ' /> ';
			echo esc_html($label);
			echo '</label>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label>Límite de imágenes</label></th><td>';
		echo '<input type="number" min="1" max="4" name="images_limit" value="' . esc_attr((string)$s['images_limit']) . '" style="width:120px;" />';
		echo '<p class="description">Cuántos marcadores [IMAGEN: ...] se respetan (1 a 4).</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label>Prompt “todo en uno”</label></th><td>';
		echo '<textarea name="prompt_single_all" rows="10" style="width:100%;">' . esc_textarea((string)($s['prompt_single_all'] ?? '')) . '</textarea>';
		echo '<p class="description">Usa {title}. Marcadores: [IMAGEN: descripción].</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label>Idioma del post</label></th><td>';
		$language_options = [
			'español'   => 'Español',
			'portugués' => 'Portugués',
			'inglés'    => 'Inglés',
			'francés'   => 'Francés',
			'italiano'  => 'Italiano',
			'alemán'    => 'Alemán',
		];
		echo '<select name="post_language" style="width:220px;">';
		foreach ($language_options as $val => $label) {
			echo '<option value="' . esc_attr($val) . '" ' . selected($s['post_language'], $val, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">Se usa para {IDIOMA_POST} y para normalizar el título de “Preguntas frecuentes”.</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label>FAQ: título personalizado</label></th><td>';
		echo '<input type="text" name="faq_heading_custom" value="' . esc_attr((string)$s['faq_heading_custom']) . '" style="width:420px;" />';
		echo '<p class="description">Si lo rellenas, se fuerza este <code>&lt;h2&gt;</code> para la sección de FAQ.</p>';
		echo '</td></tr>';


		// Imagen IA (formato + prompt por sección)
		$formats = cbia_config_image_formats_catalog();
		echo '<tr><th scope="row"><label>Imagen IA (formato y prompt por sección)</label></th><td>';
		echo '<p class="description">Nota: el plugin fuerza <strong>destacada/intro = panorámica</strong> y <strong>resto = banner</strong> (como en v8.4). Esta UI se guarda igualmente para mantener coherencia y poder ajustarlo en el futuro.</p>';

		// INTRO
		echo '<p style="margin:12px 0 6px;"><strong>Formato de imagen para Introducción (destacada)</strong></p>';
		echo '<select name="image_format_intro" style="width:420px;">';
		foreach ($formats as $k => $label) {
			echo '<option value="' . esc_attr($k) . '" ' . selected($s['image_format_intro'], $k, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">Nota: el plugin fuerza: destacada = panorámica, resto = banner.</p>';

		echo '<p style="margin:12px 0 6px;"><strong>Prompt de imagen para Introducción (destacada)</strong></p>';
		echo '<textarea name="prompt_img_intro" rows="3" style="width:100%;">' . esc_textarea((string)($s['prompt_img_intro'] ?? '')) . '</textarea>';
		echo '<p class="description">Se usa como base y se concatena con la descripción del marcador. Sin texto ni logos. En banner se refuerza headroom y márgenes.</p>';

		// CUERPO
		echo '<p style="margin:16px 0 6px;"><strong>Formato de imagen para Cuerpo</strong></p>';
		echo '<select name="image_format_body" style="width:420px;">';
		foreach ($formats as $k => $label) {
			echo '<option value="' . esc_attr($k) . '" ' . selected($s['image_format_body'], $k, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">Nota: el plugin fuerza: destacada = panorámica, resto = banner.</p>';

		echo '<p style="margin:12px 0 6px;"><strong>Prompt de imagen para Cuerpo</strong></p>';
		echo '<textarea name="prompt_img_body" rows="3" style="width:100%;">' . esc_textarea((string)($s['prompt_img_body'] ?? '')) . '</textarea>';
		echo '<p class="description">Se usa como base y se concatena con la descripción del marcador. Sin texto ni logos. En banner se refuerza headroom y márgenes.</p>';

		// CIERRE / CONCLUSIÓN
		echo '<p style="margin:16px 0 6px;"><strong>Formato de imagen para Cierre</strong></p>';
		echo '<select name="image_format_conclusion" style="width:420px;">';
		foreach ($formats as $k => $label) {
			echo '<option value="' . esc_attr($k) . '" ' . selected($s['image_format_conclusion'], $k, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">Nota: el plugin fuerza: destacada = panorámica, resto = banner.</p>';

		echo '<p style="margin:12px 0 6px;"><strong>Prompt de imagen para Cierre</strong></p>';
		echo '<textarea name="prompt_img_conclusion" rows="3" style="width:100%;">' . esc_textarea((string)($s['prompt_img_conclusion'] ?? '')) . '</textarea>';
		echo '<p class="description">Se usa como base y se concatena con la descripción del marcador. Sin texto ni logos. En banner se refuerza headroom y márgenes.</p>';

		// FAQ
		echo '<p style="margin:16px 0 6px;"><strong>Formato de imagen para FAQ</strong></p>';
		echo '<select name="image_format_faq" style="width:420px;">';
		foreach ($formats as $k => $label) {
			echo '<option value="' . esc_attr($k) . '" ' . selected($s['image_format_faq'], $k, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">Nota: el plugin fuerza: destacada = panorámica, resto = banner.</p>';

		echo '<p style="margin:12px 0 6px;"><strong>Prompt de imagen para FAQ</strong></p>';
		echo '<textarea name="prompt_img_faq" rows="3" style="width:100%;">' . esc_textarea((string)($s['prompt_img_faq'] ?? '')) . '</textarea>';
		echo '<p class="description">Se usa como base y se concatena con la descripción del marcador. Sin texto ni logos. En banner se refuerza headroom y márgenes.</p>';

		echo '</td></tr>';

		echo '<tr><th scope="row"><label>Categoría por defecto</label></th><td>';
		echo '<input type="text" name="default_category" value="' . esc_attr((string)$s['default_category']) . '" style="width:420px;" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label>Reglas: keywords → categorías</label></th><td>';
		echo '<textarea name="keywords_to_categories" rows="6" style="width:100%;">' . esc_textarea((string)($s['keywords_to_categories'] ?? '')) . '</textarea>';
		echo '<p class="description">Formato por línea: <code>Categoria: kw1, kw2, kw3</code>. Se compara contra (título+contenido).</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label>Tags permitidas</label></th><td>';
		echo '<input type="text" name="default_tags" value="' . esc_attr((string)($s['default_tags'] ?? '')) . '" style="width:100%;" />';
		echo '<p class="description">Separadas por comas. El engine SOLO podrá usar estas tags (máx 7 por post).</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label>Bloquear modelos (no usables)</label></th><td>';
		echo '<p class="description">Si marcas un modelo, el motor lo rechazará aunque esté seleccionado.</p>';
		$blocked = is_array($s['blocked_models']) ? $s['blocked_models'] : [];
		echo '<div style="columns:2;max-width:920px;">';
		foreach ($models as $m) {
			$checked = isset($blocked[$m]) ? 'checked' : '';
			$label = $m;
			if ($m === $recommended) $label .= ' (RECOMENDADO)';
			echo '<label style="display:block;margin:3px 0;">';
			echo '<input type="checkbox" name="blocked_models[' . esc_attr($m) . ']" value="1" ' . $checked . ' /> ';
			echo esc_html($label);
			echo '</label>';
		}
		echo '</div>';
		echo '</td></tr>';

		echo '</table>';

		echo '<p>';
		echo '<button type="submit" name="cbia_config_save" class="button button-primary">Guardar configuración</button>';
		echo '</p>';

		echo '</form>';
		echo '</div>';
	}
}
