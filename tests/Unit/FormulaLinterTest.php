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

/*
 * Sheet names that need quoting — issue #6.
 *
 * Excel quotes any sheet name that is not a bare identifier ('My Sheet'!A1) and
 * escapes a literal quote by doubling it ('Q3 ''Final'''!B2). The tokenizer had
 * no case for `'`, so every such reference linted as #NAME? and an agent reading
 * the error concluded cross-sheet formulas were unsupported. `cleanSheetName()`
 * existed for exactly these names and could never be reached.
 */
function twoSheets(string $first, string $formula): array
{
    return ['sheets' => [
        ['name' => $first, 'columns' => [['header' => 'Deal Size', 'type' => 'number']], 'rows' => [[9407], [9750]]],
        ['name' => 'Summary', 'columns' => [['header' => 'Total', 'type' => 'number']], 'rows' => [[['formula' => $formula]]]],
    ]];
}

it('lints a reference to a quoted sheet name like an unquoted one', function () {
    expect(Agent::lint(twoSheets('My Earnings Projection', "SUM('My Earnings Projection'!A2:A3)")))->toBe([]);
});

it('resolves a quoted sheet reference to the real cell', function () {
    // Parsing is not resolving: a tokenizer that accepted the quote and then
    // looked the cell up under the quoted spelling would read a blank and lint
    // clean. A1 is the header text, so only a real lookup yields #VALUE!.
    $issues = Agent::lint(twoSheets('My Earnings Projection', "'My Earnings Projection'!A1*2"));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#VALUE!')
        ->and($issues[0]['hint'])->toContain('A1 = "Deal Size" (string)')
        ->and($issues[0]['hint'])->toContain('Did you mean A2');
});

it("reads Excel's doubled quote as one literal quote in a sheet name", function () {
    expect(Agent::lint(twoSheets("Q3 'Final'", "SUM('Q3 ''Final'''!A2:A3)")))->toBe([]);
});

it('reports #REF! for a quoted sheet that does not exist, naming it', function () {
    $issues = Agent::lint(twoSheets('Deals', "SUM('No Such Sheet'!A2:A3)"));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#REF!')
        // Byte-for-byte the Node and Python hint: an agent reads it, and the
        // three engines must say the same thing.
        ->and($issues[0]['hint'])->toBe("The formula refers to a sheet named 'No Such Sheet', and this workbook has no such sheet. Its sheets are: Deals, Summary. Quote a name that contains spaces or punctuation: 'My Sheet'!A1.");
});

it('reports #REF! for an unquoted sheet that does not exist', function () {
    // This used to lint clean: the missing sheet's cells read as blanks, so a
    // formula pointing at a sheet that was never created summed to zero and
    // passed. Excel shows #REF! for it.
    $issues = Agent::lint(twoSheets('Deals', 'SUM(Nope!A2:A3)'));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#REF!');
});

it('matches sheet names case-insensitively, as Excel does', function () {
    expect(Agent::lint(twoSheets('Deals', 'SUM(deals!A2:A3)')))->toBe([])
        ->and(Agent::lint(twoSheets('My Deals', "SUM('MY DEALS'!A2:A3)")))->toBe([]);

    // And the case-folded name reaches the real cells, not blanks.
    $issues = Agent::lint(twoSheets('Deals', 'DEALS!A1*2'));
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#VALUE!');
});

it('reports #NAME? for a quote that never closes', function () {
    $issues = Agent::lint(twoSheets('My Deals', "SUM('My Deals!A2:A3)"));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#NAME?');
});

it('reports #NAME? for a quoted name that is not a sheet reference', function () {
    $issues = Agent::lint(twoSheets('My Deals', "'My Deals'+1"));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#NAME?');
});
