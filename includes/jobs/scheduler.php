<?php
/**
 * Scheduler/job hooks.
 *
 * Mantiene compatibilidad con el flujo legacy de cron.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_pending_fill_event_handler')) {
    /**
     * Cron handler for filling pending images.
     * Uses a transient lock to avoid overlapping runs.
     */
    function cbia_pending_fill_event_handler() {
        $log = function ($msg, $level = 'INFO') {
            if (function_exists('cbia_log_message')) {
                cbia_log_message((string)$msg);
            } elseif (function_exists('cbia_log')) {
                cbia_log((string)$msg, (string)$level);
            }
        };

        $lock_key = 'cbia_pending_fill_lock';
        if (get_transient($lock_key)) {
            $log('[WARN] CRON: pending fill ya en ejecuciÃ³n. Se omite.');
            return;
        }
        set_transient($lock_key, 1, 15 * MINUTE_IN_SECONDS);

        try {
            if (function_exists('cbia_run_fill_pending_images')) {
                $log('[INFO] CRON: ejecutando relleno de pendientes.');
                cbia_run_fill_pending_images(10);
            } else {
                $log('[WARN] CRON: falta cbia_run_fill_pending_images().');
            }
        } finally {
            delete_transient($lock_key);
        }
    }
}

if (!class_exists('CBIA_Scheduler')) {
    class CBIA_Scheduler {
        public function register() {
            // Hook for pending images cron fill.
            if (!has_action('cbia_pending_fill_event', 'cbia_pending_fill_event_handler')) {
                add_action('cbia_pending_fill_event', 'cbia_pending_fill_event_handler');
            }

            // Schedule/unschedule pending fill based on settings.
            $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];
            $enable = !empty($settings['enable_cron_fill']);

            if ($enable) {
                if (!wp_next_scheduled('cbia_pending_fill_event')) {
                    wp_schedule_event(time() + 300, 'hourly', 'cbia_pending_fill_event');
                }
            } else {
                if (wp_next_scheduled('cbia_pending_fill_event')) {
                    wp_clear_scheduled_hook('cbia_pending_fill_event');
                }
            }
        }
    }
}

