<?php

declare(strict_types=1);

namespace HolySheet\Reader\Ods;

/**
 * An OpenDocument `table:formula` -> the A1 syntax the xlsx reader returns.
 *
 * `of:=SUM([.A1:.B2];[$'Other Sheet'.C3])` becomes `SUM(A1:B2,'Other Sheet'!C3)`.
 *
 * Translated:
 *   - the grammar prefix and the leading `=`: `of:` (OpenFormula) and `oooc:`
 *     (OpenOffice 1.x-2.x) are translated; `msoxl:` is already Excel syntax and
 *     is only unwrapped;
 *   - references: `[.A1]` -> `A1`, `[.A1:.B2]` -> `A1:B2`, `[Sheet.A1]` ->
 *     `Sheet!A1`, `[$'My Sheet'.$A$1]` -> `'My Sheet'!$A$1` (the `$` that marks
 *     an absolute SHEET has no A1 equivalent and is dropped), whole columns and
 *     rows, and a range across sheets -> `Sheet1:Sheet3!A1:B2`;
 *   - `;` between arguments -> `,`;
 *   - inline arrays: `{1;2|3;4}` -> `{1,2;3,4}` (OpenFormula separates columns
 *     with `;` and rows with `|`, Excel with `,` and `;`).
 *
 * Not translated, and passed through as written:
 *   - function names. OpenFormula and Excel agree on the common ones; where they
 *     differ (`COM.MICROSOFT.IFS` against `_xlfn.IFS`) the name is left alone;
 *   - the `~` union and `!` intersection operators, whose Excel spellings (`,`
 *     inside parentheses, a space) are not a character-for-character swap;
 *   - references to other files, and anything else inside brackets that does not
 *     parse as a reference, which keeps its brackets (an error reference reads
 *     as `#REF!`).
 * Nothing inside a string literal is ever touched.
 */
final class OdsFormula
{
    public static function toA1(string $formula): string
    {
        $grammar = 'of';
        if (preg_match('/^(of|oooc|msoxl):/i', $formula, $m) === 1) {
            $grammar = strtolower($m[1]);
            $formula = substr($formula, strlen($m[0]));
        }
        if (str_starts_with($formula, '=')) {
            $formula = substr($formula, 1);
        }

        return $grammar === 'msoxl' ? $formula : self::translate($formula);
    }

    private static function translate(string $s): string
    {
        $out = '';
        $length = strlen($s);
        $arrayDepth = 0;

        for ($i = 0; $i < $length; $i++) {
            $c = $s[$i];

            if ($c === '"') {
                $end = $i + 1;
                while ($end < $length) {
                    if ($s[$end] === '"') {
                        if ($end + 1 < $length && $s[$end + 1] === '"') {
                            $end += 2;
                            continue;
                        }
                        break;
                    }
                    $end++;
                }
                $out .= substr($s, $i, $end - $i + 1);
                $i = $end;
                continue;
            }

            if ($c === '[') {
                $end = $i + 1;
                $quoted = false;
                while ($end < $length && ($quoted || $s[$end] !== ']')) {
                    if ($s[$end] === "'") $quoted = !$quoted;
                    $end++;
                }
                $out .= self::reference(substr($s, $i + 1, $end - $i - 1));
                $i = $end;
                continue;
            }

            if ($c === '{') {
                $arrayDepth++;
            } elseif ($c === '}') {
                $arrayDepth = max(0, $arrayDepth - 1);
            } elseif ($c === ';') {
                $c = ',';
            } elseif ($c === '|' && $arrayDepth > 0) {
                $c = ';';
            }
            $out .= $c;
        }

        return $out;
    }

    /** The inside of one `[...]`. */
    private static function reference(string $ref): string
    {
        $parts = [];
        $start = 0;
        $quoted = false;
        for ($i = 0, $length = strlen($ref); $i < $length; $i++) {
            if ($ref[$i] === "'") {
                $quoted = !$quoted;
            } elseif ($ref[$i] === ':' && !$quoted) {
                $parts[] = substr($ref, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($ref, $start);

        $parsed = count($parts) <= 2 ? array_map(self::part(...), $parts) : [null];
        if (in_array(null, $parsed, true)) {
            return str_contains($ref, '#REF!') ? '#REF!' : '['.$ref.']';
        }

        [$sheet, $address] = $parsed[0];
        if (count($parsed) === 1) {
            return self::sheetPrefix($sheet).$address;
        }

        [$sheet2, $address2] = $parsed[1];
        if ($sheet2 === null || $sheet2 === $sheet) {
            return self::sheetPrefix($sheet).$address.':'.$address2;
        }
        if ($sheet === null) {
            return $address.':'.self::sheetPrefix($sheet2).$address2;
        }

        // A range across sheets: one prefix naming both, quoted as a whole.
        $quote = self::needsQuotes($sheet) || self::needsQuotes($sheet2);
        $both = $quote
            ? "'".str_replace("'", "''", $sheet).':'.str_replace("'", "''", $sheet2)."'"
            : $sheet.':'.$sheet2;

        return $both.'!'.$address.':'.$address2;
    }

    /**
     * One end of a reference: `$'Sheet'.$A$1`, `Sheet.A1`, `.A1`, `.A`, `.1`.
     *
     * @return array{0:?string,1:string}|null  [sheet name unquoted, address]
     */
    private static function part(string $part): ?array
    {
        $length = strlen($part);
        $i = 0;
        if ($i < $length && $part[$i] === '$') $i++;

        $sheet = null;
        if ($i < $length && $part[$i] === "'") {
            $sheet = '';
            $i++;
            while (true) {
                if ($i >= $length) return null;
                if ($part[$i] === "'") {
                    if ($i + 1 < $length && $part[$i + 1] === "'") {
                        $sheet .= "'";
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $sheet .= $part[$i++];
            }
            if ($i >= $length || $part[$i] !== '.') return null;
            $i++;
        } else {
            $dot = strpos($part, '.', $i);
            if ($dot === false) return null;
            if ($dot > $i) $sheet = substr($part, $i, $dot - $i);
            $i = $dot + 1;
        }

        $address = substr($part, $i);
        if (preg_match('/^(\$?[A-Za-z]{1,3}\$?[0-9]+|\$?[A-Za-z]{1,3}|\$?[0-9]+)$/', $address) !== 1) {
            return null;
        }

        return [$sheet, $address];
    }

    private static function sheetPrefix(?string $sheet): string
    {
        if ($sheet === null) return '';

        return (self::needsQuotes($sheet) ? "'".str_replace("'", "''", $sheet)."'" : $sheet).'!';
    }

    /**
     * Whether Excel needs the sheet name quoted. Quoting a name that did not
     * need it is still valid, so anything outside plain ASCII identifiers is
     * quoted, and so is a name that reads as a cell address.
     */
    private static function needsQuotes(string $sheet): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $sheet) !== 1
            || preg_match('/^[A-Za-z]{1,3}[0-9]+$/', $sheet) === 1
            || preg_match('/^[Rr]([0-9]+)?[Cc]([0-9]+)?$/', $sheet) === 1;
    }
}
