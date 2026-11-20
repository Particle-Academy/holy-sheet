<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Archive\PartXml;

/**
 * Parses OOXML *.rels parts and resolves their targets.
 *
 * Returns `[rId => ['Target' => ..., 'Type' => ..., 'TargetMode' => ...]]`.
 * The Type URI is kept so the caller can filter by relationship kind; the
 * TargetMode so an External relationship (a URI, never a part) is never read
 * as one.
 *
 * Targets resolve per OPC (ECMA-376 Part 2): against the directory of the part
 * that OWNS the relationship, unless the target starts with "/", which makes it
 * package-absolute. Excel writes relative targets; openpyxl, LibreOffice and
 * others write absolute ones, and both are normal.
 */
final class RelsParser
{
    /**
     * @return array<string,array{Target:string,Type:string,TargetMode:string}>
     */
    public static function parse(string $relsXml): array
    {
        $xml = PartXml::simple($relsXml);
        if ($xml === false) return [];

        $rels = [];
        foreach ($xml->Relationship as $rel) {
            $id = (string) $rel['Id'];
            $rels[$id] = [
                'Target' => (string) $rel['Target'],
                'Type' => (string) $rel['Type'],
                'TargetMode' => (string) $rel['TargetMode'] === 'External' ? 'External' : 'Internal',
            ];
        }
        return $rels;
    }

    /**
     * The INTERNAL relationships whose Type URI contains `$typeUriContains`.
     * External ones are left out: they name a URI, not a part of this package.
     */
    public static function byType(array $rels, string $typeUriContains): array
    {
        $out = [];
        foreach ($rels as $id => $rel) {
            if (str_contains($rel['Type'], $typeUriContains) && ! self::isExternal($rel)) {
                $out[$id] = $rel;
            }
        }
        return $out;
    }

    /** @param array{TargetMode?:string} $rel */
    public static function isExternal(array $rel): bool
    {
        return ($rel['TargetMode'] ?? 'Internal') === 'External';
    }

    /**
     * The part name a target names, from the part `$source` that owns the
     * relationship: "/xl/a.xml" is package-absolute, "../a.xml" relative to
     * `$source`'s directory. "." and ".." segments normalise; ".." never climbs
     * above the package root.
     */
    public static function resolve(string $source, string $target): string
    {
        if (str_starts_with($target, '/')) {
            $combined = $target;
        } else {
            $slash = strrpos($source, '/');
            $combined = ($slash === false ? '' : substr($source, 0, $slash)).'/'.$target;
        }
        $parts = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '' && $segment !== '.') {
                $parts[] = $segment;
            }
        }
        return implode('/', $parts);
    }

    /** The rels part of `$part`: xl/worksheets/data.xml -> xl/worksheets/_rels/data.xml.rels. */
    public static function relsPathOf(string $part): string
    {
        $slash = strrpos($part, '/');

        return $slash === false ? '_rels/'.$part.'.rels' : substr($part, 0, $slash).'/_rels/'.substr($part, $slash + 1).'.rels';
    }
}
