<?php

declare(strict_types=1);

namespace HolySheet\Workbook;

/**
 * A worksheet inside a Workbook.
 *
 * Cells are stored as a sparse map keyed by A1 address, matching
 * fancy-sheets' data model and Excel's worksheet layout. The writer
 * walks cells in row-then-column order (driven by the rows() helper).
 */
final class Sheet
{
    /**
     * @param  array<string,Cell>  $cells  A1-keyed sparse map
     * @param  list<array{start:string,end:string}>  $mergedRegions
     * @param  array<int,float>  $columnWidths
     */
    public function __construct(
        public readonly string $name,
        public readonly array $cells = [],
        public readonly array $mergedRegions = [],
        public readonly array $columnWidths = [],
        public readonly int $frozenRows = 0,
        public readonly int $frozenCols = 0,
    ) {}

    /**
     * Group cells by row index for sequential xlsx writing.
     *
     * @return array<int,array<string,Cell>>  rowIndex => [colLetter => Cell]
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->cells as $address => $cell) {
            preg_match('/^([A-Z]+)(\d+)$/', $address, $m);
            if (!$m) {
                continue; // Validator should have caught this, but fail closed.
            }
            $rows[(int) $m[2]][$m[1]] = $cell;
        }
        ksort($rows);
        return $rows;
    }
}
