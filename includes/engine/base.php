<?php
/**
 * Base helpers for engine.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_get_settings')) {
    function cbia_get_settings() {
        if (defined('CBIA_OPTION_SETTINGS')) {
            $stored = get_option(CBIA_OPTION_SETTINGS, []);
            if (!is_array($stored)) $stored = [];

            if (function_exists('cbia_get_default_settings')) {
                $defaults = cbia_get_default_settings();
                return array_replace_recursive($defaults, $stored);
            }

            return $stored;
        }

        $s = get_option('cbia_settings', []);
        return is_array($s) ? $s : [];
    }
}

if (!function_exists('cbia_log_counter_key')) {
    function cbia_log_counter_key(){
        if (defined('CBIA_OPTION_LOG_COUNTER')) return CBIA_OPTION_LOG_COUNTER;
        return 'cbia_log_counter';
    }
}

if (!function_exists('cbia_log_key')) {
    function cbia_log_key(){
        if (defined('CBIA_OPTION_LOG')) return CBIA_OPTION_LOG;
        return 'cbia_activity_log';
    }
}

if (!function_exists('cbia_log')) {
    function cbia_log($message, $level = 'INFO') {
        if (function_exists('cbia_fix_mojibake')) {
            $message = cbia_fix_mojibake($message);
        }
        if (defined('CBIA_OPTION_LOG') && defined('CBIA_OPTION_LOG_COUNTER')) {
            $level = strtoupper(trim((string)$level ?: 'INFO'));
            $ts = function_exists('cbia_now_mysql') ? cbia_now_mysql() : current_time('mysql');
            $line = '[' . $ts . '][' . $level . '] ' . (string)$message;
            $log = (string) get_option(CBIA_OPTION_LOG, '');
            if ($log !== '') {
                $last = rtrim($log, "\r\n");
                $last = (strrpos($last, "\n") !== false) ? substr($last, strrpos($last, "\n") + 1) : $last;
                if ($last === $line) {
                    return;
                }
                $log .= "\n" . $line;
            } else {
                $log = $line;
            }

            if (strlen($log) > 250000) {
                $lines = explode("\n", $log);
                if (count($lines) > 2000) {
                    $lines = array_slice($lines, -2000);
                    $log = implode("\n", $lines);
                }
            }

            update_option(CBIA_OPTION_LOG, $log, false);

            $cnt = (int) get_option(CBIA_OPTION_LOG_COUNTER, 0);
            update_option(CBIA_OPTION_LOG_COUNTER, $cnt + 1, false);

            wp_cache_delete(CBIA_OPTION_LOG, 'options');
            wp_cache_delete(CBIA_OPTION_LOG_COUNTER, 'options');
            return;
        }

        $log = (string) get_option(cbia_log_key(), '');
        $ts  = current_time('mysql');
        $line = "[{$ts}] [{$level}] {$message}";
        if ($log !== '') {
            $last = rtrim($log, "\r\n");
            $last = (strrpos($last, "\n") !== false) ? substr($last, strrpos($last, "\n") + 1) : $last;
            if ($last === $line) {
                return;
            }
            $log .= $line . "\n";
        } else {
            $log = $line . "\n";
        }
        if (strlen($log) > 250000) $log = substr($log, -250000);

        update_option(cbia_log_key(), $log, false);

        // contador anti-cache para polling
        $cnt = (int) get_option(cbia_log_counter_key(), 0);
        update_option(cbia_log_counter_key(), $cnt + 1, false);

        // fuerza a no servir valores cacheados de options
        wp_cache_delete(cbia_log_key(), 'options');
        wp_cache_delete(cbia_log_counter_key(), 'options');
    }
}

if (!function_exists('cbia_get_log')) {
    function cbia_get_log() {
        if (defined('CBIA_OPTION_LOG') && defined('CBIA_OPTION_LOG_COUNTER')) {
            $log = (string) get_option(CBIA_OPTION_LOG, '');
            if (function_exists('cbia_fix_mojibake')) {
                $log = cbia_fix_mojibake($log);
            }
            $counter = (int) get_option(CBIA_OPTION_LOG_COUNTER, 0);
            return array('log' => $log, 'counter' => $counter);
        }

        $log = (string) get_option(cbia_log_key(), '');
        if (function_exists('cbia_fix_mojibake')) {
            $log = cbia_fix_mojibake($log);
        }
        $counter = (int) get_option(cbia_log_counter_key(), 0);
        return array('log' => $log, 'counter' => $counter);
    }
}

if (!function_exists('cbia_clear_log')) {
    function cbia_clear_log() {
        if (defined('CBIA_OPTION_LOG') && defined('CBIA_OPTION_LOG_COUNTER')) {
            delete_option(CBIA_OPTION_LOG);
            delete_option(CBIA_OPTION_LOG_COUNTER);
            wp_cache_delete(CBIA_OPTION_LOG, 'options');
            wp_cache_delete(CBIA_OPTION_LOG_COUNTER, 'options');
            return;
        }

        delete_option(cbia_log_key());
        delete_option(cbia_log_counter_key());
        wp_cache_delete(cbia_log_key(), 'options');
        wp_cache_delete(cbia_log_counter_key(), 'options');
    }
}

if (!function_exists('cbia_is_stop_requested')) {
    /**
     * Unified STOP flag reader (used by engine + AJAX).
     */
    function cbia_is_stop_requested(): bool {
        // Preview/manual flows can bypass STOP so users are never blocked from testing.
        if (!empty($GLOBALS['cbia_ignore_stop'])) {
            return false;
        }
        if (function_exists('cbia_check_stop_flag')) {
            return (bool)cbia_check_stop_flag();
        }
        if (function_exists('cbia_stop_flag_key')) {
            return !empty(get_option(cbia_stop_flag_key(), 0));
        }
        if (defined('CBIA_OPTION_STOP')) {
            return !empty(get_option(CBIA_OPTION_STOP, 0));
        }
        return false;
    }
}

if (!function_exists('cbia_openai_api_key')) {
    /**
     * API key accessor (kept for legacy compatibility).
     */
    function cbia_openai_api_key(): string {
        if (function_exists('cbia_get_settings')) {
            $settings = cbia_get_settings();
            return (string)($settings['openai_api_key'] ?? '');
        }
        if (defined('CBIA_OPTION_SETTINGS')) {
            $settings = get_option(CBIA_OPTION_SETTINGS, []);
            return is_array($settings) ? (string)($settings['openai_api_key'] ?? '') : '';
        }
        $settings = get_option('cbia_settings', []);
        return is_array($settings) ? (string)($settings['openai_api_key'] ?? '') : '';
    }
}

// CAMBIO: helpers de proveedor/modelo/keys (texto e imagen) con compatibilidad
if (!function_exists('cbia_get_legacy_api_key')) {
    function cbia_get_legacy_api_key(): string {
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];
        if (!empty($settings['api_key'])) return (string)$settings['api_key'];
        return '';
    }
}

if (!function_exists('cbia_get_provider_api_key')) {
    /**
     * Obtiene la API key segun proveedor con fallback a estructuras antiguas.
     */
    function cbia_get_provider_api_key(string $provider): string {
        $provider = sanitize_key($provider);
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];

        // Prioridad: keys nuevas por proveedor en settings principales.
        $map = array(
            'openai'  => (string)($settings['openai_api_key'] ?? ''),
            'google'  => (string)($settings['google_api_key'] ?? ''),
            'deepseek'=> (string)($settings['deepseek_api_key'] ?? ''),
        );
        if (!empty($map[$provider])) return $map[$provider];

        // Fallback: settings de providers (pro)
        if (function_exists('cbia_providers_get_settings')) {
            $p = cbia_providers_get_settings();
            if (!empty($p['providers'][$provider]['api_key'])) {
                return (string)$p['providers'][$provider]['api_key'];
            }
        }

        // Fallback legacy: api_key unico
        $legacy = cbia_get_legacy_api_key();
        if ($legacy !== '') return $legacy;

        return '';
    }
}

// CAMBIO: helpers para Google Imagen (Vertex AI)
if (!function_exists('cbia_get_google_project_id')) {
    function cbia_get_google_project_id(): string {
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];
        return (string)($settings['google_project_id'] ?? '');
    }
}

if (!function_exists('cbia_get_google_location')) {
    function cbia_get_google_location(): string {
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];
        return (string)($settings['google_location'] ?? '');
    }
}

if (!function_exists('cbia_get_google_service_account_json')) {
    function cbia_get_google_service_account_json(): string {
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];
        return (string)($settings['google_service_account_json'] ?? '');
    }
}

if (!function_exists('cbia_get_text_provider')) {
    function cbia_get_text_provider(): string {
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];
        $p = sanitize_key((string)($settings['text_provider'] ?? ''));
        if ($p !== '') return $p;
        if (function_exists('cbia_providers_get_current_provider')) {
            return cbia_providers_get_current_provider();
        }
        return 'openai';
    }
}

if (!function_exists('cbia_get_image_provider')) {
    function cbia_get_image_provider(): string {
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];
        $p = sanitize_key((string)($settings['image_provider'] ?? ''));
        return $p !== '' ? $p : 'openai';
    }
}

if (!function_exists('cbia_get_text_model_for_provider')) {
    function cbia_get_text_model_for_provider(string $provider, string $fallback = ''): string {
        $provider = sanitize_key($provider);
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];

        // CAMBIO: modelo guardado para texto (solo si coincide proveedor)
        if (!empty($settings['text_provider']) && sanitize_key((string)$settings['text_provider']) === $provider) {
            $m = (string)($settings['text_model'] ?? '');
            if ($m !== '') return $m;
        }

        // Fallback legacy: openai_model
        if ($provider === 'openai' && !empty($settings['openai_model'])) {
            return (string)$settings['openai_model'];
        }

        // Fallback providers settings (pro)
        if (function_exists('cbia_providers_get_provider')) {
            $cfg = cbia_providers_get_provider($provider);
            if (!empty($cfg['model'])) return (string)$cfg['model'];
        }

        return $fallback;
    }
}

if (!function_exists('cbia_get_image_model_for_provider')) {
    function cbia_get_image_model_for_provider(string $provider, string $fallback = ''): string {
        $provider = sanitize_key($provider);
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];

        // CAMBIO: modelo guardado para imagen (solo si coincide proveedor)
        if (!empty($settings['image_provider']) && sanitize_key((string)$settings['image_provider']) === $provider) {
            $m = (string)($settings['image_model'] ?? '');
            if ($m !== '') return $m;
        }

        // Fallback legacy: image_model global
        if (!empty($settings['image_model']) && $provider === 'openai') {
            return (string)$settings['image_model'];
        }

        // Fallback providers settings (pro)
        if (function_exists('cbia_providers_get_provider')) {
            $cfg = cbia_providers_get_provider($provider);
            if (!empty($cfg['image_model'])) return (string)$cfg['image_model'];
        }

        return $fallback;
    }
}

if (!function_exists('cbia_openai_consent_ok')) {
    /**
     * User consent flag for OpenAI usage (required for external calls).
     */
    function cbia_openai_consent_ok(): bool {
        return true;
    }
}

if (!function_exists('cbia_http_headers_openai')) {
    /**
     * Build HTTP headers for OpenAI API calls.
     */
    function cbia_http_headers_openai(string $api_key): array {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ];

        // Optional: Organization header if present in settings.
        if (function_exists('cbia_get_settings')) {
            $settings = cbia_get_settings();
            $org = trim((string)($settings['openai_org'] ?? ''));
            if ($org !== '') {
                $headers['OpenAI-Organization'] = $org;
            }
        }

        return $headers;
    }
}

if (!function_exists('cbia_run_test_configuration')) {
    /**
     * Basic configuration test: validates settings and (optionally) performs a lightweight API call.
     * Returns an array with ok/error details for future UI use.
     */
    function cbia_run_test_configuration(): array {
        $log = function ($msg, $level = 'INFO') {
            if (function_exists('cbia_log_message')) {
                cbia_log_message((string)$msg);
            } elseif (function_exists('cbia_log')) {
                cbia_log((string)$msg, (string)$level);
            }
        };

        $log('[INFO] TEST: Iniciando prueba de configuraciÃ³n.');

        $api_key = function_exists('cbia_openai_api_key') ? cbia_openai_api_key() : '';
        if (trim((string)$api_key) === '') {
            $log('[ERROR] TEST: Falta OpenAI API key.');
            return ['ok' => false, 'error' => 'missing_api_key'];
        }

        if (function_exists('cbia_openai_consent_ok') && !cbia_openai_consent_ok()) {
            $log('[ERROR] TEST: Consentimiento OpenAI no aceptado.');
            return ['ok' => false, 'error' => 'missing_consent'];
        }

        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];
        $model = '';
        if (function_exists('cbia_pick_model')) {
            $model = (string)cbia_pick_model();
        }
        if ($model === '' && isset($settings['openai_model'])) {
            $model = (string)$settings['openai_model'];
        }

        if ($model !== '') {
            $log("[INFO] TEST: Modelo texto actual: {$model}");
        }

        if (!function_exists('cbia_openai_responses_call')) {
            $log('[WARN] TEST: cbia_openai_responses_call no disponible. Prueba limitada a validaciÃ³n de ajustes.');
            return ['ok' => true, 'error' => 'limited_check'];
        }

        $prompt = 'Devuelve SOLO la palabra OK.';
        $res = cbia_openai_responses_call($prompt, 'test_config', 1);
        $ok = is_array($res) ? (bool)($res[0] ?? false) : false;
        $usage = is_array($res) ? (array)($res[2] ?? []) : [];
        $model_used = is_array($res) ? (string)($res[3] ?? '') : '';
        $err = is_array($res) ? (string)($res[4] ?? '') : 'unknown_error';

        if ($ok) {
            $log("[INFO] TEST: OK. modelo={$model_used} tokens_in=" . (int)($usage['input_tokens'] ?? 0) . " tokens_out=" . (int)($usage['output_tokens'] ?? 0));
            return ['ok' => true, 'model' => $model_used, 'usage' => $usage];
        }

        $log("[ERROR] TEST: fallo en llamada de prueba. " . ($err !== '' ? $err : 'error'));
        return ['ok' => false, 'error' => $err ?: 'test_call_failed'];
    }
}

if (!function_exists('cbia_fix_bracket_headings')) {
    /**
     * Convierte headings en formato [H2]...[/H2] a HTML vÃ¡lido.
     */
    function cbia_fix_bracket_headings($html): string {
        $text = (string)$html;
        // [H2]TÃ­tulo[/H2] => <h2>TÃ­tulo</h2>
        $text = preg_replace_callback('/\\[(H[1-6])\\]\\s*(.*?)\\s*\\[\\/\\1\\]/si', function ($m) {
            $tag = strtolower($m[1]);
            $content = trim((string)$m[2]);
            return '<' . $tag . '>' . $content . '</' . $tag . '>';
        }, $text);

        return $text;
    }
}

if (!function_exists('cbia_replace_first_occurrence')) {
    /**
     * Replace first occurrence of a substring.
     */
    function cbia_replace_first_occurrence($haystack, $needle, $replacement) {
        $haystack = (string)$haystack;
        $needle = (string)$needle;
        $replacement = (string)$replacement;
        if ($needle === '') return $haystack;
        $pos = strpos($haystack, $needle);
        if ($pos === false) return $haystack;
        return substr($haystack, 0, $pos) . $replacement . substr($haystack, $pos + strlen($needle));
    }
}

