<?php
if (!defined('ABSPATH')) exit;
if (!function_exists('cbia_render_view_diagnostics')) {
    function cbia_render_view_diagnostics() {
        if (!current_user_can('manage_options')) return;

        $settings = get_option('cbia_settings', array());
$api_key = (string)($settings['openai_api_key'] ?? '');
$api_masked = $api_key !== '' ? (substr($api_key, 0, 4) . '…' . substr($api_key, -4)) : '';

$info = array(
    'Plugin versión' => defined('CBIA_VERSION') ? CBIA_VERSION : 'n/d',
    'WordPress' => get_bloginfo('version'),
    'PHP' => PHP_VERSION,
    'Memoria (PHP)' => (string)ini_get('memory_limit'),
    'Max execution time' => (string)ini_get('max_execution_time'),
    'Upload max' => (string)ini_get('upload_max_filesize'),
    'Post max' => (string)ini_get('post_max_size'),
    'WP_DEBUG' => (defined('WP_DEBUG') && WP_DEBUG) ? 'true' : 'false',
    'WP_DEBUG_LOG' => (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) ? 'true' : 'false',
    'DISABLE_WP_CRON' => (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'true' : 'false',
    'Timezone' => (string)wp_timezone_string(),
    'OpenAI API Key' => $api_key !== '' ? ('Sí (' . $api_masked . ')') : 'No',
    'Plugin dir escribible' => wp_is_writable(CBIA_PLUGIN_DIR) ? 'Sí' : 'No',
    'WP content escribible' => defined('WP_CONTENT_DIR') && wp_is_writable(WP_CONTENT_DIR) ? 'Sí' : 'No',
);

$log = (string)get_option(CBIA_OPTION_LOG, '');
$log_lines = $log ? array_slice(explode("\n", $log), -20) : array();
?>

<div class="wrap" style="padding-left:0;">
    <h2>Diagnóstico</h2>

    <p class="description">
        Resumen rápido del entorno y del estado del plugin. Útil para soporte y depuración.
    </p>

    <table class="widefat striped" style="max-width:980px;">
        <tbody>
        <?php foreach ($info as $label => $value): ?>
            <tr>
                <td style="width:280px;"><strong><?php echo esc_html($label); ?></strong></td>
                <td><code><?php echo esc_html((string)$value); ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3 style="margin-top:24px;">Últimas líneas de log</h3>
    <textarea rows="10" cols="120" readonly style="background:#f9f9f9;width:100%;"><?php echo esc_textarea(implode("\n", $log_lines)); ?></textarea>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    function tryDecodeLatin1ToUtf8(str) {
        try { return decodeURIComponent(escape(str)); } catch (e) { return str; }
    }
    function fixMojibakeInTextNodes(root) {
        const doc = root.ownerDocument || document;
        const walker = doc.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const suspicious = /[\u00C3\u00C2\u00E2]/;
        let node;
        while ((node = walker.nextNode())) {
            const original = node.nodeValue;
            if (!original || !suspicious.test(original)) continue;
            let fixed = tryDecodeLatin1ToUtf8(original);
            if (fixed !== original && suspicious.test(fixed)) {
                fixed = tryDecodeLatin1ToUtf8(fixed);
            }
            if (fixed && fixed !== original) {
                node.nodeValue = fixed;
            }
        }
    }
    fixMojibakeInTextNodes(document.querySelector('.wrap'));
    const ta = document.querySelector('textarea[readonly]');
    if (ta && /[\u00C3\u00C2\u00E2]/.test(ta.value)) {
        let fixed = tryDecodeLatin1ToUtf8(ta.value);
        if (fixed !== ta.value && /[\u00C3\u00C2\u00E2]/.test(fixed)) {
            fixed = tryDecodeLatin1ToUtf8(fixed);
        }
        if (fixed && fixed !== ta.value) ta.value = fixed;
    }
});
</script>
<?php
    }
}

cbia_render_view_diagnostics();


