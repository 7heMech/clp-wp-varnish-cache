<?php

class ClpVarnishCacheAutoPurge {

    private $purge_entire_cache = false;
    private $purge_post_ids = [];

    public function __construct() {
        add_action('upgrader_process_complete', array($this, 'auto_purge'), 100, 2);
        add_action('deactivated_plugin', array($this, 'auto_purge'), 100);
        add_action('activated_plugin', array($this, 'auto_purge'), 100);
        add_action('switch_theme', array($this, 'auto_purge'), 100);
        add_action('customize_save_after', array($this, 'auto_purge'), 100);
        add_action('wp_update_nav_menu', array($this, 'auto_purge'), 100);
        add_action('wp_update_nav_menu_item', array($this, 'auto_purge'), 100);
        add_action('transition_post_status', array($this, 'post_status_changed'), 100, 3);
        add_action('elementor/document/after_save', array($this, 'elementor_document_saved'), 100);
        add_action('elementor/core/files/clear_cache', array($this, 'auto_purge'), 100);
        add_action('shutdown', array($this, 'purge_queued'));
    }

    public function auto_purge($upgrader_or_plugin = null, $options = null) {
        if (current_action() === 'upgrader_process_complete') {
            if (empty($options['action']) || $options['action'] !== 'update') return;
            if (empty($options['type']) || ! in_array( $options['type'], ['plugin', 'theme', 'core'], true)) return;
        }

        $this->purge_entire_cache = true;
    }

    // Publishing or unpublishing changes listings (home, archives, feeds) that
    // don't carry the post's cache tag yet, so only in-place updates purge by tag.
    public function post_status_changed($new_status, $old_status, $post) {
        if ($new_status !== 'publish' && $old_status !== 'publish') return;
        if (!is_post_type_viewable($post->post_type)) return;

        if ($new_status !== $old_status) {
            $this->purge_entire_cache = true;
        } else {
            $this->purge_post_ids[] = $post->ID;
        }
    }

    public function elementor_document_saved($document) {
        if (method_exists($document, 'is_autosave') && $document->is_autosave()) return;

        $post_id = $document->get_main_id();
        // Templates and the site kit (headers, footers, global styles) render on other pages.
        if (get_post_type($post_id) === 'elementor_library') {
            $this->purge_entire_cache = true;
        } else {
            $this->purge_post_ids[] = $post_id;
        }
    }

    public function purge_queued() {
        if (!$this->purge_entire_cache && empty($this->purge_post_ids)) return;

        if (!class_exists( 'ClpVarnishCacheManager')) return;
        $manager = new ClpVarnishCacheManager();
        $manager->set_exit_on_error(false);

        if (!$manager->is_enabled()) return;

        $prefix = $manager->get_cache_tag_prefix();

        if ($this->purge_entire_cache) {
            $host = wp_parse_url(home_url(), PHP_URL_HOST);
            if (!empty($host)) $manager->purge_host($host);
            if (!empty($prefix)) $manager->purge_tag($prefix);
        } elseif (!empty($prefix)) {
            // The VCL bans X-Cache-Tags by regex: purge one tag per request, and anchor
            // the end so "post-2" doesn't also purge "post-23".
            foreach (array_unique($this->purge_post_ids) as $post_id) {
                $manager->purge_tag(sprintf('%s-post-%s(,|$)', $prefix, $post_id));
            }
        }

        $this->purge_entire_cache = false;
        $this->purge_post_ids = [];
    }
}
