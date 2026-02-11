<?php
/**
 * Image helpers.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_image_model_chain')) {
    // CAMBIO: cadena de modelos por proveedor con preferido al inicio
    function cbia_image_model_chain(string $provider = 'openai', string $preferred = ''): array {
        $provider = sanitize_key($provider);
        $list = function_exists('cbia_providers_get_image_model_list')
            ? cbia_providers_get_image_model_list($provider)
            : array();

        if (empty($list)) {
            $list = ['gpt-image-1-mini', 'gpt-image-1'];
        }

        $preferred = trim((string)$preferred);
        if ($preferred !== '') {
            if (!in_array($preferred, $list, true)) {
                array_unshift($list, $preferred);
            } else {
                $list = array_values(array_unique(array_merge([$preferred], $list)));
            }
        }

        return $list;
    }
}

/**
 * Catalogo (para UI y coherencia v8.4)
 */
if (!function_exists('cbia_image_formats_catalog')) {
    function cbia_image_formats_catalog(): array {
        return [
            'panoramic_1536x1024' => [
                'label' => 'Panoramica (1536x1024)',
                'size'  => '1536x1024',
                'type'  => 'panoramic',
            ],
            'banner_1536x1024' => [
                'label' => 'Banner (1536x1024, encuadre amplio + headroom 25-35%)',
                'size'  => '1536x1024',
                'type'  => 'banner',
            ],
        ];
    }
}

if (!function_exists('cbia_get_image_format_for_section')) {
    /**
     * Format by section:
     * - featured/intro => panoramic (fixed)
     * - internal => configurable per internal (1/2/3), fallback to body
     */
    function cbia_get_image_format_for_section($section, $idx = 0): string {
        $section = sanitize_key((string)$section);
        $idx = (int)$idx;
        if ($section === 'intro') return 'panoramic_1536x1024';

        $s = function_exists('cbia_get_settings') ? cbia_get_settings() : [];
        if ($section === 'conclusion') {
            return (string)($s['image_format_conclusion'] ?? 'banner_1536x1024');
        }
        if ($section === 'faq') {
            return (string)($s['image_format_faq'] ?? 'banner_1536x1024');
        }

        if ($idx >= 1) {
            $key = 'image_format_internal_' . $idx;
            if (!empty($s[$key])) {
                return (string)$s[$key];
            }
        }
        return (string)($s['image_format_body'] ?? 'banner_1536x1024');
    }
}

if (!function_exists('cbia_is_banner_format')) {
    function cbia_is_banner_format($format_key): bool {
        $catalog = cbia_image_formats_catalog();
        if (!isset($catalog[$format_key])) return false;
        return (($catalog[$format_key]['type'] ?? '') === 'banner');
    }
}

if (!function_exists('cbia_build_content_img_tag')) {
    /**
     * Tag <img> en contenido:
     * - decoding="async"
     * - banner => class="cbia-banner lazyloaded" (como v8.4)
     */
    function cbia_build_content_img_tag($url, $alt, $section, $idx = 0): string {
        $url = (string)$url;
        $alt = (string)$alt;
        $section = sanitize_key((string)$section);

        $fmt = cbia_get_image_format_for_section($section, $idx);

        $apply_banner = true;
        if ($section === 'intro') {
            $apply_banner = false;
        }

        $classes = [];
        if ($apply_banner && cbia_is_banner_format($fmt)) {
            $classes[] = 'cbia-banner';
            $classes[] = 'lazyloaded';
        }

        $class_attr = !empty($classes) ? ' class="' . esc_attr(implode(' ', $classes)) . '"' : '';
        return '<img decoding="async" loading="lazy"' . $class_attr . ' src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" style="display:block;width:100%;height:auto;margin:15px 0;" />';
    }
}

if (!function_exists('cbia_build_content_img_tag_with_meta')) {
    /**
     * Wrapper para incluir data-cbia-attach si hay ID de adjunto.
     */
    function cbia_build_content_img_tag_with_meta($url, $alt, $section, $attach_id = 0, $idx = 0): string {
        $tag = cbia_build_content_img_tag($url, $alt, $section, $idx);
        $attach_id = (int)$attach_id;
        if ($attach_id > 0) {
            $tag = preg_replace(
                '/<img\s+/i',
                '<img data-cbia-attach="' . esc_attr((string)$attach_id) . '" ',
                $tag,
                1
            );
        }
        return $tag;
    }
}

if (!function_exists('cbia_replace_img_by_attach_id')) {
    /**
     * Reemplaza una imagen del contenido buscando por data-cbia-attach o por URL del adjunto.
     */
    function cbia_replace_img_by_attach_id(&$html, $old_attach_id, $new_img_tag): bool {
        $html = (string)$html;
        $old_attach_id = (int)$old_attach_id;
        $new_img_tag = (string)$new_img_tag;
        if ($old_attach_id <= 0 || $new_img_tag === '') return false;

        $count = 0;
        $pattern = '/<img[^>]*data-cbia-attach=("|\')' . $old_attach_id . '\1[^>]*>/i';
        $new = preg_replace($pattern, $new_img_tag, $html, 1, $count);
        if ($count > 0 && $new !== null) {
            $html = $new;
            return true;
        }

        $old_url = wp_get_attachment_url($old_attach_id);
        if ($old_url) {
            $pattern = '/<img[^>]*src=("|\')' . preg_quote($old_url, '/') . '\1[^>]*>/i';
            $new = preg_replace($pattern, $new_img_tag, $html, 1, $count);
            if ($count > 0 && $new !== null) {
                $html = $new;
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('cbia_replace_pending_marker')) {
    /**
     * Reemplaza un marcador pendiente por HTML de imagen.
     */
    function cbia_replace_pending_marker(&$html, $pending_token, $replacement): bool {
        $token = (string)$pending_token;
        $pattern = '/<span[^>]*class=("|\')cbia-img-pendiente\1[^>]*>\s*' . preg_quote($token, '/') . '\s*<\/span>/iu';
        $count = 0;
        $new = preg_replace($pattern, (string)$replacement, (string)$html, 1, $count);
        if ($count > 0 && $new !== null) {
            $html = $new;
            return true;
        }
        return false;
    }
}

if (!function_exists('cbia_get_image_request_delay')) {
    function cbia_get_image_request_delay(): int {
        $s = function_exists('cbia_get_settings') ? cbia_get_settings() : [];
        $delay = isset($s['image_request_delay']) ? (int)$s['image_request_delay'] : 2;
        if ($delay < 0) $delay = 0;
        if ($delay > 10) $delay = 10;
        return $delay;
    }
}

if (!function_exists('cbia_get_img_prompt_override_key')) {
    function cbia_get_img_prompt_override_key($type, $idx = 0): string {
        $type = (string)$type;
        $idx = (int)$idx;
        if ($type === 'featured') return '_cbia_img_prompt_override_featured';
        if ($idx < 1) $idx = 1;
        if ($idx > 3) $idx = 3;
        return '_cbia_img_prompt_override_internal_' . $idx;
    }
}

if (!function_exists('cbia_get_img_prompt_override')) {
    function cbia_get_img_prompt_override($post_id, $type, $idx = 0): string {
        $post_id = (int)$post_id;
        if ($post_id <= 0) return '';
        $key = cbia_get_img_prompt_override_key($type, $idx);
        return (string)get_post_meta($post_id, $key, true);
    }
}

if (!function_exists('cbia_set_img_prompt_override')) {
    function cbia_set_img_prompt_override($post_id, $type, $idx, $prompt): bool {
        $post_id = (int)$post_id;
        if ($post_id <= 0) return false;
        $key = cbia_get_img_prompt_override_key($type, $idx);
        update_post_meta($post_id, $key, (string)$prompt);
        return true;
    }
}

if (!function_exists('cbia_default_image_prompt_template')) {
    function cbia_default_image_prompt_template(): string {
        return "Professional editorial photography with natural anatomy and realistic perspective. Main subject: {title}. Specific detail: {desc}. {format} Keep proportions correct, avoid warped faces/hands, avoid duplicated limbs, avoid fisheye distortion, and keep clean straight lines. Realistic lighting and sharp focus. No text, no logos, no watermarks.";
    }
}

if (!function_exists('cbia_get_image_prompt_template')) {
    function cbia_get_image_prompt_template($type = '', $idx = 0): string {
        $type = (string)$type;
        $idx = (int)$idx;
        $s = function_exists('cbia_get_settings') ? cbia_get_settings() : [];

        if ($type === 'featured' && isset($s['prompt_img_featured'])) {
            $tpl = trim((string)$s['prompt_img_featured']);
            if ($tpl !== '') return $tpl;
        }
        if ($type === 'internal') {
            if ($idx >= 1) {
                $key = 'prompt_img_internal_' . $idx;
                if (isset($s[$key])) {
                    $tpl = trim((string)$s[$key]);
                    if ($tpl !== '') return $tpl;
                }
            }
            if (isset($s['prompt_img_internal'])) {
                $tpl = trim((string)$s['prompt_img_internal']);
                if ($tpl !== '') return $tpl;
            }
        }

        $tpl = isset($s['prompt_img_global']) ? trim((string)$s['prompt_img_global']) : '';
        if ($tpl === '') {
            $tpl = cbia_default_image_prompt_template();
        }
        return $tpl;
    }
}

if (!function_exists('cbia_image_prompt_format_hint')) {
    function cbia_image_prompt_format_hint($section, $idx = 0): string {
        $fmt = cbia_get_image_format_for_section($section, $idx);
        if (cbia_is_banner_format($fmt)) {
            return 'Horizontal 3:2 banner composition, crop-safe framing, centered subject, top headroom 25-35%, and safe margins on all sides to avoid cropped faces/hands.';
        }
        return 'Wide 3:2 panoramic composition with balanced framing, full subject visibility, and safe margins to avoid edge clipping.';
    }
}

if (!function_exists('cbia_format_image_prompt')) {
    function cbia_format_image_prompt($tpl, $desc, $title, $section, $type, $format_hint): string {
        $desc = (string)$desc;
        $title = (string)$title;
        $section = (string)$section;
        $type = (string)$type;
        $format_hint = (string)$format_hint;

        $replacements = array(
            '{desc}' => $desc,
            '{title}' => $title,
            '{section}' => $section,
            '{type}' => $type,
            '{format}' => $format_hint,
            '{SHORT_DESC}' => $desc,
            '{POST_TITLE}' => $title,
        );

        $formatted = str_replace(array_keys($replacements), array_values($replacements), (string)$tpl);
        $safety = ' Preserve natural human proportions and realistic perspective. Avoid warped anatomy, duplicated limbs, fisheye distortion, and edge clipping.';
        if (stripos($formatted, 'warped anatomy') === false && stripos($formatted, 'natural human proportions') === false) {
            $formatted .= $safety;
        }
        return $formatted;
    }
}

if (!function_exists('cbia_build_image_prompt_for_post')) {
    function cbia_build_image_prompt_for_post($post_id, $type, $short_desc, $idx = 0): string {
        $post_id = (int)$post_id;
        $type = (string)$type;
        $idx = (int)$idx;

        $title = $post_id > 0 ? get_the_title($post_id) : '';
        if ($title === '' && !empty($GLOBALS['cbia_current_post_title_for_prompt'])) {
            $title = (string)$GLOBALS['cbia_current_post_title_for_prompt'];
        }
        $short_desc = cbia_sanitize_image_short_desc($short_desc);
        if ($short_desc === '') {
            $short_desc = $title;
            if ($short_desc === '') $short_desc = 'general scene';
            cbia_log('Imagen: SHORT_DESC vacio, usando fallback por titulo', 'INFO');
        }

        $override = cbia_get_img_prompt_override($post_id, $type, $idx);
        if ($override !== '') {
            cbia_log(sprintf('Imagen: usando override de prompt (%s%s)', (string)$type, $idx ? '_' . (string)$idx : ''), 'INFO');
            return $override;
        }

        $section = ($type === 'featured') ? 'intro' : 'body';
        $tpl = cbia_get_image_prompt_template($type, $idx);
        $format_hint = cbia_image_prompt_format_hint($section, $idx);

        return cbia_format_image_prompt($tpl, $short_desc, (string)$title, $section, $type, $format_hint);
    }
}

if (!function_exists('cbia_get_post_image_descs')) {
    function cbia_get_post_image_descs($post_id): array {
        $post_id = (int)$post_id;
        if ($post_id <= 0) {
            return array(
                'featured' => array('desc' => '', 'section' => 'intro', 'attach_id' => 0),
                'internal' => array(),
            );
        }
        $raw = get_post_meta($post_id, '_cbia_img_descs', true);
        if (!$raw) {
            return array(
                'featured' => array('desc' => '', 'section' => 'intro', 'attach_id' => 0),
                'internal' => array(),
            );
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return array(
                'featured' => array('desc' => '', 'section' => 'intro', 'attach_id' => 0),
                'internal' => array(),
            );
        }
        return cbia_normalize_img_descs($decoded);
    }
}

if (!function_exists('cbia_normalize_img_descs')) {
    function cbia_normalize_img_descs($decoded): array {
        $out = array(
            'featured' => array('desc' => '', 'section' => 'intro', 'attach_id' => 0),
            'internal' => array(),
        );

        if (isset($decoded['featured'])) {
            if (is_array($decoded['featured'])) {
                $out['featured'] = array(
                    'desc' => (string)($decoded['featured']['desc'] ?? ''),
                    'section' => (string)($decoded['featured']['section'] ?? 'intro'),
                    'attach_id' => (int)($decoded['featured']['attach_id'] ?? 0),
                );
            } else {
                $out['featured']['desc'] = (string)$decoded['featured'];
            }
        }

        $internals = is_array($decoded['internal'] ?? null) ? $decoded['internal'] : array();
        foreach ($internals as $it) {
            if (is_array($it)) {
                $out['internal'][] = array(
                    'desc' => (string)($it['desc'] ?? ''),
                    'section' => (string)($it['section'] ?? 'body'),
                    'attach_id' => (int)($it['attach_id'] ?? 0),
                );
            } else {
                $out['internal'][] = array(
                    'desc' => (string)$it,
                    'section' => 'body',
                    'attach_id' => 0,
                );
            }
        }

        return $out;
    }
}

if (!function_exists('cbia_set_post_image_descs')) {
    function cbia_set_post_image_descs($post_id, array $descs): void {
        $post_id = (int)$post_id;
        if ($post_id <= 0) return;
        update_post_meta($post_id, '_cbia_img_descs', wp_json_encode($descs));
    }
}

if (!function_exists('cbia_find_internal_index_by_desc')) {
    function cbia_find_internal_index_by_desc(array $internal, $desc): int {
        $needle = cbia_sanitize_image_short_desc((string)$desc);
        if ($needle === '') return 0;
        foreach ($internal as $idx => $it) {
            $it_desc = is_array($it) ? (string)($it['desc'] ?? '') : (string)$it;
            $it_desc = cbia_sanitize_image_short_desc($it_desc);
            if ($it_desc !== '' && $it_desc === $needle) {
                return (int)$idx + 1; // 1-based
            }
        }
        return 0;
    }
}

if (!function_exists('cbia_build_image_prompt')) {
    function cbia_build_image_prompt($desc, $section, $title) {
        $desc = trim((string)$desc);
        $title = trim((string)$title);
        $section = sanitize_key((string)$section);

        $type = ($section === 'intro') ? 'featured' : 'internal';
        $tpl = cbia_get_image_prompt_template($type);
        $format_hint = cbia_image_prompt_format_hint($section);

        return cbia_format_image_prompt($tpl, $desc, $title, $section, $type, $format_hint);
    }
}

if (!function_exists('cbia_image_size_for_section')) {
    function cbia_image_size_for_section($section, $idx = 0) {
        $fmt = cbia_get_image_format_for_section($section, $idx);
        $catalog = cbia_image_formats_catalog();
        return $catalog[$fmt]['size'] ?? '1536x1024';
    }
}

if (!function_exists('cbia_upload_image_to_media')) {
    function cbia_upload_image_to_media($bytes, $title, $section, $alt_text) {
        if ($bytes === '') return [false, 'bytes_vacios'];

        $filename = sanitize_title($title . '-' . $section) . '-' . wp_generate_password(6, false) . '.png';

        $upload = wp_upload_bits($filename, null, $bytes);
        if (!$upload || !empty($upload['error'])) {
            return [false, 'upload_error: ' . ($upload['error'] ?? 'desconocido')];
        }

        $wp_filetype = wp_check_filetype($filename, null);
        $attachment = [
            'post_mime_type' => $wp_filetype['type'] ?: 'image/png',
            'post_title'     => sanitize_text_field($title . ' - ' . $section),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        if (is_wp_error($attach_id) || !$attach_id) {
            return [false, 'wp_insert_attachment_error'];
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        update_post_meta($attach_id, '_wp_attachment_image_alt', $alt_text);

        return [$attach_id, ''];
    }
}

