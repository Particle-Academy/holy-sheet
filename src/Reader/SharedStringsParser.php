<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Archive\PartXml;
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
    /**
     * The table is counted before any entry is rendered, so a refusal under
     * `maxSharedStrings` costs the parse and nothing built from it.
     *
     * @return list<string>
     */
    public static function parse(string $xml, ?ReadLimits $limits = null): array
    {
        $doc = PartXml::simple($xml);
        if ($doc === false) {
            return [];
        }
        $limits?->sharedStrings($doc->si->count());

        $out = [];
        foreach ($doc->si as $si) {
            $out[] = self::renderSi($si);
        }
        return $out;
    }

    /**
     * One string item's text: its `<t>`, or its `<r><t>` runs joined. Also how
     * a cell's inline `<is>` is read, which has the same content model.
     */
    public static function renderSi(SimpleXMLElement $si): string
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
