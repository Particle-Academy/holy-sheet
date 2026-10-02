<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Exceptions\SchemaException;
use HolySheet\Schema\Normalizer;

/*
 * `columnWidths` entries that are not a column index and a width.
 *
 * Before 2.3.4 the three writers disagreed: PHP cast the key "abc" to 0 and
 * OVERWROTE column A's width, Python raised ValueError from to_bytes(), and Node
 * wrote a NaN column. One rule now (HolySheet\Schema\ColumnWidths), in all three.
 */

function cwSheet(array $widths): array
{
    return ['sheets' => [['name' => 'S', 'cells' => ['A1' => ['value' => 'x']], 'columnWidths' => $widths]]];
}

it('never lets a key that is not a column index overwrite a column', function () {
    // The key comes AFTER column A's width, so reading it as 0 would replace 120.
    $workbook = (new Normalizer())->normalize(cwSheet([0 => 120, 'abc' => 999, 1 => 80]));

    expect($workbook->sheets[0]->columnWidths)->toBe([0 => 120.0, 1 => 80.0]);
});

it('reports every bad entry by path instead of writing it', function () {
    $errors = Agent::validate(cwSheet([
        'abc' => 50,
        '-1' => 50,
        '16384' => 50,
        'B' => 50,
        '0' => 'wide',
        '1' => -5,
    ]));

    expect(array_column($errors, 'path'))->toBe([
        'sheets[0].columnWidths.abc',
        'sheets[0].columnWidths.-1',
        'sheets[0].columnWidths.16384',
        'sheets[0].columnWidths.B',
        'sheets[0].columnWidths.0',
        'sheets[0].columnWidths.1',
    ]);

    expect(fn () => Agent::toBytes(cwSheet(['abc' => 999])))->toThrow(SchemaException::class);
});

it('accepts indexes as ints or digit strings, widths as numbers or digit strings, and a list', function () {
    expect(Agent::validate(cwSheet([0 => 120, '1' => '80', 2 => 140.5, '16383' => 10])))->toBe([]);
    // PHP encodes widths keyed 0..n-1 as a JSON list; that is a valid map.
    expect(Agent::validate(cwSheet([120, 80])))->toBe([]);
});

it('repairs a letter key to its index and drops what it cannot repair', function () {
    $result = Agent::validateAndRepair(cwSheet(['B' => 90, 'abc' => 5, '0' => 'wide', '2' => 60]));

    expect($result['errors'])->toBe([]);
    expect($result['schema']['sheets'][0]['columnWidths'])->toBe([1 => 90, 2 => 60]);
    expect($result['repairs'])->toContain("converted 'sheets[0].columnWidths.B' to column index 1");
    expect($result['repairs'])->toContain("dropped 'sheets[0].columnWidths.abc' (not a column index and a width)");
    expect($result['repairs'])->toContain("dropped 'sheets[0].columnWidths.0' (not a column index and a width)");
});

/*
 * Per-column `width` (holy-sheet#8).
 *
 * `skills/holy-sheet.schema.json` has declared a `Column.width` of "Column width
 * in pixels. Same as columnWidths but per-column." since the field was written,
 * and `skills/holy-sheet.md` lists it in the Column table. The writer read
 * `type`, `decimals` and `currency` off a column and nothing else, so the field
 * was accepted by the validator, dropped by the normalizer and emitted no
 * `<cols>` element at all — the documented-but-wired-to-nothing shape, with no
 * signal at any layer.
 *
 * Both mechanisms now feed one `columnWidths` map. The sheet-level map is applied
 * LAST, so a sheet that sets both keeps the explicit map: that is the mechanism
 * that already worked, and a consumer who added `columnWidths` to work around
 * this bug must not have it silently overridden by the `width` they left behind.
 */

/** Local to this file: Pest's toContain is iterable-only. */
function cwSheetXml(array $schema): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'hs-cw-').'.xlsx';
    file_put_contents($tmp, Agent::toBytes($schema));
    $zip = new ZipArchive();
    $zip->open($tmp);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($tmp);

    return $xml;
}

function cwColsOf(string $xml): string
{
    return preg_match('/<cols>.*?<\/cols>/', $xml, $m) === 1 ? $m[0] : '';
}

it('emits <cols> from a per-column width', function () {
    $xml = cwSheetXml(['sheets' => [[
        'name' => 'Q4',
        'columns' => [
            ['header' => 'Account', 'width' => 220],
            ['header' => 'Stage', 'width' => 140],
        ],
        'rows' => [['Acme Corporation Limited', 'Won']],
    ]]]);

    expect(cwColsOf($xml))->toBe(
        '<cols>'
        .'<col min="1" max="1" width="30.7143" customWidth="1"/>'
        .'<col min="2" max="2" width="19.2857" customWidth="1"/>'
        .'</cols>'
    );
});

it('only widens the columns that carry a width, in column order', function () {
    $xml = cwSheetXml(['sheets' => [[
        'name' => 'Q4',
        'columns' => [
            ['header' => 'A'],
            ['header' => 'B', 'width' => 140],
            ['header' => 'C'],
        ],
        'rows' => [[1, 2, 3]],
    ]]]);

    expect(cwColsOf($xml))->toBe('<cols><col min="2" max="2" width="19.2857" customWidth="1"/></cols>');
});

it('lets the sheet-level columnWidths map win over a per-column width', function () {
    $xml = cwSheetXml(['sheets' => [[
        'name' => 'Q4',
        'columns' => [
            ['header' => 'Account', 'width' => 220],
            ['header' => 'Stage', 'width' => 140],
        ],
        'columnWidths' => [0 => 400],
        'rows' => [['Acme Corporation Limited', 'Won']],
    ]]]);

    // Column A takes the map's 400px; column B keeps its own 140px, and the two
    // stay in ascending order even though the map was merged in second.
    expect(cwColsOf($xml))->toBe(
        '<cols>'
        .'<col min="1" max="1" width="56.4286" customWidth="1"/>'
        .'<col min="2" max="2" width="19.2857" customWidth="1"/>'
        .'</cols>'
    );
});

it('reports a per-column width that is not a width instead of dropping it in silence', function () {
    $errors = Agent::validate(['sheets' => [[
        'name' => 'S',
        'columns' => [
            ['header' => 'A', 'width' => 'wide'],
            ['header' => 'B', 'width' => -5],
            ['header' => 'C', 'width' => '140'],
        ],
        'rows' => [[1, 2, 3]],
    ]]]);

    expect(array_column($errors, 'path'))->toBe([
        'sheets[0].columns[0].width',
        'sheets[0].columns[1].width',
    ]);
});

it('repairs away a per-column width it cannot honour and says so', function () {
    $result = Agent::validateAndRepair(['sheets' => [[
        'name' => 'S',
        'columns' => [
            ['header' => 'A', 'width' => 'wide'],
            ['header' => 'B', 'width' => 140],
        ],
        'rows' => [[1, 2]],
    ]]]);

    expect($result['errors'])->toBe([]);
    expect($result['schema']['sheets'][0]['columns'][0])->toBe(['header' => 'A']);
    expect($result['schema']['sheets'][0]['columns'][1]['width'])->toBe(140);
    expect($result['repairs'])->toContain("dropped 'sheets[0].columns[0].width' (not a width in pixels)");
});
