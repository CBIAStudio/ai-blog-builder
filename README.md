# AI Blog Builder (FREE)

Genera entradas con IA (texto + 1 imagen destacada) con reanudación por checkpoint y log en vivo. Ideal para crear posts sin bloquear el admin.

## Qué hace
- Genera el texto completo del post con OpenAI.
- Crea 1 imagen destacada.
- Reanuda automáticamente con checkpoint (procesa por tandas).
- Log en vivo y STOP seguro.

## Requisitos
- WordPress 6.9+
- PHP 8.2+
- API Key de OpenAI con consentimiento explícito.

## Uso básico
1. Configura tu API Key en **Ajustes → AI Blog Builder**.
2. Escribe tus títulos (uno por línea) en la pestaña **Blog**.
3. Pulsa **“Crear blogs (con reanudación)”**.

## Transparencia
Este plugin usa la API de OpenAI únicamente cuando el usuario lo activa desde el panel y con consentimiento explícito.

## Estructura (v1.0.1)
- `includes/core/`: bootstrap, hooks y wiring
- `includes/admin/`: controladores y vistas
- `includes/engine/`: generación de texto/imagenes
- `includes/integrations/`: OpenAI (y Yoast si se activa)
- `includes/support/`: helpers (logging, sanitizado, encoding)

## Cambios recientes
### 1.0.1
- Fix del selector de autor por defecto.
- Botón de “Añadir entrada con IA” desde assets (sin inline CSS/JS).
- Ajuste de labels de longitud y enlace de API Key.

### 1.0.0
- Primera versión estable.

## Versión actual
- 1.0.1 (FREE)
