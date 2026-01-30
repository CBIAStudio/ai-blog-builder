<?php
/**
 * Prompt builder.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_build_prompt_for_title')) {
    function cbia_build_prompt_for_title($title) {
        $s = cbia_get_settings();
        $idioma_post = trim((string)($s['post_language'] ?? 'español'));

        $prompt_unico = $s['prompt_single_all'] ?? '';
        $prompt_unico = is_string($prompt_unico) ? trim($prompt_unico) : '';

        if ($prompt_unico === '') {
            $prompt_unico =
                "Escribe un POST COMPLETO en {IDIOMA_POST} y en HTML para \"{title}\", optimizado para Google Discover, con una extensión aproximada de 1600–1800 palabras (±10%)."
                ."\n\nREGLA DE IDIOMA (OBLIGATORIA)"
                ."\n- Todo el contenido debe estar escrito EXCLUSIVAMENTE en {IDIOMA_POST}."
                ."\n- Esto incluye encabezados, preguntas frecuentes y respuestas."
                ."\n- Está PROHIBIDO usar cualquier otro idioma en el contenido (salvo el título {title} si viene en otro idioma)."
                ."\n\nEl contenido debe priorizar interés humano, lectura fluida, contexto cultural y experiencia real. Evita el enfoque de SEO tradicional y no fuerces keywords exactas."
                ."\n\nTONO Y ESTILO"
                ."\n- Profesional, cercano y natural."
                ."\n- Editorial y cultural, no enciclopédico."
                ."\n- Narrativo cuando sea adecuado, con criterio y punto de vista."
                ."\n- Pensado para lectores que no estaban buscando activamente el tema."
                ."\n\nLa estructura debe ser EXACTA. No añadas ni elimines secciones:"
                ."\n- Párrafo inicial en <p> (180–220 palabras). NO usar la palabra \"Introducción\" ni equivalentes."
                ."\n- 3 bloques principales con <h2> y, SOLO si aporta claridad real, <h3> (250–300 palabras por bloque; usa listas <ul><li>…</li></ul> únicamente cuando ayuden a la comprensión)."
                ."\n- Sección de preguntas frecuentes:"
                ."\n  • Un <h2> cuyo texto debe estar en {IDIOMA_POST} y ser el equivalente natural a \"Preguntas frecuentes\" en ese idioma."
                ."\n  • 6 FAQs en el formato exacto <h3>Pregunta</h3><p>Respuesta</p> (120–150 palabras por respuesta)."
                ."\n\nINSTRUCCIÓN CRÍTICA: ninguna respuesta debe cortarse y TODAS las respuestas deben terminar en punto final."
                ."\n\nIMÁGENES"
                ."\nInserta marcadores de imagen SOLO donde aporten valor usando el formato EXACTO:"
                ."\n[IMAGEN: descripción breve, concreta, sin texto ni marcas de agua, estilo realista/editorial]"
                ."\n\nReglas de obligado cumplimiento:"
                ."\n• NO usar <h1>."
                ."\n• NO añadir sección de conclusión ni CTA final."
                ."\n• NO incluir <!DOCTYPE>, <html>, <head>, <body>, <script>, <style>, <iframe>, <table> ni <blockquote>."
                ."\n• NO enlazar a webs externas (usar el texto plano \"(enlace interno)\" si es necesario)."
                ."\n• Evitar redundancias y muletillas."
                ."\n• No escribir con enfoque SEO por keyword exacta.";
        }

        $prompt_unico = str_replace('{title}', $title, $prompt_unico);
        $prompt_unico = str_replace('{IDIOMA_POST}', $idioma_post, $prompt_unico);

        return $prompt_unico;
    }
}
