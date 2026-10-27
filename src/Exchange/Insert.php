<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * A sheet's `insert` list: portable content placed at an address.
 *
 *     {"name": "Q4", "cells": {...}, "insert": [{"type": "portable", "at": "B4", "content": {fancyContent...}}]}
 *
 * Each entry expands through Inserter (SPEC 11.2): strings as `{value: s}`, so
 * `=` never promotes; formulas never written. Expansion is deterministic and
 * runs in `validateAndRepair` and in the write path.
 *
 * Where an inserted cell and an explicit `cells` entry name the same address,
 * the explicit entry wins, as `cells` already wins over `rows`: naming an
 * address is the more specific statement. A width the sheet sets wins the
 * same way; merged regions are added.
 */
final class Insert
{
    /**
     * Problems with a sheet's `insert`, as [subpath, expected, got, value, hint] rows.
     *
     * @return list<array{0:string,1:string,2:string,3:mixed,4:string}>
     */
    public static function problems(mixed $insert): array
    {
        if (! Json::isList($insert)) {
            return [['', 'array of {type: "portable", at, content}', \gettype($insert), $insert,
                'insert is a list of portable-content placements: [{"type": "portable", "at": "A1", "content": {...}}].']];
        }
        $out = [];
        foreach ($insert as $j => $entry) {
            if (! Json::isObj($entry) || ($entry['type'] ?? null) !== 'portable') {
                $out[] = ["[{$j}].type", '"portable"', \gettype($entry['type'] ?? null), $entry['type'] ?? null,
                    'Each insert entry is {"type": "portable", "at": "A1", "content": {...}}.'];

                continue;
            }
            foreach (array_keys($entry) as $k) {
                if (! \in_array($k, ['type', 'at', 'content'], true)) {
                    $out[] = ["[{$j}].{$k}", 'one of: type, at, content', 'unknown key', $k, 'An insert entry has only type, at and content.'];
                }
            }
            if (! Range::parseCell($entry['at'] ?? 'A1')['ok']) {
                $out[] = ["[{$j}].at", 'an A1 cell address', \gettype($entry['at']), $entry['at'], 'Where the content\'s top-left cell goes, like "A1" or "C5".'];
            }
            $r = Inserter::fromPortable($entry['content'] ?? null, $entry['at'] ?? 'A1');
            if (! $r['ok']) {
                $out[] = ["[{$j}].content", 'valid portable content (fancyContent: 1)', $r['refused']['reason'], $r['refused']['path'],
                    'The content was refused with '.$r['refused']['reason'].' at JSON pointer "'.$r['refused']['path'].'". Pass what extract() returned, unedited, or fix that location.'];
            }
        }

        return $out;
    }

    /**
     * The sheet with every insert expanded into `cells`, `mergedRegions` and
     * `columnWidths`, and `insert` removed; plus one report per entry.
     *
     * @param  array<string,mixed>  $sheet
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
     */
    public static function expand(array $sheet): array
    {
        if (! \array_key_exists('insert', $sheet)) {
            return [$sheet, []];
        }
        $cells = [];
        $merged = [];
        $widths = [];
        $reports = [];
        foreach (\is_array($sheet['insert']) ? $sheet['insert'] : [] as $entry) {
            $r = Inserter::fromPortable($entry['content'] ?? null, $entry['at'] ?? 'A1');
            if (! $r['ok']) {
                // The validator reports this; the write path never gets here with it.
                continue;
            }
            $cells = array_replace($cells, $r['sheet']['cells']);
            $merged = array_merge($merged, $r['sheet']['mergedRegions'] ?? []);
            $widths = array_replace($widths, $r['sheet']['columnWidths'] ?? []);
            $reports[] = $r['report'];
        }
        unset($sheet['insert']);

        $explicit = \is_array($sheet['cells'] ?? null) ? $sheet['cells'] : [];
        $sheet['cells'] = array_replace($cells, $explicit);
        if ($merged !== []) {
            $sheet['mergedRegions'] = array_merge(\is_array($sheet['mergedRegions'] ?? null) ? $sheet['mergedRegions'] : [], $merged);
        }
        if ($widths !== []) {
            $sheet['columnWidths'] = array_replace($widths, \is_array($sheet['columnWidths'] ?? null) ? $sheet['columnWidths'] : []);
        }

        return [$sheet, $reports];
    }
}
