<?php

declare(strict_types=1);

/**
 * Holy Sheet — sales-report demo.
 *
 * Runnable from the package root:
 *
 *   php examples/sales-report.php /tmp/sales.xlsx
 *
 * Demonstrates every feature in one ~3-sheet workbook:
 *   - Currency, percent, date columns with type-derived formatting
 *   - Symbolic totals row (SUM, AVERAGE)
 *   - Theme presets — default banded rows, bold headers, totals border
 *   - Frozen header row, custom column widths
 *   - Sparse cells map for arbitrary layouts
 *   - Merged title cell
 *   - Cross-sheet formula reference
 *   - Per-cell highlight overrides
 *   - Cell comment with author + color
 */

require __DIR__.'/../vendor/autoload.php';

use HolySheet\Agent;

$out = $argv[1] ?? __DIR__.'/sales.xlsx';

$schema = [
    'meta' => [
        'creator' => 'Holy Sheet demo',
        'created' => gmdate('Y-m-d\TH:i:s\Z'),
    ],
    'sheets' => [
        // -----------------------------------------------------------------
        // Sheet 1 — row-oriented Q4 sales with totals + currency + percent
        // -----------------------------------------------------------------
        [
            'name' => 'Q4 Sales',
            'theme' => 'default',
            'frozenRows' => 1,
            'columnWidths' => [0 => 200, 1 => 140, 2 => 100, 3 => 130],
            'columns' => [
                ['header' => 'Region',    'type' => 'string'],
                ['header' => 'Revenue',   'type' => 'currency', 'currency' => 'USD'],
                ['header' => 'YoY',       'type' => 'percent',  'decimals' => 1],
                ['header' => 'Reviewed',  'type' => 'date'],
            ],
            'rows' => [
                ['North America', 4_820_000, 0.124, '2026-05-01'],
                ['Europe',        3_210_000, 0.081, '2026-05-01'],
                ['APAC',          2_895_000, 0.227, '2026-05-02'],
                ['LATAM',         1_240_000, 0.153, '2026-05-02'],
                ['Africa',          612_000, 0.310, '2026-05-03'],
            ],
            'totals' => [
                'Revenue' => 'sum',
                'YoY'     => 'avg',
            ],
        ],

        // -----------------------------------------------------------------
        // Sheet 2 — sparse cells for a stylized title + cross-sheet formula
        // -----------------------------------------------------------------
        [
            'name' => 'Notes',
            'mergedRegions' => [
                ['start' => 'A1', 'end' => 'D1'],
            ],
            'columnWidths' => [0 => 220],
            'cells' => [
                'A1' => [
                    'value' => 'Q4 2026 Performance — Internal',
                    'format' => [
                        'bold' => true, 'fontSize' => 16, 'textAlign' => 'center',
                        'backgroundColor' => '#1F2937', 'color' => '#FFFFFF',
                    ],
                ],
                'A3' => ['value' => 'Total revenue', 'format' => ['bold' => true]],
                'B3' => [
                    'formula' => "SUM('Q4 Sales'!B2:B6)",
                    'format' => ['displayFormat' => 'currency', 'currency' => 'USD', 'decimals' => 0],
                ],
                'A4' => ['value' => 'Avg YoY growth', 'format' => ['bold' => true]],
                'B4' => [
                    'formula' => "AVERAGE('Q4 Sales'!C2:C6)",
                    'format' => ['displayFormat' => 'percentage', 'decimals' => 1],
                ],
                'A6' => [
                    'value' => 'Note',
                    'format' => ['bold' => true],
                    'comment' => [
                        'text' => 'Numbers are pre-audit. Final figures land 2026-05-15.',
                        'author' => 'Holy Sheet demo',
                        'color' => '#f59e0b',
                    ],
                ],
                'A7' => ['value' => 'APAC growth driven by India + Singapore expansion. Africa figure includes one-time onboarding revenue.'],
            ],
        ],

        // -----------------------------------------------------------------
        // Sheet 3 — service status with per-cell highlight overrides
        // -----------------------------------------------------------------
        [
            'name' => 'Status',
            'theme' => 'minimal',
            'frozenRows' => 1,
            'columns' => [
                ['header' => 'Service'],
                ['header' => 'State'],
                ['header' => 'Latency (ms)', 'type' => 'integer'],
            ],
            'rows' => [
                ['API gateway', 'Healthy', 142],
                ['Primary DB', ['value' => 'WARNING', 'format' => [
                    'bold' => true, 'backgroundColor' => '#fee2e2', 'color' => '#991b1b',
                ]], 312],
                ['Cache', 'Healthy', 8],
                ['Queue worker', ['value' => 'Healthy', 'format' => ['color' => '#065f46']], 45],
                ['Search index', 'Healthy', 89],
            ],
        ],
    ],
];

$result = Agent::write($schema, $out);

echo "✓ wrote {$result['path']}".PHP_EOL;
echo "  {$result['sheets']} sheets, {$result['bytes']} bytes".PHP_EOL;
echo PHP_EOL;
echo "Open in Excel / LibreOffice / Google Sheets to inspect.".PHP_EOL;
