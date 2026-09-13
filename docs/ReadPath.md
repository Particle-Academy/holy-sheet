# The read path — `Agent::describe()`

`Agent::describe(string $path): array` round-trips an xlsx file back to a Holy Sheet schema. The returned array can be fed straight back into `Agent::write()` — that's the contract.

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
        ],
    ],
    'meta' => ['creator' => '…', 'created' => '2026-05-01T12:00:00Z'],
]
```

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
| docProps `creator` + `created` | ✓ |

## Lossy fields (by design)

- **Themes** are write-time presets that bake into individual cell formats. A described workbook returns explicit per-cell formatting instead of a `theme: 'business'` directive — the round-trip is *equivalent*, not *identical*. To re-apply a theme on a described file, set `theme` on the sheet and re-write; theme styling will overlay the per-cell formats.
- **Custom number-format codes that don't match a recognized pattern** fall through. The reader recognizes every format code Holy Sheet's writer emits, plus the standard 50 built-in numFmtIds. Foreign codes outside that set return as raw `format` strings.
- **Shared strings** — Excel may store strings in `xl/sharedStrings.xml`. The 1.1 reader returns shared-string indices with a `[shared:N]` placeholder; full sharedStrings expansion lands in 1.2.
- **Charts, images, drawings, pivot tables** — not parsed (and not authored by Holy Sheet either).

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
