<?php

declare(strict_types=1);

namespace HolySheet\Workbook;

/**
 * A1 cell address utilities — column-letter ↔ 0-based index conversion,
 * address parsing, and range expansion. Used by the writer (assembling
 * sheet xml), the reader (parsing sheetN.xml), the normalizer (column
 * placement during row-oriented mode), and the schema validator
 * (detecting whitespace in addresses).
 */
final class CellAddress
{
    /** Convert a 0-based column index to its Excel-style letter. */
    public static function letter(int $index): string
    {
        if ($index < 0) {
            throw new \InvalidArgumentException("[holy-sheet] column index must be ≥ 0, got {$index}");
        }
        $letters = '';
        $n = $index;
        do {
            $letters = chr(65 + ($n % 26)).$letters;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);
        return $letters;
    }

    /** Convert a column-letter string ("A", "Z", "AA", ...) to a 0-based index. */
    public static function index(string $letters): int
    {
        $letters = strtoupper(trim($letters));
        if ($letters === '' || preg_match('/^[A-Z]+$/', $letters) !== 1) {
            throw new \InvalidArgumentException("[holy-sheet] invalid column letters: '{$letters}'");
        }
        $idx = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $idx = $idx * 26 + (ord($letters[$i]) - 64);
        }
        return $idx - 1;
    }

    /**
     * Parse an A1 address into [columnIndex, rowNumber].
     * Row number is 1-based (matches Excel and the cell map keys).
     *
     * @return array{0:int,1:int}|null  null when the address is malformed
     */
    public static function parse(string $address): ?array
    {
        if (preg_match('/^([A-Z]+)(\d+)$/', strtoupper(trim($address)), $m) !== 1) {
            return null;
        }
        return [self::index($m[1]), (int) $m[2]];
    }
}
