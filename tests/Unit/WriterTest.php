<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\HolySheet;

it('writes a minimum viable xlsx', function () {
    $schema = [
        'sheets' => [[
            'name' => 'Sheet 1',
            'columns' => [
                ['header' => 'Name'],
                ['header' => 'Age', 'type' => 'integer'],
            ],
            'rows' => [
                ['Alice', 30],
                ['Bob', 42],
            ],
        ]],
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'holy-sheet-test-').'.xlsx';
    $result = Agent::write($schema, $tmp);

    expect($result)
        ->toMatchArray(['path' => $tmp, 'sheets' => 1])
        ->and($result['bytes'])->toBeGreaterThan(0);
    expect(file_exists($tmp))->toBeTrue();

    // Verify the produced bytes look like a valid ZIP — first 4 bytes "PK\x03\x04"
    $head = file_get_contents($tmp, false, null, 0, 4);
    expect($head)->toBe("PK\x03\x04");

    @unlink($tmp);
});

it('writes a workbook with multiple sheets', function () {
    $schema = [
        'sheets' => [
            ['name' => 'A', 'columns' => [['header' => 'X']], 'rows' => [[1], [2]]],
            ['name' => 'B', 'columns' => [['header' => 'Y']], 'rows' => [[3], [4]]],
        ],
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'holy-sheet-test-').'.xlsx';
    $result = Agent::write($schema, $tmp);

    expect($result['sheets'])->toBe(2);
    @unlink($tmp);
});

it('accepts the fancy-sheets-style sparse cells map', function () {
    $schema = [
        'sheets' => [[
            'name' => 'Sparse',
            'cells' => [
                'A1' => ['value' => 'Header'],
                'A2' => ['value' => 100],
                'B2' => ['value' => 200],
                'A3' => ['formula' => 'SUM(A2:B2)'],
            ],
        ]],
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'holy-sheet-test-').'.xlsx';
    $result = Agent::write($schema, $tmp);

    expect($result['bytes'])->toBeGreaterThan(0);
    @unlink($tmp);
});

it('the produced xlsx contains the five mandatory parts', function () {
    $schema = ['sheets' => [['name' => 'Test', 'columns' => [['header' => 'A']], 'rows' => [[1]]]]];
    $tmp = tempnam(sys_get_temp_dir(), 'holy-sheet-test-').'.xlsx';
    Agent::write($schema, $tmp);

    $zip = new ZipArchive();
    expect($zip->open($tmp))->toBeTrue();

    foreach ([
        '[Content_Types].xml',
        '_rels/.rels',
        'xl/workbook.xml',
        'xl/_rels/workbook.xml.rels',
        'xl/worksheets/sheet1.xml',
        'docProps/core.xml',
        'docProps/app.xml',
    ] as $entry) {
        expect($zip->locateName($entry))->not->toBeFalse(
            "missing required xlsx entry: {$entry}",
        );
    }

    $zip->close();
    @unlink($tmp);
});

it('exposes HolySheet::writeFile as the static convenience entry', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'holy-sheet-test-').'.xlsx';
    HolySheet::writeFile($tmp, ['sheets' => [['name' => 'X', 'columns' => [['header' => 'A']], 'rows' => [[1]]]]]);
    expect(file_exists($tmp))->toBeTrue();
    @unlink($tmp);
});

it('the HolySheet singleton mirrors Agent for facade/DI use', function () {
    $hs = new HolySheet();
    $tmp = tempnam(sys_get_temp_dir(), 'holy-sheet-test-').'.xlsx';
    $result = $hs->write(['sheets' => [['name' => 'X', 'columns' => [['header' => 'A']], 'rows' => [[1]]]]], $tmp);
    expect($result['sheets'])->toBe(1);
    expect($hs->validate(['sheets' => []]))->not->toBe([]);
    expect(substr($hs->toBytes(['sheets' => [['name' => 'Y', 'columns' => [['header' => 'A']], 'rows' => [[1]]]]]), 0, 4))
        ->toBe("PK\x03\x04");
    expect($hs->toolDefinition())->toHaveKey('$schema');
    expect($hs->getVersion())->toBeString();
    @unlink($tmp);
});
