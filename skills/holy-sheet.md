---
name: holy-sheet
description: Write XLSX (Excel) spreadsheets from a JSON schema. Use this skill whenever an output should be a spreadsheet — sales reports, data exports, formatted tables, multi-sheet workbooks, anything Excel-shaped.
trigger_keywords:
  - xlsx
  - spreadsheet
  - excel
  - workbook
  - "export to spreadsheet"
  - "create a spreadsheet"
entry_points:
  cli: "php artisan holy-sheet:write --out=<path>  (reads JSON schema from stdin)"
  php: "HolySheet\\Agent::write($schema, $path)"
  http: "POST /holy-sheet/export  (Laravel apps that opt-in to the route)"
schema_url: "vendor/particle-academy/holy-sheet/skills/holy-sheet.schema.json"
version: "1.1"
---

# Skill: holy-sheet

Holy Sheet writes valid XLSX files from a JSON schema. It's the structured-output way to produce spreadsheets — instead of building cells one at a time, you describe the workbook as data and the package emits the file.

## When to use

✅ **Use holy-sheet when:**
- The user asks for a spreadsheet, Excel file, xlsx, workbook
- The output is tabular and the user wants headers, totals, formatting, or multiple sheets
- You generated structured data (an array of records) and the user wants Excel
- A web UI's "Export to xlsx" button needs to deliver a file

❌ **Don't use holy-sheet when:**
- Plain CSV is fine and there's no formatting / formulas / multi-sheet need (just write the CSV directly)
- The user wants real-time interactive editing (use the `<Spreadsheet>` React component from `@particle-academy/fancy-sheets` instead)
- The data is binary / non-tabular (PDFs, images, graphs go elsewhere)

## Minimum viable invocation

The smallest valid schema. Copy this, change the values, you're done:

```json
{
  "sheets": [
    {
      "name": "Sheet 1",
      "columns": [
        { "header": "Name" },
        { "header": "Age", "type": "integer" }
      ],
      "rows": [
        ["Alice", 30],
        ["Bob",   42]
      ]
    }
  ]
}
```

Pipe it through the CLI:

```bash
echo '<schema-above>' | php artisan holy-sheet:write --out=people.xlsx
```

Or call from PHP:

```php
use HolySheet\Agent;
Agent::write($schema, '/path/to/people.xlsx');
// → ['path' => '/path/to/people.xlsx', 'bytes' => 1234, 'sheets' => 1]
```

## Schema reference (essentials)

The full JSON Schema lives at `skills/holy-sheet.schema.json`. The fields you'll use in 95% of cases:

### Workbook
| Field | Type | Notes |
|-------|------|-------|
| `sheets` | array (required, ≥1) | One or more sheets |
| `meta.creator` | string | Shown in Excel File → Info |

### Sheet
| Field | Type | Notes |
|-------|------|-------|
| `name` | string (required) | ≤31 chars; `\/?*[]:` forbidden |
| `columns` | array of `Column` | Header row 1 + per-column types |
| `rows` | array of arrays | One per data row, in column order |
| `cells` | object keyed by A1 | Use INSTEAD of columns/rows when you have sparse data or are exporting fancy-sheets state |
| `theme` | `default` / `minimal` / `plain` / `business` | Pre-baked styling |
| `frozenRows` / `frozenCols` | integer | Lock the first N rows/cols on scroll |
| `mergedRegions` | `[{start, end}]` | Cell ranges to merge |
| `totals` | `{ColumnHeader: 'sum'\|'avg'\|'count'\|'min'\|'max'}` | Symbolic aggregations appended at the bottom |

### Column
| Field | Type | Notes |
|-------|------|-------|
| `header` | string (required) | Label shown in row 1 |
| `type` | `auto`/`string`/`number`/`integer`/`boolean`/`date`/`datetime`/`currency`/`percent`/`formula` | Default `auto` (infer per cell) |
| `currency` | ISO-4217 code | `"USD"`, `"EUR"`, `"JPY"` — only with `type: currency` |
| `decimals` | integer 0-10 | Display precision |
| `width` | number (px) | Column width override |

### Per-cell format (when you need fine control)

Inside `rows`, replace a primitive with `{value, formula?, format?, comment?}`:

```json
"rows": [
  ["Alice", { "value": 4500, "format": { "bold": true, "color": "#10b981" } }]
]
```

Or use the sparse `cells` shape for sheet-wide control:

```json
"cells": {
  "A1": { "value": "Q4 Sales", "format": { "bold": true, "fontSize": 14 } },
  "B2": { "value": 4820, "format": { "displayFormat": "currency", "decimals": 0 } }
}
```

## Recipes

### 1. Sales report with totals row

```json
{
  "sheets": [
    {
      "name": "Q4 Sales",
      "columns": [
        { "header": "Region", "type": "string" },
        { "header": "Revenue", "type": "currency", "currency": "USD" },
        { "header": "YoY", "type": "percent", "decimals": 1 }
      ],
      "rows": [
        ["North America", 4820000, 0.124],
        ["Europe", 3210000, 0.081],
        ["APAC", 2895000, 0.227]
      ],
      "totals": { "Revenue": "sum", "YoY": "avg" },
      "theme": "default"
    }
  ]
}
```

### 2. Multi-sheet workbook

```json
{
  "sheets": [
    {
      "name": "Q4 Summary",
      "columns": [{ "header": "Metric" }, { "header": "Value", "type": "number" }],
      "rows": [["Revenue", 10925000], ["Customers", 12840]]
    },
    {
      "name": "By Region",
      "columns": [{ "header": "Region" }, { "header": "Revenue", "type": "currency" }],
      "rows": [["North America", 4820000], ["Europe", 3210000]]
    }
  ]
}
```

### 3. Cross-sheet formula

```json
{
  "sheets": [
    { "name": "Detail", "columns": [{ "header": "Amount", "type": "number" }], "rows": [[100], [200], [300]] },
    {
      "name": "Summary",
      "cells": {
        "A1": { "value": "Total" },
        "B1": { "formula": "SUM(Detail!A2:A4)" }
      }
    }
  ]
}
```

### 4. Date column

```json
{
  "sheets": [{
    "name": "Events",
    "columns": [
      { "header": "Date", "type": "date" },
      { "header": "Title", "type": "string" }
    ],
    "rows": [
      ["2026-05-01", "Launch"],
      ["2026-06-15", "Review"]
    ]
  }]
}
```

Pass dates as **ISO-8601 strings** (`YYYY-MM-DD` or full `YYYY-MM-DDTHH:MM:SS`). Don't pass Excel serial numbers — the package handles the conversion.

### 5. Currency column

```json
{
  "sheets": [{
    "name": "Invoices",
    "columns": [
      { "header": "Invoice", "type": "string" },
      { "header": "Amount", "type": "currency", "currency": "USD", "decimals": 2 }
    ],
    "rows": [
      ["INV-001", 1234.56],
      ["INV-002", 789.00]
    ]
  }]
}
```

Pass the **raw number** (1234.56), not a pre-formatted string (`"$1,234.56"`). Holy Sheet applies the format.

### 6. Bold header overrides + frozen header row

```json
{
  "sheets": [{
    "name": "Inventory",
    "frozenRows": 1,
    "theme": "default",
    "columns": [{ "header": "SKU" }, { "header": "Stock", "type": "integer" }],
    "rows": [["A-001", 42], ["A-002", 17]]
  }]
}
```

`theme: "default"` already gives you bold headers + banded rows. `frozenRows: 1` keeps the header visible when the user scrolls.

### 7. Highlight a specific cell

```json
{
  "sheets": [{
    "name": "Status",
    "rows": [
      ["Healthy", { "value": "WARNING", "format": { "bold": true, "backgroundColor": "#fee2e2", "color": "#991b1b" } }, "Healthy"]
    ]
  }]
}
```

### 8. Export from `<Spreadsheet>` (fancy-sheets passthrough)

If you have a `WorkbookData` from the React `<Spreadsheet>` component, pass its `sheets[].cells` map directly:

```json
{
  "sheets": [
    {
      "name": "Imported",
      "cells": {
        "A1": { "value": "Header" },
        "A2": { "value": 100 },
        "A3": { "value": 200 },
        "A4": { "formula": "SUM(A2:A3)" }
      },
      "frozenRows": 1,
      "columnWidths": { "0": 120 }
    }
  ]
}
```

## Errors

If validation fails, you get a structured error list — no half-written files. Each error has:

| Field | Meaning |
|-------|---------|
| `path` | JSON pointer-style path to the problem (`sheets[0].rows[2][1]`) |
| `expected` | What the schema wanted |
| `got` | What it received |
| `value` | The actual offending value |
| `hint` | One-line recovery suggestion |

Example:

```json
{
  "path": "sheets[0].rows[2][1]",
  "expected": "number",
  "got": "string",
  "value": "n/a",
  "hint": "Use null for missing values, or change the column type to 'string'."
}
```

To dry-run without writing, call `Agent::validate()` — it returns the error list (empty list = valid).

## Anti-patterns

- ❌ **Don't put A1 references in row data.** Use `totals` shorthand or `@ColumnName` references in formulas. Agents that write `[["Total", "=SUM(B2:B10)"]]` directly will work, but the schema-driven path handles ranges automatically.
- ❌ **Don't pass dates as Excel serial numbers** (e.g. `45678`). Use ISO strings (`"2026-05-01"`) or PHP `DateTimeInterface` objects.
- ❌ **Don't pre-format numbers as strings.** Pass `4820000`, not `"$4,820,000"`. Use `type: "currency"` for the formatting.
- ❌ **Don't construct multiple workbook calls for one file.** A single `write()` call takes the full schema. Multiple calls would overwrite the previous file.
- ❌ **Don't mix `columns + rows` and `cells` in the same sheet.** Pick one shape per sheet. The package gives precedence to `cells` if both appear.

## Tool-use definition

The exact JSON Schema for tool integration:

```php
$schema = HolySheet\Agent::toolDefinition();
// Returns the contents of skills/holy-sheet.schema.json as a parsed array.
```

Drop into Anthropic's `tool_use`:

```json
{
  "name": "holy_sheet_write",
  "description": "Write an xlsx workbook from a structured schema.",
  "input_schema": <output of toolDefinition()>
}
```

## Round-trip introspection (0.9+)

Once `Agent::describe(path)` lands, you can read an existing xlsx back to a Holy Sheet schema, modify, and re-emit. The full pipeline:

```
agent generates → write() → user opens xlsx → user edits in Excel
                                              → describe() → agent reads → re-generate
```

This is the loop for "make these changes to my spreadsheet" workflows.

## 1.1: read, repair, build (the agentic loop)

### Round-trip an existing xlsx with `Agent::describe()`

When the user gives you an xlsx file (or you wrote one and need to revise), don't regenerate from scratch. Read it back to a schema, mutate the schema, write again:

```php
$schema = HolySheet\Agent::describe('/tmp/in.xlsx');
// modify cells, formulas, merges…
HolySheet\Agent::write($schema, '/tmp/out.xlsx');
```

`describe()` returns the same shape `write()` consumes. Holy-Sheet-authored files round-trip without loss; foreign Excel files round-trip everything except themes (they bake into per-cell formats — equivalent, not identical) and obscure custom number-format codes (returned raw).

### Recover from imperfect schemas with `Agent::validateAndRepair()`

If your generated schema has high-confidence-fixable issues (singular `sheet`, `row` typo, `{0:…,1:…}` instead of `[…]`, stringified numbers, unknown theme), run:

```php
$result = HolySheet\Agent::validateAndRepair($schema);
// $result = ['schema' => array, 'errors' => list, 'repairs' => list<string>]
```

Apply `$result['schema']` directly; check `$result['repairs']` to learn what got fixed. Anything ambiguous stays in `$result['errors']` for you to address.

### Build schemas from data you already have

Three helpers replace hand-crafted schema arrays — type inference reads header names + sample values:

```php
HolySheet\Agent::fromArray($rows, $headers);
HolySheet\Agent::fromCsv($csvOrPath);
HolySheet\Laravel\Facades\HolySheet::fromQuery($eloquentBuilder, $columns);
```

Inference picks `currency` for headers like *revenue/amount/price*, `percent` for *rate/growth/yoy* (when values are in [0,1]), `integer` for *count/qty/id*, ISO date strings → `date`/`datetime`, otherwise `auto`/`string`. Eloquent `$casts` win over header inference when you pass a Builder.

### What gets auto-repaired

Conservative repairs only — the package never invents missing required fields. The repairer fixes:

- Top-level `sheet` → `sheets` (renames + wraps)
- Sheet `row` → `rows`
- Row map → row list (`{0:…,1:…}` → `[…]`)
- Stringified numerics in number/integer/currency/percent columns
- Unknown theme → `'default'`
- Whitespace in cell addresses
- Missing column type, all-ISO-date values → infer `'date'`

It does **not** fix: missing `sheets`, missing sheet `name`, type mismatches outside the stringified-numeric case, anything with multiple plausible interpretations. Those come back as errors for the agent to handle.
