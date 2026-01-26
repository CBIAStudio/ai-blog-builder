=== Creador Blog IA ===
Contributors: webgoh
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 5.7.9
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generación de entradas con IA (texto + imágenes), reanudación con checkpoint y rellenado de imágenes pendientes. Cálculo de costes estimado y real.

== Description ==

Plugin para crear posts completos con OpenAI (texto + imágenes) sin bloquear la pantalla. Procesa por tandas, reanuda con checkpoint, asigna categorías/etiquetas por reglas y permite rellenar imágenes pendientes (manual o por CRON).

Características clave:
* 1 destacada + (images_limit - 1) imágenes en contenido
* Marcadores `[IMAGEN: ...]` insertados automáticamente si faltan; los sobrantes se eliminan
* Log en vivo y STOP seguro
* Costes: estimación rápida y cálculo REAL por llamada (con precio fijo por imagen opcional)
* Compatible con Yoast SEO (opcional). El plugin NO requiere Yoast; si está activo, se integran metas y hooks.

== Installation ==
1. Sube la carpeta del plugin a `wp-content/plugins/`.
2. Activa el plugin desde “Plugins”.
3. Ve a Ajustes → Creador Blog IA, añade tu API Key y configura.

== Frequently Asked Questions ==

= ¿Cómo funciona la reanudación? =
Usa un checkpoint que guarda cola, índice y totales. El botón “Crear Blogs” encola un evento; cada evento procesa N posts (por defecto 1) y, si queda cola, reprograma el siguiente.

= ¿Qué pasa si una imagen falla? =
Se reemplaza por un marcador “pendiente” oculto. Luego puedes pulsar “Rellenar pendientes” o dejar que el CRON lo haga.

= ¿Por qué el coste real no coincide? =
Activa “precio fijo por imagen”, ajusta importes mini/full y, si fuera necesario, el sobrecoste por llamada de texto/SEO y el multiplicador de ajuste del coste real.

== Changelog ==
* 5.7.9 – Reanudación estable, rellenado de pendientes, costes estimado/real, mejoras de limpieza y logs.
