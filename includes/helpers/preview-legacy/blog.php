<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) return;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

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
$blog_prompt_mode = function_exists('cbia_prompt_get_mode')
    ? cbia_prompt_get_mode((array)$settings)
    : sanitize_key((string)($settings['blog_prompt_mode'] ?? 'recommended'));
if (!in_array($blog_prompt_mode, array('recommended', 'legacy'), true)) $blog_prompt_mode = 'recommended';
$blog_prompt_editable = (string)($settings['blog_prompt_editable'] ?? '');
if ($blog_prompt_editable === '' && function_exists('cbia_prompt_recommended_editable_default')) {
    $blog_prompt_editable = cbia_prompt_recommended_editable_default();
}
$blog_prompt_editable = function_exists('cbia_prompt_sanitize_editable_block')
    ? cbia_prompt_sanitize_editable_block($blog_prompt_editable)
    : $blog_prompt_editable;
$legacy_full_prompt = (string)($settings['legacy_full_prompt'] ?? '');
$legacy_placeholder = (string)($settings['prompt_single_all'] ?? '');
$csv_url = $settings['csv_url'] ?? '';


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
        ? ('EN CURSO | idx ' . intval($cp['idx'] ?? 0) . ' de ' . count((array)($cp['queue'] ?? array())))
        : 'inactivo';
    $last_dt = $service && method_exists($service, 'get_last_scheduled_at')
        ? ($service->get_last_scheduled_at() ?: '(sin registros)')
        : (function_exists('cbia_get_last_scheduled_at') ? (cbia_get_last_scheduled_at() ?: '(sin registros)') : '(sin registros)');
}

$log_payload = $service && method_exists($service, 'get_log') ? $service->get_log() : cbia_get_log();
$log_text = is_array($log_payload) ? (string)($log_payload['log'] ?? '') : '';

if ($saved_notice === 'guardado') {
    echo '<div class="notice notice-success is-dismissible"><p>Configuracion de Blog guardada.</p></div>';
} elseif ($saved_notice === 'guardado_warn') {
    $warns = get_transient('cbia_blog_prompt_warnings');
    if (!is_array($warns)) $warns = array();
    $msg = 'Configuracion de Blog guardada con avisos.';
    if (!empty($warns)) {
        $msg .= ' ' . implode(' ', array_map('sanitize_text_field', $warns));
    }
    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($msg) . '</p></div>';
} elseif ($saved_notice === 'test') {
    echo '<div class="notice notice-success is-dismissible"><p>Prueba ejecutada. Revisa el log.</p></div>';
} elseif ($saved_notice === 'stop') {
    echo '<div class="notice notice-warning is-dismissible"><p>Stop activado.</p></div>';
} elseif ($saved_notice === 'pending') {
    echo '<div class="notice notice-success is-dismissible"><p>Relleno de pendientes ejecutado. Revisa el log.</p></div>';
} elseif ($saved_notice === 'checkpoint') {
    echo '<div class="notice notice-success is-dismissible"><p>Checkpoint limpiado y programacion reseteada.</p></div>';
} elseif ($saved_notice === 'log') {
    echo '<div class="notice notice-success is-dismissible"><p>Log limpiado.</p></div>';
}

$ajax_nonce = wp_create_nonce('cbia_ajax_nonce');
?>

<h2>Titulos</h2>
<form method="post">
<input type="hidden" name="cbia_form" value="blog_save" />
<?php wp_nonce_field('cbia_blog_save_nonce'); ?>

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
<th>Titulos manuales</th>
<td>
<div class="cbia-single-title-wrap" style="max-width:1100px;">
    <div id="cbia_single_title_card" style="display:none;align-items:center;gap:10px;border:1px solid #d7dce1;border-radius:10px;padding:10px 12px;background:#fff;">
        <span id="cbia_single_title_text" style="flex:1;word-break:break-word;"></span>
        <button type="button" id="cbia_single_title_clear" class="button" style="line-height:1;">×</button>
    </div>
    <input type="text" id="cbia_single_title_input" class="regular-text" style="width:100%;max-width:1100px;" placeholder="Introduce un titulo..." />
    <textarea name="manual_titles" id="cbia_manual_titles" rows="6" style="display:none;"><?php echo esc_textarea($manual_titles); ?></textarea>
</div>
  <p class="description">Guarda y luego pulsa "Crear blog automático".</p>

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
<tr>
<th>Prompt del contenido del blog</th>
<td>
<div class="cbia-blog-prompt-panel" style="padding:12px;border:1px solid #dcdcde;border-radius:8px;max-width:1100px;">
<p class="description" style="margin-top:0;">Prompt editorial optimizado para Google Discover e insercion de marcadores de imagen. Puedes ajustar el estilo, pero hay reglas fijas para evitar cortes y mantener compatibilidad.</p>
<p class="description" style="margin-top:0;">El idioma se aplica automaticamente segun el selector de idioma y no se edita desde el prompt.</p>

<p style="margin:8px 0;">
<label><input type="radio" name="blog_prompt_mode" value="recommended" <?php checked($blog_prompt_mode, 'recommended'); ?> /> Prompt recomendado (seguro)</label>
</p>
<p style="margin:8px 0;">
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input type="checkbox" id="cbia_toggle_advanced_prompt" <?php checked($blog_prompt_mode, 'legacy'); ?> />
    Mostrar opciones avanzadas (compatibilidad)
</label>
</p>
<div id="cbia_advanced_prompt_wrap" style="display:none;">
<p style="margin:8px 0;">
<label><input type="radio" name="blog_prompt_mode" value="legacy" <?php checked($blog_prompt_mode, 'legacy'); ?> /> Prompt avanzado (compatibilidad)</label>
</p>
<p class="description" style="margin-top:0;">Advertencia: este modo permite control total y puede romper formato, idioma o marcadores de imagen.</p>
</div>

<label style="display:inline-flex;align-items:center;gap:6px;">
    <input type="checkbox" id="cbia_toggle_prompt_edit" />
    Editar prompt
</label>

<div id="cbia_prompt_edit_wrap" style="display:none;margin-top:10px;">
    <div id="cbia_prompt_edit_recommended" style="display:none;">
        <textarea name="blog_prompt_editable" id="cbia_blog_prompt_editable" rows="12" style="width:100%;"><?php echo esc_textarea($blog_prompt_editable); ?></textarea>
        <input type="hidden" id="cbia_blog_prompt_default" value="<?php echo esc_attr(function_exists('cbia_prompt_recommended_editable_default') ? cbia_prompt_recommended_editable_default() : ''); ?>" />
        <p style="margin-top:8px;">
            <button type="button" class="button" id="cbia_btn_restore_prompt">Restaurar prompt recomendado</button>
        </p>
    </div>
    <div id="cbia_prompt_edit_legacy" style="display:none;">
        <textarea name="legacy_full_prompt" rows="12" style="width:100%;" placeholder="Prompt legado completo"><?php echo esc_textarea($legacy_full_prompt !== '' ? $legacy_full_prompt : $legacy_placeholder); ?></textarea>
        <p class="description">Modo avanzado: se usa el prompt completo historico para compatibilidad.</p>
    </div>
</div>
</div>
</td>
</tr>
</table>
</form>

<?php
$preview_titles = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$manual_titles))));
?>

<h2>Publicacion y clasificacion</h2>
<form method="post">
<input type="hidden" name="cbia_form" value="blog_save" />
<?php wp_nonce_field('cbia_blog_save_nonce'); ?>
<table class="form-table">
<tr>
<th>Autor por defecto</th>
<td>
<?php
$author_args = [
    'name'             => 'default_author_id',
    'selected'         => (int)($settings['default_author_id'] ?? 0),
    'show_option_none' => '- Automatico (usuario actual / admin) -',
    'option_none_value'=> 0,
    'capability'       => ['edit_posts'],
    'class'            => 'regular-text',
];
ob_start();
wp_dropdown_users($author_args);
$dd = ob_get_clean();
$dd = str_replace('class=\'', 'style="width:420px;" class=\'', $dd);
$dd = str_replace('class="', 'style="width:420px;" class="', $dd);
echo $dd;
?>
</td>
</tr>
<tr>
<th>Idioma del post</th>
<td>
<?php
$language_options = [
    'Espanol'   => 'Espanol',
    'Portugues' => 'Portugues',
    'Ingles'    => 'Ingles',
    'Frances'   => 'Frances',
    'italiano'  => 'Italiano',
    'Aleman'    => 'Aleman',
    'Holandes'  => 'Holandes',
    'sueco'     => 'Sueco',
    'Danes'     => 'Danes',
    'noruego'   => 'Noruego',
    'Fines'     => 'Fines',
    'polaco'    => 'Polaco',
    'checo'     => 'Checo',
    'eslovaco'  => 'Eslovaco',
    'Hungaro'   => 'Hungaro',
    'rumano'    => 'Rumano',
    'Bulgaro'   => 'Bulgaro',
    'griego'    => 'Griego',
    'croata'    => 'Croata',
    'esloveno'  => 'Esloveno',
    'estonio'   => 'Estonio',
    'Leton'     => 'Leton',
    'lituano'   => 'Lituano',
    'Irlandes'  => 'Irlandes',
    'Maltes'    => 'Maltes',
    'romanche'  => 'Romanche',
];
$current_language = (string)($settings['post_language'] ?? 'Espanol');
echo '<select name="post_language" class="abb-select" style="width:220px;">';
foreach ($language_options as $val => $label) {
    echo '<option value="' . esc_attr($val) . '" ' . selected($current_language, $val, false) . '>' . esc_html($label) . '</option>';
}
echo '</select>';
?>
<p class="description">Se usa para {IDIOMA_POST} y para normalizar el titulo de "Preguntas frecuentes".</p>
</td>
</tr>
<tr>
<th>Categoria por defecto</th>
<td>
<input type="text" name="default_category" value="<?php echo esc_attr((string)($settings['default_category'] ?? 'Noticias')); ?>" style="width:420px;" />
</td>
</tr>
<tr>
<th>Reglas: keywords - Categorias</th>
<td>
<textarea name="keywords_to_categories" rows="6" style="width:100%;"><?php echo esc_textarea((string)($settings['keywords_to_categories'] ?? '')); ?></textarea>
<p class="description">Formato por linea: <code>Categoria: kw1, kw2, kw3</code>. Se compara contra (titulo+contenido).</p>
</td>
</tr>
<tr>
<th>Tags permitidas</th>
<td>
<input type="text" name="default_tags" value="<?php echo esc_attr((string)($settings['default_tags'] ?? '')); ?>" style="width:100%;" />
<p class="description">Separadas por comas. El engine SOLO podra usar estas tags (max 7 por post).</p>
</td>
</tr>
</table>
<p style="margin-top:10px;">
<button type="submit" class="button button-primary">Guardar</button>
</p>
</form>

  <hr/>

  <h2>Acciones</h2>
<form method="post" id="cbia_actions_form">
<input type="hidden" name="cbia_form" value="blog_actions" />
<?php wp_nonce_field('cbia_blog_actions_nonce'); ?>

<p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
<button type="submit" class="button" name="cbia_action" value="test_config">Probar configuracion</button>

  <button type="button" class="button button-primary" id="cbia_btn_generate">Crear blog automÃ¡tico</button>
<button type="button" class="button" id="cbia_btn_open_preview_modal">Generacion con previsualizacion</button>

<button type="submit" class="button" name="cbia_action" value="stop_generation" style="background:#b70000;color:#fff;border-color:#7a0000;">Detener (STOP)</button>
<button type="submit" class="button" name="cbia_action" value="clear_log">Limpiar log</button>
</p>
</form>

<h2>Vista previa del articulo</h2>
<section id="cbia-preview-panel" class="cbia-preview-card" aria-live="polite" data-open="false">
<header class="cbia-preview-header">
<div class="cbia-preview-title-wrap">
<h3 class="cbia-preview-title">ARTICLE PREVIEW</h3>
<div class="cbia-preview-title-inline">
<label for="cbia_preview_title" class="screen-reader-text">Titulo</label>
<select id="cbia_preview_title" class="abb-select" style="width:420px;">
<?php if (!empty($preview_titles)): ?>
    <?php foreach ($preview_titles as $pt): ?>
        <option value="<?php echo esc_attr($pt); ?>"><?php echo esc_html($pt); ?></option>
    <?php endforeach; ?>
<?php else: ?>
    <option value="">(Primero anade titulos manuales y guarda)</option>
<?php endif; ?>
</select>
</div>
<span id="cbia-preview-wordcount" class="cbia-wordcount">0 words</span>
<span id="cbia_preview_mode_badge" class="cbia-preview-mode">STREAM</span>
</div>
<div class="cbia-preview-head-actions">
<button type="button" class="button cbia-preview-icon-btn" id="cbia_preview_btn_copy" title="Copiar texto"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>
<button type="button" class="button cbia-preview-icon-btn" id="cbia_preview_btn_expand" title="Expandir preview"><span class="dashicons dashicons-editor-expand" aria-hidden="true"></span></button>
<button type="button" class="button cbia-preview-icon-btn" id="cbia_preview_btn_edit" title="Editar preview"><span class="dashicons dashicons-edit" aria-hidden="true"></span></button>
<button type="button" class="button cbia-preview-icon-btn cbia-preview-icon-danger" id="cbia_preview_btn_clear" title="Limpiar output"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
</div>
</header>

<div class="cbia-preview-controls">
<select id="cbia_preview_mode" class="abb-select" style="width:220px;">
    <option value="fast">Preview rapido (sin imagen real)</option>
    <option value="full">Preview completo (genera imagenes)</option>
</select>
<button type="button" class="button button-primary" id="cbia_btn_preview">Generar preview</button>
</div>

<div class="cbia-preview-body">
<aside class="cbia-preview-media">
<div id="cbia-featured-image-wrap" class="cbia-featured-image" data-state="idle">
<div class="cbia-image-placeholder">Featured image preview</div>
</div>
<input id="cbia-preview-token" type="hidden" value="">
<div id="cbia_preview_runtime" class="cbia-preview-runtime" style="display:none;">
<div id="cbia_preview_phase" class="cbia-preview-phase">
<span id="cbia_phase_texto" style="padding:4px 8px;border:1px solid #dcdcde;border-radius:999px;background:#f6f7f7;">Texto</span>
<span id="cbia_phase_img" style="padding:4px 8px;border:1px solid #dcdcde;border-radius:999px;background:#f6f7f7;">Imagenes</span>
<span id="cbia_phase_ready" style="padding:4px 8px;border:1px solid #dcdcde;border-radius:999px;background:#f6f7f7;">Listo</span>
</div>
</div>
</aside>
<main class="cbia-preview-main">
<div id="cbia-preview-status" class="cbia-status">Esperando generacion...</div>
<article id="cbia-preview-content" class="cbia-preview-content"></article>
<div id="cbia_preview_edit_panel" style="display:none;">
<p style="margin:8px 0;"><strong>Editar antes de crear</strong></p>
<p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0;">
<label for="cbia_preview_edit_title">Titulo</label>
<input type="text" id="cbia_preview_edit_title" style="width:420px;" />
</p>
<p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0;">
<button type="button" class="button" id="cbia_btn_preview_edit_toggle" style="display:none;">&#9998; Editar preview</button>
<button type="button" class="button" id="cbia_btn_preview_edit_save" style="display:none;">Guardar cambios</button>
<button type="button" class="button" id="cbia_btn_preview_edit_cancel" style="display:none;">Cancelar</button>
</p>
<p class="description">Edita directamente el contenido del preview con el boton de editar. No se muestra HTML crudo.</p>
</div>
</main>
</div>

<section class="cbia-seo-card" aria-label="SEO and metadata">
<button type="button" id="cbia_preview_meta_toggle" class="button-link" style="display:flex;align-items:center;gap:6px;padding:0;text-decoration:none;">
<span id="cbia_preview_meta_arrow" class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
<h4 style="margin:0;">SEO &amp; METADATA</h4>
</button>
<div id="cbia_preview_meta_body" style="display:none;margin-top:8px;">
<div style="margin-top:8px;">
<label><strong>Excerpt</strong></label>
<textarea id="cbia_preview_meta_excerpt" rows="2" style="width:100%;"></textarea>
</div>
<div style="margin-top:8px;">
<label><strong>Etiquetas</strong></label>
<textarea id="cbia_preview_meta_tags" rows="2" style="width:100%;"></textarea>
</div>
<div style="margin-top:8px;">
<label><strong>Focus Keyword</strong></label>
<input type="text" id="cbia_preview_meta_focus" style="width:100%;" />
</div>
<div style="margin-top:8px;">
<label><strong>Meta Description</strong></label>
<textarea id="cbia_preview_meta_description" rows="2" style="width:100%;"></textarea>
</div>
</div>
</section>

<footer class="cbia-preview-actions">
<div class="cbia-preview-schedule">
<label for="cbia_preview_post_date">Programar</label>
<input type="datetime-local" id="cbia_preview_post_date" />
</div>
<div class="cbia-preview-actions-buttons">
<button id="cbia-create-draft" type="button" class="button" disabled>Guardar borrador</button>
<button id="cbia-create-publish" type="button" class="button button-primary" disabled>Publicar</button>
<button id="cbia-create-schedule" type="button" class="button" disabled>Programar</button>
</div>
</footer>
</section>

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

    const singleTitleInput = document.getElementById('cbia_single_title_input');
    const singleTitleCard = document.getElementById('cbia_single_title_card');
    const singleTitleText = document.getElementById('cbia_single_title_text');
    const singleTitleClear = document.getElementById('cbia_single_title_clear');
    const manualTitlesField = document.getElementById('cbia_manual_titles');

    function setSingleTitle(value){
        const v = (value || '').toString().replace(/\r?\n/g, ' ').trim();
        if (manualTitlesField) manualTitlesField.value = v;
        if (v !== '') {
            if (singleTitleText) singleTitleText.textContent = v;
            if (singleTitleCard) singleTitleCard.style.display = 'inline-flex';
            if (singleTitleInput) singleTitleInput.style.display = 'none';
        } else {
            if (singleTitleText) singleTitleText.textContent = '';
            if (singleTitleCard) singleTitleCard.style.display = 'none';
            if (singleTitleInput) {
                singleTitleInput.style.display = '';
                singleTitleInput.value = '';
            }
        }
    }

    if (manualTitlesField) {
        const first = (manualTitlesField.value || '').split(/\r\n|\r|\n/).map(s => s.trim()).filter(Boolean)[0] || '';
        setSingleTitle(first);
    }

    if (singleTitleInput) {
        singleTitleInput.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                setSingleTitle(singleTitleInput.value);
            }
        });
        singleTitleInput.addEventListener('blur', function(){
            if (singleTitleInput.value.trim() !== '') {
                setSingleTitle(singleTitleInput.value);
            }
        });
    }
    if (singleTitleClear) {
        singleTitleClear.addEventListener('click', function(){
            setSingleTitle('');
        });
    }

    const logBox = document.getElementById('cbia_log');

    function tryDecodeLatin1ToUtf8(str){
        try { return decodeURIComponent(escape(str)); } catch(e) { return str; }
    }
    function fixMojibake(str){
        if (typeof str !== 'string') return str;
        if (!/[\u00C3\u00C2\u00E2]/.test(str)) return str;
        let fixed = tryDecodeLatin1ToUtf8(str);
        if (fixed !== str && /[\u00C3\u00C2\u00E2]/.test(fixed)) {
            fixed = tryDecodeLatin1ToUtf8(fixed);
        }
        return fixed || str;
    }

    function extractLogText(payload){
        if (!payload) return '';
        if (typeof payload === 'string') return fixMojibake(payload);
        if (typeof payload === 'object') {
            if (payload.log && typeof payload.log === 'string') return fixMojibake(payload.log);
            if (payload.data && payload.data.log && typeof payload.data.log === 'string') return fixMojibake(payload.data.log);
            try { return JSON.stringify(payload, null, 2); } catch(e){ return String(payload); }
        }
        return fixMojibake(String(payload));
    }
    function dedupeLogLines(text){
        const raw = String(text || '');
        if (!raw) return '';
        const lines = raw.split(/\r?\n/);
        const seen = new Set();
        const out = [];
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            if (!line) continue;
            if (seen.has(line)) continue;
            seen.add(line);
            out.push(line);
        }
        return out.join('\n');
    }

    function refreshLog(){
        if (typeof ajaxurl === 'undefined') return;
        const logUrl = ajaxurl + '?action=cbia_get_log&_ajax_nonce=' + encodeURIComponent(<?php echo wp_json_encode($ajax_nonce); ?>) + '&ts=' + Date.now();
        fetch(logUrl, { credentials:'same-origin', cache:'no-store' })
        .then(r => r.text())
        .then(text => {
            if(!logBox) return;
            let data = null;
            try { data = JSON.parse(text); } catch(e) { return; }
            if (data && data.success) {
                logBox.value = dedupeLogLines(extractLogText(data.data));
            } else {
                logBox.value = dedupeLogLines(extractLogText(data));
            }
            logBox.scrollTop = logBox.scrollHeight;
        })
        .catch(()=>{});
    }
    if (logBox && /[\u00C3\u00C2\u00E2]/.test(logBox.value)) {
        logBox.value = fixMojibake(logBox.value);
    }
    setInterval(refreshLog, 3000);
    refreshLog();

    const btn = document.getElementById('cbia_btn_generate');
    if(btn){
        btn.addEventListener('click', function(){
            btn.disabled = true;
            const old = btn.textContent;
            btn.textContent = 'Lanzando...';

            const fd = new FormData();
            fd.append('action','cbia_start_generation');
            fd.append('_ajax_nonce', <?php echo wp_json_encode($ajax_nonce); ?>);

            fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: fd })
            .then(r => r.text())
            .then(text => {
                let data = null;
                try { data = JSON.parse(text); } catch(e) { data = null; }
                if(data && data.success){
                    btn.textContent = 'En marcha (ver log)...';
                    setTimeout(()=>{ btn.disabled=false; btn.textContent=old; }, 4000);
                }else{
                    btn.disabled=false; btn.textContent=old;
                }
            })
            .catch(() => {
                btn.disabled=false; btn.textContent=old;
            });
        });
    }

    const previewOpenBtn = document.getElementById('cbia_btn_open_preview_modal');
    const previewBtn = document.getElementById('cbia_btn_preview');
    const previewPanel = document.getElementById('cbia-preview-panel');
    const previewTitle = document.getElementById('cbia_preview_title');
    const previewMode = document.getElementById('cbia_preview_mode');
    const previewRuntime = document.getElementById('cbia_preview_runtime');
    const previewStatus = document.getElementById('cbia-preview-status');
    const previewWordCount = document.getElementById('cbia-preview-wordcount');
    const previewModeBadge = document.getElementById('cbia_preview_mode_badge');
    const phaseTexto = document.getElementById('cbia_phase_texto');
    const phaseImg = document.getElementById('cbia_phase_img');
    const phaseReady = document.getElementById('cbia_phase_ready');
    const previewMetaExcerpt = document.getElementById('cbia_preview_meta_excerpt');
    const previewMetaTags = document.getElementById('cbia_preview_meta_tags');
    const previewMetaFocus = document.getElementById('cbia_preview_meta_focus');
    const previewMetaDesc = document.getElementById('cbia_preview_meta_description');
    const previewFeaturedWrap = document.getElementById('cbia-featured-image-wrap');
    const previewMetaToggle = document.getElementById('cbia_preview_meta_toggle');
    const previewMetaArrow = document.getElementById('cbia_preview_meta_arrow');
    const previewMetaBody = document.getElementById('cbia_preview_meta_body');
    const previewHtml = document.getElementById('cbia-preview-content');
    const previewTokenField = document.getElementById('cbia-preview-token');
    const previewBtnCopy = document.getElementById('cbia_preview_btn_copy');
    const previewBtnExpand = document.getElementById('cbia_preview_btn_expand');
    const previewBtnClear = document.getElementById('cbia_preview_btn_clear');
    const previewBtnEdit = document.getElementById('cbia_preview_btn_edit');
    const previewEditPanel = document.getElementById('cbia_preview_edit_panel');
    const previewEditTitle = document.getElementById('cbia_preview_edit_title');
    const previewEditToggleBtn = document.getElementById('cbia_btn_preview_edit_toggle');
    const previewEditSaveBtn = document.getElementById('cbia_btn_preview_edit_save');
    const previewEditCancelBtn = document.getElementById('cbia_btn_preview_edit_cancel');
    const previewPostDate = document.getElementById('cbia_preview_post_date');
    const createDraftBtn = document.getElementById('cbia-create-draft');
    const createPublishBtn = document.getElementById('cbia-create-publish');
    const createScheduleBtn = document.getElementById('cbia-create-schedule');
    const promptModeChecked = () => document.querySelector('input[name="blog_prompt_mode"]:checked');
    const promptEditable = document.getElementById('cbia_blog_prompt_editable');
    const legacyPrompt = document.querySelector('textarea[name="legacy_full_prompt"]');
    const postLanguage = document.querySelector('select[name="post_language"]');
    const imagesLimit = document.querySelector('select[name="images_limit"]');
    let previewToken = '';
    let previewOriginalHtml = '';
    let progressiveQueue = [];
    let progressiveTimer = null;
    let progressiveLastHtml = '';
    let previewExpanded = false;
    let progressiveMode = 'stream';

    function setPreviewStatus(msg, isError){
        if (!previewStatus) return;
        previewStatus.textContent = msg || '';
        previewStatus.style.color = isError ? '#b32d2e' : '#50575e';
    }
    function setWordCount(count){
        if (!previewWordCount) return;
        const n = Number(count || 0);
        previewWordCount.textContent = (n > 0 ? n : 0) + ' words';
    }
    function setPreviewModeBadge(mode){
        if (!previewModeBadge) return;
        if (mode === 'classic') {
            previewModeBadge.textContent = 'SIMULADO';
            previewModeBadge.style.borderColor = '#fed7aa';
            previewModeBadge.style.color = '#9a3412';
            previewModeBadge.style.background = '#fff7ed';
            return;
        }
        previewModeBadge.textContent = 'STREAM';
        previewModeBadge.style.borderColor = '#bae6fd';
        previewModeBadge.style.color = '#0c4a6e';
        previewModeBadge.style.background = '#f0f9ff';
    }
    function calcWordCountFromHtml(html){
        const text = String(html || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        if (!text) return 0;
        return text.split(' ').length;
    }
    function setFeaturedState(status, message, url){
        if (!previewFeaturedWrap) return;
        const state = status || 'idle';
        previewFeaturedWrap.dataset.state = state;
        if (state === 'done' && url) {
            previewFeaturedWrap.innerHTML = '<img src="' + String(url) + '" alt="" />';
            return;
        }
        if (state === 'error') {
            previewFeaturedWrap.innerHTML = '<div class="cbia-image-placeholder">No se pudo generar la imagen destacada.</div>';
            return;
        }
        if (state === 'placeholder') {
            previewFeaturedWrap.innerHTML = '<div class="cbia-image-placeholder">' + (message || 'Preview rapido: imagen destacada en placeholder.') + '</div>';
            return;
        }
        previewFeaturedWrap.innerHTML = '<div class="cbia-image-placeholder">' + (message || 'Imagen destacada en proceso...') + '</div>';
    }
    function renderSeoMeta(excerpt, tags, focus, metaDescription){
        if (previewMetaExcerpt) previewMetaExcerpt.value = excerpt || '';
        if (previewMetaTags) previewMetaTags.value = Array.isArray(tags) ? tags.join(', ') : (tags || '');
        if (previewMetaFocus) previewMetaFocus.value = focus || '';
        if (previewMetaDesc) previewMetaDesc.value = metaDescription || '';
    }
    function processPreviewHtml(html){
        const raw = String(html || '');
        if (!raw) return '';
        return raw.replace(/\[IMAGEN:[^\]]*\]/gi, function(marker){
            const label = marker.replace(/^\[IMAGEN:\s*/i, '').replace(/\]$/, '').trim();
            return '<figure class="cbia-preview-img-ph"><span class="dashicons dashicons-format-image" aria-hidden="true"></span><span class="description">' + (label ? label : 'Imagen en proceso...') + '</span></figure>';
        });
    }
    function renderInitialImagePlaceholders(){
        if (!previewHtml) return;
        const count = Number(imagesLimit && imagesLimit.value ? imagesLimit.value : 0);
        if (!count || count < 1) {
            previewHtml.innerHTML = '<div class="cbia-preview-img-ph"><span class="dashicons dashicons-format-image" aria-hidden="true"></span><span class="description">Imagenes en proceso...</span></div>';
            return;
        }
        let html = '';
        for (let i = 0; i < count; i++) {
            html += '<div class="cbia-preview-img-ph"><span class="dashicons dashicons-format-image" aria-hidden="true"></span><span class="description">Imagen ' + (i + 1) + ' en proceso...</span></div>';
        }
        previewHtml.innerHTML = html;
    }
    function clearProgressiveQueue(){
        progressiveQueue = [];
        progressiveLastHtml = '';
        progressiveMode = 'stream';
        setPreviewModeBadge('stream');
        if (progressiveTimer) {
            clearTimeout(progressiveTimer);
            progressiveTimer = null;
        }
    }
    function runProgressiveQueue(){
        if (progressiveTimer || !progressiveQueue.length) return;
        function nextDelayByRemaining(remaining){
            if (progressiveMode === 'classic') {
                if (remaining > 24) return 55;
                if (remaining > 16) return 75;
                if (remaining > 10) return 95;
                if (remaining > 6) return 115;
                if (remaining > 3) return 135;
                return 160;
            }
            if (remaining > 24) return 30;
            if (remaining > 16) return 42;
            if (remaining > 10) return 56;
            if (remaining > 6) return 70;
            if (remaining > 3) return 84;
            return 100;
        }
        const tick = function(){
            if (!progressiveQueue.length) {
                progressiveTimer = null;
                return;
            }
            const next = progressiveQueue.shift();
            if (previewHtml && typeof next.html === 'string') {
                previewHtml.innerHTML = processPreviewHtml(next.html);
            }
            const liveCount = Number(next.word_count || 0) > 0 ? Number(next.word_count || 0) : calcWordCountFromHtml(processPreviewHtml(next.html || ''));
            setWordCount(liveCount);
            progressiveTimer = setTimeout(tick, nextDelayByRemaining(progressiveQueue.length));
        };
        progressiveTimer = setTimeout(tick, progressiveMode === 'classic' ? 70 : 35);
    }
    function enqueueProgressiveHtml(html, wordCount){
        if (typeof html !== 'string') return;
        const clean = html.trim();
        if (!clean || clean === progressiveLastHtml) return;
        progressiveLastHtml = clean;
        progressiveQueue.push({
            html: html,
            word_count: Number(wordCount || 0),
        });
        runProgressiveQueue();
    }
    function splitHtmlProgressChunks(html){
        const source = String(html || '');
        if (!source.trim()) return [];
        const parts = source.split(/(<\/(?:p|h2|h3|ul|ol|li|figure|div)>|<img\b[^>]*>)/i);
        const chunks = [];
        let acc = '';
        for (let i = 0; i < parts.length; i++) {
            const part = String(parts[i] || '');
            if (!part) continue;
            acc += part;
            if (/(<\/(?:p|h2|h3|ul|ol|li|figure|div)>|<img\b[^>]*>)$/i.test(part)) {
                chunks.push(acc);
            }
        }
        if (!chunks.length || chunks[chunks.length - 1] !== acc) {
            chunks.push(acc);
        }
        return chunks;
    }
    function enqueueClassicSimulation(finalHtml){
        clearProgressiveQueue();
        progressiveMode = 'classic';
        setPreviewModeBadge('classic');
        const chunks = splitHtmlProgressChunks(finalHtml);
        if (!chunks.length) {
            enqueueProgressiveHtml(finalHtml, calcWordCountFromHtml(finalHtml));
            return;
        }
        for (let i = 0; i < chunks.length; i++) {
            enqueueProgressiveHtml(chunks[i], calcWordCountFromHtml(chunks[i]));
        }
    }
    function setPreviewEditMode(enabled){
        if (!previewHtml) return;
        previewHtml.contentEditable = enabled ? 'true' : 'false';
        previewHtml.style.outline = enabled ? '2px solid #0ea5e9' : 'none';
        previewHtml.style.cursor = enabled ? 'text' : 'default';
        if (previewEditToggleBtn) previewEditToggleBtn.style.display = enabled ? 'none' : '';
        if (previewEditSaveBtn) previewEditSaveBtn.style.display = enabled ? '' : 'none';
        if (previewEditCancelBtn) previewEditCancelBtn.style.display = enabled ? '' : 'none';
    }
    function copyPreviewText(){
        if (!previewHtml) return;
        const text = (previewHtml.innerText || previewHtml.textContent || '').trim();
        if (!text) {
            setPreviewStatus('No hay contenido para copiar.', true);
            return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function(){
                setPreviewStatus('Contenido copiado al portapapeles.', false);
            }).catch(function(){
                setPreviewStatus('No se pudo copiar al portapapeles.', true);
            });
            return;
        }
        const aux = document.createElement('textarea');
        aux.value = text;
        document.body.appendChild(aux);
        aux.select();
        try {
            document.execCommand('copy');
            setPreviewStatus('Contenido copiado al portapapeles.', false);
        } catch (e) {
            setPreviewStatus('No se pudo copiar al portapapeles.', true);
        }
        document.body.removeChild(aux);
    }
    function togglePreviewExpand(){
        if (!previewPanel) return;
        previewExpanded = !previewExpanded;
        if (previewExpanded) {
            previewPanel.classList.add('cbia-preview-expanded');
            document.body.classList.add('cbia-preview-lock');
            if (previewBtnExpand) previewBtnExpand.title = 'Contraer preview';
        } else {
            previewPanel.classList.remove('cbia-preview-expanded');
            document.body.classList.remove('cbia-preview-lock');
            if (previewBtnExpand) previewBtnExpand.title = 'Expandir preview';
        }
    }
    function clearPreviewOutput(){
        clearProgressiveQueue();
        previewToken = '';
        previewOriginalHtml = '';
        if (previewTokenField) previewTokenField.value = '';
        if (previewHtml) previewHtml.innerHTML = '';
        if (createDraftBtn) createDraftBtn.disabled = true;
        if (createPublishBtn) createPublishBtn.disabled = true;
        if (createScheduleBtn) createScheduleBtn.disabled = true;
        setWordCount(0);
        renderSeoMeta('', [], '', '');
        setFeaturedState('placeholder', 'Sin contenido generado.', '');
        setPreviewStatus('Preview limpiado.', false);
        if (previewEditPanel) previewEditPanel.style.display = 'none';
        setPreviewEditMode(false);
    }
    function setPhase(activeKey, hasError){
        const map = {
            texto: phaseTexto,
            img: phaseImg,
            ready: phaseReady
        };
        Object.keys(map).forEach(function(key){
            const node = map[key];
            if (!node) return;
            const isActive = key === activeKey;
            node.style.background = isActive ? (hasError ? '#fee2e2' : '#e0f2fe') : '#f6f7f7';
            node.style.borderColor = isActive ? (hasError ? '#fca5a5' : '#7dd3fc') : '#dcdcde';
            node.style.color = isActive ? (hasError ? '#991b1b' : '#0c4a6e') : '#50575e';
            node.style.fontWeight = isActive ? '600' : '400';
        });
    }

    function renderPreviewResult(data, options){
        if (!data) return;
        const opts = options || {};
        const skipImmediateHtml = !!opts.skipImmediateHtml;
        if (!skipImmediateHtml) {
            clearProgressiveQueue();
            if (previewHtml) previewHtml.innerHTML = processPreviewHtml(data.preview_html || '');
        }
        setWordCount(calcWordCountFromHtml(processPreviewHtml(data.preview_html || '')));
        if (previewEditPanel) previewEditPanel.style.display = '';
        if (previewEditTitle) previewEditTitle.value = data.title || '';
        previewOriginalHtml = data.preview_html || '';
        setPreviewEditMode(false);
        previewToken = data.preview_token || '';
        if (previewTokenField) previewTokenField.value = previewToken || '';
        if (createDraftBtn) createDraftBtn.disabled = !previewToken;
        if (createPublishBtn) createPublishBtn.disabled = !previewToken;
        if (createScheduleBtn) createScheduleBtn.disabled = !previewToken;
        renderSeoMeta(data.excerpt || '', data.tags || [], data.focus_keyphrase || '', data.meta_description || '');
        if (Array.isArray(data.images) && data.images.length) {
            const firstWithUrl = data.images.find(row => row && row.url);
            if (firstWithUrl && firstWithUrl.url) {
                setFeaturedState('done', 'Imagen destacada lista.', firstWithUrl.url);
            }
        }
    }
    if (previewEditToggleBtn) {
        previewEditToggleBtn.addEventListener('click', function(){
            if (!previewHtml) return;
            previewOriginalHtml = previewHtml.innerHTML || '';
            setPreviewEditMode(true);
            previewHtml.focus();
        });
    }
    if (previewEditSaveBtn) {
        previewEditSaveBtn.addEventListener('click', function(){
            if (!previewHtml) return;
            previewOriginalHtml = previewHtml.innerHTML || '';
            setPreviewEditMode(false);
            setPreviewStatus('Cambios del preview guardados.', false);
        });
    }
    if (previewEditCancelBtn) {
        previewEditCancelBtn.addEventListener('click', function(){
            if (!previewHtml) return;
            previewHtml.innerHTML = previewOriginalHtml || '';
            setPreviewEditMode(false);
            setPreviewStatus('Edicion cancelada.', false);
        });
    }
    if (previewBtnCopy) {
        previewBtnCopy.addEventListener('click', copyPreviewText);
    }
    if (previewBtnExpand) {
        previewBtnExpand.addEventListener('click', togglePreviewExpand);
    }
    if (previewBtnClear) {
        previewBtnClear.addEventListener('click', clearPreviewOutput);
    }
    if (previewBtnEdit) {
        previewBtnEdit.addEventListener('click', function(){
            if (previewEditPanel) previewEditPanel.style.display = '';
            if (!previewHtml) return;
            previewOriginalHtml = previewHtml.innerHTML || '';
            setPreviewEditMode(true);
            previewHtml.focus();
        });
    }
    function openPreviewPanel(){
        if (!previewPanel) return;
        previewPanel.dataset.open = 'true';
        previewPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (previewOpenBtn) previewOpenBtn.textContent = 'Ocultar previsualizacion';
    }
    function closePreviewPanel(){
        if (!previewPanel) return;
        previewPanel.dataset.open = 'false';
        previewPanel.classList.remove('cbia-preview-expanded');
        previewExpanded = false;
        document.body.classList.remove('cbia-preview-lock');
        if (previewOpenBtn) previewOpenBtn.textContent = 'Generacion con previsualizacion';
    }
    function ensurePreviewVisible(){
        openPreviewPanel();
    }
    document.addEventListener('keydown', function(evt){
        if (evt.key !== 'Escape') return;
        if (previewExpanded) {
            togglePreviewExpand();
            return;
        }
    });

    function runPreviewClassic(titleVal, modeOverride){
        setPreviewModeBadge('classic');
        const fd = new FormData();
        fd.append('action', 'cbia_preview_article');
        fd.append('_ajax_nonce', <?php echo wp_json_encode($ajax_nonce); ?>);
        fd.append('title', titleVal);
        fd.append('preview_mode', modeOverride || (previewMode ? previewMode.value : 'fast'));
        fd.append('post_language', postLanguage ? postLanguage.value : '');
        fd.append('images_limit', imagesLimit ? imagesLimit.value : '3');
        const modeInput = promptModeChecked();
        fd.append('blog_prompt_mode', modeInput ? modeInput.value : 'recommended');
        fd.append('blog_prompt_editable', promptEditable ? promptEditable.value : '');
        fd.append('legacy_full_prompt', legacyPrompt ? legacyPrompt.value : '');

        return fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: fd })
        .then(r => r.text())
        .then(text => {
            let data = null;
            try { data = JSON.parse(text); } catch(e) { data = null; }
            if (!data || !data.success || !data.data) {
                const msg = data && data.data && data.data.message ? data.data.message : 'No se pudo generar el preview.';
                const selectedMode = modeOverride || (previewMode ? previewMode.value : 'fast');
                if (selectedMode === 'full') {
                    setPreviewStatus('Fallo en modo completo. Reintentando en preview rapido...', false);
                    return runPreviewClassic(titleVal, 'fast');
                }
                setPreviewStatus(msg, true);
                throw new Error(msg);
            }
            setPhase('texto', false);
            enqueueClassicSimulation(data.data.preview_html || '');
            renderPreviewResult(data.data, { skipImmediateHtml: true });
            setPreviewStatus('Preview generado correctamente.', false);
            setPhase('ready', false);
        });
    }

    function runPreviewStream(titleVal){
        if (typeof EventSource === 'undefined') {
            return runPreviewClassic(titleVal);
        }
        setPreviewModeBadge('stream');
        const params = new URLSearchParams();
        params.append('action', 'cbia_preview_article_stream');
        params.append('_ajax_nonce', <?php echo wp_json_encode($ajax_nonce); ?>);
        params.append('title', titleVal);
        params.append('preview_mode', previewMode ? previewMode.value : 'fast');
        params.append('post_language', postLanguage ? postLanguage.value : '');
        params.append('images_limit', imagesLimit ? imagesLimit.value : '3');
        const modeInput = promptModeChecked();
        params.append('blog_prompt_mode', modeInput ? modeInput.value : 'recommended');
        const streamUrl = ajaxurl + '?' + params.toString();

        return new Promise((resolve, reject) => {
            let completed = false;
            let doneReceived = false;
            let fatalReceived = false;
            let hasAnyContent = false;
            let hadFeaturedEvent = false;
            let lastEventName = '';
            let lastEventAt = Date.now();
            const WATCHDOG_MS = 12000;
            const source = new EventSource(streamUrl);

            function readEventData(evt){
                if (!evt || !evt.data) return {};
                try { return JSON.parse(evt.data); } catch(e) { return {}; }
            }
            function markEvent(name){
                lastEventName = name || lastEventName;
                lastEventAt = Date.now();
            }
            const watchdog = setInterval(function(){
                if (completed || doneReceived || fatalReceived) {
                    clearInterval(watchdog);
                    return;
                }
                if (Date.now() - lastEventAt > WATCHDOG_MS) {
                    clearInterval(watchdog);
                    try { source.close(); } catch(e) {}
                    console.log('[CBIA_PREVIEW_TMP] watchdog timeout, last_event=' + lastEventName);
                    reject(new Error('Timeout de streaming.'));
                }
            }, 1000);

            source.addEventListener('preview_start', function(evt){
                markEvent('preview_start');
                const data = readEventData(evt);
                setPreviewStatus('Iniciando preview de: ' + (data.title || ''), false);
                setWordCount(0);
                renderInitialImagePlaceholders();
            });
            source.addEventListener('text_progress', function(evt){
                markEvent('text_progress');
                const data = readEventData(evt);
                if ((data.html || '').trim() !== '') hasAnyContent = true;
                progressiveMode = 'stream';
                setPreviewModeBadge('stream');
                enqueueProgressiveHtml(data.html || '', data.word_count || 0);
            });
            source.addEventListener('word_count', function(evt){
                markEvent('word_count');
                const data = readEventData(evt);
                setWordCount(data.count || 0);
            });
            source.addEventListener('featured_image_status', function(evt){
                markEvent('featured_image_status');
                const data = readEventData(evt);
                hadFeaturedEvent = true;
                setFeaturedState(data.status || 'pending', data.message || '', data.url || '');
            });
            source.addEventListener('seo_payload', function(evt){
                markEvent('seo_payload');
                const data = readEventData(evt);
                renderSeoMeta(
                    data.excerpt || '',
                    data.tags || [],
                    data.focus_keyphrase || '',
                    data.meta_description || ''
                );
            });
            source.addEventListener('cbia_content', function(evt){
                markEvent('cbia_content');
                const data = readEventData(evt);
                if ((data.html || '').trim() !== '') hasAnyContent = true;
                progressiveMode = 'stream';
                setPreviewModeBadge('stream');
                enqueueProgressiveHtml(data.html || '', data.word_count || 0);
            });
            source.addEventListener('preview_done', function(evt){
                markEvent('preview_done');
                const data = readEventData(evt);
                completed = true;
                doneReceived = true;
                clearInterval(watchdog);
                source.close();
                if (!data || !data.result) {
                    reject(new Error('Respuesta incompleta de streaming.'));
                    return;
                }
                renderPreviewResult(data.result);
                setPreviewStatus('Preview generado correctamente.', false);
                console.log('[CBIA_PREVIEW_TMP] stream closed ok, last_event=' + lastEventName);
                resolve();
            });
            source.addEventListener('cbia_done', function(evt){
                markEvent('cbia_done');
                const data = readEventData(evt);
                completed = true;
                doneReceived = true;
                clearInterval(watchdog);
                source.close();
                if (!data || !data.result) {
                    reject(new Error('Respuesta incompleta de streaming.'));
                    return;
                }
                renderPreviewResult(data.result);
                setPreviewStatus('Preview generado correctamente.', false);
                console.log('[CBIA_PREVIEW_TMP] stream closed ok, last_event=' + lastEventName);
                resolve();
            });
            source.addEventListener('preview_error', function(evt){
                markEvent('preview_error');
                const data = readEventData(evt);
                completed = true;
                fatalReceived = true;
                clearInterval(watchdog);
                source.close();
                setPhase('ready', true);
                console.log('[CBIA_PREVIEW_TMP] stream error, last_event=' + lastEventName);
                reject(new Error(data.message || 'No se pudo generar el preview.'));
            });
            source.addEventListener('cbia_error', function(evt){
                markEvent('cbia_error');
                const data = readEventData(evt);
                completed = true;
                fatalReceived = true;
                clearInterval(watchdog);
                source.close();
                setPhase('ready', true);
                console.log('[CBIA_PREVIEW_TMP] stream error, last_event=' + lastEventName);
                reject(new Error(data.message || 'No se pudo generar el preview.'));
            });
            source.onerror = function(){
                if (completed || doneReceived || fatalReceived) {
                    source.close();
                    return;
                }
                setTimeout(function(){
                    if (completed || doneReceived || fatalReceived) {
                        source.close();
                        return;
                    }
                    source.close();
                    clearInterval(watchdog);
                    if (hasAnyContent || hadFeaturedEvent) {
                        resolve();
                        return;
                    }
                    console.log('[CBIA_PREVIEW_TMP] stream onerror, last_event=' + lastEventName);
                    reject(new Error('Fallo en streaming.'));
                }, 1100);
            };
        });
    }

    function startPreview(){
        const titleVal = previewTitle ? (previewTitle.value || '').trim() : '';
        if (!titleVal) {
            setPreviewStatus('Selecciona o escribe primero un titulo manual.', true);
            return;
        }

        setPreviewStatus('Generando preview...', false);
        if (previewRuntime) previewRuntime.style.display = '';
        setPhase('texto', false);
        ensurePreviewVisible();
        if (previewBtn) previewBtn.disabled = true;
        previewToken = '';
        if (previewTokenField) previewTokenField.value = '';
        if (createDraftBtn) createDraftBtn.disabled = true;
        if (createPublishBtn) createPublishBtn.disabled = true;
        if (createScheduleBtn) createScheduleBtn.disabled = true;
        renderSeoMeta('', [], '', '');
        setWordCount(0);
        setPreviewModeBadge('stream');
        setFeaturedState('placeholder', 'Pendiente de fase de imagen...', '');
        renderInitialImagePlaceholders();
        previewOriginalHtml = '';
        clearProgressiveQueue();
        setPreviewEditMode(false);
        if (previewEditPanel) previewEditPanel.style.display = 'none';
        runPreviewStream(titleVal)
        .catch((streamErr) => {
            console.log('[CBIA_PREVIEW_TMP] fallback enter: ' + (streamErr && streamErr.message ? streamErr.message : 'stream_fail'));
            setPreviewStatus('Streaming inestable, reintentando en modo clasico...', false);
            return runPreviewClassic(titleVal);
        })
        .catch((classicErr) => {
            const msg = (classicErr && classicErr.message) ? classicErr.message : 'Error al generar preview.';
            setPreviewStatus(msg, true);
            setPhase('ready', true);
        })
        .finally(() => { if (previewBtn) previewBtn.disabled = false; });
    }

    if (previewOpenBtn) {
        previewOpenBtn.addEventListener('click', function(){
            if (!previewPanel) return;
            const isOpen = previewPanel.dataset.open === 'true';
            if (!isOpen) {
                openPreviewPanel();
                return;
            }
            // Si ya esta abierto, usar el boton para generar
            startPreview();
        });
    }
    if (previewBtn) {
        previewBtn.addEventListener('click', startPreview);
    }
    if (previewMetaToggle && previewMetaBody && previewMetaArrow) {
        previewMetaToggle.addEventListener('click', function(){
            const isHidden = window.getComputedStyle(previewMetaBody).display === 'none';
            previewMetaBody.style.display = isHidden ? '' : 'none';
            previewMetaArrow.className = isHidden
                ? 'dashicons dashicons-arrow-down-alt2'
                : 'dashicons dashicons-arrow-right-alt2';
        });
        previewMetaBody.style.display = 'none';
        previewMetaArrow.className = 'dashicons dashicons-arrow-right-alt2';
    }
    function setCreateButtonsDisabled(disabled){
        if (createDraftBtn) createDraftBtn.disabled = disabled;
        if (createPublishBtn) createPublishBtn.disabled = disabled;
        if (createScheduleBtn) createScheduleBtn.disabled = disabled;
    }
    function createPostFromPreview(status){
        if (!previewToken) {
            setPreviewStatus('Primero genera una preview valida.', true);
            return;
        }
        if (status === 'future' && (!previewPostDate || !previewPostDate.value)) {
            setPreviewStatus('Indica fecha/hora para programar.', true);
            return;
        }
        setCreateButtonsDisabled(true);
        setPreviewStatus('Creando post desde preview...', false);
        setPhase('ready', false);
        const fd = new FormData();
        fd.append('action', 'cbia_create_post_from_preview');
        fd.append('_ajax_nonce', <?php echo wp_json_encode($ajax_nonce); ?>);
        fd.append('preview_token', previewToken);
        fd.append('edited_title', previewEditTitle ? previewEditTitle.value : '');
        fd.append('edited_html', previewHtml ? previewHtml.innerHTML : '');
        fd.append('post_status', status || 'publish');
        fd.append('post_date_local', previewPostDate ? previewPostDate.value : '');

        fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: fd })
        .then(r => r.text())
        .then(text => {
            let data = null;
            try { data = JSON.parse(text); } catch(e) { data = null; }
            if (!data || !data.success || !data.data) {
                const msg = data && data.data && data.data.message ? data.data.message : 'No se pudo crear el post desde preview.';
                setPreviewStatus(msg, true);
                setCreateButtonsDisabled(false);
                return;
            }
            const editUrl = data.data.edit_url || '';
            if (editUrl) {
                setPreviewStatus('Post creado correctamente. Abriendo edicion...', false);
                window.location.href = editUrl;
                return;
            }
            setPreviewStatus(data.data.message || 'Post creado correctamente.', false);
            setCreateButtonsDisabled(false);
        })
        .catch(() => {
            setPreviewStatus('Error de red al crear post desde preview.', true);
            setCreateButtonsDisabled(false);
        });
    }
    if (createDraftBtn) {
        createDraftBtn.addEventListener('click', function(){
            createPostFromPreview('draft');
        });
    }
    if (createPublishBtn) {
        createPublishBtn.addEventListener('click', function(){
            createPostFromPreview('publish');
        });
    }
    if (createScheduleBtn) {
        createScheduleBtn.addEventListener('click', function(){
            createPostFromPreview('future');
        });
    }

    // CAMBIO: panel de prompt recomendado/legacy (colapsado por defecto).
    const modeInputs = document.querySelectorAll('input[name="blog_prompt_mode"]');
    const advancedToggle = document.getElementById('cbia_toggle_advanced_prompt');
    const advancedWrap = document.getElementById('cbia_advanced_prompt_wrap');
    const editToggle = document.getElementById('cbia_toggle_prompt_edit');
    const editWrap = document.getElementById('cbia_prompt_edit_wrap');
    const editRecommended = document.getElementById('cbia_prompt_edit_recommended');
    const editLegacy = document.getElementById('cbia_prompt_edit_legacy');
    const restoreBtn = document.getElementById('cbia_btn_restore_prompt');
    const editableTa = document.getElementById('cbia_blog_prompt_editable');
    const editableDefault = document.getElementById('cbia_blog_prompt_default');

    function getPromptMode(){
        const selected = document.querySelector('input[name="blog_prompt_mode"]:checked');
        return selected ? selected.value : 'recommended';
    }

    function refreshPromptEditor(){
        const opened = !!(editToggle && editToggle.checked);
        const mode = getPromptMode();
        const advancedOn = !!(advancedToggle && advancedToggle.checked);
        if (advancedWrap) advancedWrap.style.display = advancedOn ? '' : 'none';
        if (editWrap) editWrap.style.display = opened ? '' : 'none';
        if (editRecommended) editRecommended.style.display = opened && mode === 'recommended' ? '' : 'none';
        if (editLegacy) editLegacy.style.display = opened && mode === 'legacy' ? '' : 'none';
    }

    if (editToggle) editToggle.addEventListener('change', refreshPromptEditor);
    if (advancedToggle) {
        advancedToggle.addEventListener('change', function(){
            const legacyRadio = document.querySelector('input[name="blog_prompt_mode"][value="legacy"]');
            const recRadio = document.querySelector('input[name="blog_prompt_mode"][value="recommended"]');
            if (!advancedToggle.checked && recRadio) recRadio.checked = true;
            if (advancedToggle.checked && legacyRadio) legacyRadio.checked = true;
            refreshPromptEditor();
        });
    }
    modeInputs.forEach(function(r){ r.addEventListener('change', refreshPromptEditor); });

    if (restoreBtn && editableTa && editableDefault) {
        restoreBtn.addEventListener('click', function(){
            editableTa.value = editableDefault.value || '';
        });
    }

    refreshPromptEditor();
})();
</script>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>


