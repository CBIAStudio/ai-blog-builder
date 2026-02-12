<?php
/**
 * Bootstrap for the 3.0 structure.
 */

if (!defined('ABSPATH')) exit;

$cbia_new_files = array(
    CBIA_INCLUDES_DIR . 'core/loader.php',
    CBIA_INCLUDES_DIR . 'core/container.php',
    CBIA_INCLUDES_DIR . 'core/wiring.php',
    CBIA_INCLUDES_DIR . 'core/hooks.php',
    CBIA_INCLUDES_DIR . 'admin/admin-router.php',
    CBIA_INCLUDES_DIR . 'admin/admin-tab.php',
    CBIA_INCLUDES_DIR . 'admin/config.php',
    CBIA_INCLUDES_DIR . 'admin/config-admin.php',
    CBIA_INCLUDES_DIR . 'admin/blog-admin.php',
    CBIA_INCLUDES_DIR . 'admin/usage-admin.php',
    CBIA_INCLUDES_DIR . 'admin/costs-admin.php',
    CBIA_INCLUDES_DIR . 'admin/oldposts-admin.php',
    CBIA_INCLUDES_DIR . 'admin/yoast-admin.php',
    CBIA_INCLUDES_DIR . 'admin/diagnostics-admin.php',
    CBIA_INCLUDES_DIR . 'services/costs-service.php',
    CBIA_INCLUDES_DIR . 'services/blog-service.php',
    CBIA_INCLUDES_DIR . 'services/article-preview-service.php',
    CBIA_INCLUDES_DIR . 'services/oldposts-service.php',
    CBIA_INCLUDES_DIR . 'services/engine-service.php',
    CBIA_INCLUDES_DIR . 'services/yoast-service.php',
    CBIA_INCLUDES_DIR . 'services/settings-service.php',
    CBIA_INCLUDES_DIR . 'services/log-service.php',
    CBIA_INCLUDES_DIR . 'repositories/costs-repo.php',
    CBIA_INCLUDES_DIR . 'repositories/oldposts-repo.php',
    CBIA_INCLUDES_DIR . 'repositories/post-repo.php',
    CBIA_INCLUDES_DIR . 'repositories/settings-repo.php',
    CBIA_INCLUDES_DIR . 'domain/costs.php',
    CBIA_INCLUDES_DIR . 'domain/text.php',
    CBIA_INCLUDES_DIR . 'support/encoding.php',
    CBIA_INCLUDES_DIR . 'support/runtime.php',
    CBIA_INCLUDES_DIR . 'support/logger.php',
    CBIA_INCLUDES_DIR . 'support/sanitize.php',
    CBIA_INCLUDES_DIR . 'support/config-catalog.php',
    CBIA_INCLUDES_DIR . 'engine/engine.php',
    CBIA_INCLUDES_DIR . 'engine/blog.php',
    CBIA_INCLUDES_DIR . 'engine/oldposts.php',
    CBIA_INCLUDES_DIR . 'integrations/yoast.php',
    CBIA_INCLUDES_DIR . 'integrations/yoast-legacy.php',
    CBIA_INCLUDES_DIR . 'integrations/providers.php',
    CBIA_INCLUDES_DIR . 'integrations/openai.php',
    CBIA_INCLUDES_DIR . 'jobs/oldposts-job.php',
    CBIA_INCLUDES_DIR . 'jobs/scheduler.php',
);

foreach ($cbia_new_files as $f) {
    if (file_exists($f)) {
        require_once $f;
    }
}
