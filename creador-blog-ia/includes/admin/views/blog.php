<?php
if (!defined('ABSPATH')) exit;

// Blog tab view (extracted from legacy cbia-blog.php)

if (!current_user_can('manage_options')) return;

$service = isset($cbia_blog_service) ? $cbia_blog_service : null;

$saved_notice = '';
if ($service && method_exists($service, 'handle_post')) {
    $saved_notice = (string)$service->handle_post();
} elseif (function_exists('cbia_blog_handle_post')) {
    $saved_notice = cbia_blog_handle_post();
}

$settings = $service && method_exists($service, 'get_settings')
    ? $service->get_settings()
    : (function_exists('cbia_get_settings') ? cbia_get_settings() : (array)get_option('cbia_settings', array()));

$mode = $settings['title_input_mode'] ?? 'manual';
$manual_titles = $settings['manual_titles'] ?? '';
$csv_url = $settings['csv_url'] ?? '';

$first_dt = $settings['first_publication_datetime'] ?? '';
$first_dt_local = '';
if ($first_dt !== '') $first_dt_local = substr(str_replace(' ', 'T', $first_dt), 0, 16);

$interval = max(1, intval($settings['publication_interval'] ?? 5));
$enable_cron = !empty($settings['enable_cron_fill']);

$cp_status = 'inactivo';
$last_dt = '(sin registros)';
if ($service && method_exists($service, 'get_checkpoint_status')) {
    $status_payload = $service->get_checkpoint_status();
    if (is_array($status_payload)) {
        $cp_status = (string)($status_payload['status'] ?? $cp_status);
        $last_dt = (string)($status_payload['last'] ?? $last_dt);
    }
} else {
    $cp = cbia_checkpoint_get();
    $cp_status = (!empty($cp) && !empty($cp['running']))
        ? ('EN CURSO | idx '.intval($cp['idx'] ?? 0).' de '.count((array)($cp['queue'] ?? array())))
        : 'inactivo';
    $last_dt = $service && method_exists($service, 'get_last_scheduled_at')
        ? ($service->get_last_scheduled_at() ?: '(sin registros)')
        : (function_exists('cbia_get_last_scheduled_at') ? (cbia_get_last_scheduled_at() ?: '(sin registros)') : '(sin registros)');
}
$log_payload = $service && method_exists($service, 'get_log') ? $service->get_log() : cbia_get_log();
$log_text = is_array($log_payload) ? (string)($log_payload['log'] ?? '') : '';

if ($saved_notice === 'guardado') {
    echo '<div class="notice notice-success is-dismissible"><p>Configuración de Blog guardada.</p></div>';
} elseif ($saved_notice === 'test') {
    echo '<div class="notice notice-success is-dismissible"><p>Prueba ejecutada. Revisa el log.</p></div>';
} elseif ($saved_notice === 'stop') {
    echo '<div class="notice notice-warning is-dismissible"><p>Stop activado.</p></div>';
} elseif ($saved_notice === 'pending') {
    echo '<div class="notice notice-success is-dismissible"><p>Relleno de pendientes ejecutado. Revisa el log.</p></div>';
} elseif ($saved_notice === 'checkpoint') {
    echo '<div class="notice notice-success is-dismissible"><p>Checkpoint limpiado y programación reseteada.</p></div>';
} elseif ($saved_notice === 'log') {
    echo '<div class="notice notice-success is-dismissible"><p>Log limpiado.</p></div>';
}

$ajax_nonce = wp_create_nonce('cbia_ajax_nonce');
?>
<h2>Programación</h2>
<form method="post">
<input type="hidden" name="cbia_form" value="blog_save" />
<?php wp_nonce_field('cbia_blog_save_nonce'); ?>

<table class="form-table">
<tr>
<th>Primera fecha/hora</th>
<td>
<input type="datetime-local" name="first_publication_datetime_local" value="<?php echo esc_attr($first_dt_local); ?>" />
<p class="description">Si lo dejas vacío, empieza inmediato. Si defines fecha/hora, la primera se programa y las siguientes respetan el intervalo.</p>
</td>
</tr>
<tr>
<th>Intervalo entre publicaciones (días)</th>
<td>
<input type="number" min="1" name="publication_interval" value="<?php echo esc_attr($interval); ?>" style="width:90px;" />
</td>
</tr>
</table>

<h2>CRON: rellenar pendientes</h2>
<label>
<input type="checkbox" name="enable_cron_fill" <?php checked($enable_cron); ?> />
Activar CRON hourly para rellenar imágenes pendientes
</label>

<h2>Títulos</h2>
<table class="form-table">
<tr>
<th>Modo</th>
<td>
<label><input type="radio" name="title_input_mode" value="manual" <?php checked($mode,'manual'); ?> /> Manual</label>
&nbsp;&nbsp;
<label><input type="radio" name="title_input_mode" value="csv" <?php checked($mode,'csv'); ?> /> CSV</label>
</td>
</tr>
<tr id="cbia_row_manual" <?php if($mode!=='manual') echo 'style="display:none;"'; ?>>
<th>Títulos manuales</th>
<td>
<textarea name="manual_titles" rows="6" style="width:100%;max-width:1100px;" placeholder="Un título por línea"><?php echo esc_textarea($manual_titles); ?></textarea>
<p class="description">Guarda y luego pulsa “Crear Blogs (con reanudación)”.</p>
<p style="margin-top:10px;">
<button type="submit" class="button button-primary">Guardar</button>
</p>
</td>
</tr>
<tr id="cbia_row_csv" <?php if($mode!=='csv') echo 'style="display:none;"'; ?>>
<th>URL CSV</th>
<td>
<input type="text" name="csv_url" value="<?php echo esc_attr($csv_url); ?>" style="width:100%;max-width:1100px;" />
</td>
</tr>
</table>
</form>

<hr/>

<h2>Estado del checkpoint</h2>
<p><strong id="cbia_cp_status"><?php echo esc_html($cp_status); ?></strong></p>
<p><strong>Última programada/publicada:</strong> <code id="cbia_cp_last"><?php echo esc_html($last_dt); ?></code></p>

<hr/>

<h2>Acciones</h2>
<form method="post" id="cbia_actions_form">
<input type="hidden" name="cbia_form" value="blog_actions" />
<?php wp_nonce_field('cbia_blog_actions_nonce'); ?>

<p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
<button type="submit" class="button" name="cbia_action" value="test_config">Probar configuración</button>

<button type="button" class="button button-primary" id="cbia_btn_generate">Crear Blogs (con reanudación)</button>

<button type="submit" class="button" name="cbia_action" value="stop_generation" style="background:#b70000;color:#fff;border-color:#7a0000;">Detener (STOP)</button>
<button type="submit" class="button" name="cbia_action" value="fill_pending_imgs">Rellenar pendientes</button>
<button type="submit" class="button" name="cbia_action" value="clear_checkpoint">Limpiar checkpoint</button>
<button type="submit" class="button" name="cbia_action" value="clear_log">Limpiar log</button>
</p>
</form>

<h2>Log</h2>
<textarea id="cbia_log" rows="14" readonly style="width:100%;max-width:1100px;background:#f9f9f9;"><?php echo esc_textarea($log_text); ?></textarea>

<script>
(function(){
    const manualRow = document.getElementById('cbia_row_manual');
    const csvRow = document.getElementById('cbia_row_csv');
    const radios = document.querySelectorAll('input[name="title_input_mode"]');
    radios.forEach(r => r.addEventListener('change', function(){
        if(this.value === 'manual'){ manualRow.style.display=''; csvRow.style.display='none'; }
        else { manualRow.style.display='none'; csvRow.style.display=''; }
    }));

    const logBox = document.getElementById('cbia_log');

    function extractLogText(payload){
        if (!payload) return '';
        if (typeof payload === 'string') return payload;
        if (typeof payload === 'object') {
            if (payload.log && typeof payload.log === 'string') return payload.log;
            if (payload.data && payload.data.log && typeof payload.data.log === 'string') return payload.data.log;
            try { return JSON.stringify(payload, null, 2); } catch(e){ return String(payload); }
        }
        return String(payload);
    }

    function refreshLog(){
        if (typeof ajaxurl === 'undefined') return;
        const logUrl = ajaxurl + '?action=cbia_get_log&_ajax_nonce=' + encodeURIComponent(<?php echo wp_json_encode($ajax_nonce); ?>);
        fetch(logUrl, { credentials:'same-origin' })
        .then(r => r.json())
        .then(data => {
            if(!logBox) return;
            if (data && data.success) {
                logBox.value = extractLogText(data.data);
            } else {
                logBox.value = extractLogText(data);
            }
            logBox.scrollTop = logBox.scrollHeight;
        })
        .catch(()=>{});
    }
    setInterval(refreshLog, 3000);
    refreshLog();

    const cpStatus = document.getElementById('cbia_cp_status');
    const cpLast = document.getElementById('cbia_cp_last');

    function refreshCheckpoint(){
        if (typeof ajaxurl === 'undefined') return;
        const statusUrl = ajaxurl + '?action=cbia_get_checkpoint_status&_ajax_nonce=' + encodeURIComponent(<?php echo wp_json_encode($ajax_nonce); ?>);
        fetch(statusUrl, { credentials:'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success || !data.data) return;
            if (cpStatus) cpStatus.textContent = data.data.status || '';
            if (cpLast) cpLast.textContent = data.data.last || '';
        })
        .catch(()=>{});
    }
    setInterval(refreshCheckpoint, 5000);
    refreshCheckpoint();

    const btn = document.getElementById('cbia_btn_generate');
    if(btn){
        btn.addEventListener('click', function(){
            btn.disabled = true;
            const old = btn.textContent;
            btn.textContent = 'Lanzando…';

            const fd = new FormData();
            fd.append('action','cbia_start_generation');
            fd.append('_ajax_nonce', <?php echo wp_json_encode($ajax_nonce); ?>);

            fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: fd })
            .then(r => r.json())
            .then(data => {
                if(data && data.success){
                    btn.textContent = 'En marcha (ver log)…';
                    setTimeout(()=>{ btn.disabled=false; btn.textContent=old; }, 4000);
                }else{
                    btn.disabled=false; btn.textContent=old;
                    alert((data && data.data) ? data.data : 'No se pudo iniciar');
                }
            })
            .catch(e => {
                btn.disabled=false; btn.textContent=old;
                alert('Error: ' + e.message);
            });
        });
    }
})();
</script>
