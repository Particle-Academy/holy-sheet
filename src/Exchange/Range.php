<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * Selectors and the range grammar (SPEC 8).
 *
 * - `"Q4!A1:D20"`, `"'Q4 2026'!A1:D20"`, `{sheet, range}` -> a range
 * - `"Q4"`, `"'Q4 2026'"`, `{sheet}` -> the sheet's used range. A string
 *   without `!` is ALWAYS a sheet, never a cell.
 * - `{name}` -> a defined name; `{table}` -> an Excel table
 *
 * Whole-row and whole-column ranges are not accepted; a reversed range
 * normalises to top-left / bottom-right.
 */
final class Range
{
    public const MAX_COL = 16384;

    public const MAX_ROW = 1048576;

    private const BARE_SHEET = '/^[A-Za-z_][A-Za-z0-9_.]*$/';

    private const NAME = '/^[A-Za-z_][A-Za-z0-9_.]{0,254}$/';

    public static function colLetters(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $r = ($n - 1) % 26;
            $s = \chr(65 + $r).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    public static function addr(int $col, int $row): string
    {
        return self::colLetters($col).$row;
    }

    /** @return array{ok:true,column:int,row:int}|array{ok:false,code:string} */
    public static function parseCell(mixed $ref): array
    {
        $bad = ['ok' => false, 'code' => 'selector_invalid'];
        if (! \is_string($ref) || preg_match('/^\$?([A-Za-z]{1,3})\$?([0-9]+)$/', $ref, $m) !== 1) {
            return $bad;
        }
        if ($m[2][0] === '0') {
            return $bad;
        }
        $col = 0;
        foreach (str_split(strtoupper($m[1])) as $ch) {
            $col = $col * 26 + (\ord($ch) - 64);
        }
        if (\strlen($m[2]) > 7) {
            return $bad;
        }
        $row = (int) $m[2];
        if ($col < 1 || $col > self::MAX_COL || $row < 1 || $row > self::MAX_ROW) {
            return $bad;
        }

        return ['ok' => true, 'column' => $col, 'row' => $row];
    }

    /** @return array{start:string,end:string}|null */
    public static function parseRange(string $s): ?array
    {
        $parts = explode(':', $s);
        if (\count($parts) > 2) {
            return null;
        }
        $a = self::parseCell($parts[0]);
        $b = self::parseCell($parts[1] ?? $parts[0]);
        if (! $a['ok'] || ! $b['ok']) {
            return null;
        }

        return [
            'start' => self::addr(min($a['column'], $b['column']), min($a['row'], $b['row'])),
            'end' => self::addr(max($a['column'], $b['column']), max($a['row'], $b['row'])),
        ];
    }

    /**
     * Split `Sheet!RANGE`, `'Quoted'!RANGE` or a bare sheet.
     *
     * @return array{sheet:string,range:?string}|null
     */
    public static function splitSheet(string $s): ?array
    {
        if (str_starts_with($s, "'")) {
            $i = 1;
            $name = '';
            $len = \strlen($s);
            for (;;) {
                if ($i >= $len) {
                    return null;
                }
                if ($s[$i] === "'") {
                    if ($i + 1 < $len && $s[$i + 1] === "'") {
                        $name .= "'";
                        $i += 2;

                        continue;
                    }
                    break;
                }
                $name .= $s[$i];
                $i++;
            }
            $rest = substr($s, $i + 1);
            if ($rest === '') {
                return ['sheet' => $name, 'range' => null];
            }
            if (! str_starts_with($rest, '!')) {
                return null;
            }

            return ['sheet' => $name, 'range' => substr($rest, 1)];
        }
        $bang = strrpos($s, '!');
        if ($bang === false) {
            return ['sheet' => $s, 'range' => null];
        }
        $sheet = substr($s, 0, $bang);
        if (preg_match(self::BARE_SHEET, $sheet) !== 1) {
            return null;
        }

        return ['sheet' => $sheet, 'range' => substr($s, $bang + 1)];
    }

    public static function sheetNameOk(string $n): bool
    {
        $len = self::utf16Length($n);

        return $len >= 1 && $len <= 31
            && preg_match('/[\/\\\\?*\[\]:]/', $n) !== 1
            && ! str_starts_with($n, "'") && ! str_ends_with($n, "'");
    }

    /** Length in UTF-16 code units, as JavaScript and Excel count a sheet name. */
    public static function utf16Length(string $s): int
    {
        if (preg_match('//u', $s) !== 1) {
            return \strlen($s);
        }
        $n = 0;
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $n += \strlen($ch) === 4 ? 2 : 1;
        }

        return $n;
    }

    /**
     * `parseSelector(selector, options?)`. `headerRows` may be in the selector
     * or in the options, never both: ambiguous intent is refused.
     *
     * @return array{ok:true,selector:array<string,mixed>}|array{ok:false,code:string}
     */
    public static function parseSelector(mixed $sel, mixed $options = null): array
    {
        $bad = ['ok' => false, 'code' => 'selector_invalid'];
        $optRows = \is_array($options) && \array_key_exists('headerRows', $options) ? $options['headerRows'] : null;

        if (\is_string($sel)) {
            $sp = self::splitSheet($sel);
            if ($sp === null || ! self::sheetNameOk($sp['sheet'])) {
                return $bad;
            }
            if ($sp['range'] === null) {
                $out = ['kind' => 'sheet', 'sheet' => $sp['sheet']];
            } else {
                $r = self::parseRange($sp['range']);
                if ($r === null) {
                    return $bad;
                }
                $out = ['kind' => 'range', 'sheet' => $sp['sheet']] + $r;
            }
        } elseif (\is_array($sel) && $sel !== [] && ! array_is_list($sel)) {
            $kinds = array_values(array_filter(['sheet', 'name', 'table'], static fn (string $k): bool => \array_key_exists($k, $sel)));
            if (\count($kinds) !== 1) {
                return $bad;
            }
            $kind = $kinds[0];
            $allowed = $kind === 'sheet' ? ['sheet', 'range', 'headerRows'] : [$kind, 'headerRows'];
            foreach (array_keys($sel) as $k) {
                if (! \in_array((string) $k, $allowed, true)) {
                    return $bad;
                }
            }
            if (\array_key_exists('headerRows', $sel) && ! (Json::isInt($sel['headerRows']) && $sel['headerRows'] >= 0)) {
                return $bad;
            }
            if ($kind === 'sheet') {
                if (! \is_string($sel['sheet']) || ! self::sheetNameOk($sel['sheet'])) {
                    return $bad;
                }
                if (\array_key_exists('range', $sel)) {
                    if (! \is_string($sel['range']) || str_contains($sel['range'], '!')) {
                        return $bad;
                    }
                    $r = self::parseRange($sel['range']);
                    if ($r === null) {
                        return $bad;
                    }
                    $out = ['kind' => 'range', 'sheet' => $sel['sheet']] + $r;
                } else {
                    $out = ['kind' => 'sheet', 'sheet' => $sel['sheet']];
                }
            } else {
                $n = $sel[$kind];
                if (! \is_string($n) || preg_match(self::NAME, $n) !== 1 || self::parseCell($n)['ok'] || preg_match('/^[rc]$/i', $n) === 1) {
                    return $bad;
                }
                $out = ['kind' => $kind, $kind => $n];
            }
            if (\array_key_exists('headerRows', $sel)) {
                if ($optRows !== null) {
                    return $bad;
                }
                $out['headerRows'] = (int) $sel['headerRows'];
            }
        } else {
            return $bad;
        }

        if ($optRows !== null && ! isset($out['headerRows'])) {
            $out['headerRows'] = $optRows;
        }

        return ['ok' => true, 'selector' => $out];
    }

    /**
     * `headerRowsFor({selector?, table?, frozenRows?, startRow, rowCount})`:
     * the selector's value, then an Excel table's header count, then
     * `frozenRows` when the range starts on row 1 (clamped), else 0.
     *
     * @param  array<string,mixed>  $i
     * @return array{ok:true,headerRows:int,from:string}|array{ok:false,code:string}
     */
    public static function headerRowsFor(array $i): array
    {
        $rowCount = (int) ($i['rowCount'] ?? 0);
        if (isset($i['selector'])) {
            if ($i['selector'] > $rowCount) {
                return ['ok' => false, 'code' => 'selector_invalid'];
            }

            return ['ok' => true, 'headerRows' => (int) $i['selector'], 'from' => 'selector'];
        }
        if (isset($i['table'])) {
            return ['ok' => true, 'headerRows' => (int) $i['table'], 'from' => 'table'];
        }
        if (! empty($i['frozenRows']) && ($i['startRow'] ?? null) === 1) {
            return ['ok' => true, 'headerRows' => min((int) $i['frozenRows'], $rowCount), 'from' => 'frozenRows'];
        }

        return ['ok' => true, 'headerRows' => 0, 'from' => 'default'];
    }
}
