<?php
if (!defined('ABSPATH')) exit;

// Config tab view (extracted from legacy cbia-config.php)

$settings_service = isset($cbia_settings_service) ? $cbia_settings_service : null;
$s = $settings_service && method_exists($settings_service, 'get_settings')
    ? $settings_service->get_settings()
    : cbia_get_settings();

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
if (!isset($s['content_images_banner_enabled'])) $s['content_images_banner_enabled'] = 1;
if (!isset($s['openai_consent'])) $s['openai_consent'] = 0;
if (!isset($s['content_images_banner_css']) || trim((string)$s['content_images_banner_css']) === '') {
    $defaults = function_exists('cbia_get_default_settings') ? cbia_get_default_settings() : [];
    $s['content_images_banner_css'] = (string)($defaults['content_images_banner_css'] ?? '');
}
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

// Estado rápido (UX)
$stop_flag = function_exists('cbia_is_stop_requested') ? (bool)cbia_is_stop_requested() : false;
$next_ts = wp_next_scheduled('cbia_generation_event');
$next_txt = $next_ts ? date_i18n('Y-m-d H:i:s', (int)$next_ts) : 'no programado';
$blocked_current = function_exists('cbia_costes_is_model_blocked') ? cbia_costes_is_model_blocked((string)$s['openai_model']) : false;

$cost_settings = function_exists('cbia_costes_get_settings') ? cbia_costes_get_settings() : array();
$mult_global = (float)($cost_settings['real_adjust_multiplier'] ?? 1.0);
$mult_model = function_exists('cbia_costes_get_model_multiplier') ? (float)cbia_costes_get_model_multiplier((string)$s['openai_model'], $cost_settings) : 1.0;
$mult_effective = ($mult_global > 0 && $mult_global != 1.0) ? $mult_global : (($mult_model > 0 && $mult_model != 1.0) ? $mult_model : 1.0);
$mult_source = ($mult_global > 0 && $mult_global != 1.0) ? 'global' : (($mult_model > 0 && $mult_model != 1.0) ? 'modelo' : 'ninguno');

echo '<div class="notice notice-info" style="margin:8px 0 12px 0;">';
echo '<p style="margin:6px 0;"><strong>Estado rápido:</strong> ';
echo 'Modelo: <code>' . esc_html((string)$s['openai_model']) . '</code>';
if ($blocked_current) {
    echo ' <span style="color:#b70000;font-weight:700;">(bloqueado)</span>';
}
echo ' &nbsp;|&nbsp; STOP: <strong>' . ($stop_flag ? 'activo' : 'no') . '</strong>';
echo ' &nbsp;|&nbsp; Próximo evento: <code>' . esc_html($next_txt) . '</code>';
echo ' &nbsp;|&nbsp; Ajuste REAL efectivo: <code>x' . esc_html(number_format((float)$mult_effective, 3, ',', '.')) . '</code> <span class="description">(' . esc_html($mult_source) . ')</span>';
echo '</p>';
echo '</div>';

echo '<form method="post">';
wp_nonce_field('cbia_config_save_action', 'cbia_config_nonce');
echo '<input type="hidden" name="cbia_config_save" value="1" />';

echo '<table class="form-table" role="presentation">';

echo '<tr><th scope="row"><label>OpenAI API Key</label></th><td>';
echo '<input type="password" name="openai_api_key" value="' . esc_attr((string)($s['openai_api_key'] ?? '')) . '" style="width:420px;" autocomplete="off" />';
echo '<p class="description">Se guarda en la base de datos. Recomendado usar una key con permisos mínimos.</p>';
echo '<p class="description"><strong>Transparencia:</strong> Este plugin usa la API de OpenAI para generar texto e imágenes. No se realizan llamadas sin acciones explícitas del usuario.</p>';
echo '<label style="display:block;margin-top:6px;">';
echo '<input type="checkbox" name="openai_consent" value="1" ' . checked(!empty($s['openai_consent']), true, false) . ' /> ';
echo 'Confirmo que tengo permiso para enviar contenido a la API de OpenAI.</label>';
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
    'capability'       => ['edit_posts'],
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
if (function_exists('cbia_config_presets_catalog')) {
    $presets = cbia_config_presets_catalog();
    echo '<div style="margin-top:6px;">';
    echo '<span class="description" style="margin-right:8px;"><strong>Presets:</strong></span>';
    foreach ($presets as $pk => $pd) {
        $label = (string)($pd['label'] ?? $pk);
        echo '<button type="submit" name="cbia_preset_model" value="' . esc_attr($pk) . '" class="button button-secondary" style="margin-right:6px;margin-bottom:6px;">' . esc_html($label) . '</button>';
    }
    echo '</div>';
    echo '<p class="description">Los presets ajustan modelo, temperature y max tokens.</p>';
}
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
    'holandés'  => 'Holandés',
    'sueco'     => 'Sueco',
    'danés'     => 'Danés',
    'noruego'   => 'Noruego',
    'finés'     => 'Finés',
    'polaco'    => 'Polaco',
    'checo'     => 'Checo',
    'eslovaco'  => 'Eslovaco',
    'húngaro'   => 'Húngaro',
    'rumano'    => 'Rumano',
    'búlgaro'   => 'Búlgaro',
    'griego'    => 'Griego',
    'croata'    => 'Croata',
    'esloveno'  => 'Esloveno',
    'estonio'   => 'Estonio',
    'letón'     => 'Letón',
    'lituano'   => 'Lituano',
    'irlandés'  => 'Irlandés',
    'maltés'    => 'Maltés',
    'romanche'  => 'Romanche',
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

echo '<tr><th scope="row"><label>Imágenes de contenido como banner (no destacada)</label></th><td>';
echo '<label><input type="checkbox" name="content_images_banner_enabled" value="1" ' . checked(!empty($s['content_images_banner_enabled']), true, false) . ' /> Aplicar clase <code>cbia-banner</code> a imágenes internas (no destacada).</label>';
echo '<p class="description">Por defecto está activado. Puedes desactivarlo si no quieres aplicar estilos a las imágenes internas.</p>';
if (function_exists('cbia_config_banner_css_presets')) {
    $presets = cbia_config_banner_css_presets();
    $current_css = (string)($s['content_images_banner_css'] ?? '');
    $current_preset = function_exists('cbia_config_detect_banner_css_preset')
        ? cbia_config_detect_banner_css_preset($current_css)
        : 'custom';

    if (!empty($presets)) {
        echo '<p class="description" style="margin-top:8px;"><strong>Preset de estilo:</strong></p>';
        echo '<select name="content_images_banner_preset" id="cbia-banner-css-preset" style="width:420px;">';
        foreach ($presets as $key => $data) {
            $label = (string)($data['label'] ?? $key);
            echo '<option value="' . esc_attr($key) . '" ' . selected($current_preset, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '<option value="custom" ' . selected($current_preset, 'custom', false) . '>Personalizado (editar CSS)</option>';
        echo '</select>';
        echo '<p class="description">Si eliges un preset, el CSS se rellena automáticamente. Para editarlo, elige “Personalizado”.</p>';
    }
}

echo '<div id="cbia-banner-css-custom" style="margin-top:10px;">';
echo '<p style="margin:10px 0 6px;"><strong>CSS para imágenes internas (cbia-banner)</strong></p>';
echo '<textarea name="content_images_banner_css" id="cbia-banner-css-textarea" rows="8" style="width:100%;">' . esc_textarea((string)($s['content_images_banner_css'] ?? '')) . '</textarea>';
echo '<p class="description">Este CSS se inyecta en el frontend cuando la opción está activa. Puedes personalizarlo.</p>';
echo '</div>';

if (function_exists('cbia_config_banner_css_presets')) {
    $preset_payload = [];
    foreach ($presets as $key => $data) {
        $preset_payload[$key] = (string)($data['css'] ?? '');
    }
    $preset_json = wp_json_encode($preset_payload);
    echo '<script>
    (function(){
        var presetSelect = document.getElementById("cbia-banner-css-preset");
        var textarea = document.getElementById("cbia-banner-css-textarea");
        var customBox = document.getElementById("cbia-banner-css-custom");
        if (!presetSelect || !textarea || !customBox) return;
        var presetMap = ' . $preset_json . ';
        function applyPreset() {
            var key = presetSelect.value;
            if (key === "custom") {
                customBox.style.display = "block";
                return;
            }
            if (presetMap[key] !== undefined) {
                textarea.value = presetMap[key];
            }
            customBox.style.display = "none";
        }
        presetSelect.addEventListener("change", applyPreset);
        applyPreset();
    })();
    </script>';
}
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
echo '<p class="description">Formato por línea: <code>Categoría: kw1, kw2, kw3</code>. Se compara contra (título+contenido).</p>';
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
