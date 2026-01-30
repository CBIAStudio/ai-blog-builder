<?php
if (!defined('ABSPATH')) exit;

// Yoast tab view (extracted from legacy cbia-yoast.php)

if (!current_user_can('manage_options')) return;

$batch = 50; $offset = 0; $force = false; $only_cbia = true;

$service = isset($cbia_yoast_service) ? $cbia_yoast_service : null;

if ($service && method_exists($service, 'handle_post')) {
    list($batch, $offset, $force, $only_cbia) = $service->handle_post($batch, $offset, $force, $only_cbia);
} elseif (function_exists('cbia_yoast_handle_post')) {
    list($batch, $offset, $force, $only_cbia) = cbia_yoast_handle_post($batch, $offset, $force, $only_cbia);
}

$log = $service && method_exists($service, 'get_log')
    ? $service->get_log()
    : cbia_yoast_log_get();
?>
<div class="wrap">
<h2>Yoast</h2>

<p>
<strong>Semáforo</strong> aquí significa rellenar:
<code>_yoast_wpseo_linkdex</code> (SEO) y <code>_yoast_wpseo_content_score</code> (Legibilidad),
para que deje de estar en gris en el listado.
</p>

<form method="post" action="">
<?php wp_nonce_field('cbia_yoast_nonce_action', 'cbia_yoast_nonce'); ?>

<h3>Acciones por lote</h3>

<table class="form-table">
<tr>
<th>Lote</th>
<td><input type="number" name="cbia_yoast_batch" min="1" max="500" value="<?php echo esc_attr($batch); ?>" style="width:110px;" /></td>
</tr>
<tr>
<th>Offset</th>
<td><input type="number" name="cbia_yoast_offset" min="0" value="<?php echo esc_attr($offset); ?>" style="width:110px;" /></td>
</tr>
<tr>
<th>Opciones</th>
<td>
<label style="display:inline-block;margin-right:18px;">
<input type="checkbox" name="cbia_yoast_force" value="1" <?php checked($force); ?> />
Forzar (reescribe metas/scores aunque existan)
</label>
<label style="display:inline-block;margin-right:18px;">
<input type="checkbox" name="cbia_yoast_include_unmarked" value="1" <?php checked(!$only_cbia); ?> />
Incluir posts no-CBIA (sin <code>_cbia_created</code>)
</label>
</td>
</tr>
</table>

<p>
<button type="submit" class="button button-secondary" name="cbia_yoast_action" value="metas">Recalcular solo metas</button>
<button type="submit" class="button button-secondary" name="cbia_yoast_action" value="semaphore" style="margin-left:8px;">Actualizar solo semáforo</button>
<button type="submit" class="button button-primary"   name="cbia_yoast_action" value="both" style="margin-left:8px;">Metas + Semáforo</button>
<button type="submit" class="button" name="cbia_yoast_action" value="clear_log" style="margin-left:8px;">Limpiar log</button>
</p>

<hr/>

<h3>Marcar antiguos como CBIA</h3>
<p>Esto añade <code>_cbia_created=1</code> a posts que no lo tengan, para que entren en lotes por defecto.</p>

<table class="form-table">
<tr>
<th>Desde (opcional)</th>
<td><input type="datetime-local" name="cbia_yoast_date_from" value="" /></td>
</tr>
<tr>
<th>Hasta (opcional)</th>
<td><input type="datetime-local" name="cbia_yoast_date_to" value="" /></td>
</tr>
<tr>
<th>Solo señales CBIA</th>
<td>
<label>
<input type="checkbox" name="cbia_yoast_only_signals" value="1" />
Marcar solo si detecta señales (FAQ JSON-LD / pendientes / marcadores en contenido)
</label>
</td>
</tr>
</table>

<p>
<button type="submit" class="button button-primary" name="cbia_yoast_action" value="mark_legacy">Marcar antiguos como CBIA</button>
</p>

<hr/>

<h3>Log Yoast</h3>
<textarea rows="14" cols="120" readonly style="background:#f9f9f9;width:100%;"><?php echo esc_textarea($log); ?></textarea>
</form>
</div>
