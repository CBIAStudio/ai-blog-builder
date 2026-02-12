<?php
/**
 * CBIA - Costes (estimaciÃ³n + cÃ¡lculo post-hoc)
 * v12 (FIX: imÃ¡genes con precio fijo + botÃ³n "solo coste real" + tokens reales en log)
 *
 * Archivo: includes/domain/costs.php
 *
 * OBJETIVO:
 * - EstimaciÃ³n sencilla por post: TEXTO + IMÃGENES + SEO (si hay llamadas de relleno Yoast/SEO)
 * - CÃ¡lculo REAL post-hoc: suma el coste POR CADA LLAMADA guardada en _cbia_usage_rows,
 *   respetando el modelo real usado en cada llamada (texto vs imagen vs seo) y su tabla de precios.
 *
 * IMPORTANTE:
 * - Para que el cÃ¡lculo REAL funcione, el engine/yoast debe llamar a:
 *   cbia_costes_record_usage($post_id, [...])
 *   en CADA llamada a OpenAI (texto / imagen / seo).
 *
 * - Este archivo NO â€œadivinaâ€ tokens reales de imÃ¡genes si no se registran. Solo estima si faltan.
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
        register_setting('cbia_costes_settings_group', cbia_costes_settings_key(), array(
            'sanitize_callback' => 'cbia_costes_sanitize_settings',
        ));
    }
    add_action('admin_init', 'cbia_costes_register_settings');
}

if (!function_exists('cbia_costes_sanitize_settings')) {
    function cbia_costes_sanitize_settings($settings) {
        if (!is_array($settings)) return array();
        $out = array();
        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                $out[$key] = array_map('sanitize_text_field', $value);
            } else {
                $out[$key] = is_numeric($value) ? (float)$value : sanitize_text_field((string)$value);
            }
        }
        return $out;
    }
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
            cbia_log((string)$msg, 'INFO');
            return;
        }
        $log = get_option(cbia_costes_log_key(), '');
        $ts  = current_time('mysql');
        $log .= "[{$ts}] COSTES: {$msg}\n";
        if (strlen($log) > 250000) $log = substr($log, -250000);
        update_option(cbia_costes_log_key(), $log);
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
   ===================== TABLA DE PRECIOS ===================
   Valores en USD por 1.000.000 tokens (1M)
   SOLO modelos usados en el plugin (segÃºn tu Config actual):
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

            // IMAGEN (solo para estimaciÃ³n basada en tokens; por defecto usaremos tarifa fija)
            'gpt-image-1'       => array('in'=>10.00, 'cin'=>2.50, 'out'=>40.00),
            'gpt-image-1-mini'  => array('in'=>2.50,  'cin'=>0.25, 'out'=>8.00),
        );
    }
}

/* =========================================================
   ======= PRECIOS FIJOS POR IMAGEN (USD por generaciÃ³n) ===
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
   ============== ESTIMACIÃ“N: palabras -> tokens ============
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
   ===================== CÃLCULO DE COSTE ===================
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
   ===== AJUSTE AUTOMÃTICO POR MODELO (opcional) ============
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

        // Ajuste automÃ¡tico por modelo (solo si el multiplicador global estÃ¡ en 1.0)
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

            // IMÃGENES: si estÃ¡ activa la tarifa plana, sumar por generaciÃ³n OK
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

        // AÃ±adir sobrecoste fijo por llamada de texto/SEO (en USD)
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
   ===================== ESTIMACIÃ“N POR POST =================
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
   ============ CÃLCULO ÃšLTIMOS POSTS (real/estimado) =======
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
            $args['meta_key'] = '_cbia_created';
            $args['meta_value'] = '1';
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

            // 2) ESTIMACIÃ“N
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
   ================== POST HANDLER (COSTES) ================
   ========================================================= */
if (!function_exists('cbia_costes_handle_post')) {
    function cbia_costes_handle_post($cost, $cbia, $defaults, $table, $model_text_current) {
        if (function_exists('cbia_container')) {
            $container = cbia_container();
            if ($container) {
                $service = $container->get('costs_service');
                if ($service && method_exists($service, 'handle_post')) {
                    return $service->handle_post($cost, $cbia, $defaults, $table, $model_text_current);
                }
            }
        }
        $notice = '';
        $calibration_info = null;

        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return array($cost, $notice, $calibration_info);
        }

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

            // nÂº llamadas texto/imagen
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
            cbia_costes_log("ConfiguraciÃ³n guardada.");
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
                    cbia_costes_log("CÃ¡lculo Ãºltimos {$n}: posts={$sum['posts']} real={$sum['real_posts']} est={$sum['est_posts']} real_calls={$sum['real_calls']} real_fails={$sum['real_fails']} tokens_in={$sum['tokens_in']} tokens_out={$sum['tokens_out']} totalâ‚¬=" . number_format((float)$sum['eur_total'], 4, ',', '.'));
                } else {
                    cbia_costes_log("CÃ¡lculo Ãºltimos {$n}: sin resultados.");
                }
                $notice = 'calc';
            }

            if ($action === 'calc_last_real') {
                $n = isset($u['calc_last_n']) ? (int)$u['calc_last_n'] : 20;
                $n = max(1, min(200, $n));
                $only_cbia = !empty($u['calc_only_cbia']) ? true : false;
                $sum = cbia_costes_calc_last_posts($n, $only_cbia, false, $cost, $cbia);
                if ($sum) {
                    cbia_costes_log("CÃ¡lculo SOLO REAL Ãºltimos {$n}: posts={$sum['posts']} real={$sum['real_posts']} real_calls={$sum['real_calls']} real_fails={$sum['real_fails']} tokens_in={$sum['tokens_in']} tokens_out={$sum['tokens_out']} totalâ‚¬=" . number_format((float)$sum['eur_total'], 4, ',', '.'));
                } else {
                    cbia_costes_log("CÃ¡lculo SOLO REAL Ãºltimos {$n}: sin resultados.");
                }
                $notice = 'calc';
            }

            if ($action === 'calibrate_real') {
                $n = isset($u['calc_last_n']) ? (int)$u['calc_last_n'] : 20;
                $n = max(1, min(200, $n));
                $only_cbia = !empty($u['calc_only_cbia']) ? true : false;

                $actual_eur = isset($u['calibrate_actual_eur']) ? (float)str_replace(',', '.', (string)$u['calibrate_actual_eur']) : 0.0;
                if ($actual_eur > 0) {
                    $sum = cbia_costes_calc_last_posts($n, $only_cbia, false, $cost, $cbia);
                    if ($sum && !empty($sum['eur_total'])) {
                        $estimated = (float)$sum['eur_total'];
                        $suggested = $estimated > 0 ? ($actual_eur / $estimated) : 1.0;
                        if ($suggested < 0.5) $suggested = 0.5;
                        if ($suggested > 1.5) $suggested = 1.5;

                        $cost['real_adjust_multiplier'] = $suggested;
                        update_option(cbia_costes_settings_key(), $cost);

                        $calibration_info = array(
                            'actual_eur' => $actual_eur,
                            'estimated_eur' => $estimated,
                            'suggested' => $suggested,
                        );

                        cbia_costes_log("CalibraciÃ³n REAL aplicada: billing={$actual_eur}â‚¬, real_calc={$estimated}â‚¬, mult=" . number_format($suggested, 4, ',', '.'));
                        $notice = 'saved';
                    } else {
                        cbia_costes_log("CalibraciÃ³n REAL: sin datos suficientes para calcular.");
                        $notice = 'calc';
                    }
                }
            }
        }

        return array($cost, $notice, $calibration_info);
    }
}

/* =========================================================
   ===================== UI TAB: COSTES =====================
   ========================================================= */
if (!function_exists('cbia_render_tab_costes')) {
    function cbia_render_tab_costes(){
        if (!current_user_can('manage_options')) return;

        $view = (defined('CBIA_INCLUDES_DIR') ? CBIA_INCLUDES_DIR . 'admin/views/costs.php' : __DIR__ . '/../admin/views/costs.php');
        if (file_exists($view)) {
            include $view;
            return;
        }

        echo '<p>No se pudo cargar Costes.</p>';
    }
}

/* ------------------------- FIN includes/domain/costs.php ------------------------- */


