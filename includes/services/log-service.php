<?php
/**
 * Log service (scoped access).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Log_Service')) {
    class CBIA_Log_Service {
        public function get_log(string $scope = 'global') {
            $scope = strtolower(trim($scope));
            if ($scope === 'oldposts' && function_exists('cbia_oldposts_get_log')) {
                $log = cbia_oldposts_get_log();
                return is_array($log) ? $log : array('log' => (string)$log, 'counter' => 0);
            }
            if ($scope === 'costes' && function_exists('cbia_costes_log_get')) {
                $log = cbia_costes_log_get();
                return is_array($log) ? $log : array('log' => (string)$log, 'counter' => 0);
            }
            if (function_exists('cbia_get_log')) {
                return cbia_get_log();
            }
            return array('log' => '', 'counter' => 0);
        }

        public function clear_log(string $scope = 'global'): bool {
            $scope = strtolower(trim($scope));
            if ($scope === 'oldposts' && function_exists('cbia_oldposts_clear_log')) {
                cbia_oldposts_clear_log();
                return true;
            }
            if ($scope === 'costes' && function_exists('cbia_costes_log_clear')) {
                cbia_costes_log_clear();
                return true;
            }
            if (function_exists('cbia_clear_log')) {
                cbia_clear_log();
                return true;
            }
            return false;
        }
    }
}

