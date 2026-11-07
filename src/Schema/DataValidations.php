<?php

declare(strict_types=1);

namespace HolySheet\Schema;

use HolySheet\Workbook\CellAddress;
use HolySheet\Workbook\ChartRange;
use HolySheet\Writer\Format\DateConverter;

/**
 * Data validation: the schema shape, its problems, its normal form and its XML.
 *
 * One rule, as an agent writes it:
 *
 *     {"range": "B2:B500", "type": "list", "values": ["Open", "Closed"],
 *      "prompt": {"title": "Status", "text": "Pick one."},
 *      "error": {"style": "stop", "title": "Unknown status", "text": "Pick from the list."}}
 *
 * `range` belongs to the sheet-level `dataValidations` list only. The same
 * object without `range` sits on a column (`columns[].validation`) and covers
 * that column's data rows.
 *
 * Every rule is held to the same checks in the same order here and in both
 * ports, because the error list (paths AND wording) is part of the contract:
 * type, range, the type's own keys, the keys that do not apply, allowBlank,
 * prompt, error.
 *
 * Lengths are counted in characters (code points), not bytes.
 */
final class DataValidations
{
    public const TYPES = ['list', 'whole', 'decimal', 'date', 'textLength'];

    public const OPERATORS = ['between', 'notBetween', 'equal', 'notEqual', 'greaterThan', 'lessThan', 'greaterThanOrEqual', 'lessThanOrEqual'];

    public const STYLES = ['stop', 'warning', 'information'];

    /** Excel's cap on an inline list, the commas between choices included. */
    public const MAX_LIST = 255;

    /** The largest integer every engine holds exactly (2^53 - 1). */
    private const MAX_WHOLE = 9007199254740991;

    private const MAX_ROW = 1048576;

    /**
     * The problems with one rule, as [subpath, expected, got, value, hint].
     *
     * @param  bool  $column  the rule sits on a column (no `range`; it covers the data rows)
     * @param  ?list<string>  $sheetNames  every sheet in the workbook, to check a source's sheet; null skips that check
     * @return list<array{0:string,1:string,2:string,3:mixed,4:string}>
     */
    public static function problems(mixed $dv, string $ownSheet, ?array $sheetNames, bool $column, int $dataRows): array
    {
        if (! is_array($dv) || ($dv !== [] && array_is_list($dv))) {
            return [['', 'object', self::typeOf($dv), $dv, $column
                ? 'A column validation is an object: {"type": "list", "values": ["Open", "Closed"]}.'
                : 'A validation is an object: {"range": "B2:B100", "type": "list", "values": ["Open", "Closed"]}.']];
        }

        $out = [];
        $type = $dv['type'] ?? null;
        if (! in_array($type, self::TYPES, true)) {
            $out[] = ['.type', 'one of: '.implode(', ', self::TYPES), self::typeOf($type), $type,
                'list is a dropdown; whole, decimal and date bound a number or a date; textLength bounds how long the text is.'];
        }

        if ($column) {
            if (array_key_exists('range', $dv)) {
                $out[] = ['.range', 'absent', self::typeOf($dv['range']), $dv['range'],
                    'A column validation covers that column\'s data rows. To choose the cells yourself, put it in the sheet\'s dataValidations with a range.'];
            } elseif ($dataRows === 0) {
                $out[] = ['', 'a sheet with data rows', 'no rows', null,
                    'A column validation covers the column\'s data rows, and this sheet has none. For a template, put it in the sheet\'s dataValidations with a range, like "B2:B500".'];
            }
        } elseif (self::sqref($dv['range'] ?? null) === null) {
            $out[] = ['.range', 'a cell or range on this sheet, like "B2:B100"', self::typeOf($dv['range'] ?? null), $dv['range'] ?? null,
                'The cells the rule applies to, on this sheet. A dropdown for a template column usually runs well past the sample rows: "B2:B500".'];
        }

        if ($type === 'list') {
            $out = array_merge($out, self::listProblems($dv, $ownSheet, $sheetNames));
        } elseif (in_array($type, self::TYPES, true)) {
            $out = array_merge($out, self::boundProblems($dv, $type));
        }

        if (array_key_exists('allowBlank', $dv) && ! is_bool($dv['allowBlank'])) {
            $out[] = ['.allowBlank', 'boolean', self::typeOf($dv['allowBlank']), $dv['allowBlank'],
                'true (the default) lets a cell be left empty; false makes an empty cell break the rule.'];
        }

        if (array_key_exists('prompt', $dv)) {
            $p = $dv['prompt'];
            if (! is_array($p) || ($p !== [] && array_is_list($p))) {
                $out[] = ['.prompt', 'object {title?, text?}', self::typeOf($p), $p,
                    'Shown when the cell is selected: {"title": "Status", "text": "Pick one."}.'];
            } else {
                $out = array_merge($out, self::textProblems($p, '.prompt', 255, 'Excel limits an input prompt to 255 characters.'));
            }
        }

        if (array_key_exists('error', $dv)) {
            $e = $dv['error'];
            if (! is_array($e) || ($e !== [] && array_is_list($e))) {
                $out[] = ['.error', 'object {style?, title?, text?}', self::typeOf($e), $e,
                    'Shown when an entry breaks the rule: {"style": "stop", "title": "Invalid status", "text": "Pick a status from the list."}.'];
            } else {
                if (array_key_exists('style', $e) && ! in_array($e['style'], self::STYLES, true)) {
                    $out[] = ['.error.style', 'one of: '.implode(', ', self::STYLES), self::typeOf($e['style']), $e['style'],
                        'stop refuses the entry (the default); warning lets the person keep it after a warning; information only tells them.'];
                }
                $out = array_merge($out, self::textProblems($e, '.error', 225, 'Excel limits an error message to 225 characters.'));
            }
        }

        return $out;
    }

    /** @return list<array{0:string,1:string,2:string,3:mixed,4:string}> */
    private static function listProblems(array $dv, string $ownSheet, ?array $sheetNames): array
    {
        $out = [];
        $hasValues = array_key_exists('values', $dv);
        $hasSource = array_key_exists('source', $dv);
        if ($hasValues === $hasSource) {
            $out[] = ['', 'exactly one of values or source', $hasValues ? 'both' : 'neither', null,
                'A dropdown lists its choices inline, {"values": ["Open", "Closed"]}, or reads them from cells, {"source": "\'Lists\'!A1:A10"}.'];
        } elseif ($hasValues) {
            $values = $dv['values'];
            if (! is_array($values) || $values === [] || ! array_is_list($values)) {
                $out[] = ['.values', 'a non-empty list of choices', self::typeOf($values), $values,
                    'The choices, in order: ["Open", "Closed"].'];
            } else {
                $bad = false;
                foreach ($values as $k => $v) {
                    if (self::choice($v) === null) {
                        $bad = true;
                        $out[] = [".values[{$k}]", 'a non-empty string with no comma, or a whole number', self::typeOf($v), $v,
                            'Each choice is text, like "Open", or a whole number. Excel separates inline choices with commas and cannot escape one, so a choice containing a comma must come from cells: use source.'];
                    }
                }
                if (! $bad) {
                    $length = mb_strlen(implode(',', array_map(self::choice(...), $values)), 'UTF-8');
                    if ($length > self::MAX_LIST) {
                        $out[] = ['.values', 'at most 255 characters, commas included', "{$length} characters", $values,
                            'Excel cannot store an inline list longer than 255 characters. Put the choices in cells and point source at them, like "\'Lists\'!A1:A40".'];
                    }
                }
            }
        } else {
            $range = self::sourceRange($dv['source'], $ownSheet);
            if ($range === null) {
                $out[] = ['.source', 'a cell range like "\'Lists\'!A1:A10"', self::typeOf($dv['source']), $dv['source'],
                    'The cells holding the choices: "A2:A20" on this sheet, or "\'Lists\'!A1:A10" on another.'];
            } elseif ($sheetNames !== null && ! in_array($range->sheet, $sheetNames, true)) {
                $out[] = ['.source', 'a sheet in this workbook', 'unknown sheet', $dv['source'],
                    'There is no sheet named "'.$range->sheet.'". Name the sheet exactly as sheets[].name has it.'];
            }
        }

        if (array_key_exists('showDropdown', $dv) && ! is_bool($dv['showDropdown'])) {
            $out[] = ['.showDropdown', 'boolean', self::typeOf($dv['showDropdown']), $dv['showDropdown'],
                'true (the default) shows the in-cell dropdown arrow; false hides the arrow and still enforces the list.'];
        }

        foreach (['operator', 'min', 'max', 'value'] as $key) {
            if (array_key_exists($key, $dv)) {
                $out[] = [".{$key}", 'absent', self::typeOf($dv[$key]), $dv[$key],
                    "{$key} does not apply to a list: its choices are the rule."];
            }
        }

        return $out;
    }

    /** @return list<array{0:string,1:string,2:string,3:mixed,4:string}> */
    private static function boundProblems(array $dv, string $type): array
    {
        $out = [];
        $operator = $dv['operator'] ?? 'between';
        $known = in_array($operator, self::OPERATORS, true);
        if (! $known) {
            $out[] = ['.operator', 'one of: '.implode(', ', self::OPERATORS), self::typeOf($operator), $operator,
                'Omit for between. between and notBetween take min and max; the others take value.'];
        }

        [$expected, $hint] = match ($type) {
            'whole' => ['a whole number', 'A whole number, like 10.'],
            'decimal' => ['a number', 'A number, like 0.5.'],
            'date' => ['a date as YYYY-MM-DD', 'A calendar date, like "2026-12-31".'],
            default => ['a whole number of characters, 0 or more', 'A length in characters, like 50.'],
        };

        if ($known) {
            $two = $operator === 'between' || $operator === 'notBetween';
            foreach ($two ? ['min', 'max'] : ['value'] as $key) {
                $v = $dv[$key] ?? null;
                if (self::operand($v, $type) === null) {
                    $out[] = [".{$key}", $expected, self::typeOf($v), $v, $hint];
                }
            }
            if ($two && self::operand($dv['min'] ?? null, $type) !== null && self::operand($dv['max'] ?? null, $type) !== null
                && self::compare($dv['min'], $dv['max'], $type) > 0) {
                $out[] = ['.max', 'a value no less than min', self::typeOf($dv['max']), $dv['max'],
                    'min is the lower bound and max the upper; as written, nothing could ever pass this rule.'];
            }
            foreach ($two ? ['value'] : ['min', 'max'] as $key) {
                if (array_key_exists($key, $dv)) {
                    $out[] = [".{$key}", 'absent', self::typeOf($dv[$key]), $dv[$key], $two
                        ? "{$operator} takes min and max, not value."
                        : "{$operator} compares against one value: use value, not min and max."];
                }
            }
        }

        foreach (['values', 'source', 'showDropdown'] as $key) {
            if (array_key_exists($key, $dv)) {
                $out[] = [".{$key}", 'absent', self::typeOf($dv[$key]), $dv[$key], "{$key} applies only to a list."];
            }
        }

        return $out;
    }

    /** @return list<array{0:string,1:string,2:string,3:mixed,4:string}> */
    private static function textProblems(array $box, string $at, int $max, string $hint): array
    {
        $out = [];
        if (array_key_exists('title', $box) && (! is_string($box['title']) || mb_strlen($box['title'], 'UTF-8') > 32)) {
            $out[] = ["{$at}.title", 'a string of at most 32 characters', self::typeOf($box['title']), $box['title'],
                'Excel limits a prompt or error title to 32 characters.'];
        }
        if (array_key_exists('text', $box) && (! is_string($box['text']) || mb_strlen($box['text'], 'UTF-8') > $max)) {
            $out[] = ["{$at}.text", "a string of at most {$max} characters", self::typeOf($box['text']), $box['text'], $hint];
        }

        return $out;
    }

    /**
     * A rule in normal form, or null when it has a problem (the validator has
     * already named it). `$sqref` is the canonical range it covers.
     *
     * @return ?array{sqref:string,type:string,operator:?string,formula1:string,formula2:?string,allowBlank:bool,hideDropdown:bool,prompt:?array{title:string,text:string},error:array{style:string,title:string,text:string}}
     */
    public static function normalize(mixed $dv, string $sqref, string $ownSheet): ?array
    {
        // The range was checked by the caller and is carried as $sqref; the
        // rest is checked as a column rule with rows, which is every other check.
        if (is_array($dv)) {
            unset($dv['range']);
        }
        if (self::problems($dv, $ownSheet, null, true, 1) !== []) {
            return null;
        }
        $type = $dv['type'];
        $operator = null;
        $formula2 = null;
        if ($type === 'list') {
            if (array_key_exists('values', $dv)) {
                $formula1 = '"'.str_replace('"', '""', implode(',', array_map(self::choice(...), $dv['values']))).'"';
            } else {
                $range = self::sourceRange($dv['source'], $ownSheet);
                $formula1 = self::sourceFormula($range, $ownSheet);
            }
        } else {
            $op = $dv['operator'] ?? 'between';
            $operator = $op === 'between' ? null : $op;
            if ($op === 'between' || $op === 'notBetween') {
                $formula1 = self::operand($dv['min'], $type);
                $formula2 = self::operand($dv['max'], $type);
            } else {
                $formula1 = self::operand($dv['value'], $type);
            }
        }

        $prompt = null;
        if (is_array($dv['prompt'] ?? null)) {
            $prompt = ['title' => (string) ($dv['prompt']['title'] ?? ''), 'text' => (string) ($dv['prompt']['text'] ?? '')];
        }
        $error = is_array($dv['error'] ?? null) ? $dv['error'] : [];

        return [
            'sqref' => $sqref,
            'type' => $type,
            'operator' => $operator,
            'formula1' => (string) $formula1,
            'formula2' => $formula2,
            'allowBlank' => ($dv['allowBlank'] ?? true) === true,
            'hideDropdown' => $type === 'list' && ($dv['showDropdown'] ?? true) === false,
            'prompt' => $prompt,
            'error' => [
                'style' => (string) ($error['style'] ?? 'stop'),
                'title' => (string) ($error['title'] ?? ''),
                'text' => (string) ($error['text'] ?? ''),
            ],
        ];
    }

    /**
     * The rules of one sheet in normal form: column rules first, in column
     * order, then the sheet's own list, in its order. A rule with a problem
     * is dropped (the validator names it).
     *
     * @param  array<string,mixed>  $sheet
     * @return list<array<string,mixed>>
     */
    public static function forSheet(array $sheet, string $name): array
    {
        $out = [];
        $columns = is_array($sheet['columns'] ?? null) ? $sheet['columns'] : [];
        $rows = is_array($sheet['rows'] ?? null) ? count($sheet['rows']) : 0;
        if ($rows > 0) {
            foreach (array_values($columns) as $i => $col) {
                if (! is_array($col) || ! array_key_exists('validation', $col)) {
                    continue;
                }
                $letter = CellAddress::letter($i);
                $sqref = $letter.'2'.($rows > 1 ? ':'.$letter.($rows + 1) : '');
                if (is_array($col['validation']) && ! array_key_exists('range', $col['validation'])
                    && ($n = self::normalize($col['validation'], $sqref, $name)) !== null) {
                    $out[] = $n;
                }
            }
        }
        if (is_array($sheet['dataValidations'] ?? null) && array_is_list($sheet['dataValidations'])) {
            foreach ($sheet['dataValidations'] as $dv) {
                $sqref = is_array($dv) ? self::sqref($dv['range'] ?? null) : null;
                if ($sqref !== null && ($n = self::normalize($dv, $sqref, $name)) !== null) {
                    $out[] = $n;
                }
            }
        }

        return $out;
    }

    /**
     * `<dataValidations>`, or '' for none. Attributes in CT_DataValidation's
     * order; an attribute at its default is left out, except
     * showErrorMessage, without which Excel would not enforce the rule.
     *
     * @param  list<array<string,mixed>>  $rules
     * @param  callable(string):string  $escape
     */
    public static function xml(array $rules, callable $escape): string
    {
        if ($rules === []) {
            return '';
        }
        $xml = '<dataValidations count="'.count($rules).'">';
        foreach ($rules as $r) {
            $xml .= '<dataValidation type="'.$r['type'].'"'
                .($r['error']['style'] !== 'stop' ? ' errorStyle="'.$r['error']['style'].'"' : '')
                .($r['operator'] !== null ? ' operator="'.$r['operator'].'"' : '')
                .($r['allowBlank'] ? ' allowBlank="1"' : '')
                // OOXML's showDropDown="1" HIDES the arrow; the schema's
                // showDropdown says what a person sees.
                .($r['hideDropdown'] ? ' showDropDown="1"' : '')
                .($r['prompt'] !== null ? ' showInputMessage="1"' : '')
                .' showErrorMessage="1"'
                .($r['error']['title'] !== '' ? ' errorTitle="'.$escape($r['error']['title']).'"' : '')
                .($r['error']['text'] !== '' ? ' error="'.$escape($r['error']['text']).'"' : '')
                .($r['prompt'] !== null && $r['prompt']['title'] !== '' ? ' promptTitle="'.$escape($r['prompt']['title']).'"' : '')
                .($r['prompt'] !== null && $r['prompt']['text'] !== '' ? ' prompt="'.$escape($r['prompt']['text']).'"' : '')
                .' sqref="'.$r['sqref'].'">'
                .'<formula1>'.$escape($r['formula1']).'</formula1>'
                .($r['formula2'] !== null ? '<formula2>'.$escape($r['formula2']).'</formula2>' : '')
                .'</dataValidation>';
        }

        return $xml.'</dataValidations>';
    }

    /**
     * A sheet-level `range` as the canonical sqref ("B2:B500", "J2"), or null
     * when it is not a cell or range on this sheet inside Excel's grid.
     */
    public static function sqref(mixed $range): ?string
    {
        if (! is_string($range) || str_contains($range, '!')) {
            return null;
        }
        $r = self::gridRange($range, '');

        return $r?->toSchema('');
    }

    private static function sourceRange(mixed $source, string $ownSheet): ?ChartRange
    {
        return self::gridRange($source, $ownSheet);
    }

    private static function gridRange(mixed $ref, string $ownSheet): ?ChartRange
    {
        $r = ChartRange::parse($ref, $ownSheet);
        if ($r === null || $r->c2 > ColumnWidths::MAX_INDEX || $r->r2 > self::MAX_ROW) {
            return null;
        }

        return $r;
    }

    /** $A$1:$A$9 on the rule's own sheet, 'Lists'!$A$1:$A$9 on another. */
    private static function sourceFormula(ChartRange $r, string $ownSheet): string
    {
        $from = '$'.CellAddress::letter($r->c1).'$'.$r->r1;
        $to = '$'.CellAddress::letter($r->c2).'$'.$r->r2;
        $ref = $from === $to ? $from : $from.':'.$to;

        return $r->sheet === $ownSheet ? $ref : "'".str_replace("'", "''", $r->sheet)."'!".$ref;
    }

    /** An inline choice as text, or null when it cannot be one. */
    private static function choice(mixed $v): ?string
    {
        if (is_string($v)) {
            return $v !== '' && ! str_contains($v, ',') ? $v : null;
        }
        if (is_int($v) && abs($v) <= self::MAX_WHOLE) {
            return (string) $v;
        }

        return null;
    }

    /** An operand as the text of its formula, or null when it is not one for this type. */
    private static function operand(mixed $v, string $type): ?string
    {
        switch ($type) {
            case 'whole':
            case 'textLength':
                $whole = self::whole($v);
                if ($whole === null || ($type === 'textLength' && $whole < 0)) {
                    return null;
                }

                return (string) $whole;
            case 'decimal':
                if (is_int($v)) {
                    return (string) $v;
                }
                if (! is_float($v) || ! is_finite($v)) {
                    return null;
                }
                $s = rtrim(rtrim(number_format($v, 14, '.', ''), '0'), '.');

                return $s === '' ? '0' : $s;
            case 'date':
                if (! is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1
                    || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return null;
                }

                return (string) (int) DateConverter::toSerial($v);
        }

        return null;
    }

    /** A number with no fraction inside the exact-integer range, as an int. */
    private static function whole(mixed $v): ?int
    {
        if (is_int($v)) {
            return abs($v) <= self::MAX_WHOLE ? $v : null;
        }
        if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) <= self::MAX_WHOLE) {
            return (int) $v;
        }

        return null;
    }

    private static function compare(mixed $a, mixed $b, string $type): int
    {
        return $type === 'date' ? strcmp($a, $b) : ((float) $a <=> (float) $b);
    }

    private static function typeOf(mixed $value): string
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
