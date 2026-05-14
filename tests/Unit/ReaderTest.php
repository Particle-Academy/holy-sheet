<?php

declare(strict_types=1);

use HolySheet\Agent;

function holy_write_tmp(array $schema): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'holy_read_').'.xlsx';
    Agent::write($schema, $tmp);
    return $tmp;
}

it('describes a simple workbook back to schema', function () {
    $path = holy_write_tmp([
        'sheets' => [[
            'name' => 'Q1',
            'columns' => [
                ['header' => 'Region', 'type' => 'string'],
                ['header' => 'Revenue', 'type' => 'currency', 'currency' => 'USD'],
            ],
            'rows' => [
                ['NA', 100],
                ['EU', 200],
            ],
        ]],
    ]);

    $out = Agent::describe($path);
    unlink($path);

    expect($out)->toHaveKey('sheets')
        ->and($out['sheets'][0]['name'])->toBe('Q1')
        ->and($out['sheets'][0]['cells'])->toHaveKey('A1')
        ->and($out['sheets'][0]['cells']['A1']['value'])->toBe('Region')
        ->and($out['sheets'][0]['cells']['B2']['value'])->toBe(100);
});

it('returns not_found for a missing path', function () {
    expect(Agent::describe('/nope/missing.xlsx'))
        ->toBe(['error' => 'not_found', 'path' => '/nope/missing.xlsx']);
});

it('round-trips merged regions', function () {
    $path = holy_write_tmp([
        'sheets' => [[
            'name' => 'M',
            'rows' => [['a', 'b']],
            'mergedRegions' => [['start' => 'A1', 'end' => 'B1']],
        ]],
    ]);
    $out = Agent::describe($path);
    unlink($path);
    expect($out['sheets'][0]['mergedRegions'][0])->toBe(['start' => 'A1', 'end' => 'B1']);
});

it('round-trips frozen panes', function () {
    $path = holy_write_tmp([
        'sheets' => [[
            'name' => 'F',
            'rows' => [['x']],
            'frozenRows' => 1,
            'frozenCols' => 2,
        ]],
    ]);
    $out = Agent::describe($path);
    unlink($path);
    expect($out['sheets'][0]['frozenRows'])->toBe(1)
        ->and($out['sheets'][0]['frozenCols'])->toBe(2);
});

it('round-trips formulas with cached values', function () {
    $path = holy_write_tmp([
        'sheets' => [[
            'name' => 'C',
            'rows' => [
                [1, 2, ['formula' => 'A1+B1', 'computedValue' => 3]],
            ],
        ]],
    ]);
    $out = Agent::describe($path);
    unlink($path);
    expect($out['sheets'][0]['cells']['C1']['formula'])->toBe('A1+B1')
        ->and($out['sheets'][0]['cells']['C1']['computedValue'])->toBe(3);
});

it('round-trips comments', function () {
    $path = holy_write_tmp([
        'sheets' => [[
            'name' => 'N',
            'cells' => [
                'A1' => ['value' => 'hi', 'comment' => ['text' => 'note', 'author' => 'me']],
            ],
        ]],
    ]);
    $out = Agent::describe($path);
    unlink($path);
    expect($out['sheets'][0]['cells']['A1']['comment']['text'])->toBe('note');
});

it('resolves shared-string references from xl/sharedStrings.xml', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'holy_shared_').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>
XML);

    $zip->addFromString('_rels/.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);

    $zip->addFromString('xl/_rels/workbook.xml.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>
XML);

    $zip->addFromString('xl/workbook.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>
</workbook>
XML);

    $zip->addFromString('xl/sharedStrings.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="3" uniqueCount="3">
<si><t>Revenue Category</t></si>
<si><t>Subscription</t></si>
<si><r><t>Rich </t></r><r><t>String</t></r></si>
</sst>
XML);

    $zip->addFromString('xl/worksheets/sheet1.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetData>
<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>
<row r="2"><c r="A2" t="s"><v>2</v></c></row>
</sheetData>
</worksheet>
XML);

    $zip->close();

    $out = Agent::describe($tmp);
    unlink($tmp);

    expect($out['sheets'][0]['cells']['A1']['value'])->toBe('Revenue Category')
        ->and($out['sheets'][0]['cells']['B1']['value'])->toBe('Subscription')
        ->and($out['sheets'][0]['cells']['A2']['value'])->toBe('Rich String');
});
