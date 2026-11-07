<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Archive\SourceArchive;
use HolySheet\Exceptions\ArchiveRefused;
use HolySheet\Exceptions\ReadLimitExceeded;
use HolySheet\Archive\PartXml;
use HolySheet\Workbook\CellAddress;

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
 * Since 2.14.0 the same `limits` option also carries the archive guard's five
 * limits (ARCHIVE_DEFAULTS), so every limit a read enforces is one setting:
 * positive integers keyed by name. An unknown key or a bad read limit refuses
 * with `ArchiveRefused('read_limits')`, a bad archive limit with
 * `ArchiveRefused('archive_limits')`, each naming the setting. The read
 * defaults and their measurements are in the 2.13.0 CHANGELOG entry; the
 * measured ceilings per port are in the README's "Large workbooks".
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

    /**
     * The archive guard's limits, which the same `limits` option sets (since
     * 2.14.0): `maxArchiveBytes`, `maxEntries`, `maxPartBytes`,
     * `maxExpandedBytes`, `maxCompressionRatio`.
     */
    public const ARCHIVE_DEFAULTS = SourceArchive::DEFAULTS;

    private int $cells = 0;

    /** @var array<string,int> column letters => index, bounded so a hostile part cannot grow it */
    private array $letterCache = [];

    /**
     * @param  array<string,int>  $limits
     * @param  array<string,int>  $archive
     */
    private function __construct(private readonly array $limits, private readonly array $archive) {}

    /**
     * Any of the nine limits, by name. A read limit that is not a positive
     * integer, or a name that is no limit, is `ArchiveRefused('read_limits')`;
     * a bad archive limit is `ArchiveRefused('archive_limits')`. The message
     * names the setting. Every key is checked before anything is opened.
     *
     * @param  array<mixed>  $limits
     */
    public static function from(array $limits = []): self
    {
        $read = $archive = [];
        foreach ($limits as $key => $value) {
            if (array_key_exists($key, self::ARCHIVE_DEFAULTS)) {
                $archive[$key] = $value;

                continue;
            }
            if (! array_key_exists($key, self::DEFAULTS) || ! is_int($value) || $value < 1) {
                throw ArchiveRefused::badSetting('read_limits', $key, array_key_exists($key, self::DEFAULTS));
            }
            $read[$key] = $value;
        }

        return new self($read + self::DEFAULTS, SourceArchive::resolveLimits($archive));
    }

    public function max(string $limit): int
    {
        return $this->limits[$limit] ?? $this->archive[$limit];
    }

    /** @return array<string,int> the archive limits, resolved over their defaults */
    public function archive(): array
    {
        return $this->archive;
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
     * Count a worksheet part BEFORE it is parsed: the highest row a `<row r>`
     * or a cell uses, the highest column a cell or a `<col max>` uses, and
     * every `<c>` element. It streams the part (PartXml::stream), so it holds
     * nothing: until 2.14.0 this walked the parsed tree, and by then the tree
     * was built, so a limit could refuse a 10,000,000-cell sheet only after
     * libxml had spent gigabytes on it (and PHP's memory_limit, which does not
     * see libxml's allocations, never stopped it).
     *
     * It matches what the parse reads: the first unprefixed `cols` and
     * `sheetData` under the root, their unprefixed `col` / `row` children, and
     * a row's unprefixed `c` children, as SimpleXML property access does. A
     * part that turns out malformed is counted up to the error, so a limit
     * still refuses it.
     */
    public function worksheetPart(string $xml): void
    {
        $rows = $columns = $cells = 0;
        $seenCols = $seenSheetData = false;
        $in = null;      // 'cols' | 'sheetData' | null: the depth-1 element we are inside
        $inRow = false;
        PartXml::stream($xml, 3, function (\XMLReader $r) use (&$rows, &$columns, &$cells, &$seenCols, &$seenSheetData, &$in, &$inRow): void {
            $depth = $r->depth;
            $mine = $r->prefix === '';
            $name = $r->localName;
            if ($depth === 1) {
                $in = null;
                if ($mine && $name === 'cols' && ! $seenCols) {
                    [$in, $seenCols] = ['cols', true];
                } elseif ($mine && $name === 'sheetData' && ! $seenSheetData) {
                    [$in, $seenSheetData] = ['sheetData', true];
                }

                return;
            }
            if ($depth === 2) {
                $inRow = false;
                if ($in === 'cols' && $mine && $name === 'col') {
                    $columns = max($columns, self::number((string) $r->getAttribute('max')));
                } elseif ($in === 'sheetData' && $mine && $name === 'row') {
                    $rows = max($rows, self::number((string) $r->getAttribute('r')));
                    $inRow = true;
                }

                return;
            }
            if ($depth === 3 && $inRow && $mine && $name === 'c') {
                // Refused the moment the workbook passes maxCells, rather than
                // after streaming the rest: the message already says "at least".
                if (++$cells + $this->cells > $this->limits['maxCells']) {
                    throw new ReadLimitExceeded('maxCells', $this->limits['maxCells'], $cells + $this->cells);
                }
                [$columns, $rows] = $this->cellExtent((string) $r->getAttribute('r'), $columns, $rows);
            }
        });
        $this->sheet($rows, $columns, $cells);
    }

    /**
     * Count a shared-strings part BEFORE it is parsed: its root's unprefixed
     * `si` children, streamed, so a table too big to parse is refused without
     * being parsed. A malformed part is counted up to the error.
     */
    public function sharedStringsPart(string $xml): void
    {
        $count = 0;
        PartXml::stream($xml, 1, function (\XMLReader $r) use (&$count): void {
            if ($r->depth === 1 && $r->prefix === '' && $r->localName === 'si') {
                $count++;
            }
        });
        $this->sharedStrings($count);
    }

    /**
     * The extent one cell address adds: `[columns, rows]` after it.
     *
     * @return array{0:int,1:int}
     */
    private function cellExtent(string $r, int $columns, int $rows): array
    {
        // Fast path for the canonical form every writer emits; the same answer
        // address() gives, without a regex per cell.
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

            return [max($columns, $col + 1), max($rows, (int) substr($r, $n))];
        }
        $at = self::address($r);

        return $at === null ? [$columns, $rows] : [max($columns, $at[0] + 1), max($rows, $at[1])];
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
