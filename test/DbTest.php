<?php

namespace amund\WP_Assistant\Test;

use amund\WP_Assistant\Db;

class DbTest
{
    public $db;

    function setUp()
    {
        $this->db = new Db(':memory:');
    }

    function tearDown()
    {
        $this->db = null;
    }

    function testConnectionAndDisconnection()
    {
        if ($this->db->connection === null) throw new \Exception('no db connection');

        $this->db->connection->query("CREATE TABLE test (name TEXT)");
        $this->db->connection->query("INSERT INTO test (name) VALUES ('test')");
        $result = $this->db->connection->query("SELECT * FROM test");
        $rows = $result->fetchArray();
        if (count($rows) !== 1) throw new \Exception('rows count is not one');
        if ($rows[0]['name'] !== 'test') throw new \Exception('row name is not test');

        // cleanly disconnect
        $this->db = null;

        $this->db = new Db(':memory:');
        $error = false;
        try {
            $result = $this->db->connection->query("SELECT * FROM test");
        } catch (\Exception $e) {
            $error = true;
        }
        if (!$error) throw new \Exception('db not resetted');
    }

    function testTables()
    {
        $result = $this->db->connection->query('PRAGMA table_info(documents)');
        if (empty($result)) throw new \Exception('missing table documents');
        $result = $this->db->connection->query('PRAGMA table_info(chunks)');
        if (empty($result)) throw new \Exception('missing table chunks');
    }

    function testCachedStatement()
    {
        if (count($this->db->statements) !== 0) throw new \Exception('statements not empty');
        $this->db->stmt('count_documents');
        if (count($this->db->statements) !== 1) throw new \Exception('statements count is not one');
    }

    function testDocuments()
    {
        $nb = $this->db->count_documents();
        if ($nb !== 0) throw new \Exception('documents table is not empty');

        $this->db->insert_document(42, 'fr', 'hash');
        $nb = $this->db->count_documents();
        if ($nb !== 1) throw new \Exception('document 42 is not inserted');

        $doc = $this->db->select_document(42);
        if (!is_array($doc)) throw new \Exception('document 42 is not an array');
        if ($doc['post_id'] !== 42) throw new \Exception('document 42 post_id is not 42');
        if ($doc['lang'] !== 'fr') throw new \Exception('document 42 lang is not fr');
        if ($doc['hash'] !== 'hash') throw new \Exception('document 42 hash is not hash');

        $doc = $this->db->select_document(24);
        if ($doc !== null) throw new \Exception('document 24 is not null');

        $this->db->delete_document(42);
        $nb = $this->db->count_documents();
        if ($nb !== 0) throw new \Exception('document 42 is not deleted');
    }

    public function testChunks()
    {
        $embed = array_fill(0, $this->db->embed_size, (float) 0.0);
        // insert fake documents as chunks has a foreign key constraint
        $this->db->connection->query('INSERT INTO documents (`post_id`, `lang`, `hash`) VALUES (?, ?, ?)', [24, 'fr', 'hash']);
        $this->db->connection->query('INSERT INTO documents (`post_id`, `lang`, `hash`) VALUES (?, ?, ?)', [42, 'fr', 'hash']);

        $nb = $this->db->count_chunks();
        if ($nb !== 0) throw new \Exception('chunks table is not empty');

        $nb = $this->db->count_chunks(42);
        if ($nb !== 0) throw new \Exception('chunks for document 42 is not empty');

        $this->db->insert_chunk(24, $embed);

        $nb = $this->db->count_chunks();
        if ($nb !== 1) throw new \Exception('chunk is not inserted');

        $this->db->insert_chunk(42, $embed);
        $this->db->insert_chunk(42, $embed);

        $nb = $this->db->count_chunks();
        if ($nb !== 3) throw new \Exception('chunks are not inserted');

        $nb = $this->db->count_chunks(42);
        if ($nb !== 2) throw new \Exception('chunks for document 42 are not 2');

        $this->db->connection->query('DELETE FROM `documents` WHERE `post_id` = ?', [42]);
        $nb = $this->db->count_chunks(42);
        if ($nb !== 0) throw new \Exception('chunks for document 42 are not 0');
    }
}
