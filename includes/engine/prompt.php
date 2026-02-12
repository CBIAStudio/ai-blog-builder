<?php
/**
 * Prompt builder.
 */

if (!defined('ABSPATH')) exit;

// CAMBIO: bloques fijos (no editables) del prompt recomendado.
if (!function_exists('cbia_prompt_recommended_header_template')) {
    function cbia_prompt_recommended_header_template(): string {
        return
            "Escribe un POST COMPLETO en {IDIOMA_POST} y en HTML para \"{title}\", optimizado para Google Discover, con una extension aproximada de 1800-2100 palabras (+/-10%)."
            ."\n\nREGLA DE IDIOMA (OBLIGATORIA)"
            ."\n- TODO el contenido debe estar escrito EXCLUSIVAMENTE en {IDIOMA_POST}."
            ."\n- Esto incluye titulos, encabezados, preguntas frecuentes y respuestas."
            ."\n- Esta PROHIBIDO usar cualquier otro idioma en el contenido (salvo el titulo {title} si viene en otro idioma)."
            ."\n\nEl contenido debe priorizar interes humano, lectura fluida, contexto cultural y experiencia real."
            ."\nEvita el enfoque de SEO tradicional y no fuerces keywords exactas.";
    }
}

if (!function_exists('cbia_prompt_recommended_editable_default')) {
    function cbia_prompt_recommended_editable_default(): string {
        return
            "TONO Y ESTILO"
            ."\n- Profesional, cercano y natural."
            ."\n- Editorial y cultural, no enciclopedico."
            ."\n- Narrativo cuando sea adecuado, con criterio y punto de vista."
            ."\n- Pensado para lectores que no estaban buscando activamente el tema."
            ."\n\nESTRUCTURA OBLIGATORIA (no anadir ni eliminar secciones)"
            ."\n"
            ."\n1) Un encabezado usando la etiqueta <h2>"
            ."\n   Parrafo inicial usando la etiqueta <p>."
            ."\n   - NO usar la palabra \"Introduccion\" ni equivalentes."
            ."\n   - Extension: 180-220 palabras."
            ."\n"
            ."\n2) Tres bloques principales, cada uno con:"
            ."\n   - (Opcional) un subtitulo usando la etiqueta <h3> SOLO si aporta claridad real."
            ."\n   - Extension: 250-300 palabras por bloque."
            ."\n   - Listas SOLO cuando ayuden a la comprension (etiquetas <ul> y <li>)."
            ."\n"
            ."\n3) Seccion de preguntas frecuentes:"
            ."\n   - Un encabezado <h2> cuyo texto debe estar escrito en {IDIOMA_POST} y ser el equivalente natural a \"Preguntas frecuentes\" en ese idioma."
            ."\n   - Seis preguntas frecuentes, cada una con:"
            ."\n     - Pregunta en etiqueta <h3>."
            ."\n     - Respuesta en etiqueta <p> (120-150 palabras).";
    }
}

if (!function_exists('cbia_prompt_recommended_footer_template')) {
    function cbia_prompt_recommended_footer_template(): string {
        return
            "INSTRUCCION CRITICA"
            ."\n- Ninguna respuesta debe cortarse."
            ."\n- TODAS las respuestas deben terminar en punto final."
            ."\n\nIMAGENES"
            ."\nInserta marcadores de imagen SOLO donde aporten valor, usando el formato EXACTO:"
            ."\n[IMAGEN: descripcion breve, concreta, sin texto ni marcas de agua, estilo realista/editorial]"
            ."\n\nREGLAS DE OBLIGADO CUMPLIMIENTO"
            ."\n- NO usar la etiqueta <h1>."
            ."\n- NO anadir seccion de conclusion."
            ."\n- NO incluir CTA final."
            ."\n- NO usar las etiquetas: doctype, html, head, body, script, style, iframe, table, blockquote."
            ."\n- NO enlazar a webs externas (usar el texto plano \"(enlace interno)\" si es necesario)."
            ."\n- Evitar redundancias y muletillas."
            ."\n- No escribir con enfoque SEO por keyword exacta."
            ."\n\nEl resultado debe leerse como un articulo editorial premium, interesante por si mismo y adecuado para aparecer en Google Discover.";
    }
}

if (!function_exists('cbia_prompt_sanitize_editable_block')) {
    function cbia_prompt_sanitize_editable_block($text): string {
        $text = is_string($text) ? $text : '';
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // CAMBIO: elimina intentos de inyectar secciones/etiquetas bloqueadas en bloque editable.
        $blocked_prefixes = array(
            'INSTRUCCION CRITICA',
            'IMAGENES',
            'REGLAS DE OBLIGADO CUMPLIMIENTO',
        );
        $blocked_tags = array(
            '<h1', '<script', '<style', '<iframe', '<table', '<blockquote', '<!doctype', '<html', '<head', '<body',
        );

        $clean = array();
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line_trim = ltrim((string)$line);
            $normalized = function_exists('remove_accents') ? remove_accents($line_trim) : $line_trim;
            $upper = strtoupper($normalized);

            $skip = false;
            foreach ($blocked_prefixes as $prefix) {
                if (strpos($upper, $prefix) === 0) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            $line_lower = strtolower($line_trim);
            foreach ($blocked_tags as $tag) {
                if (strpos($line_lower, $tag) !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            $clean[] = $line;
        }

        $out = trim(implode("\n", $clean));
        if ($out === '') {
            $out = cbia_prompt_recommended_editable_default();
        }
        return $out;
    }
}

if (!function_exists('cbia_prompt_build_recommended_template')) {
    function cbia_prompt_build_recommended_template($editable = null): string {
        $editable_block = is_string($editable) ? $editable : cbia_prompt_recommended_editable_default();
        $editable_block = cbia_prompt_sanitize_editable_block($editable_block);
        return cbia_prompt_recommended_header_template() . "\n\n" . $editable_block . "\n\n" . cbia_prompt_recommended_footer_template();
    }
}

if (!function_exists('cbia_prompt_get_mode')) {
    function cbia_prompt_get_mode(array $settings): string {
        // CAMBIO: detectar si el modo fue elegido explicitamente en BD o viene del merge de defaults.
        $stored_raw = get_option('cbia_settings', array());
        $has_explicit_mode = is_array($stored_raw) && array_key_exists('blog_prompt_mode', $stored_raw);

        $mode = sanitize_key((string)($settings['blog_prompt_mode'] ?? ''));
        if ($has_explicit_mode && in_array($mode, array('recommended', 'legacy'), true)) {
            return $mode;
        }

        // CAMBIO: para instalaciones previas, si habia prompt historico, usar legacy por defecto.
        $legacy = trim((string)($settings['legacy_full_prompt'] ?? ''));
        if ($legacy === '') $legacy = trim((string)($settings['prompt_single_all'] ?? ''));
        return $legacy !== '' ? 'legacy' : 'recommended';
    }
}

if (!function_exists('cbia_prompt_get_legacy_template')) {
    function cbia_prompt_get_legacy_template(array $settings): string {
        // CAMBIO: compatibilidad con historico prompt_single_all.
        $legacy = trim((string)($settings['legacy_full_prompt'] ?? ''));
        if ($legacy !== '') return $legacy;
        $legacy = trim((string)($settings['prompt_single_all'] ?? ''));
        return $legacy;
    }
}

if (!function_exists('cbia_build_prompt_for_title')) {
    function cbia_build_prompt_for_title($title) {
        $s = cbia_get_settings();
        $idioma_post = trim((string)($s['post_language'] ?? 'espanol'));

        // CAMBIO: recomendado/legacy con compatibilidad.
        $mode = cbia_prompt_get_mode($s);
        if ($mode === 'legacy') {
            $prompt_unico = cbia_prompt_get_legacy_template($s);
            if ($prompt_unico === '') {
                $editable = (string)($s['blog_prompt_editable'] ?? cbia_prompt_recommended_editable_default());
                $prompt_unico = cbia_prompt_build_recommended_template($editable);
            }
        } else {
            $editable = (string)($s['blog_prompt_editable'] ?? cbia_prompt_recommended_editable_default());
            $prompt_unico = cbia_prompt_build_recommended_template($editable);
        }

        $prompt_unico = str_replace('{title}', (string)$title, $prompt_unico);
        // CAMBIO: idioma siempre forzado desde selector.
        $prompt_unico = str_replace('{IDIOMA_POST}', $idioma_post, $prompt_unico);

        return (string)$prompt_unico;
    }
}

