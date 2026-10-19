<?php

declare(strict_types=1);

namespace HolySheet\Schema;

use DateTimeInterface;
use HolySheet\Exceptions\SchemaException;
use HolySheet\Workbook\Cell;
use HolySheet\Workbook\CellAddress;
use HolySheet\Workbook\CellComment;
use HolySheet\Workbook\CellFormat;
use HolySheet\Workbook\ChartRange;
use HolySheet\Workbook\MergedRegion;
use HolySheet\Workbook\Sheet;
use HolySheet\Workbook\Workbook;
use HolySheet\Writer\Format\DateConverter;
use Stringable;

final class Normalizer
{
    /** @param  array<string,mixed>  $schema */
    public function normalize(array $schema): Workbook
    {
        $sheets = [];
        foreach ($schema['sheets'] as $sheetSchema) {
            $sheets[] = $this->normalizeSheet($sheetSchema);
        }
        return new Workbook($sheets, $schema['meta'] ?? []);
    }

    /** @param  array<string,mixed>  $sheet */
    private function normalizeSheet(array $sheet): Sheet
    {
        $name = (string) $sheet['name'];

        // NOTE: there is no separate "cells-only" path. A sheet with no columns
        // and no rows simply builds an empty table below and then takes the
        // overlay, which is the same code the combined case runs.
        //
        // It USED to return here the moment `cells` was set, and that early
        // return silently discarded `columns`, `rows`, `totals` and `theme` — a
        // four-row table with one styled title cell wrote a one-cell workbook,
        // and `Agent::validate()` returned no errors, because the validator
        // (`Validator::validateSheet`) explicitly permits both together. The two
        // halves of the contract disagreed and the writer was the one that was
        // wrong.
        $cells = [];
        $columns = $sheet['columns'] ?? [];
        $rows = $sheet['rows'] ?? [];
        $themeKey = $sheet['theme'] ?? 'default';
        $theme = new Theme($themeKey);
        $headerOffset = 0;

        $columnFormats = [];
        $columnByHeader = [];
        foreach ($columns as $colIdx => $columnDef) {
            $columnFormats[$colIdx] = $this->columnFormat($columnDef);
            if (is_array($columnDef) && isset($columnDef['header'])) {
                $columnByHeader[(string) $columnDef['header']] = $colIdx;
            }
        }

        if ($columns !== []) {
            foreach ($columns as $col => $columnDef) {
                $address = CellAddress::letter($col).'1';
                $header = is_array($columnDef) ? ($columnDef['header'] ?? '') : (string) $columnDef;
                $cells[$address] = new Cell($address, (string) $header, format: $theme->headerFormat());
            }
            $headerOffset = 1;
        }

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $address = CellAddress::letter($c).($r + 1 + $headerOffset);
                $columnFormat = $columnFormats[$c] ?? null;
                $rowBand = $theme->dataFormat($r);
                $merged = $rowBand !== null ? ($columnFormat?->mergeWith($rowBand) ?? $rowBand) : $columnFormat;
                $cells[$address] = $this->buildCell($address, $value, $merged, $name);
            }
        }

        if (!empty($sheet['totals']) && is_array($sheet['totals']) && $rows !== [] && $columns !== []) {
            $totalsRow = count($rows) + 1 + $headerOffset;
            $totalsTheme = $theme->totalsFormat();
            $cells[CellAddress::letter(0).$totalsRow] = new Cell(
                CellAddress::letter(0).$totalsRow,
                'Total',
                format: $totalsTheme,
            );

            foreach ($sheet['totals'] as $headerKey => $aggOp) {
                if (!isset($columnByHeader[$headerKey])) continue;
                $colIdx = $columnByHeader[$headerKey];
                $colLetter = CellAddress::letter($colIdx);
                $rangeStart = $colLetter.($headerOffset + 1);
                $rangeEnd = $colLetter.(count($rows) + $headerOffset);
                $func = strtoupper((string) $aggOp);
                if (!in_array($func, ['SUM', 'AVG', 'COUNT', 'MIN', 'MAX'], true)) continue;
                $excelFunc = $func === 'AVG' ? 'AVERAGE' : $func;
                $address = $colLetter.$totalsRow;
                $combined = ($columnFormats[$colIdx] ?? null)?->mergeWith($totalsTheme) ?? $totalsTheme;
                $cells[$address] = new Cell(
                    address: $address,
                    value: null,
                    formula: "{$excelFunc}({$rangeStart}:{$rangeEnd})",
                    format: $combined,
                );
            }
        }

        // The overlay. Explicit cells win at their address, because naming an
        // address is a more specific statement than "row 3 of the table".
        //
        // A format is MERGED rather than swapped, so a cell that only sets
        // `bold` keeps the theme's banding and the column's currency format
        // instead of dropping to bare. Set a field to override it; omit it to
        // inherit. (`mergeWith` takes the overlay as the argument — the caller's
        // value wins field by field.)
        if (isset($sheet['cells']) && is_array($sheet['cells'])) {
            foreach ($this->normalizeCellMap($sheet['cells'], $name) as $address => $cell) {
                $beneath = $cells[$address] ?? null;

                $cells[$address] = $beneath?->format !== null
                    ? $cell->withFormat($beneath->format->mergeWith($cell->format))
                    : $cell;
            }
        }

        return new Sheet(
            name: $name,
            cells: $cells,
            mergedRegions: $this->normalizeMerges($sheet['mergedRegions'] ?? []),
            columnWidths: $this->normalizeWidths($sheet),
            frozenRows: (int) ($sheet['frozenRows'] ?? 0),
            frozenCols: (int) ($sheet['frozenCols'] ?? 0),
            rowHeights: self::normalizeRowHeights($sheet['rowHeights'] ?? null),
            print: self::normalizePrint($sheet['print'] ?? null),
            charts: self::normalizeCharts($sheet['charts'] ?? null, $name, $cells),
        );
    }

    /** The chart types the writer draws. */
    public const CHART_TYPES = ['column', 'bar', 'line', 'pie'];

    /**
     * `charts`, normalised. A chart the validator would refuse is dropped here
     * (no type it draws, or no series with a range); everything else keeps
     * what it can. A chart with no `position` is placed to the right of the
     * sheet's content, 8 columns wide and 16 rows tall, stacked one under the
     * other.
     *
     * @param  array<string, Cell>  $cells
     * @return list<array<string, mixed>>
     */
    /**
     * A series' coloured points: index within its values -> colour, by index.
     * An index outside the values or a colour that is not #RRGGBB is dropped;
     * a repeated index keeps its last colour.
     *
     * @return list<array{index: int, color: string}>
     */
    public static function normalizePoints(mixed $points, int $count): array
    {
        if (! is_array($points)) {
            return [];
        }
        $byIndex = [];
        foreach ($points as $p) {
            $index = is_array($p) ? ($p['index'] ?? null) : null;
            $color = is_array($p) ? ($p['color'] ?? null) : null;
            if (is_int($index) && $index >= 0 && $index < $count && is_string($color) && preg_match('/^#([0-9A-Fa-f]{6})$/', $color, $m) === 1) {
                $byIndex[$index] = strtoupper($m[1]);
            }
        }
        ksort($byIndex);
        $out = [];
        foreach ($byIndex as $index => $color) {
            $out[] = ['index' => $index, 'color' => $color];
        }

        return $out;
    }

    public static function normalizeCharts(mixed $charts, string $sheetName, array $cells): array
    {
        if (! is_array($charts)) {
            return [];
        }
        $lastCol = -1;
        foreach (array_keys($cells) as $address) {
            $parsed = CellAddress::parse((string) $address);
            if ($parsed !== null) {
                $lastCol = max($lastCol, $parsed[0]);
            }
        }

        $out = [];
        foreach (array_values($charts) as $chart) {
            if (! is_array($chart) || ! in_array($chart['type'] ?? null, self::CHART_TYPES, true)) {
                continue;
            }
            $series = [];
            foreach (is_array($chart['series'] ?? null) ? array_values($chart['series']) : [] as $s) {
                $values = is_array($s) ? ChartRange::parse($s['values'] ?? null, $sheetName) : null;
                if ($values === null) {
                    continue;
                }
                $color = is_string($s['color'] ?? null) && preg_match('/^#([0-9A-Fa-f]{6})$/', $s['color'], $m) === 1 ? strtoupper($m[1]) : null;
                $series[] = ['name' => is_string($s['name'] ?? null) ? $s['name'] : null, 'values' => $values, 'color' => $color, 'points' => self::normalizePoints($s['points'] ?? null, count($values->addresses()))];
            }
            if ($series === []) {
                continue;
            }

            $stack = count($out);
            $from = [$lastCol + 2, 1 + 18 * $stack];
            $to = [$from[0] + 8, $from[1] + 16];
            if (is_array($chart['position'] ?? null)) {
                $a = is_string($chart['position']['from'] ?? null) ? CellAddress::parse($chart['position']['from']) : null;
                $b = is_string($chart['position']['to'] ?? null) ? CellAddress::parse($chart['position']['to']) : null;
                if ($a !== null && $b !== null && $b[0] > $a[0] && $b[1] > $a[1]) {
                    $from = [$a[0], $a[1] - 1];
                    $to = [$b[0], $b[1] - 1];
                }
            }

            $out[] = [
                'type' => $chart['type'],
                'title' => is_string($chart['title'] ?? null) && $chart['title'] !== '' ? $chart['title'] : null,
                'categories' => ChartRange::parse($chart['categories'] ?? null, $sheetName),
                'series' => $series,
                'from' => $from,
                'to' => $to,
                'legend' => is_bool($chart['legend'] ?? null) ? $chart['legend'] : null,
                'dataLabels' => ($chart['dataLabels'] ?? false) === true,
                'format' => is_string($chart['format'] ?? null) && $chart['format'] !== '' ? $chart['format'] : null,
            ];
        }

        return $out;
    }

    /**
     * `rowHeights`: 1-based row number => pixels. Anything that is not a row
     * number with a non-negative number of pixels is dropped (the validator
     * names it first).
     *
     * @return array<int,float>
     */
    public static function normalizeRowHeights(mixed $map): array
    {
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $row => $px) {
            if (preg_match('/^[1-9]\d{0,6}$/', (string) $row) === 1 && is_numeric($px) && (float) $px >= 0) {
                $out[(int) $row] = (float) $px;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * `print`, normalised; null when it asks for nothing.
     *
     * @return array{orientation: ?string, fitToWidth: bool, fitToPage: bool, footer: ?array{left: string, center: string, right: string}}|null
     */
    public static function normalizePrint(mixed $print): ?array
    {
        if (!is_array($print)) {
            return null;
        }
        $orientation = in_array($print['orientation'] ?? null, ['portrait', 'landscape'], true) ? $print['orientation'] : null;
        $fit = ($print['fitToWidth'] ?? false) === true;
        $page = ($print['fitToPage'] ?? false) === true;
        $footer = null;
        if (is_array($print['footer'] ?? null)) {
            $slot = static fn (string $k): string => is_string($print['footer'][$k] ?? null) ? $print['footer'][$k] : '';
            $footer = ['left' => $slot('left'), 'center' => $slot('center'), 'right' => $slot('right')];
            if ($footer['left'] === '' && $footer['center'] === '' && $footer['right'] === '') {
                $footer = null;
            }
        }
        if ($orientation === null && ! $fit && ! $page && $footer === null) {
            return null;
        }

        return ['orientation' => $orientation, 'fitToWidth' => $fit, 'fitToPage' => $page, 'footer' => $footer];
    }

    /** @param  array<string,array<string,mixed>>  $map */
    private function normalizeCellMap(array $map, ?string $sheetName = null): array
    {
        $cells = [];
        foreach ($map as $address => $cellData) {
            $address = (string) $address;

            // Bare scalar cell value, e.g. ['A1' => 42] or ['A1' => '=SUM(B1:B5)'].
            // A leading "=" string is promoted to a formula; everything else is
            // a plain value. Object cells fall through to the explicit branch.
            if (!is_array($cellData)) {
                [$bare, $formula] = $this->promoteFormula($cellData);
                $cells[$address] = new Cell(
                    address: $address,
                    value: $formula !== null ? null : $this->coerceValue($bare, null, $address, $sheetName),
                    formula: $formula,
                );
                continue;
            }

            $format = isset($cellData['format']) && is_array($cellData['format'])
                ? CellFormat::fromArray($cellData['format'])
                : null;
            // `comment` takes either a string or an object. The string form was
            // accepted by the validator and dropped here, so a note written the
            // obvious way vanished with no error — the same silent-drop shape as
            // the discarded table above.
            $comment = match (true) {
                is_array($cellData['comment'] ?? null) => new CellComment(
                    text: (string) ($cellData['comment']['text'] ?? ''),
                    author: $cellData['comment']['author'] ?? null,
                    color: $cellData['comment']['color'] ?? null,
                ),
                is_string($cellData['comment'] ?? null) => new CellComment(text: $cellData['comment']),
                default => null,
            };

            $rawValue = $cellData['value'] ?? null;
            $cells[$address] = new Cell(
                address: $address,
                value: $this->coerceValue($rawValue, $format, $address, $sheetName),
                formula: $cellData['formula'] ?? null,
                format: $format,
                comment: $comment,
                cachedValue: $cellData['computedValue'] ?? null,
            );
        }
        return $cells;
    }

    private function normalizeMerges(array $list): array
    {
        $out = [];
        foreach ($list as $m) {
            if (isset($m['start'], $m['end'])) {
                $out[] = new MergedRegion((string) $m['start'], (string) $m['end']);
            }
        }
        return $out;
    }

    /**
     * The two ways a width can be stated, folded into one map.
     *
     * `columns[].width` widens the column at that POSITION; the sheet-level
     * `columnWidths` map keys the same columns by 0-based index. Both have been
     * documented in `skills/holy-sheet.schema.json` ("Same as columnWidths but
     * per-column") for as long as the field has existed, and only the map was
     * ever read — so a column width written the way the Column table documents
     * it emitted no `<cols>` element at all, with no error and no repair note
     * (holy-sheet#8).
     *
     * The MAP IS APPLIED LAST and wins. It is the mechanism that already worked,
     * so anyone who reached for it to work around this bug must not now find a
     * leftover `width` quietly overriding it.
     *
     * @param  array<string,mixed>  $sheet
     * @return array<int,float>
     */
    private function normalizeWidths(array $sheet): array
    {
        $out = [];

        foreach (($sheet['columns'] ?? []) as $colIdx => $columnDef) {
            if (! is_array($columnDef) || ! isset($columnDef['width'])) continue;
            $index = ColumnWidths::index($colIdx);
            $width = ColumnWidths::width($columnDef['width']);
            if ($index === null || $width === null) continue;
            $out[$index] = $width;
        }

        foreach ($this->normalizeColumnWidths($sheet['columnWidths'] ?? []) as $index => $width) {
            $out[$index] = $width;
        }

        // `<col>` elements are expected in ascending order, and merging two
        // sources means insertion order is no longer column order.
        ksort($out);

        return $out;
    }

    private function normalizeColumnWidths(array $widths): array
    {
        $out = [];
        foreach ($widths as $key => $px) {
            // An entry that is not a column index and a width is skipped. `(int)`
            // read the key "abc" as 0 and overwrote column A's width.
            $index = ColumnWidths::index($key);
            $width = ColumnWidths::width($px);
            if ($index === null || $width === null) continue;
            $out[$index] = $width;
        }
        return $out;
    }

    /** @param  array<string,mixed>|string  $columnDef */
    private function columnFormat(array|string $columnDef): ?CellFormat
    {
        if (is_string($columnDef)) return null;
        $type = $columnDef['type'] ?? 'auto';
        $decimals = isset($columnDef['decimals']) ? (int) $columnDef['decimals'] : null;
        $currency = $columnDef['currency'] ?? null;

        return match ($type) {
            'integer' => new CellFormat(displayFormat: 'number', decimals: 0),
            'number' => $decimals !== null ? new CellFormat(displayFormat: 'number', decimals: $decimals) : null,
            'percent' => new CellFormat(displayFormat: 'percentage', decimals: $decimals ?? 1),
            'currency' => new CellFormat(displayFormat: 'currency', decimals: $decimals ?? 2, currency: $currency),
            'date' => new CellFormat(displayFormat: 'date'),
            'datetime' => new CellFormat(displayFormat: 'datetime'),
            default => null,
        };
    }

    private function buildCell(string $address, mixed $value, ?CellFormat $columnFormat, ?string $sheetName = null): Cell
    {
        if (is_array($value) && !array_is_list($value)) {
            $cellFormat = isset($value['format']) && is_array($value['format'])
                ? CellFormat::fromArray($value['format'])
                : null;
            $merged = $columnFormat ? $columnFormat->mergeWith($cellFormat) : $cellFormat;
            $comment = isset($value['comment']) && is_array($value['comment'])
                ? new CellComment(
                    text: (string) ($value['comment']['text'] ?? ''),
                    author: $value['comment']['author'] ?? null,
                    color: $value['comment']['color'] ?? null,
                )
                : null;
            $rawValue = $value['value'] ?? null;
            return new Cell(
                address: $address,
                value: $this->coerceValue($rawValue, $merged, $address, $sheetName),
                formula: $value['formula'] ?? null,
                format: $merged,
                comment: $comment,
                cachedValue: $value['computedValue'] ?? null,
            );
        }

        [$value, $formula] = $this->promoteFormula($value);

        return new Cell(
            address: $address,
            value: $formula !== null ? null : $this->coerceValue($value, $columnFormat, $address, $sheetName),
            formula: $formula,
            format: $columnFormat,
        );
    }

    /**
     * A bare string cell value beginning with "=" (e.g. "=SUM(B2:B10)") is an
     * Excel formula, not literal text — promote it to a real formula cell.
     *
     * Only *bare* strings promote. An object cell ({"value": "=x"} or
     * {"formula": "x"}) is always taken as the caller's explicit intent, so
     * {"value": "=literal"} is the escape hatch for a genuine leading-"=" string,
     * and {"formula": "..."} means the caller already knows best.
     *
     * @return array{0: mixed, 1: string|null}  [value, formula]
     */
    private function promoteFormula(mixed $value): array
    {
        if (is_string($value) && strlen($value) > 1 && $value[0] === '=') {
            return [null, substr($value, 1)];
        }

        return [$value, null];
    }

    private function coerceValue(mixed $value, ?CellFormat $format, string $address = '?', ?string $sheetName = null): string|int|float|bool|null
    {
        if ($value === null) return null;

        $df = $format?->displayFormat;
        if ($df === 'date' || $df === 'datetime') {
            if ($value instanceof DateTimeInterface || (is_string($value) && trim($value) !== '')) {
                return DateConverter::toSerial($value, $df === 'datetime');
            }
        }

        if (is_string($value) && is_numeric($value)) {
            // See CsvBuilder: `+ 0` is PHP's numeric-string coercion; the dot
            // test clamped at PHP_INT_MAX and read "2e-3" as 0.
            return $value + 0;
        }

        if ($value instanceof DateTimeInterface) {
            return DateConverter::toSerial($value, true);
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        // REFUSE rather than cast. This used to read "not a scalar, so it must
        // be safe to stringify", and an ARRAY reaches it whenever a plain
        // `rows` form is nested one level too deep -- no object-cell syntax
        // required, which is exactly the shape an AI-generated edit produces.
        //
        // The cast had two outcomes and the quiet one was the dangerous one:
        // under a handler that promotes warnings (Laravel) an ErrorException
        // killed the whole normalize(), and with warnings suppressed the cell
        // was written as the literal string "Array" while the workbook
        // reported SUCCESS. A normalizer that rejects bad input is useful; one
        // that writes "Array" is not. holy-sheet#9, from MOIC production.
        //
        // Testing Stringable rather than "is not a scalar" also fixes the
        // sibling case in the same line: a non-Stringable object raised Error.
        //
        // The path is the cell's `Sheet!A1` location, not the `sheets[0].rows[1]`
        // schema path Validator uses. Deliberate, and the one place the two
        // conventions differ: this fault is always ONE CELL, and the A1
        // location is what both an operator and an agent can act on -- the
        // production report was that the error named no cell, not that it threw.
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            $where = $sheetName === null ? $address : "{$sheetName}!{$address}";

            throw SchemaException::fromErrors([[
                'path' => $where,
                'expected' => 'a string, number, bool, null, DateTimeInterface or Stringable',
                'got' => get_debug_type($value),
                'value' => $value,
                'hint' => is_array($value)
                    ? 'This cell holds an array. The usual cause is a `rows` entry nested one level too deep: `rows: [[["x"]]]` makes cell A1 an array, where `rows: [["x"]]` makes it the string "x". For a cell with formatting use the object form: {"value": "x", "format": {...}}.'
                    : 'Give the cell a scalar, or implement Stringable on the object so the conversion is yours rather than implicit.',
            ]]);
            // No custom message: `fromErrors()` takes only the error list, so a
            // second argument here would be silently DISCARDED -- PHP ignores
            // extra positional args to a userland function. `summarize()`
            // already renders "schema invalid at {path}: expected X, got Y",
            // which is the format every other schema error in this package
            // uses, so the house message is also the right one.
        }

        return $value;
    }

}
