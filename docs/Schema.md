# Schema reference

Holy Sheet's input is a single JSON-shaped array describing a workbook. The validator lives at `HolySheet\Schema\Validator` and produces structured errors with `path` / `expected` / `got` / `value` / `hint` fields. The full machine-readable spec is at [`skills/holy-sheet.schema.json`](../skills/holy-sheet.schema.json).

This page is the human-readable companion: every field, every type, every option, with examples.

## Top level

```json
{
  "sheets": [...],          // required — at least one sheet
  "meta": {                 // optional
    "creator": "My App",
    "created": "2026-05-06T10:00:00Z"
  }
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `sheets` | yes | Array of sheet definitions. Must contain at least one. The first sheet is shown when the file opens. |
| `meta.creator` | no | Surfaced in Excel's File → Info pane. |
| `meta.created` | no | ISO-8601 datetime. Defaults to `now()`. |

## Sheet

A sheet has a `name` plus EITHER `columns + rows` (row-oriented, agent-ergonomic) OR `cells` (sparse A1-keyed, fancy-sheets passthrough).

```json
{
  "name": "Q4 Sales",                      // required, ≤31 chars, no /\?*[]:
  "theme": "default",                       // optional: default | business | minimal | plain
  "columns": [...],                         // row-oriented mode: column definitions
  "rows": [...],                            // row-oriented mode: data rows
  "cells": { "A1": {...}, "B2": {...} },    // sparse mode: A1-keyed cells
  "totals": { "Revenue": "sum" },           // optional aggregation row
  "mergedRegions": [{"start": "A1", "end": "C1"}],
  "columnWidths": { "0": 200, "1": 80 },    // 0-based col index → pixels
  "frozenRows": 1,
  "frozenCols": 0
}
```

### Modes

**Row-oriented** is the agent-ergonomic shape. Declare column types once; every row inherits the type's display format.

**Sparse `cells`** is the passthrough for `<Spreadsheet>` exports — keys are A1 references, values are full `CellData` objects. Use this when you have arbitrary cell layout (gaps, mixed types in same column, custom per-cell styling).

## Column

```json
{
  "header": "Revenue",          // required — visible in row 1
  "type": "currency",            // see types below
  "currency": "USD",             // when type=currency
  "decimals": 2,                 // for number/currency/percent
  "format": "0.00 \"units\"",    // power-user: raw Excel format string
  "width": 120                   // pixels
}
```

### Types

| Type | Behavior |
|------|----------|
| `auto` (default) | Infer per cell — strings stay strings, numbers stay numbers, booleans stay booleans |
| `string` | Inline string |
| `number` | Numeric, decimals optional |
| `integer` | Numeric, no decimals |
| `boolean` | TRUE/FALSE |
| `currency` | Numeric + currency format. Set `currency` to ISO code (USD/EUR/GBP/JPY/CNY/INR/AUD/CAD/CHF/KRW + others fall back to ISO prefix). Default decimals = 2. |
| `percent` | Numeric + percent format. Pass raw fraction (`0.124` → 12.4%). Default decimals = 1. |
| `date` | Numeric serial + date format. Accepts ISO strings (`"2026-05-01"`) or `DateTimeInterface`. |
| `datetime` | Numeric serial + datetime format. Same accepted inputs as `date` but preserves time component. |
| `formula` | Cell value is a formula. Use the `CellData` shape for individual formula cells; `formula` column type is reserved for future per-column formula generation. |

## Cell value (in row-oriented mode)

A row is an array of values, one per column. Each value can be:

- **A primitive**: `"North America"`, `4820000`, `0.124`, `true`, `null` — the column's type determines how it's rendered
- **A `CellData` object**: `{ "value": 100, "format": { "bold": true, "color": "#10b981" } }` — overrides the column's defaults for this single cell

### CellData

```json
{
  "value": 4820000,                          // primitive (string/number/bool/null)
  "formula": "SUM(A1:A10)",                  // optional — formula without leading "="
  "computedValue": 4820000,                  // optional — cached result, emitted as <v>
  "format": {                                // optional — see CellFormat
    "bold": true,
    "italic": false,
    "textAlign": "right",
    "displayFormat": "currency",
    "decimals": 2,
    "currency": "USD",
    "color": "#FFFFFF",
    "backgroundColor": "#1F2937",
    "fontSize": 12,
    "borderTop": "#000000",
    "borderRight": null,
    "borderBottom": null,
    "borderLeft": null
  },
  "comment": {                               // optional — see Comment
    "text": "This figure is preliminary",
    "author": "Agent",
    "color": "#f59e0b"
  }
}
```

## Cells (sparse mode)

```json
"cells": {
  "A1": { "value": "Header", "format": { "bold": true } },
  "B2": { "value": 100 },
  "B3": { "formula": "SUM(B2:B5)", "computedValue": 100 }
}
```

A1 keys must match `^[A-Z]+[1-9][0-9]*$`. Each value follows the `CellData` shape above. Sparse mode means missing addresses are simply absent in the output — perfect for grids with gaps.

## Themes

| Theme | Headers | Body | Totals row |
|-------|---------|------|-----------|
| `default` | Bold white text on dark slate background | Banded rows (alternating fill) | Bold + top border |
| `business` | Bold white on near-black | Banded rows | Bold + top border |
| `minimal` | Bold + bottom border | Plain | Bold + top border |
| `plain` | No formatting | No formatting | No formatting |

Theme defaults are merged with per-column types — a `currency` column under the `default` theme gets BOTH the theme's banded-row fills AND the currency number format.

## Symbolic totals

```json
"totals": {
  "Revenue": "sum",
  "YoY": "avg",
  "Customers": "count",
  "Min Price": "min",
  "Max Price": "max"
}
```

Resolves at write time to `SUM/AVERAGE/COUNT/MIN/MAX` formulas appended in a row below the data, with the totals theme format applied. Keys are column **headers** (not A1 refs) — the package figures out which column letter to point at.

## Merged regions

```json
"mergedRegions": [
  { "start": "A1", "end": "C1" },     // merge title across 3 columns
  { "start": "A2", "end": "A4" }      // merge a label down 3 rows
]
```

## Frozen panes

```json
"frozenRows": 1,    // header row stays visible when scrolling
"frozenCols": 1     // first column stays visible when scrolling sideways
```

## Column widths

```json
"columnWidths": {
  "0": 200,    // column A: 200 pixels
  "2": 80      // column C: 80 pixels
}
```

Keys are 0-based column indexes. The package converts pixels to Excel's character-width unit (Excel uses ~7 pixels per character).

## Date handling

Pass dates as ISO-8601 strings (`"2026-05-01"`, `"2026-05-01T14:30:00Z"`) OR PHP `DateTimeInterface` objects. Holy Sheet converts to Excel serial numbers (days since 1899-12-30, with time as fractional days).

```json
{ "header": "When", "type": "date" }
// rows: [["2026-05-01"], ["2026-05-02"]]   ← strings
// rows: [[$dateObj]]                         ← DateTime / DateTimeImmutable
```

Don't pass Excel serial numbers directly — let the package convert.

## Validation errors

Every error has the same structured shape:

```json
{
  "path": "sheets[0].rows[2][1]",
  "expected": "number",
  "got": "string",
  "value": "n/a",
  "hint": "Use null for missing values, or change the column type to 'string'."
}
```

Call `Agent::validate($schema)` for a dry-run that returns the error list without writing anything (empty list = valid).

## See also

- [Recipes](./Recipes.md) — concrete end-to-end patterns
- [LaravelAdapter](./LaravelAdapter.md) — facade, service provider, artisan command
- [JSON Schema](../skills/holy-sheet.schema.json) — machine-readable spec
- [SKILL](../skills/holy-sheet.md) — agent-facing prompt
