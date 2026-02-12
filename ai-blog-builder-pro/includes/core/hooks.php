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
        // Add posts list button via admin JS (no inline styles)

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
        if (!has_action('wp_ajax_cbia_get_img_prompt', 'cbia_ajax_get_img_prompt')) {
            add_action('wp_ajax_cbia_get_img_prompt', 'cbia_ajax_get_img_prompt');
        }
        if (!has_action('wp_ajax_cbia_save_img_prompt_override', 'cbia_ajax_save_img_prompt_override')) {
            add_action('wp_ajax_cbia_save_img_prompt_override', 'cbia_ajax_save_img_prompt_override');
        }
        if (!has_action('wp_ajax_cbia_regen_image', 'cbia_ajax_regen_image')) {
            add_action('wp_ajax_cbia_regen_image', 'cbia_ajax_regen_image');
        }
        if (!has_action('wp_ajax_cbia_sync_models', 'cbia_ajax_sync_models')) {
            add_action('wp_ajax_cbia_sync_models', 'cbia_ajax_sync_models');
        }
        if (!has_action('wp_ajax_cbia_preview_article', 'cbia_ajax_preview_article')) {
            add_action('wp_ajax_cbia_preview_article', 'cbia_ajax_preview_article');
        }
        if (!has_action('wp_ajax_cbia_preview_article_stream', 'cbia_ajax_preview_article_stream')) {
            add_action('wp_ajax_cbia_preview_article_stream', 'cbia_ajax_preview_article_stream');
        }
        if (!has_action('wp_ajax_cbia_create_post_from_preview', 'cbia_ajax_create_post_from_preview')) {
            add_action('wp_ajax_cbia_create_post_from_preview', 'cbia_ajax_create_post_from_preview');
        }
        if (!has_action('wp_ajax_cbia_cancel_preview', 'cbia_ajax_cancel_preview')) {
            add_action('wp_ajax_cbia_cancel_preview', 'cbia_ajax_cancel_preview');
        }
        if (!has_action('admin_post_cbia_usage_export', 'cbia_admin_post_usage_export')) {
            add_action('admin_post_cbia_usage_export', 'cbia_admin_post_usage_export');
        }

        // Frontend styles for banner images
        if (!has_action('wp_head', 'cbia_output_banner_css')) {
            add_action('wp_head', 'cbia_output_banner_css', 20);
        }
        // Admin/editor styles for banner images (preview + editor)
        if (!has_action('admin_head', 'cbia_output_banner_css_admin')) {
            add_action('admin_head', 'cbia_output_banner_css_admin', 20);
        }
    }
}

if (!function_exists('cbia_admin_post_usage_export')) {
    function cbia_admin_post_usage_export() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        check_admin_referer('cbia_usage_export');

        $days = isset($_GET['usage_days']) ? absint(wp_unslash((string) $_GET['usage_days'])) : 7;
        if (!in_array($days, array(7, 30, 90), true)) $days = 7;
        $model_filter = isset($_GET['usage_model']) ? sanitize_text_field(wp_unslash((string) $_GET['usage_model'])) : '';
        $since_ts = time() - ($days * DAY_IN_SECONDS);

        $rows = array();
        $query = new WP_Query(array(
            'post_type'      => 'post',
            'post_status'    => array('publish', 'future', 'draft', 'pending'),
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));

        $ids = !empty($query->posts) ? $query->posts : array();
        foreach ($ids as $post_id) {
            if (!function_exists('cbia_costes_get_usage_rows_for_post')) break;
            $usage_rows = cbia_costes_get_usage_rows_for_post((int) $post_id);
            if (empty($usage_rows) || !is_array($usage_rows)) continue;
            foreach ($usage_rows as $r) {
                if (!is_array($r)) continue;
                $ts = isset($r['ts']) ? strtotime((string) $r['ts']) : 0;
                if ($ts && $ts < $since_ts) continue;
                $model = (string) ($r['model'] ?? '');
                if ($model_filter !== '' && $model_filter !== $model) {
                    continue;
                }
                $rows[] = array(
                    'post_id' => (int) $post_id,
                    'ts' => (string) ($r['ts'] ?? ''),
                    'type' => (string) ($r['type'] ?? ''),
                    'model' => $model,
                    'in' => (int) ($r['in'] ?? 0),
                    'out' => (int) ($r['out'] ?? 0),
                    'cin' => (int) ($r['cin'] ?? 0),
                    'ok' => !empty($r['ok']) ? 1 : 0,
                );
            }
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ai-blog-builder-usage.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, array('post_id','ts','type','model','in','out','cached_in','ok'));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}

// Button injected via admin.js (no inline CSS/JS)

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
                $msg = 'Yoast SEO estÃ¡ instalado pero inactivo. <a href="' . esc_url($activate_url) . '">Activar ahora</a>.';
            } else {
                $msg = 'Yoast SEO estÃ¡ instalado pero inactivo.';
            }
            echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses($msg, ['a' => ['href' => []]]) . '</p></div>';
            return;
        }

        if (current_user_can('install_plugins')) {
            $install_url = wp_nonce_url(
                self_admin_url('update.php?action=install-plugin&plugin=wordpress-seo'),
                'install-plugin_wordpress-seo'
            );
            $msg = 'Yoast SEO no estÃ¡ instalado. <a href="' . esc_url($install_url) . '">Instalar Yoast SEO</a>.';
        } else {
            $msg = 'Yoast SEO no estÃ¡ instalado.';
        }

        echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses($msg, ['a' => ['href' => []]]) . '</p></div>';
    }
}

if (!function_exists('cbia_admin_enqueue_inline')) {
    function cbia_admin_enqueue_inline($hook) {
        if ($hook !== 'toplevel_page_cbia' && $hook !== 'edit.php') return;

        wp_enqueue_script('jquery');
        wp_enqueue_style('dashicons');
        $css_path = CBIA_PRO_PLUGIN_DIR . 'assets/css/admin.css';
        $js_path  = CBIA_PRO_PLUGIN_DIR . 'assets/js/admin.js';
        $css_ver  = file_exists($css_path) ? filemtime($css_path) : CBIA_VERSION;
        $js_ver   = file_exists($js_path) ? filemtime($js_path) : CBIA_VERSION;

        wp_enqueue_style(
            'abb-admin',
            plugins_url('assets/css/admin.css', CBIA_PRO_PLUGIN_FILE),
            array(),
            $css_ver
        );
        wp_enqueue_script(
            'abb-admin',
            plugins_url('assets/js/admin.js', CBIA_PRO_PLUGIN_FILE),
            array('jquery'),
            $js_ver,
            true
        );
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('cbia_ajax_nonce');

        $enable_button = false;
        if ($hook === 'edit.php' && function_exists('get_current_screen')) {
            $screen = get_current_screen();
            $enable_button = ($screen && $screen->base === 'edit' && $screen->post_type === 'post');
        }
        wp_localize_script('abb-admin', 'ABB', array(
            'ajaxUrl' => $ajax_url,
            'nonce'   => $nonce,
            'addPostButton' => array(
                'enabled' => $enable_button,
                'url' => admin_url('admin.php?page=cbia&tab=blog'),
                'label' => 'Anadir entrada con IA',
            ),
        ));

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

if (!function_exists('cbia_output_banner_css_admin')) {
    function cbia_output_banner_css_admin() {
        if (!function_exists('cbia_get_settings')) return;
        $settings = cbia_get_settings();
        if (empty($settings['content_images_banner_enabled'])) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen) {
            $is_plugin_screen = (strpos((string)$screen->id, 'ai-blog-builder') !== false) || (strpos((string)$screen->id, 'cbia') !== false);
            $is_post_editor = in_array((string)$screen->base, array('post', 'post-new'), true);
            if (!$is_plugin_screen && !$is_post_editor) {
                return;
            }
        }

        $css = trim((string)($settings['content_images_banner_css'] ?? ''));
        if ($css === '') return;
        echo "<style id='cbia-banner-css-admin'>\n" . $css . "\n</style>";
    }
}

if (!function_exists('cbia_ajax_preview_article')) {
    function cbia_ajax_preview_article() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No autorizado'), 403);
        }

        $payload = array(
            'title' => isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '',
            'preview_mode' => isset($_POST['preview_mode']) ? sanitize_key((string)wp_unslash($_POST['preview_mode'])) : 'fast',
            'images_limit' => isset($_POST['images_limit']) ? absint(wp_unslash($_POST['images_limit'])) : 3,
            'post_language' => isset($_POST['post_language']) ? sanitize_text_field(wp_unslash($_POST['post_language'])) : '',
            'blog_prompt_mode' => isset($_POST['blog_prompt_mode']) ? sanitize_key((string)wp_unslash($_POST['blog_prompt_mode'])) : '',
            'blog_prompt_editable' => isset($_POST['blog_prompt_editable']) ? sanitize_textarea_field(wp_unslash($_POST['blog_prompt_editable'])) : '',
            'legacy_full_prompt' => isset($_POST['legacy_full_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['legacy_full_prompt'])) : '',
        );
        $service = null;
        if (function_exists('cbia_container')) {
            $container = cbia_container();
            if ($container) $service = $container->get('article_preview_service');
        }
        if (!$service && class_exists('CBIA_Article_Preview_Service')) {
            $service = new CBIA_Article_Preview_Service();
        }
        if (!$service || !method_exists($service, 'generate')) {
            wp_send_json_error(array('message' => 'Servicio de preview no disponible'), 500);
        }

        $result = $service->generate($payload);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }

        wp_send_json_success($result);
    }
}

if (!function_exists('cbia_sse_emit')) {
    function cbia_sse_emit($event, array $payload) {
        echo 'event: ' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$event) . "\n";
        echo 'data: ' . wp_json_encode($payload) . "\n\n";
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
        }
        @flush();
    }
}

if (!function_exists('cbia_ajax_preview_article_stream')) {
    function cbia_ajax_preview_article_stream() {
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        @ini_set('implicit_flush', '1');
        @ini_set('display_errors', '0');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            @ini_set('log_errors', '1');
        }
        nocache_headers();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');

        $nonce_ok = check_ajax_referer('cbia_ajax_nonce', '_ajax_nonce', false);
        if (!$nonce_ok) {
            cbia_sse_emit('preview_error', array('message' => 'Nonce invalido.'));
            cbia_sse_emit('cbia_error', array('message' => 'Nonce invalido.'));
            exit;
        }
        if (!current_user_can('manage_options')) {
            cbia_sse_emit('preview_error', array('message' => 'No autorizado.'));
            cbia_sse_emit('cbia_error', array('message' => 'No autorizado.'));
            exit;
        }

        $payload = array(
            'title' => isset($_GET['title']) ? sanitize_text_field(wp_unslash($_GET['title'])) : '',
            'preview_mode' => isset($_GET['preview_mode']) ? sanitize_key((string)wp_unslash($_GET['preview_mode'])) : 'fast',
            'images_limit' => isset($_GET['images_limit']) ? absint(wp_unslash($_GET['images_limit'])) : 3,
            'post_language' => isset($_GET['post_language']) ? sanitize_text_field(wp_unslash($_GET['post_language'])) : '',
            'blog_prompt_mode' => isset($_GET['blog_prompt_mode']) ? sanitize_key((string)wp_unslash($_GET['blog_prompt_mode'])) : '',
            // El SSE usa GET: no aceptar payload largo aqui para evitar URL enorme.
            'blog_prompt_editable' => '',
            'legacy_full_prompt' => '',
        );

        $service = null;
        if (function_exists('cbia_container')) {
            $container = cbia_container();
            if ($container) $service = $container->get('article_preview_service');
        }
        if (!$service && class_exists('CBIA_Article_Preview_Service')) {
            $service = new CBIA_Article_Preview_Service();
        }
        if (!$service) {
            cbia_sse_emit('preview_error', array('message' => 'Servicio de preview no disponible.'));
            cbia_sse_emit('cbia_error', array('message' => 'Servicio de preview no disponible.'));
            exit;
        }

        cbia_sse_emit('cbia_status', array('message' => 'Iniciando preview...'));
        cbia_sse_emit('cbia_ping', array('ts' => time()));
        if (method_exists($service, 'generate_stream')) {
            $result = $service->generate_stream($payload, function($event, $data) {
                cbia_sse_emit($event, is_array($data) ? $data : array());
            });
        } else {
            $result = $service->generate($payload);
        }

        if (is_wp_error($result)) {
            cbia_sse_emit('preview_error', array('message' => $result->get_error_message()));
            cbia_sse_emit('cbia_error', array('message' => $result->get_error_message()));
            exit;
        }
        cbia_sse_emit('preview_done', array(
            'ok' => 1,
            'preview_token' => is_array($result) ? (string)($result['preview_token'] ?? '') : '',
            'post_id' => is_array($result) ? (int)($result['post_id'] ?? 0) : 0,
            'word_count' => is_array($result) ? (int)($result['word_count'] ?? 0) : 0,
            'result' => $result
        ));
        cbia_sse_emit('cbia_done', array('result' => $result));
        exit;
    }
}

if (!function_exists('cbia_ajax_cancel_preview')) {
    function cbia_ajax_cancel_preview() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No autorizado'), 403);
        }
        $token = isset($_POST['preview_token']) ? sanitize_text_field(wp_unslash($_POST['preview_token'])) : '';
        if ($token === '') {
            wp_send_json_error(array('message' => 'Falta token de preview.'), 400);
        }

        $service = null;
        if (function_exists('cbia_container')) {
            $container = cbia_container();
            if ($container) $service = $container->get('article_preview_service');
        }
        if (!$service && class_exists('CBIA_Article_Preview_Service')) {
            $service = new CBIA_Article_Preview_Service();
        }
        if (!$service || !method_exists($service, 'cancel_preview')) {
            wp_send_json_error(array('message' => 'Servicio de preview no disponible'), 500);
        }

        $result = $service->cancel_preview($token);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }
        wp_send_json_success($result);
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

if (!function_exists('cbia_ajax_create_post_from_preview')) {
    function cbia_ajax_create_post_from_preview() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No autorizado'), 403);
        }

        $token = isset($_POST['preview_token']) ? sanitize_text_field(wp_unslash($_POST['preview_token'])) : '';
        if ($token === '') {
            wp_send_json_error(array('message' => 'Falta token de preview.'), 400);
        }
        $overrides = array(
            'title' => isset($_POST['edited_title']) ? sanitize_text_field(wp_unslash($_POST['edited_title'])) : '',
            'html' => isset($_POST['edited_html']) ? wp_kses_post(wp_unslash($_POST['edited_html'])) : '',
            'post_status' => isset($_POST['post_status']) ? sanitize_key((string)wp_unslash($_POST['post_status'])) : 'publish',
            'post_date_local' => isset($_POST['post_date_local']) ? sanitize_text_field(wp_unslash($_POST['post_date_local'])) : '',
        );

        $service = null;
        if (function_exists('cbia_container')) {
            $container = cbia_container();
            if ($container) $service = $container->get('article_preview_service');
        }
        if (!$service && class_exists('CBIA_Article_Preview_Service')) {
            $service = new CBIA_Article_Preview_Service();
        }
        if (!$service || !method_exists($service, 'create_post_from_token')) {
            wp_send_json_error(array('message' => 'Servicio de preview no disponible'), 500);
        }

        $result = $service->create_post_from_token($token, $overrides);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }

        wp_send_json_success($result);
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
            cbia_log($stop === 1 ? 'Se activÃ³ STOP (detener generaciÃ³n).' : 'Se desactivÃ³ STOP (reanudar).', 'INFO');
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
            cbia_log_message('[INFO] START: Ejecutando primera tanda inmediata (para evitar â€œno hace nadaâ€).');
        }
        $result = $blog_service
            ? $blog_service->run_generate_blogs($max_per_run)
            : cbia_run_generate_blogs($max_per_run);

        // No encolar aqui: cbia_run_generate_blogs ya gestiona el scheduling si queda cola.
        if (function_exists('cbia_log_message')) {
            cbia_log_message(empty($result['done'])
                ? '[INFO] START: Cola pendiente, el scheduler continuara.'
                : '[INFO] START: No queda cola pendiente.'
            );
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

if (!function_exists('cbia_ajax_get_img_prompt')) {
    function cbia_ajax_get_img_prompt() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'No autorizado'], 403);

        $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
        $type = isset($_POST['type']) ? sanitize_key((string)$_POST['type']) : '';
        $idx = isset($_POST['idx']) ? (int)$_POST['idx'] : 0;

        if ($type !== 'featured' && $type !== 'internal') {
            wp_send_json_error(['message' => 'Parametros invalidos'], 400);
        }

        if ($post_id <= 0) {
            if (!function_exists('cbia_get_image_prompt_template')) {
                wp_send_json_error(['message' => 'Funcion no disponible'], 500);
            }
            $tpl = cbia_get_image_prompt_template($type, $idx);
            wp_send_json_success([
                'prompt' => (string)$tpl,
                'has_override' => 0,
            ]);
        }
        $title = get_the_title($post_id);
        $img_descs = function_exists('cbia_get_post_image_descs')
            ? cbia_get_post_image_descs($post_id)
            : array('featured' => array('desc' => '', 'section' => 'intro', 'attach_id' => 0), 'internal' => array());

        $desc = '';
        if ($type === 'featured') {
            $desc = (string)($img_descs['featured']['desc'] ?? '');
        } else {
            if ($idx < 1) $idx = 1;
            if (!empty($img_descs['internal'][$idx - 1]['desc'])) {
                $desc = (string)$img_descs['internal'][$idx - 1]['desc'];
            }
        }
        if ($desc === '') $desc = (string)$title;

        $has_override = function_exists('cbia_get_img_prompt_override')
            ? (cbia_get_img_prompt_override($post_id, $type, $idx) !== '')
            : false;

        $prompt = function_exists('cbia_build_image_prompt_for_post')
            ? cbia_build_image_prompt_for_post($post_id, $type, $desc, $idx)
            : '';

        wp_send_json_success([
            'prompt' => (string)$prompt,
            'has_override' => $has_override ? 1 : 0,
        ]);
    }
}

if (!function_exists('cbia_ajax_save_img_prompt_override')) {
    function cbia_ajax_save_img_prompt_override() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'No autorizado'], 403);

        $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
        $type = isset($_POST['type']) ? sanitize_key((string)$_POST['type']) : '';
        $idx = isset($_POST['idx']) ? (int)$_POST['idx'] : 0;
        $prompt = isset($_POST['prompt']) ? wp_unslash((string)$_POST['prompt']) : '';

        if ($type !== 'featured' && $type !== 'internal') {
            wp_send_json_error(['message' => 'Parametros invalidos'], 400);
        }

        $prompt = trim($prompt);
        if (function_exists('sanitize_textarea_field')) {
            $prompt = sanitize_textarea_field($prompt);
        }

        if ($post_id <= 0) {
            if (!function_exists('cbia_update_settings_merge')) {
                wp_send_json_error(['message' => 'Funcion no disponible'], 500);
            }
            $partial = [];
            if ($type === 'featured') {
                $partial['prompt_img_featured'] = $prompt;
            } else {
                if ($idx >= 1) {
                    $partial['prompt_img_internal_' . $idx] = $prompt;
                } else {
                    $partial['prompt_img_internal'] = $prompt;
                }
            }
            cbia_update_settings_merge($partial);
            wp_send_json_success(['ok' => 1]);
        }

        if (!function_exists('cbia_set_img_prompt_override')) {
            wp_send_json_error(['message' => 'Funcion no disponible'], 500);
        }

        cbia_set_img_prompt_override($post_id, $type, $idx, $prompt);
        wp_send_json_success(['ok' => 1]);
    }
}

if (!function_exists('cbia_ajax_regen_image')) {
    function cbia_ajax_regen_image() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'No autorizado'], 403);

        $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
        $type = isset($_POST['type']) ? sanitize_key((string)$_POST['type']) : '';
        $idx = isset($_POST['idx']) ? (int)$_POST['idx'] : 0;

        if ($post_id <= 0 || ($type !== 'featured' && $type !== 'internal')) {
            wp_send_json_error(['message' => 'Parametros invalidos'], 400);
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post no encontrado'], 404);
        }

        $title = get_the_title($post_id);
        $img_descs = function_exists('cbia_get_post_image_descs')
            ? cbia_get_post_image_descs($post_id)
            : array('featured' => array('desc' => '', 'section' => 'intro', 'attach_id' => 0), 'internal' => array());

        $desc = '';
        $section = 'body';
        $old_attach = 0;

        if ($type === 'featured') {
            $desc = (string)($img_descs['featured']['desc'] ?? '');
            $section = 'intro';
            $old_attach = (int)($img_descs['featured']['attach_id'] ?? 0);
        } else {
            if ($idx < 1) $idx = 1;
            if (!empty($img_descs['internal'][$idx - 1])) {
                $desc = (string)($img_descs['internal'][$idx - 1]['desc'] ?? '');
                $section = (string)($img_descs['internal'][$idx - 1]['section'] ?? 'body');
                $old_attach = (int)($img_descs['internal'][$idx - 1]['attach_id'] ?? 0);
            }
        }

        if ($desc === '') $desc = (string)$title;
        $prompt = function_exists('cbia_build_image_prompt_for_post')
            ? cbia_build_image_prompt_for_post($post_id, $type, $desc, $idx)
            : '';

        $alt = function_exists('cbia_sanitize_alt_from_desc') ? cbia_sanitize_alt_from_desc($desc) : '';
        if ($alt === '') $alt = function_exists('cbia_sanitize_alt_from_desc') ? cbia_sanitize_alt_from_desc($title) : '';

        list($ok, $attach_id, $model, $err) = cbia_generate_image_openai_with_prompt($prompt, $section, $title, $alt, $idx);
        if (!$ok || !$attach_id) {
            cbia_log(__("Regenerar imagen: fallo post {$post_id} ({$type} {$idx}): ","ai-blog-builder-pro") . ($err ?: ''), 'ERROR');
            wp_send_json_error(['message' => $err ?: 'No se pudo generar la imagen'], 500);
        }

        // Registrar usage imagen (costes + agregados)
        if (function_exists('cbia_costes_record_usage')) {
            cbia_costes_record_usage($post_id, array(
                'type' => 'image',
                'model' => (string)$model,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cached_input_tokens' => 0,
                'ok' => 1,
                'error' => '',
            ));
        }
        if (function_exists('cbia_image_append_call')) {
            cbia_image_append_call($post_id, $section, (string)$model, true, (int)$attach_id, '');
        }

        if ($type === 'featured') {
            set_post_thumbnail($post_id, (int)$attach_id);
            $img_descs['featured']['desc'] = (string)$desc;
            $img_descs['featured']['section'] = 'intro';
            $img_descs['featured']['attach_id'] = (int)$attach_id;
            cbia_set_post_image_descs($post_id, $img_descs);

            cbia_log(__("Regenerar imagen: destacada OK post {$post_id} attach_id={$attach_id}","ai-blog-builder-pro"), 'INFO');
            wp_send_json_success(['ok' => 1, 'attach_id' => (int)$attach_id]);
        }

        // Interna: reemplazar en contenido
        $html = (string)$post->post_content;
        $url = wp_get_attachment_url((int)$attach_id);
        $img_tag = cbia_build_content_img_tag_with_meta($url, $alt, $section, (int)$attach_id, $idx);

        $replaced = false;
        if ($old_attach > 0) {
            $replaced = cbia_replace_img_by_attach_id($html, $old_attach, $img_tag);
        }

        if (!$replaced) {
            $desc_clean = cbia_sanitize_alt_from_desc($desc);
            $token = '[IMAGEN_PENDIENTE: ' . $desc_clean . ']';
            $replaced = cbia_replace_pending_marker($html, $token, $img_tag);
        }

        if ($replaced) {
            $html = cbia_cleanup_post_html($html);
            wp_update_post(['ID' => $post_id, 'post_content' => $html]);
        }

        if (!empty($img_descs['internal'][$idx - 1])) {
            $img_descs['internal'][$idx - 1]['desc'] = (string)$desc;
            $img_descs['internal'][$idx - 1]['section'] = (string)$section;
            $img_descs['internal'][$idx - 1]['attach_id'] = (int)$attach_id;
        } else {
            $img_descs['internal'][] = array(
                'desc' => (string)$desc,
                'section' => (string)$section,
                'attach_id' => (int)$attach_id,
            );
        }
        cbia_set_post_image_descs($post_id, $img_descs);

        // Actualiza pendientes si existian
        $list_raw = get_post_meta($post_id, '_cbia_pending_images_list', true);
        $list = [];
        if ($list_raw) {
            $tmp = json_decode((string)$list_raw, true);
            if (is_array($tmp)) $list = $tmp;
        }
        if (!empty($list)) {
            foreach ($list as &$it) {
                if (!is_array($it)) continue;
                if (($it['desc'] ?? '') === cbia_sanitize_alt_from_desc($desc) && ($it['status'] ?? '') === 'pending') {
                    $it['status'] = 'done';
                    $it['attach_id'] = (int)$attach_id;
                    $it['last_error'] = '';
                    break;
                }
            }
            unset($it);
            update_post_meta($post_id, '_cbia_pending_images_list', wp_json_encode($list));
            $left = cbia_extract_pending_markers($html);
            update_post_meta($post_id, '_cbia_pending_images', (string)count($left));
        }

        cbia_log(__("Regenerar imagen: interna OK post {$post_id} idx={$idx} attach_id={$attach_id}","ai-blog-builder-pro"), 'INFO');
        wp_send_json_success([
            'ok' => 1,
            'attach_id' => (int)$attach_id,
            'replaced' => $replaced ? 1 : 0,
        ]);
    }
}

if (!function_exists('cbia_ajax_sync_models')) {
    function cbia_ajax_sync_models() {
        check_ajax_referer('cbia_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'No autorizado'], 403);

        $provider = isset($_POST['provider']) ? sanitize_key((string)$_POST['provider']) : '';
        if ($provider === '') {
            if (function_exists('cbia_providers_get_current_provider')) {
                $provider = cbia_providers_get_current_provider();
            }
        }
        if ($provider === '') {
            wp_send_json_error(['message' => 'Proveedor invalido'], 400);
        }

        if (!function_exists('cbia_providers_sync_models')) {
            wp_send_json_error(['message' => 'Sync no disponible'], 500);
        }

        $result = cbia_providers_sync_models($provider);
        if (!empty($result['ok'])) {
            if (function_exists('cbia_providers_get_model_sync_meta')) {
                $meta_all = cbia_providers_get_model_sync_meta();
                if (isset($meta_all[$provider]) && is_array($meta_all[$provider])) {
                    $result['meta'] = $meta_all[$provider];
                }
            }
            if (function_exists('cbia_log')) {
                cbia_log(__("Sync modelos OK provider={$provider} count=","ai-blog-builder-pro") . (int)$result['count'] . " source=" . (string)$result['source'], 'INFO');
            }
            wp_send_json_success($result);
        }

        if (function_exists('cbia_log')) {
            cbia_log(__("Sync modelos fallo provider={$provider} err=","ai-blog-builder-pro") . (string)($result['error'] ?? ''), 'WARN');
        }
        wp_send_json_error(['message' => 'No se pudo sincronizar', 'result' => $result], 500);
    }
}

