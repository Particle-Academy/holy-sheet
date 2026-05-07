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
