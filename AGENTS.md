# AGENTS.md — holy-sheet (PHP)

This file describes **this repo's code**: its API, its invariants, and the traps
in it. Process rules (publishing, versioning, backports, support lifecycle) live
in the envelope's `RULES.md` and are deliberately not repeated here: a copy in a
repo is frozen on whichever branch you have checked out.

`CLAUDE.md` is a symlink to this file. Edit this one.

## What this is

A zero-dependency `.xlsx` writer, reader and formula linter for agent-written
spreadsheets, plus an exchange layer (CSV / JSON / Markdown in and out). It is
the **reference implementation** of a contract with two ports:

| | |
|---|---|
| `particle-academy/holy-sheet` (this) | the reference: semantics are decided here |
| `@particle-academy/holy-sheet` (`holy-sheet-js`) | Node/TypeScript port |
| `fancy-holy-sheet` (`holy-sheet-py`) | Python port |

**PHP is normative for behaviour.** When the ports disagree with this package,
they are wrong. That cuts both ways: a behavioural change here is a change the
ports must mirror, and their suites test against this repo's output (see "Byte
parity" below), so a change to the writer's bytes or the linter's labels and
hints breaks their CI until it is ported.

Requires PHP `^8.4` and `ext-zip`. No runtime Composer dependencies.

## The API

Two entry points over the same implementation:

- **`HolySheet\Agent`**, static: `validate`, `validateAndRepair`, `write`,
  `toBytes`, `read`, `describe`, `lint`, `fromArray`, `fromCsv`, `dumpJson`,
  `diff`, `reduce`, `opSchema`, `equivalent`, `toolDefinition`, `guide`,
  `convertTemplate`, and the exchange surface `catalog`, `extract`,
  `fromPortable`, `export`, `exportToolDefinition`.
- **`HolySheet\HolySheet`**, an instance (what the Laravel facade resolves),
  plus `fromQuery()` and `HolySheet::VERSION`.

**The input schema is a plain array and must stay one.** The declarative
one-shot schema is the product: an agent emits the whole workbook as one JSON
object, `Schema\Validator` is the gate, and `Schema\Repairer` exists to fix the
loose input a typed constructor would reject. `Schema\Normalizer` turns it into
the internal `Workbook` / `Sheet` / `Cell` / `CellFormat` objects; those are
internal.

### Layout

| Directory | What |
|---|---|
| `src/Schema/` | `Validator`, `Repairer`, `Normalizer`, `Inference`, `FormulaLinter`, `Dumper`, `Theme`, `ColumnWidths` |
| `src/Workbook/` | the internal model |
| `src/Writer/` | `XlsxWriter`, `StylesRegistry`, `ChartXml` |
| `src/Reader/` | `XlsxReader`, `OdsReader`, the part parsers, `FormatSniffer` |
| `src/Ops/` | `SheetDiff`, `SheetReducer`, `SheetOpSchema`: the op log behind `diff` / `reduce` |
| `src/Exchange/` | portable content, CSV / JSON / Markdown, export policy |
| `src/Archive/` | `OfficeTemplate` (xltx / xlsx conversion), `SourceArchive` |
| `src/Laravel/`, `src/Toolkit/` | PHP-only: service provider, facade, artisan command, query adapter; prompt / tool / schema-store layer |
| `skills/holy-sheet.schema.json` | the JSON Schema handed to an LLM as the tool definition |

`Laravel/`, `Toolkit/` and `Schema\Dumper` exist only here. Neither port has
them, and that is deliberate, not an omission to fill in from a port.

## Invariants

**`HolySheet::VERSION` must equal the newest released heading in
`CHANGELOG.md`.** `VersionIsSingleSourcedTest` fails otherwise. A release bumps
both in the same commit.

**`skills/holy-sheet.schema.json` is one of three byte-identical copies** (the
others are `holy-sheet-js/src/holy-sheet.schema.json` and the Python port's).
Each repo pins its SHA-256 over CRLF-normalised content in a test
(`SchemaSyncTest` here). Edit one copy, edit all three, update all three
constants. `SchemaDescribesTheShippedPackageTest` also fails on a description
that promises a feature in the future tense: the schema is the whole contract an
agent sees, so it describes what ships.

**The XML is built as strings, and the exact bytes are the contract.** Attribute
order, self-closing style and the absence of inter-element whitespace are what
the ports' parity suites compare part by part. A DOM serialiser would own those
decisions and break every fixture in both ports.

**Cells within a row are emitted in ascending column INDEX** (`A..Z, AA, AB,
...`), as OOXML requires of `CT_Row`. `Sheet::rows()` keys a row by the column
LETTER, so `XlsxWriter` sorts with `uksort` on `CellAddress::index`; a plain
`ksort` there is a string sort and writes `A, AA, AB, ..., B, C`. That was the
behaviour until 2026-10-28, and it lost data: a holy-sheet workbook whose row 1
was written `A1, AA1..AE1, B1..Z1`, imported into Google Sheets and exported
again, kept only `A1` in that row. Google Sheets drops the rest of a row
silently after the first out-of-order cell. (LibreOffice 26.2 kept every cell in
either order; Excel was not measured.) Both ports mirror the index order
byte-for-byte, pinned by `WideRowCellOrderTest` here and the ports' `wide`
parity fixtures.

**The container is not deterministic here, and does not need to be.** The
writer goes through `ZipArchive` (DEFLATE, real mtimes). Parity is defined on
the PARTS, never the zip.

**A `DOCTYPE` is refused before parsing** in the chart parser and in
`OfficeTemplate`, which read untrusted XML. An xlsx never legitimately carries
one, and an internal DTD subset is the entry point for entity expansion.

## The formula linter (`Schema\FormulaLinter`)

`Agent::lint()` evaluates every formula and reports cells whose value is an
Excel error, with a hint aimed at an agent fixing its own output. The JS and
Python linters are line-by-line ports of this class.

- **It evaluates while it parses.** There is no AST: each `parse*` method
  computes its value as it consumes tokens. The consequence that matters is lazy
  `IF`: `parseIf` parses every argument (so a syntax error or an unknown function
  anywhere is reported) but evaluates only the condition and the branch it
  selects. The untaken branch is parsed under `$skipping > 0`, in which **every
  evaluating step returns null**: each operator, `callFunction`, and
  `resolveRefOrRange` (which reads no cell and raises no `#REF!`). A new
  operator or value source must honour `$skipping`, or an untaken branch starts
  reporting errors again.
- **A computed error stops the parse where it arises.** `=1/0+(` is `#DIV/0!`,
  not a syntax error. Only an error the IF condition *evaluates to* is deferred
  until the whole `IF` has parsed.
- **Precedence, loosest first:** comparison, `&`, `+ -`, `* /`, `^`
  (left-associative), unary `- +`, postfix `%`. So `=-2^2` is 4 and `=2^3^2` is
  64, as in Excel.
- **The vocabulary is `FUNCTIONS`.** An unknown name is `#NAME?` the moment it
  is seen, before its arguments, whichever branch it sits in. Adding a function
  means adding it to `FUNCTIONS`, `callFunction` and the supported-list hint
  text, in all three repos.
- **`error` is a closed vocabulary consumers switch on**: `#VALUE!`, `#REF!`,
  `#NAME?`, `#DIV/0!`, `#CIRC!`. A syntax error deliberately keeps `#NAME?`;
  `hintName` explains why. The cause (syntax, unknown function, inherited,
  linter fault) is carried in the hint's prefix, via `LinterError::$cause` and
  `$nameCauses`.
- **Hint texts are byte-identical across the three linters**, pinned by literal
  tests in each suite. They contain em dashes (`Division by zero — ...`) and
  the doubled quote `"a""b"`; a hyphen or an edited sentence is a parity
  failure.
- **The tokenizer is byte-wise with ASCII `ctype_*` classes.** A no-break space,
  `é` or `²` outside a text literal is a syntax error. The ports narrowed their
  Unicode-aware character tests to match; keep this side ASCII.
- **Text comparison is `strcmp` on `mb_strtolower`**, i.e. code-point order.
  Excel's own collation is locale-dependent and is not modelled.
- **`ROUND` relies on PHP 8.4's `round()`** giving half-away-from-zero on the
  decimal value (`ROUND(1.005,2)` is 1.01). A guard test pins it. A
  "simpler" `floor($x * 100 + 0.5) / 100` breaks it.
- **The tests observe a value through a division**: `1/(<claim>)` lints clean
  when the claim is TRUE and `#DIV/0!` when it is FALSE (`lintClaim()` in
  `FormulaLinterTest`).

## Testing

`vendor/bin/pest` (Pest, PHP 8.4+).

`ConformanceTest` runs every `exchange/*` suite in fancy-conformance that names
this package, at the version pinned in `require-dev`; it asserts the pin and a
vacuity floor, so a loader that finds no rows fails rather than passing.
