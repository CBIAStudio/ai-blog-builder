<?php
/**
 * Uninstall cleanup for Creador Blog IA.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

// Core options
delete_option('cbia_settings');
delete_option('cbia_activity_log');
delete_option('cbia_log_counter');
delete_option('cbia_stop_generation');
delete_option('cbia_checkpoint');
delete_option('cbia_legacy_usage');

// Costes
delete_option('cbia_costes_settings');
delete_option('cbia_costes_log');

// Old posts
delete_option('cbia_oldposts_settings');
delete_option('cbia_oldposts_log');

// Yoast integration (legacy)
delete_option('cbia_yoast_settings');
delete_option('cbia_yoast_log');

// Settings in new structure (if any)
delete_option('cbia_costes_log_counter');

