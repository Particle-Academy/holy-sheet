<?php

declare(strict_types=1);

use HolySheet\Agent;

/**
 * Cells within a row are written in ascending column INDEX: A..Z, AA, AB, ...
 *
 * OOXML requires it (CT_Row's cells are in column order), and the old
 * lexicographic order -- A, AA, AB, ..., B, C -- loses data in Google Sheets:
 * on 2026-10-28 a holy-sheet workbook whose row 1 was written A1, AA1..AE1,
 * B1..Z1 came back from a Sheets import/export with only A1 in that row.
 */
function wideOrderSheetXml(array $schema): string
{
    $path = tempnam(sys_get_temp_dir(), 'wide').'.xlsx';
    Agent::write($schema, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($path);

    return $xml;
}

/** @return list<string>  the column letters of row $row, in document order */
function wideOrderColumns(string $xml, int $row): array
{
    preg_match_all('/<c r="([A-Z]+)'.$row.'"/', $xml, $m);

    return $m[1];
}

/** 32 columns, A..AF, inserted in a scrambled order so insertion order proves nothing. */
function wideOrderCells(): array
{
    $cells = [];
    foreach (array_reverse(range(0, 31)) as $i) {
        $cells[HolySheet\Workbook\CellAddress::letter($i).'1'] = $i;
    }
    // A sparse row: Z, AA, B and AZ, inserted backwards.
    $cells['AZ2'] = 'az';
    $cells['B2'] = 'b';
    $cells['AA2'] = 'aa';
    $cells['Z2'] = 'z';

    return $cells;
}

it('writes the cells of a row wider than 26 columns in column-index order', function () {
    $xml = wideOrderSheetXml(['sheets' => [['name' => 'Wide', 'cells' => wideOrderCells()]]]);

    $expected = array_map(HolySheet\Workbook\CellAddress::letter(...), range(0, 31));
    expect(wideOrderColumns($xml, 1))->toBe($expected)
        ->and(wideOrderColumns($xml, 2))->toBe(['B', 'Z', 'AA', 'AZ']);
});

it('round-trips every cell of a wide sheet', function () {
    $cells = wideOrderCells();
    $read = Agent::read(Agent::toBytes(['sheets' => [['name' => 'Wide', 'cells' => $cells]]]));

    $got = [];
    foreach ($read['sheets'][0]['cells'] as $address => $cell) {
        $got[$address] = is_array($cell) ? ($cell['value'] ?? null) : $cell;
    }
    ksort($got);
    ksort($cells);
    expect($got)->toBe($cells);
});
