# Changelog

All notable changes to `particle-academy/holy-sheet` will be documented in this file.

## [Unreleased]

## [2.2.0] — 2026-09-13

### Added

- **`Agent::describe()` reads OpenDocument spreadsheets (`.ods`) into the same
  schema as `.xlsx`.** A Laravel host was keeping a second spreadsheet library
  installed for one branch of one `match`: xlsx went through holy-sheet, ods
  through something else with its own cell model and a converter to make its
  output look like a holy-sheet schema. That branch can go. `describe()` picks
  the reader from the file's CONTENTS (an OpenDocument package names itself in
  its `mimetype` entry; an xlsx has `xl/workbook.xml`), never the extension, and
  the Laravel facade and the `describe_file` tool get it for free.

  Mapped: every value type, strings with several paragraphs and inline runs,
  dates and times as UTC ISO strings, OpenFormula translated to A1 with its
  cached result, repeated cells and rows (the million-row padding is skipped,
  not expanded), merges, content in covered cells, comments with authors, cell
  styles through parents and row / column defaults, data styles, and document
  creator / created. Not mapped: frozen panes (view settings), column widths and
  row heights, fonts and the other formatting `CellFormat` has no field for,
  function-name translation, and flat `.fods` files. The full list is in
  `docs/ReadPath.md`.

  **Nothing to do to take it.** A `.xlsx` describes exactly as before; the
  schema conversion both readers now share was moved out of `XlsxReader`
  unchanged.

  Tested against real LibreOffice output, not hand-built approximations: the
  same workbook is written as xlsx by this package and converted to ods by
  LibreOffice, and `OdsReaderTest` asserts the two describe to the same schema.
  How every fixture was made is in `tests/fixtures/ods/README.md`.

- **`HolySheet\Exceptions\UnsupportedFormatException`**, thrown by `describe()`
  for a file that is neither format, with the declared `mimetype` when there is
  one (an OpenDocument text file says so instead of failing somewhere inside the
  xlsx reader).

### Changed

- **A file `describe()` cannot read now throws `UnsupportedFormatException`
  instead of a bare `RuntimeException`.** It extends `RuntimeException`, so
  **a `catch (RuntimeException $e)` keeps working: do nothing.** The message
  for a file that is not a zip still says "zip archive"; only code matching
  the rest of the old text (`cannot open …`, `missing xl/workbook.xml`) sees
  different wording.

### Fixed

- **The tool schema announced its own shipped features as unreleased.**
  `skills/holy-sheet.schema.json` is the file handed to an LLM as the tool
  definition — the entire contract an agent sees — and every formatting field
  in it carried a future-tense description: `theme` "Lands in 0.3.",
  `frozenRows` "Lands in 0.5.", `mergedRegions` "Lands in 0.5.", `totals`
  "Lands in 0.7.", `CellFormat` "Per-cell format. Lands in 0.3.", under a
  heading declaring "v0.2.0 supports scalar values, formulas (cached), and
  multiple sheets. Styles, formats, comments, merges land in subsequent
  minors."

  The package was on **2.1.1**. All of it had shipped, some of it a long time
  ago. Nothing caught it because every test calls the writer directly and none
  of them reads the document an agent is given.

  **In fairness to the schema: measured A/B runs showed this did NOT stop the
  current model using the formatting** — four runs on the old text used typed
  columns and frozen panes 4/4. It is fixed because it was false, not because
  it was proven costly. Descriptions now say what each field does and when to
  reach for it.

  `SchemaDescribesTheShippedPackageTest` fails if the contract ever again tells
  an agent a shipped feature is unreleased, and writes a workbook using every
  field it describes — so fixing the prose can never drift from the code.

- **`describe()` then `write()` works on a workbook with an empty sheet.** An empty sheet describes as `cells: []`, and the validator read an empty PHP array as a list and rejected it as "not a map", so the read path's documented round trip failed for any workbook containing one, xlsx or ods. An empty `cells` is now accepted; a non-empty list is still an error. Nothing to do.

## [2.1.1] — 2026-09-10

### Fixed

- **`version()` reports the version this package actually ships as.** It
  returned `1.3.0` from a 2.1.x release. The constant had drifted because
  nothing compared it to the packaging metadata — the same shape as every other
  two-copies-of-one-number failure in this estate.

  `VersionIsSingleSourcedTest` / `version.test.ts` now pins it, so the class is
  closed rather than the instance fixed. `dark-slide-py` already had that
  assertion and was the only engine in the family to catch itself.


## [2.1.0] — 2026-09-10

### Added

- **`composer verify:published` — a check that the PUBLISHED package works, not
  just the source.** The suite proves the code is right; it cannot prove the
  package is, because it loads `src/` through the dev autoloader with every
  `require-dev` package installed. A consumer gets a zip wired up by the
  `autoload` map with none of them. Those two paths usually agree, and nothing
  checked that they did.

  `verify/published.php` ships **inside** the package and boots through the
  consumer's own autoloader — a checker that reads the repo is testing the repo
  again. `verify/install-check.sh` builds the install the way Packagist does:
  `git archive HEAD` so only committed files are seen and `export-ignore` is
  honoured, `--no-dev`, and a non-symlinked path repo so it is a copy rather
  than an alias.

  **Demonstrated rather than asserted.** With `/skills export-ignore` added to
  `.gitattributes` — a realistic change someone would make to slim the tarball —
  `composer test` passes 101 tests and 284 assertions, exit 0. `composer
  verify:published` exits 1 and names the file and the consequence:
  `toolDefinition()` silently returns `[]`, so an agent is handed an empty tool
  definition with no exception and no warning.

  It also caught two things about itself on the way: a class name inferred from
  a directory rather than read from source, and an assertion about
  `toolDefinition()`'s shape written without opening the file it reads. Both
  were fixed before this landed — a verification script that reports defects the
  package does not have trains its reader to discount the one real finding later
  on.

### Added

- **A checksum test pinning `skills/holy-sheet.schema.json` to its Node twin.**
  The schema is a byte-identical copy of
  `holy-sheet-js/src/holy-sheet.schema.json`, kept in sync by remembering to
  edit both. Nothing checked that — and this is the file handed to an LLM as
  the tool definition, so a one-sided edit did not fail a build, it changed what
  an agent was told the API is on one backend and not the other.

  To change the schema: edit both copies, run either suite, paste the new hash
  into both tests. The hash is taken over **newline-normalised** content,
  because the file is stored LF and lands CRLF on a Windows checkout.

### Fixed

- **A sheet with BOTH a table and explicit cells no longer discards the table.**
  `Normalizer::normalizeSheet()` returned the moment `cells` was set, silently
  throwing away `columns`, `rows`, `totals` and `theme`. A four-row report with
  one styled title cell wrote a **one-cell workbook**.

  It failed in the quietest possible way: `Agent::validate()` reported no
  errors, because `Validator::validateSheet()` says in as many words that a
  sheet may carry "columns + rows OR cells (sparse map) **OR both**". The
  validator permitted the combination and the writer dropped half of it, so the
  file opened cleanly in Excel and was simply missing the report.

  This blocked the most ordinary premium layout there is — a styled band plus a
  formatted table on one sheet — which is how it was found.

  **What you must do: nothing.** A sheet that used only one of the two shapes
  behaves exactly as before. Explicit cells now win at their address, and their
  format is MERGED over whatever the table put there, so a cell asking only for
  `bold` keeps the column's currency format and the theme's banding instead of
  dropping to bare.

- **A `comment` given as a plain string is no longer dropped.** Only the object
  form (`['text' => '...']`) was read; `'comment' => 'Reviewed by finance'` was
  accepted by the validator, normalized to `null`, and lost with no error. Both
  forms now work.

- **This changelog was out of order and `[Unreleased]` was buried in the middle
  of it.** File order ran 2.0.1, 1.3.0, 1.2.0, 1.1.0, 1.0.1, `[Unreleased]`,
  2.0.0 — so `2.0.0` sat *below* the 1.x entries and a reader scanning from the
  top saw 2.0.1 followed by 1.3.0, with every reason to conclude the 2.0.0
  release was never documented. Anyone accumulating into `[Unreleased]` was also
  writing into the middle of the file.

  Reordered newest-first per Keep a Changelog, with `[Unreleased]` at the top.
  No entry text changed — verified as an exact multiset of the original lines.

## [2.0.1] — 2026-08-09

### Fixed

- **Numeric strings past `PHP_INT_MAX` were clamped, and negative exponents read
  as zero.** Cell values were coerced with `str_contains($v, '.') ? (float) : (int)`,
  and that dot test is not a numeric test:

  | input | was | now |
  |---|---|---|
  | `"1e21"` | `9223372036854775807` (`PHP_INT_MAX`) | `1e21` |
  | `"99999999999999999999"` | `9223372036854775807` | `1e20` |
  | `"2e-3"` | `0` | `0.002` |

  Now `$value + 0`, which is PHP's own numeric-string coercion: still `int` for
  integral in-range strings (`"007"` is `7`, unchanged), `float` otherwise.
  Fixed in `CsvBuilder`, `Schema/Normalizer` and `Schema/Repairer` — all three
  carried the same line.

  Nothing threw: a clamped value is a plausible-looking integer, so a sheet with
  the wrong number in it opened fine and was believed.

- **`NAN` and `INF` were written into cells** as `<v>NAN</v>`, which is neither a
  number nor valid cell content. Both now write `0`, matching the Node port.

  **What you must do:** nothing, unless you were relying on a clamped value — in
  which case the sheet had the wrong number in it and now has the right one.

### Note

`2.0.0` has no entry in this file. It predates the changelog rule being enforced
here, and the PHP repos have no publish-time gate to catch that the way the npm
ones do. Left as-is rather than reconstructed after the fact.

## [2.0.0] — 2026-08-07

### Changed

- **BREAKING — PHP 8.2 is no longer supported.** `require.php` moves from `^8.2` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.2, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- CI now tests PHP 8.4 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

This package is past 1.0, so a floor raise takes a **major**. Most of the suite is pre-1.0 and lands the identical change in a minor — that is semver, not a difference in how much changed. **No API changed, nothing was removed, nothing was renamed.**


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
