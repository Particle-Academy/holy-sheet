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

/*
 * Excel semantics (fancy-formula plan, decision D17).
 *
 * The linter only reports errors, so a value is observed through a division:
 * `1/(<claim>)` lints clean exactly when the claim is TRUE (1/1), and as
 * #DIV/0! when it is FALSE (1/0). Division rather than IF because it is the
 * smaller claim: it does not depend on IF being lazy, which is tested on its
 * own below. holy-sheet writes for Excel, so Excel decides.
 */

/** @param array<string,mixed> $cells */
function lintFormula(string $formula, array $cells = []): array
{
    return Agent::lint(['sheets' => [[
        'name' => 'S',
        'cells' => $cells + ['Z1' => ['formula' => $formula]],
    ]]]);
}

/** @param array<string,mixed> $cells */
function lintClaim(string $claim, array $cells = []): array
{
    return lintFormula("1/({$claim})", $cells);
}

it('treats % as postfix percent, not a binary operator', function () {
    expect(lintClaim('50%=0.5'))->toBe([])
        ->and(lintClaim('A1%=0.5', ['A1' => ['value' => 50]]))->toBe([])
        ->and(lintClaim('-50%=-0.5'))->toBe([])
        ->and(lintClaim('200%*3=6'))->toBe([])
        ->and(lintClaim('(1+1)%=0.02'))->toBe([]);
});

it('reports a binary use of % as an error, since Excel rejects it', function () {
    // The old parser read 10%2 as 10/100*2 and passed it.
    $issues = lintFormula('10%2');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#NAME?');
});

it('binds ^ tighter than * and /', function () {
    expect(lintClaim('2*3^2=18'))->toBe([])
        ->and(lintClaim('10/2^2=2.5'))->toBe([])
        ->and(lintClaim('2^3*2=16'))->toBe([])
        // Excel's ^ is left-associative, and negation binds tighter than ^.
        ->and(lintClaim('2^3^2=64'))->toBe([])
        ->and(lintClaim('-2^2=4'))->toBe([])
        ->and(lintClaim('2^-1=0.5'))->toBe([])
        // Percent binds tighter still: (50%)^2.
        ->and(lintClaim('50%^2=0.25'))->toBe([]);
});

it('reads a doubled quote inside a text literal as one quote', function () {
    // ="a""b" is the three characters a"b.
    expect(lintClaim('LEN("a""b")=3'))->toBe([])
        ->and(lintClaim('"a""b"="a"&"""b"'))->toBe([])
        ->and(lintClaim('LEN("""")=1'))->toBe([])
        ->and(lintClaim('LEN("")=0'))->toBe([]);
});

it('reads TRUE and FALSE text as logicals in IF, in any case, as Excel does', function () {
    // The text "FALSE" WAS already falsy; that part matched Excel. What did not
    // was every other spelling: "false" counted as TRUE.
    expect(lintClaim('IF("FALSE",1,2)=2'))->toBe([])
        ->and(lintClaim('IF("false",1,2)=2'))->toBe([])
        ->and(lintClaim('IF("True",1,2)=1'))->toBe([])
        ->and(lintClaim('IF(A1,1,2)=2', ['A1' => ['value' => 'FALSE']]))->toBe([])
        // An empty cell is FALSE, a non-zero number TRUE.
        ->and(lintClaim('IF(A1,1,2)=2'))->toBe([])
        ->and(lintClaim('IF(-3,1,2)=1'))->toBe([]);
});

it('reports #VALUE! when IF is given text that is not TRUE or FALSE', function () {
    // The old linter called any other text TRUE and "" FALSE. Excel errors.
    foreach (['IF("abc",1,2)', 'IF("",1,2)', 'IF(A1,1,2)'] as $formula) {
        $issues = lintFormula($formula, ['A1' => ['value' => 'yes']]);
        expect($issues)->toHaveCount(1)
            ->and($issues[0]['error'])->toBe('#VALUE!');
    }
});

it('propagates an error in the IF condition instead of choosing a branch', function () {
    $issues = lintFormula('IF(A1,1,2)', ['A1' => ['formula' => '1/0']]);
    $z1 = array_values(array_filter($issues, fn ($i) => $i['address'] === 'Z1'));

    expect($z1)->toHaveCount(1)
        ->and($z1[0]['error'])->toBe('#DIV/0!');
});

it('counts characters in LEN, not bytes', function () {
    expect(lintClaim('LEN("é")=1'))->toBe([])
        ->and(lintClaim('LEN("naïve café")=10'))->toBe([])
        ->and(lintClaim('LEN(A1)=2', ['A1' => ['value' => '日本']]))->toBe([])
        // Excel counts UTF-16 code units: a character outside the BMP is 2.
        ->and(lintClaim('LEN("😀")=2'))->toBe([]);
});

it('compares numeric text as text, which Excel sorts above every number', function () {
    // ="10">9 is TRUE in Excel, but so is 10>9, so it cannot tell the two
    // readings apart. "5">9 can.
    expect(lintClaim('"5">9'))->toBe([])
        ->and(lintClaim('"10">9'))->toBe([])
        ->and(lintClaim('"10"<>10'))->toBe([])
        ->and(lintClaim('"2">"10"'))->toBe([])
        ->and(lintClaim('A1>99', ['A1' => ['value' => '10']]))->toBe([]);
});

it('compares the rest as Excel does: text ignores case, logicals sort last, blanks match', function () {
    expect(lintClaim('"a"="A"'))->toBe([])
        ->and(lintClaim('"abc"<"ABD"'))->toBe([])
        ->and(lintClaim('TRUE>1'))->toBe([])
        ->and(lintClaim('TRUE>"zzz"'))->toBe([])
        ->and(lintClaim('FALSE<TRUE'))->toBe([])
        ->and(lintClaim('A1=0'))->toBe([])
        ->and(lintClaim('A1=""'))->toBe([])
        ->and(lintClaim('A1=FALSE'))->toBe([]);
});

it('propagates an error through a comparison', function () {
    $issues = lintFormula('A1>0', ['A1' => ['formula' => '1/0']]);
    $z1 = array_values(array_filter($issues, fn ($i) => $i['address'] === 'Z1'));

    expect($z1)->toHaveCount(1)
        ->and($z1[0]['error'])->toBe('#DIV/0!');
});

it('says a malformed formula is a syntax error, never an unknown function', function () {
    // #NAME? stays the label (see FormulaLinter::hintName). The hint used to be
    // one sentence for every cause, "references an unknown function or has a
    // syntax error", which sent an agent hunting for a function that was fine.
    $malformed = ['SUM(1,2', '(1+2', '1 2', '1+', '1 # 2', '10%2', '"abc', "'S'+1", 'SUM(1,,'];
    foreach ($malformed as $formula) {
        $issues = lintFormula($formula);
        expect($issues)->toHaveCount(1)
            ->and($issues[0]['error'])->toBe('#NAME?')
            ->and($issues[0]['hint'])->toStartWith('Syntax error: ')
            ->and(strtolower($issues[0]['hint']))->not->toContain('unknown function');
    }
});

it('names the unknown function in its hint', function () {
    $issues = lintFormula('VLOOKUP(1, A1:B2, 2)');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#NAME?')
        ->and($issues[0]['hint'])->toStartWith('Unknown function VLOOKUP.')
        ->and($issues[0]['hint'])->toContain('SUM, AVERAGE');
});

it('reports an unknown function even in an IF branch that is not taken', function () {
    // Excel resolves names before it evaluates, so this is #NAME? in Excel.
    // The old linter returned #NAME? as a VALUE, and IF discarded it.
    $issues = lintFormula('IF(FALSE, BOGUSFN(1), 1)');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#NAME?')
        ->and($issues[0]['hint'])->toStartWith('Unknown function BOGUSFN.');
});

it('points an inherited #NAME? at the cell it came from', function () {
    $issues = lintFormula('A1+1', ['A1' => ['formula' => 'BOGUSFN(1)']]);
    $z1 = array_values(array_filter($issues, fn ($i) => $i['address'] === 'Z1'));

    expect($z1)->toHaveCount(1)
        ->and($z1[0]['error'])->toBe('#NAME?')
        ->and($z1[0]['hint'])->toContain('A1')
        ->and(strtolower($z1[0]['hint']))->not->toContain('unknown function');
});

it('rounds half away from zero on the decimal value, as Excel ROUND does', function () {
    // A GUARD, not a fix: PHP 8.4's round() already gives Excel's answer on
    // every case here (checked 2026-10-28). It pins the behaviour so the
    // fancy-formula delegation, or a "simpler" floor(x*100+0.5)/100, cannot
    // quietly turn 2.675 into 2.67 (2.675 is 2.67499999... as a double).
    $cases = [
        'ROUND(2.675,2)=2.68', 'ROUND(-2.675,2)=-2.68', 'ROUND(1.005,2)=1.01',
        'ROUND(0.285,2)=0.29', 'ROUND(8.325,2)=8.33', 'ROUND(2.5,0)=3',
        'ROUND(-2.5,0)=-3', 'ROUND(-0.5,0)=-1', 'ROUND(1250,-2)=1300',
        'ROUND(-1250,-2)=-1300', 'ROUND(1.45,1)=1.5',
    ];
    foreach ($cases as $claim) {
        expect(lintClaim($claim))->toBe([]);
    }
});

it('reports a text literal that never closes', function () {
    // The old tokenizer ran the literal to the end of the formula and passed it.
    $issues = lintFormula('"abc');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['error'])->toBe('#NAME?');
});

/*
 * IF is lazy, as in Excel: only the branch it takes is evaluated. The linter
 * evaluates while it parses, so the untaken branch is still PARSED (a syntax
 * error or an unknown function there is still reported) but never EVALUATED.
 */

it('does not evaluate the branch IF does not take', function () {
    // The guard every spreadsheet writes. Eager IF reported #DIV/0! on it.
    expect(lintFormula('IF(A2=0,0,1/A2)', ['A2' => ['value' => 0]]))->toBe([])
        ->and(lintFormula('IF(TRUE,1,1/0)'))->toBe([])
        ->and(lintFormula('IF(FALSE,1/0,1)'))->toBe([])
        ->and(lintFormula('IF(1,2,1/0)+1'))->toBe([])
        // An untaken branch reads no cells, so their text and errors stay out.
        ->and(lintFormula('IF(TRUE,1,A1*2)', ['A1' => ['value' => 'Annual']]))->toBe([])
        ->and(lintFormula('IF(FALSE,Nope!A1,1)'))->toBe([])
        ->and(lintFormula('IF(TRUE,1,foo)'))->toBe([])
        ->and(lintFormula('IF(TRUE,1,A1:foo)'))->toBe([]);
});

it('still evaluates the branch IF takes', function () {
    foreach (['IF(TRUE,1/0,1)', 'IF(FALSE,1,1/0)', 'IF(A2=0,1/A2,0)'] as $formula) {
        $issues = lintFormula($formula, ['A2' => ['value' => 0]]);
        expect($issues)->toHaveCount(1)
            ->and($issues[0]['error'])->toBe('#DIV/0!');
    }
});

it('reads an untaken IF branch without evaluating anything inside it, nested IFs included', function () {
    expect(lintFormula('IF(TRUE,1,IF(1/0,1/0,1/0))'))->toBe([])
        ->and(lintFormula('IF(FALSE,IF(1/0,1,2),3)'))->toBe([])
        ->and(lintFormula('IF(TRUE,1,SUM(1/0,"x"*2)&-("y")%)'))->toBe([])
        ->and(lintFormula('IF(TRUE,IF(FALSE,1/0,2),3)'))->toBe([]);
});

it('takes the else branch as FALSE when IF has none', function () {
    // =IF(FALSE,1) is FALSE in Excel, and FALSE is not an error.
    expect(lintFormula('IF(FALSE,1/0)'))->toBe([])
        // Through &, where FALSE is "FALSE" and a blank is "": IF(...)=FALSE
        // cannot tell them apart, since a blank equals FALSE.
        ->and(lintClaim('IF(FALSE,1)&""="FALSE"'))->toBe([]);
});

it('still reports a syntax error inside an untaken IF branch', function () {
    foreach (['IF(TRUE,1,2+)', 'IF(FALSE,(1,2)', 'IF(TRUE,1,"abc)', 'IF(TRUE,1,2 3)'] as $formula) {
        $issues = lintFormula($formula);
        expect($issues)->toHaveCount(1)
            ->and($issues[0]['error'])->toBe('#NAME?')
            ->and($issues[0]['hint'])->toStartWith('Syntax error: ');
    }
});

it('parses the whole IF before an error in its condition propagates', function () {
    // Excel refuses a formula it cannot parse, so the syntax error is the one
    // to report. (A computed error such as 1/0 still stops the parse where it
    // arises, in IF as anywhere else in a formula; this is the error the
    // condition evaluates to.)
    $issues = lintFormula('IF(A1,1,2+)', ['A1' => ['formula' => '1/0']]);
    $z1 = array_values(array_filter($issues, fn ($i) => $i['address'] === 'Z1'));

    expect($z1)->toHaveCount(1)
        ->and($z1[0]['error'])->toBe('#NAME?')
        ->and($z1[0]['hint'])->toStartWith('Syntax error: ');
});

it('still reports #VALUE! for an IF with fewer than two arguments', function () {
    foreach (['IF(TRUE)', 'IF()'] as $formula) {
        $issues = lintFormula($formula);
        expect($issues)->toHaveCount(1)
            ->and($issues[0]['error'])->toBe('#VALUE!');
    }
});

/*
 * Parity with holy-sheet-js and holy-sheet-py. Each port carries these same
 * cases with the same literal texts, so a hint or an ordering that drifts in
 * any one of the three fails there.
 */

it('writes the #NAME? hints byte for byte as the JS and Python ports do', function () {
    $tail = '. Excel cannot parse this formula. Check for an unbalanced parenthesis or quote, a missing operator or argument, or a character a formula cannot contain. Text goes in double quotes, and a quote inside text is written twice: "a""b".';
    $supported = 'Holy Sheet supports: SUM, AVERAGE, COUNT, COUNTA, MIN, MAX, IF, ROUND, ABS, LEN, UPPER, LOWER, CONCAT.';
    $cases = [
        ['SUM(1,2', "Syntax error: expected ')', but the formula ends".$tail],
        ['1 2', 'Syntax error: unexpected number 2 after a complete expression'.$tail],
        ['1+', 'Syntax error: the formula ends where a value was expected'.$tail],
        ['1 # 2', "Syntax error: unexpected character '#'".$tail],
        ['1 é 2', 'Syntax error: unexpected non-ASCII character outside a text literal'.$tail],
        ['"abc', 'Syntax error: a text literal opens with " and never closes'.$tail],
        ["'S'+1", "Syntax error: the quoted name 'S' is not followed by ! and a cell reference".$tail],
        ['SUM(1,,', "Syntax error: unexpected ',' where a value was expected".$tail],
        ['"a""b" 3', 'Syntax error: unexpected number 3 after a complete expression'.$tail],
        ['VLOOKUP(1, A2:B3, 2)', 'Unknown function VLOOKUP. '.$supported],
        ['A1+1', 'This formula depends on A1, whose value is #NAME?. Fix that cell first.'],
    ];
    foreach ($cases as [$formula, $hint]) {
        $issues = lintFormula($formula, ['A1' => ['formula' => 'BOGUSFN(1)']]);
        $z1 = array_values(array_filter($issues, fn ($i) => $i['address'] === 'Z1'));
        expect($z1)->toHaveCount(1)
            ->and($z1[0]['hint'])->toBe($hint);
    }
});

it('orders text by code point, which the ports match', function () {
    // strcmp on UTF-8 is code-point order. JavaScript's < compares UTF-16 code
    // units, which would put U+1F600 below U+FF5E; the JS port does not.
    expect(lintClaim('"😀">"～"'))->toBe([]);
});

it('tokenizes with ASCII character classes, which the ports match', function () {
    // ctype_space and ctype_alpha are ASCII-only, so a no-break space and "é"
    // outside a text literal are syntax errors. The ports' Unicode-aware
    // character tests had to be narrowed to agree.
    foreach (["1\u{00A0}+1", 'é1+1'] as $formula) {
        $issues = lintFormula($formula);
        expect($issues)->toHaveCount(1)
            ->and($issues[0]['hint'])->toStartWith('Syntax error: unexpected non-ASCII character outside a text literal.');
    }
});
