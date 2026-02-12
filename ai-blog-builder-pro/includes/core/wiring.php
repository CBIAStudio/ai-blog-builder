<?php
/**
 * Wiring for the 2.3 structure (safe, no behavior change yet).
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_container')) {
    function cbia_container() {
        static $container = null;
        if ($container !== null) return $container;

        $container = new CBIA_Container();

        // Services
        if (class_exists('CBIA_Settings_Repository')) {
            $container->set('settings_repo', new CBIA_Settings_Repository());
        }
        if (class_exists('CBIA_Settings_Service')) {
            $container->set('settings_service', new CBIA_Settings_Service($container->get('settings_repo')));
        }
        if (class_exists('CBIA_Log_Service')) {
            $container->set('log_service', new CBIA_Log_Service());
        }
        if (class_exists('CBIA_Costs_Repo')) {
            $container->set('costs_repo', new CBIA_Costs_Repo());
        }
        if (class_exists('CBIA_Costs_Service')) {
            $container->set('costs_service', new CBIA_Costs_Service($container->get('costs_repo')));
        }
        if (class_exists('CBIA_Blog_Service')) {
            $container->set('blog_service', new CBIA_Blog_Service());
        }
        if (class_exists('CBIA_Article_Preview_Service')) {
            $container->set('article_preview_service', new CBIA_Article_Preview_Service());
        }
        if (class_exists('CBIA_Oldposts_Repo')) {
            $container->set('oldposts_repo', new CBIA_Oldposts_Repo());
        }
        if (class_exists('CBIA_Oldposts_Service')) {
            $container->set('oldposts_service', new CBIA_Oldposts_Service($container->get('oldposts_repo')));
        }
        if (class_exists('CBIA_OpenAI_Client')) {
            $container->set('openai_client', new CBIA_OpenAI_Client());
        }
        if (class_exists('CBIA_Engine_Service')) {
            $container->set('engine_service', new CBIA_Engine_Service($container->get('openai_client')));
        }
        // Integrations
        if (class_exists('CBIA_Yoast_Client')) {
            $container->set('yoast_client', new CBIA_Yoast_Client());
        }

        if (class_exists('CBIA_Yoast_Service')) {
            $container->set('yoast_service', new CBIA_Yoast_Service($container->get('yoast_client')));
        }

        // Admin
        if (class_exists('CBIA_Admin_Router')) {
            $container->set('admin_router', new CBIA_Admin_Router());
        }
        if (class_exists('CBIA_Costs_Admin')) {
            $container->set('costs_admin', new CBIA_Costs_Admin($container->get('costs_service')));
        }
        if (class_exists('CBIA_Oldposts_Admin')) {
            $container->set('oldposts_admin', new CBIA_Oldposts_Admin($container->get('oldposts_service')));
        }
        if (class_exists('CBIA_Config_Admin')) {
            $container->set('config_admin', new CBIA_Config_Admin($container->get('settings_service')));
        }
        if (class_exists('CBIA_Blog_Admin')) {
            $container->set('blog_admin', new CBIA_Blog_Admin($container->get('blog_service')));
        }
        if (class_exists('CBIA_Usage_Admin')) {
            $container->set('usage_admin', new CBIA_Usage_Admin());
        }
        if (class_exists('CBIA_Yoast_Admin')) {
            $container->set('yoast_admin', new CBIA_Yoast_Admin($container->get('yoast_service')));
        }
        if (class_exists('CBIA_Diagnostics_Admin')) {
            $container->set('diagnostics_admin', new CBIA_Diagnostics_Admin());
        }

        // Jobs / scheduler
        if (class_exists('CBIA_Scheduler')) {
            $container->set('scheduler', new CBIA_Scheduler());
        }
        if (class_exists('CBIA_Oldposts_Job')) {
            $container->set('oldposts_job', new CBIA_Oldposts_Job());
        }

        // Register tabs on router (if available)
        $router = $container->get('admin_router');
        if ($router) {
            $router->register_tab_object($container->get('config_admin'));
            $router->register_tab_object($container->get('blog_admin'));
            $router->register_tab_object($container->get('oldposts_admin'));
            $router->register_tab_object($container->get('costs_admin'));
            $router->register_tab_object($container->get('usage_admin'));
            $router->register_tab_object($container->get('yoast_admin'));
            $router->register_tab_object($container->get('diagnostics_admin'));
        }

        return $container;
    }
}
