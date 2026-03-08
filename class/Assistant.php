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

        add_action('save_post', [$this, 'save_post'], 9, 3);
        add_action('delete_post', [$this, 'delete_post'], 9);
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
     * Checks if a post has any text content.
     *
     * @param int $post_id The ID of the post.
     * @return bool True if the post has non-empty content.
     */
    public function has_content(int $post_id): bool
    {
        return !empty($this->text($post_id));
    }

    /**
     * Determines if a post is outdated (needs reindexing).
     * A post without content is never considered outdated.
     *
     * @param int $post_id The ID of the post.
     * @return bool True if the post is outdated and has content.
     */
    public function is_outdated(int $post_id): bool
    {
        if (!$this->has_content($post_id)) {
            return false;
        }
        $hash = $this->hash($this->text($post_id));
        $db = $this->plugin->get('db');
        $doc = $db->select_document($post_id);
        if ($doc) {
            return $hash !== $doc['hash'];
        }
        return true;
    }

    /**
     * Counts the number of outdated posts (posts with content that need reindexing).
     *
     * @return int The count of outdated posts.
     */
    public function count_outdated_posts(): int
    {
        $result = $this->get_filtered_posts([
            'outdated' => true,
            'per_page' => 1,
        ]);
        return $result['total_posts'];
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
        $result = $this->get_filtered_posts(['per_page' => -1]);
        return $result['posts'];
    }

    /**
     * Retrieves filtered and paginated posts from public post types.
     *
     * @param array $args {
     *     Optional. Array of query parameters.
     *
     *     @type string|array $post_type  Post type(s) to filter. Default all public post types.
     *     @type string       $lang       Language code to filter. Default all languages.
     *     @type bool|null    $outdated   Filter by outdated status (true = outdated, false = up-to-date, null = all). Default null.
     *     @type int          $page       Page number for pagination (starts at 1). Default 1.
     *     @type int          $per_page   Number of posts per page. Default 50.
     * }
     * @return array {
     *     @type array  $posts       Array of post data.
     *     @type int    $total_posts Total number of posts matching filters.
     *     @type int    $total_pages Total number of pages.
     *     @type int    $current_page Current page number.
     * }
     */
    public function get_filtered_posts($args = [])
    {
        $defaults = [
            'post_type' => $this->get_post_types(),
            'lang' => '',
            'outdated' => null,
            'page' => 1,
            'per_page' => 50,
        ];
        $args = wp_parse_args($args, $defaults);
        $page = max(1, (int) $args['page']);
        $per_page = (int) $args['per_page'];
        if ($per_page == -1) {
            $page = 1;
        } elseif ($per_page < 1) {
            $per_page = 1;
        }

        // Determine post types
        $post_types = $args['post_type'];
        if (empty($post_types)) {
            $post_types = $this->get_post_types();
        }
        if (is_string($post_types)) {
            $post_types = [$post_types];
        }

        // Step 1: Get post IDs filtered by post_type and language (if possible)
        $base_query_args = [
            'post_type' => $post_types,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'orderby' => 'post_type title',
            'order' => 'ASC',
        ];

        // If language filter and Polylang available, use tax_query
        if (!empty($args['lang']) && function_exists('pll_get_post_language') && taxonomy_exists('language')) {
            // Polylang stores language as taxonomy 'language'
            $base_query_args['tax_query'] = [
                [
                    'taxonomy' => 'language',
                    'field' => 'slug',
                    'terms' => $args['lang'],
                ],
            ];
        }

        $base_query = new \WP_Query($base_query_args);
        $post_ids = $base_query->posts; // array of IDs

        // Step 2: Filter by outdated if needed
        if ($args['outdated'] !== null) {
            $filtered_ids = [];
            foreach ($post_ids as $post_id) {
                $outdated = $this->is_outdated($post_id);
                if ($outdated === $args['outdated']) {
                    $filtered_ids[] = $post_id;
                }
            }
            $post_ids = $filtered_ids;
        }

        $total_posts = count($post_ids);
        if ($per_page == -1) {
            $total_pages = 1;
        } else {
            $total_pages = ceil($total_posts / $per_page);
        }

        // Step 3: Paginate IDs
        $offset = ($page - 1) * $per_page;
        $paginated_ids = array_slice($post_ids, $offset, $per_page);

        // Step 4: Fetch post details for paginated IDs
        $posts = [];
        if (!empty($paginated_ids)) {
            $post_objects = get_posts([
                'post__in' => $paginated_ids,
                'post_type' => $post_types,
                'posts_per_page' => $per_page,
                'post_status' => 'publish',
                'orderby' => 'post__in', // preserve order
            ]);
            $db = $this->plugin->get('db');
            foreach ($post_objects as $p) {
                $id = $p->ID;
                $post_type_obj = get_post_type_object(get_post_type($p));
                $post_type = $post_type_obj ? $post_type_obj->labels->singular_name : get_post_type($p);
                $title = $p->post_title;
                $url = get_edit_post_link($id);
                $lang = $this->get_post_lang($id);

                // Load outdated status (already computed if outdated filter used, but recompute for consistency)
                $outdated = $this->is_outdated($id);

                $posts[] = [
                    'id' => $id,
                    'post_type' => $post_type,
                    'title' => $title,
                    'lang' => $lang,
                    'url' => $url,
                    'outdated' => $outdated,
                ];
            }
        }

        return [
            'posts' => $posts,
            'total_posts' => $total_posts,
            'total_pages' => $total_pages,
            'current_page' => $page,
        ];
    }

    /**
     * Get available languages for filtering.
     *
     * @return array Associative array of language code => language name.
     */
    public function get_available_languages()
    {
        $languages = [];
        if (function_exists('pll_languages_list')) {
            $codes = pll_languages_list(['fields' => 'slug']);
            $names = pll_languages_list(['fields' => 'name']);
            foreach ($codes as $index => $code) {
                $languages[$code] = $names[$index];
            }
        } else {
            // Default language
            $locale = get_locale();
            $code = explode('_', $locale)[0];
            $languages[$code] = $code;
        }
        return $languages;
    }

    /**
     * Get available post types for filtering with labels.
     *
     * @return array Associative array of post type slug => label (singular name).
     */
    public function get_available_post_types()
    {
        $post_types = get_post_types([], 'objects');
        $available = [];
        foreach ($post_types as $post_type) {
            if ($post_type->public && $post_type->name !== 'attachment') {
                $available[$post_type->name] = $post_type->labels->singular_name;
            }
        }
        return $available;
    }

    /**
     * Retrieves the language of a post.
     *
     * If Polylang is active, it uses pll_get_post_language to get the post language.
     * Otherwise, it falls back to the site's locale.
     *
     * @param int $post_id The ID of the post.
     * @return string The language code of the post.
     */
    public function get_post_lang(int $post_id): string
    {
        if (function_exists('pll_get_post_language')) {
            return pll_get_post_language($post_id);
        }
        return explode('_', get_locale())[0];
    }

    /**
     * Get the current language of the site.
     *
     * @return string The language code (e.g., 'fr', 'en').
     */
    public function get_current_lang(): string
    {
        if (function_exists('pll_current_language')) {
            $lang = pll_current_language();
            if ($lang) {
                return $lang;
            }
        }
        return explode('_', get_locale())[0];
    }

    /**
     * Index a post by generating its embeddings and storing them in the database.
     *
     * @param int $post_id The ID of the post to index.
     * @return bool True on success, false on failure.
     */
    public function index_post(int $post_id): bool
    {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $text = $this->text($post_id);
        if (empty($text)) {
            return false;
        }

        $hash = $this->hash($text);
        $lang = $this->get_post_lang($post_id);

        // Generate chunks
        $chunks = $this->chunk($text);
        if (empty($chunks)) {
            return false;
        }

        // Generate embeddings for each chunk
        $client = $this->plugin->get('client');
        $embeddings = [];
        foreach ($chunks as $chunk) {
            try {
                $embedding = $client::embeddings($chunk);
                $embeddings[] = $embedding;
            } catch (\Exception $e) {
                error_log('WP Assistant: Failed to generate embedding for post ' . $post_id . ': ' . $e->getMessage());
                return false;
            }
        }

        // Store in database
        try {
            $db = $this->plugin->get('db');
            $db->set_document($post_id, $lang, $hash, $embeddings);
        } catch (\Exception $e) {
            error_log('WP Assistant: Failed to store embeddings for post ' . $post_id . ': ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Remove a post from the index.
     *
     * @param int $post_id The ID of the post to remove.
     */
    public function delete_post(int $post_id): void
    {
        try {
            $db = $this->plugin->get('db');
            $db->unset_document($post_id);
        } catch (\Exception $e) {
            error_log('WP Assistant: Failed to delete post ' . $post_id . ' from index: ' . $e->getMessage());
        }
    }

    /**
     * Handle save_post hook.
     *
     * @param int $post_id Post ID.
     * @param WP_Post $post Post object.
     * @param bool $update Whether this is an existing post being updated.
     */
    public function save_post(int $post_id, \WP_Post $post, bool $update): void
    {
        // Skip autosaves, revisions, and non-published posts
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if ($post->post_status === 'publish') {
            $this->index_post($post_id);
        } else {
            $this->delete_post($post_id);
        }
    }

    /**
     * Generate an answer to a question using RAG.
     *
     * @param string $question The user's question.
     * @return string JSON response from the AI.
     */
    public function rag_answer(string $question): string
    {
        $client = $this->plugin->get('client');
        $current_lang = $this->get_current_lang();
        try {
            // Generate embedding for the question
            $embedding = $client::embeddings($question);

            // Search for similar chunks in current language
            $db = $this->plugin->get('db');
            $results = $db->search_chunks($embedding, 5, $current_lang);

            if (empty($results)) {
                // No results found, return a default response
                return json_encode([
                    'message' => 'Je n\'ai pas trouvé d\'informations pertinentes pour répondre à votre question. Vous pouvez essayer de reformuler votre demande ou parcourir le site via les menus de navigation.',
                    'post_ids' => [],
                ]);
            }

            // Get unique post IDs from results
            $post_ids = array_unique(array_column($results, 'post_id'));

            if (empty($post_ids)) {
                return json_encode([
                    'message' => 'Je n\'ai pas trouvé d\'informations pertinentes pour répondre à votre question.',
                    'post_ids' => [],
                ]);
            }

            // Build context from the posts
            $context_parts = [];
            foreach ($post_ids as $post_id) {
                $title = get_the_title($post_id);
                $content = $this->text($post_id);
                $context_parts[] = "Post_ID: " . $post_id . "\nTitre: " . $title . "\n" . $content;
            }
            $context = implode("\n\n", $context_parts);

            // Determine language label for the prompt
            $lang = $current_lang === 'fr' ? 'français' : ($current_lang === 'en' ? 'anglais' : $current_lang);

            // Generate answer using AI
            $response = $client::generate_answer([
                '[LANG]' => $lang,
                '[CONTENT]' => $context,
                '[QUERY]' => $question,
            ]);

            return $response;
        } catch (\Exception $e) {
            error_log('WP Assistant RAG error: ' . $e->getMessage());
            // Return a safe error response
            return json_encode([
                'message' => 'Désolé, une erreur est survenue lors du traitement de votre demande. Veuillez réessayer plus tard.',
                'post_ids' => [],
            ]);
        }
    }
}
