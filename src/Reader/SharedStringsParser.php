<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use SimpleXMLElement;

/**
 * Parses xl/sharedStrings.xml → list<string> indexed by shared-string index.
 *
 * Excel and other authoring tools deduplicate string cell values into a
 * single shared-strings table; cells of type `s` reference the entry by
 * integer index. Rich strings (`<si>` with multiple `<r><t>` runs) are
 * flattened to plain text — run-level formatting is dropped because
 * Holy Sheet's cell-value model is plain text.
 */
final class SharedStringsParser
{
    /** @return list<string> */
    public static function parse(string $xml): array
    {
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }

        $out = [];
        foreach ($doc->si as $si) {
            $out[] = self::renderSi($si);
        }
        return $out;
    }

    private static function renderSi(SimpleXMLElement $si): string
    {
        if (isset($si->t)) {
            return (string) $si->t;
        }

        $parts = [];
        if (isset($si->r)) {
            foreach ($si->r as $run) {
                if (isset($run->t)) {
                    $parts[] = (string) $run->t;
                }
            }
        }
        return implode('', $parts);
    }
}
