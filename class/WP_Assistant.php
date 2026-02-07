<?php

use Libsql\Database;
use League\HTMLToMarkdown\HtmlConverter;

class WP_Assistant
{
    private static $db;
    private static $limit = 5;

    private static function db()
    {
        if (self::$db) return self::$db;

        if (!defined('WP_ASSISTANT_DB_PATH')) throw new Exception('WP_ASSISTANT_DB_PATH is not defined');

        $db = new Database(WP_ASSISTANT_DB_PATH);
        try {
            self::$db = $db->connect();
            self::$db->executeBatch("
                CREATE TABLE IF NOT EXISTS documents (
                    post_id INTEGER NOT NULL PRIMARY KEY,
                    teaser TEXT NOT NULL,
                    embedding vector(1024),
                    modified DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                -- CREATE INDEX IF NOT EXISTS documents_idx ON documents (libsql_vector_idx(embedding));
            ");
            return self::$db;
        } catch (Exception $e) {
            error_log('Failed to connect to database: ' . $e->getMessage());
        }
    }

    public static function text_content(int $post_id): string
    {
        $value = apply_filters('the_content', get_the_content(null, false, $post_id));
        $value = self::html2md($value);
        $value = trim($value);
        return $value;
    }

    public static function html2md(string $html): string
    {
        $converter = new HtmlConverter(['strip_tags' => true]);
        return $converter->convert($html);
    }

    public static function db_remove(int $post_id): void
    {
        self::db()->query("DELETE FROM documents WHERE post_id = ?", [$post_id]);
    }

    public static function db_get_teaser(int $post_id): string
    {
        $posts = self::db()->query("SELECT teaser FROM documents WHERE post_id = ?", [$post_id])->fetchArray();
        return (string) ($posts[0]['teaser'] ?? '');
    }

    public static function db_get_all(): array
    {
        return self::db()->query("SELECT post_id, teaser FROM documents")->fetchArray();
    }

    public static function db_update_post(int $post_id, string $teaser): void
    {
        if (empty($teaser)) {
            delete_post_meta($post_id, '_wp_assistant_teaser');
            try {
                self::db()->query(
                    "DELETE FROM documents WHERE post_id = ?",
                    [$post_id],
                );
            } catch (Exception $e) {
                error_log('Failed to delete document: ' . $e->getMessage());
            }
        } else {
            update_post_meta($post_id, '_wp_assistant_teaser', $teaser);
            $embedding = WP_Assistant_Client::embeddings($teaser);
            $vector = '[' . implode(',', $embedding) . ']';
            try {
                self::db()->query(
                    "REPLACE INTO documents (post_id, teaser, embedding) VALUES (?, ?, vector(?))",
                    [$post_id, $teaser, $vector],
                );
            } catch (Exception $e) {
                error_log('Failed to update document: ' . $e->getMessage());
            }
        }
    }

    public static function db_search_documents(string $query): array
    {
        $embeddings = WP_Assistant_Client::embeddings($query);
        $vector = '[' . implode(',', $embeddings) . ']';
        $results = [];
        try {
            $results = self::db()->query("
                SELECT 
                    post_id,
                    vector_distance_cos(embedding, vector(?)) as score
                FROM documents
                WHERE embedding IS NOT NULL
                ORDER BY score DESC
                LIMIT ?
            ", [$vector, self::$limit])->fetchArray();

            foreach ($results as $i => $row) {
                $results[$i]['score'] = round($row['score'], 3);
                $results[$i]['title'] = get_the_title($row['post_id']);
                $results[$i]['url'] = get_permalink($row['post_id']);
                $results[$i]['teaser'] = get_post_meta($row['post_id'], '_wp_assistant_teaser', true);
            }
        } catch (Exception $e) {
            error_log('Failed to search documents: ' . $e->getMessage());
        }

        return $results;
    }

    public static function rag_answer(string $query): string
    {
        $documents = WP_Assistant::db_search_documents($query);
        $context = "Pages de contenus, classées par score de pertinence descendant:\n\n";
        foreach ($documents as $i => $doc) {
            $context .= strtr("Post_id: [post_id]\nTitre: [title]\nScore: [score]\nUrl: [url]\nDescription:\n[teaser]\n\n", [
                '[post_id]' => $doc['post_id'],
                '[title]' => $doc['title'],
                '[score]' => $doc['score'],
                '[teaser]' => $doc['teaser'],
            ]);
        }

        $query = "$context\nQuestion du visiteur: $query";
        $answer = WP_Assistant_Client::answer($query);

        return $answer;
    }

    public static function get_teaser_prompt(): string
    {
        $value = get_option(
            'wp_assistant_teaser_prompt',
            WP_Assistant_Client::$default_teaser_prompt
        );

        if (empty($value)) {
            $value = WP_Assistant_Client::$default_teaser_prompt;
            update_option('wp_assistant_teaser_prompt', $value);
        }

        return $value;
    }

    public static function get_answer_prompt(): string
    {
        $value = get_option(
            'wp_assistant_answer_prompt',
            WP_Assistant_Client::$default_answer_prompt
        );

        if (empty($value)) {
            $value = WP_Assistant_Client::$default_answer_prompt;
            update_option('wp_assistant_answer_prompt', $value);
        }

        return $value;
    }

    public static function db_remove_old_posts(): void {}

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

    static function get_post_types()
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

    static function get_posts()
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

    static function get_posts_without_teaser()
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

    static function count_posts_without_teaser()
    {
        return count(self::get_posts_without_teaser());
    }
}
