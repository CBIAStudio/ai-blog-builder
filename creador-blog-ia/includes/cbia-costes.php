<?php
/**
 * CBIA - Costes (estimación + cálculo post-hoc)
 * v12 (FIX: imágenes con precio fijo + botón "solo coste real" + tokens reales en log)
 *
 * Archivo: includes/cbia-costes.php
 *
 * OBJETIVO:
 * - Estimación sencilla por post: TEXTO + IMÁGENES + SEO (si hay llamadas de relleno Yoast/SEO)
 * - Cálculo REAL post-hoc: suma el coste POR CADA LLAMADA guardada en _cbia_usage_rows,
 *   respetando el modelo real usado en cada llamada (texto vs imagen vs seo) y su tabla de precios.
 *
 * IMPORTANTE:
 * - Para que el cálculo REAL funcione, el engine/yoast debe llamar a:
 *   cbia_costes_record_usage($post_id, [...])
 *   en CADA llamada a OpenAI (texto / imagen / seo).
 *
 * - Este archivo NO “adivina” tokens reales de imágenes si no se registran. Solo estima si faltan.
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
   ===================== SETTINGS (COSTES) ==================
   ========================================================= */
if (!function_exists('cbia_costes_settings_key')) {
    function cbia_costes_settings_key() { return 'cbia_costes_settings'; }
}

if (!function_exists('cbia_costes_get_settings')) {
    function cbia_costes_get_settings() {
        $s = get_option(cbia_costes_settings_key(), array());
        return is_array($s) ? $s : array();
    }
}

if (!function_exists('cbia_costes_register_settings')) {
    function cbia_costes_register_settings() {
        register_setting('cbia_costes_settings_group', cbia_costes_settings_key());
    }
    add_action('admin_init', 'cbia_costes_register_settings');
}

/* =========================================================
   ========================= LOG ============================
   ========================================================= */
if (!function_exists('cbia_costes_log_key')) {
    function cbia_costes_log_key() { return 'cbia_costes_log'; }
}
if (!function_exists('cbia_costes_log')) {
    function cbia_costes_log($msg) {
        if (function_exists('cbia_log')) {
            cbia_log('[COSTES] ' . (string)$msg, 'INFO');
            return;
        }
        $log = get_option(cbia_costes_log_key(), '');
        $ts  = current_time('mysql');
        $log .= "[{$ts}] COSTES: {$msg}\n";
        if (strlen($log) > 250000) $log = substr($log, -250000);
        update_option(cbia_costes_log_key(), $log);
        if (function_exists('error_log')) error_log('[CBIA-COSTES] ' . $msg);
    }
}
if (!function_exists('cbia_costes_log_get')) {
    function cbia_costes_log_get() {
        if (function_exists('cbia_get_log')) {
            $payload = cbia_get_log();
            return is_array($payload) ? (string)($payload['log'] ?? '') : (string)$payload;
        }
        return (string)get_option(cbia_costes_log_key(), '');
    }
}
if (!function_exists('cbia_costes_log_clear')) {
    function cbia_costes_log_clear() {
        if (function_exists('cbia_clear_log')) {
            cbia_clear_log();
            return;
        }
        delete_option(cbia_costes_log_key());
    }
}

/* =========================================================
   ===================== HELPERS (global) ===================
   ========================================================= */
if (!function_exists('cbia_get_settings')) {
    function cbia_get_settings() {
        $s = get_option('cbia_settings', array());
        return is_array($s) ? $s : array();
    }
}

/* =========================================================
   =============== BLOQUEO MODELOS (desde Config) ============
   ========================================================= */
if (!function_exists('cbia_costes_is_model_blocked')) {
    function cbia_costes_is_model_blocked($model) {
        $cbia = cbia_get_settings();
        $blocked = isset($cbia['blocked_models']) && is_array($cbia['blocked_models']) ? $cbia['blocked_models'] : array();
        $model = (string)$model;

        if (isset($blocked[$model]) && (int)$blocked[$model] === 1) return true;

        // por si alguna vez viene como lista
        if (in_array($model, array_keys($blocked), true)) return true;

        return false;
    }
}

/* =========================================================
   ===================== TABLA DE PRECIOS ===================
   Valores en USD por 1.000.000 tokens (1M)
   SOLO modelos usados en el plugin (según tu Config actual):
   - Texto/SEO: gpt-4.1*, gpt-5*, gpt-5.1, gpt-5.2
   - Imagen: gpt-image-1, gpt-image-1-mini
   ========================================================= */
if (!function_exists('cbia_costes_price_table_usd_per_million')) {
    function cbia_costes_price_table_usd_per_million() {
        // input, cached_input, output  (USD por 1M tokens)
        return array(
            // TEXTO / SEO
            'gpt-4.1'       => array('in'=>2.00,  'cin'=>0.50,  'out'=>8.00),
            'gpt-4.1-mini'  => array('in'=>0.40,  'cin'=>0.10,  'out'=>1.60),
            'gpt-4.1-nano'  => array('in'=>0.10,  'cin'=>0.025, 'out'=>0.40),

            'gpt-5'         => array('in'=>1.25,  'cin'=>0.125, 'out'=>10.00),
            'gpt-5-mini'    => array('in'=>0.25,  'cin'=>0.025, 'out'=>2.00),
            'gpt-5-nano'    => array('in'=>0.05,  'cin'=>0.005, 'out'=>0.40),

            'gpt-5.1'       => array('in'=>1.25,  'cin'=>0.125, 'out'=>10.00),
            'gpt-5.2'       => array('in'=>1.75,  'cin'=>0.175, 'out'=>14.00),

            // IMAGEN (solo para estimación basada en tokens; por defecto usaremos tarifa fija)
            'gpt-image-1'       => array('in'=>10.00, 'cin'=>2.50, 'out'=>40.00),
            'gpt-image-1-mini'  => array('in'=>2.50,  'cin'=>0.25, 'out'=>8.00),
        );
    }
}

/* =========================================================
   ======= PRECIOS FIJOS POR IMAGEN (USD por generación) ===
   ========================================================= */
if (!function_exists('cbia_costes_image_flat_price_usd')) {
    function cbia_costes_image_flat_price_usd($model, $cost_settings) {
        $model = (string)$model;
        $def_mini = isset($cost_settings['image_flat_usd_mini']) ? (float)$cost_settings['image_flat_usd_mini'] : 0.040; // editable
        $def_full = isset($cost_settings['image_flat_usd_full']) ? (float)$cost_settings['image_flat_usd_full'] : 0.080; // editable
        if ($model === 'gpt-image-1-mini') return $def_mini;
        if ($model === 'gpt-image-1') return $def_full;
        // fallback: si no reconocemos el modelo, usar mini
        return $def_mini;
    }
}

/* =========================================================
   ============== ESTIMACIÓN: palabras -> tokens ============
   ========================================================= */
if (!function_exists('cbia_costes_words_for_variant')) {
    function cbia_costes_words_for_variant($variant) {
        $variant = (string)$variant;
        if ($variant === 'short') return 1000;
        if ($variant === 'long')  return 2200;
        return 1700;
    }
}

if (!function_exists('cbia_costes_count_words')) {
    function cbia_costes_count_words($text) {
        $txt = wp_strip_all_tags((string)$text);
        $txt = preg_replace('/\s+/u', ' ', $txt);
        $txt = trim($txt);
        if ($txt === '') return 0;
        return count(preg_split('/\s+/u', $txt));
    }
}

if (!function_exists('cbia_costes_words_to_tokens')) {
    function cbia_costes_words_to_tokens($words, $tokens_per_word = 1.30) {
        $w = max(0, (float)$words);
        $tpw = max(0.5, min(2.0, (float)$tokens_per_word));
        return (int)ceil($w * $tpw);
    }
}

if (!function_exists('cbia_costes_estimate_input_tokens')) {
    function cbia_costes_estimate_input_tokens($title, $settings_cbia, $tokens_per_word, $input_overhead_tokens) {
        $prompt = isset($settings_cbia['prompt_single_all']) ? (string)$settings_cbia['prompt_single_all'] : '';
        $words_prompt = cbia_costes_count_words($prompt);
        $words_title  = cbia_costes_count_words((string)$title);

        $tokens = cbia_costes_words_to_tokens($words_prompt + $words_title, $tokens_per_word);
        $tokens += (int)max(0, (int)$input_overhead_tokens);
        return $tokens;
    }
}

if (!function_exists('cbia_costes_estimate_output_tokens')) {
    function cbia_costes_estimate_output_tokens($settings_cbia, $tokens_per_word) {
        $variant = $settings_cbia['post_length_variant'] ?? 'medium';
        $words = cbia_costes_words_for_variant($variant);
        return cbia_costes_words_to_tokens($words, $tokens_per_word);
    }
}

if (!function_exists('cbia_costes_estimate_image_prompt_input_tokens_per_call')) {
    function cbia_costes_estimate_image_prompt_input_tokens_per_call($settings_cbia, $tokens_per_word, $per_image_overhead_words) {
        $p_intro = (string)($settings_cbia['prompt_img_intro'] ?? '');
        $p_body  = (string)($settings_cbia['prompt_img_body'] ?? '');
        $p_conc  = (string)($settings_cbia['prompt_img_conclusion'] ?? '');
        $p_faq   = (string)($settings_cbia['prompt_img_faq'] ?? '');

        $sum_words = 0;
        $sum_words += max(10, cbia_costes_count_words($p_intro));
        $sum_words += max(10, cbia_costes_count_words($p_body));
        $sum_words += max(10, cbia_costes_count_words($p_conc));
        $sum_words += max(10, cbia_costes_count_words($p_faq));

        $avg_words = (int)ceil($sum_words / 4);
        $avg_words += (int)max(0, (int)$per_image_overhead_words);

        return cbia_costes_words_to_tokens($avg_words, $tokens_per_word);
    }
}

/* =========================================================
   ===================== CÁLCULO DE COSTE ===================
   ========================================================= */
if (!function_exists('cbia_costes_calc_cost_eur')) {
    /**
     * @param string $model
     * @param int $in_tokens
     * @param int $out_tokens
     * @param float $usd_to_eur
     * @param float $cached_input_ratio 0..1 parte de input que se cobra como cached_input
     * @return array [eur_total, eur_in, eur_out]
     */
    function cbia_costes_calc_cost_eur($model, $in_tokens, $out_tokens, $usd_to_eur, $cached_input_ratio = 0.0) {
        $table = cbia_costes_price_table_usd_per_million();
        $model = (string)$model;

        if (!isset($table[$model])) return array(null, null, null);

        $p = $table[$model];
        $usd_in_per_m  = (float)$p['in'];
        $usd_cin_per_m = (float)$p['cin'];
        $usd_out_per_m = (float)$p['out'];

        $in_tokens  = max(0, (int)$in_tokens);
        $out_tokens = max(0, (int)$out_tokens);

        $ratio = (float)$cached_input_ratio;
        if ($ratio < 0) $ratio = 0;
        if ($ratio > 1) $ratio = 1;

        $in_cached = (int)floor($in_tokens * $ratio);
        $in_normal = $in_tokens - $in_cached;

        $usd_in  = ($in_normal / 1000000.0) * $usd_in_per_m;
        $usd_in += ($in_cached / 1000000.0) * $usd_cin_per_m;

        $usd_out = ($out_tokens / 1000000.0) * $usd_out_per_m;

        $usd_total = $usd_in + $usd_out;

        $eur_total = $usd_total * (float)$usd_to_eur;
        $eur_in    = $usd_in    * (float)$usd_to_eur;
        $eur_out   = $usd_out   * (float)$usd_to_eur;

        return array($eur_total, $eur_in, $eur_out);
    }
}

/* =========================================================
   ====== GUARDAR USAGE REAL POR POST (engine debe llamar) ===
   ========================================================= */
if (!function_exists('cbia_costes_record_usage')) {
    /**
     * Guarda una fila de usage por llamada.
     *
     * type: 'text' | 'image' | 'seo' (seo se trata como texto a nivel de pricing)
     * model: modelo real usado
     * input_tokens / output_tokens: tokens reales
     * cached_input_tokens: si lo tienes (si no, 0)
     */
    function cbia_costes_record_usage($post_id, $usage) {
        $post_id = (int)$post_id;
        if ($post_id <= 0) return false;
        if (!is_array($usage)) return false;

        $type  = isset($usage['type']) ? (string)$usage['type'] : 'text';
        $model = isset($usage['model']) ? (string)$usage['model'] : '';
        $in_t  = isset($usage['input_tokens']) ? (int)$usage['input_tokens'] : 0;
        $out_t = isset($usage['output_tokens']) ? (int)$usage['output_tokens'] : 0;
        $cin_t = isset($usage['cached_input_tokens']) ? (int)$usage['cached_input_tokens'] : 0;
        $ok    = !empty($usage['ok']) ? 1 : 0;
        $attempt = isset($usage['attempt']) ? (int)$usage['attempt'] : 1;
        $err   = isset($usage['error']) ? (string)$usage['error'] : '';

        // normaliza type
        $type = strtolower(trim($type));
        if ($type !== 'image' && $type !== 'seo') $type = 'text';

        $row = array(
            'ts' => current_time('mysql'),
            'type' => $type,
            'model' => $model,
            'in' => max(0, $in_t),
            'cin' => max(0, $cin_t),
            'out' => max(0, $out_t),
            'ok' => $ok,
            'attempt' => max(1, $attempt),
            'error' => $err,
        );

        $key = '_cbia_usage_rows';
        $rows = get_post_meta($post_id, $key, true);
        if (!is_array($rows)) $rows = array();
        $rows[] = $row;

        if (count($rows) > 200) $rows = array_slice($rows, -200);

        update_post_meta($post_id, $key, $rows);
        update_post_meta($post_id, '_cbia_usage_last_ts', $row['ts']);
        update_post_meta($post_id, '_cbia_usage_last_model', $model);

        return true;
    }
}

/* =========================================================
   ========= REAL: calcular coste por post sumando filas =====
   ========================================================= */
if (!function_exists('cbia_costes_get_usage_rows_for_post')) {
    function cbia_costes_get_usage_rows_for_post($post_id) {
        $rows = get_post_meta((int)$post_id, '_cbia_usage_rows', true);
        return is_array($rows) ? $rows : array();
    }
}


/* =========================================================
   ===== AJUSTE AUTOMÁTICO POR MODELO (opcional) ============
   ========================================================= */
if (!function_exists('cbia_costes_pick_primary_text_model')) {
    function cbia_costes_pick_primary_text_model($rows, $cbia_settings = array()) {
        $counts = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $type = isset($r['type']) ? strtolower(trim((string)$r['type'])) : 'text';
                if ($type !== 'text' && $type !== 'seo') continue;
                $model = isset($r['model']) ? (string)$r['model'] : '';
                if ($model === '') continue;
                if (!isset($counts[$model])) $counts[$model] = 0;
                $counts[$model]++;
            }
        }

        if (!empty($counts)) {
            arsort($counts);
            $top = array_key_first($counts);
            if (is_string($top) && $top !== '') return $top;
        }

        $fallback = (string)($cbia_settings['openai_model'] ?? '');
        return $fallback;
    }
}

if (!function_exists('cbia_costes_get_model_multiplier')) {
    function cbia_costes_get_model_multiplier($model, $cost_settings) {
        $model = (string)$model;
        $map = isset($cost_settings['real_adjust_multiplier_by_model']) && is_array($cost_settings['real_adjust_multiplier_by_model'])
            ? $cost_settings['real_adjust_multiplier_by_model']
            : array();

        if ($model === '' || empty($map) || !isset($map[$model])) return 1.0;

        $mult = (float)$map[$model];
        if ($mult <= 0) return 1.0;
        return $mult;
    }
}
if (!function_exists('cbia_costes_calc_real_for_post')) {
    /**
     * Devuelve:
     * [
     *   'eur' => float,
     *   'calls' => int,
     *   'fails' => int,
     *   'by_type' => ['text'=>['eur'=>..,'calls'=>..], 'seo'=>..., 'image'=>...],
     *   'by_model' => ['gpt-4.1-mini'=>['eur'=>..,'calls'=>..], ...],
     * ]
     * o null si no hay filas.
     */
    function cbia_costes_calc_real_for_post($post_id, $cost_settings, $cbia_settings = array()) {
        $rows = cbia_costes_get_usage_rows_for_post((int)$post_id);
        if (empty($rows)) return null;

        $table = cbia_costes_price_table_usd_per_million();
        $usd_to_eur = (float)($cost_settings['usd_to_eur'] ?? 0.92);
        $fallback_ratio = (float)($cost_settings['cached_input_ratio'] ?? 0.0);
        $use_image_flat = !empty($cost_settings['use_image_flat_pricing']);
        $resp_fixed_usd = (float)($cost_settings['responses_fixed_usd_per_call'] ?? 0.0);
        $real_mult = (float)($cost_settings['real_adjust_multiplier'] ?? 1.0);

        // Ajuste automático por modelo (solo si el multiplicador global está en 1.0)
        $primary_text_model = cbia_costes_pick_primary_text_model($rows, $cbia_settings);
        $model_mult = cbia_costes_get_model_multiplier($primary_text_model, $cost_settings);

        $sum_eur = 0.0;
        $calls = 0;
        $fails = 0;
        $sum_in_tokens = 0; // para log
        $sum_out_tokens = 0; // para log

        $by_type = array(
            'text' => array('eur'=>0.0,'calls'=>0),
            'seo'  => array('eur'=>0.0,'calls'=>0),
            'image'=> array('eur'=>0.0,'calls'=>0),
        );
        $by_model = array();
        $resp_calls_count = 0; // text+seo

        foreach ($rows as $r) {
            if (!is_array($r)) continue;

            $type = isset($r['type']) ? strtolower(trim((string)$r['type'])) : 'text';
            if ($type !== 'image' && $type !== 'seo') $type = 'text';

            $model = isset($r['model']) ? (string)$r['model'] : '';
            $in    = isset($r['in']) ? (int)$r['in'] : 0;
            $cin   = isset($r['cin']) ? (int)$r['cin'] : 0;
            $out   = isset($r['out']) ? (int)$r['out'] : 0;
            $ok    = !empty($r['ok']) ? 1 : 0;

            $calls++;
            if (!$ok) $fails++;
            if ($type === 'text' || $type === 'seo') $resp_calls_count++;

            // Tokens acumulados para log (texto/seo). Para imagen normalmente 0.
            $sum_in_tokens += (int)$in;
            $sum_out_tokens += (int)$out;

            // IMÁGENES: si está activa la tarifa plana, sumar por generación OK
            if ($type === 'image' && $ok && $use_image_flat) {
                $usd = (float)cbia_costes_image_flat_price_usd($model, $cost_settings);
                $sum_eur += $usd * $usd_to_eur;

                if (!isset($by_type[$type])) $by_type[$type] = array('eur'=>0.0,'calls'=>0);
                $by_type[$type]['eur'] += $usd * $usd_to_eur;
                $by_type[$type]['calls']++;

                if (!isset($by_model[$model])) $by_model[$model] = array('eur'=>0.0,'calls'=>0);
                $by_model[$model]['eur'] += $usd * $usd_to_eur;
                $by_model[$model]['calls']++;
                continue;
            }

            // Si no tenemos modelo en tabla, no podemos calcular esa fila (texto/seo o imagen sin flat)
            if ($model === '' || !isset($table[$model])) continue;

            $in = max(0, $in);
            $cin = max(0, $cin);
            $out = max(0, $out);

            $ratio = $fallback_ratio;
            if ($in > 0 && $cin > 0) {
                $ratio = min(1.0, max(0.0, $cin / (float)max(1, $in)));
            }

            list($eur, $eur_in, $eur_out) = cbia_costes_calc_cost_eur($model, $in, $out, $usd_to_eur, $ratio);
            if ($eur === null) continue;

            $sum_eur += (float)$eur;

            if (!isset($by_type[$type])) $by_type[$type] = array('eur'=>0.0,'calls'=>0);
            $by_type[$type]['eur'] += (float)$eur;
            $by_type[$type]['calls']++;

            if (!isset($by_model[$model])) $by_model[$model] = array('eur'=>0.0,'calls'=>0);
            $by_model[$model]['eur'] += (float)$eur;
            $by_model[$model]['calls']++;
        }

        // Añadir sobrecoste fijo por llamada de texto/SEO (en USD)
        if ($resp_fixed_usd > 0 && $resp_calls_count > 0) {
            $sum_eur += ($resp_fixed_usd * $resp_calls_count) * $usd_to_eur;
        }

        // Multiplicador de ajuste final
        $final_mult = (float)$real_mult;
        if (($final_mult === 1.0 || $final_mult <= 0) && $model_mult > 0 && $model_mult != 1.0) {
            $final_mult = (float)$model_mult;
        }
        if ($final_mult > 0 && $final_mult != 1.0) {
            $sum_eur *= $final_mult;
        }

        return array(
            'eur' => (float)$sum_eur,
            'calls' => (int)$calls,
            'fails' => (int)$fails,
            'by_type' => $by_type,
            'by_model' => $by_model,
            'in_tokens' => (int)$sum_in_tokens,
            'out_tokens' => (int)$sum_out_tokens,
        );
    }
}

/* =========================================================
   ===================== ESTIMACIÓN POR POST =================
   Incluye: TEXTO + IMAGEN + SEO
   ========================================================= */
if (!function_exists('cbia_costes_estimate_for_post')) {
    function cbia_costes_estimate_for_post($post_id, $cost_settings, $cbia_settings) {
        $table = cbia_costes_price_table_usd_per_million();

        $title = get_the_title((int)$post_id);
        if (!$title) $title = '{title}';

        // Modelos
        $model_text = (string)($cbia_settings['openai_model'] ?? 'gpt-4.1-mini');
        if (!isset($table[$model_text])) $model_text = 'gpt-4.1-mini';

        $model_seo = (string)($cost_settings['seo_model'] ?? $model_text);
        if (!isset($table[$model_seo])) $model_seo = $model_text;

        $model_img = (string)($cost_settings['image_model'] ?? 'gpt-image-1-mini');
        if (!isset($table[$model_img])) $model_img = 'gpt-image-1-mini';

        // Llamadas por post
        $text_calls = max(1, (int)($cost_settings['text_calls_per_post'] ?? 1));

        $img_calls  = (int)($cost_settings['image_calls_per_post'] ?? 0);
        if ($img_calls <= 0) {
            $img_calls = isset($cbia_settings['images_limit']) ? (int)$cbia_settings['images_limit'] : 3;
        }
        $img_calls = max(0, min(20, $img_calls));

        $seo_calls = max(0, (int)($cost_settings['seo_calls_per_post'] ?? 0));
        $seo_calls = min(20, $seo_calls);

        $usd_to_eur = (float)($cost_settings['usd_to_eur'] ?? 0.92);
        $cached_ratio = (float)($cost_settings['cached_input_ratio'] ?? 0.0);

        /* ===== TEXTO ===== */
        $in_text  = cbia_costes_estimate_input_tokens($title, $cbia_settings, (float)$cost_settings['tokens_per_word'], (int)$cost_settings['input_overhead_tokens']);
        $out_text = cbia_costes_estimate_output_tokens($cbia_settings, (float)$cost_settings['tokens_per_word']);

        $in_text  = (int)ceil($in_text  * (float)$cost_settings['mult_text']);
        $out_text = (int)ceil($out_text * (float)$cost_settings['mult_text']);

        $in_text_total  = $in_text  * $text_calls;
        $out_text_total = $out_text * $text_calls;

        list($eur_text, $eur_in_text, $eur_out_text) =
            cbia_costes_calc_cost_eur($model_text, $in_text_total, $out_text_total, $usd_to_eur, $cached_ratio);
        if ($eur_text === null) return null;

        /* ===== IMAGEN ===== */
        $in_img_per_call = cbia_costes_estimate_image_prompt_input_tokens_per_call($cbia_settings, (float)$cost_settings['tokens_per_word'], (int)$cost_settings['per_image_overhead_words']);
        $out_img_per_call = max(0, (int)($cost_settings['image_output_tokens_per_call'] ?? 0));

        $in_img_per_call  = (int)ceil($in_img_per_call  * (float)$cost_settings['mult_image']);
        $out_img_per_call = (int)ceil($out_img_per_call * (float)$cost_settings['mult_image']);

        $in_img_total  = $in_img_per_call  * $img_calls;
        $out_img_total = $out_img_per_call * $img_calls;

        $use_image_flat = !empty($cost_settings['use_image_flat_pricing']);
        if ($use_image_flat) {
            $usd_flat = (float)cbia_costes_image_flat_price_usd($model_img, $cost_settings);
            $eur_img = $img_calls * $usd_flat * $usd_to_eur;
            $eur_in_img = 0.0; $eur_out_img = 0.0;
        } else {
            list($eur_img, $eur_in_img, $eur_out_img) =
                cbia_costes_calc_cost_eur($model_img, $in_img_total, $out_img_total, $usd_to_eur, $cached_ratio);
            if ($eur_img === null) $eur_img = 0.0;
        }

        /* ===== SEO ===== */
        $seo_in_per_call  = max(0, (int)($cost_settings['seo_input_tokens_per_call'] ?? 0));
        $seo_out_per_call = max(0, (int)($cost_settings['seo_output_tokens_per_call'] ?? 0));

        $seo_in_per_call  = (int)ceil($seo_in_per_call  * (float)$cost_settings['mult_seo']);
        $seo_out_per_call = (int)ceil($seo_out_per_call * (float)$cost_settings['mult_seo']);

        $seo_in_total  = $seo_in_per_call  * $seo_calls;
        $seo_out_total = $seo_out_per_call * $seo_calls;

        $eur_seo = 0.0;
        if ($seo_calls > 0 && ($seo_in_total > 0 || $seo_out_total > 0)) {
            list($eur_seo_calc, $eur_in_seo, $eur_out_seo) =
                cbia_costes_calc_cost_eur($model_seo, $seo_in_total, $seo_out_total, $usd_to_eur, $cached_ratio);
            if ($eur_seo_calc !== null) $eur_seo = (float)$eur_seo_calc;
        }

        return (float)$eur_text + (float)$eur_img + (float)$eur_seo;
    }
}

/* =========================================================
   ======================= AJAX LOG =========================
   ========================================================= */
add_action('wp_ajax_cbia_get_costes_log', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    nocache_headers();
    if (function_exists('cbia_get_log')) {
        wp_send_json_success(cbia_get_log());
    }
    wp_send_json_success(cbia_costes_log_get());
});

/* =========================================================
   ============ CÁLCULO ÚLTIMOS POSTS (real/estimado) =======
   ========================================================= */
if (!function_exists('cbia_costes_calc_last_posts')) {
    function cbia_costes_calc_last_posts($n, $only_cbia, $use_est_if_missing, $cost_settings, $cbia_settings) {
        $n = max(1, min(200, (int)$n));

        $args = array(
            'post_type'      => 'post',
            'posts_per_page' => $n,
            'post_status'    => array('publish','future','draft','pending'),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        );

        if ($only_cbia) {
            $args['meta_query'] = array(
                array('key' => '_cbia_created', 'value' => '1', 'compare' => '=')
            );
        }

        $q = new WP_Query($args);
        $ids = !empty($q->posts) ? $q->posts : array();
        if (empty($ids)) return null;

        $total_eur = 0.0;
        $real_posts = 0;
        $est_posts  = 0;

        $real_calls = 0;
        $real_fails = 0;
        $tok_in_sum = 0;
        $tok_out_sum = 0;

        foreach ($ids as $post_id) {
            $post_id = (int)$post_id;

            // 1) REAL: suma por filas (modelo real por llamada)
            $real = cbia_costes_calc_real_for_post($post_id, $cost_settings, $cbia_settings);
            if (is_array($real)) {
                $total_eur += (float)$real['eur'];
                $real_posts++;
                $real_calls += (int)$real['calls'];
                $real_fails += (int)$real['fails'];
                $tok_in_sum += (int)$real['in_tokens'];
                $tok_out_sum += (int)$real['out_tokens'];
                continue;
            }

            // 2) ESTIMACIÓN
            if ($use_est_if_missing) {
                $est = cbia_costes_estimate_for_post($post_id, $cost_settings, $cbia_settings);
                if ($est !== null) {
                    $total_eur += (float)$est;
                    $est_posts++;
                }
            }
        }

        return array(
            'posts' => count($ids),
            'real_posts' => $real_posts,
            'est_posts' => $est_posts,
            'eur_total' => $total_eur,
            'real_calls' => $real_calls,
            'real_fails' => $real_fails,
            'tokens_in' => $tok_in_sum,
            'tokens_out'=> $tok_out_sum,
        );
    }
}

/* =========================================================
   ===================== UI TAB: COSTES =====================
   ========================================================= */
if (!function_exists('cbia_render_tab_costes')) {
    function cbia_render_tab_costes() {
        if (!current_user_can('manage_options')) return;

        $cbia = cbia_get_settings();
        $cost = cbia_costes_get_settings();

        $defaults = array(
            'usd_to_eur' => 0.92,
            'tokens_per_word' => 1.30,
            'input_overhead_tokens' => 350,
            'per_image_overhead_words' => 18,
            'cached_input_ratio' => 0.0, // 0..1
            // Imágenes: usar precio fijo por generación (recomendado)
            'use_image_flat_pricing' => 1,
            'image_flat_usd_mini' => 0.040,
            'image_flat_usd_full' => 0.080,
            // Ajustes finos
            'responses_fixed_usd_per_call' => 0.000,
            'real_adjust_multiplier' => 1.00,
            // Ajuste automático por modelo (solo si el multiplicador REAL está en 1.0)
            'real_adjust_multiplier_by_model' => array(
                'gpt-5-mini' => 1.12,
                'gpt-5.1-mini' => 1.12,
            ),

            // Multiplicadores para aproximar fallos/reintentos
            'mult_text'  => 1.00,
            'mult_image' => 1.00,
            'mult_seo'   => 1.00,

            // llamadas por post (estimación)
            'text_calls_per_post'  => 1,
            'image_calls_per_post' => 0, // 0 => usa images_limit

            // modelo imagen
            'image_model' => 'gpt-image-1-mini',

            // output tokens por llamada de imagen (opcional)
            'image_output_tokens_per_call' => 0,

            // SEO (relleno Yoast / metas / etc)
            'seo_calls_per_post' => 0,
            'seo_model' => '',
            'seo_input_tokens_per_call' => 320,
            'seo_output_tokens_per_call' => 180,
        );
        $cost = array_merge($defaults, $cost);

        $table = cbia_costes_price_table_usd_per_million();

        $model_text_current = isset($cbia['openai_model']) ? (string)$cbia['openai_model'] : 'gpt-4.1-mini';
        if (!isset($table[$model_text_current])) $model_text_current = 'gpt-4.1-mini';

        $model_img_current = isset($cost['image_model']) ? (string)$cost['image_model'] : 'gpt-image-1-mini';
        if (!isset($table[$model_img_current])) $model_img_current = 'gpt-image-1-mini';

        $model_seo_current = (string)($cost['seo_model'] ?? '');
        if ($model_seo_current === '' || !isset($table[$model_seo_current])) $model_seo_current = $model_text_current;

        $notice = '';
        $calibration_info = null;

        /* ===== Handle POST ===== */
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $u = wp_unslash($_POST);

            if (!empty($u['cbia_form']) && $u['cbia_form'] === 'costes_settings' && check_admin_referer('cbia_costes_settings_nonce')) {

                $cost['usd_to_eur'] = isset($u['usd_to_eur']) ? (float)str_replace(',', '.', (string)$u['usd_to_eur']) : $cost['usd_to_eur'];
                if ($cost['usd_to_eur'] <= 0) $cost['usd_to_eur'] = 0.92;

                $cost['tokens_per_word'] = isset($u['tokens_per_word']) ? (float)str_replace(',', '.', (string)$u['tokens_per_word']) : $cost['tokens_per_word'];
                if ($cost['tokens_per_word'] < 0.5) $cost['tokens_per_word'] = 0.5;
                if ($cost['tokens_per_word'] > 2.0) $cost['tokens_per_word'] = 2.0;

                $cost['input_overhead_tokens'] = isset($u['input_overhead_tokens']) ? (int)$u['input_overhead_tokens'] : (int)$cost['input_overhead_tokens'];
                if ($cost['input_overhead_tokens'] < 0) $cost['input_overhead_tokens'] = 0;
                if ($cost['input_overhead_tokens'] > 5000) $cost['input_overhead_tokens'] = 5000;

                $cost['per_image_overhead_words'] = isset($u['per_image_overhead_words']) ? (int)$u['per_image_overhead_words'] : (int)$cost['per_image_overhead_words'];
                if ($cost['per_image_overhead_words'] < 0) $cost['per_image_overhead_words'] = 0;
                if ($cost['per_image_overhead_words'] > 300) $cost['per_image_overhead_words'] = 300;

                $cost['cached_input_ratio'] = isset($u['cached_input_ratio']) ? (float)str_replace(',', '.', (string)$u['cached_input_ratio']) : (float)$cost['cached_input_ratio'];
                if ($cost['cached_input_ratio'] < 0) $cost['cached_input_ratio'] = 0;
                if ($cost['cached_input_ratio'] > 1) $cost['cached_input_ratio'] = 1;

                // Tarifa fija por imagen
                $cost['use_image_flat_pricing'] = !empty($u['use_image_flat_pricing']) ? 1 : 0;
                $cost['image_flat_usd_mini'] = isset($u['image_flat_usd_mini']) ? (float)str_replace(',', '.', (string)$u['image_flat_usd_mini']) : (float)$cost['image_flat_usd_mini'];
                if ($cost['image_flat_usd_mini'] < 0) $cost['image_flat_usd_mini'] = 0.0;
                $cost['image_flat_usd_full'] = isset($u['image_flat_usd_full']) ? (float)str_replace(',', '.', (string)$u['image_flat_usd_full']) : (float)$cost['image_flat_usd_full'];
                if ($cost['image_flat_usd_full'] < 0) $cost['image_flat_usd_full'] = 0.0;

                $cost['mult_text'] = isset($u['mult_text']) ? (float)str_replace(',', '.', (string)$u['mult_text']) : (float)$cost['mult_text'];
                if ($cost['mult_text'] < 1.0) $cost['mult_text'] = 1.0;
                if ($cost['mult_text'] > 5.0) $cost['mult_text'] = 5.0;

                $cost['mult_image'] = isset($u['mult_image']) ? (float)str_replace(',', '.', (string)$u['mult_image']) : (float)$cost['mult_image'];
                if ($cost['mult_image'] < 1.0) $cost['mult_image'] = 1.0;
                if ($cost['mult_image'] > 5.0) $cost['mult_image'] = 5.0;

                $cost['mult_seo'] = isset($u['mult_seo']) ? (float)str_replace(',', '.', (string)$u['mult_seo']) : (float)$cost['mult_seo'];
                if ($cost['mult_seo'] < 1.0) $cost['mult_seo'] = 1.0;
                if ($cost['mult_seo'] > 5.0) $cost['mult_seo'] = 5.0;

                // Ajustes finos
                $cost['responses_fixed_usd_per_call'] = isset($u['responses_fixed_usd_per_call']) ? (float)str_replace(',', '.', (string)$u['responses_fixed_usd_per_call']) : (float)$cost['responses_fixed_usd_per_call'];
                if ($cost['responses_fixed_usd_per_call'] < 0) $cost['responses_fixed_usd_per_call'] = 0.0;
                $cost['real_adjust_multiplier'] = isset($u['real_adjust_multiplier']) ? (float)str_replace(',', '.', (string)$u['real_adjust_multiplier']) : (float)$cost['real_adjust_multiplier'];
                if ($cost['real_adjust_multiplier'] < 0.5) $cost['real_adjust_multiplier'] = 0.5;
                if ($cost['real_adjust_multiplier'] > 1.5) $cost['real_adjust_multiplier'] = 1.5;

                // nº llamadas texto/imagen
                $cost['text_calls_per_post'] = isset($u['text_calls_per_post']) ? (int)$u['text_calls_per_post'] : (int)$cost['text_calls_per_post'];
                if ($cost['text_calls_per_post'] < 1) $cost['text_calls_per_post'] = 1;
                if ($cost['text_calls_per_post'] > 20) $cost['text_calls_per_post'] = 20;

                $cost['image_calls_per_post'] = isset($u['image_calls_per_post']) ? (int)$u['image_calls_per_post'] : (int)$cost['image_calls_per_post'];
                if ($cost['image_calls_per_post'] < 0) $cost['image_calls_per_post'] = 0;
                if ($cost['image_calls_per_post'] > 20) $cost['image_calls_per_post'] = 20;

                // modelo imagen (solo 2)
                $im = isset($u['image_model']) ? sanitize_text_field((string)$u['image_model']) : (string)$cost['image_model'];
                if (!isset($table[$im]) || ($im !== 'gpt-image-1' && $im !== 'gpt-image-1-mini')) {
                    $im = 'gpt-image-1-mini';
                }
                $cost['image_model'] = $im;

                // output tokens por imagen
                $cost['image_output_tokens_per_call'] = isset($u['image_output_tokens_per_call']) ? (int)$u['image_output_tokens_per_call'] : (int)$cost['image_output_tokens_per_call'];
                if ($cost['image_output_tokens_per_call'] < 0) $cost['image_output_tokens_per_call'] = 0;
                if ($cost['image_output_tokens_per_call'] > 50000) $cost['image_output_tokens_per_call'] = 50000;

                // SEO settings
                $cost['seo_calls_per_post'] = isset($u['seo_calls_per_post']) ? (int)$u['seo_calls_per_post'] : (int)$cost['seo_calls_per_post'];
                if ($cost['seo_calls_per_post'] < 0) $cost['seo_calls_per_post'] = 0;
                if ($cost['seo_calls_per_post'] > 20) $cost['seo_calls_per_post'] = 20;

                $seo_model = isset($u['seo_model']) ? sanitize_text_field((string)$u['seo_model']) : (string)$cost['seo_model'];
                if ($seo_model === '' || !isset($table[$seo_model])) $seo_model = $model_text_current;
                $cost['seo_model'] = $seo_model;

                $cost['seo_input_tokens_per_call'] = isset($u['seo_input_tokens_per_call']) ? (int)$u['seo_input_tokens_per_call'] : (int)$cost['seo_input_tokens_per_call'];
                if ($cost['seo_input_tokens_per_call'] < 0) $cost['seo_input_tokens_per_call'] = 0;
                if ($cost['seo_input_tokens_per_call'] > 50000) $cost['seo_input_tokens_per_call'] = 50000;

                $cost['seo_output_tokens_per_call'] = isset($u['seo_output_tokens_per_call']) ? (int)$u['seo_output_tokens_per_call'] : (int)$cost['seo_output_tokens_per_call'];
                if ($cost['seo_output_tokens_per_call'] < 0) $cost['seo_output_tokens_per_call'] = 0;
                if ($cost['seo_output_tokens_per_call'] > 50000) $cost['seo_output_tokens_per_call'] = 50000;

                update_option(cbia_costes_settings_key(), $cost);
                $notice = 'saved';
                cbia_costes_log("Configuración guardada.");
            }

            if (!empty($u['cbia_form']) && $u['cbia_form'] === 'costes_actions' && check_admin_referer('cbia_costes_actions_nonce')) {
                $action = isset($u['cbia_action']) ? sanitize_text_field((string)$u['cbia_action']) : '';

                if ($action === 'clear_log') {
                    cbia_costes_log_clear();
                    cbia_costes_log("Log limpiado manualmente.");
                    $notice = 'log';
                }

                if ($action === 'calc_last') {
                    $n = isset($u['calc_last_n']) ? (int)$u['calc_last_n'] : 20;
                    $n = max(1, min(200, $n));

                    $only_cbia = !empty($u['calc_only_cbia']) ? true : false;
                    $use_est_if_missing = !empty($u['calc_estimate_if_missing']) ? true : false;

                    $sum = cbia_costes_calc_last_posts($n, $only_cbia, $use_est_if_missing, $cost, $cbia);
                    if ($sum) {
                        cbia_costes_log("Cálculo últimos {$n}: posts={$sum['posts']} real={$sum['real_posts']} est={$sum['est_posts']} real_calls={$sum['real_calls']} real_fails={$sum['real_fails']} tokens_in={$sum['tokens_in']} tokens_out={$sum['tokens_out']} total€=" . number_format((float)$sum['eur_total'], 4, ',', '.'));
                    } else {
                        cbia_costes_log("Cálculo últimos {$n}: sin resultados.");
                    }
                    $notice = 'calc';
                }

                if ($action === 'calc_last_real') {
                    $n = isset($u['calc_last_n']) ? (int)$u['calc_last_n'] : 20;
                    $n = max(1, min(200, $n));
                    $only_cbia = !empty($u['calc_only_cbia']) ? true : false;
                    $sum = cbia_costes_calc_last_posts($n, $only_cbia, false, $cost, $cbia);
                    if ($sum) {
                        cbia_costes_log("Cálculo SOLO REAL últimos {$n}: posts={$sum['posts']} real={$sum['real_posts']} real_calls={$sum['real_calls']} real_fails={$sum['real_fails']} tokens_in={$sum['tokens_in']} tokens_out={$sum['tokens_out']} total€=" . number_format((float)$sum['eur_total'], 4, ',', '.'));
                    } else {
                        cbia_costes_log("Cálculo SOLO REAL últimos {$n}: sin resultados.");
                    }
                    $notice = 'calc';
                }
                if ($action === 'calibrate_real') {
                    $n = isset($u['calc_last_n']) ? (int)$u['calc_last_n'] : 20;
                    $n = max(1, min(200, $n));

                    $only_cbia = !empty($u['calc_only_cbia']) ? true : false;

                    $actual_eur = isset($u['calibrate_actual_eur'])
                        ? (float)str_replace(',', '.', (string)$u['calibrate_actual_eur'])
                        : 0.0;

                    $sum = cbia_costes_calc_last_posts($n, $only_cbia, false, $cost, $cbia);

                    if ($sum && $actual_eur > 0 && (float)$sum['eur_total'] > 0) {
                        $suggested = $actual_eur / (float)$sum['eur_total'];
                        $suggested = max(0.50, min(1.50, (float)$suggested));

                        $cost['real_adjust_multiplier'] = (float)$suggested;
                        update_option(cbia_costes_settings_key(), $cost);

                        $calibration_info = array(
                            'n' => $n,
                            'only_cbia' => $only_cbia ? 1 : 0,
                            'actual_eur' => (float)$actual_eur,
                            'estimated_eur' => (float)$sum['eur_total'],
                            'suggested' => (float)$suggested,
                        );

                        cbia_costes_log(
                            "Calibración REAL últimos {$n}: real_calc€=" . number_format((float)$sum['eur_total'], 4, ',', '.') .
                            " billing€=" . number_format((float)$actual_eur, 4, ',', '.') .
                            " -> multiplier=" . number_format((float)$suggested, 4, ',', '.')
                        );

                        $notice = 'saved';
                    } else {
                        cbia_costes_log("Calibración REAL últimos {$n}: datos insuficientes (billing o total real <= 0).");
                        $notice = 'calc';
                    }
                }
            }
        }

        // refrescar
        $cost = array_merge($defaults, cbia_costes_get_settings());
        $log  = cbia_costes_log_get();

        $model_text_current = isset($cbia['openai_model']) ? (string)$cbia['openai_model'] : 'gpt-4.1-mini';
        if (!isset($table[$model_text_current])) $model_text_current = 'gpt-4.1-mini';

        $model_img_current = isset($cost['image_model']) ? (string)$cost['image_model'] : 'gpt-image-1-mini';
        if (!isset($table[$model_img_current])) $model_img_current = 'gpt-image-1-mini';

        $model_seo_current = (string)($cost['seo_model'] ?? '');
        if ($model_seo_current === '' || !isset($table[$model_seo_current])) $model_seo_current = $model_text_current;

        // Ajuste efectivo aplicado ahora mismo (UX: hacerlo visible)
        $applied_mult = (float)($cost['real_adjust_multiplier'] ?? 1.0);
        $applied_source = 'global';
        if ($applied_mult <= 0) $applied_mult = 1.0;
        if ($applied_mult == 1.0 && function_exists('cbia_costes_get_model_multiplier')) {
            $model_mult = (float)cbia_costes_get_model_multiplier($model_text_current, $cost);
            if ($model_mult > 0 && $model_mult != 1.0) {
                $applied_mult = $model_mult;
                $applied_source = 'modelo';
            }
        }
        // llamadas por post
        $text_calls = max(1, (int)$cost['text_calls_per_post']);
        $img_calls  = (int)$cost['image_calls_per_post'];

        if ($img_calls <= 0) {
            $img_calls = isset($cbia['images_limit']) ? (int)$cbia['images_limit'] : 3;
        }
        $img_calls = max(0, min(20, $img_calls));

        $seo_calls = max(0, (int)$cost['seo_calls_per_post']);
        $seo_calls = min(20, $seo_calls);

        // Estimación tokens TEXTO por llamada
        $in_tokens_text_per_call  = cbia_costes_estimate_input_tokens('{title}', $cbia, (float)$cost['tokens_per_word'], (int)$cost['input_overhead_tokens']);
        $out_tokens_text_per_call = cbia_costes_estimate_output_tokens($cbia, (float)$cost['tokens_per_word']);

        // Imagen: input por llamada, output configurable
        $in_tokens_img_per_call   = cbia_costes_estimate_image_prompt_input_tokens_per_call($cbia, (float)$cost['tokens_per_word'], (int)$cost['per_image_overhead_words']);
        $out_tokens_img_per_call  = max(0, (int)$cost['image_output_tokens_per_call']);

        // SEO: tokens por llamada configurables
        $in_tokens_seo_per_call   = max(0, (int)$cost['seo_input_tokens_per_call']);
        $out_tokens_seo_per_call  = max(0, (int)$cost['seo_output_tokens_per_call']);

        // Multiplicadores reintentos
        $in_tokens_text_per_call_m  = (int)ceil($in_tokens_text_per_call  * (float)$cost['mult_text']);
        $out_tokens_text_per_call_m = (int)ceil($out_tokens_text_per_call * (float)$cost['mult_text']);

        $in_tokens_img_per_call_m   = (int)ceil($in_tokens_img_per_call   * (float)$cost['mult_image']);
        $out_tokens_img_per_call_m  = (int)ceil($out_tokens_img_per_call  * (float)$cost['mult_image']);

        $in_tokens_seo_per_call_m   = (int)ceil($in_tokens_seo_per_call   * (float)$cost['mult_seo']);
        $out_tokens_seo_per_call_m  = (int)ceil($out_tokens_seo_per_call  * (float)$cost['mult_seo']);

        // Totales por post
        $in_tokens_text_total  = $in_tokens_text_per_call_m  * $text_calls;
        $out_tokens_text_total = $out_tokens_text_per_call_m * $text_calls;

        $in_tokens_img_total   = $in_tokens_img_per_call_m   * $img_calls;
        $out_tokens_img_total  = $out_tokens_img_per_call_m  * $img_calls;

        $in_tokens_seo_total   = $in_tokens_seo_per_call_m   * $seo_calls;
        $out_tokens_seo_total  = $out_tokens_seo_per_call_m  * $seo_calls;

        // Costes estimados por bloque
        list($eur_total_text, $eur_in_text, $eur_out_text) =
            cbia_costes_calc_cost_eur($model_text_current, $in_tokens_text_total, $out_tokens_text_total, (float)$cost['usd_to_eur'], (float)$cost['cached_input_ratio']);

        list($eur_total_img, $eur_in_img, $eur_out_img) =
            cbia_costes_calc_cost_eur($model_img_current, $in_tokens_img_total, $out_tokens_img_total, (float)$cost['usd_to_eur'], (float)$cost['cached_input_ratio']);

        $eur_total_seo = 0.0; $eur_in_seo = 0.0; $eur_out_seo = 0.0;
        if ($seo_calls > 0 && ($in_tokens_seo_total > 0 || $out_tokens_seo_total > 0)) {
            list($eur_total_seo_tmp, $eur_in_seo_tmp, $eur_out_seo_tmp) =
                cbia_costes_calc_cost_eur($model_seo_current, $in_tokens_seo_total, $out_tokens_seo_total, (float)$cost['usd_to_eur'], (float)$cost['cached_input_ratio']);
            if ($eur_total_seo_tmp !== null) {
                $eur_total_seo = (float)$eur_total_seo_tmp;
                $eur_in_seo = (float)$eur_in_seo_tmp;
                $eur_out_seo = (float)$eur_out_seo_tmp;
            }
        }

        $eur_total_est = null;
        if ($eur_total_text !== null && $eur_total_img !== null) {
            $eur_total_est = (float)$eur_total_text + (float)$eur_total_img + (float)$eur_total_seo;
        }

        // Notices
        if ($notice === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>Configuración de Costes guardada.</p></div>';
        } elseif ($notice === 'log') {
            echo '<div class="notice notice-success is-dismissible"><p>Log limpiado.</p></div>';
        } elseif ($notice === 'calc') {
            echo '<div class="notice notice-success is-dismissible"><p>Cálculo ejecutado. Revisa el log.</p></div>';
        }

        if (is_array($calibration_info)) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Calibración aplicada.</strong> ' .
                'Billing: ' . esc_html(number_format((float)$calibration_info['actual_eur'], 4, ',', '.')) . ' € | ' .
                'Real calculado: ' . esc_html(number_format((float)$calibration_info['estimated_eur'], 4, ',', '.')) . ' € | ' .
                'Multiplicador: <code>' . esc_html(number_format((float)$calibration_info['suggested'], 4, ',', '.')) . '</code></p></div>';
        }
?>
        <div class="wrap" style="padding-left:0;">
            <h2>Costes</h2>
            <div class="notice notice-info" style="margin:8px 0 16px 0;">
                <p style="margin:6px 0;">
                    <strong>Ajuste REAL efectivo:</strong>
                    <code><?php echo esc_html(number_format((float)$applied_mult, 4, ',', '.')); ?>×</code>
                    <?php if ($applied_source === 'modelo') : ?>
                        <span class="description">(por modelo: <?php echo esc_html($model_text_current); ?>)</span>
                    <?php else : ?>
                        <span class="description">(por ajuste global)</span>
                    <?php endif; ?>
                </p>
            </div>

            <h3>Estimación rápida (según Config actual)</h3>
            <table class="widefat striped" style="max-width:980px;">
                <tbody>
                    <tr>
                        <td style="width:280px;"><strong>Modelo TEXTO (Config)</strong></td>
                        <td>
                            <code><?php echo esc_html($model_text_current); ?></code>
                            <?php echo cbia_costes_is_model_blocked($model_text_current) ? '<span style="color:#b70000;font-weight:700;">(BLOQUEADO en Config)</span>' : ''; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Modelo IMAGEN (Costes)</strong></td>
                        <td><code><?php echo esc_html($model_img_current); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Modelo SEO (Costes)</strong></td>
                        <td><code><?php echo esc_html($model_seo_current); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Llamadas texto por post</strong></td>
                        <td><code><?php echo esc_html((int)$text_calls); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Llamadas imagen por post</strong></td>
                        <td><code><?php echo esc_html((int)$img_calls); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Llamadas SEO por post</strong></td>
                        <td><code><?php echo esc_html((int)$seo_calls); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Input tokens TEXTO (total post)</strong></td>
                        <td><code><?php echo esc_html((int)$in_tokens_text_total); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Output tokens TEXTO (total post)</strong></td>
                        <td><code><?php echo esc_html((int)$out_tokens_text_total); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Input tokens IMAGEN (total post)</strong></td>
                        <td><code><?php echo esc_html((int)$in_tokens_img_total); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Output tokens IMAGEN (total post)</strong></td>
                        <td><code><?php echo esc_html((int)$out_tokens_img_total); ?></code> <span class="description">(si lo dejas a 0, solo estimamos input)</span></td>
                    </tr>
                    <tr>
                        <td><strong>Input tokens SEO (total post)</strong></td>
                        <td><code><?php echo esc_html((int)$in_tokens_seo_total); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Output tokens SEO (total post)</strong></td>
                        <td><code><?php echo esc_html((int)$out_tokens_seo_total); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Coste estimado (TEXTO)</strong></td>
                        <td>
                            <?php
                            echo ($eur_total_text === null)
                                ? '<span style="color:#b70000;">Modelo no encontrado en tabla</span>'
                                : '<strong>' . esc_html(number_format((float)$eur_total_text, 4, ',', '.')) . ' €</strong> <span class="description">(in ' . number_format((float)$eur_in_text, 4, ',', '.') . ' € | out ' . number_format((float)$eur_out_text, 4, ',', '.') . ' €)</span>';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Coste estimado (IMÁGENES)</strong></td>
                        <td>
                            <?php
                            echo ($eur_total_img === null)
                                ? '<span style="color:#b70000;">Modelo no encontrado en tabla</span>'
                                : '<strong>' . esc_html(number_format((float)$eur_total_img, 4, ',', '.')) . ' €</strong> <span class="description">(in ' . number_format((float)$eur_in_img, 4, ',', '.') . ' € | out ' . number_format((float)$eur_out_img, 4, ',', '.') . ' €)</span>';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Coste estimado (SEO)</strong></td>
                        <td>
                            <strong><?php echo esc_html(number_format((float)$eur_total_seo, 4, ',', '.')); ?> €</strong>
                            <span class="description">(in <?php echo esc_html(number_format((float)$eur_in_seo, 4, ',', '.')); ?> € | out <?php echo esc_html(number_format((float)$eur_out_seo, 4, ',', '.')); ?> €)</span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Coste total estimado</strong></td>
                        <td>
                            <?php
                            echo ($eur_total_est === null)
                                ? '<span style="color:#b70000;">No se pudo estimar (modelo no en tabla)</span>'
                                : '<strong style="font-size:16px;">' . esc_html(number_format((float)$eur_total_est, 4, ',', '.')) . ' €</strong>';
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <hr/>

            <h3>Configuración</h3>
            <form method="post" action="" autocomplete="off">
                <input type="hidden" name="cbia_form" value="costes_settings" />
                <?php wp_nonce_field('cbia_costes_settings_nonce'); ?>

                <table class="form-table" style="max-width:980px;">
                    <tr>
                        <th>Conversión USD → EUR</th>
                        <td>
                            <input type="number" step="0.01" min="0.5" max="1.5" name="usd_to_eur" value="<?php echo esc_attr((string)$cost['usd_to_eur']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Tokens por palabra (aprox)</th>
                        <td>
                            <input type="number" step="0.01" min="0.5" max="2" name="tokens_per_word" value="<?php echo esc_attr((string)$cost['tokens_per_word']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Overhead input (tokens) por llamada de texto</th>
                        <td>
                            <input type="number" min="0" max="5000" name="input_overhead_tokens" value="<?php echo esc_attr((int)$cost['input_overhead_tokens']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Overhead por imagen (palabras) por llamada</th>
                        <td>
                            <input type="number" min="0" max="300" name="per_image_overhead_words" value="<?php echo esc_attr((int)$cost['per_image_overhead_words']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Ratio cached input (0..1)</th>
                        <td>
                            <input type="number" step="0.05" min="0" max="1" name="cached_input_ratio" value="<?php echo esc_attr((string)$cost['cached_input_ratio']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Sobrecoste fijo por llamada TEXTO/SEO (USD)</th>
                        <td>
                            <input type="number" step="0.001" min="0" max="0.050" name="responses_fixed_usd_per_call" value="<?php echo esc_attr((string)$cost['responses_fixed_usd_per_call']); ?>" style="width:120px;" />
                            <p class="description">Ajuste fino para cuadrar con el billing real (se aplica a cada llamada de texto/SEO).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Multiplicador reintentos (texto)</th>
                        <td>
                            <input type="number" step="0.05" min="1" max="5" name="mult_text" value="<?php echo esc_attr((string)$cost['mult_text']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Multiplicador reintentos (imágenes)</th>
                        <td>
                            <input type="number" step="0.05" min="1" max="5" name="mult_image" value="<?php echo esc_attr((string)$cost['mult_image']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Imágenes: usar precio fijo por generación</th>
                        <td>
                            <label><input type="checkbox" name="use_image_flat_pricing" value="1" <?php checked(!empty($cost['use_image_flat_pricing'])); ?> /> Activar (recomendado). Más cercano al billing real.</label>
                            <p class="description">Si está activo, la estimación y el cálculo REAL usarán precio fijo por imagen, ignorando tokens de imagen.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Multiplicador reintentos (SEO)</th>
                        <td>
                            <input type="number" step="0.05" min="1" max="5" name="mult_seo" value="<?php echo esc_attr((string)$cost['mult_seo']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Ajuste multiplicador total (REAL)</th>
                        <td>
                            <input type="number" step="0.01" min="0.5" max="1.5" name="real_adjust_multiplier" value="<?php echo esc_attr((string)$cost['real_adjust_multiplier']); ?>" style="width:120px;" />
                            <p class="description">Multiplica el total real. Útil para compensar pequeñas diferencias de conversión/rounding.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Nº llamadas de TEXTO por post</th>
                        <td>
                            <input type="number" min="1" max="20" name="text_calls_per_post" value="<?php echo esc_attr((int)$cost['text_calls_per_post']); ?>" style="width:120px;" />
                            <p class="description">Si tu engine hace más de 1 llamada para el texto, súbelo aquí.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Nº llamadas de IMAGEN por post</th>
                        <td>
                            <input type="number" min="0" max="20" name="image_calls_per_post" value="<?php echo esc_attr((int)$cost['image_calls_per_post']); ?>" style="width:120px;" />
                            <p class="description">Si pones 0, se usa <code>images_limit</code> de Config.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Modelo de imagen</th>
                        <td>
                            <select name="image_model" style="width:240px;">
                                <option value="gpt-image-1-mini" <?php selected($model_img_current, 'gpt-image-1-mini'); ?>>gpt-image-1-mini</option>
                                <option value="gpt-image-1" <?php selected($model_img_current, 'gpt-image-1'); ?>>gpt-image-1</option>
                            </select>
                            <p class="description">Precios fijos por imagen (USD): mini <input type="number" step="0.001" min="0" name="image_flat_usd_mini" value="<?php echo esc_attr((string)$cost['image_flat_usd_mini']); ?>" style="width:90px;" /> &nbsp;full <input type="number" step="0.001" min="0" name="image_flat_usd_full" value="<?php echo esc_attr((string)$cost['image_flat_usd_full']); ?>" style="width:90px;" /></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Output tokens por llamada de imagen (opcional)</th>
                        <td>
                            <input type="number" min="0" max="50000" name="image_output_tokens_per_call" value="<?php echo esc_attr((int)$cost['image_output_tokens_per_call']); ?>" style="width:120px;" />
                            <p class="description">Si lo dejas en 0, la estimación contará básicamente el input.</p>
                        </td>
                    </tr>
                    <tr><th colspan="2"><hr/></th></tr>
                    <tr>
                        <th>Nº llamadas SEO por post</th>
                        <td>
                            <input type="number" min="0" max="20" name="seo_calls_per_post" value="<?php echo esc_attr((int)$cost['seo_calls_per_post']); ?>" style="width:120px;" />
                            <p class="description">Si tu relleno Yoast/SEO hace llamadas a OpenAI (meta, keyphrase, etc), ponlas aquí para estimación.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Modelo SEO</th>
                        <td>
                            <select name="seo_model" style="width:240px;">
                                <?php
                                $seo_candidates = array('gpt-4.1-mini','gpt-4.1','gpt-4.1-nano','gpt-5','gpt-5-mini','gpt-5-nano','gpt-5.1','gpt-5.2');
                                foreach ($seo_candidates as $m) {
                                    if (!isset($table[$m])) continue;
                                    echo '<option value="' . esc_attr($m) . '" ' . selected($model_seo_current, $m, false) . '>' . esc_html($m) . '</option>';
                                }
                                ?>
                            </select>
                            <p class="description">Si no sabes, deja el mismo que el de texto.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Input tokens por llamada SEO</th>
                        <td>
                            <input type="number" min="0" max="50000" name="seo_input_tokens_per_call" value="<?php echo esc_attr((int)$cost['seo_input_tokens_per_call']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Output tokens por llamada SEO</th>
                        <td>
                            <input type="number" min="0" max="50000" name="seo_output_tokens_per_call" value="<?php echo esc_attr((int)$cost['seo_output_tokens_per_call']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button button-primary">Guardar configuración de Costes</button>
                </p>
            </form>

            <hr/>

            <h3>Acciones (post-hoc)</h3>
            <form method="post" action="" autocomplete="off">
                <input type="hidden" name="cbia_form" value="costes_actions" />
                <?php wp_nonce_field('cbia_costes_actions_nonce'); ?>

                <table class="form-table" style="max-width:980px;">
                    <tr>
                        <th>Calcular últimos N posts</th>
                        <td>
                            <input type="number" name="calc_last_n" min="1" max="200" value="20" style="width:120px;" />
                            <label style="margin-left:14px;">
                                <input type="checkbox" name="calc_only_cbia" value="1" checked />
                                Solo posts del plugin (<code>_cbia_created=1</code>)
                            </label>
                            <label style="margin-left:14px;">
                                <input type="checkbox" name="calc_estimate_if_missing" value="1" checked />
                                Si no hay usage real, usar estimación
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Calibrar con billing real (€)</th>
                        <td>
                            <input type="number" name="calibrate_actual_eur" step="0.01" min="0" placeholder="Ej: 1.84" style="width:120px;" />
                            <span class="description" style="margin-left:8px;">Introduce el gasto real para esos N posts y ajustamos el multiplicador REAL automáticamente.</span>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button button-primary" name="cbia_action" value="calc_last">Calcular</button>
                    <button type="submit" class="button" name="cbia_action" value="calc_last_real" style="margin-left:8px;">Calcular SOLO real</button>
                    <button type="submit" class="button button-secondary" name="cbia_action" value="calibrate_real" style="margin-left:8px;">Calibrar REAL desde billing</button>
                    <button type="submit" class="button button-secondary" name="cbia_action" value="clear_log" style="margin-left:8px;">Limpiar log</button>
                </p>
            </form>

            <h3>Log Costes</h3>
            <textarea id="cbia-costes-log" rows="14" cols="120" readonly style="background:#f9f9f9;width:100%;"><?php echo esc_textarea($log); ?></textarea>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const logBox = document.getElementById('cbia-costes-log');
                function refreshLog(){
                    if (typeof ajaxurl === 'undefined') return;
                    fetch(ajaxurl + '?action=cbia_get_costes_log', { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(data => {
                            if(data && data.success && logBox){
                                if (data.data && typeof data.data === 'object' && data.data.log) {
                                    logBox.value = data.data.log || '';
                                } else {
                                    logBox.value = data.data || '';
                                }
                                logBox.scrollTop = logBox.scrollHeight;
                            }
                        })
                        .catch(() => {});
                }
                setInterval(refreshLog, 3000);
            });
            </script>
        </div>
        <?php
    }
}

/* ------------------------- FIN includes/cbia-costes.php ------------------------- */

