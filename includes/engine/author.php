<?php
/**
 * Author selection for posts.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_pick_post_author_id')) {
    function cbia_pick_post_author_id() {
        $s = cbia_get_settings();

        // 1) Si defines un autor fijo en settings (recomendado para cron)
        $fixed = (int)($s['default_author_id'] ?? 0);
        if ($fixed > 0) return $fixed;

        // 2) Si hay usuario actual (cuando lanzas manual desde admin)
        $cur = (int)get_current_user_id();
        if ($cur > 0) return $cur;

        // 3) Fallback: primer administrador
        $admins = get_users([
            'role__in' => ['administrator'],
            'number'   => 1,
            'fields'   => 'ID',
        ]);
        if (!empty($admins) && isset($admins[0])) return (int)$admins[0];

        // 4) Ãšltimo fallback
        return 1;
    }
}

