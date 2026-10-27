<?php

declare(strict_types=1);

namespace HolySheet\Schema;

use HolySheet\Exceptions\SchemaException;
use HolySheet\Exchange\Insert;
use HolySheet\Workbook\CellAddress;
use HolySheet\Workbook\ChartRange;

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

        $visible = 0;
        foreach ($schema['sheets'] as $i => $sheet) {
            $errors = array_merge($errors, $this->validateSheet($sheet, "sheets[{$i}]"));
            if (!is_array($sheet) || !in_array($sheet['state'] ?? 'visible', ['hidden', 'veryHidden'], true)) {
                $visible++;
            }
        }
        if ($visible === 0) {
            $errors[] = $this->error('sheets', 'at least one visible sheet', 'every sheet hidden', null,
                'Excel refuses a workbook with no visible sheet. Leave `state` off (visible) on at least one.');
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
     * Validate + apply conservative repairs.
     *
     * Returns the (possibly modified) schema, the original error list,
     * and a list of human-readable repair descriptions. Multiple repairs
     * compose — caller can re-validate the returned schema if needed.
     *
     * @param  array<string,mixed>  $schema
     * @return array{schema:array<string,mixed>,errors:list<array<string,mixed>>,repairs:list<string>}
     */
    public function validateAndRepair(array $schema): array
    {
        $repairer = new Repairer();
        [$repairedSchema, $repairs] = $repairer->repair($schema);
        $errors = $this->validate($repairedSchema);
        $out = [
            'schema' => $repairedSchema,
            'errors' => $errors,
            'repairs' => $repairs,
        ];

        // Expand every sheet's `insert` list into cells, once it validates: the
        // returned schema is then exactly what will be written, and each
        // expansion's report (what portable content could not carry) is
        // returned alongside it rather than lost.
        $insertErrors = array_filter($errors, static fn (array $e): bool => str_contains($e['path'], '.insert'));
        if ($insertErrors === [] && is_array($repairedSchema['sheets'] ?? null)) {
            $reports = [];
            foreach ($repairedSchema['sheets'] as $i => $sheet) {
                if (!is_array($sheet) || !array_key_exists('insert', $sheet)) {
                    continue;
                }
                [$expanded, $sheetReports] = Insert::expand($sheet);
                $out['schema']['sheets'][$i] = $expanded;
                foreach ($sheetReports as $j => $report) {
                    $reports[] = ['sheet' => $i, 'insert' => $j, 'report' => $report];
                }
                $out['repairs'][] = "sheets[{$i}].insert: expanded ".count($sheetReports).' portable placement(s) into cells';
            }
            if ($reports !== []) {
                $out['inserts'] = $reports;
            }
        }

        return $out;
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
        $hasCells = isset($sheet['cells']) || isset($sheet['insert']);

        if (array_key_exists('insert', $sheet)) {
            foreach (Insert::problems($sheet['insert']) as [$sub, $expected, $got, $value, $hint]) {
                $errors[] = $this->error("{$path}.insert{$sub}", $expected, $got, $value, $hint);
            }
        }

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
                        continue;
                    }

                    // A LIST array as a cell value is the holy-sheet#9 shape: a
                    // row nested one level too deep. It must be caught HERE and
                    // not left to the writer, because `validate()` returning no
                    // errors for a schema `normalize()` throws on tells a caller
                    // the input is fine and moves the failure to where it is
                    // hardest to attribute. An agent that validates before
                    // writing — which is the whole point of Agent::validate() —
                    // was being told to go ahead.
                    //
                    // The test is `array_is_list`, NOT `is_array`: the object
                    // cell form {"value": …, "format": {…}} is an ASSOCIATIVE
                    // array and has always been legal. Rejecting any array here
                    // would trade this bug for a far worse one — every formatted
                    // cell in every workbook.
                    foreach ($row as $k => $cell) {
                        if (is_array($cell) && array_is_list($cell)) {
                            $errors[] = $this->error("{$path}.rows[{$j}][{$k}]", 'a cell value or a cell object', 'array', $cell,
                                'This cell is a list, which usually means the row is nested one level too deep: `rows: [[["x"]]]` makes cell A1 a list, where `rows: [["x"]]` makes it "x". For a cell with formatting use the object form: {"value": "x", "format": {...}}.');
                        }
                    }
                }
            }
        }

        if (isset($sheet['cells'])) {
            // `[]` is an empty map too: it is how describe() reports a sheet with
            // no cells, so rejecting it broke describe() -> write().
            if (!is_array($sheet['cells']) || ($sheet['cells'] !== [] && array_is_list($sheet['cells']))) {
                $errors[] = $this->error("{$path}.cells", 'object keyed by A1 address', $this->typeOf($sheet['cells']), $sheet['cells'],
                    'Cells must be an object/map keyed by A1 references like "A1", "B2".');
            }
        }

        if (isset($sheet['theme']) && !in_array($sheet['theme'], ['default', 'minimal', 'plain', 'business'], true)) {
            $errors[] = $this->error("{$path}.theme", 'one of: default, minimal, plain, business', 'unknown', $sheet['theme'],
                'Pick a built-in theme or omit for default.');
        }

        if (isset($sheet['columnWidths'])) {
            if (!is_array($sheet['columnWidths'])) {
                $errors[] = $this->error("{$path}.columnWidths", 'object keyed by 0-based column index', $this->typeOf($sheet['columnWidths']), $sheet['columnWidths'],
                    'Column widths map a 0-based column index to pixels: {"0": 120, "1": 80}.');
            } else {
                foreach ($sheet['columnWidths'] as $key => $px) {
                    if (ColumnWidths::index($key) === null) {
                        $errors[] = $this->error("{$path}.columnWidths.{$key}", 'a 0-based column index from 0 to '.ColumnWidths::MAX_INDEX, $this->typeOf($key), $key,
                            'Keys are 0-based column indexes: "0" is column A, "1" is column B. Use the index, not the letter.');
                    } elseif (ColumnWidths::width($px) === null) {
                        $errors[] = $this->error("{$path}.columnWidths.{$key}", 'a non-negative number of pixels', $this->typeOf($px), $px,
                            'A width is a number of pixels, like 120.');
                    }
                }
            }
        }

        if (isset($sheet['rowHeights'])) {
            if (!is_array($sheet['rowHeights'])) {
                $errors[] = $this->error("{$path}.rowHeights", 'object keyed by 1-based row number', $this->typeOf($sheet['rowHeights']), $sheet['rowHeights'],
                    'Row heights map a row NUMBER to pixels: {"1": 32, "4": 28}.');
            } else {
                foreach ($sheet['rowHeights'] as $row => $px) {
                    if (preg_match('/^[1-9]\d{0,6}$/', (string) $row) !== 1) {
                        $errors[] = $this->error("{$path}.rowHeights.{$row}", 'a 1-based row number', $this->typeOf($row), $row,
                            'Keys are row numbers as Excel shows them: "1" is the first row.');
                    } elseif (!is_numeric($px) || (float) $px < 0) {
                        $errors[] = $this->error("{$path}.rowHeights.{$row}", 'a non-negative number of pixels', $this->typeOf($px), $px,
                            'A height is a number of pixels, like 28 (20 is a default row).');
                    }
                }
            }
        }

        if (array_key_exists('state', $sheet) && !in_array($sheet['state'], ['visible', 'hidden', 'veryHidden'], true)) {
            $errors[] = $this->error("{$path}.state", 'one of: visible, hidden, veryHidden', $this->typeOf($sheet['state']), $sheet['state'],
                'A hidden sheet is not shown in the tab strip; veryHidden cannot be unhidden from Excel\'s UI. Omit for visible.');
        }
        foreach (['hiddenRows' => [1, 1048576, 'a 1-based row number', 'Row numbers as Excel shows them: 1 is the first row.'],
            'hiddenColumns' => [0, ColumnWidths::MAX_INDEX, 'a 0-based column index', 'Column indexes as columnWidths uses them: 0 is column A.']] as $key => [$min, $max, $expected, $hint]) {
            if (!array_key_exists($key, $sheet)) {
                continue;
            }
            if (!is_array($sheet[$key]) || !array_is_list($sheet[$key])) {
                $errors[] = $this->error("{$path}.{$key}", 'array of '.$expected.'s', $this->typeOf($sheet[$key]), $sheet[$key], $hint);
                continue;
            }
            foreach ($sheet[$key] as $k => $v) {
                if (!is_int($v) || $v < $min || $v > $max) {
                    $errors[] = $this->error("{$path}.{$key}[{$k}]", $expected, $this->typeOf($v), $v, $hint);
                }
            }
        }
        if (isset($sheet['cells']) && is_array($sheet['cells'])) {
            foreach ($sheet['cells'] as $address => $cell) {
                if (!is_array($cell) || !is_array($cell['format'] ?? null) || !array_key_exists('formatCode', $cell['format'])) {
                    continue;
                }
                $code = $cell['format']['formatCode'];
                if (!is_string($code) || $code === '' || strlen($code) > 255) {
                    $errors[] = $this->error("{$path}.cells.{$address}.format.formatCode", 'a non-empty number-format code of at most 255 bytes', $this->typeOf($code), $code,
                        'A raw Excel number-format code, as describe() reads it from a file. Prefer displayFormat for anything it covers.');
                }
            }
        }

        if (isset($sheet['charts'])) {
            $errors = array_merge($errors, $this->validateCharts($sheet['charts'], $path, (string) ($sheet['name'] ?? '')));
        }

        if (isset($sheet['print'])) {
            $print = $sheet['print'];
            if (!is_array($print)) {
                $errors[] = $this->error("{$path}.print", 'object {orientation?, fitToWidth?, footer?}', $this->typeOf($print), $print,
                    'Print setup: {"orientation": "landscape", "fitToWidth": true, "footer": {"right": "Page {page} of {pages}"}}.');
            } else {
                if (isset($print['orientation']) && !in_array($print['orientation'], ['portrait', 'landscape'], true)) {
                    $errors[] = $this->error("{$path}.print.orientation", 'one of: portrait, landscape', $this->typeOf($print['orientation']), $print['orientation'],
                        'Use landscape for a sheet wider than it is tall.');
                }
                if (isset($print['fitToWidth']) && !is_bool($print['fitToWidth'])) {
                    $errors[] = $this->error("{$path}.print.fitToWidth", 'boolean', $this->typeOf($print['fitToWidth']), $print['fitToWidth'],
                        'true scales the sheet to one page wide when printed.');
                }
                if (isset($print['fitToPage']) && !is_bool($print['fitToPage'])) {
                    $errors[] = $this->error("{$path}.print.fitToPage", 'boolean', $this->typeOf($print['fitToPage']), $print['fitToPage'],
                        'true scales the sheet onto ONE page, wide and tall: a one-page summary with a chart prints whole.');
                }
                if (isset($print['footer'])) {
                    if (!is_array($print['footer'])) {
                        $errors[] = $this->error("{$path}.print.footer", 'object {left?, center?, right?}', $this->typeOf($print['footer']), $print['footer'],
                            'A footer has up to three slots of text.');
                    } else {
                        foreach (['left', 'center', 'right'] as $slot) {
                            if (isset($print['footer'][$slot]) && !is_string($print['footer'][$slot])) {
                                $errors[] = $this->error("{$path}.print.footer.{$slot}", 'string', $this->typeOf($print['footer'][$slot]), $print['footer'][$slot],
                                    'Footer text; {page} and {pages} print the page number and count.');
                            }
                        }
                    }
                }
            }
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

        // Same rule as a `columnWidths` value, because it is the same field by
        // another route. Until holy-sheet#8 the writer ignored `width` entirely
        // and nothing said so; now that it is honoured, a value that CANNOT be
        // honoured gets a path instead of silence.
        if (isset($col['width']) && ColumnWidths::width($col['width']) === null) {
            $errors[] = $this->error("{$path}.width", 'a non-negative number of pixels', $this->typeOf($col['width']), $col['width'],
                'A column width is a number of pixels, like 120. The sheet-level columnWidths map takes precedence over it.');
        }

        return $errors;
    }

    /** @param  mixed  $value */
    /** @return list<array{path:string,expected:string,got:string,value:mixed,hint:string}> */
    private function validateCharts(mixed $charts, string $path, string $sheetName): array
    {
        if (!is_array($charts) || ($charts !== [] && !array_is_list($charts))) {
            return [$this->error("{$path}.charts", 'a list of charts', $this->typeOf($charts), $charts,
                'charts is a list: [{"type": "column", "categories": "A5:A7", "series": [{"name": "Revenue", "values": "B5:B7"}]}].')];
        }
        $errors = [];
        foreach ($charts as $i => $chart) {
            $at = "{$path}.charts[{$i}]";
            if (!is_array($chart)) {
                $errors[] = $this->error($at, 'object', $this->typeOf($chart), $chart, 'A chart is an object with a type and series.');
                continue;
            }
            if (!in_array($chart['type'] ?? null, Normalizer::CHART_TYPES, true)) {
                $errors[] = $this->error("{$at}.type", 'one of: '.implode(', ', Normalizer::CHART_TYPES), $this->typeOf($chart['type'] ?? null), $chart['type'] ?? null,
                    'column compares values across categories; bar does the same horizontally (long labels); line shows change over time; pie shows parts of one whole.');
            }
            $series = $chart['series'] ?? null;
            if (!is_array($series) || $series === [] || !array_is_list($series)) {
                $errors[] = $this->error("{$at}.series", 'a non-empty list of series', $this->typeOf($series), $series,
                    'Each series names the range it plots: [{"name": "Revenue", "values": "B5:B7"}].');
            } else {
                foreach ($series as $j => $s) {
                    if (!is_array($s) || ChartRange::parse($s['values'] ?? null, $sheetName) === null) {
                        $errors[] = $this->error("{$at}.series[{$j}].values", 'a cell range like "B5:B7"', $this->typeOf(is_array($s) ? ($s['values'] ?? null) : $s), is_array($s) ? ($s['values'] ?? null) : $s,
                            'A range of cells: "B5:B7" on this sheet, or "\'Other sheet\'!B5:B7".');
                    } elseif (isset($s['color']) && (!is_string($s['color']) || preg_match('/^#[0-9A-Fa-f]{6}$/', $s['color']) !== 1)) {
                        $errors[] = $this->error("{$at}.series[{$j}].color", '#RRGGBB', $this->typeOf($s['color']), $s['color'], 'A hex colour, like "#1F3A5F".');
                    }
                    if (is_array($s) && isset($s['points'])) {
                        $errors = array_merge($errors, $this->validatePoints($s['points'], "{$at}.series[{$j}].points", ChartRange::parse($s['values'] ?? null, $sheetName)));
                    }
                }
            }
            if (isset($chart['categories']) && ChartRange::parse($chart['categories'], $sheetName) === null) {
                $errors[] = $this->error("{$at}.categories", 'a cell range like "A5:A7"', $this->typeOf($chart['categories']), $chart['categories'],
                    'The labels along the axis, as a range: "A5:A7".');
            }
            if (isset($chart['position'])) {
                $p = $chart['position'];
                $a = is_array($p) && is_string($p['from'] ?? null) ? CellAddress::parse($p['from']) : null;
                $b = is_array($p) && is_string($p['to'] ?? null) ? CellAddress::parse($p['to']) : null;
                if ($a === null || $b === null || $b[0] <= $a[0] || $b[1] <= $a[1]) {
                    $errors[] = $this->error("{$at}.position", '{"from": top-left cell, "to": bottom-right cell}', $this->typeOf($p), $p,
                        'Two cells, the second below and to the right of the first: {"from": "E2", "to": "L18"} is 7 columns by 16 rows.');
                }
            }
            foreach (['title', 'format'] as $key) {
                if (isset($chart[$key]) && !is_string($chart[$key])) {
                    $errors[] = $this->error("{$at}.{$key}", 'string', $this->typeOf($chart[$key]), $chart[$key],
                        $key === 'format' ? 'An Excel number format for the axis and labels, like "$#,##0" or "0.0%".' : 'The chart title.');
                }
            }
            foreach (['dataLabels', 'legend'] as $key) {
                if (isset($chart[$key]) && !is_bool($chart[$key])) {
                    $errors[] = $this->error("{$at}.{$key}", 'boolean', $this->typeOf($chart[$key]), $chart[$key],
                        $key === 'dataLabels' ? 'true prints each value on the chart.' : 'Defaults to on for several series or a pie.');
                }
            }
        }

        return $errors;
    }

    /** @return list<array<string, mixed>> */
    private function validatePoints(mixed $points, string $at, ?ChartRange $values): array
    {
        if (! is_array($points) || ($points !== [] && ! array_is_list($points))) {
            return [$this->error($at, 'a list of points', $this->typeOf($points), $points,
                'Colour single points: [{"index": 2, "color": "#C2410C"}], index 0-based within values.')];
        }
        $count = $values === null ? 0 : count($values->addresses());
        $errors = [];
        foreach ($points as $k => $p) {
            $index = is_array($p) ? ($p['index'] ?? null) : null;
            $color = is_array($p) ? ($p['color'] ?? null) : null;
            if (! is_int($index) || $index < 0 || $index >= $count) {
                $errors[] = $this->error("{$at}[{$k}].index", 'an index within values (0 to '.max(0, $count - 1).')', $this->typeOf($index), $index,
                    'The 0-based position of the point in its values range: "B5:B7" has points 0, 1 and 2.');
            }
            if (! is_string($color) || preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
                $errors[] = $this->error("{$at}[{$k}].color", '#RRGGBB', $this->typeOf($color), $color, 'A hex colour, like "#C2410C".');
            }
        }

        return $errors;
    }

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
