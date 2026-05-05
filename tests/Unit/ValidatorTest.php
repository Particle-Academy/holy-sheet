<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Exceptions\SchemaException;

it('returns no errors for a valid schema', function () {
    $errors = Agent::validate([
        'sheets' => [['name' => 'OK', 'columns' => [['header' => 'A']], 'rows' => [[1]]]],
    ]);
    expect($errors)->toBe([]);
});

it('flags missing top-level sheets', function () {
    $errors = Agent::validate([]);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['path'])->toBe('sheets');
    expect($errors[0]['expected'])->toBe('array');
});

it('flags an empty sheets array', function () {
    $errors = Agent::validate(['sheets' => []]);
    expect($errors[0]['path'])->toBe('sheets');
    expect($errors[0]['expected'])->toContain('non-empty');
});

it('flags a sheet missing its name', function () {
    $errors = Agent::validate(['sheets' => [['columns' => [['header' => 'A']], 'rows' => [[1]]]]]);
    expect($errors[0]['path'])->toBe('sheets[0].name');
});

it('flags an unknown column type', function () {
    $errors = Agent::validate([
        'sheets' => [['name' => 'X', 'columns' => [['header' => 'A', 'type' => 'banana']], 'rows' => [[1]]]],
    ]);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['path'])->toBe('sheets[0].columns[0].type');
    expect($errors[0]['hint'])->toBeString();
});

it('flags an unknown theme', function () {
    $errors = Agent::validate([
        'sheets' => [['name' => 'X', 'columns' => [['header' => 'A']], 'rows' => [[1]], 'theme' => 'neon']],
    ]);
    expect($errors[0]['path'])->toBe('sheets[0].theme');
});

it('throws SchemaException on assert with structured errors', function () {
    expect(fn () => Agent::write([], '/tmp/should-not-be-written.xlsx'))
        ->toThrow(SchemaException::class);
});
