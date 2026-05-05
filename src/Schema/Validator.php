<?php

declare(strict_types=1);

namespace HolySheet\Schema;

use HolySheet\Exceptions\SchemaException;

/**
 * Schema validator for Holy Sheet input.
 *
 * Validates the agent-facing schema BEFORE any xlsx writing happens.
 * Returns a list of structured errors (each with `path`, `expected`,
 * `got`, `value`, `hint`) so an agent can recover without parsing
 * a stack trace.
 *
 * The validator is intentionally hand-rolled — Holy Sheet has zero
 * runtime dependencies, and a JSON-Schema validator would add 3-5
 * transitive packages. The shape is small enough that explicit
 * validation is clearer than declarative.
 */
final class Validator
{
    /**
     * Validate a workbook schema.
     *
     * @param  array<string,mixed>  $schema
     * @return list<array{path:string,expected:string,got:string,value:mixed,hint:string}>
     */
    public function validate(array $schema): array
    {
        $errors = [];

        if (!isset($schema['sheets']) || !is_array($schema['sheets'])) {
            $errors[] = $this->error('sheets', 'array', $this->typeOf($schema['sheets'] ?? null), $schema['sheets'] ?? null,
                'Top-level "sheets" must be an array of sheet definitions.');
            return $errors;
        }

        if (count($schema['sheets']) === 0) {
            $errors[] = $this->error('sheets', 'non-empty array', 'empty array', [],
                'A workbook must contain at least one sheet.');
            return $errors;
        }

        foreach ($schema['sheets'] as $i => $sheet) {
            $errors = array_merge($errors, $this->validateSheet($sheet, "sheets[{$i}]"));
        }

        return $errors;
    }

    /**
     * Throw if validation fails.
     *
     * @param  array<string,mixed>  $schema
     */
    public function assert(array $schema): void
    {
        $errors = $this->validate($schema);
        if ($errors !== []) {
            throw SchemaException::fromErrors($errors);
        }
    }

    /**
     * @param  mixed  $sheet
     * @return list<array{path:string,expected:string,got:string,value:mixed,hint:string}>
     */
    private function validateSheet(mixed $sheet, string $path): array
    {
        $errors = [];

        if (!is_array($sheet)) {
            return [$this->error($path, 'object', $this->typeOf($sheet), $sheet,
                'Each sheet must be an object with at least a "name" key.')];
        }

        if (!isset($sheet['name']) || !is_string($sheet['name']) || trim($sheet['name']) === '') {
            $errors[] = $this->error("{$path}.name", 'non-empty string', $this->typeOf($sheet['name'] ?? null), $sheet['name'] ?? null,
                'Sheet name is required and visible in Excel\'s tab strip.');
        }

        // Either columns + rows OR cells (sparse map) OR both must be present
        $hasColumnsRows = isset($sheet['rows']) || isset($sheet['columns']);
        $hasCells = isset($sheet['cells']);

        if (!$hasColumnsRows && !$hasCells) {
            $errors[] = $this->error($path, 'object with rows OR cells', 'object without either', $sheet,
                'A sheet needs either {columns, rows} (row-oriented) or {cells} (sparse A1-keyed) data.');
        }

        if (isset($sheet['columns'])) {
            if (!is_array($sheet['columns'])) {
                $errors[] = $this->error("{$path}.columns", 'array', $this->typeOf($sheet['columns']), $sheet['columns'],
                    'Columns must be an array of column definitions.');
            } else {
                foreach ($sheet['columns'] as $j => $col) {
                    $errors = array_merge($errors, $this->validateColumn($col, "{$path}.columns[{$j}]"));
                }
            }
        }

        if (isset($sheet['rows'])) {
            if (!is_array($sheet['rows'])) {
                $errors[] = $this->error("{$path}.rows", 'array', $this->typeOf($sheet['rows']), $sheet['rows'],
                    'Rows must be an array of arrays.');
            } else {
                foreach ($sheet['rows'] as $j => $row) {
                    if (!is_array($row)) {
                        $errors[] = $this->error("{$path}.rows[{$j}]", 'array', $this->typeOf($row), $row,
                            'Each row is an array of cell values, in column order.');
                    }
                }
            }
        }

        if (isset($sheet['cells'])) {
            if (!is_array($sheet['cells']) || array_is_list($sheet['cells'])) {
                $errors[] = $this->error("{$path}.cells", 'object keyed by A1 address', $this->typeOf($sheet['cells']), $sheet['cells'],
                    'Cells must be an object/map keyed by A1 references like "A1", "B2".');
            }
        }

        if (isset($sheet['theme']) && !in_array($sheet['theme'], ['default', 'minimal', 'plain', 'business'], true)) {
            $errors[] = $this->error("{$path}.theme", 'one of: default, minimal, plain, business', 'unknown', $sheet['theme'],
                'Pick a built-in theme or omit for default.');
        }

        return $errors;
    }

    /**
     * @param  mixed  $col
     * @return list<array{path:string,expected:string,got:string,value:mixed,hint:string}>
     */
    private function validateColumn(mixed $col, string $path): array
    {
        if (!is_array($col)) {
            return [$this->error($path, 'object', $this->typeOf($col), $col,
                'Each column is an object with at least a "header" key.')];
        }

        $errors = [];
        if (!isset($col['header']) || !is_string($col['header'])) {
            $errors[] = $this->error("{$path}.header", 'string', $this->typeOf($col['header'] ?? null), $col['header'] ?? null,
                'Column header is the visible label in row 1.');
        }

        $allowedTypes = ['auto', 'string', 'number', 'integer', 'boolean', 'date', 'datetime', 'currency', 'percent', 'formula'];
        if (isset($col['type']) && !in_array($col['type'], $allowedTypes, true)) {
            $errors[] = $this->error("{$path}.type", 'one of: '.implode(', ', $allowedTypes), 'unknown', $col['type'],
                'Pick a supported type or omit for "auto" (inferred per cell).');
        }

        return $errors;
    }

    /** @param  mixed  $value */
    private function error(string $path, string $expected, string $got, mixed $value, string $hint): array
    {
        return [
            'path' => $path,
            'expected' => $expected,
            'got' => $got,
            'value' => $value,
            'hint' => $hint,
        ];
    }

    private function typeOf(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return array_is_list($value) ? 'array' : 'object';
        }
        return get_debug_type($value);
    }
}
