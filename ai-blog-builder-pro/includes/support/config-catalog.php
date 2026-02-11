<?php
/**
 * Config catalogs and helpers.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_get_allowed_models_for_ui')) {
    /**
     * Lista UI (selector + bloqueo)
     */
    function cbia_get_allowed_models_for_ui(): array {
        return [
            'gpt-4.1-mini',
            'gpt-4.1',
            'gpt-4.1-nano',
            'gpt-5',
            'gpt-5-mini',
            'gpt-5-nano',
            'gpt-5.1',
            'gpt-5.2',
        ];
    }
}

if (!function_exists('cbia_get_recommended_text_model')) {
    function cbia_get_recommended_text_model(): string {
        return 'gpt-4.1-mini';
    }
}

if (!function_exists('cbia_config_safe_model')) {
    function cbia_config_safe_model($model): string {
        $model = sanitize_text_field((string)$model);
        $models = cbia_get_allowed_models_for_ui();
        if (in_array($model, $models, true)) return $model;
        return cbia_get_recommended_text_model();
    }
}

if (!function_exists('cbia_config_image_formats_catalog')) {
    function cbia_config_image_formats_catalog(): array {
        return [
            'panoramic_1536x1024' => 'PanorÃ¡mica (1536x1024)',
            'banner_1536x1024'    => 'Banner (1536x1024, encuadre amplio + headroom 25â€“35%)',
        ];
    }
}

if (!function_exists('cbia_config_presets_catalog')) {
    /**
     * Presets rÃ¡pidos por modelo (UX).
     */
    function cbia_config_presets_catalog(): array {
        return [
            'gpt-4.1-mini' => [
                'label' => 'Preset GPT-4.1-mini (estable)',
                'openai_model' => 'gpt-4.1-mini',
                'openai_temperature' => 0.7,
                'responses_max_output_tokens' => 6000,
            ],
            'gpt-5-mini' => [
                'label' => 'Preset GPT-5-mini (mÃ¡s creativo)',
                'openai_model' => 'gpt-5-mini',
                'openai_temperature' => 0.7,
                'responses_max_output_tokens' => 8000,
            ],
            'gpt-5.1-mini' => [
                'label' => 'Preset GPT-5.1-mini (mÃ¡s coste/calidad)',
                'openai_model' => 'gpt-5.1-mini',
                'openai_temperature' => 0.7,
                'responses_max_output_tokens' => 8000,
            ],
        ];
    }
}

if (!function_exists('cbia_config_apply_preset')) {
    function cbia_config_apply_preset(string $preset_key, array $current): array {
        $presets = cbia_config_presets_catalog();
        if (!isset($presets[$preset_key])) return $current;
        $p = $presets[$preset_key];

        $current['openai_model'] = cbia_config_safe_model($p['openai_model'] ?? ($current['openai_model'] ?? 'gpt-4.1-mini'));
        $current['openai_temperature'] = isset($p['openai_temperature']) ? (float)$p['openai_temperature'] : (float)($current['openai_temperature'] ?? 0.7);
        $current['responses_max_output_tokens'] = isset($p['responses_max_output_tokens']) ? (int)$p['responses_max_output_tokens'] : (int)($current['responses_max_output_tokens'] ?? 6000);

        return $current;
    }
}

if (!function_exists('cbia_config_sanitize_image_format')) {
    function cbia_config_sanitize_image_format($value, $fallback_key): string {
        $value = sanitize_key((string)$value);
        $formats = cbia_config_image_formats_catalog();
        if (isset($formats[$value])) return $value;
        return sanitize_key((string)$fallback_key);
    }
}

if (!function_exists('cbia_config_banner_css_presets')) {
    /**
     * Presets de CSS para imÃ¡genes internas (clase cbia-banner).
     */
    function cbia_config_banner_css_presets(): array {
        return [
            'elementor_350' => [
                'label' => 'Banner 350px (Elementor)',
                'css' =>
                    ".elementor-widget-theme-post-content img.cbia-banner{\n" .
                    "  width: 100%;\n" .
                    "  height: 350px !important;\n" .
                    "  max-height: 350px !important;\n" .
                    "  object-fit: cover;\n" .
                    "  object-position: center;\n" .
                    "  border-radius: 20px;\n" .
                    "  display: block;\n" .
                    "}\n",
            ],
            'compact_250' => [
                'label' => 'Banner 250px (compacto)',
                'css' =>
                    "img.cbia-banner {\n" .
                    "  width: 100%;\n" .
                    "  height: 250px !important;\n" .
                    "  object-fit: cover !important;\n" .
                    "  object-position: 50% 60% !important;\n" .
                    "  display: block !important;\n" .
                    "  margin: 15px 0 !important;\n" .
                    "  transition: transform 0.3s ease !important;\n" .
                    "}\n",
            ],
            'none' => [
                'label' => 'Sin estilo (vacÃ­o)',
                'css' => '',
            ],
        ];
    }
}

if (!function_exists('cbia_config_detect_banner_css_preset')) {
    /**
     * Detect preset key based on current CSS.
     */
    function cbia_config_detect_banner_css_preset(string $css): string {
        $css = trim($css);
        $presets = cbia_config_banner_css_presets();
        foreach ($presets as $key => $data) {
            $preset_css = trim((string)($data['css'] ?? ''));
            if ($preset_css !== '' && $preset_css === $css) {
                return $key;
            }
            if ($preset_css === '' && $css === '') {
                return $key;
            }
        }
        return 'custom';
    }
}


