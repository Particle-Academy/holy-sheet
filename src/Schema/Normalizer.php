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

        if (isset($sheet['cells'])) {
            return new Sheet(
                name: $name,
                cells: $this->normalizeCellMap($sheet['cells']),
                mergedRegions: $this->normalizeMerges($sheet['mergedRegions'] ?? []),
                columnWidths: $this->normalizeColumnWidths($sheet['columnWidths'] ?? []),
                frozenRows: (int) ($sheet['frozenRows'] ?? 0),
                frozenCols: (int) ($sheet['frozenCols'] ?? 0),
            );
        }

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
            $format = isset($cellData['format']) && is_array($cellData['format'])
                ? CellFormat::fromArray($cellData['format'])
                : null;
            $comment = isset($cellData['comment']) && is_array($cellData['comment'])
                ? new CellComment(
                    text: (string) ($cellData['comment']['text'] ?? ''),
                    author: $cellData['comment']['author'] ?? null,
                    color: $cellData['comment']['color'] ?? null,
                )
                : null;

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

        return new Cell(
            address: $address,
            value: $this->coerceValue($value, $columnFormat),
            format: $columnFormat,
        );
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
            return str_contains($value, '.') ? (float) $value : (int) $value;
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
