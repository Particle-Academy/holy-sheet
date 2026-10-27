<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Schema\Normalizer;

/**
 * An explicit `{value: <string>}` cell is a STRING cell, written exactly as
 * given.
 *
 * The object form is how a caller says "I mean this literally" (it is already
 * the documented escape hatch for a leading "="). Numeric-string coercion ran
 * on it anyway, so `{value: "+1"}` was written as the number 1 and
 * `{value: "007"}` as 7: a phone prefix, a zip code or a part number silently
 * lost its characters. Bare strings keep their promotion rules unchanged.
 *
 * The Node and Python engines carry the same table; keep the three in step.
 */
function explicitXlsx(array $schema): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'hs_explicit_').'.xlsx';
    Agent::write($schema, $tmp);

    return $tmp;
}

function explicitCellXml(string $path, string $ref): string
{
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    preg_match('/<c r="'.$ref.'"[ >].*?(?:\/>|<\/c>)/', $xml, $m);

    return $m[0] ?? '(no '.$ref.')';
}

dataset('numeric-looking strings', ['+1', '007', '1e5', '-0', '1.50', ' 12 ', '0.0', '-3']);

it('writes an explicit numeric-looking string in a cells map as a string cell', function (string $s) {
    $schema = ['sheets' => [['name' => 'S', 'cells' => ['A1' => ['value' => $s]]]]];

    expect((new Normalizer())->normalize($schema)->sheets[0]->cells['A1']->value)->toBe($s);

    $path = explicitXlsx($schema);
    $xml = explicitCellXml($path, 'A1');
    $read = Agent::describe($path);
    unlink($path);

    expect($xml)->toContain('t="inlineStr"');
    expect($xml)->toContain('<t xml:space="preserve">'.$s.'</t>');
    expect($read['sheets'][0]['cells']['A1']['value'])->toBe($s);
})->with('numeric-looking strings');

it('writes an explicit numeric-looking string in a row as a string cell', function (string $s) {
    $schema = ['sheets' => [[
        'name' => 'S',
        'columns' => [['header' => 'Code']],
        'rows' => [[['value' => $s]]],
    ]]];

    expect((new Normalizer())->normalize($schema)->sheets[0]->cells['A2']->value)->toBe($s);

    $path = explicitXlsx($schema);
    $xml = explicitCellXml($path, 'A2');
    $read = Agent::describe($path);
    unlink($path);

    expect($xml)->toContain('t="inlineStr"');
    expect($read['sheets'][0]['cells']['A2']['value'])->toBe($s);
})->with('numeric-looking strings');

it('keeps an explicit string a string under a numeric column type and a number format', function () {
    $schema = ['sheets' => [[
        'name' => 'S',
        'columns' => [['header' => 'N', 'type' => 'number'], ['header' => 'C', 'type' => 'currency']],
        'rows' => [[['value' => '+1'], ['value' => '007', 'format' => ['displayFormat' => 'currency']]]],
    ]]];

    $cells = (new Normalizer())->normalize($schema)->sheets[0]->cells;

    expect($cells['A2']->value)->toBe('+1')
        ->and($cells['B2']->value)->toBe('007');
});

it('does not promote an explicit leading-= string to a formula', function () {
    $cell = (new Normalizer())->normalize(['sheets' => [['name' => 'S', 'cells' => ['A1' => ['value' => '=1+1']]]]])
        ->sheets[0]->cells['A1'];

    expect($cell->formula)->toBeNull()->and($cell->value)->toBe('=1+1');
});

it('leaves bare strings, explicit numbers and explicit dates as they were', function () {
    $cells = (new Normalizer())->normalize(['sheets' => [[
        'name' => 'S',
        'cells' => [
            'A1' => '+1',                                                        // bare: still coerced
            'A2' => '=SUM(B1:B2)',                                               // bare: still a formula
            'A3' => ['value' => 1],                                              // explicit number
            'A4' => ['value' => '2024-03-15', 'format' => ['displayFormat' => 'date']], // date: still a serial
        ],
    ]]])->sheets[0]->cells;

    expect($cells['A1']->value)->toBe(1)
        ->and($cells['A2']->formula)->toBe('SUM(B1:B2)')
        ->and($cells['A3']->value)->toBe(1)
        ->and($cells['A4']->value)->toBe(45366.0);

    $rows = (new Normalizer())->normalize(['sheets' => [[
        'name' => 'S',
        'columns' => [['header' => 'N']],
        'rows' => [['+1']],
    ]]])->sheets[0]->cells;

    expect($rows['A2']->value)->toBe(1);
});
