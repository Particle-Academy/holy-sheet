# The read path — `Agent::describe()` / `Agent::read()`

`Agent::describe(string $path): array` round-trips an xlsx file back to a Holy Sheet schema. The returned array can be fed straight back into `Agent::write()` — that's the contract.

`Agent::read(string $bytesOrPath): array` is the same read under the name the sibling packages (last-word, dark-slide, and holy-sheet's JS and Python ports) use, and it also takes raw bytes. `describe()` is unchanged.

It reads an OpenDocument spreadsheet (`.ods`) too, into the same schema: see [OpenDocument spreadsheets](#opendocument-spreadsheets-ods).

```php
use HolySheet\Agent;

$schema = Agent::describe('/path/to/workbook.xlsx');
// modify…
Agent::write($schema, '/path/to/workbook.xlsx');
```

Same surface via the Laravel facade:

```php
use HolySheet\Laravel\Facades\HolySheet;
$schema = HolySheet::describe(storage_path('app/exports/q4.xlsx'));
```

Returns `['error' => 'not_found', 'path' => …]` if the file doesn't exist. Throws `HolySheet\Exceptions\UnsupportedFormatException` if the file is neither an xlsx nor an ods (not a zip at all, or a zip of something else such as an OpenDocument text file; `$e->mimetype` says what it declared). That exception extends `RuntimeException`, which is what an unreadable file threw before 2.2, so an existing `catch (RuntimeException)` still catches it.

## Output shape

The returned schema is **cell-keyed** (not row-list-keyed) so round-tripping styled, sparse, formula-bearing workbooks is lossless:

```php
[
    'sheets' => [
        [
            'name' => 'Q4',
            'cells' => [
                'A1' => ['value' => 'Region'],
                'B1' => ['value' => 'Revenue'],
                'A2' => ['value' => 'NA'],
                'B2' => ['value' => 4_820_000, 'format' => ['displayFormat' => 'currency', 'currency' => 'USD', 'decimals' => 2]],
                'B5' => ['formula' => 'SUM(B2:B4)', 'computedValue' => 12_180_000],
            ],
            'mergedRegions' => [['start' => 'A1', 'end' => 'C1']],
            'columnWidths' => [0 => 120, 1 => 140],
            'frozenRows' => 1,
            // only when present in the file:
            'state' => 'hidden',                       // or 'veryHidden'
            'hiddenRows' => [3],                       // 1-based
            'hiddenColumns' => [2],                    // 0-based, as columnWidths
            'tables' => [['name' => 'Sales', 'ref' => 'A1:B5', 'headerRowCount' => 1]],
        ],
    ],
    'definedNames' => [['name' => 'Revenue', 'ref' => "'Q4'!\$A\$1:\$B\$5"]],
    'meta' => ['creator' => '…', 'created' => '2026-05-01T12:00:00Z'],
]
```

`state`, `hiddenRows`, `hiddenColumns`, `tables` and `definedNames` appear only when the file has them, so a plain workbook describes exactly as before. Hidden rows and columns are listed only as far as the sheet holds anything: "hide every column from D on" is one element for 16,381 empty columns, and is not expanded. Excel's own `_xlnm.*` names (print areas, filter ranges) are not listed.

`Agent::write()` accepts this shape directly — the writer treats `cells` as authoritative when present.

## What round-trips cleanly

| Feature | Round-trip |
|---|---|
| Cell values (string, number, bool, date) | ✓ |
| Formulas + cached results | ✓ |
| Bold / italic / font size / color | ✓ |
| Background fill color | ✓ |
| All four borders + colors | ✓ |
| Text alignment | ✓ |
| Number / currency / percent / date / datetime formats | ✓ (Holy-Sheet-authored) and ✓ (Excel built-in numFmtIds 0–49) |
| Comments (text + author) | ✓ |
| Merged regions | ✓ |
| Column widths | ✓ (within rounding of Excel's px ↔ char-units conversion) |
| Frozen rows / cols | ✓ |
| Sheet state (hidden / veryHidden), hidden rows, hidden columns | ✓ |
| Foreign number-format codes (`format.formatCode`) | ✓ written back verbatim |
| Native charts | ✓ |
| Defined names, Excel tables | read only: reported, not written back |
| docProps `creator` + `created` | ✓ |

## Lossy fields (by design)

- **Themes** are write-time presets that bake into individual cell formats. A described workbook returns explicit per-cell formatting instead of a `theme: 'business'` directive — the round-trip is *equivalent*, not *identical*. To re-apply a theme on a described file, set `theme` on the sheet and re-write; theme styling will overlay the per-cell formats.
- **Custom number-format codes that don't match a recognized pattern** keep their raw code. The reader recognizes every format code Holy Sheet's writer emits, plus the standard 50 built-in numFmtIds, as a `displayFormat`; any other code comes back as `format.formatCode` (`"# ?/?"`), which the writer writes back verbatim. Portable content reports such a cell as `format-approximated`.
- **Defined names and Excel tables** are read (`definedNames`, `tables`) so `extract()` can select by them, but the writer does not author them: a describe -> write round-trip drops them.
- **Native charts are parsed** (`XlsxReader` reads each chart part into the sheet's `charts`, the same shape the writer authors). **Images, other drawings and pivot tables** are not parsed.

## OpenDocument spreadsheets (.ods)

Since 2.2, `describe()` reads `.ods` into the same schema as `.xlsx`. The format is decided by the file's contents, never its name: an OpenDocument package declares itself in its `mimetype` entry (`application/vnd.oasis.opendocument.spreadsheet`, or the `-template` variant), and an xlsx has `xl/workbook.xml`.

The same workbook saved both ways describes the same way. `tests/Unit/OdsReaderTest.php` asserts exactly that, on a workbook written by this package and converted to ods by LibreOffice.

| Feature | ods |
|---|---|
| Numbers, percentages, currency, booleans | ✓ |
| Strings, including several paragraphs (joined with `\n`), runs, links, `text:s` spaces, tabs, line breaks | ✓ |
| Dates and datetimes, as ISO strings in UTC; times on the 1899-12-30 epoch, as xlsx shows them | ✓ |
| Formulas, OpenFormula translated to A1 (`of:=SUM([.A1:.B2];[$'Other'.C3])` → `SUM(A1:B2,'Other'!C3)`), with `computedValue` | ✓ |
| Repeated cells and rows | ✓ expanded only where they hold something; the padding rows and columns every producer writes are skipped |
| Merged regions, and content inside a covered cell | ✓ |
| Comments (text + author) | ✓ |
| Bold / italic / font size / colour / background / four border colours / horizontal alignment | ✓ through parent styles, row and column default styles, and the document default |
| Number / percentage / currency / date / datetime / text data styles | ✓ |
| `meta:initial-creator` (or `dc:creator`) + `meta:creation-date` | ✓ as `creator` + `created` |
| Hidden sheets (`table:display="false"`), hidden rows and columns (`table:visibility` collapse or filter) | ✓ as `state: hidden`, `hiddenRows`, `hiddenColumns` |
| Frozen panes | ✗ view settings, not document content |
| Column widths, row heights | ✗ stored for every column, whether or not anyone sized it |
| Fonts, underline, wrapping, vertical alignment, border widths, conditional formats, validation, named ranges, charts, images | ✗ |
| Flat `.fods` files | ✗ not a zip package; convert to `.ods` first |

Details worth knowing:

- **Formulas.** Function names are not translated: OpenFormula and Excel agree on the common ones, and where they differ (`COM.MICROSOFT.IFS` against `_xlfn.IFS`) the name comes back as written. So do the `~` union and `!` intersection operators, and references to other files. `msoxl:` formulas are already Excel syntax and are only unwrapped.
- **Booleans.** LibreOffice stores a typed TRUE or FALSE as the formula `TRUE()` / `FALSE()` on a boolean cell. That reads back as the boolean it was entered as, not as a formula.
- **Font size** is reported when it differs from the document's default cell style, for the same reason the xlsx reader leaves out Excel's 11pt.
- **`displayFormat: auto`.** The xlsx reader reports `auto` (Excel's "General") on every unformatted cell, because every xlsx cell has a style. An unformatted ods cell has none, so no `auto` is invented. Written back without it, a cell gets the default style, whose number format is also General.
- **Date formats from the value type.** An ods cell says what its value IS (`office:value-type`), separately from how it is shown, so a date cell with no data style is still reported as `date` / `datetime`.

## Exchange: catalog, extract, export

Holy Sheet implements the portable-content format in `fancy-conformance`'s
`suites/exchange/SPEC.md` (`fancyContent: 1`), so an agent can grab part of a
spreadsheet and place it in a docx, pdf, slide or another workbook. Every
applicable `exchange/*` suite runs in `tests/Unit/ConformanceTest.php`.

Each takes bytes, a path, or a model `read()` already returned.

```php
$cat = Agent::catalog($xlsx);                       // sheets, definedNames, tables, charts: each with its selector
$x   = Agent::extract($xlsx, 'Q4!A1:D20');           // ['ok' => true, 'content' => [...], 'report' => [...]]
$x   = Agent::extract($xlsx, ['table' => 'Sales']);  // or {name}, {sheet, range}, "'Q4 2026'!A1:D20", "Q4", null
```

- **Selectors.** `"Q4!A1:D20"`, `"'Q4 2026'!A1:D20"` (quote any name that is not `[A-Za-z_][A-Za-z0-9_.]*`), `"Q4"` (the sheet's used range; a string without `!` is always a sheet, never a cell), `{sheet, range}`, `{name}`, `{table}`, or `null` for every visible sheet. Names match ASCII case-insensitively.
- **Header rows**, in order: the selector's or option's `headerRows` (not both: that is refused); an Excel table's header count; `frozenRows` when the range starts on row 1; else 0. The value used is in `source.selector.headerRows`.
- **Formulas** come back as their cached values. A formula with no cached value is an empty cell, reported `formula-uncomputed`. Nothing is ever evaluated.
- **Hidden sheets, rows and columns** are skipped and reported (`hidden-sheet`, `hidden-row`, `hidden-column`); a selector naming a hidden sheet is refused `selector_hidden`. A value stored under a merged cell is never emitted (`hidden-element`). Only the host may pass `includeHidden: true`.
- **Invisible characters** (zero-width, bidi overrides and isolates, Unicode tags) are stripped from every string and counted in `report.guard`. Reports carry locations and counts, never document text.
- **Number display**: `text` is what the spreadsheet shows (`$4,820,000.00`, `12.5%`), rounded half away from zero on the decimal digits. A foreign format code shows the plain value (`format-approximated`).
- **Limits** (host-overridable through `limits`): 5,000 items, 2,000 rows, 64 columns, 10,000 cells, 4 MiB of strings. Over one, extract refuses with a stable code; it never cuts. A cell string over 32 KiB is the single lossy exception (`cell-truncated`).

`extract` is a read, and is not gated. **Exports are**, and are off by default:

```php
use HolySheet\Exchange\ExportPolicy;

$policy = ExportPolicy::allow(['csv', 'markdown']);          // the HOST builds this
$out = Agent::export($xlsx, 'csv', ['selector' => 'Q4'], $policy);
// ['ok' => true, 'target' => 'csv', 'mime' => 'text/csv; charset=utf-8', 'text' => "...", 'report' => [...]]

$tool = Agent::exportToolDefinition($policy);   // target enum: ONLY the enabled targets; null if none
```

| Target | What |
|---|---|
| `csv` | One table, RFC 4180, CRLF records, values (not display strings, unless the agent passes `display: true`). A field beginning `=`, `+`, `-`, `@`, TAB or CR is prefixed `'` (`csv-escaped`); only the host turns that off, with `['csv' => ['escapeFormulas' => false]]`. With no selector and more than one visible sheet it is refused (`export_refused`, cause `selector_invalid`) rather than silently keeping one. |
| `markdown` | GFM tables; every inline marker in cell text is backslash-escaped, so text cannot become a link, an image or HTML. |
| `xlsx` | From an ods (or an xlsx template): a real conversion that keeps hidden sheets, rows and columns hidden. An xlsx document comes back byte for byte. |
| `pdf`, `docx` | Need fine-print / last-word; not bridged in this release, so `export_unavailable` (with `missing`) whatever is installed. |

The policy is never agent input. A null policy allows nothing; an `allow` (or anything but `selector`, `headerRows`, `display`) in the agent's options throws `OptionsInvalid`. Refusals are `{ok: false, refused: {reason, target, missing?, cause?}}`, checked in this order: `export_unsupported`, `export_disabled`, `export_unavailable`, `export_refused` (the input could not be read; `cause` is the reader's code), then a limit code.

Going the other way, `Agent::fromPortable($content, 'B4')` turns portable content into cells, and a sheet's `insert` list places it inside a workbook you are writing: see [Schema](./Schema.md#placing-portable-content-insert).

## The recovery loop

Combined with `validateAndRepair`, `describe` enables agents to read → diagnose → fix → write in 1–3 turns instead of 5–10:

```php
// 1. Read
$schema = Agent::describe('/tmp/in.xlsx');

// 2. Modify (e.g., recalc a totals row)
$schema['sheets'][0]['cells']['B5']['formula'] = 'SUM(B2:B4)';

// 3. Validate + auto-repair if the agent introduced typos
$result = Agent::validateAndRepair($schema);
if ($result['errors'] === []) {
    Agent::write($result['schema'], '/tmp/out.xlsx');
}
```

See `examples/round-trip.php` for a runnable demo.
