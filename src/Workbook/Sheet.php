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
     * @param  array{orientation: ?string, fitToWidth: bool, footer: ?array{left: string, center: string, right: string}}|null  $print
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
    ) {}

    /** @param list<array<string, mixed>> $charts */
    public function withCharts(array $charts): self
    {
        return new self($this->name, $this->cells, $this->mergedRegions, $this->columnWidths,
            $this->frozenRows, $this->frozenCols, $this->rowHeights, $this->print, $charts);
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
