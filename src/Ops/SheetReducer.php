<?php

declare(strict_types=1);

namespace HolySheet\Ops;

use HolySheet\Workbook\CellAddress;

/**
 * Apply {@see SheetOpSchema} ops to a Holy Sheet schema, returning a new schema.
 *
 * Pure: the input is never modified. An op naming a sheet or cell that is not
 * there is skipped, as fancy-sheets' `reduceWorkbook` skips an unknown sheet, so
 * a replayed history degrades rather than throws.
 *
 * `set_cell`, `set_range` and `set_workbook` keep fancy-sheets' shapes and
 * semantics — a `set_cell` without `formula` clears the formula and keeps the
 * format and comment; a null write to an absent cell does nothing — so the same
 * ops can drive a live `useSheetSync` session. Everything else is holy-sheet's:
 * the structure fancy-sheets has no op for (sheets, rows, columns, merges, widths,
 * panes, meta) and the cell parts it does not carry (`computedValue`, `format`,
 * `comment`).
 *
 * The row and column ops move cells, merged regions and column widths. They do
 * not rewrite formula text: a formula that should follow an inserted row is a
 * change to that cell, and a diff records it as one.
 */
final class SheetReducer
{
    /** Sheet keys the granular ops address. A sheet with any other key is authored form. */
    public const CELL_FORM_KEYS = ['name', 'cells', 'mergedRegions', 'columnWidths', 'frozenRows', 'frozenCols'];

    /**
     * @param  array<string,mixed>  $schema
     * @param  list<array<string,mixed>>  $ops
     * @return array<string,mixed>
     */
    public static function applyAll(array $schema, array $ops): array
    {
        foreach ($ops as $op) {
            $schema = self::apply($schema, $op);
        }

        return $schema;
    }

    /**
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $op
     * @return array<string,mixed>
     */
    public static function apply(array $schema, array $op): array
    {
        $type = $op['type'] ?? null;

        if ($type === 'set_workbook') {
            return is_array($op['data'] ?? null) ? $op['data'] : $schema;
        }

        if ($type === 'set_meta') {
            if (($op['meta'] ?? null) === null) {
                unset($schema['meta']);
            } else {
                $schema['meta'] = $op['meta'];
            }

            return $schema;
        }

        $sheets = is_array($schema['sheets'] ?? null) ? array_values($schema['sheets']) : [];

        if ($type === 'add_sheet') {
            if (! is_array($op['sheet'] ?? null)) {
                return $schema;
            }
            $index = max(0, min(count($sheets), (int) ($op['index'] ?? count($sheets))));
            array_splice($sheets, $index, 0, [$op['sheet']]);
            $schema['sheets'] = $sheets;

            return $schema;
        }

        $at = self::find($sheets, (string) ($op['sheet'] ?? ''));

        if ($at === null) {
            return $schema;
        }

        switch ($type) {
            case 'remove_sheet':
                array_splice($sheets, $at, 1);
                break;

            case 'rename_sheet':
                $sheets[$at]['name'] = (string) ($op['name'] ?? $sheets[$at]['name']);
                break;

            case 'move_sheet':
                [$moved] = array_splice($sheets, $at, 1);
                $to = max(0, min(count($sheets), (int) ($op['toIndex'] ?? $at)));
                array_splice($sheets, $to, 0, [$moved]);
                break;

            case 'replace_sheet':
                if (is_array($op['data'] ?? null)) {
                    $sheets[$at] = $op['data'];
                }
                break;

            case 'set_merged_regions':
                $sheets[$at] = self::setOrUnset($sheets[$at], 'mergedRegions', $op['mergedRegions'] ?? [], []);
                break;

            case 'set_column_widths':
                $sheets[$at] = self::setOrUnset($sheets[$at], 'columnWidths', $op['columnWidths'] ?? [], []);
                break;

            case 'set_frozen':
                $sheets[$at] = self::setOrUnset($sheets[$at], 'frozenRows', (int) ($op['rows'] ?? 0), 0);
                $sheets[$at] = self::setOrUnset($sheets[$at], 'frozenCols', (int) ($op['cols'] ?? 0), 0);
                break;

            case 'set_cell':
                $sheets[$at] = self::setCell($sheets[$at], $op);
                break;

            case 'set_range':
                $sheets[$at] = self::setRange($sheets[$at], $op);
                break;

            case 'clear_cell':
                unset($sheets[$at]['cells'][strtoupper((string) ($op['address'] ?? ''))]);
                break;

            case 'insert_rows':
                $sheets[$at] = self::shiftRows($sheets[$at], (int) ($op['at'] ?? 0), max(0, (int) ($op['count'] ?? 0)));
                break;

            case 'delete_rows':
                $sheets[$at] = self::shiftRows($sheets[$at], (int) ($op['at'] ?? 0), -max(0, (int) ($op['count'] ?? 0)));
                break;

            case 'insert_columns':
                $sheets[$at] = self::shiftColumns($sheets[$at], (int) ($op['at'] ?? 0), max(0, (int) ($op['count'] ?? 0)));
                break;

            case 'delete_columns':
                $sheets[$at] = self::shiftColumns($sheets[$at], (int) ($op['at'] ?? 0), -max(0, (int) ($op['count'] ?? 0)));
                break;

            default:
                return $schema;
        }

        $schema['sheets'] = array_values($sheets);

        return $schema;
    }

    /** @param list<array<string,mixed>> $sheets */
    private static function find(array $sheets, string $name): ?int
    {
        foreach ($sheets as $i => $sheet) {
            if (($sheet['name'] ?? null) === $name) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $sheet
     * @return array<string,mixed>
     */
    private static function setOrUnset(array $sheet, string $key, mixed $value, mixed $empty): array
    {
        if ($value === $empty || $value === null) {
            unset($sheet[$key]);
        } else {
            $sheet[$key] = $value;
        }

        return $sheet;
    }

    /**
     * fancy-sheets' `set_cell`, plus the parts of a cell it does not carry.
     *
     * - `value` is written; `formula` and `computedValue` are REPLACED — absent
     *   in the op means absent in the cell, as fancy-sheets clears `formula`.
     * - `format` and `comment` are KEPT when the op omits them, replaced when it
     *   carries one, and removed when it carries null.
     * - A null write to an absent cell, carrying nothing else, does nothing.
     *
     * @param  array<string,mixed>  $sheet
     * @param  array<string,mixed>  $op
     * @return array<string,mixed>
     */
    private static function setCell(array $sheet, array $op): array
    {
        $address = strtoupper((string) ($op['address'] ?? ''));

        if (CellAddress::parse($address) === null) {
            return $sheet;
        }

        $cells = is_array($sheet['cells'] ?? null) ? $sheet['cells'] : [];
        $existing = $cells[$address] ?? null;
        $value = $op['value'] ?? null;

        $carries = static fn (string $key): bool => array_key_exists($key, $op) && $op[$key] !== null;

        if ($existing === null && $value === null && ! $carries('formula') && ! $carries('computedValue') && ! $carries('format') && ! $carries('comment')) {
            return $sheet;
        }

        // `value` is optional in a CellData ({"formula": "SUM(A1:A3)"} is a whole
        // cell), so an op without one writes a cell without one.
        $cell = array_key_exists('value', $op) ? ['value' => $value] : [];

        foreach (['formula', 'computedValue'] as $key) {
            if ($carries($key)) {
                $cell[$key] = $op[$key];
            }
        }

        foreach (['format', 'comment'] as $key) {
            if (array_key_exists($key, $op)) {
                if ($op[$key] !== null) {
                    $cell[$key] = $op[$key];
                }
            } elseif (is_array($existing) && array_key_exists($key, $existing)) {
                $cell[$key] = $existing[$key];
            }
        }

        if ($cell === []) {
            unset($cells[$address]);
        } else {
            $cells[$address] = $cell;
        }
        $sheet['cells'] = $cells;

        return $sheet;
    }

    /**
     * fancy-sheets' `set_range`: values row-major from `start`, each written as a
     * `set_cell` with no formula. `end` is accepted and, as there, not read.
     *
     * @param  array<string,mixed>  $sheet
     * @param  array<string,mixed>  $op
     * @return array<string,mixed>
     */
    private static function setRange(array $sheet, array $op): array
    {
        $start = CellAddress::parse((string) ($op['start'] ?? ''));

        if ($start === null || ! is_array($op['values'] ?? null)) {
            return $sheet;
        }

        [$col0, $row0] = $start;

        foreach (array_values($op['values']) as $r => $row) {
            foreach (array_values(is_array($row) ? $row : []) as $c => $value) {
                $sheet = self::setCell($sheet, [
                    'address' => CellAddress::letter($col0 + $c).($row0 + $r),
                    'value' => $value,
                ]);
            }
        }

        return $sheet;
    }

    /**
     * Insert (`$delta` > 0) or delete (`$delta` < 0) rows at 1-based row `$at`.
     *
     * @param  array<string,mixed>  $sheet
     * @return array<string,mixed>
     */
    private static function shiftRows(array $sheet, int $at, int $delta): array
    {
        if ($at < 1 || $delta === 0) {
            return $sheet;
        }

        $sheet = self::remapCells($sheet, static function (int $col, int $row) use ($at, $delta): ?array {
            if ($row < $at) {
                return [$col, $row];
            }
            if ($delta < 0 && $row < $at - $delta) {
                return null;
            }

            return [$col, $row + $delta];
        });

        return self::remapMerges($sheet, 'row', $at, $delta);
    }

    /**
     * Insert or delete columns at 1-based column `$at` (A = 1).
     *
     * @param  array<string,mixed>  $sheet
     * @return array<string,mixed>
     */
    private static function shiftColumns(array $sheet, int $at, int $delta): array
    {
        if ($at < 1 || $delta === 0) {
            return $sheet;
        }

        $sheet = self::remapCells($sheet, static function (int $col, int $row) use ($at, $delta): ?array {
            $number = $col + 1;
            if ($number < $at) {
                return [$col, $row];
            }
            if ($delta < 0 && $number < $at - $delta) {
                return null;
            }

            return [$col + $delta, $row];
        });

        if (is_array($sheet['columnWidths'] ?? null)) {
            $widths = [];
            foreach ($sheet['columnWidths'] as $index => $width) {
                $number = (int) $index + 1;
                if ($number < $at) {
                    $widths[(int) $index] = $width;
                } elseif ($delta > 0 || $number >= $at - $delta) {
                    $widths[(int) $index + $delta] = $width;
                }
            }
            ksort($widths);
            $sheet = self::setOrUnset($sheet, 'columnWidths', $widths, []);
        }

        return self::remapMerges($sheet, 'col', $at, $delta);
    }

    /**
     * Move a 1-based span [$start, $end] for an insert or delete at `$at`. Null
     * when a delete removes the whole span; a span the delete cuts into shrinks.
     *
     * @return array{0:int,1:int}|null
     */
    private static function shiftSpan(int $start, int $end, int $at, int $delta): ?array
    {
        if ($delta > 0) {
            return [$start >= $at ? $start + $delta : $start, $end >= $at ? $end + $delta : $end];
        }

        $last = $at - $delta - 1; // the last deleted index
        $newStart = $start < $at ? $start : ($start > $last ? $start + $delta : $at);
        $newEnd = $end < $at ? $end : ($end > $last ? $end + $delta : $at - 1);

        return $newEnd < $newStart ? null : [$newStart, $newEnd];
    }

    /**
     * @param  array<string,mixed>  $sheet
     * @param  callable(int,int): (array{0:int,1:int}|null)  $move
     * @return array<string,mixed>
     */
    private static function remapCells(array $sheet, callable $move): array
    {
        if (! is_array($sheet['cells'] ?? null)) {
            return $sheet;
        }

        $placed = [];

        foreach ($sheet['cells'] as $address => $cell) {
            $parsed = CellAddress::parse((string) $address);

            if ($parsed === null) {
                continue;
            }

            $to = $move($parsed[0], $parsed[1]);

            if ($to !== null) {
                $placed[] = [$to[1], $to[0], CellAddress::letter($to[0]).$to[1], $cell];
            }
        }

        // Row-major, the order describe() reads cells in.
        usort($placed, static fn (array $x, array $y): int => [$x[0], $x[1]] <=> [$y[0], $y[1]]);

        $cells = [];
        foreach ($placed as [, , $address, $cell]) {
            $cells[$address] = $cell;
        }

        $sheet['cells'] = $cells;

        return $sheet;
    }

    /**
     * @param  array<string,mixed>  $sheet
     * @return array<string,mixed>
     */
    private static function remapMerges(array $sheet, string $axis, int $at, int $delta): array
    {
        if (! is_array($sheet['mergedRegions'] ?? null)) {
            return $sheet;
        }

        $regions = [];

        foreach ($sheet['mergedRegions'] as $region) {
            $start = CellAddress::parse((string) ($region['start'] ?? ''));
            $end = CellAddress::parse((string) ($region['end'] ?? ''));

            if ($start === null || $end === null) {
                $regions[] = $region;

                continue;
            }

            if ($axis === 'row') {
                $moved = self::shiftSpan($start[1], $end[1], $at, $delta);
                if ($moved === null) {
                    continue;
                }
                $regions[] = ['start' => CellAddress::letter($start[0]).$moved[0], 'end' => CellAddress::letter($end[0]).$moved[1]];
            } else {
                $moved = self::shiftSpan($start[0] + 1, $end[0] + 1, $at, $delta);
                if ($moved === null) {
                    continue;
                }
                $regions[] = ['start' => CellAddress::letter($moved[0] - 1).$start[1], 'end' => CellAddress::letter($moved[1] - 1).$end[1]];
            }
        }

        return self::setOrUnset($sheet, 'mergedRegions', $regions, []);
    }
}
