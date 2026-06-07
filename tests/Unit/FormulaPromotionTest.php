<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Schema\Normalizer;

it('promotes a bare =string row cell to a formula', function () {
    $schema = ['sheets' => [[
        'name' => 'T',
        'columns' => [['header' => 'A'], ['header' => 'B'], ['header' => 'C']],
        'rows' => [[10, 20, '=A2+B2']],
    ]]];

    $cell = (new Normalizer())->normalize($schema)->sheets[0]->cells['C2'];

    expect($cell->formula)->toBe('A2+B2')
        ->and($cell->value)->toBeNull();
});

it('promotes a bare =string in a cells map', function () {
    $schema = ['sheets' => [['name' => 'T', 'cells' => ['A1' => '=SUM(B1:B5)']]]];

    $cell = (new Normalizer())->normalize($schema)->sheets[0]->cells['A1'];

    expect($cell->formula)->toBe('SUM(B1:B5)')
        ->and($cell->value)->toBeNull();
});

it('does NOT promote an object cell with an explicit value (literal escape hatch)', function () {
    $schema = ['sheets' => [['name' => 'T', 'cells' => ['A1' => ['value' => '=literal']]]]];

    $cell = (new Normalizer())->normalize($schema)->sheets[0]->cells['A1'];

    expect($cell->formula)->toBeNull()
        ->and($cell->value)->toBe('=literal');
});

it('leaves an explicit formula object untouched', function () {
    $schema = ['sheets' => [['name' => 'T', 'cells' => ['A1' => ['value' => null, 'formula' => 'SUM(B1:B5)']]]]];

    $cell = (new Normalizer())->normalize($schema)->sheets[0]->cells['A1'];

    expect($cell->formula)->toBe('SUM(B1:B5)');
});

it('does not promote a lone "=" or a non-= string', function () {
    $schema = ['sheets' => [['name' => 'T', 'cells' => ['A1' => '=', 'A2' => 'hello']]]];

    $cells = (new Normalizer())->normalize($schema)->sheets[0]->cells;

    expect($cells['A1']->formula)->toBeNull()
        ->and($cells['A1']->value)->toBe('=')
        ->and($cells['A2']->value)->toBe('hello');
});

it('treats a promoted bare =string as a formula at lint time, an object value as literal text', function () {
    $promoted = ['sheets' => [['name' => 'T', 'cells' => ['A1' => '=10/0']]]];
    $literal = ['sheets' => [['name' => 'T', 'cells' => ['A1' => ['value' => '=10/0']]]]];

    expect(Agent::lint($promoted))->not->toBeEmpty()   // evaluated → an Excel error
        ->and(Agent::lint($literal))->toBe([]);          // stored as text → nothing to lint
});

it('writes a promoted-formula schema to valid xlsx bytes without error', function () {
    $schema = ['sheets' => [[
        'name' => 'T',
        'columns' => [['header' => 'A'], ['header' => 'B'], ['header' => 'C']],
        'rows' => [[10, 20, '=A2+B2']],
    ]]];

    $bytes = Agent::toBytes($schema);

    expect(substr($bytes, 0, 4))->toBe("PK\x03\x04");
});
