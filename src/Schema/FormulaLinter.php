<?php

declare(strict_types=1);

namespace HolySheet\Schema;

use HolySheet\Workbook\Cell;
use HolySheet\Workbook\CellAddress;
use HolySheet\Workbook\Sheet;
use HolySheet\Workbook\Workbook;

/**
 * Evaluates every formula in a schema and reports cells that produce Excel-
 * style errors (`#VALUE!`, `#REF!`, `#DIV/0!`, `#NAME?`, `#CIRC!`).
 *
 * The linter exists to catch the bugs an LLM can introduce with formulas:
 * referencing the header row instead of a data row, passing a string to a
 * numeric operator, citing a cell that doesn't exist, building a circular
 * dependency. Catching these *before* writing the xlsx lets agents
 * self-correct on the next loop iteration instead of producing broken files.
 *
 * Supported formula vocabulary (the subset agents actually emit):
 *   - Operators:        + - * / ^ % & = < > <= >= <>
 *   - Cell refs:        A1, $A$1, Sheet2!B5
 *   - Ranges:           A1:A10, Sheet2!B2:B100
 *   - Numeric / string literals, parenthesized expressions
 *   - Functions:        SUM, AVERAGE/AVG, COUNT, COUNTA, MIN, MAX, IF,
 *                       ROUND, ABS, LEN, UPPER, LOWER, CONCAT/CONCATENATE
 *
 * Out of scope: array formulas, dynamic arrays, structured table refs,
 * named ranges, the long tail of Excel's 400+ functions. Those return
 * `#NAME?` so the agent knows to avoid them.
 *
 * Usage:
 *   $issues = (new FormulaLinter())->lint($schema);
 *   // → list<{sheet, address, formula, error, hint}>
 */
final class FormulaLinter
{
    private const ERR_VALUE = '#VALUE!';
    private const ERR_REF = '#REF!';
    private const ERR_NAME = '#NAME?';
    private const ERR_DIV0 = '#DIV/0!';
    private const ERR_CIRC = '#CIRC!';

    /**
     * @param  array<string,mixed>  $schema
     * @return list<array{sheet:string,address:string,formula:string,error:string,hint:string}>
     */
    public function lint(array $schema): array
    {
        $workbook = (new Normalizer())->normalize($schema);
        $index = $this->buildIndex($workbook);
        $cache = [];
        $issues = [];

        foreach ($workbook->sheets as $sheet) {
            foreach ($sheet->cells as $address => $cell) {
                if ($cell->formula === null) {
                    continue;
                }
                $key = $sheet->name.'!'.$address;
                $result = $this->evaluate($cell->formula, $sheet->name, $index, $cache, [$key]);
                $cache[$key] = $result;

                if ($this->isError($result)) {
                    $issues[] = [
                        'sheet' => $sheet->name,
                        'address' => $address,
                        'formula' => $cell->formula,
                        'error' => $result,
                        'hint' => $this->hint($result, $cell->formula, $sheet->name, $index, $cache),
                    ];
                }
            }
        }
        return $issues;
    }

    /** @return array<string,Cell> */
    private function buildIndex(Workbook $wb): array
    {
        $index = [];
        foreach ($wb->sheets as $sheet) {
            foreach ($sheet->cells as $address => $cell) {
                $index[$sheet->name.'!'.$address] = $cell;
            }
        }
        return $index;
    }

    private function isError(mixed $v): bool
    {
        return is_string($v) && isset($v[0]) && $v[0] === '#';
    }

    /**
     * Evaluate a formula. Returns a number, string, bool, error sentinel,
     * or an array (for range refs and function arg lists).
     *
     * @param  array<string,Cell>  $index
     * @param  array<string,mixed>  $cache
     * @param  list<string>  $stack  cycle-detection stack of fully-qualified keys
     */
    private function evaluate(string $formula, string $defaultSheet, array $index, array &$cache, array $stack): mixed
    {
        try {
            $tokens = $this->tokenize($formula);
            $pos = 0;
            $result = $this->parseExpr($tokens, $pos, $defaultSheet, $index, $cache, $stack);
            // If anything follows the parsed expression, the formula was malformed.
            if ($pos < count($tokens)) {
                return self::ERR_NAME;
            }
            return $result;
        } catch (LinterError $e) {
            return $e->errorCode;
        } catch (\Throwable) {
            return self::ERR_NAME;
        }
    }

    // ------------------------------------------------------------------ //
    // Tokenizer                                                           //
    // ------------------------------------------------------------------ //

    /** @return list<array{type:string, value:string}> */
    private function tokenize(string $src): array
    {
        $tokens = [];
        $i = 0;
        $len = strlen($src);
        while ($i < $len) {
            $ch = $src[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            // Number
            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $len && ctype_digit($src[$i + 1]))) {
                $start = $i;
                while ($i < $len && (ctype_digit($src[$i]) || $src[$i] === '.')) {
                    $i++;
                }
                $tokens[] = ['type' => 'NUMBER', 'value' => substr($src, $start, $i - $start)];
                continue;
            }
            // String literal
            if ($ch === '"') {
                $i++;
                $start = $i;
                while ($i < $len && $src[$i] !== '"') {
                    $i++;
                }
                $value = substr($src, $start, $i - $start);
                $i++; // consume closing quote
                $tokens[] = ['type' => 'STRING', 'value' => $value];
                continue;
            }
            // Identifier (cell ref, function name, sheet name)
            // Allow $ for absolute refs ($A$1)
            if (ctype_alpha($ch) || $ch === '_' || $ch === '$') {
                $start = $i;
                while ($i < $len && (ctype_alnum($src[$i]) || $src[$i] === '_' || $src[$i] === '$' || $src[$i] === '.')) {
                    $i++;
                }
                $tokens[] = ['type' => 'IDENT', 'value' => substr($src, $start, $i - $start)];
                continue;
            }
            // Multi-char operators
            if ($i + 1 < $len) {
                $two = substr($src, $i, 2);
                if ($two === '<=' || $two === '>=' || $two === '<>') {
                    $tokens[] = ['type' => 'OP', 'value' => $two];
                    $i += 2;
                    continue;
                }
            }
            // Single-char operators
            if (str_contains('+-*/^%&=<>(),:!', $ch)) {
                $tokens[] = ['type' => 'OP', 'value' => $ch];
                $i++;
                continue;
            }
            // Unknown character — fail soft as #NAME?
            throw new LinterError(self::ERR_NAME);
        }
        return $tokens;
    }

    // ------------------------------------------------------------------ //
    // Parser (recursive descent)                                          //
    // ------------------------------------------------------------------ //

    private function parseExpr(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        $left = $this->parseConcat($tokens, $pos, $sheet, $index, $cache, $stack);
        while ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP'
            && in_array($tokens[$pos]['value'], ['=', '<', '>', '<=', '>=', '<>'], true)) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = $this->parseConcat($tokens, $pos, $sheet, $index, $cache, $stack);
            $left = $this->compare($left, $right, $op);
        }
        return $left;
    }

    private function parseConcat(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        $left = $this->parseArith($tokens, $pos, $sheet, $index, $cache, $stack);
        while ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === '&') {
            $pos++;
            $right = $this->parseArith($tokens, $pos, $sheet, $index, $cache, $stack);
            $left = $this->coerceString($left).$this->coerceString($right);
        }
        return $left;
    }

    private function parseArith(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        $left = $this->parseTerm($tokens, $pos, $sheet, $index, $cache, $stack);
        while ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP'
            && ($tokens[$pos]['value'] === '+' || $tokens[$pos]['value'] === '-')) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = $this->parseTerm($tokens, $pos, $sheet, $index, $cache, $stack);
            $a = $this->coerceNumber($left);
            $b = $this->coerceNumber($right);
            $left = $op === '+' ? $a + $b : $a - $b;
        }
        return $left;
    }

    private function parseTerm(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        $left = $this->parseUnary($tokens, $pos, $sheet, $index, $cache, $stack);
        while ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP'
            && in_array($tokens[$pos]['value'], ['*', '/', '%', '^'], true)) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = $this->parseUnary($tokens, $pos, $sheet, $index, $cache, $stack);
            $a = $this->coerceNumber($left);
            $b = $this->coerceNumber($right);
            $left = match ($op) {
                '*' => $a * $b,
                '/' => $b == 0.0 ? throw new LinterError(self::ERR_DIV0) : $a / $b,
                '%' => $a / 100.0 * $b,    // Excel `%` is post-fix /100; treating as binary multiply for simplicity
                '^' => $a ** $b,
            };
        }
        return $left;
    }

    private function parseUnary(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        if ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === '-') {
            $pos++;
            $v = $this->parseUnary($tokens, $pos, $sheet, $index, $cache, $stack);
            return -$this->coerceNumber($v);
        }
        if ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === '+') {
            $pos++;
            return $this->parseUnary($tokens, $pos, $sheet, $index, $cache, $stack);
        }
        return $this->parsePrimary($tokens, $pos, $sheet, $index, $cache, $stack);
    }

    private function parsePrimary(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        if ($pos >= count($tokens)) {
            throw new LinterError(self::ERR_NAME);
        }
        $tok = $tokens[$pos];

        // Parenthesized expression
        if ($tok['type'] === 'OP' && $tok['value'] === '(') {
            $pos++;
            $val = $this->parseExpr($tokens, $pos, $sheet, $index, $cache, $stack);
            $this->expectOp($tokens, $pos, ')');
            return $val;
        }

        // Number / string literal
        if ($tok['type'] === 'NUMBER') {
            $pos++;
            return (float) $tok['value'];
        }
        if ($tok['type'] === 'STRING') {
            $pos++;
            return $tok['value'];
        }

        // Identifier — function call, sheet-qualified ref, cell ref, or range
        if ($tok['type'] === 'IDENT') {
            // Sheet!Ref form: IDENT '!' IDENT
            if ($pos + 1 < count($tokens)
                && $tokens[$pos + 1]['type'] === 'OP' && $tokens[$pos + 1]['value'] === '!'
                && $pos + 2 < count($tokens) && $tokens[$pos + 2]['type'] === 'IDENT') {
                $sheetName = $this->cleanSheetName($tok['value']);
                $pos += 2;
                $startTok = $tokens[$pos]['value'];
                $pos++;
                return $this->resolveRefOrRange($startTok, $sheetName, $tokens, $pos, $index, $cache, $stack);
            }

            // Function call: IDENT '('
            if ($pos + 1 < count($tokens)
                && $tokens[$pos + 1]['type'] === 'OP' && $tokens[$pos + 1]['value'] === '(') {
                $name = strtoupper($tok['value']);
                $pos += 2; // consume IDENT '('
                $args = [];
                if (! ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === ')')) {
                    while (true) {
                        $args[] = $this->parseExpr($tokens, $pos, $sheet, $index, $cache, $stack);
                        if ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === ',') {
                            $pos++;
                            continue;
                        }
                        break;
                    }
                }
                $this->expectOp($tokens, $pos, ')');
                return $this->callFunction($name, $args);
            }

            // Boolean literals (Excel uses TRUE / FALSE bareword)
            $upper = strtoupper($tok['value']);
            if ($upper === 'TRUE' || $upper === 'FALSE') {
                $pos++;
                return $upper === 'TRUE';
            }

            // Bare cell reference
            $pos++;
            return $this->resolveRefOrRange($tok['value'], $sheet, $tokens, $pos, $index, $cache, $stack);
        }

        throw new LinterError(self::ERR_NAME);
    }

    /** Resolve a single ref OR a range (when the next token is `:`). */
    private function resolveRefOrRange(string $startRef, string $defaultSheet, array $tokens, int &$pos, array $index, array &$cache, array $stack): mixed
    {
        $cleanStart = $this->cleanRef($startRef);
        if ($cleanStart === null) {
            throw new LinterError(self::ERR_REF);
        }
        // Range?
        if ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === ':'
            && $pos + 1 < count($tokens) && $tokens[$pos + 1]['type'] === 'IDENT') {
            $endRef = $tokens[$pos + 1]['value'];
            $pos += 2;
            $cleanEnd = $this->cleanRef($endRef);
            if ($cleanEnd === null) {
                throw new LinterError(self::ERR_REF);
            }
            return $this->resolveRange($defaultSheet, $cleanStart, $cleanEnd, $index, $cache, $stack);
        }
        return $this->resolveCell($defaultSheet, $cleanStart, $index, $cache, $stack);
    }

    /** Strip `$` (absolute) markers; return uppercase A1 or null if malformed. */
    private function cleanRef(string $ref): ?string
    {
        $ref = strtoupper(str_replace('$', '', $ref));
        return preg_match('/^[A-Z]+\d+$/', $ref) === 1 ? $ref : null;
    }

    /** Strip surrounding single quotes that Excel uses for sheet names with spaces. */
    private function cleanSheetName(string $name): string
    {
        if (strlen($name) >= 2 && $name[0] === "'" && $name[-1] === "'") {
            $name = substr($name, 1, -1);
        }
        return $name;
    }

    private function resolveCell(string $sheet, string $a1, array $index, array &$cache, array $stack): mixed
    {
        $key = $sheet.'!'.$a1;
        if (in_array($key, $stack, true)) {
            return self::ERR_CIRC;
        }
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $cell = $index[$key] ?? null;
        if ($cell === null) {
            // Cell isn't defined — empty in Excel. Treat as 0 for math, "" for concat.
            // We return null so coerceNumber/coerceString handle it consistently.
            return null;
        }
        if ($cell->formula !== null) {
            $stack[] = $key;
            $result = $this->evaluate($cell->formula, $sheet, $index, $cache, $stack);
            $cache[$key] = $result;
            return $result;
        }
        return $cell->value;
    }

    /** @return list<mixed> */
    private function resolveRange(string $sheet, string $start, string $end, array $index, array &$cache, array $stack): array
    {
        $a = CellAddress::parse($start);
        $b = CellAddress::parse($end);
        if ($a === null || $b === null) {
            throw new LinterError(self::ERR_REF);
        }
        [$col1, $row1] = [min($a[0], $b[0]), min($a[1], $b[1])];
        [$col2, $row2] = [max($a[0], $b[0]), max($a[1], $b[1])];

        $values = [];
        for ($r = $row1; $r <= $row2; $r++) {
            for ($c = $col1; $c <= $col2; $c++) {
                $addr = CellAddress::letter($c).$r;
                $values[] = $this->resolveCell($sheet, $addr, $index, $cache, $stack);
            }
        }
        return $values;
    }

    private function expectOp(array $tokens, int &$pos, string $op): void
    {
        if ($pos >= count($tokens) || $tokens[$pos]['type'] !== 'OP' || $tokens[$pos]['value'] !== $op) {
            throw new LinterError(self::ERR_NAME);
        }
        $pos++;
    }

    // ------------------------------------------------------------------ //
    // Coercion + comparisons + functions                                  //
    // ------------------------------------------------------------------ //

    private function coerceNumber(mixed $v): float
    {
        if ($this->isError($v)) {
            throw new LinterError($v);
        }
        if (is_array($v)) {
            // Implicit-intersection: take first numeric, otherwise #VALUE!
            foreach ($v as $item) {
                if (is_int($item) || is_float($item)) {
                    return (float) $item;
                }
                if (is_string($item) && is_numeric($item)) {
                    return (float) $item;
                }
            }
            throw new LinterError(self::ERR_VALUE);
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }
        if ($v === null) {
            return 0.0;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }
        throw new LinterError(self::ERR_VALUE);
    }

    private function coerceString(mixed $v): string
    {
        if ($this->isError($v)) {
            throw new LinterError($v);
        }
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if (is_array($v)) {
            return implode('', array_map(fn ($x) => $this->coerceString($x), $v));
        }
        return (string) $v;
    }

    private function compare(mixed $a, mixed $b, string $op): bool
    {
        $cmp = $this->cmpVal($a, $b);
        return match ($op) {
            '=' => $cmp === 0,
            '<>' => $cmp !== 0,
            '<' => $cmp < 0,
            '>' => $cmp > 0,
            '<=' => $cmp <= 0,
            '>=' => $cmp >= 0,
            default => false,
        };
    }

    private function cmpVal(mixed $a, mixed $b): int
    {
        // Both numeric? compare as numbers
        $an = is_int($a) || is_float($a) || (is_string($a) && is_numeric($a));
        $bn = is_int($b) || is_float($b) || (is_string($b) && is_numeric($b));
        if ($an && $bn) {
            return (float) $a <=> (float) $b;
        }
        return (string) $a <=> (string) $b;
    }

    /** @param list<mixed> $args */
    private function callFunction(string $name, array $args): mixed
    {
        $flat = $this->flatten($args);
        return match ($name) {
            'SUM' => array_sum(array_map(fn ($v) => $this->maybeNum($v), array_filter($flat, fn ($v) => $this->isNumericLike($v)))),
            'AVERAGE', 'AVG' => $this->avg($flat),
            'COUNT' => count(array_filter($flat, fn ($v) => $this->isNumericLike($v))),
            'COUNTA' => count(array_filter($flat, fn ($v) => $v !== null && $v !== '')),
            'MIN' => $this->minMax($flat, true),
            'MAX' => $this->minMax($flat, false),
            'IF' => $this->ifFn($args),
            'ROUND' => round($this->coerceNumber($args[0] ?? 0), (int) $this->coerceNumber($args[1] ?? 0)),
            'ABS' => abs($this->coerceNumber($args[0] ?? 0)),
            'LEN' => strlen($this->coerceString($args[0] ?? '')),
            'UPPER' => strtoupper($this->coerceString($args[0] ?? '')),
            'LOWER' => strtolower($this->coerceString($args[0] ?? '')),
            'CONCAT', 'CONCATENATE' => implode('', array_map(fn ($v) => $this->coerceString($v), $flat)),
            'TRUE' => true,
            'FALSE' => false,
            default => self::ERR_NAME,
        };
    }

    private function flatten(array $args): array
    {
        $out = [];
        foreach ($args as $a) {
            if (is_array($a)) {
                foreach ($a as $x) {
                    $out[] = $x;
                }
            } else {
                $out[] = $a;
            }
        }
        return $out;
    }

    private function isNumericLike(mixed $v): bool
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)) || is_bool($v);
    }

    private function maybeNum(mixed $v): float
    {
        return is_bool($v) ? ($v ? 1.0 : 0.0) : (float) $v;
    }

    private function avg(array $flat): float|string
    {
        $nums = array_filter($flat, fn ($v) => $this->isNumericLike($v));
        if ($nums === []) {
            return self::ERR_DIV0;
        }
        return array_sum(array_map(fn ($v) => $this->maybeNum($v), $nums)) / count($nums);
    }

    private function minMax(array $flat, bool $min): float|string
    {
        $nums = array_filter($flat, fn ($v) => $this->isNumericLike($v));
        if ($nums === []) {
            return 0.0;
        }
        $vals = array_map(fn ($v) => $this->maybeNum($v), $nums);
        return $min ? min($vals) : max($vals);
    }

    private function ifFn(array $args): mixed
    {
        if (count($args) < 2) {
            return self::ERR_VALUE;
        }
        $cond = $args[0];
        $truthy = is_bool($cond) ? $cond : ($cond !== null && $cond !== 0 && $cond !== 0.0 && $cond !== '' && $cond !== 'FALSE');
        return $truthy ? $args[1] : ($args[2] ?? false);
    }

    // ------------------------------------------------------------------ //
    // Hint generation                                                     //
    // ------------------------------------------------------------------ //

    private function hint(string $error, string $formula, string $sheet, array $index, array $cache): string
    {
        return match ($error) {
            self::ERR_VALUE => $this->hintValue($formula, $sheet, $index, $cache),
            self::ERR_REF => 'A cell reference points to a cell that doesn\'t exist in the workbook. Check column letters and row numbers.',
            self::ERR_NAME => 'The formula references an unknown function or has a syntax error. Holy Sheet supports: SUM, AVERAGE, COUNT, COUNTA, MIN, MAX, IF, ROUND, ABS, LEN, UPPER, LOWER, CONCAT.',
            self::ERR_DIV0 => 'Division by zero — the divisor evaluated to 0.',
            self::ERR_CIRC => 'Circular reference — the formula directly or transitively depends on its own cell.',
            default => 'Formula evaluation failed.',
        };
    }

    private function hintValue(string $formula, string $sheet, array $index, array $cache): string
    {
        // Look at every cell ref appearing in the formula and surface ones
        // that resolved to non-numeric strings — that's almost always the
        // off-by-one bug (header row instead of data row).
        if (preg_match_all('/(?:([A-Za-z][A-Za-z0-9_]*)!)?\$?([A-Z]+)\$?(\d+)/i', $formula, $matches, PREG_SET_ORDER) === false) {
            return 'A non-numeric value was used in arithmetic.';
        }

        $offenders = [];
        foreach ($matches as $m) {
            $sheetName = $m[1] !== '' ? $m[1] : $sheet;
            $a1 = strtoupper($m[2]).$m[3];
            $key = $sheetName.'!'.$a1;
            $cell = $index[$key] ?? null;
            if ($cell === null) {
                continue;
            }
            $value = $cache[$key] ?? ($cell->formula !== null ? null : $cell->value);
            if (is_string($value) && ! is_numeric($value) && $value !== '') {
                // Suggest the row below if it exists and is numeric — classic
                // header→data off-by-one fix.
                $row = (int) $m[3];
                $col = $m[2];
                $next = $sheetName.'!'.strtoupper($col).($row + 1);
                $nextCell = $index[$next] ?? null;
                $suggestion = '';
                if ($nextCell !== null && is_numeric($nextCell->value)) {
                    $suggestion = sprintf(' Did you mean %s%d? (it holds %s)', strtoupper($col), $row + 1, (string) $nextCell->value);
                }
                $offenders[] = sprintf('%s = "%s" (string)%s', $a1, $value, $suggestion);
            }
        }
        if ($offenders !== []) {
            return 'Arithmetic on a non-numeric cell: '.implode('; ', $offenders);
        }
        return 'A non-numeric value was used in arithmetic. Check that all referenced cells contain numbers.';
    }
}

/** Internal exception for short-circuit error returns from deep in the parser. */
final class LinterError extends \RuntimeException
{
    public function __construct(public string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
