# Changelog

All notable changes to `particle-academy/holy-sheet` will be documented in this file.

## [Unreleased]

### Changed

- **The agent guide (`Agent::guide()`) catches up with 2.10 to 2.14.** It was
  last touched at 2.9.0. New: building a large or formula-heavy workbook in
  passes (inputs, then calculations and scenarios, then analysis such as a
  sensitivity matrix or break-even, checking after each); fixing only the cells
  a lint issue names (`lint` -> ops checked against `opSchema()` -> `reduce`),
  with a worked example; `diff`, `equivalent` and `validateAndRepair`; data
  validation; border styles; `{value: "007"}` keeping text as text; reading
  (`read` / `describe` with `sheet` and `limits`, `rows`, `catalog`, `dumpJson`,
  `convertTemplate`, refusals that name the limit); the exchange methods;
  `fromArray` / `fromCsv`. Byte-identical in holy-sheet-js and holy-sheet-py
  (new SHA-256 in all three). **What to do:** nothing; guide text only, a patch.
- Dev only: `AgentGuideCoversTheApiTest` fails when a public `Agent` method is
  not named in the guide and not on its allowlist, where each entry carries its
  reason (`guide`, the guide itself). It failed on the 2.9.0 guide, which named
  none of 18 methods. `phpunit.xml` now forces sqlite `:memory:` (env and
  server), so the Laravel tests never reach a database set in the shell.

## [2.14.0] - 2026-11-07

Owner decision, 2026-11-07: "all limitations need to be a config option so apps
can build to their use case." Every default stays where it was; what changes is
that an app can now set every limit a read enforces. The JavaScript and Python
packages ship the same names, defaults, refusals and messages; the cases all
three pass are in `tests/fixtures/read-options/cases.json`.

### Added

- **Every limit is configurable.** `read()` / `describe()`'s `limits` option
  now takes the archive guard's five limits beside the four read limits, so
  every refusal a read can make is one setting: `maxArchiveBytes`,
  `maxEntries`, `maxPartBytes`, `maxExpandedBytes`, `maxCompressionRatio`,
  `maxRows`, `maxColumns`, `maxCells`, `maxSharedStrings`. Until now the
  archive limits could not be reached through any public method, so a sheet
  part over 64 MiB (about 120,000 rows of 10 columns) could not be read at all.
  `ReadLimits::ARCHIVE_DEFAULTS` holds their defaults.
  **What to do:** nothing; every default is unchanged. To read a large export,
  raise the limits it needs; the README's "Large workbooks" section has the
  measured memory and time per row, and the settings for one.
- **Laravel: `holy-sheet.limits` and `holy-sheet.query_limit` config.** The
  package config now lists all nine limits at their defaults (no `env()`), and
  `fromQuery()`'s row cap (5,000). The facade and the container apply them to
  every `read()`, `describe()`, `rows()` (its `maxCells`) and `fromQuery()`; a
  call's own options override them key by key. A bad value refuses when
  `HolySheet` is first resolved, naming the setting. `HolySheet` gains
  `read()`, and its constructor takes the same limits for non-Laravel use.
  **What to do:** nothing. To change a limit app-wide, publish the config
  (`php artisan vendor:publish --tag=holy-sheet-config`) and edit it.
- **A refusal over a limit says which one.** `ArchiveRefused` now carries
  `limit`, `max` and `actual` (null for refusals that are not about a limit),
  and an `archive_limit` message names them: `Source archive refused:
  archive_limit (maxPartBytes is 67,108,864; this file needs at least
  275,497,997)`. Before, all five archive limits read as a bare
  `archive_limit`, so nobody could tell which one to raise.

### Changed

- **The read limits are now counted BEFORE the part is parsed, not after.**
  Measured on this release: libxml builds a worksheet's whole tree before any
  count could run, so with `maxPartBytes` raised, a 500,000-row upload grew to
  6.1 GB before `maxCells` could refuse it. PHP's `memory_limit` does not stop
  that either, because libxml's allocations are not counted against it: the
  same read under `memory_limit=512M` still reached 6.1 GB, and the 1,000,000-
  row one passed 12 GB under `memory_limit=1G`. Rows, columns and cells are now
  counted by streaming the part through `XMLReader` (no tree), and the cell
  count refuses the moment the workbook passes `maxCells`; shared strings are
  counted the same way. Measured peak at refusal, 500,000 rows x 10 columns
  (a 275,497,997-byte sheet part), `memory_limit=512M`:
  - at the **defaults**: refused by `maxPartBytes`, from the zip's central
    directory before anything is inflated, in 0.03 s at a **40 MiB** process
    peak;
  - with the part limits raised and `maxCells` at 1,000,000: refused by
    `maxCells` in 2.5 s at **817 MiB** (the inflated part is most of it), not
    6.1 GB.
  A worksheet or shared-strings part cut off mid-document is counted up to the
  cut, so a truncated part over a limit is now refused; before, it parsed as
  malformed and read as empty, unseen by any limit. A part libxml calls
  malformed is counted without libxml (`PartXml::scanTags`, a port of the
  JavaScript package's scanner), because what `XMLReader` delivers before the
  error depends on the libxml version: measured on a worksheet cut off after
  three cells, libxml 2.11.9 delivered all three and 2.9.14 (Ubuntu 24.04's)
  none. So a truncated part counts the same on every libxml and in every port.
  **What to do:** nothing. Reads that succeeded still succeed with the same
  result; the stream replaces the old walk of the parsed tree, and measured
  0 to 5% more time on a full read (5,000,000 cells: 54.0 s against 51.5 s).
- **A bad limit setting names itself.** `read_limits (maxRows must be a
  positive integer)`, `archive_limits (maxPartBytes must be a positive
  integer)`, `read_limits (unknown limit "maxBogus")`. The reasons are the
  same as before. **What to do:** nothing, unless you compare `getMessage()`
  exactly; compare `reason` instead.
- **`describe($path)` opens the file once.** It opened and inflated the whole
  archive to sniff its format, then opened it again to read it.
- `ReadLimitExceeded`'s `limit`, `max` and `actual` are now declared on
  `ArchiveRefused`, typed nullable there. **What to do:** nothing; they are
  set on every `ReadLimitExceeded` as before.

### Removed

- `ReadLimits::worksheet(SimpleXMLElement)`, replaced by `worksheetPart(string)`.
  The class is `@internal`. **What to do:** nothing, unless you called it.

## [2.13.0] - 2026-11-07

Approved in the change council as CC-fancy-0012 and CC-fancy-0013 (owner, plus
MOIC and Tynn, 2 of 2 each). The JavaScript and Python packages ship the same
options, defaults, refusals and messages; the cases all three pass are in
`tests/fixtures/read-options/cases.json`.

### Added

- **Read one sheet by name: `Agent::read($bytes, ['sheet' => 'Contacts'])`**
  (and `describe($path, [...])`). Only that sheet is parsed; the others cost
  nothing and count toward no limit. Names match exactly, as written in the
  file. An unknown name throws `SheetNotFoundException` (an
  `InvalidArgumentException`) carrying `sheet` and `available`, with a message
  listing every sheet: `[holy-sheet] no sheet named "Nope"; this workbook has:
  "Data", "Summary"`. Works for `.ods` too.
  **What to do:** nothing; without `sheet` a read returns every sheet as before.
  If you read a whole workbook to use one sheet, pass `sheet` instead.
- **`Agent::rows($sheet, $options)`: a read sheet as rows of values in column
  order.** Row 1 and column A are index 0. A blank row in the middle is `[]`, so
  row indexes are preserved; trailing blank rows are not padded, and a row stops
  at its last value. A formula gives its cached `computedValue` by default, which
  is what the cell displays (null when the file cached none);
  `'formulas' => 'formula'` gives the formula with its leading `=`. A merged
  region's value appears at its top-left cell only. The view is sized before it
  is built and refuses (`ReadLimitExceeded`, `maxCells`) past `maxCells`
  positions, because a few cells far to the right of a million rows would
  otherwise pad to billions.
  **What to do:** nothing; it is new. Use it instead of walking `cells` yourself.
- **Border styles beyond thin.** Each border side takes a bare colour, which is
  a thin line exactly as before, or `{"style": ..., "color": ...}` with `style`
  one of `thin`, `medium`, `thick`, `dashed`, `dotted`, `double` (thin when
  omitted) and `color` `#RRGGBB` (black when omitted). `validate()` reports a bad
  style, colour or key at its path. `read()` gives a thin side back as the bare
  colour and another style as the object, so a schema with styled borders
  round-trips (`diff` of it and its read-back is `[]`). An Excel style outside
  the six (`hair`, `mediumDashDot`, ...) reads as thin, as it always did. The
  shared JSON Schema gains a `Border` definition (new SHA-256 in all three
  repos).
  **What to do:** nothing. A bare colour writes byte for byte as before. To use
  a style, give the side an object.
- **Read limits on rows, columns, cells and shared strings.** Until now only the
  archive guard's byte limits bounded a read, and bytes are not what a read
  spends: a 64 MiB worksheet part can hold millions of `<c>` elements, and one
  `.ods` cell can repeat across a whole sheet. Pass `['limits' => [...]]` to
  `read()` / `describe()`; each value must be a positive integer, and an unknown
  key or any other value is refused as `ArchiveRefused` with reason
  `read_limits`, as the archive limits are. Each limit is checked before the
  thing it counts is built: a worksheet is counted from its parsed part before
  one cell object exists, the shared-strings table before one string is
  rendered, an `.ods` repeat before it is expanded.
  A refusal is the new **`ReadLimitExceeded`**, which extends `ArchiveRefused`
  (reason `read_limit`), so a catch for the archive guard still catches it. It
  carries `limit` (which one), `max` and `actual`, and a message for people:
  `Spreadsheet refused: a sheet has 1,200,000 rows, over the limit of 1,048,576
  rows (maxRows) by 151,424.` It names no sheet and quotes no content.
  `maxCells` counts sheet by sheet, so its message says "at least".
  The defaults, and why:
  - `maxRows` **1,048,576** and `maxColumns` **16,384**: Excel's own sheet
    limits, which LibreOffice shares and which this package already treats as
    the edge of a sheet (the ODS reader clamps there; `columnWidths` and
    `hiddenRows` validate against them). They are set AT those limits, not
    above: every sheet any spreadsheet application can save is admitted, and a
    row or column past them is not one Excel can open. Counted per sheet,
    including a `<row>` past the last cell and a `<col>` past the last column
    (`<col max="2000000000">` used to drive a loop that long).
  - `maxCells` **20,000,000**, the whole workbook. Measured, not guessed: a
    workbook of 1,000,000 rows x 10 columns written by this package holds
    10,000,010 cells (with its header row) and one of 300,000 rows x 50 columns
    holds 15,000,050; the defaults admit both (run through `ReadLimits` itself).
    20,000,000 is the larger of the two with a third to spare. As a memory
    bound: a PHP read measured 1,260 bytes per cell at peak (1,100,010 cells,
    1,322 MiB), so the default allows a read of about 25 GB, and PHP's
    `memory_limit` will usually stop a read well before it.
  - `maxSharedStrings` **20,000,000**, the same as `maxCells`: a table with more
    distinct strings than the workbook may hold cells has entries no cell can
    reference.
  **Measured limits you will meet first.** Both measured workbooks are refused
  today, with or without this release, by the archive guard's existing byte
  limits: the 1,000,000 x 10 sheet part is 553,315,173 bytes and the 300,000 x
  50 part is 865,123,885, against `maxPartBytes` of 64 MiB (and
  `maxExpandedBytes` of 256 MiB). With 10 mixed columns that part limit is
  reached at about 120,000 rows. Raising it is a separate decision; this release
  does not change it.
  **What to do:** nothing for an ordinary workbook; no default refuses a sheet
  Excel can hold. If you show refusals to people, catch `ReadLimitExceeded`
  (or keep catching `ArchiveRefused`) and show its message. Lower a limit to
  bound a read more tightly than the defaults.

### Security

- **One `<col>` element could exhaust a read's memory.** The column-width
  reader expanded `<col min="1" max="...">` one column at a time, so a 1.5 KB
  file with `max="2000000000"` ran a PHP read out of memory (measured: `Allowed
  memory size of 536870912 bytes exhausted` against 2.12.0). The default
  `maxColumns` now refuses it before the loop. An `.ods` cell repeated across
  rows and columns is bounded the same way by `maxCells`.
  **What to do:** upgrade if you read uploaded files.

## [2.12.0] - 2026-11-07

### Added

- **Data validation: dropdowns and bounds.** A sheet's `dataValidations` list
  (`{range, type, ...}`) and a column's `validation` (covering that column's
  data rows) write Excel data validation: `type: "list"` is a dropdown whose
  choices come inline from `values` or from cells through `source` (on this
  sheet or another, written as an absolute reference); `whole`, `decimal`,
  `date` and `textLength` bound the entry with `operator` (`between`,
  `notBetween`, `equal`, `notEqual`, `greaterThan`, `lessThan`,
  `greaterThanOrEqual`, `lessThanOrEqual`) and `min` / `max` or `value`.
  `allowBlank`, `showDropdown`, an input `prompt` and an `error` alert
  (`stop`, `warning` or `information`) are optional. `validate()` reports a bad
  rule at its path, including an inline list over Excel's 255-character limit
  and a `source` naming a sheet the workbook does not have;
  `validateAndRepair()` fixes only the case of a type, operator or error style.
  The reader does not return validations yet.
  **What to do:** nothing unless you want dropdowns. Workbooks without
  validations are written byte for byte as before. To add one, see the README's
  "Data validation" section; a template's dropdown usually belongs in
  `dataValidations` with a range past the sample rows, like `"B2:B500"`.

## [2.11.5] - 2026-11-07

### Security

- **A `DOCTYPE` in any XML part now refuses the file, instead of reading as an
  empty part.** 2.11.4 stopped such a part from being expanded but read it as
  absent, so a worksheet carrying one came back as an EMPTY sheet, which a
  caller cannot tell from a sheet that holds nothing. Reading, describing or
  converting a file with a `DOCTYPE` in any part now throws
  `UnsupportedFormatException` (a `RuntimeException`), as one in
  `[Content_Types].xml` already did; `catalog` and `extract` report
  `not_a_workbook`. A part that is missing is still just absent, and a part that
  is merely malformed is handled exactly as before.
  **What to do:** nothing for real spreadsheets, which carry no `DOCTYPE`. If you
  read files you did not write and catch `RuntimeException` around a read, you
  already handle this; if you relied on such a file reading with empty sheets,
  it is now refused.

### Fixed

- **Workbooks in the 1904 date system read every date four years early.** The
  reader ignored `<workbookPr date1904="1"/>` and counted every serial from
  1899-12-30, so a date in such a file (older Mac Excel saves) came back 1462
  days too early. It now uses the 1904-01-01 epoch for that workbook.
  **What to do:** nothing. Dates in 1904-system files now read correctly; files
  in the default 1900 system read exactly as before. Writing one back produces
  the same dates in the 1900 system.
- **A rich inline string read as null.** A cell holding `<is><r><t>..</t></r></is>`
  (runs, as some producers write formatted text) lost its text. Its runs are now
  joined, exactly as a rich shared string's already were.
  **What to do:** nothing. Those cells now have their text.
- **BEHAVIOUR CHANGE: the cached value of a date-formatted formula is now an ISO
  string.** `computedValue` came back as the raw serial (`46334`) while the
  cell's own `value` was converted, so a date formula read as a number nobody
  wrote. It is now converted like `value`: `"2026-11-08"` for a date format,
  `"2026-11-08T06:00:00Z"` for a datetime one, in both xlsx and ods. Writing
  that output back turns it into the serial again, so describe() round-trips.
  Because the exchange layer reads the same output, `extract` now reports such a
  cell as a date rather than a number.
  **What to do:** if your code reads `computedValue` of a date-formatted formula
  as a number, read it as an ISO date string instead (parse it, or check
  `is_numeric()` first if you must handle both). Formulas that are not
  date-formatted are unchanged.
- **A column's `format` code was ignored.** The schema has documented
  `columns[].format`, a custom Excel number-format string that overrides the
  type-derived format, but the normalizer never read it. It is now written as a
  custom number format and applied to every cell in the column, including the
  totals row; the column `type` still decides the value (a `date` column's
  strings still become dates). A cell that sets its own `displayFormat` still
  wins. `validate` now reports a `format` that cannot be written (not a string,
  empty, or longer than 255 bytes) at `sheets[i].columns[j].format`.
  **What to do:** nothing, unless you set `format` on a column and relied on it
  having no effect. Remove it to keep the type-derived format.

## [2.11.4] - 2026-11-04

### Security

- **The reader now refuses a `DOCTYPE` in every XML part of an xlsx or ods.**
  Most parts were parsed with no such check, so a crafted file could define its
  own entities and have them expanded into the values, names and text the reader
  returned. The two places that already refused one, the chart parser and
  template classification, now use the same check, because the chart parser's
  own check did not hold in every case. No file contents were reachable this way
  and no external file was loaded; the effect was on what the reader returned
  and on a limited amount of extra work.
  **What to do:** upgrade if you read files you did not write. No code changes.
  Real spreadsheets carry no `DOCTYPE`, so they read exactly as before. A part
  with one is treated as if it were malformed: a refused `xl/workbook.xml` or ods
  `content.xml` still throws `RuntimeException`, and any other part is read as
  absent, the handling each has always had for a part it cannot parse. So a
  refused worksheet gives an empty sheet with its name, and refused shared
  strings give blank string cells. Text that only looks like a `DOCTYPE`, such as
  a chart title in CDATA holding an HTML page, is no longer refused.
- **A package whose `[Content_Types].xml` carries a `DOCTYPE` is now refused.**
  It was treated as declaring nothing, so the checks that depend on what a
  package declares did not run on it. Reading or converting one now throws
  `UnsupportedFormatException`, a `RuntimeException`. `catalog` and `extract`
  report `not_a_workbook`, and `export` reports `export_refused` with that cause.
  **What to do:** nothing, unless you catch `OfficeTemplateRefused` alone around
  a read. Real spreadsheets carry no `DOCTYPE` there, so they read as before.

## [2.11.3] - 2026-10-28

### Fixed

- **Data loss in Google Sheets for sheets wider than 26 columns.** Cells within
  a row were written in string order of the column letter (`A, AA, AB, ..., B,
  C`) instead of the ascending column order OOXML requires. Google Sheets drops
  every cell in such a row after the first out-of-order one, silently: a row
  written `A1, AA1..AE1, B1..Z1` came back from a Sheets import/export holding
  only `A1`. Cells are now written `A..Z, AA, AB, ...`. LibreOffice 26.2 read
  both orders without loss; Excel was not measured. Sheets of 26 columns or
  fewer were never affected, and their bytes do not change.
  **What to do:** regenerate any file wider than 26 columns that was written by
  an earlier version and may be opened in Google Sheets. Nothing else changes:
  no API, schema or reader behaviour moved.

## [2.11.2] - 2026-10-28

### Fixed

- **`IF` evaluates only the branch it takes, as Excel does.** What you will
  notice: **fewer false `#DIV/0!` reports.** The guard every spreadsheet writes,
  `=IF(A2=0,0,1/A2)` with A2 = 0, used to lint as `#DIV/0!` because both
  branches were evaluated; it now lints clean. The same holds for any error in
  the untaken branch: `#VALUE!` from text in arithmetic, `#REF!` from a missing
  sheet, or an error held by a cell the branch reads. Nothing to do.
  - The untaken branch is still **parsed**, so a syntax error or an unknown
    function there is still `#NAME?` (the 2.11.1 rule that Excel resolves names
    before it evaluates is unchanged).
  - An error in the condition still propagates, but now only after the whole
    `IF` has been parsed, so a syntax error later in it is the one reported.
  - `IF` with no else branch still gives FALSE when the condition is FALSE, and
    fewer than two arguments is still `#VALUE!`.
  - `IFERROR` is not in the linter's vocabulary: it is `#NAME?` ("Unknown
    function IFERROR"), as before.

## [2.11.1] - 2026-10-28

### Fixed

`Agent::lint()` now follows Excel's formula semantics in the places below.
What you will notice: **fewer false `#NAME?` reports** on formulas Excel
accepts, and **some new true positives** on formulas Excel rejects or errors on
that the linter used to pass. `lint()`'s signature and the issue shape
`{sheet, address, formula, error, hint}` are unchanged. Nothing to do unless
you assert on the exact set of issues a formula produces.

- **`%` is postfix percent.** `=50%` is 0.5 and `=A1%` is A1/100; these used to
  lint as `#NAME?`. A binary `%` such as `=10%2`, which the linter used to
  evaluate as `10/100*2`, is now reported, because Excel rejects it.
- **`^` binds tighter than `*` and `/`.** `=2*3^2` is 18, not 36. It stays
  left-associative (`=2^3^2` is 64) and below negation (`=-2^2` is 4), as in
  Excel.
- **A doubled quote inside text is one quote.** `="a""b"` is the three
  characters `a"b`; it used to lint as `#NAME?`. A text literal that never
  closes (`="abc`) is now reported instead of passing.
- **IF reads its condition as Excel does.** Text is TRUE or FALSE only when it
  spells one of them, in any case: `"false"` used to count as TRUE. Any other
  text, `""` included, is now `#VALUE!` (a new true positive; it used to pass).
  An error in the condition now propagates instead of picking a branch.
- **LEN counts characters, not bytes.** `=LEN("é")` is 1, not 2. As in Excel,
  it counts UTF-16 code units, so an emoji outside the Basic Multilingual Plane
  is 2.
- **Comparisons follow Excel's type order.** Numeric text is text, and Excel
  sorts text above every number: `="5">9` is TRUE and `="10"=10` is FALSE (both
  used to compare as numbers). Text compares without regard to case
  (`="a"="A"` is TRUE), logicals sort above text, and a blank cell equals 0,
  `""` and FALSE. An error on either side now propagates instead of being
  compared as text.
- **A `#NAME?` hint says which kind it is.** The label is still `#NAME?`, but
  every cause used to get one hint, "references an unknown function or has a
  syntax error". A malformed formula now gets `Syntax error: <what and
  where>...` and never mentions an unknown function; an unknown function gets
  `Unknown function VLOOKUP. ...`; a `#NAME?` inherited from another cell names
  that cell; and an exception inside the linter itself says it is a linter
  defect. The label stays `#NAME?` because Excel has no cell value for a formula
  it cannot parse, and the planned fancy-formula delegation maps syntax errors
  to `#NAME?` too. Match on the hint's prefix if you need to tell them apart.
- **An unknown function is `#NAME?` wherever it sits.** `=IF(FALSE, FOO(1), 1)`
  used to pass, because the error was a value that IF discarded. Excel resolves
  names first, so it is `#NAME?` there and now here (a new true positive).

## [2.11.0] - 2026-10-27

### Added

- **Document exchange (portable content, `fancyContent: 1`).** Implements
  fancy-conformance's `suites/exchange/SPEC.md`; every applicable suite runs in
  `tests/Unit/ConformanceTest.php` (301 of 301 rows, nine suites, at 0.37.0).
  - `Agent::read($bytesOrPath)`: the sibling-named read, taking bytes or a
    path. `describe($path)` is unchanged.
  - `Agent::catalog($input)`: sheets (state, used range, counts), defined
    names, Excel tables and charts, each with the selector that grabs it.
  - `Agent::extract($input, $selector, $options)`: a range, sheet, defined
    name or table as portable content. Formulas come back as cached values,
    never evaluated; hidden sheets, rows and columns are skipped and reported
    unless the HOST passes `includeHidden`; invisible characters are stripped
    and counted; reports carry locations, never text; output over a limit is
    refused, never cut. Display strings follow the writer's formats, rounded
    half away from zero on the decimal digits (a percent shifts the decimal
    point; it is never a float multiply).
  - `Agent::fromPortable($content, $at)` and a sheet `insert` list
    (`{"type": "portable", "at": "B4", "content": ...}`), expanded by
    `validateAndRepair` and the write path. Every string is written as
    `{value: s}`, so a leading `=` stays text; a formula is never written.
    `validateAndRepair` returns each expansion's report under `inserts`.
  - `Agent::export($input, $target, $options, $policy)` for `csv`, `markdown`
    and `xlsx` (from ods, or an xlsx template), plus `ExportPolicy` and
    `Agent::exportToolDefinition($policy)`. **Off by default**: a null policy
    allows nothing, and an `allow` in the agent's options throws. CSV
    formula-injection escaping is on unless the host turns it off. `pdf` and
    `docx` refuse `export_unavailable` in this release.
- **The reader reports what exchange needs**, each only when present: sheet
  `state` (`hidden` / `veryHidden`), `hiddenRows` (1-based), `hiddenColumns`
  (0-based), `tables`, workbook `definedNames`, and `format.formatCode` for a
  number-format code no `displayFormat` describes. The ods reader reads hidden
  sheets, rows and columns too. Hidden rows and columns are listed only as far
  as the sheet holds anything.
- **The writer writes `state`, `hiddenRows`, `hiddenColumns` and
  `format.formatCode`**, so they survive describe -> write. A hidden first
  sheet gets a visible active tab.
- Dev: `particle-academy/fancy-conformance` 0.37.0 as an exact-pinned
  `require-dev` (first-party); the pin is asserted by the conformance test.

### Changed

- `validate()` now refuses a workbook in which every sheet is hidden (Excel
  will not open one), a `state` other than visible / hidden / veryHidden, an
  out-of-range `hiddenRows` / `hiddenColumns` entry, an empty or over-255-byte
  `formatCode`, and invalid `insert` content. **What a consumer must do:**
  nothing, unless you already pass these keys; none was read before.
- `Sheet`, `Workbook` and `CellFormat` gain trailing optional constructor
  arguments. Positional calls are unaffected.
- **A describe -> write round-trip now keeps hidden sheets, rows and columns
  hidden.** It used to un-hide them, because neither side knew about them.

### Fixed

- A sheet's comments, charts and tables are found through the rels part
  beside the sheet's own file. They were looked up by tab position
  (`sheet{N}.xml.rels`), which misses them whenever a producer's file names
  do not follow tab order.
- `docs/ReadPath.md` said charts are "not parsed" (the reader has parsed them
  since charts shipped), that shared strings come back as `[shared:N]`
  placeholders (they are expanded), and that foreign number formats come back
  as raw `format` strings (they were dropped; they are now `formatCode`).

- **An explicit `{value: <string>}` cell is now written as a string, exactly
  as given.** Numeric-string coercion used to run on the object form too, so
  `{"value": "+1"}` was written as the number 1, `{"value": "007"}` as 7 and
  `{"value": "1e5"}` as 100000: a phone prefix, zip code or part number lost
  its characters with no error. This applies in `rows` and in the sparse
  `cells` map, under any column type or number format, and to a `set_cell`
  op's `value`. Unchanged: a BARE string in a row or a `cells` entry is still
  coerced (`"+1"` is still 1) and a bare `"=..."` is still a formula; an
  object value that is already a number is still a number; and a string under
  a `date` / `datetime` display format is still converted to a date serial.
  `describe()` reads such a cell back as the same string, so
  `diff(s, read(toBytes(s)))` is now empty for it. Identical in holy-sheet-js
  and holy-sheet-py.
  **What a consumer must do:** if you relied on `{"value": "100"}` becoming a
  number, pass the number (`{"value": 100}`) or the bare string (`"100"`).
  Otherwise nothing.

## [2.10.0] - 2026-10-27

### Added

- **Excel `.xltx` templates read, and `.ots` templates marked.**
  `Agent::describe()` reads an `.xltx` exactly as its `.xlsx` twin and adds
  `"sourceKind": "template"`; an `.ots` (already read since 2.2, unmarked) now
  says so too. `Workbook` gains a trailing optional `sourceKind` argument
  (default `'document'`). Document descriptions are unchanged and carry no
  `sourceKind` key, so nothing to do unless you want to tell the two apart. A
  template description still validates and writes (as an `.xlsx`).
- **`Agent::convertTemplate($bytesOrPath, $to = null)`** (also on the
  `HolySheet` instance), `vendor/bin/holy-sheet template:convert <in> <out>
  [--to=document|template] [--force]` and `php artisan
  holy-sheet:template-convert` turn a template into its workbook twin and back.
  Only the declaration is rewritten: the workbook part's content-type value
  (OOXML), or the `mimetype` entry plus the manifest root's `media-type` (ODF
  1.3 Part 2 section 3.3 requires them to agree). Every other zip member is
  copied as its original record, byte for byte; `mimetype` stays first and
  stored.
- `HolySheet\Exceptions\OfficeTemplateRefused` with a constant `reason`.

### Changed

- **A macro-enabled template (`.xltm`) is now refused** with
  `OfficeTemplateRefused('macro_enabled')`; it used to be described as a
  workbook with its VBA silently ignored. So is an `.xltx` carrying a
  `vbaProject.bin`. An `.xlsm` still reads as before but is never converted.
  Re-save an `.xltm` as `.xltx` if you need it read.
- A package that declares a template of another family (a Word or PowerPoint
  template type on the workbook part, a `spreadsheet-template` whose body is
  text) is refused with `family_mismatch` instead of being read as whatever its
  parts looked like.

## [2.9.2] — 2026-10-26

### Security

- Validate XLSX/ODS ZIP records, member names, duplicates, CRC and declared and
  actual sizes before parsing. Bound archive/file input, individual and total
  expansion and compression ratio; unsafe or unsupported packages throw typed
  `HolySheet\Exceptions\ArchiveRefused` without echoing document content.
  Validated input retains original bytes and opaque members. Default ceilings:
  64 MiB archive/part, 256 MiB expanded, 16,384 entries and 1000:1 expansion ratio.

## [2.9.1] — 2026-10-19

### Fixed

- **A bar or column chart's value axis starts at zero** when every plotted value
  is zero or more. Left to the renderer, an axis over values like 90-97 started
  near 88, so a three-point gap read as a collapse; reviewers gated an
  agent-built deck for exactly that. A chart with a negative value, and a line
  chart, keep the renderer's own scaling. **What to do: nothing**; bar charts
  you already write now carry `<c:min val="0"/>` on the value axis.

## [2.9.0] — 2026-10-19

### Added

- **An agent guide ships with the package: `agent-guide/professional-workbooks.md`, and
  `HolySheet\Agent::guide()` returns it.** What makes a workbook professional and the schema
  idioms that produce it, distilled from rubric-judged agent builds in the
  Fancy document lab: every rule in it moved a measured score. Give it to a
  model together with the JSON schema; it works with any agent loop. The same
  bytes ship in the PHP, Node and Python packages (a test in each pins the
  hash). **Nothing to do**; nothing else changed.

## [2.8.0] — 2026-10-19

### Added

- **`print.fitToPage`: one page wide AND tall.** `fitToWidth` scales a sheet
  to one page wide and lets it run as many pages tall as it needs, so the page
  break fell through the chart below a board summary in three of four
  agent-built workbooks reviewed. `fitToPage: true` scales the whole sheet,
  table, notes and chart, onto one sheet of paper.
- **`charts[].series[].points`: colour single points.** `[{"index": 2,
  "color": "#C2410C"}]` colours the one bar, slice or line marker the chart is
  about (index 0-based within `values`). Reviewers marked down every comparison
  chart whose bars were all one colour, because it did not say which bar
  mattered. Written as DrawingML data points, read back by `describe()` /
  `read()`, validated with a hint per point, and published in the schema.
- **Nothing to do**: a workbook that uses neither is byte-identical to before.

## [2.7.0] — 2026-10-19

### Added

- **`charts`: native Excel charts over cell ranges.** A sheet takes
  `charts: [{type, title?, categories?, series: [{name?, values, color?}],
  position?: {from, to}, dataLabels?, legend?, format?}]`. `type` is `column`,
  `bar` (horizontal, for long labels), `line` or `pie`. Ranges are A1 ranges on
  the chart's own sheet (`"B5:B7"`) or another (`"'Q3 Revenue'!B5:B7"`).
  `position` anchors the chart between two cells and defaults to beside the
  data. `format` is an Excel number format for the axis and labels (`"$#,##0"`).
  Agent-built workbooks reviewed for a board were marked down for having no
  chart where the point was comparison, and there was no way to make one.
- These are real charts, not pictures: edit a figure and the chart follows. The
  values are cached too, so a viewer that does not recalculate still draws them.
  Styling is quiet on purpose (grey 9pt axis text, light gridlines, no tick
  marks, a bold 12pt title) so a chart reads as part of the sheet.
- Read back by `describe()` / `read()` in the same shape, validated with a hint
  per field, and published in the schema. Byte-identical across the PHP, Node
  and Python engines.
- **Nothing to do**: a workbook without `charts` is byte-identical to before. A
  sheet with comments keeps their relationship ids; the drawing takes the next.

## [2.6.0] — 2026-10-19

### Added

- **`print`: how a sheet prints.** `orientation` (`portrait` | `landscape`),
  `fitToWidth: true` (scale to one page wide, as many tall as needed) and
  `footer: {left, center, right}`, where `{page}` and `{pages}` print the page
  number and count, as Excel's own `&P` / `&N` codes; a literal `&` is escaped.
  Agent-built workbooks reviewed for a board printed across two pages with no
  way to ask otherwise.
- **`rowHeights`**: pixels keyed by 1-based row number, the unit `columnWidths`
  uses: `{"1": 32}`. A height on a row with no cells writes that row.
- Both are read back by `describe()` / `read()`, validated with hints, and
  published in the schema. **Nothing to do**: a workbook without them is
  byte-identical. A sheet carrying either is diffed as authored form (replaced
  whole), as any sheet key the granular ops do not address.
- Lands with `@particle-academy/holy-sheet` 2.7.0 and `fancy-holy-sheet` 0.6.0;
  parity holds (new `printSetup` fixture in both ports).

## [2.5.0] — 2026-10-11

### Fixed

- **A non-scalar cell value is REFUSED and names the cell, instead of being
  silently written as the literal string `"Array"`.** Reported from production
  by the MOIC suite ([#9]), where an operator's spreadsheet edit failed with
  `Array to string conversion` and no indication of which cell was at fault.

  `Normalizer::coerceValue()` ended with "not a scalar, so it must be safe to
  stringify":

  ```php
  if (!is_string($v) && !is_int($v) && !is_float($v) && !is_bool($v)) {
      return (string) $v;   // an ARRAY reaches this line
  }
  ```

  An array gets there whenever a plain `rows` form is nested one level too
  deep — `rows: [[["x"]]]` instead of `rows: [["x"]]`. No object-cell syntax is
  needed, which is exactly the shape an AI-generated edit produces.

  **The quiet outcome was the dangerous one.** Under a handler that promotes
  warnings (Laravel) the cast raised `ErrorException` and killed the whole
  `normalize()`. With warnings suppressed it wrote the cell as the string
  `"Array"` and **reported success** — a corrupt workbook that nothing flagged.
  Both were reproduced at the same line before the fix.

  The guard now tests `instanceof Stringable` rather than "is not a scalar",
  which also fixes the sibling case on that same line: a non-`Stringable`
  **object** raised `Error`. A `Stringable` object is still accepted, because it
  always was legitimately castable.

  The refusal is a `SchemaException` whose structured error names the cell as
  `Sheet!A1`, with a hint that explains the nesting mistake. Cell addresses use
  the `Sheet!A1` form rather than the `sheets[0].rows[1]` schema path the rest of
  the validator uses — deliberate, and the one place the two differ: this fault
  is always one cell, and the A1 location is what an operator can act on.

- **`validate()` no longer passes a schema that `normalize()` will throw on.**
  The same input returned **zero** validation errors, so `Agent::validate()` told
  a caller the workbook was fine and the write then failed — moving the failure
  to the point where it is hardest to attribute, in the exact flow (validate,
  then write) that an agent uses. `Validator` now reports
  `sheets[0].rows[0][0]` for a list-array cell.

  The check is `array_is_list`, **not** `is_array`: the object cell form
  `{"value": …, "format": {…}}` is an associative array and has always been
  legal. Rejecting any array would have traded this bug for a far worse one.

  **What you must do:** nothing, if your cells were already scalars. If any
  schema you build can nest a row one level too deep, you now get a
  `SchemaException` naming the cell where you previously got a workbook with
  `"Array"` in it — read `getErrors()[0]['path']` for the address. This is
  released as a MINOR rather than a patch because that throw is observable to a
  caller whose input the validator previously accepted.

[#9]: https://github.com/Particle-Academy/holy-sheet/issues/9

## [2.4.0] — 2026-10-01

### Changed

- **The tag workflow is now `.github/workflows/publish.yml`, named `Publish`**
  (it was `release-gate.yml`, "Release gate"). Every Particle-Academy package
  publishes from that file under that name. What it does is unchanged: on a
  `v*` tag it checks that `CHANGELOG.md` has an entry for the version and that
  the entry is the newest one. Packagist syncs from the tag and never reads the
  workflow file, so nothing about how a release reaches Composer changes.

  **What you must do:** nothing. Only a script that looks runs up by the old
  file (`gh run list --workflow=release-gate.yml`) needs `publish.yml` instead.

### Fixed

- **A column's own `width` is honoured. It was documented and silently dropped.**
  `skills/holy-sheet.schema.json` has declared `Column.width` as *"Column width
  in pixels. Same as columnWidths but per-column."* for as long as the field has
  existed, and `skills/holy-sheet.md` lists it in the Column table — but the
  writer read only `type`, `decimals` and `currency` off a column. A schema
  written the documented way emitted **no `<cols>` element at all**:

  ```php
  ['header' => 'Account', 'width' => 220]   // wrote a default-width column
  ```

  Nothing reported it. `validate()` returned no errors, `validateAndRepair()`
  listed no repairs, and the file opened fine — just with a truncated account
  name and a correctly formatted currency showing as `#####`, which is most of
  what "the spreadsheet looks unstyled" means in practice. Reported by a
  consumer who had composed against the skill doc, which is exactly who the doc
  is for (holy-sheet#8).

  Both ways of stating a width now feed one map, and **the sheet-level
  `columnWidths` map is applied last and wins**. That is deliberate: it is the
  mechanism that already worked, so anyone who reached for it to work around this
  bug must not now find a leftover `width` quietly overriding it.

  A `width` that is not a width is no longer ignored either — it is the same rule
  as a `columnWidths` value (`HolySheet\Schema\ColumnWidths`): `validate()`
  reports it by path (`sheets[0].columns[0].width`), and `validateAndRepair()`
  drops it and says so.

  `<col>` elements are also emitted in ascending column order now that two
  sources merge into one map.

  **What you must do:** nothing, and do not migrate off `columnWidths` — it keeps
  working and still takes precedence. One thing to know before upgrading: if a
  schema has carried a `width` that this version can honour, the output bytes
  change — those columns get the width they always asked for. And if a schema
  carried a `width` that is not a number of pixels (`"wide"`, `-5`), `write()`
  now refuses it where it used to ignore it; run it through
  `validateAndRepair()`, or give it a number.

## [2.3.4] — 2026-09-14

### Fixed

- **A `columnWidths` key that is not a column index overwrote column A.** The
  writer cast the key with `(int)`, so `{"0": 120, "abc": 999}` wrote column A at
  999 pixels. The Node and Python writers disagreed with this and with each other:
  Python's `to_bytes()` raised `ValueError`, and Node wrote a `NaN` column. One
  rule now applies in all three (`HolySheet\Schema\ColumnWidths`):
  - a **key** is a 0-based column index from 0 to 16383 (Excel's last column), as
    an int or a string of digits;
  - a **width** is a non-negative number, or a string of digits (`"80.5"`).
  - `validate()` reports each other entry by path (`sheets[0].columnWidths.abc`),
    so `write()` and `toBytes()` refuse it instead of writing the wrong width.
  - `validateAndRepair()` turns a one- or two-letter key into its index (`"B"` is
    1) and drops an entry it cannot repair, and lists both.
  - The normalizer skips such an entry, so a schema that skips validation can no
    longer clobber a column either.

  **What you must do:** nothing, unless a schema carried a width keyed by a letter
  or by junk. `write()` now refuses it (it used to write column A wrong); run it
  through `validateAndRepair()`, or key widths by index.

## [2.3.3] — 2026-09-14

### Fixed

- **An op with a position or count that is not a number moved or unfroze
  things.** The reducer cast `add_sheet.index`, `move_sheet.toIndex`,
  `set_frozen.rows`/`cols` and the row and column ops' `at`/`count` with `(int)`,
  so `toIndex: "last"` moved a sheet to the FRONT, `index: "end"` inserted one
  there, and `rows: "one"` unfroze the panes. A present value that is not an int
  or a string of digits now skips the op; absent keys keep their defaults
  (`add_sheet` appends, `move_sheet` stays, `set_frozen` uses 0). The same class
  of defect was found in last-word's reducer (last-word 0.6.2).

  **What you must do:** nothing. `diff()` only emits integer positions.

## [2.3.2] — 2026-09-14

### Fixed

Five defects in the 2.3 op code, found by porting it to Python (fancy-holy-sheet
0.3.0), each with a test that fails against 2.3.1:

- **`opSchema()` still rejected most `set_column_widths` ops a diff emits.** PHP
  encodes widths keyed 0..n-1 as a JSON list (`[120, 80, 140]`), and 2.3.1
  allowed only an EMPTY list. A list is now accepted, indexed by position.
- **An op with a non-string `type` could remove a sheet.** The reducer's `switch`
  compared loosely, so `type: true` matched `remove_sheet`. Types are now strings
  compared strictly, and anything else is skipped.
- **A padded address wrote a key of its own.** `" a1 "` was stored under `" A1 "`;
  `set_cell` and `clear_cell` now trim, as the address is validated.
- **A column-width key that is not a column index was read as column A** on a
  column insert or delete (`(int) "abc"` is 0) and could overwrite its width. It
  is dropped.
- **Two values JSON cannot hold compared as the same.** `diff()`'s equality check
  encoded both to `""`, so invalid UTF-8 in two different cells could record no
  change. It now throws `JsonException`.

**What you must do:** nothing, unless you relied on one of the above. Ops that
2.3.1's `diff()` produced are unchanged.

## [2.3.1] — 2026-09-14

### Fixed

- **`Agent::opSchema()` accepts the `set_column_widths` op a diff emits when
  every width is removed.** PHP encodes an empty map as `[]`, and the schema
  declared `columnWidths` an object only, so a host validating stored ops with
  `opSchema()` rejected that op from `diff()`. `columnWidths` may now be an
  object or an empty array; both mean "no widths" to the reducer. Found by the
  Node port, which emits `{}` for the same case.

  **What you must do:** nothing.

## [2.3.0] — 2026-09-14

### Added

- **`Agent::diff()`, `Agent::reduce()`, `Agent::opSchema()` and `Agent::equivalent()`:
  a workbook's versions stored as ops** ([#7](https://github.com/Particle-Academy/holy-sheet/issues/7)).
  Hashing xlsx bytes cannot keep a one-cell edit small, because a zip changes
  nearly every byte, so a version history had to store a whole file per edit.
  `diff($new, $old)` is the op list that restores `$old` from `$new`.
  - `reduce($a, diff($a, $b))` equals `$b`, key order aside. The ops are verified
    by replaying them: a sheet the granular ops cannot reproduce is replaced
    whole, and so, as a last resort, is the workbook.
  - One changed cell is one `set_cell`. Rows and columns are aligned by content
    first, so an inserted row is one `insert_rows` plus its cells rather than
    every cell below it rewritten.
  - Schemas that write the same workbook diff to `[]`, so a save without a change
    records nothing. A columns/rows sheet and the cells it becomes are the same,
    and so is the creation time the writer stamps on a schema that names none.
  - `set_cell`, `set_range` and `set_workbook` are fancy-sheets' `SheetOp` shapes
    and behave as its reducer does (a `set_cell` without a formula clears it and
    keeps the format). The rest are holy-sheet's: `clear_cell`,
    `insert_rows`/`delete_rows`, `insert_columns`/`delete_columns`,
    `add_sheet`/`remove_sheet`/`rename_sheet`/`move_sheet`/`replace_sheet`,
    `set_merged_regions`, `set_column_widths`, `set_frozen` and `set_meta`.
  - Row and column ops move cells, merged regions and column widths. They do not
    rewrite formula text; a formula that changes with an insert is its own
    `set_cell`.

  **What you must do:** nothing. This only adds methods.

## [2.2.1] — 2026-09-14

### Fixed

- **`Agent::lint()` accepts quoted sheet names** ([#6](https://github.com/Particle-Academy/holy-sheet/issues/6)).
  `=SUM('My Earnings Projection'!A2:A3)` linted as `#NAME?` because the
  tokenizer had no case for `'`, so any cross-sheet formula pointing at a sheet
  whose name contains a space failed. An agent reading that error concluded
  cross-sheet formulas were unsupported and rebuilt each sheet with duplicated
  formulas. Excel's doubled-quote escape works too: `'Q3 ''Final'''!B2` is the
  sheet `Q3 'Final'`.
- **A reference to a sheet that does not exist is `#REF!`**, quoted or not, and
  the hint names the sheet and lists the ones that exist. It used to lint clean:
  the missing sheet's cells read as blanks, so `=SUM(Nope!A2:A3)` summed to zero
  and passed.
- **Sheet names match case-insensitively**, as in Excel. `deals!A2` reaches the
  sheet `Deals`; before, it read blanks.

**What you must do:** nothing, unless you relied on a formula that names a
missing sheet linting clean. It now reports `#REF!`, which is what Excel shows
for it.

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
