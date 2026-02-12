<?php
/**
 * Admin router: register tabs and menus.
 */

if (!defined('ABSPATH'))
    exit;

if (!class_exists('CBIA_Admin_Router')) {
    class CBIA_Admin_Router
    {
        private $tabs = array();

        public function register_tab($key, $label, $callback, $priority = 10)
        {
            $this->tabs[$key] = array(
                'label' => $label,
                'callback' => $callback,
                'priority' => (int) $priority,
            );
        }

        public function register_tab_object($tab_object)
        {
            if (!is_object($tab_object))
                return;
            if (!method_exists($tab_object, 'get_key'))
                return;
            if (!method_exists($tab_object, 'get_label'))
                return;
            if (!method_exists($tab_object, 'render'))
                return;

            $key = (string) $tab_object->get_key();
            $label = (string) $tab_object->get_label();
            $callback = array($tab_object, 'render');
            $priority = method_exists($tab_object, 'get_priority') ? (int) $tab_object->get_priority() : 10;

            if ($key === '' || $label === '')
                return;
            $this->register_tab($key, $label, $callback, $priority);
        }

        public function register()
        {
            add_action('admin_menu', array($this, 'on_admin_menu'));
        }

        public function on_admin_menu()
        {
            add_menu_page(
                'AI Blog Builder',
                'AI Blog Builder',
                'manage_options',
                'cbia',
                array($this, 'render_page'),
                'dashicons-edit-page',
                56
            );
        }

        public function get_tabs()
        {
            if (!empty($this->tabs)) {
                $tabs = $this->tabs;
                $tabs = apply_filters('cbia_admin_tabs', $tabs);
                uasort($tabs, function ($a, $b) {
                    return (int) ($a['priority'] ?? 10) <=> (int) ($b['priority'] ?? 10);
                });
                return $tabs;
            }

            if (function_exists('cbia_get_admin_tabs')) {
                $legacy = cbia_get_admin_tabs();
                if (is_array($legacy)) {
                    $tabs = array();
                    foreach ($legacy as $key => $tab) {
                        $tabs[$key] = array(
                            'label' => (string) ($tab['label'] ?? $key),
                            'callback' => $tab['render'] ?? null,
                        );
                    }
                    $tabs = apply_filters('cbia_admin_tabs', $tabs);
                    uasort($tabs, function ($a, $b) {
                        return (int) ($a['priority'] ?? 10) <=> (int) ($b['priority'] ?? 10);
                    });
                    return $tabs;
                }
            }

            $tabs = apply_filters('cbia_admin_tabs', array());
            uasort($tabs, function ($a, $b) {
                return (int) ($a['priority'] ?? 10) <=> (int) ($b['priority'] ?? 10);
            });
            return $tabs;
        }

        public function get_current_tab($tabs)
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation
            $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'config';
            if (isset($tabs[$tab]))
                return $tab;
            return array_key_first($tabs) ?: 'config';
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('No tienes permisos para ver esta pagina.');
            }

            $tabs = $this->get_tabs();
            if (empty($tabs)) {
                echo '<div class="wrap"><p>No hay pestanas registradas.</p></div>';
                return;
            }

            $current = $this->get_current_tab($tabs);


            $logo_header = plugins_url('assets/images/ai-blog-builder-ico.svg', CBIA_PLUGIN_FILE);
            $logo_sidebar = plugins_url('assets/images/AI-Blog-Builder.svg', CBIA_PLUGIN_FILE);
            echo '<div class="wrap cbia-shell">';
            echo '<div class="cbia-header">';
            echo '<div class="cbia-brand">';
            echo '<img class="cbia-logo" src="' . esc_url($logo_header) . '" alt="AI Blog Builder" />';
            echo '<div class="cbia-title"><span class="ai-part">AI</span> <span class="brand-part">BLOG BUILDER</span> <span class="cbia-badge-pro">PRO</span> <span class="cbia-badge-pro">MULTI-PROVIDER COMING SOON</span></div>';
            echo '</div>';
            echo '<div class="cbia-version">v' . esc_html(defined('CBIA_VERSION') ? CBIA_VERSION : '3.0.2') . '</div>';
            echo '</div>';

            echo '<div class="cbia-layout">';
            echo '<aside class="cbia-sidebar">';
            echo '<div class="cbia-sidebar-logo"><img src="' . esc_url($logo_sidebar) . '" alt="AI Blog Builder" /></div>';
            echo '<ul class="cbia-sidebar-nav">';


            foreach ($tabs as $key => $t) {
                $url = admin_url('admin.php?page=cbia&tab=' . $key);
                $cls = 'cbia-nav-link' . ($key === $current ? ' is-active' : '');
                $icon_map = array(
                    'config' => 'dashicons-admin-generic',
                    'blog' => 'dashicons-edit-page',
                    'oldposts' => 'dashicons-update',
                    'costs' => 'dashicons-money-alt',
                    'yoast' => 'dashicons-chart-bar',
                    'usage' => 'dashicons-chart-area',
                );
                $icon = $icon_map[$key] ?? 'dashicons-admin-generic';
                echo '<li><a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '"><span class="dashicons ' . esc_attr($icon) . '"></span><span class="cbia-nav-text">' . esc_html($t['label']) . '</span></a></li>';
            }
            echo '</ul>';
            echo '</aside>';
            echo '<main class="cbia-main">';
            echo '<div class="cbia-content-card">';

            do_action('cbia_admin_before_render', $current, $tabs);

            $render = $tabs[$current]['callback'] ?? null;
            if (is_callable($render)) {
                call_user_func($render);
            } else {
                echo '<p>No se pudo cargar esta pestana.</p>';
                if (function_exists('cbia_log')) {
                    cbia_log(sprintf('No se pudo cargar la pestaña: %s', (string) $current), 'ERROR');
                }
            }

            do_action('cbia_admin_after_render', $current, $tabs);
            echo '</div>'; // content card
            echo '</main>';
            echo '</div>'; // layout
            echo '</div>'; // wrap
        }
    }
}



