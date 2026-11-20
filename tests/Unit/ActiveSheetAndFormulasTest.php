<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\HolySheet;

/**
 * CC-fancy-0050: read() names the workbook's active sheet, the writer emits it
 * when a schema names one, and the `formulas` write option turns off the
 * promotion of a bare "=" string. Driven by tests/fixtures/active-sheet/cases.json,
 * which the JS and Python ports read too. A case added there is a case all
 * three must pass.
 */
function activeSheetCases(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../fixtures/active-sheet/cases.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string,string> part name => bytes */
function activeSheetParts(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'hs-active');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    $zip->open($path);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $parts[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($path);

    return $parts;
}

/**
 * Each expected cell's `value` and `formula` as read, in the expected order.
 * Formats are the writer's business and not what these cases are about.
 *
 * @param  array<string,array<string,mixed>>  $cells
 * @param  array<string,array<string,mixed>>  $expected
 * @return array<string,array<string,mixed>>
 */
function valueAndFormula(array $cells, array $expected): array
{
    $out = [];
    foreach (array_keys($expected) as $address) {
        $cell = $cells[$address] ?? [];
        $out[$address] = array_intersect_key($cell, ['value' => true, 'formula' => true]);
    }

    return $out;
}

function bookViewsOf(string $bytes): ?string
{
    $xml = activeSheetParts($bytes)['xl/workbook.xml'];

    return preg_match('#<bookViews>.*?</bookViews>#', $xml, $m) === 1 ? $m[0] : null;
}

it('has cases to run', function () {
    $cases = activeSheetCases();
    expect(count($cases['read']))->toBeGreaterThan(15)
        ->and(count($cases['write']))->toBeGreaterThan(3)
        ->and(count($cases['invalid']))->toBeGreaterThan(3)
        ->and(count($cases['formulas']))->toBe(3)
        ->and(count($cases['badOptions']))->toBe(3);
});

it('reads the active sheet of each shared fixture', function (array $case) {
    $bytes = (string) file_get_contents(__DIR__.'/../fixtures/active-sheet/'.$case['file']);
    $schema = Agent::read($bytes, $case['options']);

    expect($schema['activeSheet'] ?? null)->toBe($case['activeSheet']);
})->with(fn () => array_map(fn ($c) => [$c], array_column(activeSheetCases()['read'], null, 'name')));

it('raises for a missing sheet part rather than picking an active sheet around it', function (array $case) {
    $bytes = (string) file_get_contents(__DIR__.'/../fixtures/active-sheet/'.$case['file']);

    try {
        Agent::read($bytes, $case['options']);
        $this->fail('expected MissingPartException');
    } catch (\HolySheet\Exceptions\MissingPartException $e) {
        expect($e->part)->toBe($case['part']);
    }
})->with(fn () => array_map(fn ($c) => [$c], array_column(activeSheetCases()['readRaises'], null, 'name')));

it('describes the active sheet from a path too', function () {
    expect(Agent::describe(__DIR__.'/../fixtures/active-sheet/active-1.xlsx')['activeSheet'])
        ->toBe(['index' => 1, 'name' => 'Second']);
});

it('places activeSheet right after sheets', function () {
    $bytes = (string) file_get_contents(__DIR__.'/../fixtures/active-sheet/active-1.xlsx');

    expect(array_slice(array_keys(Agent::read($bytes)), 0, 2))->toBe(['sheets', 'activeSheet']);
});

it('writes the active sheet each shared case names, and reads it back', function (array $case) {
    $bytes = Agent::toBytes($case['schema']);

    expect(bookViewsOf($bytes))->toBe($case['bookViews'])
        ->and(Agent::read($bytes)['activeSheet'])->toBe($case['activeSheet']);
})->with(fn () => array_map(fn ($c) => [$c], array_column(activeSheetCases()['write'], null, 'name')));

it('refuses each shared invalid activeSheet with the shared error', function (array $case) {
    expect(Agent::validate($case['schema']))->toBe($case['errors']);
})->with(fn () => array_map(fn ($c) => [$c], array_column(activeSheetCases()['invalid'], null, 'name')));

it('round-trips a read workbook with its active sheet', function () {
    $bytes = (string) file_get_contents(__DIR__.'/../fixtures/active-sheet/active-2.xlsx');
    $described = Agent::read($bytes);

    expect(Agent::validate($described))->toBe([])
        ->and(Agent::read(Agent::toBytes($described))['activeSheet'])->toBe(['index' => 2, 'name' => 'Third']);
});

it('keeps a hidden first sheet off the active tab, and lets activeSheet pick another visible one', function () {
    $sheets = [['name' => 'Hidden', 'state' => 'hidden', 'cells' => ['A1' => 1]], ['name' => 'A', 'cells' => ['A1' => 1]], ['name' => 'B', 'cells' => ['A1' => 1]]];

    // Unchanged from before activeSheet existed: the first visible sheet.
    expect(bookViewsOf(Agent::toBytes(['sheets' => $sheets])))
        ->toBe('<bookViews><workbookView firstSheet="1" activeTab="1"/></bookViews>')
        ->and(bookViewsOf(Agent::toBytes(['activeSheet' => 'B', 'sheets' => $sheets])))
        ->toBe('<bookViews><workbookView firstSheet="1" activeTab="2"/></bookViews>');
});

it('refuses a hidden active sheet', function () {
    $errors = Agent::validate(['activeSheet' => 'Hidden', 'sheets' => [['name' => 'A', 'cells' => ['A1' => 1]], ['name' => 'Hidden', 'state' => 'hidden', 'cells' => ['A1' => 1]]]]);

    expect($errors)->toBe([[
        'path' => 'activeSheet',
        'expected' => 'a visible sheet',
        'got' => 'hidden sheet',
        'value' => 'Hidden',
        'hint' => 'Excel opens on a visible sheet. Make "Hidden" visible, or name another sheet.',
    ]]);
});

it('writes each formulas mode as the shared case reads it back', function (array $case) {
    $schema = activeSheetCases()['formulaSchema'];
    $read = Agent::read(Agent::toBytes($schema, $case['options']));

    expect(valueAndFormula($read['sheets'][0]['cells'], $case['cells']))->toBe($case['cells'])
        ->and(valueAndFormula($read['sheets'][1]['cells'], $case['rows']))->toBe($case['rows']);
})->with(fn () => array_map(fn ($c) => [$c], array_column(activeSheetCases()['formulas'], null, 'name')));

it('writes byte-identical parts under promote and under no option', function () {
    $schema = activeSheetCases()['formulaSchema'];
    $plain = activeSheetParts(Agent::toBytes($schema));
    $promote = activeSheetParts(Agent::toBytes($schema, ['formulas' => 'promote']));
    unset($plain['docProps/core.xml'], $promote['docProps/core.xml']);

    expect($promote)->toBe($plain);
});

it('takes the formulas option on write() and on the HolySheet instance', function () {
    $schema = activeSheetCases()['formulaSchema'];
    $path = tempnam(sys_get_temp_dir(), 'hs-formulas').'.xlsx';
    try {
        Agent::write($schema, $path, ['formulas' => 'explicit']);
        expect(Agent::describe($path)['sheets'][0]['cells']['A1']['value'])->toBe('=SUM(B1:B2)');

        (new HolySheet())->write($schema, $path, ['formulas' => 'explicit']);
        expect(Agent::describe($path)['sheets'][0]['cells']['A1']['value'])->toBe('=SUM(B1:B2)');
    } finally {
        @unlink($path);
    }

    $bytes = (new HolySheet())->toBytes($schema, ['formulas' => 'explicit']);
    expect(Agent::read($bytes)['sheets'][0]['cells']['A1']['value'])->toBe('=SUM(B1:B2)');
});

it('refuses each shared bad write option before writing', function (array $case) {
    $schema = activeSheetCases()['formulaSchema'];

    expect(fn () => Agent::toBytes($schema, $case['options']))
        ->toThrow(InvalidArgumentException::class, $case['message']);
})->with(fn () => array_map(fn ($c) => [$c], array_column(activeSheetCases()['badOptions'], null, 'name')));
