<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Exceptions\ArchiveRefused;
use HolySheet\Exceptions\ReadLimitExceeded;
use HolySheet\Workbook\CellAddress;
use SimpleXMLElement;

/**
 * How big a spreadsheet a read will expand, beyond the archive guard's byte
 * limits. The archive guard bounds the bytes of each part; these bound what
 * those bytes turn into, which is where a read spends its memory: a 64 MiB
 * part can hold millions of `<c>` elements, and one ODS cell can repeat
 * across a whole sheet.
 *
 * - `maxRows`, `maxColumns`: per sheet, the highest row and column it uses (a
 *   cell, or a `<row>` / `<col>` element). Default: Excel's own sheet limits.
 * - `maxCells`: the whole workbook, counted sheet by sheet.
 * - `maxSharedStrings`: entries in xl/sharedStrings.xml.
 *
 * Every check runs before the thing it bounds is built: a worksheet is counted
 * from its parsed part before one Cell exists, the shared-strings table before
 * one string is rendered, an ODS repeat before it is expanded. A refusal is a
 * `ReadLimitExceeded` naming the limit, its value and the count.
 *
 * Configured like the archive limits: positive integers keyed by name, and an
 * unknown key or any other value refuses with `ArchiveRefused('read_limits')`.
 * The defaults and their measurements are in the 2.13.0 CHANGELOG entry.
 *
 * @internal one instance per read; it carries the running cell count
 */
final class ReadLimits
{
    public const DEFAULTS = [
        'maxRows' => 1048576,
        'maxColumns' => 16384,
        'maxCells' => 20000000,
        'maxSharedStrings' => 20000000,
    ];

    private int $cells = 0;

    /** @var array<string,int> column letters => index, bounded so a hostile part cannot grow it */
    private array $letterCache = [];

    /** @param  array<string,int>  $limits */
    private function __construct(private readonly array $limits) {}

    /** @param  array<mixed>  $limits */
    public static function from(array $limits = []): self
    {
        foreach ($limits as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS) || ! is_int($value) || $value < 1) {
                throw new ArchiveRefused('read_limits');
            }
        }

        return new self($limits + self::DEFAULTS);
    }

    public function max(string $limit): int
    {
        return $this->limits[$limit];
    }

    public function sharedStrings(int $count): void
    {
        self::check('maxSharedStrings', $this->limits['maxSharedStrings'], $count);
    }

    /** One sheet's extent and cell count, in the order the checks are made: rows, columns, cells. */
    public function sheet(int $rows, int $columns, int $cells): void
    {
        self::check('maxRows', $this->limits['maxRows'], $rows);
        self::check('maxColumns', $this->limits['maxColumns'], $columns);
        $this->cells += $cells;
        self::check('maxCells', $this->limits['maxCells'], $this->cells);
    }

    /**
     * Count a parsed worksheet: the highest row a `<row r>` or a cell uses, the
     * highest column a cell or a `<col max>` uses, and every `<c>` element.
     * Nothing is built; this walks what PartXml already parsed.
     */
    public function worksheet(SimpleXMLElement $xml): void
    {
        $rows = $columns = $cells = 0;
        if (isset($xml->cols) && $xml->cols->col) {
            foreach ($xml->cols->col as $col) {
                $columns = max($columns, self::number((string) $col['max']));
            }
        }
        if (isset($xml->sheetData) && $xml->sheetData->row) {
            foreach ($xml->sheetData->row as $row) {
                $rows = max($rows, self::number((string) $row['r']));
                if (! $row->c) {
                    continue;
                }
                foreach ($row->c as $c) {
                    $cells++;
                    $r = (string) $c['r'];
                    // Fast path for the canonical form every writer emits; the
                    // same answer address() gives, without a regex per cell.
                    $n = strspn($r, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
                    $d = strspn($r, '0123456789', $n);
                    if ($n >= 1 && $n <= 7 && $d >= 1 && $d <= 15 && $n + $d === strlen($r)) {
                        $letters = substr($r, 0, $n);
                        $col = $this->letterCache[$letters] ?? null;
                        if ($col === null) {
                            $col = CellAddress::index($letters);
                            if (count($this->letterCache) < 20000) {
                                $this->letterCache[$letters] = $col;
                            }
                        }
                        $columns = max($columns, $col + 1);
                        $rows = max($rows, (int) substr($r, $n));

                        continue;
                    }
                    $at = self::address($r);
                    if ($at !== null) {
                        $columns = max($columns, $at[0] + 1);
                        $rows = max($rows, $at[1]);
                    }
                }
            }
        }
        $this->sheet($rows, $columns, $cells);
    }

    /**
     * `[column index, row]` of an A1 address, or null. Bounded to seven letters
     * and fifteen digits, so the numbers stay exact integers in all three
     * ports; an address outside that is not one any sheet has, and is not
     * counted toward rows or columns (it still counts as a cell).
     *
     * @return array{0:int,1:int}|null
     */
    public static function address(string $address): ?array
    {
        if (preg_match('/^([A-Z]{1,7})([0-9]{1,15})$/', strtoupper(trim($address)), $m) !== 1) {
            return null;
        }

        return [CellAddress::index($m[1]), (int) $m[2]];
    }

    /**
     * A row or column number from an attribute: digits only, after trimming.
     * Sixteen digits or more is 10^15, past every limit and still exact in all
     * three ports; anything that is not digits counts as 0, as it uses no row.
     */
    private static function number(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^[0-9]+$/', $value) !== 1) {
            return 0;
        }

        return strlen(ltrim($value, '0')) > 15 ? 1000000000000000 : (int) $value;
    }

    private static function check(string $limit, int $max, int $actual): void
    {
        if ($actual > $max) {
            throw new ReadLimitExceeded($limit, $max, $actual);
        }
    }
}
