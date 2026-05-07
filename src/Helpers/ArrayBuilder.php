<?php

declare(strict_types=1);

namespace HolySheet\Helpers;

use HolySheet\Schema\Inference;

/**
 * ArrayBuilder
 *
 * Converts a flat array of rows + (optional) headers into a Holy Sheet
 * schema with inferred column types. The output schema is the same
 * shape `Agent::write()` consumes — no special handoff, no builder
 * objects to remember.
 *
 *   $schema = ArrayBuilder::build($rows, ['Region', 'Revenue']);
 *   Agent::write($schema, $path);
 *
 * Headers can be omitted; in that case the first row is treated as the
 * header. If the first row's first cell is numeric, that's a strong
 * signal that headers were omitted by mistake — but ArrayBuilder doesn't
 * second-guess: pass headers explicitly when you need certainty.
 */
final class ArrayBuilder
{
    /**
     * @param  list<list<mixed>>  $rows  array of arrays — one inner array per row
     * @param  list<string>|null  $headers  optional header row; if null, $rows[0] is used as headers
     * @param  string  $sheetName  default 'Sheet 1'
     * @param  array<string,mixed>  $options  passthrough: theme, currency, totals, frozenRows, etc.
     * @return array<string,mixed>  Holy Sheet schema
     */
    public static function build(
        array $rows,
        ?array $headers = null,
        string $sheetName = 'Sheet 1',
        array $options = [],
    ): array {
        if ($headers === null) {
            if ($rows === []) {
                return self::wrap([], [], $sheetName, $options);
            }
            $headers = array_map(fn ($v) => (string) $v, $rows[0]);
            $rows = array_slice($rows, 1);
        }

        $columns = self::inferColumns($rows, $headers, $options);
        return self::wrap($columns, $rows, $sheetName, $options);
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<string>  $headers
     * @return list<array<string,mixed>>
     */
    private static function inferColumns(array $rows, array $headers, array $options): array
    {
        $columns = [];
        foreach ($headers as $i => $headerName) {
            $columnValues = [];
            foreach ($rows as $row) {
                $columnValues[] = $row[$i] ?? null;
            }
            $columns[] = Inference::detect($columnValues, (string) $headerName, $options);
        }
        return $columns;
    }

    private static function wrap(array $columns, array $rows, string $sheetName, array $options): array
    {
        $sheet = [
            'name' => $sheetName,
            'columns' => $columns,
            'rows' => $rows,
        ];

        if (isset($options['theme'])) {
            $sheet['theme'] = $options['theme'];
        }
        if (isset($options['totals']) && is_array($options['totals'])) {
            $sheet['totals'] = $options['totals'];
        }
        if (isset($options['frozenRows'])) {
            $sheet['frozenRows'] = (int) $options['frozenRows'];
        }
        if (isset($options['frozenCols'])) {
            $sheet['frozenCols'] = (int) $options['frozenCols'];
        }

        return ['sheets' => [$sheet]];
    }
}
