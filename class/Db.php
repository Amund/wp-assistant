<?php

namespace amund\WP_Assistant;

use Libsql\Database;
use Libsql\Statement;

class Db
{
    private $path;
    private $db;
    private $statements = [];
    private $sql = [
        'insert_document' => 'INSERT INTO `documents` (`post_id`, `lang`, `hash`) VALUES (?, ?, ?)',
        'insert_chunk' => 'INSERT INTO `chunks` (`post_id`, `embed`) VALUES (?, ?)',
        'delete_chunks' => 'DELETE FROM `chunks` WHERE `post_id` = ?',
        'delete_document' => 'DELETE FROM `documents` WHERE `post_id` = ?',
    ];

    public function __construct(string $path)
    {
        if (empty($path)) {
            $path = WP_CONTENT_DIR . '/wp-assistant.db';
        }

        $db = new Database($path);
        $db = $db->connect();
        $db->executeBatch('
            CREATE TABLE IF NOT EXISTS documents (
                `post_id` INTEGER NOT NULL PRIMARY KEY,
                `lang` TEXT NOT NULL,
                `hash` TEXT NOT NULL,
                `modified` DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS chunks (
                `ROWID` INTEGER PRIMARY KEY AUTOINCREMENT,
                `post_id` INTEGER NOT NULL,
                `embed` vector32(1024),
                FOREIGN KEY (`post_id`) REFERENCES `documents` (`post_id`)
            );
            CREATE INDEX IF NOT EXISTS chunks_idx ON documents (
                libsql_vector_idx(embed)
            );
        ');
    }

    public function stmt(string $name): Statement
    {
        if (!isset($this->sql[$name])) {
            throw new \Exception("Unknown statement $name");
        }
        if (!isset($this->statements[$name])) {
            $this->statements[$name] = $this->db->prepare($this->statements[$name]);
        }
        return $this->statements[$name];
    }

    public function set_document(int $post_id, string $lang, string $hash, array $emdeds): void
    {
        $tx = $this->db->transaction();
        $this->stmt('delete_chunks')->execute($post_id);
        $this->stmt('delete_document')->execute($post_id);
        $this->stmt('insert_document')->execute([$post_id, $lang, $hash]);
        foreach ($emdeds as $emded) {
            $this->stmt('insert_chunk')->execute([$post_id, $emded]);
        }
        $tx->commit();
    }

    public function unset_document(int $post_id): void
    {
        $tx = $this->db->transaction();
        $this->stmt('delete_chunks')->execute([$post_id]);
        $this->stmt('delete_document')->execute([$post_id]);
        $tx->commit();
    }

    public function remove(int $post_id): void
    {
        $this->db->query("DELETE FROM chunks WHERE post_id = ?", [$post_id]);
        $this->db->query("DELETE FROM documents WHERE post_id = ?", [$post_id]);
    }

    public function get_teaser(int $post_id): string
    {
        $posts = $this->db->query("SELECT teaser FROM documents WHERE post_id = ?", [$post_id])->fetchArray();
        return (string) ($posts[0]['teaser'] ?? '');
    }

    public function get_all(): array
    {
        return $this->db->query("SELECT post_id, teaser FROM documents")->fetchArray();
    }

    // public function db_update_post(int $post_id, string $teaser): void
    // {
    //     if (empty($teaser)) {
    //         delete_post_meta($post_id, '_wp_assistant_teaser');
    //         self::db_remove($post_id);
    //     } else {
    //         update_post_meta($post_id, '_wp_assistant_teaser', $teaser);
    //         $embedding = WP_Assistant_Client::embeddings($teaser);
    //         $vector = '[' . implode(',', $embedding) . ']';
    //         try {
    //             $this->db->query(
    //                 "REPLACE INTO documents (post_id, teaser, embedding) VALUES (?, ?, vector(?))",
    //                 [$post_id, $teaser, $vector],
    //             );
    //         } catch (Exception $e) {
    //             error_log('Failed to update document: ' . $e->getMessage());
    //         }
    //     }
    // }

    // public function db_search_documents(string $query): array
    // {
    //     $embeddings = WP_Assistant_Client::embeddings($query);
    //     $vector = '[' . implode(',', $embeddings) . ']';
    //     $results = [];
    //     try {
    //         $results = $this->db->query("
    //             SELECT
    //                 d.post_id,
    //                 vector_distance_cos(c.embedding, vector(?)) as score
    //             FROM documents d
    //             JOIN chunks c ON d.post_id = c.post_id
    //             WHERE c.embedding IS NOT NULL
    //             ORDER BY score DESC
    //             LIMIT ?
    //         ", [$vector, self::$limit])->fetchArray();

    //         foreach ($results as $i => $row) {
    //             $results[$i]['score'] = round($row['score'], 3);
    //             $results[$i]['title'] = get_the_title($row['post_id']);
    //             $results[$i]['url'] = get_permalink($row['post_id']);
    //             $results[$i]['teaser'] = get_post_meta($row['post_id'], '_wp_assistant_teaser', true);
    //         }
    //     } catch (Exception $e) {
    //         error_log('Failed to search documents: ' . $e->getMessage());
    //     }

    //     return $results;
    // }
}
