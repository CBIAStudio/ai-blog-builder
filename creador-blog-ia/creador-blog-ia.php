<?php
/**
 * Plugin Name: Creador Blog IA
 * Description: Genera entradas con IA (texto + marcadores de imágenes), programa con intervalos, asigna categorías/etiquetas, guarda tokens/usage y estima costes. Incluye actualización de posts antiguos y módulo Yoast.
 * Version: 9.0.0
 * 
 * Author: Angel
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

if (!defined('CBIA_VERSION')) define('CBIA_VERSION', '9.0.0');
if (!defined('CBIA_PLUGIN_FILE')) define('CBIA_PLUGIN_FILE', __FILE__);
if (!defined('CBIA_PLUGIN_DIR')) define('CBIA_PLUGIN_DIR', plugin_dir_path(__FILE__));
if (!defined('CBIA_PLUGIN_URL')) define('CBIA_PLUGIN_URL', plugin_dir_url(__FILE__));
if (!defined('CBIA_INCLUDES_DIR')) define('CBIA_INCLUDES_DIR', CBIA_PLUGIN_DIR . 'includes/');
if (!defined('CBIA_OPTION_SETTINGS')) define('CBIA_OPTION_SETTINGS', 'cbia_settings');
if (!defined('CBIA_OPTION_LOG')) define('CBIA_OPTION_LOG', 'cbia_activity_log');
if (!defined('CBIA_OPTION_LOG_COUNTER')) define('CBIA_OPTION_LOG_COUNTER', 'cbia_log_counter');
if (!defined('CBIA_OPTION_STOP')) define('CBIA_OPTION_STOP', 'cbia_stop_generation');
if (!defined('CBIA_OPTION_CHECKPOINT')) define('CBIA_OPTION_CHECKPOINT', 'cbia_checkpoint');

/**
 * Helpers globales (evitar duplicados)
 */

if (!function_exists('cbia_now_mysql')) {
	function cbia_now_mysql(): string {
		return current_time('mysql'); // respeta TZ WP
	}
}

if (!function_exists('cbia_date_mysql_from_ts')) {
	function cbia_date_mysql_from_ts(int $ts): string {
		return gmdate('Y-m-d H:i:s', $ts + (get_option('gmt_offset') * HOUR_IN_SECONDS));
	}
}

if (!function_exists('cbia_log')) {
	/**
	 * Log general en option CBIA_OPTION_LOG (texto plano acumulado)
	 */
	function cbia_log(string $message, string $level = 'INFO'): void {
		$level = strtoupper(trim($level ?: 'INFO'));
		$line = '[' . cbia_now_mysql() . '][' . $level . '] ' . $message;
		$log = (string) get_option(CBIA_OPTION_LOG, '');
		$log = $log ? ($log . "\n" . $line) : $line;

		// Mantener el log con un tamaño razonable (últimos ~2000 líneas)
		$lines = explode("\n", $log);
		if (count($lines) > 2000) {
			$lines = array_slice($lines, -2000);
			$log = implode("\n", $lines);
		}

		update_option(CBIA_OPTION_LOG, $log, false);

		$cnt = (int) get_option(CBIA_OPTION_LOG_COUNTER, 0);
		update_option(CBIA_OPTION_LOG_COUNTER, $cnt + 1, false);

		wp_cache_delete(CBIA_OPTION_LOG, 'options');
		wp_cache_delete(CBIA_OPTION_LOG_COUNTER, 'options');
	}
}

if (!function_exists('cbia_clear_log')) {
	function cbia_clear_log(): void {
		update_option(CBIA_OPTION_LOG, '', false);
		update_option(CBIA_OPTION_LOG_COUNTER, 0, false);
		wp_cache_delete(CBIA_OPTION_LOG, 'options');
		wp_cache_delete(CBIA_OPTION_LOG_COUNTER, 'options');
	}
}

if (!function_exists('cbia_get_log')) {
	function cbia_get_log(): array {
		$log = (string) get_option(CBIA_OPTION_LOG, '');
		$counter = (int) get_option(CBIA_OPTION_LOG_COUNTER, 0);
		return ['log' => $log, 'counter' => $counter];
	}
}

if (!function_exists('cbia_is_stop_requested')) {
	function cbia_is_stop_requested(): bool {
		return (bool) get_option(CBIA_OPTION_STOP, false);
	}
}

if (!function_exists('cbia_set_stop_flag')) {
	function cbia_set_stop_flag(bool $stop): void {
		update_option(CBIA_OPTION_STOP, $stop ? 1 : 0, false);
	}
}

if (!function_exists('cbia_get_default_settings')) {
	function cbia_get_default_settings(): array {
		return [
			// OpenAI
			'openai_api_key'        => '',
			'openai_model'          => 'gpt-5-mini',
			'openai_temperature'    => 0.7,
			'blocked_models'        => [],

			// Longitud / imágenes
			'post_length_variant'   => 'medium', // short|medium|long
			'images_limit'          => 2,

			// Prompts
			'prompt_single_all'     => "Escribe un artículo de blog en HTML (sin <h1>) sobre: {title}\nIncluye marcadores de imagen del tipo [IMAGEN: descripción].",
			'prompt_img_intro'      => '',
			'prompt_img_body'       => '',
			'prompt_img_conclusion' => '',
			'prompt_img_faq'        => '',
			'post_language'         => 'español',
			'faq_heading_custom'    => '',

			// Categorías/Tags
			'default_category'      => 'Noticias',
			'keywords_to_categories'=> "", // líneas: "Categoria: kw1, kw2"
			'default_tags'          => "", // tags permitidas separadas por comas

			// Blog scheduling / cron fill
			'enable_cron_fill'      => 0,
		];
	}
}

if (!function_exists('cbia_get_settings')) {
	/**
	 * Devuelve settings mergeando defaults + guardados (sin borrar campos de otros tabs)
	 */
	function cbia_get_settings(): array {
		$defaults = cbia_get_default_settings();
		$stored = get_option(CBIA_OPTION_SETTINGS, []);
		if (!is_array($stored)) $stored = [];
		return array_replace_recursive($defaults, $stored);
	}
}

if (!function_exists('cbia_update_settings_merge')) {
	/**
	 * Merge seguro (no destruye otros campos).
	 */
	function cbia_update_settings_merge(array $partial): array {
		$current = get_option(CBIA_OPTION_SETTINGS, []);
		if (!is_array($current)) $current = [];
		$merged = array_replace_recursive($current, $partial);
		update_option(CBIA_OPTION_SETTINGS, $merged, false);
		return $merged;
	}
}

/**
 * Activación: asegurar options base
 */
register_activation_hook(__FILE__, function () {
	if (get_option(CBIA_OPTION_SETTINGS, null) === null) {
		update_option(CBIA_OPTION_SETTINGS, cbia_get_default_settings(), false);
	}
	if (get_option(CBIA_OPTION_LOG, null) === null) {
		update_option(CBIA_OPTION_LOG, '', false);
	}
	if (get_option(CBIA_OPTION_LOG_COUNTER, null) === null) {
		update_option(CBIA_OPTION_LOG_COUNTER, 0, false);
	}
	if (get_option(CBIA_OPTION_STOP, null) === null) {
		update_option(CBIA_OPTION_STOP, 0, false);
	}
	if (get_option(CBIA_OPTION_CHECKPOINT, null) === null) {
		update_option(CBIA_OPTION_CHECKPOINT, [], false);
	}
});

/**
 * Cargar módulos (orden requerido)
 */
$cbia_modules = [
	CBIA_INCLUDES_DIR . 'cbia-config.php',
	CBIA_INCLUDES_DIR . 'cbia-engine.php',
	CBIA_INCLUDES_DIR . 'cbia-blog.php',
	CBIA_INCLUDES_DIR . 'cbia-oldposts.php',
	CBIA_INCLUDES_DIR . 'cbia-costes.php',
	CBIA_INCLUDES_DIR . 'cbia-yoast.php',
];

foreach ($cbia_modules as $f) {
	if (file_exists($f)) {
		require_once $f;
	} else {
		// No romper el admin: solo log
		cbia_log('No se encontró el módulo requerido: ' . basename($f), 'ERROR');
	}
}

/**
 * Admin: menú + tabs
 */
add_action('admin_menu', function () {
	add_menu_page(
		'Creador Blog IA',
		'Creador Blog IA',
		'manage_options',
		'cbia',
		'cbia_render_admin_page',
		'dashicons-edit-page',
		56
	);
});

/**
 * Aviso Yoast SEO (solo en la pantalla del plugin)
 */
add_action('admin_notices', function () {
	if (!is_admin() || !current_user_can('manage_options')) return;
	if (!function_exists('get_current_screen')) return;

	$screen = get_current_screen();
	if (!$screen || $screen->id !== 'toplevel_page_cbia') return;

	include_once ABSPATH . 'wp-admin/includes/plugin.php';

	$yoast_plugin = 'wordpress-seo/wp-seo.php';
	$yoast_path = WP_PLUGIN_DIR . '/wordpress-seo/wp-seo.php';
	$installed = file_exists($yoast_path);

	if (is_plugin_active($yoast_plugin)) {
		echo '<div class="notice notice-success is-dismissible"><p>Yoast SEO detectado y activo.</p></div>';
		return;
	}

	if ($installed) {
		if (current_user_can('activate_plugins')) {
			$activate_url = wp_nonce_url(
				self_admin_url('plugins.php?action=activate&plugin=' . rawurlencode($yoast_plugin)),
				'activate-plugin_' . $yoast_plugin
			);
			$msg = 'Yoast SEO est&aacute; instalado pero inactivo. <a href="' . esc_url($activate_url) . '">Activar ahora</a>.';
		} else {
			$msg = 'Yoast SEO est&aacute; instalado pero inactivo.';
		}
		echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses($msg, ['a' => ['href' => []]]) . '</p></div>';
		return;
	}

	if (current_user_can('install_plugins')) {
		$install_url = wp_nonce_url(
			self_admin_url('update.php?action=install-plugin&plugin=wordpress-seo'),
			'install-plugin_wordpress-seo'
		);
		$msg = 'Yoast SEO no est&aacute; instalado. <a href="' . esc_url($install_url) . '">Instalar Yoast SEO</a>.';
	} else {
		$msg = 'Yoast SEO no est&aacute; instalado.';
	}

	echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses($msg, ['a' => ['href' => []]]) . '</p></div>';
});

if (!function_exists('cbia_get_admin_tabs')) {
	function cbia_get_admin_tabs(): array {
		return [
			'config'   => ['label' => 'Configuración', 'render' => 'cbia_render_tab_config'],
			'blog'     => ['label' => 'Blog',          'render' => 'cbia_render_tab_blog'],
			'oldposts' => ['label' => 'Actualizar antiguos', 'render' => 'cbia_render_tab_oldposts'],
			'costes'   => ['label' => 'Costes',        'render' => 'cbia_render_tab_costes'],
			'yoast'    => ['label' => 'Yoast',         'render' => 'cbia_render_tab_yoast'],
		];
	}
}

if (!function_exists('cbia_get_current_tab')) {
	function cbia_get_current_tab(): string {
		$tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'config';
		$tabs = cbia_get_admin_tabs();
		return isset($tabs[$tab]) ? $tab : 'config';
	}
}

if (!function_exists('cbia_render_admin_page')) {
	function cbia_render_admin_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die('No tienes permisos para ver esta página.');
		}

		$tabs = cbia_get_admin_tabs();
		$current = cbia_get_current_tab();

		echo '<div class="wrap">';
		echo '<h1>Creador Blog IA <small style="font-weight:normal;opacity:.7;">v' . esc_html(CBIA_VERSION) . '</small></h1>';

		// Tabs
		echo '<h2 class="nav-tab-wrapper">';
		foreach ($tabs as $key => $t) {
			$url = admin_url('admin.php?page=cbia&tab=' . $key);
			$cls = 'nav-tab' . ($key === $current ? ' nav-tab-active' : '');
			echo '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($t['label']) . '</a>';
		}
		echo '</h2>';

		// Render tab
		$render = $tabs[$current]['render'];
		if (is_callable($render)) {
			call_user_func($render);
		} else {
			echo '<p>No se pudo cargar esta pestaña.</p>';
			cbia_log('Render no callable para tab: ' . $current, 'ERROR');
		}

		echo '</div>';
	}
}

/**
 * AJAX: leer log / limpiar log / stop flag
 */
add_action('admin_enqueue_scripts', function ($hook) {
	if ($hook !== 'toplevel_page_cbia') return;

	wp_enqueue_script('jquery');

	// Script inline mínimo (sin archivos extra)
	$ajax_url = admin_url('admin-ajax.php');
	$nonce = wp_create_nonce('cbia_ajax_nonce');

	$js = <<<JS
(function($){
  window.CBIA = window.CBIA || {};
  CBIA.ajaxUrl = "{$ajax_url}";
  CBIA.nonce = "{$nonce}";

  CBIA.fetchLog = function(targetSelector){
    return $.post(CBIA.ajaxUrl, {action:'cbia_get_log', _ajax_nonce: CBIA.nonce})
      .done(function(res){
        if(res && res.success && res.data){
          $(targetSelector).val(res.data.log || '');
        }
      });
  };

  CBIA.clearLog = function(targetSelector){
    return $.post(CBIA.ajaxUrl, {action:'cbia_clear_log', _ajax_nonce: CBIA.nonce})
      .done(function(res){
        if(res && res.success){
          $(targetSelector).val('');
        }
      });
  };

  CBIA.setStop = function(stop){
    return $.post(CBIA.ajaxUrl, {action:'cbia_set_stop', stop: stop ? 1 : 0, _ajax_nonce: CBIA.nonce});
  };
})(jQuery);
JS;

	wp_add_inline_script('jquery', $js, 'after');
});

if (!has_action('wp_ajax_cbia_get_log')) {
	add_action('wp_ajax_cbia_get_log', function () {
		check_ajax_referer('cbia_ajax_nonce');
		if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'No autorizado'], 403);

		nocache_headers();
		$payload = cbia_get_log();
		wp_send_json_success($payload);
	});
}

add_action('wp_ajax_cbia_clear_log', function () {
	check_ajax_referer('cbia_ajax_nonce');
	if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'No autorizado'], 403);

	cbia_clear_log();
	wp_send_json_success(['ok' => 1]);
});

add_action('wp_ajax_cbia_set_stop', function () {
	check_ajax_referer('cbia_ajax_nonce');
	if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'No autorizado'], 403);

	$stop = isset($_POST['stop']) ? (int) $_POST['stop'] : 0;
	cbia_set_stop_flag($stop === 1);
	cbia_log($stop === 1 ? 'Se activó STOP (detener generación).' : 'Se desactivó STOP (reanudar).', 'INFO');
	wp_send_json_success(['stop' => $stop === 1 ? 1 : 0]);
});
