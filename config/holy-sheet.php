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
];
