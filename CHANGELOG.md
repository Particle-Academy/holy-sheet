# Changelog

All notable changes to `particle-academy/holy-sheet` will be documented in this file.

## [1.3.0] — 2026-06-07

The "agent kit that happens to write xlsx" release. One bug fix and two DX features that every Laravel/AI integration on Holy Sheet was re-implementing by hand.

### Added
- **`=`-formula promotion** — a bare string cell value beginning with `=` (e.g. `'=A2+B2'`, `'=SUM(B2:B10)'`) is now stored as a real formula cell instead of literal text. Works in row cells **and** sparse `cells` maps. Object cells are left untouched, so `{'value': '=text'}` is the escape hatch for a genuine leading-`=` string and `{'formula': '...'}` still means "caller knows best". Promotion happens in `Schema\Normalizer`, so the write path *and* `lint()` both pick it up. ([#2](https://github.com/Particle-Academy/holy-sheet/issues/2))
  ```php
  // before: C2 holds the text "=A2+B2"; after: C2 evaluates to 30
  ['rows' => [[10, 20, '=A2+B2']]]
  ```
- **`Agent::dumpJson(array $schema, ?DumpOptions $opts = null): string`** (+ `HolySheet::dumpJson()` instance/facade) — serializes a schema to JSON: the read-tool counterpart to `describe()`. `describe()` gives an agent the *shape* of an existing file; `dumpJson()` gives the full cell-level *content* (values + formulas) so it can make targeted edits or fix existing formulas. `DumpOptions` controls `prettyPrint`, `includeFormats`, `compactEmpty`, and a `maxBytes` ceiling (over budget → a compact shape index instead of an unbounded blob). ([#3](https://github.com/Particle-Academy/holy-sheet/issues/3))
- **`HolySheet\Toolkit` — the framework-agnostic agent toolkit.** Ships the canonical Build / Write / Read / Lint / Describe tools as plain descriptors (`name` + `description` + JSON-Schema `parameters` + callable `handler`) plus an overridable agent prompt (`resources/prompts/agent.md`). `Toolkit::for($store)->tools()` returns them; `write_xlsx` runs the self-correcting validate → lint → persist loop. Model-agnostic via the tiny `SchemaStore` interface (`getSchema`/`setSchema`/`getId`) with an in-memory `ArraySchemaStore`. No new package, no `laravel/ai` coupling — a laravel/ai mapping is a README recipe. ([#4](https://github.com/Particle-Academy/holy-sheet/issues/4))

### Tests
- 93 Pest tests passing (+22): `FormulaPromotionTest`, `DumperTest`, `ToolkitTest`.

### Compatibility
- No breaking changes. New methods + a new `Toolkit` namespace only; existing schemas behave identically (a bare `=string` was never usefully stored as text before).
- Still standalone — zero third-party runtime deps, `php ^8.2 + ext-zip`.

## [1.2.0] — 2026-05-08

The "agentic spreadsheets that actually work" release. v1.1 let agents read, repair, and build schemas; v1.2 makes their *formulas* trustworthy.

### Added
- **`Agent::lint(array $schema): array`** — evaluates every formula in a schema and reports cells that produce Excel-style errors (`#VALUE!`, `#REF!`, `#DIV/0!`, `#NAME?`, `#CIRC!`). Catches the bug class an LLM is most likely to introduce: referencing the header row instead of the first data row. The `hint` field on each issue surfaces *why* it failed and (for header-row-vs-data-row) suggests the correct cell.
  ```php
  $issues = Agent::lint($schema);
  // [
  //   ['sheet' => 'Q4', 'address' => 'C2', 'formula' => 'B1*12',
  //    'error' => '#VALUE!',
  //    'hint' => 'Arithmetic on a non-numeric cell: B1 = "Annual" (string). Did you mean B2? (it holds 12000)'],
  // ]
  ```
- **`HolySheet::lint()` instance method + facade `@method` annotation** — same surface for Laravel apps.
- **Built-in formula evaluator** (`Schema\FormulaLinter`) — pure PHP, zero dependencies. Supports the formula vocabulary agents actually emit: arithmetic, comparison, string concat, cell refs, ranges (incl. cross-sheet `Sheet!A1`), and the common functions: `SUM`, `AVERAGE`/`AVG`, `COUNT`, `COUNTA`, `MIN`, `MAX`, `IF`, `ROUND`, `ABS`, `LEN`, `UPPER`, `LOWER`, `CONCAT`/`CONCATENATE`. Unknown functions return `#NAME?` so agents know to avoid them.

### Tests
- 70 Pest tests passing — adds 9 new tests in `FormulaLinterTest` covering header-row off-by-one, division by zero, circular references, unknown functions, cross-sheet refs, and string-in-arithmetic detection.

### Compatibility
- No breaking changes. New methods only.
- Still standalone — `composer.json` `require` remains `php ^8.2 + ext-zip`. Zero third-party runtime deps.

## [1.1.0] — 2026-05-01

The "agentic loop" release. v1.0 let agents *write*; v1.1 lets them *read*, *recover*, and *build* schemas from data they already have.

### Added
- **`Agent::describe(string $path): array`** — round-trip an existing xlsx back to a Holy Sheet schema. Full feature parity with the writer: values, formulas + cached values, every CellFormat field, comments, mergedRegions, columnWidths, frozen panes. Also reads xlsx files authored outside Holy Sheet (Excel, LibreOffice, Google Sheets) with the standard 50 built-in numFmt ids. Lossy fields documented in `docs/ReadPath.md`.
- **`Agent::validateAndRepair(array $schema): array`** — runs validation and applies conservative repairs in one call. Returns `{schema, errors, repairs}`. Repair rules (high-confidence only): singular `sheet` → `sheets`, `row` → `rows`, object-keyed rows → indexed list, stringified numerics in numeric columns, unknown theme → `default`, ISO-date inference for missing column types, address whitespace trim. Ambiguous cases stay un-repaired by design.
- **`Agent::fromArray($rows, $headers?, $sheetName?, $options?)`** — flat array → schema with type inference (header-pattern + value sampling). Currency, percent, integer, number, date/datetime, boolean, string detection.
- **`Agent::fromCsv($csvOrPath, $options?)`** — CSV string OR file path → schema. Uses `fgetcsv` over a memory stream for embedded-newline support.
- **`HolySheet::fromQuery($builder, $columns?, $options?)`** — Laravel facade only. Eloquent Builder, Query Builder, or Collection → schema. Reads model `$casts` for type inference (`decimal:2`, `datetime`, `boolean`, `array`/`json`, etc.). Default 5000-row safety cap (configurable via `$options['limit']`).
- **`docs/ReadPath.md`** — describe() contract, lossy-fields list, reverse-numFmt notes.
- **`examples/round-trip.php`** — runnable write → describe → modify → write demo.

### Changed
- The Laravel facade `@method` annotations + skill schema cover the new methods. Existing callers see no breaking changes.
- `HolySheet::VERSION` bumped to `1.1.0`.

### Tests
- 61 Pest tests passing — adds `ReaderTest`, `RepairerTest`, `HelpersTest`, `Laravel/QueryAdapterTest`.

## [1.0.1] — 2026-05-06

Adapter cleanup + comprehensive docs + runnable demo. No breaking changes if you only used the package's facade or `Agent::*` API.

### Changed
- **Removed** `HolySheet\Laravel\Http\HolySheetController` — Holy Sheet no longer ships an HTTP layer. Apps that need an Export endpoint write their own controller around the facade. The recommended shape is documented in `docs/LaravelAdapter.md` and `docs/Recipes.md` (recipe 9).
- **Hardened framework-agnostic story** — `composer.json` `require` is now `php ^8.2 + ext-zip` only. Dropped redundant `illuminate/support` from `require-dev` (Orchestra Testbench pulls it). The Laravel adapter is fully opt-in: classes under `HolySheet\Laravel\*` only load when invoked.
- **Facade exposes the full Agent surface** — `validate()`, `write()`, `toBytes()`, `toolDefinition()`, `describe()`, `getVersion()` all reachable through `HolySheet\Laravel\Facades\HolySheet`. The singleton bound by the service provider is what your pipelines (queue jobs, listeners, controllers) see.

### Added
- `docs/Schema.md` — full schema reference. Every field, every type, every option.
- `docs/Recipes.md` — 10 end-to-end patterns (sales reports, multi-sheet, cross-sheet refs, dates, highlights, frozen panes, merged titles, query exports, HTTP controller, queue jobs).
- `docs/LaravelAdapter.md` — facade reference, service provider override pattern, artisan command, recommended HTTP controller shape.
- `examples/sales-report.php` — runnable 3-sheet demo touching every feature. `php examples/sales-report.php /tmp/sales.xlsx`.
- `examples/README.md` — walkthrough of what the demo demonstrates and how to verify your install.

### Tests
- Renamed the static-write test to `writeFile` (the static convenience matching the original 0.2.0 signature).
- New: `it('the HolySheet singleton mirrors Agent for facade/DI use')` exercises every facade method against a fresh instance.
- New: `tests/Laravel/FacadeTest.php` — every facade method via the real Laravel container/facade chain. Confirms singleton resolution by both class and `'holy-sheet'` alias.
- Removed: controller test (no controller in package anymore).
- 33 Pest tests passing.

## [Unreleased]

### Added
- Initial package scaffold:
  - `composer.json` with PHP 8.2+ floor and Laravel 10–13 dev/integration support
  - `HolySheet\HolySheet` core class (framework-agnostic)
  - `HolySheet\Laravel\HolySheetServiceProvider` (auto-discovered)
  - `HolySheet\Laravel\Facades\HolySheet` facade
  - `config/holy-sheet.php` with publishable defaults
  - Pest test harness — `tests/Unit/` for pure PHP, `tests/Laravel/` for service-provider integration via Orchestra Testbench
  - GitHub Actions matrix CI (PHP 8.2/8.3/8.4 × Laravel 10/11/12)
  - README + CHANGELOG
