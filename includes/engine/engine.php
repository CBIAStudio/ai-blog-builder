<?php
/**
 * Engine module loader.
 *
 * Order matters: low-level helpers first, higher-level workflows last.
 */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/markers.php';
require_once __DIR__ . '/author.php';
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/models.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/usage.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/taxonomy.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/pending.php';

