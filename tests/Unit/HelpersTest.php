<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Schema\Inference;

it('infers integer column from header + values', function () {
    $col = Inference::detect([1, 2, 3], 'count');
    expect($col['type'])->toBe('integer');
});

it('infers currency from header pattern', function () {
    $col = Inference::detect([1.5, 2.0], 'revenue');
    expect($col['type'])->toBe('currency')
        ->and($col['currency'])->toBe('USD');
});

it('infers percent only when values are in [0,1]', function () {
    $pct = Inference::detect([0.1, 0.5], 'growth_rate');
    $not = Inference::detect([10, 50], 'growth_rate');
    expect($pct['type'])->toBe('percent')
        ->and($not['type'])->toBe('integer');
});

it('infers date when values match ISO date', function () {
    $col = Inference::detect(['2024-01-01', '2024-02-01'], 'created');
    expect($col['type'])->toBe('date');
});

it('falls back to auto for mixed types', function () {
    $col = Inference::detect([1, 'two', true], 'mixed');
    expect($col['type'])->toBe('auto');
});

it('builds a schema from rows + headers via fromArray', function () {
    $schema = Agent::fromArray(
        rows: [['NA', 100], ['EU', 200]],
        headers: ['Region', 'Revenue'],
    );
    expect($schema['sheets'][0]['name'])->toBe('Sheet 1')
        ->and($schema['sheets'][0]['columns'][1]['type'])->toBe('currency')
        ->and($schema['sheets'][0]['rows'])->toHaveCount(2);
});

it('treats first row as headers when omitted', function () {
    $schema = Agent::fromArray([
        ['Name', 'Age'],
        ['Alice', 30],
        ['Bob', 42],
    ]);
    expect($schema['sheets'][0]['columns'][0]['header'])->toBe('Name')
        ->and($schema['sheets'][0]['rows'])->toHaveCount(2);
});

it('builds a schema from CSV string via fromCsv', function () {
    $schema = Agent::fromCsv("Name,Age\nAlice,30\nBob,42");
    expect($schema['sheets'][0]['columns'][0]['header'])->toBe('Name')
        ->and($schema['sheets'][0]['columns'][1]['type'])->toBe('integer')
        ->and($schema['sheets'][0]['rows'])->toBe([['Alice', 30], ['Bob', 42]]);
});

it('handles CSV with quoted fields and embedded newlines', function () {
    $csv = "Name,Note\n\"Alice\",\"line 1\nline 2\"\n";
    $schema = Agent::fromCsv($csv);
    expect($schema['sheets'][0]['rows'][0][1])->toBe("line 1\nline 2");
});

it('builds CSV from a file path', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'holy_csv_');
    file_put_contents($tmp, "City,Pop\nNYC,8000000\nLA,4000000\n");
    $schema = Agent::fromCsv($tmp);
    unlink($tmp);
    expect($schema['sheets'][0]['rows'])->toBe([['NYC', 8000000], ['LA', 4000000]]);
});
