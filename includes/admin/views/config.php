<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_render_view_config')) {
    function cbia_render_view_config() {
        global $cbia_settings_service;

// Config tab view (extracted from legacy cbia-config.php)

$settings_service = isset($cbia_settings_service) ? $cbia_settings_service : null;
$s = $settings_service && method_exists($settings_service, 'get_settings')
    ? $settings_service->get_settings()
    : cbia_get_settings();

$provider_settings = function_exists('cbia_providers_get_settings') ? cbia_providers_get_settings() : [];
$providers_all = function_exists('cbia_providers_get_all') ? cbia_providers_get_all() : [];
$providers_list = isset($providers_all['providers']) && is_array($providers_all['providers']) ? $providers_all['providers'] : [];
$provider_current = function_exists('cbia_providers_get_current_provider') ? cbia_providers_get_current_provider() : 'openai';
$provider_key_urls = array(
    'openai' => 'https://platform.openai.com/api-keys',
    'google' => 'https://makersuite.google.com/app/apikey',
    'deepseek' => 'https://platform.deepseek.com/api_keys',
);

// Defaults seguros
$recommended = cbia_get_recommended_text_model();
$s['openai_model'] = cbia_config_safe_model($s['openai_model'] ?? $recommended);
if (!isset($s['openai_temperature'])) $s['openai_temperature'] = 0.7;
if (!isset($s['post_length_variant'])) $s['post_length_variant'] = 'medium';
if (!isset($s['images_limit'])) $s['images_limit'] = 1;
if (!isset($s['default_category'])) $s['default_category'] = 'Noticias';
if (!isset($s['post_language'])) $s['post_language'] = 'Espanol';
if (!isset($s['responses_max_output_tokens'])) $s['responses_max_output_tokens'] = 6000;
if (!isset($s['image_model'])) $s['image_model'] = 'gpt-image-1-mini';
// CAMBIO: nuevos settings texto/imagen
if (!isset($s['text_provider'])) $s['text_provider'] = $provider_current;
if (!isset($s['image_provider'])) $s['image_provider'] = 'openai';
if (!isset($s['text_model'])) $s['text_model'] = '';
if (!isset($s['google_api_key'])) $s['google_api_key'] = '';
if (!isset($s['deepseek_api_key'])) $s['deepseek_api_key'] = '';
// CAMBIO: Google Imagen (Vertex AI) defaults
if (!isset($s['google_project_id'])) $s['google_project_id'] = '';
if (!isset($s['google_location'])) $s['google_location'] = '';
if (!isset($s['google_service_account_json'])) $s['google_service_account_json'] = '';
if (!isset($s['content_images_banner_enabled'])) $s['content_images_banner_enabled'] = 0;
if (!isset($s['openai_consent'])) $s['openai_consent'] = 1;
// Normal: sin imágenes internas.
if (!isset($s['image_format_intro'])) $s['image_format_intro'] = 'panoramic_1536x1024';
if (!isset($s['image_format_body'])) $s['image_format_body'] = 'banner_1536x1024';
if (!isset($s['image_format_conclusion'])) $s['image_format_conclusion'] = 'banner_1536x1024';
if (!isset($s['image_format_faq'])) $s['image_format_faq'] = 'banner_1536x1024';
if (!isset($s['default_author_id'])) $s['default_author_id'] = 0;
$s['prompt_img_global'] = isset($s['prompt_img_global']) ? (string)$s['prompt_img_global'] : '';
$s['prompt_img_featured'] = isset($s['prompt_img_featured']) ? (string)$s['prompt_img_featured'] : '';
$default_img_prompt = function_exists('cbia_default_image_prompt_template') ? cbia_default_image_prompt_template() : 'Professional editorial photography. Subject: {desc} related to {title}. {format} No text, no logos, no watermarks.';
if (trim((string)$s['prompt_img_global']) === '') $s['prompt_img_global'] = $default_img_prompt;
if (trim((string)$s['prompt_img_featured']) === '') $s['prompt_img_featured'] = $s['prompt_img_global'];

echo '<div class="cbia-view-container">';
if (isset($_GET['saved'])) {
    echo '<div class="notice notice-success is-dismissible" style="background: rgba(34, 211, 238, 0.1); border-color: var(--abb-cyan); color: var(--abb-cyan);"><p>' . esc_html('Configuracion guardada con exito.') . '</p></div>';
}
// CAMBIO: avisos por API key faltante
$warnings = get_transient('cbia_config_warnings');
if (!empty($warnings) && is_array($warnings)) {
    echo '<div class="notice notice-warning"><p>' . esc_html(implode(' ', $warnings)) . '</p></div>';
    delete_transient('cbia_config_warnings');
}

echo '<form method="post">';
wp_nonce_field('cbia_config_save_action', 'cbia_config_nonce');
echo '<input type="hidden" name="cbia_config_save" value="1" />';

echo '<div class="cbia-section-header">';
echo '<div class="cbia-section-title">Proveedor y Modelo</div>';
echo '<p class="description">Configura los motores de IA para texto e imágenes.</p>';
echo '</div>';
echo '<div class="abb-card">';

$text_provider = sanitize_key((string)($s['text_provider'] ?? $provider_current));
if ($text_provider === '' || !isset($providers_list[$text_provider])) $text_provider = 'openai';
$image_provider = sanitize_key((string)($s['image_provider'] ?? 'openai'));
if ($image_provider === '' || !isset($providers_list[$image_provider])) $image_provider = 'openai';

$text_provider_data = $providers_list[$text_provider] ?? [];
$text_provider_label = (string)($text_provider_data['label'] ?? 'OpenAI');
$text_provider_logo = plugins_url('assets/images/providers/' . $text_provider . '.svg', CBIA_PLUGIN_FILE);
if (!file_exists(plugin_dir_path(CBIA_PLUGIN_FILE) . 'assets/images/providers/' . $text_provider . '.svg')) {
    $text_provider_logo = plugins_url('assets/images/providers/openai.svg', CBIA_PLUGIN_FILE);
}

$image_provider_data = $providers_list[$image_provider] ?? [];
$image_provider_label = (string)($image_provider_data['label'] ?? 'OpenAI');
$image_provider_logo = plugins_url('assets/images/providers/' . $image_provider . '.svg', CBIA_PLUGIN_FILE);
if (!file_exists(plugin_dir_path(CBIA_PLUGIN_FILE) . 'assets/images/providers/' . $image_provider . '.svg')) {
    $image_provider_logo = plugins_url('assets/images/providers/openai.svg', CBIA_PLUGIN_FILE);
}

echo '<div class="abb-provider-grid">';
// Proveedor texto
echo '<div class="abb-field">';
echo '<label>Proveedor (texto)</label>';
echo '<div class="abb-provider-select" data-scope="text">';
echo '<button type="button" class="abb-provider-trigger" aria-expanded="false">';
echo '<img class="abb-provider-logo" src="' . esc_url($text_provider_logo) . '" alt="' . esc_attr($text_provider_label) . '" />';
echo '<span class="abb-provider-label">' . esc_html($text_provider_label) . '</span>';
echo '<span class="abb-provider-caret">&#9662;</span>';
echo '</button>';
echo '<div class="abb-provider-menu">';
foreach ($providers_list as $pkey => $pdef) {
    $plabel = (string)($pdef['label'] ?? $pkey);
    $plogo = plugins_url('assets/images/providers/' . $pkey . '.svg', CBIA_PLUGIN_FILE);
    if (!file_exists(plugin_dir_path(CBIA_PLUGIN_FILE) . 'assets/images/providers/' . $pkey . '.svg')) {
        $plogo = plugins_url('assets/images/providers/openai.svg', CBIA_PLUGIN_FILE);
    }
    echo '<button type="button" class="abb-provider-option" data-value="' . esc_attr($pkey) . '" data-logo="' . esc_url($plogo) . '" data-label="' . esc_attr($plabel) . '">';
    echo '<img src="' . esc_url($plogo) . '" alt="' . esc_attr($plabel) . '" />';
    echo '<span>' . esc_html($plabel) . '</span>';
    echo '</button>';
}
echo '</div>';
echo '<select class="abb-provider-select-input" name="text_provider" data-scope="text" style="display:none;">';
foreach ($providers_list as $pkey => $pdef) {
    $plabel = (string)($pdef['label'] ?? $pkey);
    $plogo = plugins_url('assets/images/providers/' . $pkey . '.svg', CBIA_PLUGIN_FILE);
    if (!file_exists(plugin_dir_path(CBIA_PLUGIN_FILE) . 'assets/images/providers/' . $pkey . '.svg')) {
        $plogo = plugins_url('assets/images/providers/openai.svg', CBIA_PLUGIN_FILE);
    }
    echo '<option value="' . esc_attr($pkey) . '" data-logo="' . esc_url($plogo) . '"' . selected($text_provider, $pkey, false) . '>' . esc_html($plabel) . '</option>';
}
echo '</select>';
echo '</div>';
echo '</div>';

// Proveedor imagen
echo '<div class="abb-field">';
echo '<label>Proveedor (imagen)</label>';
echo '<div class="abb-provider-select" data-scope="image">';
echo '<button type="button" class="abb-provider-trigger" aria-expanded="false">';
echo '<img class="abb-provider-logo" src="' . esc_url($image_provider_logo) . '" alt="' . esc_attr($image_provider_label) . '" />';
echo '<span class="abb-provider-label">' . esc_html($image_provider_label) . '</span>';
echo '<span class="abb-provider-caret">&#9662;</span>';
echo '</button>';
echo '<div class="abb-provider-menu">';
foreach ($providers_list as $pkey => $pdef) {
    $plabel = (string)($pdef['label'] ?? $pkey);
    $plogo = plugins_url('assets/images/providers/' . $pkey . '.svg', CBIA_PLUGIN_FILE);
    if (!file_exists(plugin_dir_path(CBIA_PLUGIN_FILE) . 'assets/images/providers/' . $pkey . '.svg')) {
        $plogo = plugins_url('assets/images/providers/openai.svg', CBIA_PLUGIN_FILE);
    }
    echo '<button type="button" class="abb-provider-option" data-value="' . esc_attr($pkey) . '" data-logo="' . esc_url($plogo) . '" data-label="' . esc_attr($plabel) . '">';
    echo '<img src="' . esc_url($plogo) . '" alt="' . esc_attr($plabel) . '" />';
    echo '<span>' . esc_html($plabel) . '</span>';
    echo '</button>';
}
echo '</div>';
echo '<select class="abb-provider-select-input" name="image_provider" data-scope="image" style="display:none;">';
foreach ($providers_list as $pkey => $pdef) {
    $plabel = (string)($pdef['label'] ?? $pkey);
    $plogo = plugins_url('assets/images/providers/' . $pkey . '.svg', CBIA_PLUGIN_FILE);
    if (!file_exists(plugin_dir_path(CBIA_PLUGIN_FILE) . 'assets/images/providers/' . $pkey . '.svg')) {
        $plogo = plugins_url('assets/images/providers/openai.svg', CBIA_PLUGIN_FILE);
    }
    echo '<option value="' . esc_attr($pkey) . '" data-logo="' . esc_url($plogo) . '"' . selected($image_provider, $pkey, false) . '>' . esc_html($plabel) . '</option>';
}
echo '</select>';
echo '</div>';
echo '</div>';

// Modelos texto
$openai_models = cbia_get_allowed_models_for_ui();
foreach ($providers_list as $pkey => $pdef) {
    $text_list = ($pkey === 'openai') ? $openai_models : (function_exists('cbia_providers_get_text_model_list') ? cbia_providers_get_text_model_list($pkey) : []);
    $saved = '';
    if ($text_provider === $pkey && !empty($s['text_model'])) $saved = (string)$s['text_model'];
    if ($saved === '' && !empty($provider_settings['providers'][$pkey]['model'])) $saved = (string)$provider_settings['providers'][$pkey]['model'];
    if ($saved === '' && $pkey === 'openai' && !empty($s['openai_model'])) $saved = (string)$s['openai_model'];
    if ($saved === '' && function_exists('cbia_providers_get_recommended_text_model')) $saved = cbia_providers_get_recommended_text_model($pkey);
    echo '<div class="abb-field abb-provider-model" data-scope="text" data-provider="' . esc_attr($pkey) . '"' . ($text_provider === $pkey ? '' : ' style="display:none;"') . '>';
    echo '<label>Modelo (texto)</label>';
    echo '<select name="text_model[' . esc_attr($pkey) . ']" class="abb-select">';
    foreach ($text_list as $mdl) {
        $label = $mdl;
        if ($pkey === 'google' && $mdl === 'gemini-1.5-flash-latest') $label .= ' (Recomendado)';
        if ($pkey === 'openai' && $mdl === $recommended) $label .= ' (RECOMENDADO)';
        echo '<option value="' . esc_attr($mdl) . '" ' . selected($saved, $mdl, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</div>';
}

// Modelos imagen
foreach ($providers_list as $pkey => $pdef) {
    $img_list = function_exists('cbia_providers_get_image_model_list') ? cbia_providers_get_image_model_list($pkey) : [];
    $saved_img = '';
    if ($image_provider === $pkey && !empty($s['image_model'])) $saved_img = (string)$s['image_model'];
    if ($saved_img === '' && !empty($provider_settings['providers'][$pkey]['image_model'])) $saved_img = (string)$provider_settings['providers'][$pkey]['image_model'];
    if ($saved_img === '' && function_exists('cbia_providers_get_recommended_image_model')) $saved_img = cbia_providers_get_recommended_image_model($pkey);
    echo '<div class="abb-field abb-provider-model" data-scope="image" data-provider="' . esc_attr($pkey) . '"' . ($image_provider === $pkey ? '' : ' style="display:none;"') . '>';
    echo '<label>Modelo (imagen)</label>';
    echo '<select name="image_model_by_provider[' . esc_attr($pkey) . ']" class="abb-select">';
    if (empty($img_list)) {
        echo '<option value="">' . esc_html('No disponible') . '</option>';
    } else {
        foreach ($img_list as $mdl) {
            $label = $mdl;
            if ($mdl === 'gemini-3-pro-image-preview') $label = 'Nano Banana Pro (gemini-3-pro-image-preview)';
            echo '<option value="' . esc_attr($mdl) . '" ' . selected($saved_img, $mdl, false) . '>' . esc_html($label) . '</option>';
        }
    }
    echo '</select>';
    echo '</div>';
}

echo '</div>'; // grid

// API keys: texto
echo '<div class="abb-api-row">';
echo '<label>Clave API (texto)</label>';
foreach ($providers_list as $pkey => $pdef) {
    $key_val = '';
    if ($pkey === 'openai') $key_val = (string)($s['openai_api_key'] ?? '');
    if ($pkey === 'google') $key_val = (string)($s['google_api_key'] ?? '');
    if ($pkey === 'deepseek') $key_val = (string)($s['deepseek_api_key'] ?? '');
    if ($key_val === '' && !empty($provider_settings['providers'][$pkey]['api_key'])) $key_val = (string)$provider_settings['providers'][$pkey]['api_key'];
    $link = $provider_key_urls[$pkey] ?? '';
    echo '<div class="abb-provider-key" data-scope="text" data-provider="' . esc_attr($pkey) . '" style="display:none;">';
    echo '<div class="abb-api-input">';
		echo '<input class="abb-input" type="password" name="provider_api_key_text[' . esc_attr($pkey) . ']" value="' . esc_attr($key_val) . '" autocomplete="off" />';
    if ($link !== '') {
        echo '<a class="button button-secondary abb-api-link" href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">Obtener API Key</a>';
    }
    echo '</div>';
    echo '</div>';
}
echo '</div>'; // api row texto

// API keys: imagen
echo '<div class="abb-api-row">';
echo '<label>Clave API (imagen)</label>';
foreach ($providers_list as $pkey => $pdef) {
    $key_val = '';
    if ($pkey === 'openai') $key_val = (string)($s['openai_api_key'] ?? '');
    if ($pkey === 'google') $key_val = (string)($s['google_api_key'] ?? '');
    if ($pkey === 'deepseek') $key_val = (string)($s['deepseek_api_key'] ?? '');
    if ($key_val === '' && !empty($provider_settings['providers'][$pkey]['api_key'])) $key_val = (string)$provider_settings['providers'][$pkey]['api_key'];
    $link = $provider_key_urls[$pkey] ?? '';
    echo '<div class="abb-provider-key" data-scope="image" data-provider="' . esc_attr($pkey) . '" style="display:none;">';
    echo '<div class="abb-api-input">';
		echo '<input class="abb-input" type="password" name="provider_api_key_image[' . esc_attr($pkey) . ']" value="' . esc_attr($key_val) . '" autocomplete="off" />';
    if ($link !== '') {
        echo '<a class="button button-secondary abb-api-link" href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">Obtener API Key</a>';
    }
    echo '</div>';
    echo '</div>';
}
echo '</div>'; // api row imagen

// CAMBIO: Google Imagen (Vertex AI) extra fields
echo '<div class="abb-google-imagen-fields" data-scope="image" data-provider="google" style="display:none;">';
echo '<label style="display:block;margin-top:10px;">Google Imagen (Vertex AI)</label>';
echo '<div class="abb-api-input" style="margin-bottom:8px;">';
echo '<input class="abb-input" type="text" name="google_project_id" placeholder="Project ID" value="' . esc_attr((string)($s['google_project_id'] ?? '')) . '" />';
echo '<input class="abb-input" type="text" name="google_location" placeholder="Location (ej: us-central1)" value="' . esc_attr((string)($s['google_location'] ?? '')) . '" />';
echo '</div>';
echo '<textarea class="abb-input abb-textarea" name="google_service_account_json" rows="6" placeholder="Service Account JSON">' . esc_textarea((string)($s['google_service_account_json'] ?? '')) . '</textarea>';
echo '<p class="description abb-google-imagen-note-imagen">Requerido SOLO si el modelo de imagen es <strong>Imagen 2</strong>. Usa Project ID, Location y Service Account JSON.</p>';
echo '<p class="description abb-google-imagen-note-gemini" style="display:none;">Para <strong>Gemini 3 Pro Image Preview</strong> solo necesitas la API key de Google.</p>';
echo '</div>';

echo '<p class="description" style="margin-top:8px;">Puedes usar proveedores distintos para texto e imagen.</p>';

echo '</div>'; // provider card
echo '</div>'; // section

echo '<div class="cbia-section">';
echo '<div class="cbia-section-title">Preferencias</div>';
echo '<table class="form-table" role="presentation">';

echo '<tr><th scope="row"><label>Temperatura</label></th><td>';
echo '<input type="text" name="openai_temperature" value="' . esc_attr((string)$s['openai_temperature']) . '" style="width:120px;" />';
echo '<p class="description">Rango recomendado: 0.0 a 1.0 (max 2.0).</p>';
echo '</td></tr>';

echo '<tr><th scope="row"><label>Max tokens de salida</label></th><td>';
echo '<input type="number" min="1500" max="12000" name="responses_max_output_tokens" value="' . esc_attr((string)$s['responses_max_output_tokens']) . '" style="width:120px;" />';
echo '<p class="description">Sube este valor si el texto sale cortado. Recomendado 6000-8000.</p>';
echo '</td></tr>';

echo '<tr><th scope="row"><label>Longitud de post</label></th><td>';
$variants = [
    'short'  => 'Corto (~1000 palabras)',
    'medium' => 'Medio (~1800-2000 palabras)',
    'long'   => 'Largo (~2000-2200 palabras)',
];
foreach ($variants as $k => $label) {
    echo '<label style="display:block;margin:4px 0;">';
    echo '<input type="radio" name="post_length_variant" value="' . esc_attr($k) . '" ' . checked($s['post_length_variant'], $k, false) . ' /> ';
    echo esc_html($label);
    echo '</label>';
}
echo '</td></tr>';

echo '<tr><th scope="row"><label>Prompt imagen destacada</label></th><td>';
echo '<p class="description">El prompt base ya esta optimizado. Se combina automaticamente con [IMAGEN: ...] y el titulo del post. No es necesario ajustarlo salvo que quieras cambiar el estilo.</p>';
echo '<p class="description">Marcadores disponibles: {title}, {desc}, {format}.</p>';

echo '<div class="cbia-prompt-panel" style="margin-top:10px;">';
echo '<div class="cbia-prompt-actions" style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">';
echo '<button type="button" class="button cbia-prompt-btn" data-type="featured" data-idx="0">Editar prompt destacada</button>';
echo '</div>';
echo '<p class="description">El titulo del post se inserta automaticamente en {title}.</p>';
echo '</div>';

echo '<div id="cbia-prompt-modal" class="cbia-modal" style="display:none;">';
echo '  <div class="cbia-modal-inner">';
echo '    <div class="cbia-modal-header">';
echo '      <strong id="cbia-prompt-title">Editar prompt</strong>';
echo '      <button type="button" class="button-link cbia-modal-close">Cerrar</button>';
echo '    </div>';
echo '    <textarea id="cbia-prompt-text" rows="8" style="width:100%;"></textarea>';
echo '    <div class="cbia-modal-actions">';
echo '      <button type="button" class="button button-primary" id="cbia-prompt-save">Guardar prompt base</button>';
echo '    </div>';
echo '    <div class="cbia-modal-status" id="cbia-prompt-status"></div>';
echo '  </div>';
echo '</div>';

echo '</td></tr>';

// Normal: sin imágenes internas (solo destacada). UI eliminada.

echo '<tr><th scope="row"><label>Si falla un modelo de imagen</label></th><td>';
$image_failover = (string)($s['image_failover'] ?? 'continue');
echo '<select name="image_failover" class="abb-select" style="width:260px;">';
echo '<option value="continue" ' . selected($image_failover, 'continue', false) . '>Continuar con el siguiente modelo</option>';
echo '<option value="stop" ' . selected($image_failover, 'stop', false) . '>Detener creacion de la entrada</option>';
echo '</select>';
echo '<p class="description">Por defecto se intenta el siguiente modelo si hay error.</p>';
echo '</td></tr>';

echo '</table>';
echo '</div>';

echo '<div class="cbia-section">';
echo '<div class="cbia-section-title">Integraciones</div>';
// Aviso Yoast al final de la configuracion
$yoast_active = defined('WPSEO_VERSION') || class_exists('WPSEO_Options');
if (!$yoast_active) {
    echo '<p class="description"><strong>Yoast SEO:</strong> no detectado. Si lo instalas, el plugin puede actualizar automaticamente la metadescripcion, la keyphrase y las puntuaciones de SEO/legibilidad al crear cada post.</p>';
} else {
    echo '<p class="description"><strong>Yoast SEO:</strong> detectado. Se actualizan automaticamente la metadescripcion, la keyphrase y las puntuaciones de SEO/legibilidad al crear cada post.</p>';
}
echo '</div>';

echo '<p>';
echo '<button type="submit" name="cbia_config_save" class="button button-primary">Guardar Configuracion</button>';
echo '</p>';

echo '</form>';
echo '</div>';
    }
}

cbia_render_view_config();


