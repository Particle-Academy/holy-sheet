<?php

declare(strict_types=1);

namespace HolySheet\Schema;

use DateTimeInterface;
use HolySheet\Workbook\Cell;
use HolySheet\Workbook\CellAddress;
use HolySheet\Workbook\CellComment;
use HolySheet\Workbook\CellFormat;
use HolySheet\Workbook\MergedRegion;
use HolySheet\Workbook\Sheet;
use HolySheet\Workbook\Workbook;
use HolySheet\Writer\Format\DateConverter;

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
                $cells[$address] = $this->buildCell($address, $value, $merged);
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
            foreach ($this->normalizeCellMap($sheet['cells']) as $address => $cell) {
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
            columnWidths: $this->normalizeColumnWidths($sheet['columnWidths'] ?? []),
            frozenRows: (int) ($sheet['frozenRows'] ?? 0),
            frozenCols: (int) ($sheet['frozenCols'] ?? 0),
        );
    }

    /** @param  array<string,array<string,mixed>>  $map */
    private function normalizeCellMap(array $map): array
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
                    value: $formula !== null ? null : $this->coerceValue($bare, null),
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
                value: $this->coerceValue($rawValue, $format),
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

    private function normalizeColumnWidths(array $widths): array
    {
        $out = [];
        foreach ($widths as $key => $px) {
            $out[(int) $key] = (float) $px;
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

    private function buildCell(string $address, mixed $value, ?CellFormat $columnFormat): Cell
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
                value: $this->coerceValue($rawValue, $merged),
                formula: $value['formula'] ?? null,
                format: $merged,
                comment: $comment,
                cachedValue: $value['computedValue'] ?? null,
            );
        }

        [$value, $formula] = $this->promoteFormula($value);

        return new Cell(
            address: $address,
            value: $formula !== null ? null : $this->coerceValue($value, $columnFormat),
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

    private function coerceValue(mixed $value, ?CellFormat $format): string|int|float|bool|null
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

        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            return (string) $value;
        }

        return $value;
    }

}
