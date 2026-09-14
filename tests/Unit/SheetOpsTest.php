<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Ops\SheetDiff;
use HolySheet\Ops\SheetOpSchema;

/*
 * Agent::diff / Agent::reduce / Agent::opSchema (#7).
 *
 * What a version history built on these needs, pinned:
 *
 * 1. Round trip: reduce($a, diff($a, $b)) equals $b.
 * 2. Small edits stay small: one cell is one set_cell, an inserted row is one
 *    insert_rows plus its cells — asserted as the exact op TYPES, not just
 *    "fewer ops than a replace".
 * 3. A save without a change records nothing: diff($s, describe(toBytes($s))) === [].
 * 4. set_cell / set_range / set_workbook behave as fancy-sheets' reducer does.
 */

function hsWorkbook(): array
{
    return [
        'sheets' => [
            [
                'name' => 'Q3',
                'cells' => [
                    'A1' => ['value' => 'Region', 'format' => ['bold' => true]],
                    'B1' => ['value' => 'Revenue', 'format' => ['bold' => true]],
                    'A2' => ['value' => 'North'],
                    'B2' => ['value' => 1250000.5, 'format' => ['displayFormat' => 'currency', 'decimals' => 2]],
                    'A3' => ['value' => 'South'],
                    'B3' => ['value' => 980400.25],
                    'A4' => ['value' => 'West'],
                    'B4' => ['value' => 1410000],
                    'A5' => ['value' => 'Total', 'format' => ['bold' => true]],
                    'B5' => ['value' => null, 'formula' => 'SUM(B2:B4)'],
                ],
                'mergedRegions' => [['start' => 'A7', 'end' => 'B7']],
                'columnWidths' => [0 => 120, 1 => 140],
                'frozenRows' => 1,
            ],
            ['name' => 'Notes', 'cells' => ['A1' => ['value' => 'Checked by finance', 'comment' => ['text' => 'Signed off', 'author' => 'CFO']]]],
        ],
        'meta' => ['creator' => 'MOIC', 'created' => '2026-09-14T00:00:00Z'],
    ];
}

/** @param callable(array): array $edit */
function hsEdited(callable $edit): array
{
    return $edit(hsWorkbook());
}

dataset('edits', [
    'one value' => [fn (array $w) => hsSet($w, 'Q3', 'B3', ['value' => 990000]), ['set_cell']],
    'one format' => [fn (array $w) => hsSet($w, 'Q3', 'B2', ['value' => 1250000.5, 'format' => ['displayFormat' => 'currency', 'decimals' => 0]]), ['set_cell']],
    'a comment removed' => [fn (array $w) => hsSet($w, 'Notes', 'A1', ['value' => 'Checked by finance']), ['set_cell']],
    'a formula' => [fn (array $w) => hsSet($w, 'Q3', 'B5', ['value' => null, 'formula' => 'SUM(B2:B3)']), ['set_cell']],
    'a cell cleared' => [function (array $w) {
        unset($w['sheets'][0]['cells']['A4']);

        return $w;
    }, ['clear_cell']],
    'a row inserted' => [function (array $w) {
        $cells = [];
        foreach ($w['sheets'][0]['cells'] as $address => $cell) {
            preg_match('/^([A-Z]+)(\d+)$/', $address, $m);
            $row = (int) $m[2];
            $cells[$m[1].($row >= 3 ? $row + 1 : $row)] = $cell;
        }
        $cells['A3'] = ['value' => 'East'];
        $cells['B3'] = ['value' => 505000];
        $cells['B6'] = ['value' => null, 'formula' => 'SUM(B2:B5)'];
        $w['sheets'][0]['cells'] = $cells;
        $w['sheets'][0]['mergedRegions'] = [['start' => 'A8', 'end' => 'B8']];

        return $w;
    }, ['insert_rows', 'set_cell', 'set_cell', 'set_cell']],
    'a row deleted' => [function (array $w) {
        $cells = [];
        foreach ($w['sheets'][0]['cells'] as $address => $cell) {
            preg_match('/^([A-Z]+)(\d+)$/', $address, $m);
            $row = (int) $m[2];
            if ($row === 3) {
                continue;
            }
            $cells[$m[1].($row > 3 ? $row - 1 : $row)] = $cell;
        }
        $cells['B4'] = ['value' => null, 'formula' => 'SUM(B2:B3)'];
        $w['sheets'][0]['cells'] = $cells;
        $w['sheets'][0]['mergedRegions'] = [['start' => 'A6', 'end' => 'B6']];

        return $w;
    }, ['delete_rows', 'set_cell']],
    'a column inserted' => [function (array $w) {
        $cells = [];
        foreach ($w['sheets'][0]['cells'] as $address => $cell) {
            $cells[str_replace('B', 'C', $address)] = $cell;
        }
        $cells['B1'] = ['value' => 'Units', 'format' => ['bold' => true]];
        $cells['B2'] = ['value' => 12];
        $w['sheets'][0]['cells'] = $cells;
        $w['sheets'][0]['mergedRegions'] = [['start' => 'A7', 'end' => 'C7']];
        $w['sheets'][0]['columnWidths'] = [0 => 120, 1 => 80, 2 => 140];

        return $w;
    }, ['insert_columns', 'set_cell', 'set_cell', 'set_column_widths']],
    'a column deleted' => [function (array $w) {
        $w['sheets'][0]['cells'] = array_filter($w['sheets'][0]['cells'], fn (string $a) => $a[0] !== 'B', ARRAY_FILTER_USE_KEY);
        $w['sheets'][0]['mergedRegions'] = [['start' => 'A7', 'end' => 'A7']];
        $w['sheets'][0]['columnWidths'] = [0 => 120];

        return $w;
    }, ['delete_columns']],
    'a sheet added' => [function (array $w) {
        array_splice($w['sheets'], 1, 0, [['name' => 'Q4', 'cells' => ['A1' => ['value' => 'Region']]]]);

        return $w;
    }, ['add_sheet']],
    'a sheet removed' => [function (array $w) {
        array_pop($w['sheets']);

        return $w;
    }, ['remove_sheet']],
    'a sheet renamed' => [function (array $w) {
        $w['sheets'][1]['name'] = 'Sign-off';

        return $w;
    }, ['rename_sheet']],
    'a sheet renamed and edited' => [function (array $w) {
        $w['sheets'][1]['name'] = 'Sign-off';
        $w['sheets'][1]['cells']['A2'] = ['value' => 'Approved'];

        return $w;
    }, ['rename_sheet', 'set_cell']],
    'sheets reordered' => [function (array $w) {
        $w['sheets'] = array_reverse($w['sheets']);

        return $w;
    }, ['move_sheet']],
    'merges, widths, panes' => [function (array $w) {
        $w['sheets'][0]['mergedRegions'] = [];
        unset($w['sheets'][0]['mergedRegions']);
        $w['sheets'][0]['columnWidths'] = [0 => 200, 1 => 140];
        $w['sheets'][0]['frozenCols'] = 1;

        return $w;
    }, ['set_merged_regions', 'set_column_widths', 'set_frozen']],
    'meta' => [function (array $w) {
        $w['meta']['creator'] = 'Compass';

        return $w;
    }, ['set_meta']],
    'an authored sheet changed' => [function (array $w) {
        $w['sheets'][1] = ['name' => 'Notes', 'columns' => [['header' => 'Item'], ['header' => 'Owner']], 'rows' => [['Budget', 'CFO']]];

        return $w;
    }, ['replace_sheet']],
]);

function hsSet(array $w, string $sheet, string $address, array $cell): array
{
    foreach ($w['sheets'] as $i => $s) {
        if ($s['name'] === $sheet) {
            $w['sheets'][$i]['cells'][$address] = $cell;
        }
    }

    return $w;
}

it('reproduces the target exactly, with the smallest ops', function (callable $edit, array $types) {
    $a = hsWorkbook();
    $b = $edit(hsWorkbook());

    $ops = Agent::diff($a, $b);

    expect(array_column($ops, 'type'))->toBe($types);
    expect(SheetDiff::same(Agent::reduce($a, $ops), $b))->toBeTrue();

    // And back: the reverse diff is what a version history stores.
    $reverse = Agent::diff($b, $a);
    expect(SheetDiff::same(Agent::reduce($b, $reverse), $a))->toBeTrue();
})->with('edits');

it('records nothing for a save without a change', function () {
    $described = hsWorkbook();
    $path = tempnam(sys_get_temp_dir(), 'hs');
    file_put_contents($path, Agent::toBytes($described));
    $readBack = Agent::describe($path);

    expect(Agent::diff($described, $readBack))->toBe([]);

    // An AUTHORED schema, with no meta: the writer expands columns/rows into
    // cells and stamps a creation time, and neither is an edit.
    $authored = ['sheets' => [[
        'name' => 'Deals',
        'columns' => [['header' => 'Name'], ['header' => 'Value', 'type' => 'currency']],
        'rows' => [['Acme', 1200], ['Globex', 800]],
        'totals' => ['Value' => 'sum'],
    ]]];
    file_put_contents($path, Agent::toBytes($authored));
    $authoredBack = Agent::describe($path);
    @unlink($path);

    expect(Agent::diff($authored, $authoredBack))->toBe([]);
    expect(Agent::equivalent($authored, $authoredBack))->toBeTrue();
});

it('falls back to replacing the workbook when sheets cannot be told apart', function () {
    $a = ['sheets' => [['name' => 'Same', 'cells' => ['A1' => ['value' => 1]]], ['name' => 'Same', 'cells' => ['A1' => ['value' => 2]]]]];
    $b = ['sheets' => [['name' => 'Same', 'cells' => ['A1' => ['value' => 3]]]]];

    $ops = Agent::diff($a, $b);

    expect($ops)->toBe([['type' => 'set_workbook', 'data' => $b]]);
    expect(Agent::reduce($a, $ops))->toBe($b);
});

it('keeps the round trip over a seeded run of random cell-form edits, without falling back', function () {
    mt_srand(20260915);

    for ($run = 0; $run < 60; $run++) {
        $a = hsWorkbook();
        $b = $a;
        $cells = &$b['sheets'][0]['cells'];

        for ($k = 0, $edits = mt_rand(1, 5); $k < $edits; $k++) {
            $address = chr(65 + mt_rand(0, 3)).mt_rand(1, 9);
            match (mt_rand(0, 3)) {
                0 => $cells[$address] = ['value' => mt_rand(1, 999)],
                1 => $cells[$address] = ['value' => 'x'.mt_rand(1, 9), 'format' => ['italic' => true]],
                2 => $cells[$address] = ['value' => null, 'formula' => 'SUM(B2:B'.mt_rand(3, 6).')'],
                default => $cells = array_diff_key($cells, [$address => true]),
            };
        }
        unset($cells);

        $ops = Agent::diff($a, $b);

        expect(SheetDiff::same(Agent::reduce($a, $ops), $b))->toBeTrue("run {$run}");
        expect(array_intersect(array_column($ops, 'type'), ['set_workbook', 'replace_sheet']))->toBe([], "run {$run} fell back");
    }
});

it('set_cell, set_range and set_workbook behave as fancy-sheets reduceWorkbook does', function () {
    $w = hsWorkbook();

    // No formula in the op clears the formula; the format stays.
    $cleared = Agent::reduce($w, ['type' => 'set_cell', 'sheet' => 'Q3', 'address' => 'B5', 'value' => 42]);
    expect($cleared['sheets'][0]['cells']['B5'])->toBe(['value' => 42]);

    $kept = Agent::reduce($w, ['type' => 'set_cell', 'sheet' => 'Q3', 'address' => 'A1', 'value' => 'Area']);
    expect($kept['sheets'][0]['cells']['A1'])->toBe(['value' => 'Area', 'format' => ['bold' => true]]);

    // A null write to an absent cell does nothing.
    expect(Agent::reduce($w, ['type' => 'set_cell', 'sheet' => 'Q3', 'address' => 'Z99', 'value' => null]))->toBe($w);

    // set_range writes values row-major from start.
    $ranged = Agent::reduce($w, ['type' => 'set_range', 'sheet' => 'Q3', 'start' => 'C2', 'values' => [[1, 2], [3, 4]]]);
    expect($ranged['sheets'][0]['cells']['D3'])->toBe(['value' => 4]);

    // An unknown sheet is skipped, and the input is never modified.
    expect(Agent::reduce($w, ['type' => 'set_cell', 'sheet' => 'Nope', 'address' => 'A1', 'value' => 1]))->toBe($w);
    expect($w)->toBe(hsWorkbook());

    expect(Agent::reduce($w, ['type' => 'set_workbook', 'data' => ['sheets' => []]]))->toBe(['sheets' => []]);
});

it('moves cells, merges and widths on row and column inserts and deletes', function () {
    $w = ['sheets' => [[
        'name' => 'S',
        'cells' => ['A1' => ['value' => 1], 'A2' => ['value' => 2], 'C3' => ['value' => 3]],
        'mergedRegions' => [['start' => 'A2', 'end' => 'C4']],
        'columnWidths' => [0 => 10, 2 => 30],
    ]]];

    $rows = Agent::reduce($w, ['type' => 'insert_rows', 'sheet' => 'S', 'at' => 2, 'count' => 2]);
    expect(array_keys($rows['sheets'][0]['cells']))->toBe(['A1', 'A4', 'C5']);
    expect($rows['sheets'][0]['mergedRegions'])->toBe([['start' => 'A4', 'end' => 'C6']]);

    $deleted = Agent::reduce($w, ['type' => 'delete_rows', 'sheet' => 'S', 'at' => 2, 'count' => 1]);
    expect(array_keys($deleted['sheets'][0]['cells']))->toBe(['A1', 'C2']);
    expect($deleted['sheets'][0]['mergedRegions'])->toBe([['start' => 'A2', 'end' => 'C3']]);

    $cols = Agent::reduce($w, ['type' => 'delete_columns', 'sheet' => 'S', 'at' => 1, 'count' => 1]);
    expect(array_keys($cols['sheets'][0]['cells']))->toBe(['B3']);
    expect($cols['sheets'][0]['columnWidths'])->toBe([1 => 30]);
    expect($cols['sheets'][0]['mergedRegions'])->toBe([['start' => 'A2', 'end' => 'B4']]);
});

it('publishes one schema variant per op type, and diff only emits those types', function () {
    $schema = Agent::opSchema();

    expect($schema)->toBe(SheetOpSchema::jsonSchema());
    expect(array_map(fn (array $v) => $v['properties']['type']['const'], $schema['oneOf']))->toBe(SheetOpSchema::TYPES);
});

it('emits ops its own schema accepts when every column width is removed', function () {
    // Found by the Node port: removing every width emitted `columnWidths: []`,
    // PHP's JSON for an empty map, which the schema declared an object only —
    // so a host validating stored ops with opSchema() rejected a diff's output.
    $a = hsWorkbook();
    $b = hsWorkbook();
    unset($b['sheets'][0]['columnWidths']);

    $ops = Agent::diff($a, $b);
    expect($ops)->toHaveCount(1);
    expect($ops[0]['type'])->toBe('set_column_widths');

    $encoded = json_decode((string) json_encode($ops[0]['columnWidths']));
    $variant = array_values(array_filter(Agent::opSchema()['oneOf'], fn (array $v) => $v['properties']['type']['const'] === 'set_column_widths'))[0];
    $declared = (array) $variant['properties']['columnWidths']['type'];

    expect($encoded)->toBe([]);
    expect($declared)->toContain('array');
    expect($variant['properties']['columnWidths']['maxItems'])->toBe(0);
    expect(SheetDiff::same(Agent::reduce($a, $ops), $b))->toBeTrue();
});

it('aligns rows by content, breaking ties toward deleting first', function () {
    expect(SheetDiff::hunks(['a', 'b', 'c'], ['a', 'x', 'b', 'c']))->toBe([[1, 0, 1]]);
    expect(SheetDiff::hunks(['a', 'b', 'c'], ['a', 'c']))->toBe([[1, 1, 0]]);
    expect(SheetDiff::hunks(['a', 'b'], ['a', 'z']))->toBe([[1, 1, 1]]);
    expect(SheetDiff::hunks(['a'], ['a']))->toBe([]);
});
