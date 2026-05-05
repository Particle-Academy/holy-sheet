<?php

declare(strict_types=1);

use HolySheet\Agent;

it('returns the JSON schema as a parsed array from toolDefinition', function () {
    $def = Agent::toolDefinition();
    expect($def)->toBeArray();
    expect($def)->toHaveKey('$schema');
    expect($def['title'])->toBe('Holy Sheet workbook schema');
    expect($def['definitions'])->toHaveKeys(['Sheet', 'Column', 'CellData', 'CellFormat']);
});

it('describe() stubs to a not-yet-implemented marker in 0.2', function () {
    $result = Agent::describe('/some/path.xlsx');
    expect($result)->toMatchArray([
        'error' => 'not yet implemented',
        'available_in' => '0.9',
    ]);
});

it('toBytes returns a non-empty xlsx string', function () {
    $bytes = Agent::toBytes(['sheets' => [['name' => 'X', 'columns' => [['header' => 'A']], 'rows' => [[1]]]]]);
    expect(strlen($bytes))->toBeGreaterThan(100);
    expect(substr($bytes, 0, 4))->toBe("PK\x03\x04");
});
