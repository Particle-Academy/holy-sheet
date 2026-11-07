<?php

declare(strict_types=1);

/**
 * Holy Sheet configuration.
 *
 * Published to `config/holy-sheet.php` via:
 *   php artisan vendor:publish --tag=holy-sheet-config
 *
 * The keys below are placeholders for the writing-API options that land
 * in upcoming commits (default writer, agent locale, sandbox path,
 * etc). Safe to leave at defaults during the scaffold phase.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Default writer
    |--------------------------------------------------------------------------
    |
    | Format Holy Sheet will write to when no format is specified at the
    | call site. Supported values land alongside the writer
    | implementations: `xlsx`, `csv`, `tsv`, `ods`, ...
    |
    */
    'default_writer' => 'xlsx',

    /*
    |--------------------------------------------------------------------------
    | Output directory
    |--------------------------------------------------------------------------
    |
    | Default disk-relative path where generated spreadsheets are
    | written. `null` means the call site must supply a path. Apps
    | targeting agentic flows usually point this at storage/app/sheets.
    |
    */
    'output_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Agent locale
    |--------------------------------------------------------------------------
    |
    | When the agentic helpers infer column types, dates, and number
    | formats, this locale controls the parsing + formatting defaults.
    |
    */
    'locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Read limits
    |--------------------------------------------------------------------------
    |
    | How big a spreadsheet `HolySheet::read()` / `describe()` will open,
    | through the facade or the container. A call's own `limits` option
    | overrides these key by key. Each must be a positive integer; a bad one
    | refuses (ArchiveRefused, naming it) when HolySheet is first resolved.
    |
    | The values below are the package defaults. Raise them for big exports:
    | the README's "Large workbooks" section has the measured memory and time
    | per row, and what a 1,000,000-row read needs. Memory is the real cost,
    | so check `memory_limit` before raising maxPartBytes.
    |
    | Archive (the bytes): the file, its member count, one part's size, every
    | part together, and one part's size over its compressed size.
    | Read (what the bytes become): rows and columns per sheet, cells in the
    | workbook, entries in the shared-strings table. maxRows and maxColumns
    | default to Excel's own sheet limits.
    |
    */
    'limits' => [
        'maxArchiveBytes' => 67108864,       // 64 MiB
        'maxEntries' => 16384,
        'maxPartBytes' => 67108864,          // 64 MiB
        'maxExpandedBytes' => 268435456,     // 256 MiB
        'maxCompressionRatio' => 1000,
        'maxRows' => 1048576,
        'maxColumns' => 16384,
        'maxCells' => 20000000,
        'maxSharedStrings' => 20000000,
    ],

    /*
    |--------------------------------------------------------------------------
    | fromQuery() row cap
    |--------------------------------------------------------------------------
    |
    | The most rows `HolySheet::fromQuery()` materialises when a call passes
    | no `limit`. A query builder with more rows refuses rather than cuts.
    |
    */
    'query_limit' => 5000,
];
