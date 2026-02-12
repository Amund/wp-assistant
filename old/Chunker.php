<?php

namespace amund\WP_Assistant;

class Chunker
{
    public static function chunk(string $text, int $maxChunkSize, int $overlapSize = 0): array
    {
        // Validation des paramètres
        if ($maxChunkSize <= 0) {
            throw new InvalidArgumentException("La taille maximum doit être supérieure à 0");
        }

        if ($overlapSize >= $maxChunkSize) {
            throw new InvalidArgumentException("L'overlap doit être inférieur à la taille maximum");
        }

        if (mb_strlen($text) <= $maxChunkSize) {
            return [$text];
        }

        $chunks = [];
        $start = 0;
        $textLength = mb_strlen($text);

        while ($start < $textLength) {
            // Déterminer la fin maximale du chunk (hard cap)
            $maxEnd = min($start + $maxChunkSize, $textLength);

            // Si on est à la fin du texte
            if ($maxEnd === $textLength) {
                $chunks[] = mb_substr($text, $start);
                break;
            }

            // Récupérer le segment actuel avec un buffer supplémentaire pour la recherche
            $searchBuffer = min(100, $maxChunkSize / 2); // Buffer de recherche intelligent
            $searchEnd = min($maxEnd + $searchBuffer, $textLength);
            $searchSegment = mb_substr($text, $start, $maxChunkSize + $searchBuffer);

            // 1. Essayer de couper à la fin d'un paragraphe
            $paragraphBreak = self::findBestParagraphBreak($searchSegment, $maxChunkSize);
            if ($paragraphBreak !== null) {
                $chunk = mb_substr($searchSegment, 0, $paragraphBreak);
                $chunks[] = $chunk;
                $start += mb_strlen($chunk) - $overlapSize;
                continue;
            }

            // 2. Essayer de couper à la fin d'une phrase
            $sentenceBreak = self::findBestSentenceBreak($searchSegment, $maxChunkSize);
            if ($sentenceBreak !== null) {
                $chunk = mb_substr($searchSegment, 0, $sentenceBreak);
                $chunks[] = $chunk;
                $start += mb_strlen($chunk) - $overlapSize;
                continue;
            }

            // 3. Essayer de couper à la fin d'un mot (plus agressif)
            $wordBreak = self::findBestWordBreak($searchSegment, $maxChunkSize);
            if ($wordBreak !== null) {
                $chunk = mb_substr($searchSegment, 0, $wordBreak);
                $chunks[] = $chunk;
                $start += mb_strlen($chunk) - $overlapSize;
                continue;
            }

            // 4. Dernier recours : chercher la dernière transition non-alphanumérique
            $lastNonAlpha = self::findLastNonAlphaTransition($searchSegment, $maxChunkSize);
            if ($lastNonAlpha !== null && $lastNonAlpha > $maxChunkSize * 0.9) {
                $chunk = mb_substr($searchSegment, 0, $lastNonAlpha);
                $chunks[] = $chunk;
                $start += mb_strlen($chunk) - $overlapSize;
                continue;
            }

            // 5. Fallback final : couper à la limite exacte
            $chunk = mb_substr($searchSegment, 0, $maxChunkSize);
            $chunks[] = $chunk;
            $start += $maxChunkSize - $overlapSize;
        }

        // Nettoyer les chunks
        return array_values(array_filter($chunks, function ($chunk) {
            return trim($chunk) !== '';
        }));
    }

    public static function findBestParagraphBreak(string $segment, int $maxSize): ?int
    {
        $bestBreak = null;

        // Chercher les sauts de paragraphe
        $patterns = [
            '/\n{2,}/u', // Double newline
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $segment, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    // Convertir l'offset en octets en offset en caractères
                    $byteOffset = $match[1];
                    $charOffset = self::byteOffsetToCharOffset($segment, $byteOffset);

                    // La position de coupure est après le match
                    $breakPos = $charOffset + mb_strlen($match[0]);

                    // Préférer les coupures proches de la limite mais pas trop petites
                    if ($breakPos <= $maxSize && $breakPos > $maxSize * 0.8) {
                        if ($bestBreak === null || abs($breakPos - $maxSize) < abs($bestBreak - $maxSize)) {
                            $bestBreak = $breakPos;
                        }
                    }
                }
            }
        }

        return $bestBreak;
    }

    public static function findBestSentenceBreak(string $segment, int $maxSize): ?int
    {
        $bestBreak = null;

        // Chercher les fins de phrase avec contexte
        $sentencePatterns = [
            '/(?<=[.!?])\s+(?=[A-ZÀ-ÖØ-öø-ÿ])/u',  // Fin de phrase suivie d'une majuscule
            '/(?<=[.!?]["\'])\s+(?=[A-ZÀ-ÖØ-öø-ÿ])/u',  // Fin de phrase avec guillemets
            '/(?<=[.!?])\s*$/u',                  // Fin de phrase en fin de segment
        ];

        foreach ($sentencePatterns as $pattern) {
            if (preg_match_all($pattern, $segment, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    // Convertir l'offset en octets en offset en caractères
                    $byteOffset = $match[1];
                    $charOffset = self::byteOffsetToCharOffset($segment, $byteOffset);

                    // La position de coupure est après le match
                    $breakPos = $charOffset + mb_strlen($match[0]);

                    // Éviter les coupures trop courtes
                    if ($breakPos <= $maxSize && $breakPos > $maxSize * 0.8) {
                        if ($bestBreak === null || abs($breakPos - $maxSize) < abs($bestBreak - $maxSize)) {
                            $bestBreak = $breakPos;
                        }
                    }
                }
            }
        }

        return $bestBreak;
    }

    public static function findBestWordBreak(string $segment, int $maxSize): ?int
    {
        $bestBreak = null;

        // Chercher tous les délimiteurs de mots
        $wordBoundaries = [
            ' ', // Espace
            "\t", // Tabulation
            "\n", // Nouvelle ligne
            "\r", // Retour chariot
            ',',
            ';',
            ':',
            '.',
            '!',
            '?', // Ponctuation
            '-',
            '_', // Traits d'union et underscores
            '/',
            '\\', // Slash
            '|', // Pipe
            '(',
            ')',
            '[',
            ']',
            '{',
            '}', // Parenthèses
            '"',
            "“",
            "”",
            "'", // Guillemets
            "’", // Apostrophe courbe
        ];

        // Chercher le meilleur délimiteur proche de la limite
        foreach ($wordBoundaries as $delimiter) {
            $pos = mb_strrpos(mb_substr($segment, 0, $maxSize + 30), $delimiter);
            if ($pos !== false) {
                $breakPos = $pos + mb_strlen($delimiter);
                // Préférer les délimiteurs proches de la limite
                if ($breakPos <= $maxSize && $breakPos > $maxSize * 0.8) {
                    if ($bestBreak === null || abs($breakPos - $maxSize) < abs($bestBreak - $maxSize)) {
                        $bestBreak = $breakPos;
                    }
                }
            }
        }

        // Si pas trouvé, chercher le dernier espace dans une fenêtre plus large
        if ($bestBreak === null) {
            $lastSpace = mb_strrpos(mb_substr($segment, 0, $maxSize + 50), ' ');
            if ($lastSpace !== false) {
                $breakPos = $lastSpace + 1;
                if ($breakPos <= $maxSize && $breakPos > $maxSize * 0.8) {
                    $bestBreak = $breakPos;
                }
            }
        }

        return $bestBreak;
    }

    public static function findLastNonAlphaTransition(string $segment, int $maxSize): ?int
    {
        // Chercher la dernière transition entre caractère alphanumérique et non-alphanumérique
        $segmentPart = mb_substr($segment, 0, $maxSize + 20);
        $length = mb_strlen($segmentPart);

        // Parcourir en arrière pour trouver une transition
        for ($i = min($maxSize, $length) - 1; $i >= $maxSize * 0.8; $i--) {
            if ($i > 0) {
                $current = mb_substr($segmentPart, $i, 1);
                $previous = mb_substr($segmentPart, $i - 1, 1);

                // Détection simple : si le caractère courant est non-alphanumérique
                // et le précédent est alphanumérique, c'est une bonne coupure
                $currentIsWordChar = preg_match('/[\p{L}\p{N}]/u', $current);
                $previousIsWordChar = preg_match('/[\p{L}\p{N}]/u', $previous);

                if ($previousIsWordChar && !$currentIsWordChar) {
                    return $i; // Couper après le caractère alphanumérique
                }

                // Aussi, si on trouve un espace, tabulation ou nouvelle ligne
                if (preg_match('/[\s]/u', $current) && $previousIsWordChar) {
                    return $i + 1; // Couper après l'espace
                }
            }
        }

        return null;
    }

    /**
     * Convertit un offset en octets en offset en caractères UTF-8
     */
    private static function byteOffsetToCharOffset(string $string, int $byteOffset): int
    {
        // substr en octets jusqu'à l'offset, puis mb_strlen pour compter les caractères
        return mb_strlen(substr($string, 0, $byteOffset), 'UTF-8');
    }
}
