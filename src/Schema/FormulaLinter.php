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

    /** The functions callFunction() evaluates. Anything else is #NAME?. */
    private const FUNCTIONS = [
        'SUM', 'AVERAGE', 'AVG', 'COUNT', 'COUNTA', 'MIN', 'MAX', 'IF', 'ROUND',
        'ABS', 'LEN', 'UPPER', 'LOWER', 'CONCAT', 'CONCATENATE', 'TRUE', 'FALSE',
    ];

    /**
     * @param  array<string,mixed>  $schema
     * @return list<array{sheet:string,address:string,formula:string,error:string,hint:string}>
     */
    /**
     * Every sheet name, keyed by its lower-cased form. Excel matches sheet names
     * without regard to case, and a reference to a sheet that is not here is
     * `#REF!` rather than a range of blanks.
     *
     * @var array<string,string>
     */
    private array $sheetNames = [];

    /**
     * Why a cell's own formula produced #NAME?, keyed `Sheet!A1`: a syntax
     * error, an unknown function, or a linter fault. A cell with #NAME? and no
     * entry here inherited it from a cell it references.
     *
     * @var array<string,array{cause:string,detail:string}>
     */
    private array $nameCauses = [];

    /**
     * Greater than 0 while parsing an IF branch that is not taken. The parser
     * evaluates as it goes, so a skipped branch is still PARSED (its syntax
     * errors and unknown functions are reported) but nothing in it is
     * EVALUATED: no operator runs, no cell is read, and every value is null.
     */
    private int $skipping = 0;

    public function lint(array $schema): array
    {
        $workbook = (new Normalizer())->normalize($schema);
        $this->sheetNames = [];
        $this->nameCauses = [];
        $this->skipping = 0;
        foreach ($workbook->sheets as $sheet) {
            $this->sheetNames[mb_strtolower($sheet->name)] = $sheet->name;
        }
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
                        'hint' => $this->hint($result, $key, $cell->formula, $sheet->name, $index, $cache),
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
        $key = $stack[count($stack) - 1];
        unset($this->nameCauses[$key]);
        try {
            $tokens = $this->tokenize($formula);
            $pos = 0;
            $result = $this->parseExpr($tokens, $pos, $defaultSheet, $index, $cache, $stack);
            // If anything follows the parsed expression, the formula was malformed.
            if ($pos < count($tokens)) {
                throw LinterError::syntax(sprintf('unexpected %s after a complete expression', $this->describeToken($tokens[$pos])));
            }
            return $result;
        } catch (LinterError $e) {
            if ($e->cause !== null) {
                $this->nameCauses[$key] = ['cause' => $e->cause, 'detail' => $e->detail];
            }
            return $e->errorCode;
        } catch (\Throwable $e) {
            // A bug in this linter, not a fault in the formula. Still reported,
            // so it is not silently passed, but the hint says whose fault it is.
            $this->nameCauses[$key] = ['cause' => 'internal', 'detail' => get_debug_type($e)];
            return self::ERR_NAME;
        }
    }

    /** @param array{type:string, value:string} $tok */
    private function describeToken(array $tok): string
    {
        return match ($tok['type']) {
            'STRING' => 'text "'.$tok['value'].'"',
            'NUMBER' => 'number '.$tok['value'],
            default => "'".$tok['value']."'",
        };
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
            // String literal, with Excel's "" for a literal quote: "a""b" is a"b.
            // A literal that never closes is a syntax error, not text running
            // to the end of the formula.
            if ($ch === '"') {
                $i++;
                $value = '';
                while (true) {
                    if ($i >= $len) {
                        throw LinterError::syntax('a text literal opens with " and never closes');
                    }
                    if ($src[$i] === '"') {
                        if ($i + 1 < $len && $src[$i + 1] === '"') {
                            $value .= '"';
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $value .= $src[$i];
                    $i++;
                }
                $tokens[] = ['type' => 'STRING', 'value' => $value];
                continue;
            }
            // Quoted sheet name: 'My Sheet', with Excel's '' for a literal quote.
            // Kept with its quotes; cleanSheetName() unwraps it. A quote that
            // never closes is a syntax error, not a name running to the end.
            if ($ch === "'") {
                $start = $i;
                $i++;
                while (true) {
                    if ($i >= $len) {
                        throw LinterError::syntax("a quoted sheet name opens with ' and never closes");
                    }
                    if ($src[$i] === "'") {
                        if ($i + 1 < $len && $src[$i + 1] === "'") {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                $tokens[] = ['type' => 'SHEET', 'value' => substr($src, $start, $i - $start)];
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
            // Unknown character — fail soft as #NAME?. Name it only when it is
            // ASCII: a lone byte of a multi-byte character is not valid UTF-8,
            // and the hint ends up in JSON.
            throw LinterError::syntax(ord($ch) < 128
                ? sprintf("unexpected character '%s'", $ch)
                : 'unexpected non-ASCII character outside a text literal');
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
            $left = $this->skipping > 0 ? null : $this->compare($left, $right, $op);
        }
        return $left;
    }

    private function parseConcat(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        $left = $this->parseArith($tokens, $pos, $sheet, $index, $cache, $stack);
        while ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === '&') {
            $pos++;
            $right = $this->parseArith($tokens, $pos, $sheet, $index, $cache, $stack);
            $left = $this->skipping > 0 ? null : $this->coerceString($left).$this->coerceString($right);
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
            if ($this->skipping > 0) {
                $left = null;
                continue;
            }
            $a = $this->coerceNumber($left);
            $b = $this->coerceNumber($right);
            $left = $op === '+' ? $a + $b : $a - $b;
        }
        return $left;
    }

    private function parseTerm(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        $left = $this->parsePower($tokens, $pos, $sheet, $index, $cache, $stack);
        while ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP'
            && in_array($tokens[$pos]['value'], ['*', '/'], true)) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = $this->parsePower($tokens, $pos, $sheet, $index, $cache, $stack);
            if ($this->skipping > 0) {
                $left = null;
                continue;
            }
            $a = $this->coerceNumber($left);
            $b = $this->coerceNumber($right);
            $left = match ($op) {
                '*' => $a * $b,
                '/' => $b == 0.0 ? throw new LinterError(self::ERR_DIV0) : $a / $b,
            };
        }
        return $left;
    }

    /**
     * `^` binds tighter than `*` and `/` (=2*3^2 is 18) and, as in Excel, is
     * left-associative (=2^3^2 is 64). Negation binds tighter than `^`, so
     * =-2^2 is 4: Excel's order, not mathematics'.
     */
    private function parsePower(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        $left = $this->parseUnary($tokens, $pos, $sheet, $index, $cache, $stack);
        while ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === '^') {
            $pos++;
            $right = $this->parseUnary($tokens, $pos, $sheet, $index, $cache, $stack);
            $left = $this->skipping > 0 ? null : $this->coerceNumber($left) ** $this->coerceNumber($right);
        }
        return $left;
    }

    private function parseUnary(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        if ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === '-') {
            $pos++;
            $v = $this->parseUnary($tokens, $pos, $sheet, $index, $cache, $stack);
            return $this->skipping > 0 ? null : -$this->coerceNumber($v);
        }
        if ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === '+') {
            $pos++;
            return $this->parseUnary($tokens, $pos, $sheet, $index, $cache, $stack);
        }
        return $this->parsePercent($tokens, $pos, $sheet, $index, $cache, $stack);
    }

    /**
     * Excel's `%` is a POSTFIX operator that divides by 100: `50%` is 0.5 and
     * `A1%` is A1/100. It binds tighter than `^`, `*` and `/`. There is no
     * binary `%`, so `10%2` leaves a stray `2` and is a syntax error.
     */
    private function parsePercent(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        $value = $this->parsePrimary($tokens, $pos, $sheet, $index, $cache, $stack);
        while ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === '%') {
            $pos++;
            $value = $this->skipping > 0 ? null : $this->coerceNumber($value) / 100.0;
        }
        return $value;
    }

    private function parsePrimary(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        if ($pos >= count($tokens)) {
            throw LinterError::syntax('the formula ends where a value was expected');
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

        // A quoted name is only ever a sheet qualifier. On its own it is not a
        // value, so it falls through to #NAME? below.
        if ($tok['type'] === 'SHEET' || $tok['type'] === 'IDENT') {
            // Sheet!Ref form: (SHEET | IDENT) '!' IDENT
            if ($pos + 1 < count($tokens)
                && $tokens[$pos + 1]['type'] === 'OP' && $tokens[$pos + 1]['value'] === '!'
                && $pos + 2 < count($tokens) && $tokens[$pos + 2]['type'] === 'IDENT') {
                $sheetName = $this->sheetNames[mb_strtolower($this->cleanSheetName($tok['value']))] ?? null;
                if ($sheetName === null && $this->skipping === 0) {
                    throw new LinterError(self::ERR_REF);
                }
                $pos += 2;
                $startTok = $tokens[$pos]['value'];
                $pos++;
                return $this->resolveRefOrRange($startTok, $sheetName ?? $tok['value'], $tokens, $pos, $index, $cache, $stack);
            }

            if ($tok['type'] === 'SHEET') {
                throw LinterError::syntax(sprintf('the quoted name %s is not followed by ! and a cell reference', $tok['value']));
            }

            // Function call: IDENT '('
            if ($pos + 1 < count($tokens)
                && $tokens[$pos + 1]['type'] === 'OP' && $tokens[$pos + 1]['value'] === '(') {
                $name = strtoupper($tok['value']);
                // Excel resolves names before it evaluates anything, so an
                // unknown function is #NAME? whatever its arguments hold and
                // whichever IF branch it sits in.
                if (! in_array($name, self::FUNCTIONS, true)) {
                    throw new LinterError(self::ERR_NAME, 'function', $name);
                }
                if ($name === 'IF') {
                    return $this->parseIf($tokens, $pos, $sheet, $index, $cache, $stack);
                }
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
                return $this->skipping > 0 ? null : $this->callFunction($name, $args);
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

        throw LinterError::syntax(sprintf('unexpected %s where a value was expected', $this->describeToken($tok)));
    }

    /**
     * IF, evaluated lazily as Excel does: `=IF(A2=0,0,1/A2)` with A2 = 0 is 0,
     * not #DIV/0!. Every argument is parsed, so a syntax error or an unknown
     * function anywhere in it is still reported, but only the condition and the
     * branch it selects are evaluated.
     *
     * The order of the verdicts is deliberate. A syntax error anywhere wins,
     * because Excel refuses a formula it cannot parse and computes nothing.
     * Then fewer than two arguments is #VALUE!. Then an error in the condition
     * propagates, and neither branch is evaluated.
     */
    private function parseIf(array $tokens, int &$pos, string $sheet, array $index, array &$cache, array $stack): mixed
    {
        $pos += 2; // consume IF '('
        $args = [];
        $take = null;           // the argument the condition selects: 1 or 2
        $conditionError = null; // an error the condition evaluated to
        if (! ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === ')')) {
            while (true) {
                $i = count($args);
                $skip = $i > 0 && $i !== $take;
                if ($skip) {
                    $this->skipping++;
                }
                try {
                    $args[] = $this->parseExpr($tokens, $pos, $sheet, $index, $cache, $stack);
                } finally {
                    if ($skip) {
                        $this->skipping--;
                    }
                }
                if ($i === 0 && $this->skipping === 0) {
                    try {
                        $take = $this->toLogical($args[0]) ? 1 : 2;
                    } catch (LinterError $e) {
                        $conditionError = $e;
                    }
                }
                if ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === ',') {
                    $pos++;
                    continue;
                }
                break;
            }
        }
        $this->expectOp($tokens, $pos, ')');

        if ($this->skipping > 0) {
            return null;
        }
        if (count($args) < 2) {
            return self::ERR_VALUE;
        }
        if ($conditionError !== null) {
            throw $conditionError;
        }
        return $take === 1 ? $args[1] : ($args[2] ?? false);
    }

    /** Resolve a single ref OR a range (when the next token is `:`). */
    private function resolveRefOrRange(string $startRef, string $defaultSheet, array $tokens, int &$pos, array $index, array &$cache, array $stack): mixed
    {
        if ($this->skipping > 0) {
            // In an untaken IF branch: consume a range's `:` and end so the
            // parse carries on, but read nothing. A #REF!, or an error held by
            // the cell, belongs to a branch Excel never evaluates.
            if ($pos < count($tokens) && $tokens[$pos]['type'] === 'OP' && $tokens[$pos]['value'] === ':'
                && $pos + 1 < count($tokens) && $tokens[$pos + 1]['type'] === 'IDENT') {
                $pos += 2;
            }
            return null;
        }
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

    /**
     * Unwrap the single quotes Excel puts around a sheet name that needs them,
     * and turn its doubled quote back into one: 'Q3 ''Final''' is Q3 'Final'.
     */
    private function cleanSheetName(string $name): string
    {
        if (strlen($name) >= 2 && $name[0] === "'" && $name[-1] === "'") {
            $name = str_replace("''", "'", substr($name, 1, -1));
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
        if ($pos >= count($tokens)) {
            throw LinterError::syntax(sprintf("expected '%s', but the formula ends", $op));
        }
        if ($tokens[$pos]['type'] !== 'OP' || $tokens[$pos]['value'] !== $op) {
            throw LinterError::syntax(sprintf("expected '%s', found %s", $op, $this->describeToken($tokens[$pos])));
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

    /**
     * Excel's comparison. An error on either side propagates. Otherwise the
     * types rank number < text < logical, so ="5">9 is TRUE: numeric TEXT is
     * text, never a number. Text compares without regard to case. A blank
     * takes the other side's type (0, "" or FALSE); two blanks are equal.
     */
    private function cmpVal(mixed $a, mixed $b): int
    {
        if ($this->isError($a)) {
            throw new LinterError($a);
        }
        if ($this->isError($b)) {
            throw new LinterError($b);
        }
        if (is_array($a) || is_array($b)) {
            // A range. Implicit intersection is out of scope; unchanged from before.
            return (string) $a <=> (string) $b;
        }
        $a ??= $this->blankLike($b);
        $b ??= $this->blankLike($a);
        $rank = fn (mixed $v): int => is_bool($v) ? 2 : (is_string($v) ? 1 : 0);
        if ($rank($a) !== $rank($b)) {
            return $rank($a) <=> $rank($b);
        }
        if (is_string($a)) {
            // strcmp, not <=>: PHP's <=> compares two numeric strings as
            // numbers, which is the very defect this replaces.
            return strcmp(mb_strtolower($a), mb_strtolower((string) $b)) <=> 0;
        }
        return $a <=> $b;
    }

    /** What a blank cell reads as when compared with $other. */
    private function blankLike(mixed $other): mixed
    {
        return match (true) {
            is_bool($other) => false,
            is_string($other) => '',
            default => 0.0,
        };
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
            'ROUND' => round($this->coerceNumber($args[0] ?? 0), (int) $this->coerceNumber($args[1] ?? 0)),
            'ABS' => abs($this->coerceNumber($args[0] ?? 0)),
            'LEN' => $this->excelLength($this->coerceString($args[0] ?? '')),
            'UPPER' => strtoupper($this->coerceString($args[0] ?? '')),
            'LOWER' => strtolower($this->coerceString($args[0] ?? '')),
            'CONCAT', 'CONCATENATE' => implode('', array_map(fn ($v) => $this->coerceString($v), $flat)),
            'TRUE' => true,
            'FALSE' => false,
            // Unreachable: parsePrimary checks FUNCTIONS before parsing args,
            // and IF never arrives here, because parseIf evaluates it lazily.
            default => throw new LinterError(self::ERR_NAME, 'function', $name),
        };
    }

    /**
     * Excel's LEN counts UTF-16 code units: "é" is 1, and a character outside
     * the Basic Multilingual Plane (most emoji) is 2. Never bytes.
     */
    private function excelLength(string $s): int
    {
        return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
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

    /**
     * Excel's reading of an IF condition: an error propagates, a blank is
     * FALSE, a number is TRUE unless it is 0, and text is TRUE or FALSE only
     * when it spells one of them (in any case). Any other text, "" included,
     * is #VALUE!.
     */
    private function toLogical(mixed $v): bool
    {
        if ($this->isError($v)) {
            throw new LinterError($v);
        }
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null) {
            return false;
        }
        if (is_int($v) || is_float($v)) {
            return $v != 0;
        }
        if (is_string($v)) {
            return match (strtoupper($v)) {
                'TRUE' => true,
                'FALSE' => false,
                default => throw new LinterError(self::ERR_VALUE),
            };
        }
        // A range. Implicit intersection is out of scope; unchanged from before.
        return true;
    }

    // ------------------------------------------------------------------ //
    // Hint generation                                                     //
    // ------------------------------------------------------------------ //

    private function hint(string $error, string $key, string $formula, string $sheet, array $index, array $cache): string
    {
        return match ($error) {
            self::ERR_VALUE => $this->hintValue($formula, $sheet, $index, $cache),
            self::ERR_REF => $this->hintRef($formula),
            self::ERR_NAME => $this->hintName($key, $formula, $sheet, $cache),
            self::ERR_DIV0 => 'Division by zero — the divisor evaluated to 0.',
            self::ERR_CIRC => 'Circular reference — the formula directly or transitively depends on its own cell.',
            default => 'Formula evaluation failed.',
        };
    }

    /**
     * A sheet qualifier, quoted ('My Sheet'!) or bare (Sheet2!). Group 1 is the
     * name as written, quotes included.
     */
    private const SHEET_QUALIFIER = "('(?:[^']|'')+'|[A-Za-z_][A-Za-z0-9_.]*)!";

    /**
     * #NAME? has three causes in this formula, plus inheritance, and each
     * hint says which one it is. A syntax error used to be told it "references
     * an unknown function", and an agent went hunting for a function that was
     * fine.
     *
     * Why a syntax error keeps the #NAME? label rather than a new one: Excel
     * has no cell value for a formula it cannot parse (it refuses the entry),
     * so there is no truer label to borrow; #ERROR! is a Google Sheets value,
     * and holy-sheet writes for Excel. The approved fancy-formula delegation
     * (plan section 9) maps FORMULA_SYNTAX to #NAME?, so a new label here would
     * change twice. And `error` is a closed vocabulary consumers switch on,
     * shared byte-for-byte with holy-sheet-js and holy-sheet-py. The hint is
     * where the difference belongs.
     */
    private function hintName(string $key, string $formula, string $sheet, array $cache): string
    {
        $cause = $this->nameCauses[$key] ?? null;

        return match ($cause['cause'] ?? null) {
            'syntax' => sprintf(
                'Syntax error: %s. Excel cannot parse this formula. Check for an unbalanced parenthesis or quote, a missing operator or argument, or a character a formula cannot contain. Text goes in double quotes, and a quote inside text is written twice: "a""b".',
                $cause['detail'],
            ),
            'function' => sprintf(
                'Unknown function %s. Holy Sheet supports: SUM, AVERAGE, COUNT, COUNTA, MIN, MAX, IF, ROUND, ABS, LEN, UPPER, LOWER, CONCAT.',
                $cause['detail'],
            ),
            'internal' => sprintf(
                'The linter itself failed on this formula (%s). That is a holy-sheet defect, not evidence that the formula is wrong.',
                $cause['detail'],
            ),
            default => $this->hintInheritedName($formula, $sheet, $cache),
        };
    }

    private function hintInheritedName(string $formula, string $sheet, array $cache): string
    {
        $sources = [];
        if (preg_match_all('/(?:'.self::SHEET_QUALIFIER.')?\$?([A-Z]+)\$?(\d+)/i', $formula, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $sheetName = $m[1] !== ''
                    ? ($this->sheetNames[mb_strtolower($this->cleanSheetName($m[1]))] ?? $this->cleanSheetName($m[1]))
                    : $sheet;
                $a1 = strtoupper($m[2]).$m[3];
                if (($cache[$sheetName.'!'.$a1] ?? null) === self::ERR_NAME) {
                    $sources[] = $m[1] !== '' ? $m[1].'!'.$a1 : $a1;
                }
            }
        }

        return $sources === []
            ? 'This formula depends on a cell whose value is #NAME?. Fix that cell first.'
            : sprintf('This formula depends on %s, whose value is #NAME?. Fix that cell first.', implode(', ', array_unique($sources)));
    }

    private function hintRef(string $formula): string
    {
        // Name the sheet when that is what is missing. "A cell doesn't exist"
        // sends an agent to check column letters in a sheet it never created.
        if (preg_match_all('/'.self::SHEET_QUALIFIER.'/', $formula, $matches) > 0) {
            foreach ($matches[1] as $raw) {
                $name = $this->cleanSheetName($raw);
                if (! isset($this->sheetNames[mb_strtolower($name)])) {
                    return sprintf(
                        "The formula refers to a sheet named '%s', and this workbook has no such sheet. Its sheets are: %s. Quote a name that contains spaces or punctuation: 'My Sheet'!A1.",
                        $name,
                        implode(', ', array_values($this->sheetNames)),
                    );
                }
            }
        }

        return 'A cell reference points to a cell that doesn\'t exist in the workbook. Check column letters and row numbers.';
    }

    private function hintValue(string $formula, string $sheet, array $index, array $cache): string
    {
        // Look at every cell ref appearing in the formula and surface ones
        // that resolved to non-numeric strings — that's almost always the
        // off-by-one bug (header row instead of data row).
        if (preg_match_all('/(?:'.self::SHEET_QUALIFIER.')?\$?([A-Z]+)\$?(\d+)/i', $formula, $matches, PREG_SET_ORDER) === false) {
            return 'A non-numeric value was used in arithmetic.';
        }

        $offenders = [];
        foreach ($matches as $m) {
            $sheetName = $m[1] !== ''
                ? ($this->sheetNames[mb_strtolower($this->cleanSheetName($m[1]))] ?? $this->cleanSheetName($m[1]))
                : $sheet;
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
    /**
     * @param  string|null  $cause  Why a #NAME? arose in THIS formula: 'syntax'
     *   or 'function'. Null for an error that was computed, or inherited from
     *   another cell.
     * @param  string  $detail  The syntax fault, or the unknown function's name.
     */
    public function __construct(
        public string $errorCode,
        public ?string $cause = null,
        public string $detail = '',
    ) {
        parent::__construct($errorCode);
    }

    public static function syntax(string $detail): self
    {
        return new self('#NAME?', 'syntax', $detail);
    }
}
