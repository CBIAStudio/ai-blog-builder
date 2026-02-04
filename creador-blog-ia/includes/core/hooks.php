<?php
/**
 * Core hooks (admin, AJAX helpers).
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_register_core_hooks')) {
    function cbia_register_core_hooks() {
        if (!has_action('admin_enqueue_scripts', 'cbia_admin_enqueue_inline')) {
            add_action('admin_enqueue_scripts', 'cbia_admin_enqueue_inline');
        }
        if (!has_action('admin_head-edit.php', 'cbia_admin_posts_list_button')) {
            add_action('admin_head-edit.php', 'cbia_admin_posts_list_button');
        }

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
    }
}

if (!function_exists('cbia_admin_posts_list_button')) {
    function cbia_admin_posts_list_button() {
        if (!current_user_can('manage_options')) return;
        if (!function_exists('get_current_screen')) return;

        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'post') return;

        $url = admin_url('admin.php?page=cbia&tab=blog');
        $label = 'A�adir entrada con IA';

        echo "<style>.cbia-add-ai{margin-left:6px;background:#2271b1;color:#fff;border-color:#2271b1}</style>\n";
        echo "<script>(function(){function addBtn(){var target=document.querySelector('.wrap .page-title-action');if(!target||document.querySelector('.cbia-add-ai'))return;var a=document.createElement('a');a.className='page-title-action cbia-add-ai';a.href=" . json_encode($url) . ";a.textContent=" . json_encode($label) . ";target.insertAdjacentElement('afterend',a);}if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',addBtn);}else{addBtn();}})();</script>\n";
    }
}

if (!function_exists('cbia_admin_enqueue_inline')) {
    function cbia_admin_enqueue_inline($hook) {
        if ($hook !== 'toplevel_page_cbia') return;

        wp_enqueue_script('jquery');

        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('cbia_ajax_nonce');

        $js =
            "(function($){\n" .
            "  window.CBIA = window.CBIA || {};\n" .
            "  CBIA.ajaxUrl = " . wp_json_encode($ajax_url) . ";\n" .
            "  CBIA.nonce = " . wp_json_encode($nonce) . ";\n" .
            "\n" .
            "  CBIA.fetchLog = function(targetSelector){\n" .
            "    return $.post(CBIA.ajaxUrl, {action:'cbia_get_log', _ajax_nonce: CBIA.nonce})\n" .
            "      .done(function(res){\n" .
            "        if(res && res.success && res.data){\n" .
            "          $(targetSelector).val(res.data.log || '');\n" .
            "        }\n" .
            "      });\n" .
            "  };\n" .
            "\n" .
            "  CBIA.clearLog = function(targetSelector){\n" .
            "    return $.post(CBIA.ajaxUrl, {action:'cbia_clear_log', _ajax_nonce: CBIA.nonce})\n" .
            "      .done(function(res){\n" .
            "        if(res && res.success){\n" .
            "          $(targetSelector).val('');\n" .
            "        }\n" .
            "      });\n" .
            "  };\n" .
            "\n" .
            "  CBIA.setStop = function(stop){\n" .
            "    return $.post(CBIA.ajaxUrl, {action:'cbia_set_stop', stop: stop ? 1 : 0, _ajax_nonce: CBIA.nonce});\n" .
            "  };\n" .
            "})(jQuery);\n";

        wp_add_inline_script('jquery', $js, 'after');
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
            cbia_log($stop === 1 ? 'Stop activado (detener generacion).' : 'Stop desactivado (reanudar).', 'INFO');
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
        check_ajax_referer('cbia_ajax_nonce');
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

        // Run 1 batch immediately for visible log activity.
        $max_per_run = 1;
        if (function_exists('cbia_log_message')) {
            cbia_log_message('[INFO] START: Ejecutando primera tanda inmediata.');
        }

        $result = $blog_service
            ? $blog_service->run_generate_blogs($max_per_run)
            : cbia_run_generate_blogs($max_per_run);

        if (is_array($result) && empty($result['done'])) {
            if (function_exists('cbia_log_message')) {
                cbia_log_message('[INFO] START: Queda cola, encolando evento background.');
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
