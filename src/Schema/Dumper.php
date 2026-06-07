<?php

declare(strict_types=1);

namespace HolySheet\Schema;

/**
 * Serializes a Holy Sheet schema to JSON for agent read-tools.
 *
 * Mirrors `describe()` semantically: describe() returns SHAPE (sheet names,
 * dimensions, column types, sample addresses); dumpJson() returns CONTENT
 * (every value + formula) so an agent can make targeted cell edits or fix
 * existing formulas.
 *
 * A pure transform over the schema array — no normalization, no file IO,
 * zero dependencies.
 */
final class Dumper
{
    /**
     * @param  array<string,mixed>  $schema
     */
    public function dump(array $schema, ?DumpOptions $opts = null): string
    {
        $opts ??= new DumpOptions();
        $prepared = $this->prepare($schema, $opts);

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($opts->prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($prepared, $flags);
        if ($json === false) {
            return json_encode(['_error' => 'encode_failed', 'message' => json_last_error_msg()]) ?: '{}';
        }

        if ($opts->maxBytes > 0 && strlen($json) > $opts->maxBytes) {
            return $this->overflow($schema, strlen($json), $opts, $flags);
        }

        return $json;
    }

    /**
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    private function prepare(array $schema, DumpOptions $opts): array
    {
        if (!isset($schema['sheets']) || !is_array($schema['sheets'])) {
            return $schema;
        }

        $sheets = [];
        foreach ($schema['sheets'] as $sheet) {
            if (!is_array($sheet)) {
                $sheets[] = $sheet;
                continue;
            }
            $prepared = $this->prepareSheet($sheet, $opts);
            if ($opts->compactEmpty && $this->sheetIsEmpty($prepared)) {
                continue;
            }
            $sheets[] = $prepared;
        }

        $out = $schema;
        $out['sheets'] = array_values($sheets);

        return $out;
    }

    /**
     * @param  array<string,mixed>  $sheet
     * @return array<string,mixed>
     */
    private function prepareSheet(array $sheet, DumpOptions $opts): array
    {
        if (!$opts->includeFormats) {
            unset($sheet['theme']);
        }

        if (isset($sheet['cells']) && is_array($sheet['cells'])) {
            $cells = [];
            foreach ($sheet['cells'] as $addr => $cell) {
                $cell = $this->prepareCell($cell, $opts);
                if ($opts->compactEmpty && $this->cellIsEmpty($cell)) {
                    continue;
                }
                $cells[$addr] = $cell;
            }
            $sheet['cells'] = $cells;
        }

        if (!$opts->includeFormats && isset($sheet['rows']) && is_array($sheet['rows'])) {
            $sheet['rows'] = array_map(
                fn ($row) => is_array($row)
                    ? array_map(fn ($v) => $this->prepareCell($v, $opts), $row)
                    : $row,
                $sheet['rows'],
            );
        }

        return $sheet;
    }

    /**
     * Strip cell-level styling when formats are excluded. Scalars pass through.
     */
    private function prepareCell(mixed $cell, DumpOptions $opts): mixed
    {
        if (!is_array($cell) || array_is_list($cell)) {
            return $cell;
        }
        if (!$opts->includeFormats) {
            unset($cell['format']);
        }

        return $cell;
    }

    private function cellIsEmpty(mixed $cell): bool
    {
        if ($cell === null || $cell === '') {
            return true;
        }
        if (is_array($cell) && !array_is_list($cell)) {
            $hasValue = isset($cell['value']) && $cell['value'] !== null && $cell['value'] !== '';
            $hasFormula = isset($cell['formula']) && $cell['formula'] !== null && $cell['formula'] !== '';

            return ! $hasValue && ! $hasFormula;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $sheet
     */
    private function sheetIsEmpty(array $sheet): bool
    {
        $hasCells = isset($sheet['cells']) && is_array($sheet['cells']) && $sheet['cells'] !== [];
        $hasRows = isset($sheet['rows']) && is_array($sheet['rows']) && $sheet['rows'] !== [];

        return ! $hasCells && ! $hasRows;
    }

    /**
     * Over the byte ceiling: return a compact shape index instead of the full
     * dump, so the agent can narrow its read rather than blow the token budget.
     *
     * @param  array<string,mixed>  $schema
     */
    private function overflow(array $schema, int $bytes, DumpOptions $opts, int $flags): string
    {
        $sheets = [];
        foreach (($schema['sheets'] ?? []) as $sheet) {
            if (!is_array($sheet)) {
                continue;
            }
            $sheets[] = [
                'name' => $sheet['name'] ?? null,
                'rows' => isset($sheet['rows']) && is_array($sheet['rows']) ? count($sheet['rows']) : 0,
                'cells' => isset($sheet['cells']) && is_array($sheet['cells']) ? count($sheet['cells']) : 0,
            ];
        }

        $out = json_encode([
            '_truncated' => true,
            '_bytes' => $bytes,
            '_maxBytes' => $opts->maxBytes,
            '_note' => 'Schema dump exceeded maxBytes. Raise DumpOptions::maxBytes (or 0 for unbounded), or read sheets individually. Shape index below.',
            'sheets' => $sheets,
        ], $flags);

        return $out !== false ? $out : '{"_truncated":true}';
    }
}
