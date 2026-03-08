<?php

namespace amund\WP_Assistant\Test;

use amund\WP_Assistant\Chunker;

class ChunkerTest
{
    public function testChunkSizeMustBePositive()
    {
        $error = false;
        try {
            Chunker::chunk('test', 0);
        } catch (\InvalidArgumentException $e) {
            $error = true;
        }
        if (!$error) throw new \Exception('chunk size must be positive');

        $error = false;
        try {
            Chunker::chunk('test', -10);
        } catch (\InvalidArgumentException $e) {
            $error = true;
        }
        if (!$error) throw new \Exception('chunk size must be positive');
    }

    public function testChunkOverlapParamMustBeLessThanChunkSize()
    {
        $error = false;
        try {
            Chunker::chunk('test', 10, 11);
        } catch (\InvalidArgumentException $e) {
            $error = true;
        }
        if (!$error) throw new \Exception('overlap must be less than chunk size');
    }

    public function testChunkText()
    {
        // Test de base : vérifier que le texte est divisé en morceaux de la bonne taille
        $text = "Ceci est un texte de test pour vérifier le chunker.";
        $chunks = Chunker::chunk($text, 10);

        if (count($chunks) !== 6) throw new \Exception('count must be 6');
        if ($chunks[0] !== 'Ceci est u') throw new \Exception('first chunk must be "Ceci est u"');
        if ($chunks[1] !== 'n texte de') throw new \Exception('second chunk must be "n texte de"');
    }

    public function testChunkTextWithExactDivision()
    {
        // Test lorsque le texte est exactement divisible par la taille du chunk
        $text = "1234567890";
        $chunks = Chunker::chunk($text, 5);

        if (count($chunks) !== 2) throw new \Exception('count must be 2');
        if ($chunks[0] !== '12345') throw new \Exception('first chunk must be "12345"');
        if ($chunks[1] !== '67890') throw new \Exception('first chunk must be "67890"');
    }

    public function testChunkTextWithEmptyString()
    {
        // Test avec une chaîne vide
        $text = "";
        $chunks = Chunker::chunk($text, 10);

        if (count($chunks) !== 1) throw new \Exception('count must be 1');
        if ($chunks[0] !== '') throw new \Exception('chunk must be ""');
    }

    public function testChunkTextWithChunkSizeLargerThanText()
    {
        // Test lorsque la taille du chunk est plus grande que le texte
        $text = "small";
        $chunks = Chunker::chunk($text, 10);

        if (count($chunks) !== 1) throw new \Exception('count must be 1');
        if ($chunks[0] !== 'small') throw new \Exception('chunk must be "small"');
    }

    public function testChunkTextWithSpecialCharacters()
    {
        // Test avec des caractères spéciaux
        $text = "éèàçœŒ";
        $chunks = Chunker::chunk($text, 2);

        if (count($chunks) !== 3) throw new \Exception('count must be 3');
        if ($chunks[0] !== 'éè') throw new \Exception('chunk must be "éè"');
        if ($chunks[1] !== 'àç') throw new \Exception('chunk must be "àç"');
        if ($chunks[2] !== 'œŒ') throw new \Exception('chunk must be "œŒ"');
    }

    public function testChunkTextWithOverlap()
    {
        // Test de base : vérifier que le texte est divisé en morceaux de la bonne taille
        $text = "Ceci est un texte de test pour vérifier le chunker.";
        $chunks = Chunker::chunk($text, 10, 2);

        if (count($chunks) !== 7) throw new \Exception('count must be 7');
        if ($chunks[0] !== 'Ceci est u') throw new \Exception('first chunk must be "Ceci est u"');
        if ($chunks[1] !== 'un texte') throw new \Exception('second chunk must be "un texte"');
    }
}
