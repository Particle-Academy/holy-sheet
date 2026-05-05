<?php

declare(strict_types=1);

namespace HolySheet\Schema;

use HolySheet\Workbook\Cell;
use HolySheet\Workbook\Sheet;
use HolySheet\Workbook\Workbook;

/**
 * Normalizer
 *
 * Converts an already-validated schema array into the canonical
 * Workbook value object that the xlsx writer consumes. Handles both
 * agent-ergonomic shape (rows + columns) and fancy-sheets-passthrough
 * shape (sparse cells map).
 *
 * Validation is the caller's responsibility — Normalizer assumes
 * the schema has already passed Validator::assert().
 */
final class Normalizer
{
    /**
     * @param  array<string,mixed>  $schema
     */
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

        // Sparse cells map (fancy-sheets shape) takes precedence when both
        // are present — assume the caller knows what they're doing.
        if (isset($sheet['cells'])) {
            return new Sheet(
                name: $name,
                cells: $this->normalizeCellMap($sheet['cells']),
                mergedRegions: $sheet['mergedRegions'] ?? [],
                columnWidths: $sheet['columnWidths'] ?? [],
                frozenRows: (int) ($sheet['frozenRows'] ?? 0),
                frozenCols: (int) ($sheet['frozenCols'] ?? 0),
            );
        }

        $cells = [];

        $columns = $sheet['columns'] ?? [];
        $rows = $sheet['rows'] ?? [];

        // Header row (always row 1 when columns are declared)
        $rowOffset = 0;
        if ($columns !== []) {
            foreach ($columns as $col => $columnDef) {
                $address = $this->columnLetter($col).'1';
                $header = is_array($columnDef) ? ($columnDef['header'] ?? '') : (string) $columnDef;
                $cells[$address] = new Cell($address, (string) $header);
            }
            $rowOffset = 1;
        }

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $address = $this->columnLetter($c).($r + 1 + $rowOffset);
                $cells[$address] = $this->buildCell($address, $value);
            }
        }

        return new Sheet(name: $name, cells: $cells);
    }

    /**
     * @param  array<string,array<string,mixed>>  $map
     * @return array<string,Cell>
     */
    private function normalizeCellMap(array $map): array
    {
        $cells = [];
        foreach ($map as $address => $cellData) {
            $cells[$address] = new Cell(
                address: (string) $address,
                value: $cellData['value'] ?? null,
                formula: $cellData['formula'] ?? null,
            );
        }
        return $cells;
    }

    private function buildCell(string $address, mixed $value): Cell
    {
        // Treat associative arrays {value: ..., formula: ...} as cell objects.
        if (is_array($value) && !array_is_list($value)) {
            return new Cell($address, $value['value'] ?? null, $value['formula'] ?? null);
        }
        // Coerce numeric-string PHP int/float to native types so xlsx writer picks the right cell type.
        if (is_string($value) && is_numeric($value)) {
            $value = str_contains($value, '.') ? (float) $value : (int) $value;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
            $value = (string) $value;
        }
        return new Cell($address, $value);
    }

    /** Convert 0-based column index to Excel-style letter ("A", "Z", "AA", ...). */
    private function columnLetter(int $index): string
    {
        $letters = '';
        $n = $index;
        do {
            $letters = chr(65 + ($n % 26)).$letters;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);
        return $letters;
    }
}
