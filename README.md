# Holy Sheet

**Standalone spreadsheet writing tool for agentic document creation.**

PHP 8.2+ library for writing valid xlsx files from a JSON-shaped schema. Zero framework dependencies in the core (just `ext-zip`). An optional Laravel adapter — service provider, facade, artisan command — sits in `HolySheet\Laravel\*` and only loads when used. No HTTP routes, no controllers, no opinions about how your app exposes the writer; that's your pipeline's job.

## Framework-agnostic by design

| Layer | What it requires |
|-------|------------------|
| Core (`HolySheet\Agent`, `HolySheet\HolySheet`, validator, writer, schema) | PHP 8.2+, `ext-zip`. Nothing else. Works in plain PHP scripts, Symfony, Laminas, Slim, CLI tools. |
| Laravel adapter (`HolySheet\Laravel\*`) | Optional. Auto-registered via `extra.laravel.providers` if Laravel is installed; ignored otherwise. Provides facade, service provider, and `php artisan holy-sheet:write` command. |

The package ships **no HTTP endpoints**. If you need an "Export to xlsx" route, you write the controller — Holy Sheet gives you the writer + facade.

## Installation

```bash
composer require particle-academy/holy-sheet
```

Laravel apps auto-discover the optional adapter. Non-Laravel projects use `HolySheet\Agent::*` directly.

## Quick start

### Plain PHP (Symfony, Laminas, CLI, anywhere)

```php
use HolySheet\Agent;

Agent::write([
    'sheets' => [[
        'name' => 'Q4 Sales',
        'columns' => [
            ['header' => 'Region', 'type' => 'string'],
            ['header' => 'Revenue', 'type' => 'currency', 'currency' => 'USD'],
            ['header' => 'YoY', 'type' => 'percent', 'decimals' => 1],
        ],
        'rows' => [
            ['North America', 4_820_000, 0.124],
            ['Europe',        3_210_000, 0.081],
            ['APAC',          2_895_000, 0.227],
        ],
        'totals' => ['Revenue' => 'sum', 'YoY' => 'avg'],
        'theme' => 'default',
    ]],
], '/tmp/q4.xlsx');
```

### Laravel (via the facade)

```php
use HolySheet\Laravel\Facades\HolySheet;

HolySheet::write($schema, $path);   // write to disk
$bytes = HolySheet::toBytes($schema); // raw bytes for streaming/queue jobs
$errors = HolySheet::validate($schema); // dry-run, returns structured errors
$tool = HolySheet::toolDefinition();  // JSON Schema for agent tool wiring
```

## Why this exists

Existing PHP spreadsheet libraries are either:

- Heavy and slow (PhpSpreadsheet pulls dozens of transitive deps), or
- Tightly coupled to a single output format

Agentic flows need something different: a small, deterministic API where an LLM can describe the sheet as data (rows, columns, types, formats) and the package writes the file in one pass — no per-cell ceremony, no global state, no surprise dependencies.

## Features

- ✅ Multi-sheet workbooks, inline strings, scalar types, formulas with optional cached values
- ✅ Full styling — bold/italic, text alignment, font color/size, fills, borders
- ✅ Number formats — currency (USD/EUR/GBP/JPY/CNY/INR/AUD/CAD/CHF/KRW + ISO fallback), percent, date, datetime, integer, decimals
- ✅ Date conversion — ISO strings + `DateTimeInterface` → Excel serial numbers
- ✅ Themes — `default`, `business`, `minimal`, `plain`
- ✅ Symbolic totals — `'totals' => ['Revenue' => 'sum']` → SUM/AVG/COUNT/MIN/MAX formulas
- ✅ Merged cells, column widths (px), frozen rows/cols
- ✅ Comments with author + color
- ✅ Cross-sheet formula references (`Sheet2!A1`)
- ✅ Style deduplication — every unique format becomes one record
- ✅ Zero third-party runtime dependencies (uses PHP's built-in `ZipArchive`)
- ✅ Structured validation errors with `path`, `expected`, `got`, `value`, `hint`

## Compatibility

| | Versions |
|---|---|
| **PHP** | 8.2, 8.3, 8.4 |
| **Laravel adapter** | 10.x, 11.x, 12.x, 13.x (optional) |
| **Frameworks** | any PHP 8.2+ project — Symfony, Laminas, Slim, plain PHP, CLI |
| **Runtime deps** | none beyond `ext-zip` |

## Documentation

| Topic | Doc |
|-------|-----|
| **Schema reference** — every field, every type, every option | [docs/Schema.md](docs/Schema.md) |
| **Recipes** — 10 end-to-end patterns from agentic prompts to queue exports | [docs/Recipes.md](docs/Recipes.md) |
| **Laravel adapter** — facade methods, service provider, artisan command | [docs/LaravelAdapter.md](docs/LaravelAdapter.md) |
| **Agent skill** — system prompt for LLM consumption | [skills/holy-sheet.md](skills/holy-sheet.md) |
| **JSON Schema** — for tool-use validators | [skills/holy-sheet.schema.json](skills/holy-sheet.schema.json) |

## Run the demo

```bash
php examples/sales-report.php /tmp/sales.xlsx
```

Writes a 3-sheet workbook (Sales / Notes / Status) demonstrating every feature — currency + percent + date columns, totals row, frozen header, merged title cell, comment, custom theme. See [examples/README.md](examples/README.md) for the full walkthrough.

## Three entry points, one schema

| Caller | Entry |
|--------|-------|
| **Plain PHP** | `HolySheet\Agent::write($schema, $path)` (static, framework-free) |
| **Laravel facade** | `HolySheet\Laravel\Facades\HolySheet::write($schema, $path)` |
| **CLI / agent shell** | `php artisan holy-sheet:write --in=schema.json --out=q4.xlsx` (Laravel command) |

Need an HTTP route? Build your own controller around the facade — Holy Sheet doesn't ship one. See [docs/LaravelAdapter.md](docs/LaravelAdapter.md#http-controller-pattern) for the recommended shape.

## License

MIT
