<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use SimpleXMLElement;

/**
 * Parses OOXML *.rels files. Used to:
 *   - resolve `xl/_rels/workbook.xml.rels`     → rId → worksheet path
 *   - resolve `xl/worksheets/_rels/sheetN.xml.rels` → rId → comments / vml drawing paths
 *
 * Returns simple `[rId => target]` arrays. Relationship Type URIs are
 * preserved alongside so the caller can filter by relationship kind
 * when needed (e.g. distinguishing comments from vmlDrawings).
 */
final class RelsParser
{
    /**
     * @return array<string,array{Target:string,Type:string}>
     */
    public static function parse(string $relsXml): array
    {
        $xml = @simplexml_load_string($relsXml);
        if ($xml === false) return [];

        $rels = [];
        foreach ($xml->Relationship as $rel) {
            $id = (string) $rel['Id'];
            $rels[$id] = [
                'Target' => (string) $rel['Target'],
                'Type' => (string) $rel['Type'],
            ];
        }
        return $rels;
    }

    /** Filter to only relationships with a specific Type URI. */
    public static function byType(array $rels, string $typeUriContains): array
    {
        $out = [];
        foreach ($rels as $id => $rel) {
            if (str_contains($rel['Type'], $typeUriContains)) {
                $out[$id] = $rel;
            }
        }
        return $out;
    }
}
