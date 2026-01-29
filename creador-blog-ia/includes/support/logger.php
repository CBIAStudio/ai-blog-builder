<?php
/**
 * Central logger.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Logger')) {
    class CBIA_Logger {
        private function write($level, $msg) {
            $level = strtoupper(trim((string)$level));
            if (function_exists('cbia_fix_mojibake')) {
                $msg = cbia_fix_mojibake($msg);
            }
            if (function_exists('cbia_log')) {
                cbia_log((string)$msg, $level);
                return;
            }
            error_log('[CBIA][' . $level . '] ' . (string)$msg);
        }

        public function info($msg) { $this->write('INFO', $msg); }
        public function warn($msg) { $this->write('WARN', $msg); }
        public function error($msg) { $this->write('ERROR', $msg); }
    }
}
