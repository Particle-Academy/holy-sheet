<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Exceptions\MissingPartException;

/**
 * Relationship targets resolve per OPC: against the directory of the part that
 * owns the relationship, or package-absolute when they start with "/". External
 * targets are never parts, and a relationship whose part is missing raises.
 * Driven by tests/fixtures/rels-targets/cases.json, which the JS and Python
 * ports read too. A case added there is a case all three must pass.
 */
function relsTargetCases(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../fixtures/rels-targets/cases.json'), true, 512, JSON_THROW_ON_ERROR);
}

function relsTargetFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../fixtures/rels-targets/'.$name);
}

/**
 * read()'s sheets as the shared projection: values, bold addresses, comment
 * texts and chart types.
 *
 * @param  array<string,mixed>  $schema
 * @return list<array<string,mixed>>
 */
function relsTargetProjection(array $schema): array
{
    $out = [];
    foreach ($schema['sheets'] as $sheet) {
        $cells = [];
        $bold = [];
        $comments = [];
        foreach ($sheet['cells'] ?? [] as $address => $cell) {
            $cells[$address] = $cell['value'] ?? null;
            if (($cell['format']['bold'] ?? false) === true) {
                $bold[] = $address;
            }
            if (isset($cell['comment'])) {
                $comments[$address] = $cell['comment']['text'];
            }
        }
        $out[] = [
            'name' => $sheet['name'],
            'cells' => $cells,
            'bold' => $bold,
            'comments' => $comments,
            'charts' => array_map(fn (array $c) => $c['type'], $sheet['charts'] ?? []),
        ];
    }

    return $out;
}

it('has cases to run', function () {
    $cases = relsTargetCases();
    expect(count($cases['read']))->toBeGreaterThan(10)
        ->and(count($cases['raises']))->toBeGreaterThan(8);
});

it('reads each shared rels-target fixture', function (array $case) {
    $schema = Agent::read(relsTargetFixture($case['file']), $case['options']);

    expect(relsTargetProjection($schema))->toBe(relsTargetCases()['projections'][$case['sheets']]);
})->with(fn () => array_map(fn ($c) => [$c], array_column(relsTargetCases()['read'], null, 'name')));

it('raises for each shared missing part, naming it', function (array $case) {
    try {
        Agent::read(relsTargetFixture($case['file']), $case['options']);
        $this->fail('expected MissingPartException');
    } catch (MissingPartException $e) {
        expect($e->part)->toBe($case['part'])
            ->and($e->source)->toBe($case['source'])
            ->and($e->getMessage())->toStartWith('[holy-sheet] ');
        if ($case['part'] !== null) {
            expect($e->getMessage())->toContain($case['part']);
        }
    }
})->with(fn () => array_map(fn ($c) => [$c], array_column(relsTargetCases()['raises'], null, 'name')));

it('keeps a missing part a RuntimeException, as a missing workbook.xml is', function () {
    expect(fn () => Agent::read(relsTargetFixture('missing-sheet.xlsx')))->toThrow(RuntimeException::class);
});
