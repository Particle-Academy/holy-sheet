<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Workbook\CellComment;
use SimpleXMLElement;

/**
 * Parses xl/commentsN.xml → map of A1 address → CellComment.
 */
final class CommentsParser
{
    /** @return array<string,CellComment> */
    public static function parse(string $commentsXml): array
    {
        $xml = @simplexml_load_string($commentsXml);
        if ($xml === false) return [];

        $authors = [];
        if (isset($xml->authors) && $xml->authors->author) {
            foreach ($xml->authors->author as $a) {
                $authors[] = (string) $a;
            }
        }

        $out = [];
        if (isset($xml->commentList) && $xml->commentList->comment) {
            foreach ($xml->commentList->comment as $c) {
                $ref = (string) $c['ref'];
                $authorIdx = isset($c['authorId']) ? (int) $c['authorId'] : -1;
                $author = $authors[$authorIdx] ?? null;
                $text = self::extractText($c->text);
                $out[$ref] = new CellComment(text: $text, author: $author);
            }
        }
        return $out;
    }

    private static function extractText(?SimpleXMLElement $textNode): string
    {
        if ($textNode === null) return '';
        $parts = [];
        if (isset($textNode->r)) {
            foreach ($textNode->r as $r) {
                $parts[] = (string) $r->t;
            }
        }
        if (isset($textNode->t)) {
            foreach ($textNode->t as $t) {
                $parts[] = (string) $t;
            }
        }
        return implode('', $parts);
    }
}
