<?php
/**
 * Legacy Old Posts loader.
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../engine/oldposts.php';

if (!function_exists('cbia_render_tab_oldposts')) {
    function cbia_render_tab_oldposts(){
        if (!current_user_can('manage_options')) return;

        $view = (defined('CBIA_INCLUDES_DIR') ? CBIA_INCLUDES_DIR . 'admin/views/oldposts.php' : __DIR__ . '/../admin/views/oldposts.php');
        if (file_exists($view)) {
            include $view;
            return;
        }

        echo '<p>No se pudo cargar Actualizar antiguos.</p>';
    }
}

/* ------------------------- FIN includes/legacy/cbia-oldposts.php ------------------------- */
