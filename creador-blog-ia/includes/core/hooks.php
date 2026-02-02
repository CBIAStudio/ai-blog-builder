<?php
/**
 * Core hooks (admin notices, AJAX, assets).
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_register_core_hooks')) {
    function cbia_register_core_hooks() {
        // Admin notices: Yoast SEO status (only in plugin page)
        if (!has_action('admin_notices', 'cbia_admin_notice_yoast')) {
            add_action('admin_notices', 'cbia_admin_notice_yoast');
        }
        // Admin scripts (inline helpers)
        if (!has_action('admin_enqueue_scripts', 'cbia_admin_enqueue_inline')) {
            add_action('admin_enqueue_scripts', 'cbia_admin_enqueue_inline');
        }

        // AJAX handlers
        if (!has_action('wp_ajax_cbia_get_log', 'cbia_ajax_get_log')) {
            add_action('wp_ajax_cbia_get_log', 'cbia_ajax_get_log');
        }
        if (!has_action('wp_ajax_cbia_clear_log', 'cbia_ajax_clear_log')) {
            add_action('wp_ajax_cbia_clear_log', 'cbia_ajax_clear_log');
        }
        if (!has_action('wp_ajax_cbia_set_stop', 'cbia_ajax_set_stop')) {
            add_action('wp_ajax_cbia_set_stop', 'cbia_ajax_set_stop');
        }
        if (!has_action('wp_ajax_cbia_get_checkpoint_status', 'cbia_ajax_get_checkpoint_status')) {
            add_action('wp_ajax_cbia_get_checkpoint_status', 'cbia_ajax_get_checkpoint_status');
        }
        if (!has_action('wp_ajax_cbia_start_generation', 'cbia_ajax_start_generation')) {
            add_action('wp_ajax_cbia_start_generation', 'cbia_ajax_start_generation');
        }
        if (!has_action('wp_ajax_cbia_get_oldposts_log', 'cbia_ajax_get_oldposts_log')) {
            add_action('wp_ajax_cbia_get_oldposts_log', 'cbia_ajax_get_oldposts_log');
        }
        if (!has_action('wp_ajax_cbia_get_costes_log', 'cbia_ajax_get_costes_log')) {
            add_action('wp_ajax_cbia_get_costes_log', 'cbia_ajax_get_costes_log');
        }

        // Frontend styles for banner images
        if (!has_action('wp_head', 'cbia_output_banner_css')) {
            add_action('wp_head', 'cbia_output_banner_css', 20);
        }
    }
}

if (!function_exists('cbia_admin_notice_yoast')) {
    function cbia_admin_notice_yoast() {
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
                $msg = 'Yoast SEO está instalado pero inactivo. <a href="' . esc_url($activate_url) . '">Activar ahora</a>.';
            } else {
                $msg = 'Yoast SEO está instalado pero inactivo.';
            }
            echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses($msg, ['a' => ['href' => []]]) . '</p></div>';
            return;
        }

        if (current_user_can('install_plugins')) {
            $install_url = wp_nonce_url(
                self_admin_url('update.php?action=install-plugin&plugin=wordpress-seo'),
                'install-plugin_wordpress-seo'
            );
            $msg = 'Yoast SEO no está instalado. <a href="' . esc_url($install_url) . '">Instalar Yoast SEO</a>.';
        } else {
            $msg = 'Yoast SEO no está instalado.';
        }

        echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses($msg, ['a' => ['href' => []]]) . '</p></div>';
    }
}

if (!function_exists('cbia_admin_enqueue_inline')) {
    function cbia_admin_enqueue_inline($hook) {
        if ($hook !== 'toplevel_page_cbia') return;

        wp_enqueue_script('jquery');

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
    }
}

if (!function_exists('cbia_output_banner_css')) {
    function cbia_output_banner_css() {
        if (is_admin()) return;

        if (!function_exists('cbia_get_settings')) return;
        $settings = cbia_get_settings();
        if (empty($settings['content_images_banner_enabled'])) return;

        $css = trim((string)($settings['content_images_banner_css'] ?? ''));
        if ($css === '') return;

        echo "<style id='cbia-banner-css'>\n" . $css . "\n</style>";
    }
}

if (!function_exists('cbia_ajax_get_log')) {
    function cbia_ajax_get_log() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'No autorizado'], 403);

        nocache_headers();
        $service = null;
        if (function_exists('cbia_container')) {
            $container = cbia_container();
            if ($container) $service = $container->get('log_service');
        }
        if ($service && method_exists($service, 'get_log')) {
            wp_send_json_success($service->get_log('global'));
        }
        if (function_exists('cbia_get_log')) {
            $payload = cbia_get_log();
            wp_send_json_success($payload);
        }
        wp_send_json_success(['log' => '', 'counter' => 0]);
    }
}

if (!function_exists('cbia_ajax_clear_log')) {
    function cbia_ajax_clear_log() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'No autorizado'], 403);

        if (function_exists('cbia_clear_log')) {
            cbia_clear_log();
        }
        wp_send_json_success(['ok' => 1]);
    }
}

if (!function_exists('cbia_ajax_set_stop')) {
    function cbia_ajax_set_stop() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'No autorizado'], 403);

        $stop = isset($_POST['stop']) ? (int) $_POST['stop'] : 0;
        if (function_exists('cbia_set_stop_flag')) {
            cbia_set_stop_flag($stop === 1);
        }
        if (function_exists('cbia_log')) {
            cbia_log($stop === 1 ? 'Se activó STOP (detener generación).' : 'Se desactivó STOP (reanudar).', 'INFO');
        }
        wp_send_json_success(['stop' => $stop === 1 ? 1 : 0]);
    }
}

if (!function_exists('cbia_ajax_get_checkpoint_status')) {
    function cbia_ajax_get_checkpoint_status() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'No autorizado'], 403);

        nocache_headers();
        if (!function_exists('cbia_checkpoint_get')) {
            wp_send_json_success(['status' => 'inactivo', 'last' => '(sin registros)']);
        }

        $blog_service = null;
        if (function_exists('cbia_container')) {
            $container = cbia_container();
            if ($container) $blog_service = $container->get('blog_service');
        }

        if ($blog_service && method_exists($blog_service, 'get_checkpoint_status')) {
            $data = $blog_service->get_checkpoint_status();
            wp_send_json_success($data);
        }

        $cp = cbia_checkpoint_get();
        $status = (!empty($cp) && !empty($cp['running']))
            ? ('EN CURSO | idx ' . intval($cp['idx'] ?? 0) . ' de ' . count((array)($cp['queue'] ?? array())))
            : 'inactivo';
        $last_dt = function_exists('cbia_get_last_scheduled_at') ? (cbia_get_last_scheduled_at() ?: '(sin registros)') : '(sin registros)';
        wp_send_json_success(['status' => $status, 'last' => $last_dt]);
    }
}

if (!function_exists('cbia_ajax_start_generation')) {
    function cbia_ajax_start_generation() {
        $nonce = '';
        if (isset($_REQUEST['_ajax_nonce'])) $nonce = (string)$_REQUEST['_ajax_nonce'];
        if ($nonce === '' && isset($_REQUEST['_wpnonce'])) $nonce = (string)$_REQUEST['_wpnonce'];
        if ($nonce === '' && isset($_REQUEST['nonce'])) $nonce = (string)$_REQUEST['nonce'];
        if ($nonce === '' || !wp_verify_nonce($nonce, 'cbia_ajax_nonce')) {
            wp_send_json_error(['msg' => 'bad_nonce'], 403);
        }
        if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'No autorizado'], 403);

        if (!function_exists('cbia_set_stop_flag')) {
            wp_send_json_error(['msg' => 'cbia_set_stop_flag no disponible'], 500);
        }

        cbia_set_stop_flag(false);

        $blog_service = null;
        if (function_exists('cbia_container')) {
            $container = cbia_container();
            if ($container) $blog_service = $container->get('blog_service');
        }

        if (!$blog_service && !function_exists('cbia_run_generate_blogs')) {
            wp_send_json_error(['msg' => 'cbia_run_generate_blogs no disponible'], 500);
        }

        // Ejecuta 1 tanda inmediata para que haya log visible.
        $max_per_run = 1;
        if (function_exists('cbia_log_message')) {
            cbia_log_message('[INFO] START: Ejecutando primera tanda inmediata (para evitar “no hace nada”).');
        }
        $result = $blog_service
            ? $blog_service->run_generate_blogs($max_per_run)
            : cbia_run_generate_blogs($max_per_run);

        if (is_array($result) && empty($result['done'])) {
            if (function_exists('cbia_log_message')) {
                cbia_log_message('[INFO] START: Queda cola -> encolando evento background.');
            }
            if ($blog_service) {
                $blog_service->schedule_generation_event(6, true);
            } elseif (function_exists('cbia_schedule_generation_event')) {
                cbia_schedule_generation_event(6, true);
            }
        } else {
            if (function_exists('cbia_log_message')) {
                cbia_log_message('[INFO] START: No queda cola pendiente.');
            }
        }

        wp_send_json_success(['ok' => 1, 'result' => $result]);
    }
}

if (!function_exists('cbia_ajax_get_oldposts_log')) {
    function cbia_ajax_get_oldposts_log() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'No autorizado'], 403);

        nocache_headers();
        $service = null;
        if (function_exists('cbia_container')) {
            $container = cbia_container();
            if ($container) $service = $container->get('log_service');
        }
        if ($service && method_exists($service, 'get_log')) {
            wp_send_json_success($service->get_log('oldposts'));
        }
        if (function_exists('cbia_oldposts_get_log')) {
            wp_send_json_success(cbia_oldposts_get_log());
        }
        if (function_exists('cbia_get_log')) {
            wp_send_json_success(cbia_get_log());
        }
        wp_send_json_success(['log' => '', 'counter' => 0]);
    }
}

if (!function_exists('cbia_ajax_get_costes_log')) {
    function cbia_ajax_get_costes_log() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['msg' => 'No autorizado'], 403);

        nocache_headers();
        $service = null;
        if (function_exists('cbia_container')) {
            $container = cbia_container();
            if ($container) $service = $container->get('log_service');
        }
        if ($service && method_exists($service, 'get_log')) {
            wp_send_json_success($service->get_log('costes'));
        }
        if (function_exists('cbia_costes_log_get')) {
            wp_send_json_success(cbia_costes_log_get());
        }
        if (function_exists('cbia_get_log')) {
            wp_send_json_success(cbia_get_log());
        }
        wp_send_json_success(['log' => '', 'counter' => 0]);
    }
}
