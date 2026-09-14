<?php

declare(strict_types=1);

namespace HolySheet\Ops;

use HolySheet\Agent;
use HolySheet\Workbook\CellAddress;

/**
 * The op list that turns one Holy Sheet schema into another.
 *
 * ## Two guarantees, and where each applies
 *
 * 1. **Same workbook, no ops.** When `$a` and `$b` write the same workbook —
 *    compared as `describe(toBytes(...))`, so a columns/rows sheet and the cell
 *    map it becomes are the same, and the timestamp the writer stamps on a
 *    schema that names none is not a change — the diff is `[]`. This is what
 *    makes `diff($s, describe(toBytes($s))) === []`: saving without a change
 *    records nothing.
 * 2. **Otherwise, exact.** `reduce($a, diff($a, $b))` equals `$b`, key order
 *    aside. The ops are computed on the schemas as given and VERIFIED by
 *    replaying them through {@see SheetReducer}; a sheet whose granular ops do
 *    not reproduce it is replaced whole, and a workbook that still does not
 *    match is replaced whole. Correct first, small second.
 *
 * ## Small edits stay small
 *
 * Rows and columns are ALIGNED before cells are compared (a longest common
 * subsequence over each row's, then each column's, contents), so inserting a row
 * above a hundred others is one `insert_rows` plus the new row's cells, not a
 * hundred rewritten cells. One changed cell is one `set_cell`.
 *
 * Granular ops apply to sheets in CELL form — the form `describe()` returns. A
 * sheet authored as columns/rows/theme/totals, on either side, is replaced whole
 * when it changes: those keys expand at write time into cells an op could not
 * address without re-deriving the writer.
 *
 * ## Determinism
 *
 * The same inputs give the same ops, in the same order, in the PHP, Node and
 * Python ports: alignment ties break toward deleting first, and the alignment is
 * skipped (the changed middle treated as rows changed in place) past
 * {@see self::ALIGN_LIMIT} cells of work.
 */
final class SheetDiff
{
    /** Above this many LCS cells (after trimming the common prefix and suffix), rows or columns are not aligned. */
    public const ALIGN_LIMIT = 250_000;

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return list<array<string,mixed>>
     */
    public static function diff(array $a, array $b): array
    {
        if (self::same($a, $b) || self::equivalent($a, $b)) {
            return [];
        }

        $ops = self::granular($a, $b);

        if (self::same(SheetReducer::applyAll($a, $ops), $b)) {
            return $ops;
        }

        return [['type' => 'set_workbook', 'data' => $b]];
    }

    /**
     * Whether two schemas write the same workbook.
     *
     * Both are written and described. `meta.created` is compared only when both
     * schemas name one: the writer stamps the current time on a schema that
     * does not, and that timestamp is not an edit.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public static function equivalent(array $a, array $b): bool
    {
        $describedA = self::described($a);
        $describedB = self::described($b);

        if (! isset($a['meta']['created']) || ! isset($b['meta']['created'])) {
            foreach ([&$describedA, &$describedB] as &$described) {
                unset($described['meta']['created']);
                if (($described['meta'] ?? null) === []) {
                    unset($described['meta']);
                }
            }
            unset($described);
        }

        return self::same($describedA, $describedB);
    }

    /**
     * Structural equality with map key order ignored and list order kept.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::canon($a) === self::canon($b);
    }

    /**
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    private static function described(array $schema): array
    {
        $path = tempnam(sys_get_temp_dir(), 'holy-sheet-diff');

        try {
            file_put_contents($path, Agent::toBytes($schema));

            return Agent::describe($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return list<array<string,mixed>>
     */
    private static function granular(array $a, array $b): array
    {
        $sheetsA = array_values(is_array($a['sheets'] ?? null) ? $a['sheets'] : []);
        $sheetsB = array_values(is_array($b['sheets'] ?? null) ? $b['sheets'] : []);
        $namesA = array_map(static fn (array $s): string => (string) ($s['name'] ?? ''), $sheetsA);
        $namesB = array_map(static fn (array $s): string => (string) ($s['name'] ?? ''), $sheetsB);

        // Sheets are addressed by name. Two with one name cannot be told apart.
        if (count(array_unique($namesA)) !== count($namesA) || count(array_unique($namesB)) !== count($namesB)) {
            return [['type' => 'set_workbook', 'data' => $b]];
        }

        $ops = [];

        if (! self::same($a['meta'] ?? null, $b['meta'] ?? null)) {
            $ops[] = ['type' => 'set_meta', 'meta' => $b['meta'] ?? null];
        }

        $removed = array_values(array_diff($namesA, $namesB));
        $added = array_values(array_diff($namesB, $namesA));
        $renames = [];

        // A sheet whose contents are unchanged under a new name is a rename.
        foreach ($removed as $old) {
            foreach ($added as $new) {
                if (in_array($new, $renames, true)) {
                    continue;
                }
                if (self::same(self::withoutName($sheetsA[array_search($old, $namesA, true)]), self::withoutName($sheetsB[array_search($new, $namesB, true)]))) {
                    $renames[$old] = $new;

                    break;
                }
            }
        }

        // One sheet gone and one arrived is a rename, possibly with edits.
        $unmatchedRemoved = array_values(array_filter($removed, static fn (string $n): bool => ! array_key_exists($n, $renames)));
        $unmatchedAdded = array_values(array_filter($added, static fn (string $n): bool => ! in_array($n, $renames, true)));

        if (count($unmatchedRemoved) === 1 && count($unmatchedAdded) === 1) {
            $renames[$unmatchedRemoved[0]] = $unmatchedAdded[0];
            $unmatchedRemoved = [];
            $unmatchedAdded = [];
        }

        foreach ($unmatchedRemoved as $name) {
            $ops[] = ['type' => 'remove_sheet', 'sheet' => $name];
        }

        foreach ($namesA as $name) {
            if (array_key_exists($name, $renames)) {
                $ops[] = ['type' => 'rename_sheet', 'sheet' => $name, 'name' => $renames[$name]];
            }
        }

        foreach ($namesB as $index => $name) {
            if (in_array($name, $unmatchedAdded, true)) {
                $ops[] = ['type' => 'add_sheet', 'index' => $index, 'sheet' => $sheetsB[$index]];
            }
        }

        // Put the sheets in $b's order.
        $state = SheetReducer::applyAll($a, $ops);
        foreach ($namesB as $index => $name) {
            $current = array_map(static fn (array $s): string => (string) ($s['name'] ?? ''), $state['sheets'] ?? []);
            if (($current[$index] ?? null) !== $name) {
                $op = ['type' => 'move_sheet', 'sheet' => $name, 'toIndex' => $index];
                $ops[] = $op;
                $state = SheetReducer::apply($state, $op);
            }
        }

        foreach ($sheetsB as $index => $target) {
            $current = $state['sheets'][$index];

            if (self::same($current, $target)) {
                continue;
            }

            foreach (self::sheetOps($current, $target) as $op) {
                $ops[] = $op;
            }
        }

        return $ops;
    }

    /**
     * @param  array<string,mixed>  $sheet
     * @param  array<string,mixed>  $target
     * @return list<array<string,mixed>>
     */
    private static function sheetOps(array $sheet, array $target): array
    {
        $name = (string) $target['name'];
        $replace = [['type' => 'replace_sheet', 'sheet' => $name, 'data' => $target]];

        if (! self::isCellForm($sheet) || ! self::isCellForm($target)) {
            return $replace;
        }

        $ops = [];
        $work = $sheet;

        foreach (['row', 'col'] as $axis) {
            $structural = self::structuralOps($name, $work, $target, $axis);
            foreach ($structural as $op) {
                $ops[] = $op;
                $work = self::applyToSheet($work, $op);
            }
        }

        $cellsW = is_array($work['cells'] ?? null) ? $work['cells'] : [];
        $cellsT = is_array($target['cells'] ?? null) ? $target['cells'] : [];

        foreach (self::rowMajor(array_unique(array_merge(array_keys($cellsW), array_keys($cellsT)))) as $address) {
            $has = array_key_exists($address, $cellsT);

            if (! $has) {
                $ops[] = ['type' => 'clear_cell', 'sheet' => $name, 'address' => $address];

                continue;
            }

            $was = $cellsW[$address] ?? null;

            if ($was !== null && self::same($was, $cellsT[$address])) {
                continue;
            }

            $ops[] = self::setCellOp($name, $address, is_array($was) ? $was : null, (array) $cellsT[$address]);
        }

        if (! self::same($work['mergedRegions'] ?? [], $target['mergedRegions'] ?? [])) {
            $ops[] = ['type' => 'set_merged_regions', 'sheet' => $name, 'mergedRegions' => $target['mergedRegions'] ?? []];
        }

        if (! self::same($work['columnWidths'] ?? [], $target['columnWidths'] ?? [])) {
            $ops[] = ['type' => 'set_column_widths', 'sheet' => $name, 'columnWidths' => $target['columnWidths'] ?? []];
        }

        if (($work['frozenRows'] ?? 0) !== ($target['frozenRows'] ?? 0) || ($work['frozenCols'] ?? 0) !== ($target['frozenCols'] ?? 0)) {
            $ops[] = ['type' => 'set_frozen', 'sheet' => $name, 'rows' => $target['frozenRows'] ?? 0, 'cols' => $target['frozenCols'] ?? 0];
        }

        // Verified per sheet, so one sheet the granular ops cannot reproduce is
        // replaced without costing the rest of the workbook its small diff.
        $result = $sheet;
        foreach ($ops as $op) {
            $result = self::applyToSheet($result, $op);
        }

        return self::same($result, $target) ? $ops : $replace;
    }

    /**
     * @param  array<string,mixed>|null  $was
     * @param  array<string,mixed>  $cell
     * @return array<string,mixed>
     */
    private static function setCellOp(string $sheet, string $address, ?array $was, array $cell): array
    {
        $op = ['type' => 'set_cell', 'sheet' => $sheet, 'address' => $address];

        if (array_key_exists('value', $cell)) {
            $op['value'] = $cell['value'];
        }

        foreach (['formula', 'computedValue'] as $key) {
            if (array_key_exists($key, $cell)) {
                $op[$key] = $cell[$key];
            }
        }

        foreach (['format', 'comment'] as $key) {
            if (! self::same($was[$key] ?? null, $cell[$key] ?? null)) {
                $op[$key] = $cell[$key] ?? null;
            }
        }

        return $op;
    }

    /**
     * Row (or column) inserts and deletes, found by aligning contents.
     *
     * Emitted from the bottom (or right) up, so each op's position is still
     * the position in the sheet as it was.
     *
     * @param  array<string,mixed>  $sheet
     * @param  array<string,mixed>  $target
     * @return list<array<string,mixed>>
     */
    private static function structuralOps(string $name, array $sheet, array $target, string $axis): array
    {
        $lines = self::lines($sheet, $axis);
        $targetLines = self::lines($target, $axis);
        $ops = [];

        foreach (array_reverse(self::hunks($lines, $targetLines)) as [$start, $deleted, $inserted]) {
            $kept = min($deleted, $inserted);
            $at = $start + $kept + 1;

            if ($deleted > $inserted) {
                $ops[] = ['type' => $axis === 'row' ? 'delete_rows' : 'delete_columns', 'sheet' => $name, 'at' => $at, 'count' => $deleted - $inserted];
            } elseif ($inserted > $deleted && $at <= count($lines)) {
                // Past the last row there is nothing to move down.
                $ops[] = ['type' => $axis === 'row' ? 'insert_rows' : 'insert_columns', 'sheet' => $name, 'at' => $at, 'count' => $inserted - $deleted];
            }
        }

        return $ops;
    }

    /**
     * Each row's (or column's) contents, as a comparable string, index 0 = row 1.
     *
     * @param  array<string,mixed>  $sheet
     * @return list<string>
     */
    private static function lines(array $sheet, string $axis): array
    {
        $grouped = [];
        $max = 0;

        foreach (is_array($sheet['cells'] ?? null) ? $sheet['cells'] : [] as $address => $cell) {
            $parsed = CellAddress::parse((string) $address);
            if ($parsed === null) {
                continue;
            }
            [$col, $row] = $parsed;
            $line = $axis === 'row' ? $row : $col + 1;
            $position = $axis === 'row' ? $col : $row;
            $grouped[$line][$position] = $cell;
            $max = max($max, $line);
        }

        $lines = [];
        for ($i = 1; $i <= $max; $i++) {
            $line = $grouped[$i] ?? [];
            ksort($line);
            $lines[] = self::canon($line);
        }

        return $lines;
    }

    /**
     * Hunks of a longest-common-subsequence alignment: [start in $a, deleted, inserted].
     *
     * Ties break toward deleting first. Past {@see self::ALIGN_LIMIT} the changed
     * middle is one hunk.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0:int,1:int,2:int}>
     */
    public static function hunks(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $prefix = 0;

        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;

        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            $suffix++;
        }

        $midA = array_slice($a, $prefix, $n - $prefix - $suffix);
        $midB = array_slice($b, $prefix, $m - $prefix - $suffix);
        $rows = count($midA);
        $cols = count($midB);

        if ($rows === 0 && $cols === 0) {
            return [];
        }

        if ($rows * $cols > self::ALIGN_LIMIT) {
            return [[$prefix, $rows, $cols]];
        }

        // lengths[i][j] = LCS of midA[i..] and midB[j..]
        $lengths = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));

        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $cols - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $midA[$i] === $midB[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $hunks = [];
        $open = null;
        $i = 0;
        $j = 0;

        while ($i < $rows || $j < $cols) {
            if ($i < $rows && $j < $cols && $midA[$i] === $midB[$j]) {
                if ($open !== null) {
                    $hunks[] = $open;
                    $open = null;
                }
                $i++;
                $j++;

                continue;
            }

            $open ??= [$prefix + $i, 0, 0];

            if ($j >= $cols || ($i < $rows && $lengths[$i + 1][$j] >= $lengths[$i][$j + 1])) {
                $open[1]++;
                $i++;
            } else {
                $open[2]++;
                $j++;
            }
        }

        if ($open !== null) {
            $hunks[] = $open;
        }

        return $hunks;
    }

    /**
     * @param  array<string,mixed>  $sheet
     * @param  array<string,mixed>  $op
     * @return array<string,mixed>
     */
    private static function applyToSheet(array $sheet, array $op): array
    {
        return SheetReducer::apply(['sheets' => [$sheet]], $op)['sheets'][0];
    }

    /** @param array<string,mixed> $sheet */
    private static function isCellForm(array $sheet): bool
    {
        return array_diff(array_keys($sheet), SheetReducer::CELL_FORM_KEYS) === [];
    }

    /**
     * @param  array<string,mixed>  $sheet
     * @return array<string,mixed>
     */
    private static function withoutName(array $sheet): array
    {
        unset($sheet['name']);

        return $sheet;
    }

    /**
     * @param  array<int|string>  $addresses
     * @return list<string>
     */
    private static function rowMajor(array $addresses): array
    {
        $parsed = [];

        foreach ($addresses as $address) {
            $cell = CellAddress::parse((string) $address);
            if ($cell !== null) {
                $parsed[] = [$cell[1], $cell[0], (string) $address];
            }
        }

        usort($parsed, static fn (array $x, array $y): int => [$x[0], $x[1]] <=> [$y[0], $y[1]]);

        return array_column($parsed, 2);
    }

    /**
     * JSON with map keys sorted and list order kept.
     *
     * Throws on a value JSON cannot hold (invalid UTF-8, NAN, INF) rather than
     * returning "": two different unencodable values used to compare as the
     * same, so a diff could record no change where there was one.
     */
    private static function canon(mixed $value): string
    {
        return json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR, 4096);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
