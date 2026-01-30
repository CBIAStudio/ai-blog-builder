<?php
/**
 * Image helpers.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_image_model_chain')) {
    function cbia_image_model_chain() {
        return ['gpt-image-1-mini', 'gpt-image-1'];
    }
}

/**
 * Catálogo (para UI y coherencia v8.4)
 */
if (!function_exists('cbia_image_formats_catalog')) {
    function cbia_image_formats_catalog(): array {
        return [
            'panoramic_1536x1024' => [
                'label' => 'Panorámica (1536x1024)',
                'size'  => '1536x1024',
                'type'  => 'panoramic',
            ],
            'banner_1536x1024' => [
                'label' => 'Banner (1536x1024, encuadre amplio + headroom 25–35%)',
                'size'  => '1536x1024',
                'type'  => 'banner',
            ],
        ];
    }
}

if (!function_exists('cbia_get_image_format_for_section')) {
    /**
     * IMPORTANTE (como en v8.4):
     * - destacada/intro => panorámica
     * - resto => banner
     * Aunque haya settings guardados, aquí se fuerza por compatibilidad.
     */
    function cbia_get_image_format_for_section($section): string {
        $section = sanitize_key((string)$section);
        return ($section === 'intro') ? 'panoramic_1536x1024' : 'banner_1536x1024';
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
    function cbia_build_content_img_tag($url, $alt, $section): string {
        $url = (string)$url;
        $alt = (string)$alt;
        $section = sanitize_key((string)$section);

        $fmt = cbia_get_image_format_for_section($section);

        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : [];
        $apply_banner = !empty($settings['content_images_banner_enabled']);
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

if (!function_exists('cbia_build_image_prompt')) {
    function cbia_build_image_prompt($desc, $section, $title) {
        $desc = trim((string)$desc);
        $title = trim((string)$title);
        $section = sanitize_key((string)$section);

        $fmt = cbia_get_image_format_for_section($section);

        $prompt = "Imagen editorial realista, sin texto ni marcas de agua. Tema: {$title}.";
        if ($desc !== '') $prompt .= " Descripción: {$desc}.";

        if (cbia_is_banner_format($fmt)) {
            $prompt .= " Composición tipo banner con espacio superior (headroom).";
        } else {
            $prompt .= " Composición panorámica equilibrada.";
        }

        return $prompt;
    }
}

if (!function_exists('cbia_image_size_for_section')) {
    function cbia_image_size_for_section($section) {
        $fmt = cbia_get_image_format_for_section($section);
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
