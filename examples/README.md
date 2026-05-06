# Examples

Runnable demos for `particle-academy/holy-sheet`. All examples are framework-free PHP — they call the static `HolySheet\Agent::*` API directly.

## sales-report.php

A 3-sheet workbook touching every feature surface in 1.0:

| Sheet | Demonstrates |
|-------|--------------|
| **Q4 Sales** | Row-oriented mode, currency + percent + date column types, symbolic totals (`sum` + `avg`), default theme (banded rows + bold dark header), frozen header row, custom column widths |
| **Notes** | Sparse `cells` map, merged title cell across 4 columns, cross-sheet formula references (`'Q4 Sales'!B2:B6`), cell comment with author + color, custom font size + alignment |
| **Status** | Per-cell `format` override (red WARNING badge, green Healthy state), `minimal` theme, integer column type |

### Run

```bash
cd packages/holy-sheet
composer install --no-dev    # if you haven't already
php examples/sales-report.php /tmp/sales.xlsx
```

Output:

```
✓ wrote /tmp/sales.xlsx
  3 sheets, 5.2 kB

Open in Excel / LibreOffice / Google Sheets to inspect.
```

Open the file. You should see:
- Sheet 1 with bold dark header, banded rows, currency-formatted Revenue column, percent-formatted YoY column with arrow-friendly decimals, ISO date column rendering as native Excel dates, and a Total/AVERAGE row at the bottom with a top border
- Sheet 2 with a centered dark title spanning 4 columns, a Total cell pulling its value from Sheet 1 via formula, and a small comment indicator on the "Note" cell
- Sheet 3 with a single red-highlighted WARNING cell, otherwise minimal styling

### Modifying the demo

The schema is a plain PHP array — open `sales-report.php` and tweak. Common quick edits:

- **Change the data**: rewrite the `'rows'` arrays
- **Try a different theme**: swap `'theme' => 'default'` for `'business'`, `'minimal'`, or `'plain'`
- **Add a column**: append to `'columns'` and add a value to every row in `'rows'`
- **Add a sheet**: append another entry to `'sheets'`

### Verifying the package works

If `php examples/sales-report.php` succeeds and the file opens correctly in your spreadsheet application of choice, the package is functioning end-to-end on your system. Useful as a smoke check after `composer require` or after a Holy Sheet upgrade.

## More patterns

See [docs/Recipes.md](../docs/Recipes.md) for 10 end-to-end patterns including Laravel query exports, queue jobs, and HTTP download controllers.
