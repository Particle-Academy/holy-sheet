<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Exceptions\ReadLimitExceeded;
use InvalidArgumentException;

/**
 * A read sheet as rows: a list of rows, each a list of values in column order,
 * with row 1 at index 0 and column A at index 0. `Agent::rows()`.
 *
 * - A blank row in the middle is an empty list, so `$rows[$n - 1]` is always
 *   row n. Trailing blank rows are not padded, and neither is the end of a row:
 *   it stops at its last value.
 * - A gap before a value is `null`.
 * - A formula cell gives its cached `computedValue` (null when the file cached
 *   none) by default, which is what the cell displays. `formulas: 'formula'`
 *   gives the formula instead, with its leading `=`.
 * - A merged region's value is at its top-left cell only; every other cell it
 *   covers is null, whatever the file stored there.
 * - A cell with formatting and no value is blank.
 *
 * Padding is bounded: the view is sized before it is built, and one holding
 * more than `maxCells` positions refuses with ReadLimitExceeded, because a few
 * cells far to the right of a million rows would otherwise make billions.
 */
final class RowsView
{
    /**
     * @param  array<string,mixed>  $sheet  one entry of `read()['sheets']` (any sheet with a `cells` map)
     * @param  array<string,mixed>  $options  `formulas` ('computed' | 'formula'), `maxCells`
     * @return list<list<mixed>>
     */
    public static function rows(array $sheet, array $options = []): array
    {
        foreach (array_keys($options) as $key) {
            if (! in_array($key, ['formulas', 'maxCells'], true)) {
                throw new InvalidArgumentException("[holy-sheet] unknown rows option: {$key}");
            }
        }
        $mode = $options['formulas'] ?? 'computed';
        if ($mode !== 'computed' && $mode !== 'formula') {
            throw new InvalidArgumentException('[holy-sheet] rows option formulas must be "computed" or "formula"');
        }
        $maxCells = $options['maxCells'] ?? ReadLimits::DEFAULTS['maxCells'];
        if (! is_int($maxCells) || $maxCells < 1) {
            throw new InvalidArgumentException('[holy-sheet] rows option maxCells must be a positive integer');
        }

        $covered = self::covered($sheet['mergedRegions'] ?? null);

        /** @var array<int,array<int,mixed>> $values row number => column index => value */
        $values = [];
        $cells = is_array($sheet['cells'] ?? null) ? $sheet['cells'] : [];
        foreach ($cells as $address => $cell) {
            $at = ReadLimits::address((string) $address);
            if ($at === null) {
                continue;
            }
            [$col, $row] = $at;
            if ($row < 1 || self::isCovered($covered, $col, $row)) {
                continue;
            }
            $value = self::value($cell, $mode);
            if ($value === null) {
                continue;
            }
            $values[$row][$col] = $value;
        }
        if ($values === []) {
            return [];
        }

        $size = 0;
        foreach ($values as $byCol) {
            $size += max(array_keys($byCol)) + 1;
        }
        if ($size > $maxCells) {
            throw new ReadLimitExceeded('maxCells', $maxCells, $size, 'the rows view would hold');
        }

        $out = [];
        $last = max(array_keys($values));
        for ($r = 1; $r <= $last; $r++) {
            if (! isset($values[$r])) {
                $out[] = [];

                continue;
            }
            $line = array_fill(0, max(array_keys($values[$r])) + 1, null);
            foreach ($values[$r] as $c => $v) {
                $line[$c] = $v;
            }
            $out[] = $line;
        }

        return $out;
    }

    private static function value(mixed $cell, string $mode): mixed
    {
        if (! is_array($cell)) {
            // A bare scalar; a bare "=..." string is a formula, as the writer reads it.
            if (is_string($cell) && strlen($cell) > 1 && $cell[0] === '=') {
                return $mode === 'formula' ? $cell : null;
            }

            return $cell;
        }
        $formula = $cell['formula'] ?? null;
        if (is_string($formula) && $formula !== '') {
            if ($mode === 'formula') {
                return $formula[0] === '=' ? $formula : '='.$formula;
            }

            return $cell['computedValue'] ?? null;
        }

        return $cell['value'] ?? null;
    }

    /** @return list<array{0:int,1:int,2:int,3:int}> [c1, r1, c2, r2] */
    private static function covered(mixed $regions): array
    {
        $out = [];
        foreach (is_array($regions) ? $regions : [] as $m) {
            $a = is_array($m) && is_string($m['start'] ?? null) ? ReadLimits::address($m['start']) : null;
            $b = is_array($m) && is_string($m['end'] ?? null) ? ReadLimits::address($m['end']) : null;
            if ($a !== null && $b !== null) {
                $out[] = [min($a[0], $b[0]), min($a[1], $b[1]), max($a[0], $b[0]), max($a[1], $b[1])];
            }
        }

        return $out;
    }

    /** Inside a region and not its top-left. */
    private static function isCovered(array $covered, int $col, int $row): bool
    {
        foreach ($covered as [$c1, $r1, $c2, $r2]) {
            if ($col >= $c1 && $col <= $c2 && $row >= $r1 && $row <= $r2 && ($col !== $c1 || $row !== $r1)) {
                return true;
            }
        }

        return false;
    }
}
