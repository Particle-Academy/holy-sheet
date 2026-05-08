<?php

declare(strict_types=1);

use HolySheet\Agent;

it('returns no issues for a workbook with valid formulas', function () {
    $schema = [
        'sheets' => [[
            'name' => 'Q4',
            'rows' => [
                ['Region', 'Revenue', 'Doubled'],
                ['NA', 100, ['formula' => 'B2*2']],
                ['EU', 200, ['formula' => 'B3*2']],
                ['Total', ['formula' => 'SUM(B2:B3)'], ['formula' => 'SUM(C2:C3)']],
            ],
        ]],
    ];
    expect(Agent::lint($schema))->toBe([]);
});

it('catches the header-row off-by-one bug and suggests the correct row', function () {
    $schema = [
        'sheets' => [[
            'name' => 'Q4',
            'rows' => [
                ['Region', 'Annual', 'Monthly'],
                ['NA', 12000, ['formula' => 'B1*12']],
            ],
        ]],
    ];
    $issues = Agent::lint($schema);
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#VALUE!')
        ->and($issues[0]['address'])->toBe('C2')
        ->and($issues[0]['hint'])->toContain('B1 = "Annual" (string)')
        ->and($issues[0]['hint'])->toContain('Did you mean B2');
});

it('catches division by zero', function () {
    $schema = [
        'sheets' => [[
            'name' => 'D',
            'rows' => [['x'], [0], [['formula' => '100/A2']]]
        ]],
    ];
    $issues = Agent::lint($schema);
    expect($issues[0]['error'])->toBe('#DIV/0!');
});

it('catches circular references', function () {
    $schema = [
        'sheets' => [[
            'name' => 'C',
            'rows' => [
                ['x'],
                [['formula' => 'A1+1']], // refers to header text
                [['formula' => 'A2']],   // self-loop via A2 = A2
            ],
        ]],
    ];
    // A2 = A1+1 evaluates header "x" + 1 → #VALUE!. A3 = A2 inherits the error.
    $issues = Agent::lint($schema);
    expect(count($issues))->toBeGreaterThan(0);
});

it('detects true circular dependency (A1 = B1, B1 = A1)', function () {
    $schema = [
        'sheets' => [[
            'name' => 'C',
            'cells' => [
                'A1' => ['formula' => 'B1'],
                'B1' => ['formula' => 'A1'],
            ],
        ]],
    ];
    $issues = Agent::lint($schema);
    expect($issues)->not->toBeEmpty()
        ->and($issues[0]['error'])->toBe('#CIRC!');
});

it('flags unknown function names as #NAME?', function () {
    $schema = [
        'sheets' => [[
            'name' => 'F',
            'rows' => [['x'], [['formula' => 'BOGUSFN(1,2)']]]
        ]],
    ];
    expect(Agent::lint($schema)[0]['error'])->toBe('#NAME?');
});

it('evaluates cross-sheet references', function () {
    $schema = [
        'sheets' => [
            ['name' => 'Detail', 'rows' => [['x'], [100], [200]]],
            [
                'name' => 'Summary',
                'cells' => [
                    'A1' => ['value' => 'Total'],
                    'B1' => ['formula' => 'SUM(Detail!A2:A3)'],
                ],
            ],
        ],
    ];
    expect(Agent::lint($schema))->toBe([]);
});

it('handles SUM across a numeric column with no errors', function () {
    $schema = [
        'sheets' => [[
            'name' => 'S',
            'rows' => [
                ['x'],
                [10],
                [20],
                [30],
                [['formula' => 'SUM(A2:A4)']],
                [['formula' => 'AVERAGE(A2:A4)']],
            ],
        ]],
    ];
    expect(Agent::lint($schema))->toBe([]);
});

it('catches arithmetic on a string in the middle of an expression', function () {
    $schema = [
        'sheets' => [[
            'name' => 'M',
            'rows' => [
                ['x', 'y'],
                [10, 'oops'],
                [20, 30],
                [['formula' => 'A2+B2']],
            ],
        ]],
    ];
    $issues = Agent::lint($schema);
    expect($issues[0]['error'])->toBe('#VALUE!')
        ->and($issues[0]['hint'])->toContain('B2 = "oops"');
});
