<?php

declare(strict_types=1);

namespace HolySheet\Workbook;

/**
 * A worksheet inside a Workbook.
 */
final class Sheet
{
    /**
     * @param  array<string,Cell>  $cells  A1-keyed sparse map
     * @param  list<MergedRegion>  $mergedRegions
     * @param  array<int,float>  $columnWidths  0-based col index → pixels
     * @param  array<int,float>  $rowHeights  1-based row number → pixels
     * @param  array{orientation: ?string, fitToWidth: bool, fitToPage: bool, footer: ?array{left: string, center: string, right: string}}|null  $print
     */
    public function __construct(
        public readonly string $name,
        public readonly array $cells = [],
        public readonly array $mergedRegions = [],
        public readonly array $columnWidths = [],
        public readonly int $frozenRows = 0,
        public readonly int $frozenCols = 0,
        public readonly array $rowHeights = [],
        public readonly ?array $print = null,
        /**
         * Native charts, normalised: list of {type, title, categories: ?ChartRange,
         * series: list<{name: ?string, values: ChartRange, color: ?string}>,
         * from: [col, row], to: [col, row] (both 0-based), legend: ?bool,
         * dataLabels: bool, format: ?string}.
         *
         * @var list<array<string, mixed>>
         */
        public readonly array $charts = [],
        /** 'visible' | 'hidden' | 'veryHidden' (Excel's sheet state; ods table:display="false" reads as hidden) */
        public readonly string $state = 'visible',
        /** @var list<int> 1-based row numbers, ascending */
        public readonly array $hiddenRows = [],
        /** @var list<int> 0-based column indexes, ascending */
        public readonly array $hiddenColumns = [],
        /**
         * Excel tables (read only; the writer does not author them).
         *
         * @var list<array{name:string,ref:string,headerRowCount:int}>
         */
        public readonly array $tables = [],
    ) {}

    /** @param list<array<string, mixed>> $charts */
    public function withCharts(array $charts): self
    {
        return $this->with(['charts' => $charts]);
    }

    /**
     * A copy with some constructor arguments replaced.
     *
     * @param  array<string,mixed>  $changes  keyed by constructor parameter name
     */
    public function with(array $changes): self
    {
        return new self(...array_merge([
            'name' => $this->name, 'cells' => $this->cells, 'mergedRegions' => $this->mergedRegions,
            'columnWidths' => $this->columnWidths, 'frozenRows' => $this->frozenRows, 'frozenCols' => $this->frozenCols,
            'rowHeights' => $this->rowHeights, 'print' => $this->print, 'charts' => $this->charts, 'state' => $this->state,
            'hiddenRows' => $this->hiddenRows, 'hiddenColumns' => $this->hiddenColumns, 'tables' => $this->tables,
        ], $changes));
    }

    /**
     * The last row and the last 0-based column anything occupies (cells and
     * merged regions), or [0, -1] for an empty sheet.
     *
     * @return array{0:int,1:int}
     */
    public function extent(): array
    {
        $maxRow = 0;
        $maxCol = -1;
        $touch = static function (string $a) use (&$maxRow, &$maxCol): void {
            if (preg_match('/^([A-Z]+)(\d+)$/', $a, $m) === 1) {
                $maxRow = max($maxRow, (int) $m[2]);
                $maxCol = max($maxCol, CellAddress::index($m[1]));
            }
        };
        foreach (array_keys($this->cells) as $a) {
            $touch((string) $a);
        }
        foreach ($this->mergedRegions as $m) {
            $touch($m->start);
            $touch($m->end);
        }

        return [$maxRow, $maxCol];
    }

    /** @return array<int,array<string,Cell>>  rowIndex => [colLetter => Cell] */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->cells as $address => $cell) {
            preg_match('/^([A-Z]+)(\d+)$/', $address, $m);
            if (!$m) continue;
            $rows[(int) $m[2]][$m[1]] = $cell;
        }
        ksort($rows);
        return $rows;
    }

    public function hasComments(): bool
    {
        foreach ($this->cells as $cell) {
            if ($cell->comment !== null) return true;
        }
        return false;
    }

    /** @return list<array{address:string,comment:CellComment}> */
    public function comments(): array
    {
        $out = [];
        foreach ($this->cells as $cell) {
            if ($cell->comment !== null) {
                $out[] = ['address' => $cell->address, 'comment' => $cell->comment];
            }
        }
        return $out;
    }
}
