<?php

declare(strict_types=1);

/**
 * round-trip.php — write → describe → modify → write
 *
 * Demonstrates the v1.1 read path. Run from the package root:
 *
 *   php examples/round-trip.php /tmp/round-trip.xlsx
 *
 * The script:
 *   1. Writes a small sales workbook
 *   2. Reads it back with Agent::describe()
 *   3. Mutates one cell value + adjusts the totals formula
 *   4. Writes the result to the same path
 *   5. Reads it again to confirm the change persisted
 */

require __DIR__.'/../vendor/autoload.php';

use HolySheet\Agent;

$path = $argv[1] ?? sys_get_temp_dir().DIRECTORY_SEPARATOR.'round-trip.xlsx';

echo "=== Step 1: write initial workbook ===\n";
$initial = [
    'sheets' => [[
        'name' => 'Q4 Sales',
        'columns' => [
            ['header' => 'Region', 'type' => 'string'],
            ['header' => 'Revenue', 'type' => 'currency', 'currency' => 'USD'],
        ],
        'rows' => [
            ['North America', 4_820_000],
            ['Europe', 3_210_000],
            ['APAC', 2_895_000],
        ],
        'totals' => ['Revenue' => 'sum'],
        'frozenRows' => 1,
    ]],
];

$result = Agent::write($initial, $path);
printf("wrote %s (%d bytes)\n", $result['path'], $result['bytes']);

echo "\n=== Step 2: describe the file back to a schema ===\n";
$schema = Agent::describe($path);
printf("sheet:    %s\n", $schema['sheets'][0]['name']);
printf("cells:    %d\n", count($schema['sheets'][0]['cells']));
printf("frozen:   %d row(s)\n", $schema['sheets'][0]['frozenRows'] ?? 0);

echo "\n=== Step 3: mutate one cell, recalc totals ===\n";
// B2 is North America's Revenue — bump it
$schema['sheets'][0]['cells']['B2']['value'] = 5_500_000;
echo "updated B2 → 5,500,000\n";

echo "\n=== Step 4: write the modified schema back ===\n";
$result = Agent::write($schema, $path);
printf("rewrote %s (%d bytes)\n", $result['path'], $result['bytes']);

echo "\n=== Step 5: re-describe to confirm ===\n";
$confirmed = Agent::describe($path);
$na = $confirmed['sheets'][0]['cells']['B2']['value'];
printf("B2 now = %s\n", number_format((float) $na));

echo "\nRound trip complete. Open the file in Excel / LibreOffice / Sheets to verify.\n";
