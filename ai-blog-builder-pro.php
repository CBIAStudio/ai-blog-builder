<?php
/**
 * Plugin Name: AI Blog Builder Pro
 * Description: Version Pro de AI Blog Builder (requiere la version gratuita activa).
 * Version: 3.0.4
 *
 * Author: CBIA Studio
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: ai-blog-builder
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

if (!defined('CBIA_PRO_VERSION')) define('CBIA_PRO_VERSION', '3.0.4');
if (!defined('CBIA_PRO_PLUGIN_FILE')) define('CBIA_PRO_PLUGIN_FILE', __FILE__);
if (!defined('CBIA_PRO_PLUGIN_DIR')) define('CBIA_PRO_PLUGIN_DIR', plugin_dir_path(__FILE__));
if (!defined('CBIA_PRO_PLUGIN_URL')) define('CBIA_PRO_PLUGIN_URL', plugin_dir_url(__FILE__));
if (!defined('CBIA_PRO_INCLUDES_DIR')) define('CBIA_PRO_INCLUDES_DIR', CBIA_PRO_PLUGIN_DIR . 'includes/');

// Compatibilidad con constantes base usadas por el core actual
if (!defined('CBIA_VERSION')) define('CBIA_VERSION', CBIA_PRO_VERSION);
if (!defined('CBIA_PLUGIN_FILE')) define('CBIA_PLUGIN_FILE', CBIA_PRO_PLUGIN_FILE);
if (!defined('CBIA_PLUGIN_DIR')) define('CBIA_PLUGIN_DIR', CBIA_PRO_PLUGIN_DIR);
if (!defined('CBIA_PLUGIN_URL')) define('CBIA_PLUGIN_URL', CBIA_PRO_PLUGIN_URL);
if (!defined('CBIA_INCLUDES_DIR')) define('CBIA_INCLUDES_DIR', CBIA_PRO_INCLUDES_DIR);
if (!defined('CBIA_OPTION_SETTINGS')) define('CBIA_OPTION_SETTINGS', 'cbia_settings');
if (!defined('CBIA_OPTION_LOG')) define('CBIA_OPTION_LOG', 'cbia_activity_log');
if (!defined('CBIA_OPTION_LOG_COUNTER')) define('CBIA_OPTION_LOG_COUNTER', 'cbia_log_counter');
if (!defined('CBIA_OPTION_STOP')) define('CBIA_OPTION_STOP', 'cbia_stop_generation');
if (!defined('CBIA_OPTION_CHECKPOINT')) define('CBIA_OPTION_CHECKPOINT', 'cbia_checkpoint');

// Dependencia: requiere AI Blog Builder (FREE)
if (!defined('ABB_FREE_PLUGIN')) define('ABB_FREE_PLUGIN', 'ai-blog-builder/ai-blog-builder.php');

// Cargar traducciones
// Nota: evitamos load_plugin_textdomain() para cumplir con las recomendaciones actuales.

if (!function_exists('cbia_pro_is_free_active')) {
	function cbia_pro_is_free_active(): bool {
		if (!function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active(ABB_FREE_PLUGIN);
	}
}
register_activation_hook(__FILE__, function () {
	if (!cbia_pro_is_free_active()) {
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die(
			'AI Blog Builder Pro requiere que AI Blog Builder (FREE) esté activo. Instálalo y actívalo primero.',
			'Plugin requerido no activo',
			array('back_link' => true)
		);
	}
});

if (!cbia_pro_is_free_active()) {
	add_action('admin_notices', function () {
		if (!current_user_can('activate_plugins')) return;
		echo '<div class="notice notice-error"><p>';
		echo wp_kses_post('<strong>AI Blog Builder Pro:</strong> requiere que <em>AI Blog Builder (FREE)</em> esté activo.');
		echo '</p></div>';
	});
	return;
}

// Bootstrap nueva estructura (v3.0)
$cbia_pro_bootstrap = CBIA_PRO_INCLUDES_DIR . 'core/bootstrap.php';
if (file_exists($cbia_pro_bootstrap)) {
	require_once $cbia_pro_bootstrap;
}

// Registrar loader nuevo
add_action('plugins_loaded', function () {
	if (class_exists('CBIA_Loader') && function_exists('cbia_container')) {
		$container = cbia_container();
		$router = $container ? $container->get('admin_router') : null;
		$scheduler = $container ? $container->get('scheduler') : null;
		$loader = new CBIA_Loader($router, $scheduler);
		$loader->register();
	}
});

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
		if (function_exists('cbia_fix_mojibake')) {
			$message = (string) cbia_fix_mojibake($message);
		}
		// Mensajes dinamicos: evitar traduccion directa para cumplir reglas i18n.
		$level = strtoupper(trim($level ?: 'INFO'));
		$line = '[' . cbia_now_mysql() . '][' . $level . '] ' . $message;
		$log = (string) get_option(CBIA_OPTION_LOG, '');
		$log = $log ? ($log . "\n" . $line) : $line;

		// Mantener el log con un tamaÃ±o razonable (Ãºltimos ~2000 lÃ­neas)
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

if (!function_exists('cbia_get_log')) {
	/**
	 * Lee log general
	 */
	function cbia_get_log(): array {
		$log = (string) get_option(CBIA_OPTION_LOG, '');
		if (function_exists('cbia_fix_mojibake')) {
			$log = cbia_fix_mojibake($log);
		}
		$counter = (int) get_option(CBIA_OPTION_LOG_COUNTER, 0);
		return ['log' => $log, 'counter' => $counter];
	}
}

if (!function_exists('cbia_clear_log')) {
	/**
	 * Borra log general
	 */
	function cbia_clear_log(): void {
		delete_option(CBIA_OPTION_LOG);
		delete_option(CBIA_OPTION_LOG_COUNTER);
		wp_cache_delete(CBIA_OPTION_LOG, 'options');
		wp_cache_delete(CBIA_OPTION_LOG_COUNTER, 'options');
	}
}

if (!function_exists('cbia_get_default_settings')) {
	/**
	 * Defaults globales del plugin
	 */
	function cbia_get_default_settings(): array {
		return [
			// OpenAI
			'openai_api_key'        => '',
			'openai_consent'        => 0,
			'openai_model'          => 'gpt-4.1-mini',
			'openai_temperature'    => 0.7,
			// CAMBIO: claves por proveedor
			'google_api_key'        => '',
			'deepseek_api_key'      => '',
			// CAMBIO: proveedor/modelo texto/imagen
			'text_provider'         => 'openai',
			'text_model'            => '',
			'image_provider'        => 'openai',
			'image_model'           => 'gpt-image-1-mini',
			// CAMBIO: Google Imagen (Vertex AI)
			'google_project_id'     => '',
			'google_location'       => '',
			'google_service_account_json' => '',

			// Longitud / imÃ¡genes
			'post_length_variant'   => 'medium',
			'images_limit'          => 3,
			// CAMBIO: prompt recomendado/legado (compatibilidad)
			'blog_prompt_mode'      => 'recommended',
			'blog_prompt_editable'  => '',
			'legacy_full_prompt'    => '',
			'prompt_single_all'     => "Escribe un artÃ­culo de blog en HTML (sin <h1>) sobre: {title}\nIncluye marcadores de imagen del tipo [IMAGEN: descripciÃ³n].",
			'prompt_img_intro'      => '',
			'prompt_img_body'       => '',
			'prompt_img_conclusion' => '',
			'prompt_img_faq'        => '',
			'post_language'         => 'espaÃ±ol',
			'responses_max_output_tokens' => 6000,
			'image_request_delay'   => 2,

			// CategorÃ­as/Tags
			'default_category'      => 'Noticias',
			'keywords_to_categories'=> "", // lÃ­neas: "Categoria: kw1, kw2"
			'default_tags'          => "", // tags permitidas separadas por comas

			// Blog scheduling / cron fill
			'enable_cron_fill'      => 0,

			// Estilos imagenes contenido (no destacada)
			'content_images_banner_enabled' => 1,
			'content_images_banner_css' =>
				"img.cbia-banner {\n" .
				"  width: 100%;\n" .
				"  height: 250px !important;\n" .
				"  object-fit: cover !important;\n" .
				"  object-position: 50% 60% !important;\n" .
				"  display: block !important;\n" .
				"  margin: 15px 0 !important;\n" .
				"  transition: transform 0.3s ease !important;\n" .
				"}",
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
 * ActivaciÃ³n: asegurar options base
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
 * Cargar mÃ³dulos core (sin legacy)
 */
if (!function_exists('cbia_pro_load_modules')) {
	function cbia_pro_load_modules(): void {
		$modules = [
			CBIA_INCLUDES_DIR . 'engine/engine.php',
		];
		foreach ($modules as $module_file) {
			if (file_exists($module_file)) {
				require_once $module_file;
			} else {
				// No romper el admin: solo log
				cbia_log('Modulo no encontrado: ' . basename($module_file), 'ERROR');
			}
		}
	}
}
cbia_pro_load_modules();

// Registrar hooks core (notices, AJAX, assets)
if (function_exists('cbia_register_core_hooks')) {
	cbia_register_core_hooks();
}

/**
 * Admin: menÃº + tabs
 */
add_action('admin_menu', function () {
	if (class_exists('CBIA_Admin_Router')) {
		// El router nuevo registra su propio menÃº.
		return;
	}
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

if (!function_exists('cbia_get_admin_tabs')) {
	function cbia_get_admin_tabs(): array {
		return [
			'config'   => ['label' => 'ConfiguraciÃ³n', 'render' => 'cbia_render_tab_config'],
			'blog'     => ['label' => 'Blog',          'render' => 'cbia_render_tab_blog'],
			'oldposts' => ['label' => 'Actualizar antiguos', 'render' => 'cbia_render_tab_oldposts'],
			'costes'   => ['label' => 'Costes',        'render' => 'cbia_render_tab_costes'],
			'yoast'    => ['label' => 'Yoast',         'render' => 'cbia_render_tab_yoast'],
		];
	}
}

if (!function_exists('cbia_get_current_tab')) {
	function cbia_get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'config';
		$tabs = cbia_get_admin_tabs();
		return isset($tabs[$tab]) ? $tab : 'config';
	}
}

if (!function_exists('cbia_render_admin_page')) {
	function cbia_render_admin_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die('No tienes permisos para ver esta pÃ¡gina.');
		}

		$tabs = cbia_get_admin_tabs();
		$current = cbia_get_current_tab();
		$current_tab = $tabs[$current] ?? null;

		echo '<div class="wrap">';
		echo '<h1>Creador Blog IA <small style="font-weight:normal;opacity:.7;">v' . esc_html(CBIA_VERSION) . '</small></h1>';
		echo '<h2 class="nav-tab-wrapper">';

		foreach ($tabs as $tab_key => $tab_data) {
			$label = $tab_data['label'] ?? $tab_key;
			$url = admin_url('admin.php?page=cbia&tab=' . $tab_key);
			$active = $tab_key === $current ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
		}

		echo '</h2>';

		if ($current_tab && isset($current_tab['render']) && is_callable($current_tab['render'])) {
			call_user_func($current_tab['render']);
		} else {
			echo '<p>No se pudo cargar esta pestaÃ±a.</p>';
		}

		echo '</div>';
	}
}





