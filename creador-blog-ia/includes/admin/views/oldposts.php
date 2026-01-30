<?php
if (!defined('ABSPATH')) exit;

// Oldposts tab view (extracted from legacy cbia-oldposts.php)

if (!current_user_can('manage_options')) return;

$service = isset($cbia_oldposts_service) ? $cbia_oldposts_service : null;
$settings = $service && method_exists($service, 'get_settings')
    ? $service->get_settings()
    : cbia_oldposts_get_settings();

// Defaults (presets)
$defaults = array(
    'batch_size'         => 20,
    'scope'              => 'all',

    'filter_mode'        => 'older',
    'older_than_days'    => 180,
    'date_from'          => '',
    'date_to'            => '',

    'images_limit'       => 3,
    'post_ids'           => '',
    'category_id'        => 0,
    'author_id'          => 0,
    'dry_run'            => 0,

    // Básico recomendado (lo que dices que casi siempre usarás)
    'do_note'            => 1,
    'force_note'         => 0,

    'do_yoast_metadesc'  => 1,
    'do_yoast_focuskw'   => 1,
    'do_yoast_title'     => 0,
    'force_yoast'        => 0,

    'do_yoast_reindex'   => 1,

    'do_title'           => 0,
    'force_title'        => 0,

    'do_content'         => 1,
    'force_content'      => 0,
    'do_content_no_images'    => 0,
    'force_content_no_images' => 0,

    'do_images_reset'    => 1,
    'force_images_reset' => 0,
    'clear_featured'     => 0,
    'do_images_content_only'    => 0,
    'force_images_content_only' => 0,
    'do_featured_only'          => 0,
    'force_featured_only'       => 0,
    'featured_remove_old'       => 0,

    'do_categories'      => 1,
    'force_categories'   => 0,

    'do_tags'            => 1,
    'force_tags'         => 0,
);
$settings = array_merge($defaults, is_array($settings) ? $settings : array());

// Migración suave desde v2 si existían keys antiguas
if (isset($settings['do_yoast_metas']) && !isset($settings['do_yoast_metadesc'])) {
    $val = !empty($settings['do_yoast_metas']) ? 1 : 0;
    $settings['do_yoast_metadesc'] = $val;
    $settings['do_yoast_focuskw']  = $val;
}
if (isset($settings['force_yoast_metas']) && !isset($settings['force_yoast'])) {
    $settings['force_yoast'] = !empty($settings['force_yoast_metas']) ? 1 : 0;
}

// Handle POST

if ($service && method_exists($service, 'handle_post')) {
    $settings = $service->handle_post($settings);
} elseif (function_exists('cbia_oldposts_handle_post')) {
    $settings = cbia_oldposts_handle_post($settings);
}


$log = $service && method_exists($service, 'get_log')
    ? $service->get_log()
    : cbia_oldposts_get_log();
$fm = $settings['filter_mode'];

// Summary defaults (para UX)
$defaults_summary = array();
if (!empty($settings['do_note'])) $defaults_summary[] = 'Nota actualizado';
if (!empty($settings['do_yoast_metadesc']) || !empty($settings['do_yoast_focuskw']) || !empty($settings['do_yoast_title'])) {
    $ys = array();
    if (!empty($settings['do_yoast_metadesc'])) $ys[] = 'metadesc';
    if (!empty($settings['do_yoast_focuskw']))  $ys[] = 'keyphrase';
    if (!empty($settings['do_yoast_title']))    $ys[] = 'seo title';
    $defaults_summary[] = 'Yoast: '.implode(', ', $ys);
}
if (!empty($settings['do_yoast_reindex'])) $defaults_summary[] = 'Yoast reindex';
if (!empty($settings['do_content'])) $defaults_summary[] = 'Contenido IA';
if (!empty($settings['do_content_no_images'])) $defaults_summary[] = 'Contenido IA (sin imágenes)';
if (!empty($settings['do_images_reset'])) $defaults_summary[] = 'Imágenes pendientes';
if (!empty($settings['do_images_content_only'])) $defaults_summary[] = 'Imágenes contenido';
if (!empty($settings['do_featured_only'])) $defaults_summary[] = 'Solo destacada';
if (!empty($settings['do_categories'])) $defaults_summary[] = 'Categorías';
if (!empty($settings['do_tags'])) $defaults_summary[] = 'Etiquetas';
if (!empty($settings['do_title'])) $defaults_summary[] = 'Título IA';

$defaults_summary_text = !empty($defaults_summary) ? implode(' · ', $defaults_summary) : 'Sin acciones por defecto';

?>
<div class="wrap" style="padding-left:0;">
    <h2>Actualizar antiguos</h2>

    <h3>Configuración (preselección)</h3>
    <p class="description" style="max-width:980px;">
        Esto define lo que normalmente harás “casi siempre”. En ejecución puedes usar esto tal cual o personalizar solo esa vez.
        <br><strong>Qué significa “Forzar”:</strong> rehace la acción aunque ya exista / esté marcada como hecha.
    </p>

    <form method="post" action="" autocomplete="off">
        <input type="hidden" name="cbia_form" value="oldposts_settings" />
        <?php wp_nonce_field('cbia_oldposts_settings_nonce'); ?>

        <table class="form-table" style="max-width:980px;">
            <tr>
                <th>Ámbito</th>
                <td>
                    <label style="margin-right:18px;">
                        <input type="radio" name="scope" value="all" <?php checked($settings['scope'], 'all'); ?> />
                        Todos los posts
                    </label>
                    <label>
                        <input type="radio" name="scope" value="plugin" <?php checked($settings['scope'], 'plugin'); ?> />
                        Solo posts del plugin (<code>_cbia_created=1</code>)
                    </label>
                </td>
            </tr>

            <tr>
                <th>Tamaño de lote</th>
                <td>
                    <input type="number" name="batch_size" min="1" max="200" value="<?php echo esc_attr((int)$settings['batch_size']); ?>" style="width:120px;" />
                </td>
            </tr>

            <tr>
                <th>Filtro por fechas</th>
                <td>
                    <label style="margin-right:18px;">
                        <input type="radio" name="filter_mode" value="older" <?php checked($fm, 'older'); ?> />
                        Más antiguos que (días)
                    </label>
                    <label>
                        <input type="radio" name="filter_mode" value="range" <?php checked($fm, 'range'); ?> />
                        Rango (desde / hasta)
                    </label>

                    <div style="margin-top:10px;">
                        <div id="cbia_old_filter_older" style="<?php echo ($fm==='older'?'':'display:none;'); ?>">
                            <input type="number" name="older_than_days" min="1" value="<?php echo esc_attr((int)$settings['older_than_days']); ?>" style="width:120px;" />
                            <span class="description">Ej: 180</span>
                        </div>

                        <div id="cbia_old_filter_range" style="<?php echo ($fm==='range'?'':'display:none;'); ?>">
                            <label style="margin-right:10px;">
                                Desde:
                                <input type="date" name="date_from" value="<?php echo esc_attr((string)$settings['date_from']); ?>" />
                            </label>
                            <label>
                                Hasta:
                                <input type="date" name="date_to" value="<?php echo esc_attr((string)$settings['date_to']); ?>" />
                            </label>
                            <p class="description">Se usa <code>post_date_gmt</code>. Si dejas vacío desde/hasta, se aplica solo el otro límite.</p>
                        </div>
                    </div>
                </td>
            </tr>

            <tr>
                <th>Imágenes (límite)</th>
                <td>
                    <input type="number" name="images_limit" min="1" max="10" value="<?php echo esc_attr((int)$settings['images_limit']); ?>" style="width:120px;" />
                    <p class="description">Se usa en regeneración de contenido y/o reset de pendientes.</p>
                </td>
            </tr>

            <tr>
                <th>Filtros avanzados</th>
                <td>
                    <div style="margin-bottom:8px;">
                        <label>
                            IDs concretos (opcional):
                            <input
                                type="text"
                                name="run_post_ids"
                                value="<?php echo esc_attr((string)($settings['post_ids'] ?? '')); ?>"
                                placeholder="123,456"
                                style="width:420px;"
                            />
                        </label>
                        <p class="description" style="margin:4px 0 0;">
                            Si indicas IDs, se ignoran los filtros por fecha.
                        </p>
                    </div>

                    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                        <label>
                            Categoría:
                            <?php
                            wp_dropdown_categories(array(
                                'taxonomy' => 'category',
                                'hide_empty' => false,
                                'name' => 'category_id',
                                'id' => 'cbia_category_id',
                                'selected' => (int)($settings['category_id'] ?? 0),
                                'show_option_all' => 'Todas',
                            ));
                            ?>
                        </label>

                        <label>
                            Autor:
                            <?php
                            wp_dropdown_users(array(
                                'name' => 'author_id',
                                'id' => 'cbia_author_id',
                                'selected' => (int)($settings['author_id'] ?? 0),
                                'show_option_all' => 'Todos',
                            ));
                            ?>
                        </label>

                        <label>
                            <input type="checkbox" name="dry_run" value="1" <?php checked((int)($settings['dry_run'] ?? 0), 1); ?> />
                            Dry run por defecto (solo listar)
                        </label>
                    </div>
                </td>
            </tr>


            <tr>
                <th>Acciones por defecto</th>
                <td style="padding-top:12px;">
                    <div style="padding:12px;border:1px solid #ddd;border-radius:8px;background:#fff;">
                        <div style="font-weight:600;margin-bottom:8px;">Básico</div>

                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="do_note" value="1" <?php checked((int)$settings['do_note'], 1); ?> />
                            Añadir nota “Actualizado el …”
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="force_note" value="1" <?php checked((int)$settings['force_note'], 1); ?> />
                                    Forzar (reemplazar fecha si ya existe)
                                </label>
                            </span>
                        </label>

                        <div style="font-weight:600;margin:12px 0 8px;">Yoast SEO</div>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="do_yoast_metadesc" value="1" <?php checked((int)$settings['do_yoast_metadesc'], 1); ?> />
                            Meta description
                        </label>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="do_yoast_focuskw" value="1" <?php checked((int)$settings['do_yoast_focuskw'], 1); ?> />
                            Keyphrase (focus keyword)
                        </label>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="do_yoast_title" value="1" <?php checked((int)$settings['do_yoast_title'], 1); ?> />
                            SEO title (título Yoast)
                        </label>
                        <label style="display:block;margin:8px 0;">
                            <input type="checkbox" name="force_yoast" value="1" <?php checked((int)$settings['force_yoast'], 1); ?> />
                            Forzar Yoast (sobrescribir aunque existan)
                        </label>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="do_yoast_reindex" value="1" <?php checked((int)$settings['do_yoast_reindex'], 1); ?> />
                            Reindex / semáforo (best effort)
                        </label>

                        <div style="font-weight:600;margin:12px 0 8px;">Contenido e imágenes</div>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="do_content" value="1" <?php checked((int)$settings['do_content'], 1); ?> />
                            Regenerar contenido con IA
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="force_content" value="1" <?php checked((int)$settings['force_content'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                        </label>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="do_content_no_images" value="1" <?php checked((int)$settings['do_content_no_images'], 1); ?> />
                            Regenerar contenido con IA (sin imágenes)
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="force_content_no_images" value="1" <?php checked((int)$settings['force_content_no_images'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                        </label>

                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="do_images_reset" value="1" <?php checked((int)$settings['do_images_reset'], 1); ?> />
                            Imágenes: marcar como pendientes (reset)
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="force_images_reset" value="1" <?php checked((int)$settings['force_images_reset'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="clear_featured" value="1" <?php checked((int)$settings['clear_featured'], 1); ?> />
                                    Quitar imagen destacada (no habitual)
                                </label>
                            </span>
                        </label>

                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="do_images_content_only" value="1" <?php checked((int)$settings['do_images_content_only'], 1); ?> />
                            Imágenes: regenerar solo las del contenido
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="force_images_content_only" value="1" <?php checked((int)$settings['force_images_content_only'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                        </label>

                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="do_featured_only" value="1" <?php checked((int)$settings['do_featured_only'], 1); ?> />
                            Imagen destacada: regenerar solo destacada
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="force_featured_only" value="1" <?php checked((int)$settings['force_featured_only'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="featured_remove_old" value="1" <?php checked((int)$settings['featured_remove_old'], 1); ?> />
                                    Quitar destacada anterior
                                </label>
                            </span>
                        </label>

                        <div style="font-weight:600;margin:12px 0 8px;">Taxonomías</div>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="do_categories" value="1" <?php checked((int)$settings['do_categories'], 1); ?> />
                            Recalcular categorías
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="force_categories" value="1" <?php checked((int)$settings['force_categories'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                        </label>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="do_tags" value="1" <?php checked((int)$settings['do_tags'], 1); ?> />
                            Recalcular etiquetas
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="force_tags" value="1" <?php checked((int)$settings['force_tags'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                        </label>

                        <div style="font-weight:600;margin:12px 0 8px;">Opcional</div>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="do_title" value="1" <?php checked((int)$settings['do_title'], 1); ?> />
                            Optimizar título con IA
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="force_title" value="1" <?php checked((int)$settings['force_title'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                        </label>

                        <p class="description" style="margin-top:10px;">
                            Consejo: si regeneras contenido, normalmente querrás también categorías/etiquetas + Yoast.
                        </p>
                    </div>
                </td>
            </tr>
        </table>

        <p>
            <button type="submit" class="button button-primary">Guardar configuración</button>
        </p>
    </form>

    <hr />

    <h3>Ejecución</h3>
    <p class="description" style="max-width:980px;">
        Por defecto se ejecuta con tu preselección guardada:
        <strong><?php echo esc_html($defaults_summary_text); ?></strong>
    </p>

    <form method="post" action="" autocomplete="off">
        <input type="hidden" name="cbia_form" value="oldposts_actions" />
        <?php wp_nonce_field('cbia_oldposts_actions_nonce'); ?>

        <table class="form-table" style="max-width:980px;">
            <tr>
                <th>Lote</th>
                <td>
                    <input type="number" name="run_batch_size" min="1" max="200" value="<?php echo esc_attr((int)$settings['batch_size']); ?>" style="width:120px;" />
                </td>
            </tr>

            <tr>
                <th>Ámbito</th>
                <td>
                    <label>
                        <input type="checkbox" name="run_scope_plugin" value="1" <?php checked($settings['scope'], 'plugin'); ?> />
                        Procesar solo <code>_cbia_created=1</code>
                    </label>
                </td>
            </tr>

            <tr>
                <th>Filtro</th>
                <td>
                    <label style="margin-right:18px;">
                        <input type="radio" name="run_filter_mode" value="older" <?php checked($settings['filter_mode'], 'older'); ?> />
                        Más antiguos que (días)
                    </label>
                    <label>
                        <input type="radio" name="run_filter_mode" value="range" <?php checked($settings['filter_mode'], 'range'); ?> />
                        Rango
                    </label>

                    <div style="margin-top:10px;">
                        <div id="cbia_run_filter_older" style="<?php echo ($settings['filter_mode']==='older'?'':'display:none;'); ?>">
                            <input type="number" name="run_older_than_days" min="1" value="<?php echo esc_attr((int)$settings['older_than_days']); ?>" style="width:120px;" />
                        </div>

                        <div id="cbia_run_filter_range" style="<?php echo ($settings['filter_mode']==='range'?'':'display:none;'); ?>">
                            <label style="margin-right:10px;">
                                Desde:
                                <input type="date" name="run_date_from" value="<?php echo esc_attr((string)$settings['date_from']); ?>" />
                            </label>
                            <label>
                                Hasta:
                                <input type="date" name="run_date_to" value="<?php echo esc_attr((string)$settings['date_to']); ?>" />
                            </label>
                        </div>
                    </div>
                </td>
            </tr>

            <tr>
                <th>Imágenes (límite)</th>
                <td>
                    <input type="number" name="run_images_limit" min="1" max="10" value="<?php echo esc_attr((int)$settings['images_limit']); ?>" style="width:120px;" />
                </td>
            </tr>

            <tr>
                <th>Filtros avanzados</th>
                <td>
                    <div style="margin-bottom:8px;">
                        <label>
                            IDs concretos (opcional):
                            <input
                                type="text"
                                name="post_ids"
                                value="<?php echo esc_attr((string)($settings['post_ids'] ?? '')); ?>"
                                placeholder="123,456"
                                style="width:420px;"
                            />
                        </label>
                        <p class="description" style="margin:4px 0 0;">
                            Si indicas IDs, se ignoran los filtros por fecha.
                        </p>
                    </div>

                    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
                        <label>
                            Categoría:
                            <?php
                            wp_dropdown_categories(array(
                                'taxonomy' => 'category',
                                'hide_empty' => false,
                                'name' => 'run_category_id',
                                'id' => 'cbia_run_category_id',
                                'selected' => (int)($settings['category_id'] ?? 0),
                                'show_option_all' => 'Todas',
                            ));
                            ?>
                        </label>

                        <label>
                            Autor:
                            <?php
                            wp_dropdown_users(array(
                                'name' => 'run_author_id',
                                'id' => 'cbia_run_author_id',
                                'selected' => (int)($settings['author_id'] ?? 0),
                                'show_option_all' => 'Todos',
                            ));
                            ?>
                        </label>

                        <label>
                            <input type="checkbox" name="run_dry_run" value="1" <?php checked((int)($settings['dry_run'] ?? 0), 1); ?> />
                            Dry run (solo listar)
                        </label>
                    </div>
                </td>
            </tr>

            <tr>
                <th>Personalizar esta ejecución</th>
                <td>
                    <label>
                        <input type="checkbox" name="run_custom_actions" id="cbia_run_custom_actions" value="1" />
                        Quiero elegir acciones distintas a mi preselección (solo para esta vez)
                    </label>

                    <div id="cbia_run_custom_box" style="display:none;margin-top:12px;padding:12px;border:1px solid #ddd;border-radius:8px;background:#fff;">
                        <div style="font-weight:600;margin-bottom:8px;">Acciones (solo esta ejecución)</div>

                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="run_do_note" value="1" <?php checked((int)$settings['do_note'], 1); ?> />
                            Nota “Actualizado el …”
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="run_force_note" value="1" <?php checked((int)$settings['force_note'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                        </label>

                        <div style="font-weight:600;margin:12px 0 8px;">Yoast SEO</div>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="run_do_yoast_metadesc" value="1" <?php checked((int)$settings['do_yoast_metadesc'], 1); ?> />
                            Meta description
                        </label>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="run_do_yoast_focuskw" value="1" <?php checked((int)$settings['do_yoast_focuskw'], 1); ?> />
                            Keyphrase (focus keyword)
                        </label>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox" name="run_do_yoast_title" value="1" <?php checked((int)$settings['do_yoast_title'], 1); ?> />
                            SEO title (título Yoast)
                        </label>
                        <label style="display:block;margin:8px 0;">
                            <input type="checkbox" name="run_force_yoast" value="1" <?php checked((int)$settings['force_yoast'], 1); ?> />
                            Forzar Yoast
                        </label>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="run_do_yoast_reindex" value="1" <?php checked((int)$settings['do_yoast_reindex'], 1); ?> />
                            Reindex / semáforo (best effort)
                        </label>

                        <div style="font-weight:600;margin:12px 0 8px;">Contenido e imágenes</div>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="run_do_content" value="1" <?php checked((int)$settings['do_content'], 1); ?> />
                            Contenido (IA)
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="run_force_content" value="1" <?php checked((int)$settings['force_content'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                        </label>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="run_do_images_reset" value="1" <?php checked((int)$settings['do_images_reset'], 1); ?> />
                            Imágenes: reset pendientes
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="run_force_images_reset" value="1" <?php checked((int)$settings['force_images_reset'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="run_clear_featured" value="1" <?php checked((int)$settings['clear_featured'], 1); ?> />
                                    Quitar destacada
                                </label>
                            </span>
                        </label>

                        <div style="font-weight:600;margin:12px 0 8px;">Taxonomías</div>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="run_do_categories" value="1" <?php checked((int)$settings['do_categories'], 1); ?> />
                            Categorías
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="run_force_categories" value="1" <?php checked((int)$settings['force_categories'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                        </label>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="run_do_tags" value="1" <?php checked((int)$settings['do_tags'], 1); ?> />
                            Etiquetas
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="run_force_tags" value="1" <?php checked((int)$settings['force_tags'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                        </label>

                        <div style="font-weight:600;margin:12px 0 8px;">Opcional</div>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="run_do_title" value="1" <?php checked((int)$settings['do_title'], 1); ?> />
                            Título (IA)
                            <span style="margin-left:14px;">
                                <label>
                                    <input type="checkbox" name="run_force_title" value="1" <?php checked((int)$settings['force_title'], 1); ?> />
                                    Forzar
                                </label>
                            </span>
                        </label>

                        <p class="description" style="margin-top:10px;">
                            Si no marcas “Personalizar”, se usarán tus acciones por defecto sin más.
                        </p>
                    </div>
                </td>
            </tr>
        </table>

        <div
            id="cbia-oldposts-summary"
            class="notice notice-info"
            style="max-width:980px;margin:8px 0 12px;display:none;"
            data-default-do-content="<?php echo (int)$settings['do_content']; ?>"
            data-default-do-content-no-images="<?php echo (int)($settings['do_content_no_images'] ?? 0); ?>"
            data-default-do-title="<?php echo (int)$settings['do_title']; ?>"
            data-default-do-images-reset="<?php echo (int)$settings['do_images_reset']; ?>"
            data-default-do-images-content-only="<?php echo (int)($settings['do_images_content_only'] ?? 0); ?>"
            data-default-do-featured-only="<?php echo (int)($settings['do_featured_only'] ?? 0); ?>"
        ></div>

        <div style="margin:6px 0 10px;">
            <span class="description" style="margin-right:8px;"><strong>Acciones rápidas:</strong></span>
            <button type="submit" class="button" name="cbia_action" value="run_quick_yoast_metas">Solo metas Yoast</button>
            <button type="submit" class="button" name="cbia_action" value="run_quick_yoast_reindex" style="margin-left:6px;">Solo reindex Yoast</button>
            <button type="submit" class="button" name="cbia_action" value="run_quick_featured" style="margin-left:6px;">Solo destacada</button>
            <button type="submit" class="button" name="cbia_action" value="run_quick_images_only" style="margin-left:6px;">Solo imágenes contenido</button>
            <button type="submit" class="button" name="cbia_action" value="run_quick_content_only" style="margin-left:6px;">Solo contenido (sin imágenes)</button>

            <span style="margin-left:12px;">
                <label style="margin-right:8px;">
                    <input type="checkbox" name="run_featured_remove_old" value="1" />
                    Quitar destacada anterior
                </label>
                <label style="margin-right:8px;">
                    <input type="checkbox" name="run_force_images_content_only" value="1" />
                    Forzar imágenes
                </label>
                <label>
                    <input type="checkbox" name="run_force_content_no_images" value="1" />
                    Forzar contenido
                </label>
            </span>
        </div>

        <p>
            <button type="submit" class="button button-primary" name="cbia_action" value="run_oldposts">
                Ejecutar lote
            </button>

            <button type="submit" class="button" name="cbia_action" value="stop" style="margin-left:8px;background:#b70000;color:#fff;">
                Detener
            </button>

            <button type="submit" class="button button-secondary" name="cbia_action" value="clear_log" style="margin-left:8px;">
                Limpiar log
            </button>
        </p>
    </form>

    <h3>Log</h3>
    <textarea id="cbia-oldposts-log" rows="14" cols="120" readonly style="background:#f9f9f9;width:100%;"><?php echo esc_textarea($log); ?></textarea>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Arreglo defensivo de mojibake (texto mal decodificado).
        // No toca la lógica: solo corrige legibilidad en la UI.
        function tryDecodeLatin1ToUtf8(str) {
            try {
                // Patrón típico: UTF-8 leído como Latin-1.
                return decodeURIComponent(escape(str));
            } catch (e) {
                return str;
            }
        }
        function fixMojibakeInTextNodes(root) {
            if (!root || !root.ownerDocument) return;
            const doc = root.ownerDocument;
            const walker = doc.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            const suspicious = /[\u00C3\u00C2\u00E2]/;
            let node;
            while ((node = walker.nextNode())) {
                const original = node.nodeValue;
                if (!original || !suspicious.test(original)) continue;
                let fixed = tryDecodeLatin1ToUtf8(original);
                // Algunos fragmentos están doblemente rotos.
                if (fixed !== original && suspicious.test(fixed)) {
                    fixed = tryDecodeLatin1ToUtf8(fixed);
                }
                if (fixed && fixed !== original) {
                    node.nodeValue = fixed;
                }
            }
        }
        const wrap = document.querySelector('.wrap');
        if (wrap) {
            fixMojibakeInTextNodes(wrap);
        }

        function bindFilterToggles(prefix){
            const olderBox = document.getElementById(prefix + '_filter_older');
            const rangeBox = document.getElementById(prefix + '_filter_range');
            const name = (prefix === 'cbia_old') ? 'filter_mode' : 'run_filter_mode';
            const radios = document.querySelectorAll('input[name="'+name+'"]');
            radios.forEach(r => r.addEventListener('change', function(){
                if(this.value === 'range'){
                    if(olderBox) olderBox.style.display='none';
                    if(rangeBox) rangeBox.style.display='';
                }else{
                    if(olderBox) olderBox.style.display='';
                    if(rangeBox) rangeBox.style.display='none';
                }
            }));
        }
        bindFilterToggles('cbia_old');
        bindFilterToggles('cbia_run');

        const custom = document.getElementById('cbia_run_custom_actions');
        const box = document.getElementById('cbia_run_custom_box');
        if (custom && box) {
            custom.addEventListener('change', function(){
                box.style.display = this.checked ? '' : 'none';
            });
        }

        // Resumen + confirmación antes de ejecutar
        const summary = document.getElementById('cbia-oldposts-summary');
        const actionsForm = summary ? summary.closest('form') : null;
        if (summary && actionsForm) {
            const getDefaultFlag = (key) => {
                const v = summary.dataset[key];
                return v === '1';
            };
            const isChecked = (name) => {
                const el = actionsForm.querySelector('[name="' + name + '"]');
                return !!el && !!el.checked;
            };
            const getValue = (name) => {
                const el = actionsForm.querySelector('[name="' + name + '"]');
                return el ? String(el.value || '').trim() : '';
            };

            function computeFlags() {
                const customOn = isChecked('run_custom_actions');
                if (customOn) {
                    return {
                        doContent: isChecked('run_do_content'),
                        doContentNoImages: isChecked('run_do_content_no_images'),
                        doTitle: isChecked('run_do_title'),
                        doImagesReset: isChecked('run_do_images_reset'),
                        doImagesContentOnly: isChecked('run_do_images_content_only'),
                        doFeaturedOnly: isChecked('run_do_featured_only'),
                    };
                }
                return {
                    doContent: getDefaultFlag('defaultDoContent'),
                    doContentNoImages: getDefaultFlag('defaultDoContentNoImages'),
                    doTitle: getDefaultFlag('defaultDoTitle'),
                    doImagesReset: getDefaultFlag('defaultDoImagesReset'),
                    doImagesContentOnly: getDefaultFlag('defaultDoImagesContentOnly'),
                    doFeaturedOnly: getDefaultFlag('defaultDoFeaturedOnly'),
                };
            }

            function updateSummary() {
                const flags = computeFlags();
                const actions = [];
                if (flags.doContent) actions.push('contenido IA');
                if (flags.doContentNoImages) actions.push('contenido IA (sin imágenes)');
                if (flags.doTitle) actions.push('título IA');
                if (flags.doImagesReset) actions.push('reset imágenes');
                if (flags.doImagesContentOnly) actions.push('solo imágenes contenido');
                if (flags.doFeaturedOnly) actions.push('solo destacada');
                if (actions.length === 0) actions.push('sin acciones IA');

                const scopePlugin = isChecked('run_scope_plugin');
                const ids = getValue('run_post_ids');
                const cat = getValue('run_category_id');
                const author = getValue('run_author_id');
                const dryRun = isChecked('run_dry_run');

                const filters = [];
                filters.push(scopePlugin ? 'solo plugin' : 'todos los posts');
                if (ids) filters.push('IDs: ' + ids);
                if (!ids && cat && cat !== '0') filters.push('categoría #' + cat);
                if (!ids && author && author !== '0') filters.push('autor #' + author);
                if (dryRun) filters.push('DRY RUN');

                summary.style.display = '';
                summary.innerHTML =
                    '<p style="margin:0;"><strong>Resumen:</strong> ' +
                    actions.join(', ') +
                    ' | ' +
                    filters.join(' · ') +
                    '</p>';
            }

            actionsForm.addEventListener('change', updateSummary);
            updateSummary();

            actionsForm.addEventListener('submit', function(e) {
                const flags = computeFlags();
                const aiRisk = flags.doContent || flags.doContentNoImages || flags.doTitle || flags.doImagesReset || flags.doImagesContentOnly || flags.doFeaturedOnly;
                if (!aiRisk) return;
                const ok = window.confirm('Se van a ejecutar acciones con IA que pueden consumir créditos. ¿Continuar?');
                if (!ok) e.preventDefault();
            });
        }

        // Auto-refresh log
        const logBox = document.getElementById('cbia-oldposts-log');
                function refreshLog(){
                    if (typeof ajaxurl === 'undefined') return;
                    const nonce = (window.CBIA && CBIA.nonce) ? CBIA.nonce : '';
                    const url = ajaxurl + '?action=cbia_get_oldposts_log' + (nonce ? '&_ajax_nonce=' + encodeURIComponent(nonce) : '');
                    fetch(url, { credentials: 'same-origin' })
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
