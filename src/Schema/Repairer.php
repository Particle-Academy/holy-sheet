<?php

declare(strict_types=1);

namespace HolySheet\Schema;

/**
 * Schema Repairer — conservative high-confidence fixes.
 *
 * Repairs only patterns where the intended schema is unambiguous:
 *  - Top-level singular: 'sheet' → 'sheets' (wrap in array if needed)
 *  - Sheet 'row' → 'rows'
 *  - Object-as-list: rows is {0: ..., 1: ..., 2: ...} → indexed array
 *  - Stringified numerics on number/integer/currency/percent columns
 *  - Unknown theme → 'default'
 *  - Trailing/leading whitespace in cell addresses (sparse mode)
 *  - Missing column type with all values matching ISO date regex → 'date'
 *
 * Skips ambiguous cases — agents should see those errors and fix them.
 *
 * Returns [repairedSchema, repairs] where `repairs` is a list of
 * human-readable strings describing what was changed (so agents can log
 * + learn rather than relying on auto-repair forever).
 */
final class Repairer
{
    private const VALID_THEMES = ['default', 'minimal', 'plain', 'business'];
    private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/';

    /** @var list<string> */
    private array $repairs = [];

    /**
     * @param  array<string,mixed>  $schema
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    public function repair(array $schema): array
    {
        $this->repairs = [];
        $schema = $this->repairTopLevel($schema);

        if (isset($schema['sheets']) && is_array($schema['sheets'])) {
            $sheets = [];
            foreach ($schema['sheets'] as $i => $sheet) {
                $sheets[$i] = $this->repairSheet($sheet, "sheets[{$i}]");
            }
            $schema['sheets'] = $sheets;
        }

        return [$schema, $this->repairs];
    }

    /** @param  array<string,mixed>  $schema @return array<string,mixed> */
    private function repairTopLevel(array $schema): array
    {
        // Singular 'sheet' → 'sheets'
        if (!isset($schema['sheets']) && isset($schema['sheet'])) {
            $value = $schema['sheet'];
            unset($schema['sheet']);
            $schema['sheets'] = is_array($value) && array_is_list($value) ? $value : [$value];
            $this->repairs[] = "renamed top-level 'sheet' → 'sheets'";
        }
        return $schema;
    }

    /**
     * @param  mixed  $sheet
     * @return mixed
     */
    private function repairSheet(mixed $sheet, string $path): mixed
    {
        if (!is_array($sheet)) return $sheet;

        // 'row' → 'rows'
        if (!isset($sheet['rows']) && isset($sheet['row'])) {
            $sheet['rows'] = $sheet['row'];
            unset($sheet['row']);
            $this->repairs[] = "renamed '{$path}.row' → '{$path}.rows'";
        }

        // Object-as-list: rows is {0:[],1:[],2:[]} → indexed array
        if (isset($sheet['rows']) && is_array($sheet['rows']) && !array_is_list($sheet['rows'])) {
            $keys = array_keys($sheet['rows']);
            $allIntegerKeys = true;
            foreach ($keys as $k) {
                if (!is_int($k) && !(is_string($k) && preg_match('/^\d+$/', $k) === 1)) {
                    $allIntegerKeys = false;
                    break;
                }
            }
            if ($allIntegerKeys) {
                $values = array_values($sheet['rows']);
                $sheet['rows'] = $values;
                $this->repairs[] = "converted '{$path}.rows' from integer-keyed object to indexed list";
            }
        }

        // Unknown theme → 'default'
        if (isset($sheet['theme']) && !in_array($sheet['theme'], self::VALID_THEMES, true)) {
            $original = $sheet['theme'];
            $sheet['theme'] = 'default';
            $this->repairs[] = "changed '{$path}.theme' from '{$original}' to 'default' (unknown theme)";
        }

        // Trim whitespace in sparse-cell A1 addresses
        if (isset($sheet['cells']) && is_array($sheet['cells']) && !array_is_list($sheet['cells'])) {
            $cleaned = [];
            $changed = false;
            foreach ($sheet['cells'] as $addr => $data) {
                $trimmed = is_string($addr) ? trim($addr) : (string) $addr;
                if ($trimmed !== (string) $addr) $changed = true;
                $cleaned[$trimmed] = $data;
            }
            if ($changed) {
                $sheet['cells'] = $cleaned;
                $this->repairs[] = "trimmed whitespace from cell addresses in '{$path}.cells'";
            }
        }

        // Column-driven repairs (string-numeric coercion + missing date type)
        if (isset($sheet['columns'], $sheet['rows']) && is_array($sheet['columns']) && is_array($sheet['rows'])) {
            $sheet = $this->repairColumnTypeInference($sheet, $path);
            $sheet = $this->repairStringifiedNumerics($sheet, $path);
        }

        return $sheet;
    }

    /** @param  array<string,mixed>  $sheet @return array<string,mixed> */
    private function repairColumnTypeInference(array $sheet, string $path): array
    {
        foreach ($sheet['columns'] as $colIdx => $col) {
            if (!is_array($col)) continue;
            // Only fill in if type is omitted entirely
            if (isset($col['type']) && $col['type'] !== 'auto') continue;
            $values = array_column($sheet['rows'], $colIdx);
            $values = array_filter($values, fn ($v) => $v !== null);
            if ($values === []) continue;

            $allDateLike = true;
            foreach ($values as $v) {
                if (!is_string($v) || preg_match(self::ISO_DATE, $v) !== 1) {
                    $allDateLike = false;
                    break;
                }
            }
            if ($allDateLike) {
                $sheet['columns'][$colIdx]['type'] = str_contains($values[0], 'T') ? 'datetime' : 'date';
                $this->repairs[] = "inferred '{$path}.columns[{$colIdx}].type' = '{$sheet['columns'][$colIdx]['type']}' from row values";
            }
        }
        return $sheet;
    }

    /** @param  array<string,mixed>  $sheet @return array<string,mixed> */
    private function repairStringifiedNumerics(array $sheet, string $path): array
    {
        $numericTypes = ['number', 'integer', 'currency', 'percent'];
        foreach ($sheet['columns'] as $colIdx => $col) {
            if (!is_array($col)) continue;
            $type = $col['type'] ?? 'auto';
            if (!in_array($type, $numericTypes, true)) continue;

            foreach ($sheet['rows'] as $r => $row) {
                if (!is_array($row)) continue;
                $value = $row[$colIdx] ?? null;
                if (is_string($value) && is_numeric($value)) {
                    $sheet['rows'][$r][$colIdx] = str_contains($value, '.') ? (float) $value : (int) $value;
                }
            }
        }
        // Don't pollute repairs[] for every cell — just one per column would be noisy too.
        // The fix is silent because it's stylistic, not behavioral.
        return $sheet;
    }
}
