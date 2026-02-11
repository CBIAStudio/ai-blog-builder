<?php
if (!defined('ABSPATH')) exit;
if (!function_exists('cbia_render_view_diagnostics')) {
    function cbia_render_view_diagnostics() {
        if (!current_user_can('manage_options')) return;

        $settings = get_option('cbia_settings', array());
$api_key = (string)($settings['openai_api_key'] ?? '');
$api_masked = $api_key !== '' ? (substr($api_key, 0, 4) . 'â€¦' . substr($api_key, -4)) : '';

$info = array(
    'Plugin versiÃ³n' => defined('CBIA_VERSION') ? CBIA_VERSION : 'n/d',
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
    'OpenAI API Key' => $api_key !== '' ? ('SÃ­ (' . $api_masked . ')') : 'No',
    'Plugin dir escribible' => wp_is_writable(CBIA_PLUGIN_DIR) ? 'SÃ­' : 'No',
    'WP content escribible' => defined('WP_CONTENT_DIR') && wp_is_writable(WP_CONTENT_DIR) ? 'SÃ­' : 'No',
);

$log = (string)get_option(CBIA_OPTION_LOG, '');
$log_lines = $log ? array_slice(explode("\n", $log), -20) : array();
?>

<div class="wrap" style="padding-left:0;">
    <h2>DiagnÃ³stico</h2>

    <p class="description">
        Resumen rÃ¡pido del entorno y del estado del plugin. Ãštil para soporte y depuraciÃ³n.
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

    <h3 style="margin-top:24px;">Ãšltimas lÃ­neas de log</h3>
    <textarea rows="10" cols="120" readonly style="background:#f9f9f9;width:100%;"><?php echo esc_textarea(implode("\n", $log_lines)); ?></textarea>
</div>
<?php
    }
}

cbia_render_view_diagnostics();

