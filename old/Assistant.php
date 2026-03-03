<?php

namespace amund\WP_Assistant;

// use League\HTMLToMarkdown\HtmlConverter;
use Html2Text\Html2Text;

class Assistant
{
    private static $db;
    private static $limit = 5;

    public static function text_content(int $post_id): string
    {
        $value = apply_filters('the_content', get_the_content(null, false, $post_id));
        //$value = self::html2md($value);
        $value = new Html2Text($value, ['do_links' => 'inline', 'width' => 80]);
        $value = $value->getText();
        $value = trim($value);
        $value = preg_replace('#\\\\n#', "\n", $value);
        $value = preg_replace('#\n{3,}#', "\n\n", $value);
        // $value = preg_replace('#\s*\\\\n\s*#', "\n", $value);
        return $value;
    }

    // public static function html2md(string $html): string
    // {
    //     $converter = new HtmlConverter(['strip_tags' => true]);
    //     return $converter->convert($html);
    // }

    public static function rag_answer(string $query): string
    {
        // Context
        $documents = WP_Assistant::db_search_documents($query);
        $context = [];
        foreach ($documents as $doc) {
            $context[] = strtr(
                "Post_id: [post_id]\nTitre: [title]\nScore: [score]\nDescription:\n[teaser]",
                [
                    '[post_id]' => $doc['post_id'],
                    '[title]' => $doc['title'],
                    '[score]' => $doc['score'],
                    '[teaser]' => $doc['teaser'],
                ]
            );
        }
        $context = implode("\n\n", $context);

        $query = "$context\nQuestion du visiteur: $query";
        $answer = WP_Assistant_Client::generate_answer($client, [
            '[LANG]' => 'français',
            '[CONTEXT]' => $context,
            '[QUERY]' => $query,
        ]);

        return $answer;
    }

    public static function db_reindex(): void
    {
        $post_types = array_flip(self::get_post_types());
        foreach (self::db_get_all() as $row) {
            $post_id = $row['post_id'];
            $p = get_post($post_id);

            // remove invalid posts in vector db
            if (
                $p === null
                || $p->post_status !== 'publish'
                || $p->post_password !== ''
                || !isset($post_types[$p->post_type])
            ) {
                self::db_remove($post_id);
            }
        }
    }

    public static function get_post_types()
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

    public static function get_posts()
    {
        // load all posts from public post_types    
        $posts = get_posts([
            'post_type' => self::get_post_types(),
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'post_type',
        ]);

        foreach ($posts as $k => $p) {
            $post_type = get_post_type_object(get_post_type($p));
            $posts[$k] = [
                'id' => $p->ID,
                'post_type' => $post_type->labels->singular_name,
                'title' => $p->post_title,
                'url' => get_edit_post_link($p->ID),
                'teaser' => get_post_meta($p->ID, '_wp_assistant_teaser', true),
            ];
        }

        return $posts;
    }

    public static function get_posts_without_teaser()
    {
        $posts = get_posts([
            'post_type' => self::get_post_types(),
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'post_type',
            'meta_query' => [
                [
                    'key' => '_wp_assistant_teaser',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => '_wp_assistant_teaser',
                    'value' => '',
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
        ]);

        return $posts;
    }

    public static function count_posts_without_teaser()
    {
        return count(self::get_posts_without_teaser());
    }
}
