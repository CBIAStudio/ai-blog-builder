<?php
if (!defined('ABSPATH')) exit;

// Costs tab view (extracted from legacy cbia-costes.php)

if (!function_exists('cbia_render_view_costs')) {
    function cbia_render_view_costs() {
if (!current_user_can('manage_options')) return;

$cbia = cbia_get_settings();
$service = isset($cbia_costs_service) ? $cbia_costs_service : null;
$cost = $service && method_exists($service, 'get_settings')
    ? $service->get_settings()
    : cbia_costes_get_settings();

$defaults = array(
    'usd_to_eur' => 0.92,
    'tokens_per_word' => 1.30,
    'input_overhead_tokens' => 350,
    'per_image_overhead_words' => 18,
    'cached_input_ratio' => 0.0, // 0..1
    // ImÃ¡genes: usar precio fijo por generaciÃ³n (recomendado)
    'use_image_flat_pricing' => 1,
    'image_flat_usd_mini' => 0.040,
    'image_flat_usd_full' => 0.080,
    // Ajustes finos
    'responses_fixed_usd_per_call' => 0.000,
    'real_adjust_multiplier' => 1.00,
    // Ajuste automÃ¡tico por modelo (solo si el multiplicador REAL estÃ¡ en 1.0)
    'real_adjust_multiplier_by_model' => array(
        'gpt-5-mini' => 1.12,
        'gpt-5.1-mini' => 1.12,
    ),

    // Multiplicadores para aproximar fallos/reintentos
    'mult_text'  => 1.00,
    'mult_image' => 1.00,
    'mult_seo'   => 1.00,

    // llamadas por post (estimaciÃ³n)
    'text_calls_per_post'  => 1,
    'image_calls_per_post' => 0, // 0 => usa images_limit

    // modelo imagen
    'image_model' => 'gpt-image-1-mini',

    // output tokens por llamada de imagen (opcional)
    'image_output_tokens_per_call' => 0,

    // SEO (relleno Yoast / metas / etc)
    'seo_calls_per_post' => 0,
    'seo_model' => '',
    'seo_input_tokens_per_call' => 320,
    'seo_output_tokens_per_call' => 180,
);
$cost = array_merge($defaults, $cost);

$table = cbia_costes_price_table_usd_per_million();

$model_text_current = isset($cbia['openai_model']) ? (string)$cbia['openai_model'] : 'gpt-4.1-mini';
if (!isset($table[$model_text_current])) $model_text_current = 'gpt-4.1-mini';

$model_img_current = isset($cost['image_model']) ? (string)$cost['image_model'] : 'gpt-image-1-mini';
if (!isset($table[$model_img_current])) $model_img_current = 'gpt-image-1-mini';

$model_seo_current = (string)($cost['seo_model'] ?? '');
if ($model_seo_current === '' || !isset($table[$model_seo_current])) $model_seo_current = $model_text_current;

$notice = '';
$calibration_info = null;

/* ===== Handle POST ===== */

if ($service && method_exists($service, 'handle_post')) {
    list($cost, $notice, $calibration_info) = $service->handle_post($cost, $cbia, $defaults, $table, $model_text_current);
} elseif (function_exists('cbia_costes_handle_post')) {
    list($cost, $notice, $calibration_info) = cbia_costes_handle_post($cost, $cbia, $defaults, $table, $model_text_current);
}


// refrescar
$cost_latest = $service && method_exists($service, 'get_settings')
    ? $service->get_settings()
    : cbia_costes_get_settings();
$cost = array_merge($defaults, $cost_latest);
$log  = $service && method_exists($service, 'get_log')
    ? $service->get_log()
    : cbia_costes_log_get();

$model_text_current = isset($cbia['openai_model']) ? (string)$cbia['openai_model'] : 'gpt-4.1-mini';
if (!isset($table[$model_text_current])) $model_text_current = 'gpt-4.1-mini';

$model_img_current = isset($cost['image_model']) ? (string)$cost['image_model'] : 'gpt-image-1-mini';
if (!isset($table[$model_img_current])) $model_img_current = 'gpt-image-1-mini';

$model_seo_current = (string)($cost['seo_model'] ?? '');
if ($model_seo_current === '' || !isset($table[$model_seo_current])) $model_seo_current = $model_text_current;

// Ajuste efectivo aplicado ahora mismo (UX: hacerlo visible)
$applied_mult = (float)($cost['real_adjust_multiplier'] ?? 1.0);
$applied_source = 'global';
if ($applied_mult <= 0) $applied_mult = 1.0;
if ($applied_mult == 1.0 && function_exists('cbia_costes_get_model_multiplier')) {
    $model_mult = (float)cbia_costes_get_model_multiplier($model_text_current, $cost);
    if ($model_mult > 0 && $model_mult != 1.0) {
        $applied_mult = $model_mult;
        $applied_source = 'modelo';
    }
}
// llamadas por post
$text_calls = max(1, (int)$cost['text_calls_per_post']);
$img_calls  = (int)$cost['image_calls_per_post'];

if ($img_calls <= 0) {
    $img_calls = isset($cbia['images_limit']) ? (int)$cbia['images_limit'] : 3;
}
$img_calls = max(0, min(20, $img_calls));

$seo_calls = max(0, (int)$cost['seo_calls_per_post']);
$seo_calls = min(20, $seo_calls);

// EstimaciÃ³n tokens TEXTO por llamada
$in_tokens_text_per_call  = cbia_costes_estimate_input_tokens('{title}', $cbia, (float)$cost['tokens_per_word'], (int)$cost['input_overhead_tokens']);
$out_tokens_text_per_call = cbia_costes_estimate_output_tokens($cbia, (float)$cost['tokens_per_word']);

// Imagen: input por llamada, output configurable
$in_tokens_img_per_call   = cbia_costes_estimate_image_prompt_input_tokens_per_call($cbia, (float)$cost['tokens_per_word'], (int)$cost['per_image_overhead_words']);
$out_tokens_img_per_call  = max(0, (int)$cost['image_output_tokens_per_call']);

// SEO: tokens por llamada configurables
$in_tokens_seo_per_call   = max(0, (int)$cost['seo_input_tokens_per_call']);
$out_tokens_seo_per_call  = max(0, (int)$cost['seo_output_tokens_per_call']);

// Multiplicadores reintentos
$in_tokens_text_per_call_m  = (int)ceil($in_tokens_text_per_call  * (float)$cost['mult_text']);
$out_tokens_text_per_call_m = (int)ceil($out_tokens_text_per_call * (float)$cost['mult_text']);

$in_tokens_img_per_call_m   = (int)ceil($in_tokens_img_per_call   * (float)$cost['mult_image']);
$out_tokens_img_per_call_m  = (int)ceil($out_tokens_img_per_call  * (float)$cost['mult_image']);

$in_tokens_seo_per_call_m   = (int)ceil($in_tokens_seo_per_call   * (float)$cost['mult_seo']);
$out_tokens_seo_per_call_m  = (int)ceil($out_tokens_seo_per_call  * (float)$cost['mult_seo']);

// Totales por post
$in_tokens_text_total  = $in_tokens_text_per_call_m  * $text_calls;
$out_tokens_text_total = $out_tokens_text_per_call_m * $text_calls;

$in_tokens_img_total   = $in_tokens_img_per_call_m   * $img_calls;
$out_tokens_img_total  = $out_tokens_img_per_call_m  * $img_calls;

$in_tokens_seo_total   = $in_tokens_seo_per_call_m   * $seo_calls;
$out_tokens_seo_total  = $out_tokens_seo_per_call_m  * $seo_calls;

// Costes estimados por bloque
list($eur_total_text, $eur_in_text, $eur_out_text) =
    cbia_costes_calc_cost_eur($model_text_current, $in_tokens_text_total, $out_tokens_text_total, (float)$cost['usd_to_eur'], (float)$cost['cached_input_ratio']);

list($eur_total_img, $eur_in_img, $eur_out_img) =
    cbia_costes_calc_cost_eur($model_img_current, $in_tokens_img_total, $out_tokens_img_total, (float)$cost['usd_to_eur'], (float)$cost['cached_input_ratio']);

$eur_total_seo = 0.0; $eur_in_seo = 0.0; $eur_out_seo = 0.0;
if ($seo_calls > 0 && ($in_tokens_seo_total > 0 || $out_tokens_seo_total > 0)) {
    list($eur_total_seo_tmp, $eur_in_seo_tmp, $eur_out_seo_tmp) =
        cbia_costes_calc_cost_eur($model_seo_current, $in_tokens_seo_total, $out_tokens_seo_total, (float)$cost['usd_to_eur'], (float)$cost['cached_input_ratio']);
    if ($eur_total_seo_tmp !== null) {
        $eur_total_seo = (float)$eur_total_seo_tmp;
        $eur_in_seo = (float)$eur_in_seo_tmp;
        $eur_out_seo = (float)$eur_out_seo_tmp;
    }
}

$eur_total_est = null;
if ($eur_total_text !== null && $eur_total_img !== null) {
    $eur_total_est = (float)$eur_total_text + (float)$eur_total_img + (float)$eur_total_seo;
}

// Notices
if ($notice === 'saved') {
    echo '<div class="notice notice-success is-dismissible"><p>ConfiguraciÃ³n de Costes guardada.</p></div>';
} elseif ($notice === 'log') {
    echo '<div class="notice notice-success is-dismissible"><p>Log limpiado.</p></div>';
} elseif ($notice === 'calc') {
    echo '<div class="notice notice-success is-dismissible"><p>CÃ¡lculo ejecutado. Revisa el log.</p></div>';
}

if (is_array($calibration_info)) {
    $actual_eur = (float)($calibration_info['actual_eur'] ?? 0);
    $estimated_eur = (float)($calibration_info['estimated_eur'] ?? 0);
    $suggested = (float)($calibration_info['suggested'] ?? 1);
    echo '<div class="notice notice-success is-dismissible"><p><strong>CalibraciÃ³n aplicada.</strong> ' .
    'Billing: ' . esc_html(number_format($actual_eur, 4, ',', '.')) . ' â‚¬ | ' .
    'Real calculado: ' . esc_html(number_format($estimated_eur, 4, ',', '.')) . ' â‚¬ | ' .
    'Multiplicador: <code>' . esc_html(number_format($suggested, 4, ',', '.')) . '</code></p></div>';
}
?>
<div class="wrap" style="padding-left:0;">
<h2>Costes</h2>
<div class="notice notice-info" style="margin:8px 0 16px 0;">
<p style="margin:6px 0;">
<strong>Ajuste REAL efectivo:</strong>
<code><?php echo esc_html(number_format((float)$applied_mult, 4, ',', '.')); ?>Ã—</code>
<?php if ($applied_source === 'modelo') : ?>
<span class="description">(por modelo: <?php echo esc_html($model_text_current); ?>)</span>
<?php else : ?>
<span class="description">(por ajuste global)</span>
<?php endif; ?>
</p>
</div>
<?php
    }
}

cbia_render_view_costs();

<h3>EstimaciÃ³n rÃ¡pida (segÃºn Config actual)</h3>
<table class="widefat striped" style="max-width:980px;">
<tbody>
<tr>
<td style="width:280px;"><strong>Modelo TEXTO (Config)</strong></td>
<td>
<code><?php echo esc_html($model_text_current); ?></code>
</td>
</tr>
<tr>
<td><strong>Modelo IMAGEN (Costes)</strong></td>
<td><code><?php echo esc_html($model_img_current); ?></code></td>
</tr>
<tr>
<td><strong>Modelo SEO (Costes)</strong></td>
<td><code><?php echo esc_html($model_seo_current); ?></code></td>
</tr>
<tr>
<td><strong>Llamadas texto por post</strong></td>
<td><code><?php echo esc_html((int)$text_calls); ?></code></td>
</tr>
<tr>
<td><strong>Llamadas imagen por post</strong></td>
<td><code><?php echo esc_html((int)$img_calls); ?></code></td>
</tr>
<tr>
<td><strong>Llamadas SEO por post</strong></td>
<td><code><?php echo esc_html((int)$seo_calls); ?></code></td>
</tr>
<tr>
<td><strong>Input tokens TEXTO (total post)</strong></td>
<td><code><?php echo esc_html((int)$in_tokens_text_total); ?></code></td>
</tr>
<tr>
<td><strong>Output tokens TEXTO (total post)</strong></td>
<td><code><?php echo esc_html((int)$out_tokens_text_total); ?></code></td>
</tr>
<tr>
<td><strong>Input tokens IMAGEN (total post)</strong></td>
<td><code><?php echo esc_html((int)$in_tokens_img_total); ?></code></td>
</tr>
<tr>
<td><strong>Output tokens IMAGEN (total post)</strong></td>
<td><code><?php echo esc_html((int)$out_tokens_img_total); ?></code> <span class="description">(si lo dejas a 0, solo estimamos input)</span></td>
</tr>
<tr>
<td><strong>Input tokens SEO (total post)</strong></td>
<td><code><?php echo esc_html((int)$in_tokens_seo_total); ?></code></td>
</tr>
<tr>
<td><strong>Output tokens SEO (total post)</strong></td>
<td><code><?php echo esc_html((int)$out_tokens_seo_total); ?></code></td>
</tr>
<tr>
<td><strong>Coste estimado (TEXTO)</strong></td>
<td>
<?php
echo ($eur_total_text === null)
? '<span style="color:#b70000;">Modelo no encontrado en tabla</span>'
: '<strong>' . esc_html(number_format((float)$eur_total_text, 4, ',', '.')) . ' â‚¬</strong> <span class="description">(in ' . number_format((float)$eur_in_text, 4, ',', '.') . ' â‚¬ | out ' . number_format((float)$eur_out_text, 4, ',', '.') . ' â‚¬)</span>';
?>
</td>
</tr>
<tr>
<td><strong>Coste estimado (IMÃGENES)</strong></td>
<td>
<?php
echo ($eur_total_img === null)
? '<span style="color:#b70000;">Modelo no encontrado en tabla</span>'
: '<strong>' . esc_html(number_format((float)$eur_total_img, 4, ',', '.')) . ' â‚¬</strong> <span class="description">(in ' . number_format((float)$eur_in_img, 4, ',', '.') . ' â‚¬ | out ' . number_format((float)$eur_out_img, 4, ',', '.') . ' â‚¬)</span>';
?>
</td>
</tr>
<tr>
<td><strong>Coste estimado (SEO)</strong></td>
<td>
<strong><?php echo esc_html(number_format((float)$eur_total_seo, 4, ',', '.')); ?> â‚¬</strong>
<span class="description">(in <?php echo esc_html(number_format((float)$eur_in_seo, 4, ',', '.')); ?> â‚¬ | out <?php echo esc_html(number_format((float)$eur_out_seo, 4, ',', '.')); ?> â‚¬)</span>
</td>
</tr>
<tr>
<td><strong>Coste total estimado</strong></td>
<td>
<?php
echo ($eur_total_est === null)
? '<span style="color:#b70000;">No se pudo estimar (modelo no en tabla)</span>'
: '<strong style="font-size:16px;">' . esc_html(number_format((float)$eur_total_est, 4, ',', '.')) . ' â‚¬</strong>';
?>
</td>
</tr>
</tbody>
</table>

<hr/>

<h3>ConfiguraciÃ³n</h3>
<form method="post" action="" autocomplete="off">
<input type="hidden" name="cbia_form" value="costes_settings" />
<?php wp_nonce_field('cbia_costes_settings_nonce'); ?>

<table class="form-table" style="max-width:980px;">
<tr>
<th>ConversiÃ³n USD â†’ EUR</th>
<td>
<input type="number" step="0.01" min="0.5" max="1.5" name="usd_to_eur" value="<?php echo esc_attr((string)$cost['usd_to_eur']); ?>" style="width:120px;" />
</td>
</tr>
<tr>
<th>Tokens por palabra (aprox)</th>
<td>
<input type="number" step="0.01" min="0.5" max="2" name="tokens_per_word" value="<?php echo esc_attr((string)$cost['tokens_per_word']); ?>" style="width:120px;" />
</td>
</tr>
<tr>
<th>Overhead input (tokens) por llamada de texto</th>
<td>
<input type="number" min="0" max="5000" name="input_overhead_tokens" value="<?php echo esc_attr((int)$cost['input_overhead_tokens']); ?>" style="width:120px;" />
</td>
</tr>
<tr>
<th>Overhead por imagen (palabras) por llamada</th>
<td>
<input type="number" min="0" max="300" name="per_image_overhead_words" value="<?php echo esc_attr((int)$cost['per_image_overhead_words']); ?>" style="width:120px;" />
</td>
</tr>
<tr>
<th>Ratio cached input (0..1)</th>
<td>
<input type="number" step="0.05" min="0" max="1" name="cached_input_ratio" value="<?php echo esc_attr((string)$cost['cached_input_ratio']); ?>" style="width:120px;" />
</td>
</tr>
<tr>
<th>Sobrecoste fijo por llamada TEXTO/SEO (USD)</th>
<td>
<input type="number" step="0.001" min="0" max="0.050" name="responses_fixed_usd_per_call" value="<?php echo esc_attr((string)$cost['responses_fixed_usd_per_call']); ?>" style="width:120px;" />
<p class="description">Ajuste fino para cuadrar con el billing real (se aplica a cada llamada de texto/SEO).</p>
</td>
</tr>
<tr>
<th>Multiplicador reintentos (texto)</th>
<td>
<input type="number" step="0.05" min="1" max="5" name="mult_text" value="<?php echo esc_attr((string)$cost['mult_text']); ?>" style="width:120px;" />
</td>
</tr>
<tr>
<th>Multiplicador reintentos (imÃ¡genes)</th>
<td>
<input type="number" step="0.05" min="1" max="5" name="mult_image" value="<?php echo esc_attr((string)$cost['mult_image']); ?>" style="width:120px;" />
</td>
</tr>
<tr>
<th>ImÃ¡genes: usar precio fijo por generaciÃ³n</th>
<td>
<label><input type="checkbox" name="use_image_flat_pricing" value="1" <?php checked(!empty($cost['use_image_flat_pricing'])); ?> /> Activar (recomendado). MÃ¡s cercano al billing real.</label>
<p class="description">Si estÃ¡ activo, la estimaciÃ³n y el cÃ¡lculo REAL usarÃ¡n precio fijo por imagen, ignorando tokens de imagen.</p>
</td>
</tr>
<tr>
<th>Multiplicador reintentos (SEO)</th>
<td>
<input type="number" step="0.05" min="1" max="5" name="mult_seo" value="<?php echo esc_attr((string)$cost['mult_seo']); ?>" style="width:120px;" />
</td>
</tr>
<tr>
<th>Ajuste multiplicador total (REAL)</th>
<td>
<input type="number" step="0.01" min="0.5" max="1.5" name="real_adjust_multiplier" value="<?php echo esc_attr((string)$cost['real_adjust_multiplier']); ?>" style="width:120px;" />
<p class="description">Multiplica el total real. Ãštil para compensar pequeÃ±as diferencias de conversiÃ³n/rounding.</p>
</td>
</tr>
<tr>
<th>NÂº llamadas de TEXTO por post</th>
<td>
<input type="number" min="1" max="20" name="text_calls_per_post" value="<?php echo esc_attr((int)$cost['text_calls_per_post']); ?>" style="width:120px;" />
<p class="description">Si tu engine hace mÃ¡s de 1 llamada para el texto, sÃºbelo aquÃ­.</p>
</td>
</tr>
<tr>
<th>NÂº llamadas de IMAGEN por post</th>
<td>
<input type="number" min="0" max="20" name="image_calls_per_post" value="<?php echo esc_attr((int)$cost['image_calls_per_post']); ?>" style="width:120px;" />
<p class="description">Si pones 0, se usa <code>images_limit</code> de Config.</p>
</td>
</tr>
<tr>
<th>Modelo de imagen</th>
<td>
<select name="image_model" class="abb-select" style="width:240px;">
<option value="gpt-image-1-mini" <?php selected($model_img_current, 'gpt-image-1-mini'); ?>>gpt-image-1-mini</option>
<option value="gpt-image-1" <?php selected($model_img_current, 'gpt-image-1'); ?>>gpt-image-1</option>
</select>
<p class="description">Precios fijos por imagen (USD): mini <input type="number" step="0.001" min="0" name="image_flat_usd_mini" value="<?php echo esc_attr((string)$cost['image_flat_usd_mini']); ?>" style="width:90px;" /> &nbsp;full <input type="number" step="0.001" min="0" name="image_flat_usd_full" value="<?php echo esc_attr((string)$cost['image_flat_usd_full']); ?>" style="width:90px;" /></p>
</td>
</tr>
<tr>
<th>Output tokens por llamada de imagen (opcional)</th>
<td>
<input type="number" min="0" max="50000" name="image_output_tokens_per_call" value="<?php echo esc_attr((int)$cost['image_output_tokens_per_call']); ?>" style="width:120px;" />
<p class="description">Si lo dejas en 0, la estimaciÃ³n contarÃ¡ bÃ¡sicamente el input.</p>
</td>
</tr>
<tr><th colspan="2"><hr/></th></tr>
<tr>
<th>NÂº llamadas SEO por post</th>
<td>
<input type="number" min="0" max="20" name="seo_calls_per_post" value="<?php echo esc_attr((int)$cost['seo_calls_per_post']); ?>" style="width:120px;" />
<p class="description">Si tu relleno Yoast/SEO hace llamadas a OpenAI (meta, keyphrase, etc), ponlas aquÃ­ para estimaciÃ³n.</p>
</td>
</tr>
<tr>
<th>Modelo SEO</th>
<td>
<select name="seo_model" class="abb-select" style="width:240px;">
<?php
$seo_candidates = array('gpt-4.1-mini','gpt-4.1','gpt-4.1-nano','gpt-5','gpt-5-mini','gpt-5-nano','gpt-5.1','gpt-5.2');
foreach ($seo_candidates as $m) {
    if (!isset($table[$m])) continue;
    echo '<option value="' . esc_attr($m) . '" ' . selected($model_seo_current, $m, false) . '>' . esc_html($m) . '</option>';
}
?>
</select>
<p class="description">Si no sabes, deja el mismo que el de texto.</p>
</td>
</tr>
<tr>
<th>Input tokens por llamada SEO</th>
<td>
<input type="number" min="0" max="50000" name="seo_input_tokens_per_call" value="<?php echo esc_attr((int)$cost['seo_input_tokens_per_call']); ?>" style="width:120px;" />
</td>
</tr>
<tr>
<th>Output tokens por llamada SEO</th>
<td>
<input type="number" min="0" max="50000" name="seo_output_tokens_per_call" value="<?php echo esc_attr((int)$cost['seo_output_tokens_per_call']); ?>" style="width:120px;" />
</td>
</tr>
</table>

<p>
<button type="submit" class="button button-primary">Guardar configuraciÃ³n de Costes</button>
</p>
</form>

<hr/>

<h3>Acciones (post-hoc)</h3>
<form method="post" action="" autocomplete="off">
<input type="hidden" name="cbia_form" value="costes_actions" />
<?php wp_nonce_field('cbia_costes_actions_nonce'); ?>

<table class="form-table" style="max-width:980px;">
<tr>
<th>Calcular Ãºltimos N posts</th>
<td>
<input type="number" name="calc_last_n" min="1" max="200" value="20" style="width:120px;" />
<label style="margin-left:14px;">
<input type="checkbox" name="calc_only_cbia" value="1" checked />
Solo posts del plugin (<code>_cbia_created=1</code>)
</label>
<label style="margin-left:14px;">
<input type="checkbox" name="calc_estimate_if_missing" value="1" checked />
Si no hay usage real, usar estimaciÃ³n
</label>
</td>
</tr>
<tr>
<th>Calibrar con billing real (â‚¬)</th>
<td>
<input type="number" name="calibrate_actual_eur" step="0.01" min="0" placeholder="Ej: 1.84" style="width:120px;" />
<span class="description" style="margin-left:8px;">Introduce el gasto real para esos N posts y ajustamos el multiplicador REAL automÃ¡ticamente.</span>
</td>
</tr>
</table>

<p>
<button type="submit" class="button button-primary" name="cbia_action" value="calc_last">Calcular</button>
<button type="submit" class="button" name="cbia_action" value="calc_last_real" style="margin-left:8px;">Calcular SOLO real</button>
<button type="submit" class="button button-secondary" name="cbia_action" value="calibrate_real" style="margin-left:8px;">Calibrar REAL desde billing</button>
<button type="submit" class="button button-secondary" name="cbia_action" value="clear_log" style="margin-left:8px;">Limpiar log</button>
</p>
</form>

<h3>Log Costes</h3>
<textarea id="cbia-costes-log" rows="14" cols="120" readonly style="background:#f9f9f9;width:100%;"><?php echo esc_textarea($log); ?></textarea>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const logBox = document.getElementById('cbia-costes-log');
                function refreshLog(){
                    if (typeof ajaxurl === 'undefined') return;
                    const nonce = (window.CBIA && CBIA.nonce) ? CBIA.nonce : '';
                    const url = ajaxurl + '?action=cbia_get_costes_log' + (nonce ? '&_ajax_nonce=' + encodeURIComponent(nonce) : '');
                    fetch(url, { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(data => {
            if(data && data.success && logBox){
                if (data.data && typeof data.data === 'object' && data.data.log) {
                    logBox.value = data.data.log || '';
                } else {
                    logBox.value = data.data || '';
                }
                logBox.scrollTop = logBox.scrollHeight;
            }
        })
        .catch(() => {});
    }
    setInterval(refreshLog, 3000);
});
</script>
</div>
    }
}

cbia_render_view_costs();

