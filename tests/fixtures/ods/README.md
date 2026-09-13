# ODS reader fixtures

Every file here was authored for this package. None came from a third party.

| File | What it is | How it is made |
|---|---|---|
| `workbook.xlsx` | A five-sheet workbook covering numbers, percentages, currency, dates, datetimes, booleans, multi-line and spaced strings, formulas, repeats, merges, a comment, a sheet name with a space, and an empty sheet | Written by holy-sheet's own writer from the schema in `generate.php` |
| `workbook.ods` | The same workbook as OpenDocument | `workbook.xlsx` converted by LibreOffice (`soffice --headless --convert-to ods`) |
| `native.fods` | A flat OpenDocument file written by hand: a time, a native currency cell, spans and a link in a paragraph, a tab, a line break, an authorless two-paragraph comment, sheet references, an inline array | Hand-written XML |
| `native.ods` | `native.fods` as a packaged `.ods` | Converted by LibreOffice, as above |
| `edge/` | `content.xml`, `styles.xml`, `meta.xml` for constructs LibreOffice rewrites on save or never writes | Hand-written XML |
| `edge.ods` | `edge/` as a package | Zipped by `generate.php`, without LibreOffice |

Regenerate all of them with:

```bash
php tests/fixtures/ods/generate.php ["C:/Program Files/LibreOffice/program/soffice.com"]
```

LibreOffice runs in a throwaway profile, so an open LibreOffice session is never
touched. The committed files were produced with LibreOffice 26.2.5.2. Its output
is not byte-stable across runs (timestamps), but it is stable in everything the
reader looks at.

`workbook.xlsx` and `workbook.ods` describe the same authored workbook, which is
what lets `OdsReaderTest` assert that the two formats describe to the same
schema. The Node (`holy-sheet-js`) and Python (`holy-sheet-py`) ports load these
files from this directory and diff their readers against this one.
