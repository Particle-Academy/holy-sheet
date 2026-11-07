<?php

declare(strict_types=1);

use HolySheet\Agent;

/**
 * Data validation: the dropdown, plus whole / decimal / date / text-length
 * rules, an input prompt and an error alert. LiftPoint's catalog templates
 * need a Category dropdown that a person filling the template cannot get
 * wrong; until 2.12.0 the schema could not ask for one.
 *
 * A rule attaches three ways: on a column (`columns[].validation`, covering
 * that column's data rows), or in the sheet's `dataValidations` list with an
 * explicit `range` (the form a template uses, running past the sample rows).
 *
 * The emitted bytes are the contract: holy-sheet-js and holy-sheet-py diff
 * their worksheet part against this writer's, using the fixture below.
 */
function dvSheetXml(array $schema, int $sheet = 1): string
{
    $parts = dvParts($schema);

    return $parts["xl/worksheets/sheet{$sheet}.xml"];
}

/** @return array<string,string> */
function dvParts(array $schema): array
{
    $path = tempnam(sys_get_temp_dir(), 'dv').'.xlsx';
    Agent::write($schema, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $out[$name] = (string) $zip->getFromName($name);
    }
    $zip->close();
    @unlink($path);

    return $out;
}

/** The parity fixture, verbatim in holy-sheet-js and holy-sheet-py. */
function dvFixture(): array
{
    return [
        'meta' => ['creator' => 'Parity', 'created' => '2024-01-01T00:00:00Z'],
        'sheets' => [
            [
                'name' => 'Catalog',
                'columns' => [
                    ['header' => 'SKU'],
                    ['header' => 'Category', 'validation' => [
                        'type' => 'list',
                        'source' => "'Lists'!A1:A3",
                        'prompt' => ['title' => 'Category', 'text' => 'Pick a category.'],
                        'error' => ['style' => 'stop', 'title' => 'Unknown category', 'text' => 'Choose a category from the list.'],
                    ]],
                    ['header' => 'Status', 'validation' => ['type' => 'list', 'values' => ['Draft', 'Live', 'Retired "old"'], 'showDropdown' => true]],
                    ['header' => 'Qty', 'type' => 'integer', 'validation' => ['type' => 'whole', 'operator' => 'between', 'min' => 0, 'max' => 1000]],
                ],
                'rows' => [
                    ['A-1', 'Tools', 'Draft', 3],
                    ['A-2', 'Parts', 'Live', 10],
                ],
                'mergedRegions' => [['start' => 'K1', 'end' => 'L1']],
                'print' => ['orientation' => 'landscape'],
                'dataValidations' => [
                    ['range' => 'E2:E500', 'type' => 'decimal', 'operator' => 'greaterThanOrEqual', 'value' => 0.5, 'allowBlank' => false,
                        'error' => ['style' => 'warning', 'text' => 'Below the minimum & then some <x>']],
                    ['range' => 'F2:F500', 'type' => 'date', 'operator' => 'notBetween', 'min' => '2026-01-01', 'max' => '2026-12-31',
                        'error' => ['style' => 'information']],
                    ['range' => 'G2:G500', 'type' => 'textLength', 'operator' => 'lessThanOrEqual', 'value' => 40,
                        'prompt' => ['text' => 'At most 40 characters.']],
                    ['range' => 'H2:H500', 'type' => 'list', 'values' => ['Yes', 'No', 7], 'showDropdown' => false],
                    ['range' => 'i2:I500', 'type' => 'list', 'source' => 'A20:A22'],
                    ['range' => 'J2', 'type' => 'decimal', 'operator' => 'lessThan', 'value' => 2.25],
                ],
            ],
            ['name' => 'Lists', 'cells' => ['A1' => 'Tools', 'A2' => 'Parts', 'A3' => 'Kits']],
        ],
    ];
}

const DV_EXPECTED = '<dataValidations count="9">'
    .'<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" errorTitle="Unknown category" error="Choose a category from the list." promptTitle="Category" prompt="Pick a category." sqref="B2:B3"><formula1>&apos;Lists&apos;!$A$1:$A$3</formula1></dataValidation>'
    .'<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="C2:C3"><formula1>&quot;Draft,Live,Retired &quot;&quot;old&quot;&quot;&quot;</formula1></dataValidation>'
    .'<dataValidation type="whole" allowBlank="1" showErrorMessage="1" sqref="D2:D3"><formula1>0</formula1><formula2>1000</formula2></dataValidation>'
    .'<dataValidation type="decimal" errorStyle="warning" operator="greaterThanOrEqual" showErrorMessage="1" error="Below the minimum &amp; then some &lt;x&gt;" sqref="E2:E500"><formula1>0.5</formula1></dataValidation>'
    .'<dataValidation type="date" errorStyle="information" operator="notBetween" allowBlank="1" showErrorMessage="1" sqref="F2:F500"><formula1>46023</formula1><formula2>46387</formula2></dataValidation>'
    .'<dataValidation type="textLength" operator="lessThanOrEqual" allowBlank="1" showInputMessage="1" showErrorMessage="1" prompt="At most 40 characters." sqref="G2:G500"><formula1>40</formula1></dataValidation>'
    .'<dataValidation type="list" allowBlank="1" showDropDown="1" showErrorMessage="1" sqref="H2:H500"><formula1>&quot;Yes,No,7&quot;</formula1></dataValidation>'
    .'<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="I2:I500"><formula1>$A$20:$A$22</formula1></dataValidation>'
    .'<dataValidation type="decimal" operator="lessThan" allowBlank="1" showErrorMessage="1" sqref="J2"><formula1>2.25</formula1></dataValidation>'
    .'</dataValidations>';

it('accepts the whole fixture without an error', function () {
    expect(Agent::validate(dvFixture()))->toBe([]);
});

it('writes every validation, column rules first, then the sheet list', function () {
    expect(dvSheetXml(dvFixture()))->toContain(DV_EXPECTED);
});

it('places dataValidations after mergeCells and before pageSetup, as CT_Worksheet orders them', function () {
    $xml = dvSheetXml(dvFixture());

    $merge = strpos($xml, '</mergeCells>');
    $dv = strpos($xml, '<dataValidations ');
    $page = strpos($xml, '<pageSetup ');
    expect($merge)->toBeInt()->and($dv)->toBeInt()->and($page)->toBeInt()
        ->and($merge)->toBeLessThan($dv)
        ->and($dv)->toBeLessThan($page);
});

it('writes nothing for a sheet without validations, byte for byte', function () {
    $schema = dvFixture();
    unset($schema['sheets'][0]['dataValidations']);
    foreach ($schema['sheets'][0]['columns'] as $i => $col) {
        unset($schema['sheets'][0]['columns'][$i]['validation']);
    }

    expect(dvSheetXml($schema))->not->toContain('dataValidation');
});

it('writes a single-row column rule as one cell', function () {
    $xml = dvSheetXml(['sheets' => [[
        'name' => 'S',
        'columns' => [['header' => 'Status', 'validation' => ['type' => 'list', 'values' => ['a', 'b']]]],
        'rows' => [['a']],
    ]]]);

    expect($xml)->toContain('sqref="A2"><formula1>&quot;a,b&quot;</formula1>');
});

it('quotes a sheet name with an apostrophe in a source', function () {
    $xml = dvSheetXml(['sheets' => [
        ['name' => 'S', 'cells' => ['A1' => 'x'], 'dataValidations' => [['range' => 'A2:A9', 'type' => 'list', 'source' => "'Bob''s list'!\$B\$2:B4"]]],
        ['name' => "Bob's list", 'cells' => ['B2' => 'y']],
    ]]);

    expect($xml)->toContain('<formula1>&apos;Bob&apos;&apos;s list&apos;!$B$2:$B$4</formula1>');
});

it('counts an inline list at 255 characters, commas included', function () {
    // 51 values of "abcd" joined by commas is 51*4 + 50 = 254; one more char is 255.
    $values = array_fill(0, 51, 'abcd');
    $at = fn (array $v) => Agent::validate(['sheets' => [['name' => 'S', 'cells' => [], 'dataValidations' => [['range' => 'A1', 'type' => 'list', 'values' => $v]]]]]);

    expect($at($values))->toBe([]);
    $values[0] = 'abcde';
    expect($at($values))->toBe([]);
    $values[1] = 'abcde';
    $errors = $at($values);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['path'])->toBe('sheets[0].dataValidations[0].values')
        ->and($errors[0]['expected'])->toBe('at most 255 characters, commas included')
        ->and($errors[0]['got'])->toBe('256 characters')
        ->and($errors[0]['hint'])->toBe('Excel cannot store an inline list longer than 255 characters. Put the choices in cells and point source at them, like "\'Lists\'!A1:A40".');
});

it('counts characters, not bytes', function () {
    // 127 two-byte characters + "," + 127 more = 255 characters, 509 bytes.
    $v = str_repeat('é', 127);
    $errors = Agent::validate(['sheets' => [['name' => 'S', 'cells' => [], 'dataValidations' => [['range' => 'A1', 'type' => 'list', 'values' => [$v, $v]]]]]]);

    expect($errors)->toBe([]);
});

/** @return list<array{0:string,1:string}> path => expected */
function dvErrors(array $validation, string $where = 'sheet'): array
{
    $sheet = ['name' => 'S', 'columns' => [['header' => 'A']], 'rows' => [['x']]];
    if ($where === 'sheet') {
        $sheet['dataValidations'] = [$validation];
    } else {
        $sheet['columns'][0]['validation'] = $validation;
    }
    $schema = ['sheets' => [$sheet, ['name' => 'Lists', 'cells' => ['A1' => 'a']]]];

    return array_map(fn (array $e) => [$e['path'], $e['expected']], Agent::validate($schema));
}

it('reports each bad validation at its path, in PHP wording', function (array $validation, array $expected, string $where = 'sheet') {
    expect(dvErrors($validation, $where))->toBe($expected);
})->with([
    'not an object' => [['list'], [['sheets[0].dataValidations[0]', 'object']]],
    'no type' => [['range' => 'A2'], [['sheets[0].dataValidations[0].type', 'one of: list, whole, decimal, date, textLength']]],
    'unknown type' => [['range' => 'A2', 'type' => 'dropdown', 'values' => ['a']], [['sheets[0].dataValidations[0].type', 'one of: list, whole, decimal, date, textLength']]],
    'no range' => [['type' => 'list', 'values' => ['a']], [['sheets[0].dataValidations[0].range', 'a cell or range on this sheet, like "B2:B100"']]],
    'range on another sheet' => [['range' => 'Lists!A2', 'type' => 'list', 'values' => ['a']], [['sheets[0].dataValidations[0].range', 'a cell or range on this sheet, like "B2:B100"']]],
    'range past the grid' => [['range' => 'A2:XFE2', 'type' => 'list', 'values' => ['a']], [['sheets[0].dataValidations[0].range', 'a cell or range on this sheet, like "B2:B100"']]],
    'range on a column rule' => [['range' => 'A2', 'type' => 'list', 'values' => ['a']], [['sheets[0].columns[0].validation.range', 'absent']], 'column'],
    'both values and source' => [['range' => 'A2', 'type' => 'list', 'values' => ['a'], 'source' => 'Lists!A1'], [['sheets[0].dataValidations[0]', 'exactly one of values or source']]],
    'neither values nor source' => [['range' => 'A2', 'type' => 'list'], [['sheets[0].dataValidations[0]', 'exactly one of values or source']]],
    'values not a list' => [['range' => 'A2', 'type' => 'list', 'values' => 'a,b'], [['sheets[0].dataValidations[0].values', 'a non-empty list of choices']]],
    'empty values' => [['range' => 'A2', 'type' => 'list', 'values' => []], [['sheets[0].dataValidations[0].values', 'a non-empty list of choices']]],
    'a choice with a comma' => [['range' => 'A2', 'type' => 'list', 'values' => ['a', 'b,c', '', 1.5, true]], [
        ['sheets[0].dataValidations[0].values[1]', 'a non-empty string with no comma, or a whole number'],
        ['sheets[0].dataValidations[0].values[2]', 'a non-empty string with no comma, or a whole number'],
        ['sheets[0].dataValidations[0].values[3]', 'a non-empty string with no comma, or a whole number'],
        ['sheets[0].dataValidations[0].values[4]', 'a non-empty string with no comma, or a whole number'],
    ]],
    'source not a range' => [['range' => 'A2', 'type' => 'list', 'source' => 'Lists'], [['sheets[0].dataValidations[0].source', 'a cell range like "\'Lists\'!A1:A10"']]],
    'source on an unknown sheet' => [['range' => 'A2', 'type' => 'list', 'source' => "'Lsts'!A1:A3"], [['sheets[0].dataValidations[0].source', 'a sheet in this workbook']]],
    'operator on a list' => [['range' => 'A2', 'type' => 'list', 'values' => ['a'], 'operator' => 'equal', 'value' => 1], [
        ['sheets[0].dataValidations[0].operator', 'absent'],
        ['sheets[0].dataValidations[0].value', 'absent'],
    ]],
    'list keys on a number rule' => [['range' => 'A2', 'type' => 'whole', 'value' => 1, 'operator' => 'equal', 'values' => ['a'], 'source' => 'A1', 'showDropdown' => true], [
        ['sheets[0].dataValidations[0].values', 'absent'],
        ['sheets[0].dataValidations[0].source', 'absent'],
        ['sheets[0].dataValidations[0].showDropdown', 'absent'],
    ]],
    'unknown operator' => [['range' => 'A2', 'type' => 'whole', 'operator' => 'atLeast', 'value' => 1], [['sheets[0].dataValidations[0].operator', 'one of: between, notBetween, equal, notEqual, greaterThan, lessThan, greaterThanOrEqual, lessThanOrEqual']]],
    'between without bounds' => [['range' => 'A2', 'type' => 'whole'], [
        ['sheets[0].dataValidations[0].min', 'a whole number'],
        ['sheets[0].dataValidations[0].max', 'a whole number'],
    ]],
    'between with value' => [['range' => 'A2', 'type' => 'decimal', 'min' => 1, 'max' => 2, 'value' => 3], [['sheets[0].dataValidations[0].value', 'absent']]],
    'one-value operator with bounds' => [['range' => 'A2', 'type' => 'decimal', 'operator' => 'greaterThan', 'min' => 1, 'max' => 2], [
        ['sheets[0].dataValidations[0].value', 'a number'],
        ['sheets[0].dataValidations[0].min', 'absent'],
        ['sheets[0].dataValidations[0].max', 'absent'],
    ]],
    'whole with a fraction' => [['range' => 'A2', 'type' => 'whole', 'operator' => 'equal', 'value' => 1.5], [['sheets[0].dataValidations[0].value', 'a whole number']]],
    'decimal with a string' => [['range' => 'A2', 'type' => 'decimal', 'operator' => 'equal', 'value' => '1.5'], [['sheets[0].dataValidations[0].value', 'a number']]],
    'decimal with a bool' => [['range' => 'A2', 'type' => 'decimal', 'operator' => 'equal', 'value' => true], [['sheets[0].dataValidations[0].value', 'a number']]],
    'date not ISO' => [['range' => 'A2', 'type' => 'date', 'operator' => 'equal', 'value' => '31/12/2026'], [['sheets[0].dataValidations[0].value', 'a date as YYYY-MM-DD']]],
    'date not a day' => [['range' => 'A2', 'type' => 'date', 'operator' => 'equal', 'value' => '2026-02-30'], [['sheets[0].dataValidations[0].value', 'a date as YYYY-MM-DD']]],
    'negative text length' => [['range' => 'A2', 'type' => 'textLength', 'operator' => 'equal', 'value' => -1], [['sheets[0].dataValidations[0].value', 'a whole number of characters, 0 or more']]],
    'min above max' => [['range' => 'A2', 'type' => 'date', 'min' => '2026-12-31', 'max' => '2026-01-01'], [['sheets[0].dataValidations[0].max', 'a value no less than min']]],
    'flags not booleans' => [['range' => 'A2', 'type' => 'list', 'values' => ['a'], 'allowBlank' => 1, 'showDropdown' => 'no'], [
        ['sheets[0].dataValidations[0].showDropdown', 'boolean'],
        ['sheets[0].dataValidations[0].allowBlank', 'boolean'],
    ]],
    'bad prompt' => [['range' => 'A2', 'type' => 'list', 'values' => ['a'], 'prompt' => 'pick'], [['sheets[0].dataValidations[0].prompt', 'object {title?, text?}']]],
    'long prompt' => [['range' => 'A2', 'type' => 'list', 'values' => ['a'], 'prompt' => ['title' => str_repeat('t', 33), 'text' => str_repeat('p', 256)]], [
        ['sheets[0].dataValidations[0].prompt.title', 'a string of at most 32 characters'],
        ['sheets[0].dataValidations[0].prompt.text', 'a string of at most 255 characters'],
    ]],
    'bad error' => [['range' => 'A2', 'type' => 'list', 'values' => ['a'], 'error' => ['style' => 'fatal', 'title' => 7, 'text' => str_repeat('e', 226)]], [
        ['sheets[0].dataValidations[0].error.style', 'one of: stop, warning, information'],
        ['sheets[0].dataValidations[0].error.title', 'a string of at most 32 characters'],
        ['sheets[0].dataValidations[0].error.text', 'a string of at most 225 characters'],
    ]],
    'column rule fine' => [['type' => 'list', 'values' => ['a']], [], 'column'],
]);

it('refuses a column rule on a sheet with no data rows, and says what to use instead', function () {
    $errors = Agent::validate(['sheets' => [[
        'name' => 'Template',
        'columns' => [['header' => 'Category', 'validation' => ['type' => 'list', 'values' => ['a']]]],
        'rows' => [],
    ]]]);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['path'])->toBe('sheets[0].columns[0].validation')
        ->and($errors[0]['expected'])->toBe('a sheet with data rows')
        ->and($errors[0]['hint'])->toBe('A column validation covers the column\'s data rows, and this sheet has none. For a template, put it in the sheet\'s dataValidations with a range, like "B2:B500".');
});

it('refuses a dataValidations that is not a list', function () {
    $errors = Agent::validate(['sheets' => [['name' => 'S', 'cells' => [], 'dataValidations' => ['range' => 'A1']]]]);

    expect(array_column($errors, 'path'))->toBe(['sheets[0].dataValidations'])
        ->and($errors[0]['expected'])->toBe('a list of validations');
});

it('gives a hint that names the comma rule', function () {
    $errors = Agent::validate(['sheets' => [['name' => 'S', 'cells' => [], 'dataValidations' => [['range' => 'A1', 'type' => 'list', 'values' => ['a,b']]]]]]);

    expect($errors[0]['hint'])->toBe('Each choice is text, like "Open", or a whole number. Excel separates inline choices with commas and cannot escape one, so a choice containing a comma must come from cells: use source.');
});

it('repairs only the case of a type, operator or error style', function () {
    $out = Agent::validateAndRepair(['sheets' => [[
        'name' => 'S',
        'columns' => [['header' => 'A', 'validation' => ['type' => 'List', 'values' => ['a']]]],
        'rows' => [['a']],
        'dataValidations' => [
            ['range' => 'B2', 'type' => 'WHOLE', 'operator' => 'greaterthan', 'value' => 1, 'error' => ['style' => 'Warning']],
            ['range' => 'C2', 'type' => 'dropdown', 'values' => ['a']],
        ],
    ]]]);

    expect($out['repairs'])->toBe([
        "changed 'sheets[0].columns[0].validation.type' from 'List' to 'list'",
        "changed 'sheets[0].dataValidations[0].type' from 'WHOLE' to 'whole'",
        "changed 'sheets[0].dataValidations[0].operator' from 'greaterthan' to 'greaterThan'",
        "changed 'sheets[0].dataValidations[0].error.style' from 'Warning' to 'warning'",
    ])
        ->and(array_column($out['errors'], 'path'))->toBe(['sheets[0].dataValidations[1].type'])
        ->and($out['schema']['sheets'][0]['dataValidations'][0]['operator'])->toBe('greaterThan');
});

it('does not repair a validation that is already right', function () {
    $out = Agent::validateAndRepair(dvFixture());

    expect($out['repairs'])->toBe([])->and($out['errors'])->toBe([]);
});
