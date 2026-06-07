<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\HolySheet;
use HolySheet\Schema\DumpOptions;

it('dumps a schema to JSON', function () {
    $schema = ['sheets' => [['name' => 'T', 'cells' => ['A1' => ['value' => 1], 'B1' => ['value' => 2]]]]];

    $decoded = json_decode(Agent::dumpJson($schema), true);

    expect($decoded['sheets'][0]['name'])->toBe('T')
        ->and($decoded['sheets'][0]['cells'])->toHaveKey('A1')
        ->and($decoded['sheets'][0]['cells'])->toHaveKey('B1');
});

it('is reachable through the HolySheet facade-core instance', function () {
    $schema = ['sheets' => [['name' => 'T', 'cells' => ['A1' => ['value' => 1]]]]];

    expect((new HolySheet())->dumpJson($schema))->toBeJson();
});

it('drops empty cells and fully-empty sheets when compactEmpty', function () {
    $schema = ['sheets' => [
        ['name' => 'Has', 'cells' => ['A1' => ['value' => 1], 'B1' => ['value' => null], 'C1' => ['value' => '']]],
        ['name' => 'Empty', 'cells' => []],
    ]];

    $decoded = json_decode(Agent::dumpJson($schema), true);

    expect($decoded['sheets'])->toHaveCount(1)
        ->and($decoded['sheets'][0]['cells'])->toHaveKey('A1')
        ->and($decoded['sheets'][0]['cells'])->not->toHaveKey('B1')
        ->and($decoded['sheets'][0]['cells'])->not->toHaveKey('C1');
});

it('keeps formats by default and strips them on DumpOptions::compact()', function () {
    $schema = ['sheets' => [[
        'name' => 'T',
        'theme' => 'business',
        'cells' => ['A1' => ['value' => 1, 'format' => ['displayFormat' => 'currency']]],
    ]]];

    $kept = json_decode(Agent::dumpJson($schema), true);
    $stripped = json_decode(Agent::dumpJson($schema, DumpOptions::compact()), true);

    expect($kept['sheets'][0]['cells']['A1'])->toHaveKey('format')
        ->and($stripped['sheets'][0]['cells']['A1'])->not->toHaveKey('format')
        ->and($stripped['sheets'][0])->not->toHaveKey('theme');
});

it('preserves formula cells in the dump', function () {
    $schema = ['sheets' => [['name' => 'T', 'cells' => ['A1' => ['value' => null, 'formula' => 'SUM(B1:B5)']]]]];

    $decoded = json_decode(Agent::dumpJson($schema), true);

    expect($decoded['sheets'][0]['cells']['A1']['formula'])->toBe('SUM(B1:B5)');
});

it('returns a compact shape index when the dump exceeds maxBytes', function () {
    $cells = [];
    for ($i = 1; $i <= 200; $i++) {
        $cells["A{$i}"] = ['value' => str_repeat('x', 50)];
    }
    $schema = ['sheets' => [['name' => 'Big', 'cells' => $cells]]];

    $decoded = json_decode(Agent::dumpJson($schema, new DumpOptions(maxBytes: 256)), true);

    expect($decoded['_truncated'])->toBeTrue()
        ->and($decoded['sheets'][0]['name'])->toBe('Big')
        ->and($decoded['sheets'][0]['cells'])->toBe(200);
});

it('pretty-prints when asked', function () {
    $schema = ['sheets' => [['name' => 'T', 'cells' => ['A1' => ['value' => 1]]]]];

    expect(Agent::dumpJson($schema, new DumpOptions(prettyPrint: true)))->toContain("\n");
});
