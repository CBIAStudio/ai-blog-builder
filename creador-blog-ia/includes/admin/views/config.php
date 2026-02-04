<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) return;

$settings_service = isset($cbia_settings_service) ? $cbia_settings_service : null;
$s = $settings_service && method_exists($settings_service, 'get_settings')
    ? $settings_service->get_settings()
    : (function_exists('cbia_get_settings') ? cbia_get_settings() : []);

$recommended = function_exists('cbia_get_recommended_text_model') ? cbia_get_recommended_text_model() : 'gpt-4.1-mini';
if (!isset($s['openai_model'])) $s['openai_model'] = $recommended;
if (!isset($s['openai_temperature'])) $s['openai_temperature'] = 0.7;
if (!isset($s['post_length_variant'])) $s['post_length_variant'] = 'medium';
if (!isset($s['images_limit'])) $s['images_limit'] = 1;
if (!isset($s['default_category'])) $s['default_category'] = 'Noticias';
if (!isset($s['post_language'])) $s['post_language'] = 'espanol';
if (!isset($s['faq_heading_custom'])) $s['faq_heading_custom'] = '';
if (!isset($s['responses_max_output_tokens'])) $s['responses_max_output_tokens'] = 6000;
if (!isset($s['openai_consent'])) $s['openai_consent'] = 0;
if (!isset($s['default_author_id'])) $s['default_author_id'] = 0;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- aviso informativo
$saved = isset($_GET['saved']) ? sanitize_text_field(wp_unslash((string) $_GET['saved'])) : '';
if ($saved !== '') {
    echo '<div class="notice notice-success is-dismissible"><p>Configuracion guardada.</p></div>';
}

echo '<form method="post">';
wp_nonce_field('cbia_config_save_action', 'cbia_config_nonce');
echo '<input type="hidden" name="cbia_config_save" value="1" />';

echo '<table class="form-table" role="presentation">';

echo '<tr><th scope="row"><label>OpenAI API Key</label></th><td>';
echo '<input type="password" name="openai_api_key" value="' . esc_attr((string)($s['openai_api_key'] ?? '')) . '" style="width:420px;" autocomplete="off" />';
echo '<p class="description">Se guarda en la base de datos. Recomendado usar una key con permisos minimos.</p>';
echo '<p class="description"><strong>Transparencia:</strong> Este plugin usa la API de OpenAI para generar texto e imagenes. No se realizan llamadas sin acciones explicitas del usuario.</p>';
echo '<label style="display:block;margin-top:6px;">';
echo '<input type="checkbox" name="openai_consent" value="1" ' . checked(!empty($s['openai_consent']), true, false) . ' /> ';
echo 'Confirmo que tengo permiso para enviar contenido a la API de OpenAI.</label>';
echo '</td></tr>';

// Autor por defecto
$args = [
    'name'             => 'default_author_id',
    'selected'         => (int)$s['default_author_id'],
    'show_option_none' => '? Automatico (usuario actual / admin) ?',
    'option_none_value'=> 0,
    'capability'       => ['edit_posts'],
    'class'            => 'regular-text',
];
ob_start();
wp_dropdown_users($args);
$dd = ob_get_clean();
$dd = str_replace('class=\'', 'style="width:420px;" class=\'', $dd);
$dd = str_replace('class="', 'style="width:420px;" class="', $dd);
$dd_safe = wp_kses(
    $dd,
    [
        'select' => [
            'name' => true,
            'id' => true,
            'class' => true,
            'style' => true,
        ],
        'option' => [
            'value' => true,
            'selected' => true,
        ],
        'optgroup' => [
            'label' => true,
        ],
    ]
);

echo '<tr><th scope="row"><label>Autor por defecto</label></th><td>';
echo '<p class="description">Recomendado para ejecuciones automaticas.</p>';
echo $dd_safe;
echo '</td></tr>';

$models = function_exists('cbia_get_allowed_models_for_ui') ? cbia_get_allowed_models_for_ui() : [$recommended];
echo '<tr><th scope="row"><label>Modelo (texto)</label></th><td>';
echo '<select name="openai_model" style="width:420px;">';
foreach ($models as $m) {
    $label = $m;
    if ($m === $recommended) $label .= ' (RECOMENDADO)';
    echo '<option value="' . esc_attr($m) . '" ' . selected($s['openai_model'], $m, false) . '>' . esc_html($label) . '</option>';
}
echo '</select>';
echo '</td></tr>';

echo '<tr><th scope="row"><label>Temperature</label></th><td>';
echo '<input type="text" name="openai_temperature" value="' . esc_attr((string)$s['openai_temperature']) . '" style="width:120px;" />';
echo '<p class="description">Rango recomendado: 0.0 a 1.0 (max 2.0).</p>';
echo '</td></tr>';

echo '<tr><th scope="row"><label>Max output tokens</label></th><td>';
echo '<input type="number" min="1500" max="12000" name="responses_max_output_tokens" value="' . esc_attr((string)$s['responses_max_output_tokens']) . '" style="width:120px;" />';
echo '<p class="description">Sube este valor si el texto sale cortado. Recomendado 6000?8000.</p>';
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

echo '<tr><th scope="row"><label>Limite de imagenes</label></th><td>';
echo '<code>1</code> <span class="description">(FREE: solo imagen destacada)</span>';
echo '</td></tr>';

echo '<tr><th scope="row"><label>Prompt ?todo en uno?</label></th><td>';
echo '<textarea name="prompt_single_all" rows="8" style="width:100%;">' . esc_textarea((string)($s['prompt_single_all'] ?? '')) . '</textarea>';
echo '<p class="description">Usa {title}. Marcadores: [IMAGEN: descripcion].</p>';
echo '</td></tr>';

echo '<tr><th scope="row"><label>Idioma del post</label></th><td>';
$language_options = [
    'espanol'   => 'Espanol',
    'portugues' => 'Portugues',
    'ingles'    => 'Ingles',
    'frances'   => 'Frances',
    'italiano'  => 'Italiano',
    'aleman'    => 'Aleman',
];
echo '<select name="post_language" style="width:220px;">';
foreach ($language_options as $val => $label) {
    echo '<option value="' . esc_attr($val) . '" ' . selected($s['post_language'], $val, false) . '>' . esc_html($label) . '</option>';
}
echo '</select>';
echo '</td></tr>';

echo '<tr><th scope="row"><label>FAQ: titulo personalizado</label></th><td>';
echo '<input type="text" name="faq_heading_custom" value="' . esc_attr((string)$s['faq_heading_custom']) . '" style="width:420px;" />';
echo '</td></tr>';

echo '<tr><th scope="row"><label>Categoria por defecto</label></th><td>';
echo '<input type="text" name="default_category" value="' . esc_attr((string)$s['default_category']) . '" style="width:420px;" />';
echo '</td></tr>';

echo '<tr><th scope="row"><label>Tags permitidas</label></th><td>';
echo '<input type="text" name="default_tags" value="' . esc_attr((string)($s['default_tags'] ?? '')) . '" style="width:100%;" />';
echo '<p class="description">Separadas por comas. El engine solo podra usar estas tags (max 7 por post).</p>';
echo '</td></tr>';

echo '</table>';

echo '<p><button type="submit" name="cbia_config_save" class="button button-primary">Guardar configuracion</button></p>';

echo '</form>';

