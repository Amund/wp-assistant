<?php

namespace amund\WP_Assistant;

use amund\WP_Assistant\Chunker;
use Html2Text\Html2Text;

class Assistant
{
    private $plugin;

    public $hash = 'md5';
    public $chunk_size = 1024;
    public $chunk_overlap = 0;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;

        // add_action('save_post', [$this, 'save_post'], 9);
        // add_action('delete_post', [$this, 'delete_post'], 9);

    }

    /**
     * Retrieves the text content of a post by stripping HTML.
     *
     * @param int $post_id The ID of the post.
     * @return string The cleaned text content.
     */
    public function text(int $post_id): string
    {
        $value = apply_filters('the_content', get_the_content(null, false, $post_id));
        $value = new Html2Text($value, ['do_links' => 'none', 'width' => 80]);
        $value = $value->getText();
        $value = trim($value);
        $value = preg_replace('#\\\\n#', "\n", $value);
        $value = preg_replace('#\n{3,}#', "\n\n", $value);
        return $value;
    }

    /**
     * Generates a hash for the given text using the configured algorithm.
     *
     * @param string $text The text to hash.
     * @return string The generated hash.
     */
    public function hash($text): string
    {
        return hash($this->hash, $text);
    }

    /**
     * Chunks the provided text into smaller segments based on size and overlap.
     *
     * @param string $text The text to chunk.
     * @return array An array of text chunks.
     */
    public function chunk($text): array
    {
        return Chunker::chunk($text, $this->chunk_size, $this->chunk_overlap);
    }

    /**
     * Retrieves a list of all public post types, excluding attachments.
     *
     * @return array An array of public post type names.
     */
    public function get_post_types()
    {
        // get public post_types
        $post_types = get_post_types([], 'objects');
        $public_post_types = [];
        foreach ($post_types as $post_type) {
            if ($post_type->public) {
                $public_post_types[] = $post_type->name;
            }
        }
        return array_diff($public_post_types, ['attachment']);
    }

    /**
     * Retrieves all published posts from public post types.
     *
     * @return array An array of post data including ID, type, title, URL, and teaser.
     */
    public function get_posts()
    {
        // load all posts from public post_types    
        $posts = get_posts([
            'post_type' => $this->get_post_types(),
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'post_type',
        ]);

        $db = $this->plugin->get('db');
        foreach ($posts as $k => $p) {
            $id = $p->ID;
            $post_type = get_post_type_object(get_post_type($p));
            $post_type = $post_type->labels->singular_name;
            $title = $p->post_title;
            $url = get_edit_post_link($id);
            $lang = $this->get_post_lang($id);

            // load db document
            $hash = $this->hash($this->text($id));
            $doc = $db->select_document($id);
            $outdated = true;
            if ($doc) {
                $outdated = $hash !== $doc['hash'];
            }

            $posts[$k] = [
                'id' => $id,
                'post_type' => $post_type,
                'title' => $title,
                'lang' => $lang,
                'url' => $url,
                'outdated' => $outdated,
            ];
        }

        return $posts;
    }

    public function get_post_lang(int $post_id): string
    {
        if (function_exists('pll_get_post_language')) {
            return pll_get_post_language($post_id);
        }
        return explode('_', get_locale())[0];
    }
}
