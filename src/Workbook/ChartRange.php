<?php

declare(strict_types=1);

namespace HolySheet\Workbook;

/**
 * A cell range a chart reads from: "B5:B7", "B5", "'Q3 Revenue'!B5:B7" or
 * "Data!B5:B7". Resolved against the chart's own sheet when no sheet is named.
 *
 * Written into chart XML as an ABSOLUTE, quoted reference
 * ('Q3 Revenue'!$B$5:$B$7), which is the form Excel writes and the one every
 * reader resolves without guessing at sheet-name quoting.
 */
final class ChartRange
{
    /**
     * @param  int  $c1  0-based first column
     * @param  int  $r1  1-based first row
     * @param  int  $c2  0-based last column
     * @param  int  $r2  1-based last row
     */
    public function __construct(
        public readonly string $sheet,
        public readonly int $c1,
        public readonly int $r1,
        public readonly int $c2,
        public readonly int $r2,
    ) {}

    /** Parse a range string, or null when it is not one. */
    public static function parse(mixed $ref, string $defaultSheet): ?self
    {
        if (! is_string($ref)) {
            return null;
        }
        $ref = trim($ref);
        $sheet = $defaultSheet;
        $bang = strrpos($ref, '!');
        if ($bang !== false) {
            $sheetPart = substr($ref, 0, $bang);
            $ref = substr($ref, $bang + 1);
            if (strlen($sheetPart) >= 2 && $sheetPart[0] === "'" && str_ends_with($sheetPart, "'")) {
                $sheetPart = str_replace("''", "'", substr($sheetPart, 1, -1));
            }
            if ($sheetPart === '') {
                return null;
            }
            $sheet = $sheetPart;
        }
        $ref = str_replace('$', '', $ref);
        $ends = explode(':', $ref);
        if (count($ends) > 2) {
            return null;
        }
        $a = CellAddress::parse($ends[0]);
        $b = CellAddress::parse($ends[1] ?? $ends[0]);
        if ($a === null || $b === null || $a[1] < 1 || $b[1] < 1) {
            return null;
        }

        return new self($sheet, min($a[0], $b[0]), min($a[1], $b[1]), max($a[0], $b[0]), max($a[1], $b[1]));
    }

    /** 'Q3 Revenue'!$B$5:$B$7 */
    public function formula(): string
    {
        $from = '$'.CellAddress::letter($this->c1).'$'.$this->r1;
        $to = '$'.CellAddress::letter($this->c2).'$'.$this->r2;

        return "'".str_replace("'", "''", $this->sheet)."'!".$from.($from === $to ? '' : ':'.$to);
    }

    /** "B5:B7" on its own sheet, "'Data'!B5:B7" on another: the schema's form. */
    public function toSchema(string $ownSheet): string
    {
        $from = CellAddress::letter($this->c1).$this->r1;
        $to = CellAddress::letter($this->c2).$this->r2;
        $ref = $from === $to ? $from : $from.':'.$to;

        return $this->sheet === $ownSheet ? $ref : "'".str_replace("'", "''", $this->sheet)."'!".$ref;
    }

    /** @return list<string> A1 addresses, row by row. */
    public function addresses(): array
    {
        $out = [];
        for ($r = $this->r1; $r <= $this->r2; $r++) {
            for ($c = $this->c1; $c <= $this->c2; $c++) {
                $out[] = CellAddress::letter($c).$r;
            }
        }

        return $out;
    }
}
