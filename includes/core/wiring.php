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
        if (class_exists('CBIA_Blog_Service')) {
            $container->set('blog_service', new CBIA_Blog_Service());
        }
        if (class_exists('CBIA_Article_Preview_Service')) {
            $container->set('article_preview_service', new CBIA_Article_Preview_Service());
        }
        if (class_exists('CBIA_OpenAI_Client')) {
            $container->set('openai_client', new CBIA_OpenAI_Client());
        }
        if (class_exists('CBIA_Engine_Service')) {
            $container->set('engine_service', new CBIA_Engine_Service($container->get('openai_client')));
        }
        // Integrations

        // Admin
        if (class_exists('CBIA_Admin_Router')) {
            $container->set('admin_router', new CBIA_Admin_Router());
        }
        if (class_exists('CBIA_Config_Admin')) {
            $container->set('config_admin', new CBIA_Config_Admin($container->get('settings_service')));
        }
        if (class_exists('CBIA_Blog_Admin')) {
            $container->set('blog_admin', new CBIA_Blog_Admin($container->get('blog_service')));
        }

        // Jobs / scheduler

        // Register tabs on router (if available)
        $router = $container->get('admin_router');
        if ($router) {
            $router->register_tab_object($container->get('config_admin'));
            $router->register_tab_object($container->get('blog_admin'));
            // Normal version: only Config + Blog tabs.
        }

        return $container;
    }
}

