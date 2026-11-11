<?php

declare(strict_types=1);

use HolySheet\Agent;

/**
 * A cell's `formula` with a leading "=" is read the same way by every entry
 * point (reported against 2.14.0).
 *
 * write() always stripped the "=" and wrote `<f>A2*2</f>`, while lint() parsed
 * the raw text and reported `#NAME?` "unexpected '='". An agent following
 * lint -> ops -> reduce -> lint wrote "=A2*2", was told it was broken, and
 * looped. The canonical form is written without the "="; a leading "=" is
 * accepted and stripped, once, in Cell, so lint and write cannot disagree.
 */
function leadingEqualsBase(): array
{
    $schema = Agent::validateAndRepair(['sheets' => [[
        'name' => 'Model',
        'columns' => [['header' => 'Base', 'type' => 'number'], ['header' => 'Scenario', 'type' => 'number']],
        'rows' => [[100, 0]],
    ]], 'meta' => ['creator' => 'Test', 'created' => '2024-01-01T00:00:00Z']])['schema'];

    return $schema;
}

function leadingEqualsSet(string $formula): array
{
    return Agent::reduce(leadingEqualsBase(), [['type' => 'set_cell', 'sheet' => 'Model', 'address' => 'B2', 'formula' => $formula]]);
}

it('lints a set_cell formula written with a leading = clean, as write reads it', function () {
    expect(Agent::lint(leadingEqualsSet('=A2*2')))->toBe([])
        ->and(Agent::lint(leadingEqualsSet('A2*2')))->toBe([]);
});

it('writes the = form and the canonical form byte for byte the same', function () {
    expect(Agent::toBytes(leadingEqualsSet('=A2*2')))->toBe(Agent::toBytes(leadingEqualsSet('A2*2')));
});

it('reads a repeated leading = the way write does', function () {
    // write() strips every leading "=", so lint must too.
    expect(Agent::lint(leadingEqualsSet('==A2*2')))->toBe([])
        ->and(Agent::toBytes(leadingEqualsSet('==A2*2')))->toBe(Agent::toBytes(leadingEqualsSet('A2*2')));
});

it('agrees in validate, validateAndRepair and equivalent', function () {
    $a = leadingEqualsSet('=A2*2');

    $repaired = Agent::validateAndRepair($a);

    expect(Agent::validate($a))->toBe([])
        ->and($repaired['errors'])->toBe([])
        ->and(Agent::lint($repaired['schema']))->toBe([])
        ->and(Agent::equivalent($a, leadingEqualsSet('A2*2')))->toBeTrue();
});

it('lints an object cell formula with a leading = in a cells map clean', function () {
    $schema = ['sheets' => [['name' => 'S', 'cells' => ['A1' => ['value' => 2], 'B1' => ['formula' => '=A1*2'], 'C1' => '==A1*3']]]];

    expect(Agent::lint($schema))->toBe([]);
});

it('still reports a real error in an = formula, with the canonical text', function () {
    $issues = Agent::lint(leadingEqualsSet('=A2/0'));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#DIV/0!')
        ->and($issues[0]['formula'])->toBe('A2/0');
});

it('describes the canonical formula form in opSchema', function () {
    $setCell = array_values(array_filter(Agent::opSchema()['oneOf'], fn (array $v) => $v['properties']['type']['const'] === 'set_cell'))[0];
    $formula = $setCell['properties']['formula'];

    expect($formula['type'])->toBe('string')
        ->and($formula['description'] ?? '')->toContain("without the leading '='")
        ->and($formula['description'] ?? '')->toContain("a leading '=' is accepted and stripped");
});
