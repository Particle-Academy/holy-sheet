<?php

declare(strict_types=1);

use HolySheet\Agent;

/** Helper: assert needle appears inside the string. Pest's toContain is iterable-only. */
function contained(string $haystack, string $needle): bool
{
    return str_contains($haystack, $needle);
}

it('produces a styles.xml part in every workbook', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'hs-').'.xlsx';
    Agent::write([
        'sheets' => [['name' => 'X', 'columns' => [['header' => 'A']], 'rows' => [[1]]]],
    ], $tmp);

    $zip = new ZipArchive();
    $zip->open($tmp);
    expect($zip->locateName('xl/styles.xml'))->not->toBeFalse();
    $styles = (string) $zip->getFromName('xl/styles.xml');
    foreach (['<styleSheet', '<fonts', '<fills', '<borders', '<cellXfs'] as $needle) {
        expect(contained($styles, $needle))->toBeTrue("missing {$needle} in styles.xml");
    }
    $zip->close();
    @unlink($tmp);
});

it('emits bold + colored header style when default theme is on', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'hs-').'.xlsx';
    Agent::write([
        'sheets' => [[
            'name' => 'X',
            'columns' => [['header' => 'A'], ['header' => 'B']],
            'rows' => [[1, 2]],
            'theme' => 'default',
        ]],
    ], $tmp);

    $zip = new ZipArchive();
    $zip->open($tmp);
    $styles = (string) $zip->getFromName('xl/styles.xml');
    expect(contained($styles, '<b/>'))->toBeTrue('expected bold font for header');

    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    expect((bool) preg_match('/<c r="A1" s="\d+"/', $sheet))->toBeTrue('header cell missing style index');
    $zip->close();
    @unlink($tmp);
});

it('applies currency number format on a currency column', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'hs-').'.xlsx';
    Agent::write([
        'sheets' => [[
            'name' => 'X',
            'columns' => [['header' => 'Amount', 'type' => 'currency', 'currency' => 'USD']],
            'rows' => [[1234.56]],
            'theme' => 'plain',
        ]],
    ], $tmp);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $styles = (string) $zip->getFromName('xl/styles.xml');
    expect(contained($styles, '&quot;$&quot;#,##0.00'))->toBeTrue('expected USD currency format');
    $zip->close();
    @unlink($tmp);
});

it('applies percent format on a percent column', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'hs-').'.xlsx';
    Agent::write([
        'sheets' => [[
            'name' => 'X',
            'columns' => [['header' => 'Rate', 'type' => 'percent', 'decimals' => 2]],
            'rows' => [[0.124]],
            'theme' => 'plain',
        ]],
    ], $tmp);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $styles = (string) $zip->getFromName('xl/styles.xml');
    expect(contained($styles, '0.00%'))->toBeTrue('expected 0.00% percent format');
    $zip->close();
    @unlink($tmp);
});

it('converts ISO date strings to Excel serial numbers on date columns', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'hs-').'.xlsx';
    Agent::write([
        'sheets' => [[
            'name' => 'X',
            'columns' => [['header' => 'When', 'type' => 'date']],
            'rows' => [['2026-05-01']],
            'theme' => 'plain',
        ]],
    ], $tmp);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    // 2026-05-01 → 46143 (serial days since 1899-12-30)
    expect(contained($sheet, '<v>46143</v>'))->toBeTrue('expected Excel serial 46143 for 2026-05-01, got: '.$sheet);
    $zip->close();
    @unlink($tmp);
});

it('emits merged regions, frozen panes, and column widths', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'hs-').'.xlsx';
    Agent::write([
        'sheets' => [[
            'name' => 'X',
            'cells' => ['A1' => ['value' => 'Hello']],
            'mergedRegions' => [['start' => 'A1', 'end' => 'C1']],
            'frozenRows' => 1,
            'frozenCols' => 0,
            'columnWidths' => [0 => 200],
        ]],
    ], $tmp);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    expect(contained($sheet, '<mergeCell ref="A1:C1"/>'))->toBeTrue('expected mergeCell element');
    expect(contained($sheet, '<sheetView'))->toBeTrue('expected sheetView for frozen pane');
    expect(contained($sheet, 'ySplit="1"'))->toBeTrue('expected ySplit=1');
    expect(contained($sheet, '<col min="1" max="1"'))->toBeTrue('expected column width record');
    $zip->close();
    @unlink($tmp);
});

it('writes the symbolic totals row with SUM/AVG formulas', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'hs-').'.xlsx';
    Agent::write([
        'sheets' => [[
            'name' => 'X',
            'columns' => [['header' => 'Region'], ['header' => 'Revenue', 'type' => 'number'], ['header' => 'YoY', 'type' => 'percent']],
            'rows' => [['A', 100, 0.1], ['B', 200, 0.2], ['C', 300, 0.3]],
            'totals' => ['Revenue' => 'sum', 'YoY' => 'avg'],
            'theme' => 'plain',
        ]],
    ], $tmp);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    expect(contained($sheet, '<f>SUM(B2:B4)</f>'))->toBeTrue('expected SUM formula');
    expect(contained($sheet, '<f>AVERAGE(C2:C4)</f>'))->toBeTrue('expected AVERAGE formula');
    $zip->close();
    @unlink($tmp);
});

it('writes comments xml + vml drawing when a cell has a comment', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'hs-').'.xlsx';
    Agent::write([
        'sheets' => [[
            'name' => 'X',
            'cells' => [
                'A1' => [
                    'value' => 'Hello',
                    'comment' => ['text' => 'Note this cell', 'author' => 'Agent'],
                ],
            ],
        ]],
    ], $tmp);
    $zip = new ZipArchive();
    $zip->open($tmp);
    expect($zip->locateName('xl/comments1.xml'))->not->toBeFalse();
    expect($zip->locateName('xl/drawings/vmlDrawing1.vml'))->not->toBeFalse();
    expect($zip->locateName('xl/worksheets/_rels/sheet1.xml.rels'))->not->toBeFalse();
    $comments = (string) $zip->getFromName('xl/comments1.xml');
    expect(contained($comments, 'Note this cell'))->toBeTrue();
    expect(contained($comments, '<author>Agent</author>'))->toBeTrue();
    $zip->close();
    @unlink($tmp);
});

it('emits cached formula values when computedValue is provided', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'hs-').'.xlsx';
    Agent::write([
        'sheets' => [[
            'name' => 'X',
            'cells' => [
                'A1' => ['value' => 10],
                'A2' => ['value' => 20],
                'A3' => ['formula' => 'SUM(A1:A2)', 'computedValue' => 30],
            ],
        ]],
    ], $tmp);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    expect(contained($sheet, '<f>SUM(A1:A2)</f><v>30</v>'))->toBeTrue();
    $zip->close();
    @unlink($tmp);
});
