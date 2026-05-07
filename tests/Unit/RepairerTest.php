<?php

declare(strict_types=1);

use HolySheet\Agent;

it('renames singular sheet to sheets', function () {
    $result = Agent::validateAndRepair([
        'sheet' => [['name' => 'A', 'rows' => []]],
    ]);
    expect($result['schema'])->toHaveKey('sheets')
        ->and($result['schema'])->not->toHaveKey('sheet')
        ->and($result['repairs'])->not->toBeEmpty();
});

it('renames row to rows', function () {
    $result = Agent::validateAndRepair([
        'sheets' => [['name' => 'A', 'row' => [['x']]]],
    ]);
    expect($result['schema']['sheets'][0])->toHaveKey('rows')
        ->and($result['schema']['sheets'][0])->not->toHaveKey('row');
});

it('converts object-keyed rows to indexed list', function () {
    $result = Agent::validateAndRepair([
        'sheets' => [[
            'name' => 'A',
            'rows' => ['0' => ['a'], '1' => ['b']],
        ]],
    ]);
    $rows = $result['schema']['sheets'][0]['rows'];
    expect($rows)->toBe([['a'], ['b']]);
});

it('coerces stringified numerics in number columns', function () {
    $result = Agent::validateAndRepair([
        'sheets' => [[
            'name' => 'A',
            'columns' => [['header' => 'X', 'type' => 'number']],
            'rows' => [['1.5'], ['2']],
        ]],
    ]);
    $rows = $result['schema']['sheets'][0]['rows'];
    expect($rows[0][0])->toBe(1.5)
        ->and($rows[1][0])->toBeInt();
});

it('replaces unknown theme with default', function () {
    $result = Agent::validateAndRepair([
        'sheets' => [['name' => 'A', 'theme' => 'wonkyland', 'rows' => []]],
    ]);
    expect($result['schema']['sheets'][0]['theme'])->toBe('default');
});

it('passes valid schemas through unchanged', function () {
    $valid = ['sheets' => [['name' => 'A', 'rows' => [['x']]]]];
    $result = Agent::validateAndRepair($valid);
    expect($result['schema'])->toBe($valid)
        ->and($result['repairs'])->toBe([]);
});

it('does not invent missing required fields', function () {
    // Missing 'name' isn't auto-fixed
    $result = Agent::validateAndRepair([
        'sheets' => [['rows' => []]],
    ]);
    expect($result['errors'])->not->toBeEmpty();
});
