# AI Blog Builder Pro Pro (WordPress) v3.0.3

Genera entradas completas con IA (requiere la versión gratuita activa) (texto + imágenes) en una sola pasada. Incluye marcadores de imagen inteligentes, programación con reanudación por checkpoint, rellenado de imágenes pendientes y cálculo de costes (estimado y real).

## Novedades 3.0.3
- Preview SSE más robusto con fallback automático y cierre correcto del stream.
- Nuevo card de Article Preview con wordcount, imagen destacada y SEO en vivo.
- preview_token persistente para crear post desde la previsualización (borrador/publicar/programar).

Tabla de contenidos
- Qué hace y cómo funciona
- Flujo de trabajo (pestañas)
  - Configuración
  - Blog (creación con checkpoint)
  - Costes (estimación y real)
  - Yoast/SEO
  - Diagnóstico
- Marcadores de imagen y pendientes
- Programación y CRON
- Logs y diagnóstico
- Requisitos e instalación
- Solución de problemas
- Desarrollo y hooks
- Migración legacy (eliminada en 3.0)

## Qué hace y cómo funciona

El plugin llama a OpenAI (Responses) para generar el HTML de un post y procesa marcadores de imagen con OpenAI Images. Crea la destacada y las imágenes internas respetando el límite configurado. Si alguna imagen falla, deja un marcador "pendiente" oculto para rellenarlo luego (manual o por CRON).
Este plugin usa la API de OpenAI únicamente cuando el usuario lo activa desde el panel y con consentimiento explícito en Configuración.

Puntos clave:
- 1 destacada + (images_limit - 1) imágenes en contenido.
- Marcadores sobrantes se eliminan; marcadores fallidos se sustituyen por un "pendiente" oculto y rastreable.
- Reanudación con checkpoint: nunca bloquea la pantalla; procesa por tandas cortas y reprograma el siguiente evento.
- Log de actividad en vivo.

## Flujo de trabajo (pestañas)

### 1) Configuración
- OpenAI API Key y modelo de texto preferido (con fallback entre gpt-5 / gpt-4.1).
- Longitud objetivo, temperatura, límite de imágenes.
- Prompts de imagen por sección (intro/cuerpo/cierre/FAQ).
- Reglas: keywords -> categorías; "Tags permitidas" (lista blanca) para autoseleccionar etiquetas.
- Bloqueo de modelos (no se usan aunque están en el fallback).

### 2) Blog (creación con checkpoint)
- Títulos: manual o desde CSV (URL).
- Programación: "primera fecha/hora" + intervalo (días). Si no hay fecha, publica inmediato.
- Botones:
  - Probar Configuración.
  - Crear Blogs (con reanudación): encola un evento y comienza a procesar 1 título por tanda (ajustable en código). Reanuda automáticamente hasta terminar.
  - Detener (STOP) para cortar de forma segura.
  - Rellenar pendientes: llama a OpenAI Images para completar los "pendientes".
  - Limpiar checkpoint / Limpiar log.
- Estado: muestra checkpoint, última fecha programada y log en vivo.

### 3) Costes (estimación y real)
- Estimación rápida por post (texto, imágenes, SEO) según configuraciones y tabla de precios.
- Cálculo REAL "post-hoc" sumando cada llamada guardada (modelo real por llamada). Opciones:
  - Precio fijo por imagen (recomendado) con importes mini/full en USD.
  - Sobrecoste fijo por llamada de texto/SEO (USD) para cuadrar billing.
  - Multiplicador de ajuste total del coste real.
- Acciones: "Calcular" (usa real y, si falta, estimación) o "Calcular SOLO real". Log de costes en vivo.

### 4) Yoast/SEO (opcional)
- Genera metadescripción básica y focus keyphrase. Si tienes Yoast SEO activo, se aprovechan sus metas y hook para ampliar el relleno.
- Si no usas Yoast, el post se crea igualmente (las metas quedan como metadatos estándar; no es requisito).

### 5) Diagnóstico
- Resumen del entorno (WP, PHP, límites, debug).
- Comprobación rápida de permisos de escritura y estado de la API Key.
- Últimas líneas del log del plugin para soporte.

## Marcadores de imagen y pendientes

Formato en el HTML de texto: `[IMAGEN: descripción]`
- El motor extrae hasta (images_limit - 1) marcadores para contenido (la primera imagen va a destacada si procede).
- Si faltan marcadores, inserta automáticamente en zonas útiles (tras primer párrafo, antes de FAQ, cierre).
- Si sobran, los elimina.
- Si una imagen falla, el marcador se reemplaza por: `<span class="cbia-img-pendiente" style="display:none">[IMAGEN_PENDIENTE: desc]</span>`
  - Estos spans se ocultan y no rompen el layout; el rellenado posterior los sustituye por una `<img>` real.

Limpieza de artefactos
- El motor limpia puntos sueltos tras marcadores/pendientes (`</span>.`, `</p>.`, línea con "."), y colapsa saltos extra.

## Programación y CRON
- Al pulsar "Crear Blogs", se encola un evento y se procesa 1 post por tanda (para evitar timeouts). Si queda cola, reprograma la siguiente tanda.
- Puedes activar un CRON hourly para "Rellenar pendientes".
- STOP detiene con seguridad entre pasos.

## Logs y diagnóstico
- Log de actividad en vivo (AJAX) en la pestaña Blog.
- Log de Costes con tokens reales y llamadas.
- Mensajes claros en cada fase (cola, checkpoint, evento, imágenes, pendientes, etc.).

## Requisitos e instalación
- WordPress 6.9+ (probado en 6.9.0), PHP 8.2+.
- Multisite compatible (probado en entorno multisite).
- Clave de API de OpenAI con permisos mínimos.
- Yoast SEO: opcional (el plugin funciona sin él).

Instalación:
1) Copia la carpeta en `wp-content/plugins/`.
2) Activa el plugin.
3) Ve a Ajustes -> AI Blog Builder Pro, pon tu API Key y configura.

## Solución de problemas
- "No hace nada": revisa log; si el título ya existe, se omite. Asegúrate de tener Títulos válidos (manual o CSV) y que el checkpoint no está bloqueado.
- Puntos sueltos al final: el motor limpia casos típicos (`</span>.`, `</p>.`, líneas con "."). Si detectas un patrón nuevo, actualiza y vuelve a procesar.
- Imágenes que no salen: revisa el log (fallo de generación, cuota, red). Usa "Rellenar pendientes".
- Costes no cuadran: activa "precio fijo por imagen", ajusta importes mini/full y (si hace falta) el sobrecoste por llamada y el multiplicador real.

## Desarrollo y hooks
Estructura (v2.3):
- `includes/core/`: bootstrap, hooks y wiring de dependencias.
- `includes/admin/`: controladores de pestañas y vistas (`admin/views/`).
- `includes/engine/`: lógica de generación (texto, imágenes, pendientes, posts).
- `includes/domain/`: dominios puros (p. ej., costes).
- `includes/integrations/`: integraciones externas (Yoast, OpenAI).
- `includes/jobs/`: CRON y tareas background.
- `includes/support/`: helpers (sanitizado, logging, encoding).
- `includes/legacy/`: cargadores legacy para compatibilidad.

Hooks disponibles:
- `do_action('cbia_after_post_created', $post_id, $title, $html, $usage, $model_used)`
  - útil para enriquecer SEO, relacionar contenido, etc.

Permisos y seguridad
- Todas las acciones de admin requieren `manage_options` y nonce.

## Migración legacy (eliminada en 3.0)
Los wrappers legacy (`includes/legacy/cbia-*.php`) han sido eliminados en la versión 3.0.
Si tu instalación dependía de esos archivos, migra a las rutas nuevas en `includes/` (core/admin/engine/domain).

Licencia
- GPLv2 o posterior.




