<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Exceptions\SchemaException;
use HolySheet\Schema\Normalizer;

/*
 * `columnWidths` entries that are not a column index and a width.
 *
 * Before 2.3.4 the three writers disagreed: PHP cast the key "abc" to 0 and
 * OVERWROTE column A's width, Python raised ValueError from to_bytes(), and Node
 * wrote a NaN column. One rule now (HolySheet\Schema\ColumnWidths), in all three.
 */

function cwSheet(array $widths): array
{
    return ['sheets' => [['name' => 'S', 'cells' => ['A1' => ['value' => 'x']], 'columnWidths' => $widths]]];
}

it('never lets a key that is not a column index overwrite a column', function () {
    // The key comes AFTER column A's width, so reading it as 0 would replace 120.
    $workbook = (new Normalizer())->normalize(cwSheet([0 => 120, 'abc' => 999, 1 => 80]));

    expect($workbook->sheets[0]->columnWidths)->toBe([0 => 120.0, 1 => 80.0]);
});

it('reports every bad entry by path instead of writing it', function () {
    $errors = Agent::validate(cwSheet([
        'abc' => 50,
        '-1' => 50,
        '16384' => 50,
        'B' => 50,
        '0' => 'wide',
        '1' => -5,
    ]));

    expect(array_column($errors, 'path'))->toBe([
        'sheets[0].columnWidths.abc',
        'sheets[0].columnWidths.-1',
        'sheets[0].columnWidths.16384',
        'sheets[0].columnWidths.B',
        'sheets[0].columnWidths.0',
        'sheets[0].columnWidths.1',
    ]);

    expect(fn () => Agent::toBytes(cwSheet(['abc' => 999])))->toThrow(SchemaException::class);
});

it('accepts indexes as ints or digit strings, widths as numbers or digit strings, and a list', function () {
    expect(Agent::validate(cwSheet([0 => 120, '1' => '80', 2 => 140.5, '16383' => 10])))->toBe([]);
    // PHP encodes widths keyed 0..n-1 as a JSON list; that is a valid map.
    expect(Agent::validate(cwSheet([120, 80])))->toBe([]);
});

it('repairs a letter key to its index and drops what it cannot repair', function () {
    $result = Agent::validateAndRepair(cwSheet(['B' => 90, 'abc' => 5, '0' => 'wide', '2' => 60]));

    expect($result['errors'])->toBe([]);
    expect($result['schema']['sheets'][0]['columnWidths'])->toBe([1 => 90, 2 => 60]);
    expect($result['repairs'])->toContain("converted 'sheets[0].columnWidths.B' to column index 1");
    expect($result['repairs'])->toContain("dropped 'sheets[0].columnWidths.abc' (not a column index and a width)");
    expect($result['repairs'])->toContain("dropped 'sheets[0].columnWidths.0' (not a column index and a width)");
});
