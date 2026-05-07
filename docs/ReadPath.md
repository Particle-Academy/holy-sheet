# The read path — `Agent::describe()`

`Agent::describe(string $path): array` round-trips an xlsx file back to a Holy Sheet schema. The returned array can be fed straight back into `Agent::write()` — that's the contract.

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

Returns `['error' => 'not_found', 'path' => …]` if the file doesn't exist. Throws `RuntimeException` if the file isn't a valid zip / OOXML package.

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
