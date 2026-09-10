<?php

declare(strict_types=1);

use HolySheet\Agent;

/**
 * The schema must describe THIS package, not a plan for a future one.
 *
 * ## What this caught
 *
 * `skills/holy-sheet.schema.json` is the file handed to an LLM as the tool
 * definition — it is the entire contract an agent sees. Every formatting field
 * was in it, and every one carried a description written in the FUTURE TENSE:
 *
 *     theme          "Pre-baked style preset. Lands in 0.3."
 *     frozenRows     "Lands in 0.5."
 *     mergedRegions  "Cells to merge. Lands in 0.5."
 *     totals         "... Lands in 0.7."
 *     CellFormat     "Per-cell format. Lands in 0.3."
 *
 * with a top-level description announcing "v0.2.0 supports scalar values,
 * formulas (cached), and multiple sheets. Styles, formats, comments, merges
 * land in subsequent minors."
 *
 * The package was on **2.1.1**. All of it had shipped, some of it more than a
 * year earlier.
 *
 * So an agent read the contract, was told the formatting did not exist yet, and
 * correctly did not use it. It produced a numerically perfect spreadsheet with
 * money rendered as `1250000.5` and no frozen header — and every existing test
 * passed, because they all call the writer directly and none of them reads the
 * document the agent is given.
 *
 * **The model behaved correctly. The schema lied.** That is worth stating
 * plainly, because the finding first looked like a model that would not use
 * the features.
 *
 * ## Why the check is shaped like this
 *
 * It cannot assert the descriptions are GOOD — no test can. What it can assert
 * is that the contract never again tells an agent a shipped feature is
 * unreleased, which is the specific failure that happened and the one that is
 * invisible: nothing breaks, nothing warns, the documents are just plain.
 */
function schemaText(): string
{
    return json_encode(Agent::toolDefinition(), JSON_THROW_ON_ERROR);
}

it('never announces a shipped feature as unreleased', function () {
    // "Lands in 0.5", "coming in", "not yet supported" — a promise in a
    // contract is a promise the reader will honour by staying away.
    $futureTense = ['Lands in', 'lands in', 'will land', 'coming in', 'not yet supported', 'planned for'];

    $text = schemaText();
    $found = array_values(array_filter($futureTense, fn (string $p): bool => str_contains($text, $p)));

    expect($found)->toBe([], 'the tool schema tells an agent a feature is unreleased: '.implode(', ', $found));
});

it('does not pin itself to a version the package left behind', function () {
    // The top-level description said "v0.2.0 supports ..." while the package
    // was 2.1.1. A version number inside a description is a second copy of the
    // one in the changelog, and second copies drift.
    expect(schemaText())->not->toContain('v0.2.0');
});

it('describes every formatting field the writer actually honours', function () {
    // The fields were all present and all disclaimed, so presence alone proves
    // nothing. Each must carry a description with substance in it.
    $schema = Agent::toolDefinition();
    $sheet = $schema['definitions']['Sheet']['properties'];

    foreach (['theme', 'totals', 'mergedRegions', 'columnWidths', 'frozenRows', 'frozenCols'] as $field) {
        expect($sheet)->toHaveKey($field);
        expect(strlen((string) ($sheet[$field]['description'] ?? '')))
            ->toBeGreaterThan(30, "`{$field}` has no description worth reading");
    }
});

it('tells an agent which column type to reach for', function () {
    // The single highest-leverage line in the file. `type` is what decides
    // whether money reads as money, and "Cell type applied to every value"
    // gives a model no reason to prefer `currency` over the `auto` default.
    $type = Agent::toolDefinition()['definitions']['Column']['properties']['type'];

    expect($type['enum'])->toContain('currency');
    expect($type['enum'])->toContain('percent');
    expect(strtolower((string) $type['description']))->toContain('currency');
});

it('is the schema the writer actually accepts', function () {
    // The guard against fixing the prose and describing something the code
    // does not do. Every field named above is fed through a real write.
    $path = tempnam(sys_get_temp_dir(), 'hs-schema-').'.xlsx';

    try {
        Agent::write(['sheets' => [[
            'name' => 'Q3',
            'theme' => 'default',
            'columns' => [
                ['header' => 'Region'],
                ['header' => 'Revenue', 'type' => 'currency', 'currency' => 'USD', 'decimals' => 2],
                ['header' => 'Growth', 'type' => 'percent', 'decimals' => 1],
            ],
            'rows' => [['EMEA', 2100000.0, 0.311]],
            'totals' => ['Revenue' => 'sum'],
            'mergedRegions' => [['start' => 'A5', 'end' => 'C5']],
            'columnWidths' => [0 => 200],
            'frozenRows' => 1,
            'frozenCols' => 1,
            'cells' => ['A5' => ['value' => 'Reviewed', 'comment' => 'a bare string comment']],
        ]]], $path);

        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        expect($styles)->toContain('$');
        expect($styles)->toContain('%');
        expect($sheet)->toContain('state="frozen"');
        expect($sheet)->toContain('<mergeCell');
        expect($sheet)->toContain('SUM(');
    } finally {
        @unlink($path);
    }
});
