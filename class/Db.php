<?php

namespace amund\WP_Assistant;

use Libsql\Database;
use Libsql\Statement;

class Db
{
    private $path;
    public $embed_size;
    public ?Database $db;
    public $connection;
    public $statements = [];
    private $sql = [
        'count_documents' => 'SELECT COUNT(*) AS `nb` FROM `documents`',
        'insert_document' => 'INSERT INTO `documents` (`post_id`, `lang`, `hash`) VALUES (?, ?, ?)',
        'select_document' => 'SELECT * FROM `documents` WHERE `post_id` = ?',
        'delete_document' => 'DELETE FROM `documents` WHERE `post_id` = ?',
        'count_chunks' => 'SELECT COUNT(*) AS `nb` FROM `chunks`',
        'count_document_chunks' => 'SELECT COUNT(*) AS `nb` FROM `chunks` WHERE `post_id` = ?',
        'insert_chunk' => 'INSERT INTO `chunks` (`post_id`, `embed`) VALUES (?, ?)',
        'delete_chunks' => 'DELETE FROM `chunks` WHERE `post_id` = ?',
    ];

    public function __construct(string $path = '', int $embed_size = 1024)
    {
        if (empty($path)) {
            $path = WP_CONTENT_DIR . '/wp-assistant.db';
        }

        $this->path = $path;
        $this->embed_size = $embed_size;

        $this->db = new Database($this->path);
        $this->connection = $this->db->connect();
        $this->connection->executeBatch('
            PRAGMA foreign_keys = ON;
            CREATE TABLE IF NOT EXISTS documents (
                `post_id` INTEGER NOT NULL PRIMARY KEY,
                `lang` TEXT NOT NULL,
                `hash` TEXT NOT NULL,
                `modified` DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS chunks (
                `ROWID` INTEGER PRIMARY KEY AUTOINCREMENT,
                `post_id` INTEGER NOT NULL,
                `embed` F32_BLOB(' . $this->embed_size . '),
                FOREIGN KEY (`post_id`) REFERENCES `documents` (`post_id`) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS `chunks_embed_idx` ON chunks (
                libsql_vector_idx(`embed`)
            );
        ');
    }

    public function __destruct()
    {
        $this->connection = null;
        $this->db = null;
    }

    public function stmt(string $name): Statement
    {
        if (!isset($this->sql[$name])) {
            throw new \Exception("Unknown statement $name");
        }
        if (!isset($this->statements[$name])) {
            $this->statements[$name] = $this->connection->prepare($this->sql[$name]);
            if (!$this->statements[$name]) {
                throw new \Exception("Failed to prepare statement $name");
            }
        } else {
            $this->statements[$name]->reset();
        }
        return $this->statements[$name];
    }

    public function count_documents(): int
    {
        $result = $this->stmt('count_documents')->query();
        return (int) $result->fetchArray()[0]['nb'];
    }

    public function insert_document(int $post_id, string $lang, string $hash): void
    {
        $this->stmt('insert_document')->bind([$post_id, $lang, $hash])->query();
    }

    public function select_document(int $post_id): ?array
    {
        $result = $this->stmt('select_document')->bind([$post_id])->query();
        return $result->fetchArray()[0] ?? null;
    }

    public function delete_document(int $post_id): void
    {
        $this->stmt('delete_document')->bind([$post_id])->query();
    }

    public function count_chunks(?int $post_id = null): int
    {
        if ($post_id) {
            $result = $this->stmt('count_document_chunks')->bind([$post_id])->query();
        } else {
            $result = $this->stmt('count_chunks')->query();
        }
        return (int) $result->fetchArray()[0]['nb'];
    }

    public function insert_chunk(int $post_id, array $embed): void
    {
        $this->stmt('insert_chunk')->bind([$post_id, '[' . implode(',', $embed) . ']'])->query();
    }


    // public function set_document(int $post_id, string $lang, string $hash, array $emdeds): void
    // {
    //     $tx = $this->connection->transaction();
    //     $this->stmt('delete_chunks')->execute($post_id);
    //     $this->stmt('delete_document')->execute($post_id);
    //     $this->stmt('insert_document')->execute([$post_id, $lang, $hash]);
    //     foreach ($emdeds as $emded) {
    //         $this->stmt('insert_chunk')->execute([$post_id, $emded]);
    //     }
    //     $tx->commit();
    // }

    // public function unset_document(int $post_id): void
    // {
    //     $tx = $this->connection->transaction();
    //     $this->stmt('delete_chunks')->execute([$post_id]);
    //     $this->stmt('delete_document')->execute([$post_id]);
    //     $tx->commit();
    // }

    // public function remove(int $post_id): void
    // {
    //     $this->connection->query("DELETE FROM chunks WHERE post_id = ?", [$post_id]);
    //     $this->connection->query("DELETE FROM documents WHERE post_id = ?", [$post_id]);
    // }

    // public function get_teaser(int $post_id): string
    // {
    //     $posts = $this->connection->query("SELECT teaser FROM documents WHERE post_id = ?", [$post_id])->fetchArray();
    //     return (string) ($posts[0]['teaser'] ?? '');
    // }

    // public function get_all(): array
    // {
    //     return $this->connection->query("SELECT post_id, teaser FROM documents")->fetchArray();
    // }

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
