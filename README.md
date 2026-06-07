# Holy Sheet

[![Fancy UI suite](art/fancy-ui.svg)](https://particle.academy)

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
- ✅ **Read path** (1.1+) — `Agent::describe(path)` round-trips an existing xlsx back to a Holy Sheet schema with full feature parity
- ✅ **Schema repair** (1.1+) — `Agent::validateAndRepair($schema)` applies conservative auto-fixes (singular `sheet` → `sheets`, stringified numerics, object-as-list, etc.)
- ✅ **Schema builders** (1.1+) — `Agent::fromArray()`, `Agent::fromCsv()`, `HolySheet::fromQuery()` (Laravel) — typed schemas from rows / CSV / Eloquent with no hand-crafting
- ✅ **Formula linter** (1.2+) — `Agent::lint($schema)` evaluates every formula and reports `#VALUE!` / `#REF!` / `#DIV/0!` / `#NAME?` / `#CIRC!` errors. Catches the LLM-classic header-row off-by-one (`B1*12` when B1 is "Annual" and B2 is the data) with a "Did you mean B2?" hint
- ✅ **`=`-formula promotion** (1.3+) — a bare string cell beginning with `=` (e.g. `'=A2+B2'`, `'=SUM(B2:B10)'`) is stored as a real formula, not literal text. Use the object form `{'value': '=text'}` for a genuine leading-`=` string
- ✅ **`dumpJson()`** (1.3+) — `Agent::dumpJson($schema, ?DumpOptions)` serializes a schema to JSON (values + formulas). The read-tool counterpart to `describe()`: describe gives shape, dumpJson gives content. Compaction + a byte ceiling keep agent token cost bounded
- ✅ **Agent toolkit** (1.3+) — `HolySheet\Toolkit\Toolkit` ships the canonical Build / Write / Read / Lint / Describe tools as framework-agnostic descriptors (`name` + `description` + JSON-Schema `parameters` + callable `handler`) plus a shipped agent prompt. Map them onto any SDK in a few lines (see [Agent toolkit](#agent-toolkit))

## Agent toolkit

Every team building a spreadsheet agent on Holy Sheet hand-writes the same Build / Write / Read / Lint tools and the same validate → lint → repair loop. That layer is shipped — framework-agnostic, zero coupling:

```php
use HolySheet\Toolkit\Toolkit;
use HolySheet\Toolkit\ArraySchemaStore;

$kit = Toolkit::for(new ArraySchemaStore());        // or your own SchemaStore
$system = Toolkit::instructions();                  // the shipped agent prompt

foreach ($kit->tools() as $tool) {
    // $tool->name, $tool->description, $tool->parameters (JSON Schema), $tool->handler
    $sdk->registerTool($tool->name, $tool->description, $tool->parameters, $tool->handler);
}
```

The host provides three things by implementing `SchemaStore` (`getSchema()`, `setSchema()`, `getId()`) — where the workbook lives, the agent loop, and the UI. The toolkit provides the tools, the prompts, and the self-correcting write behavior (`write_xlsx` validates → lints → and only persists when clean; on error it returns the issues so the agent fixes and retries).

### Recipe: laravel/ai

`laravel/ai` consumes the same four fields. Wrap each descriptor in a `Tool` — no extra package required:

```php
use Laravel\Ai\Tools\Tool;
use HolySheet\Toolkit\Toolkit;

$descriptors = Toolkit::for($store)->byName();

$tools = array_map(
    fn ($d) => Tool::make($d->name)
        ->description($d->description)
        ->schema($d->parameters)
        ->using(fn (array $args) => $d->call($args)),
    $descriptors,
);

return $agent->withInstructions(Toolkit::instructions())->withTools($tools)->stream($prompt);
```

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
| **Recipes** — 14 end-to-end patterns including round-trip + helper builders | [docs/Recipes.md](docs/Recipes.md) |
| **Read path** — `describe()` contract + lossy-fields list | [docs/ReadPath.md](docs/ReadPath.md) |
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
