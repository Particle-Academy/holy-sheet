<?php

declare(strict_types=1);

namespace HolySheet;

use HolySheet\Archive\OfficeTemplate;
use HolySheet\Archive\SourceArchive;
use HolySheet\Exceptions\UnsupportedFormatException;
use HolySheet\Exchange\ExportPolicy;
use HolySheet\Exchange\Exporter;
use HolySheet\Exchange\InputRefused;
use HolySheet\Exchange\Inserter;
use HolySheet\Helpers\ArrayBuilder;
use HolySheet\Helpers\CsvBuilder;
use HolySheet\Ops\SheetDiff;
use HolySheet\Ops\SheetOpSchema;
use HolySheet\Ops\SheetReducer;
use HolySheet\Reader\FormatSniffer;
use HolySheet\Reader\OdsReader;
use HolySheet\Reader\WorkbookSchema;
use HolySheet\Reader\XlsxReader;
use HolySheet\Schema\DumpOptions;
use HolySheet\Schema\Dumper;
use HolySheet\Schema\FormulaLinter;
use HolySheet\Schema\Normalizer;
use HolySheet\Schema\Repairer;
use HolySheet\Schema\Validator;
use HolySheet\Writer\XlsxWriter;

/**
 * Agent — the structured-tool surface for Holy Sheet.
 *
 * Designed for LLM tool-use: validate-then-write semantics, structured
 * error format, JSON Schema export for tool definitions, round-trip
 * introspection (lands in 0.9 — `describe()` stub today).
 *
 * Every method is static for the simplest possible call shape from
 * agent infrastructure (no DI container required, no construction
 * ceremony). Apps wanting injection should use the underlying
 * services directly: `Schema\Validator`, `Schema\Normalizer`,
 * `Writer\XlsxWriter`.
 */
final class Agent
{
    /**
     * Validate a schema without writing anything. Returns a list of
     * structured errors (empty list = valid).
     *
     * @param  array<string,mixed>  $schema
     * @return list<array{path:string,expected:string,got:string,value:mixed,hint:string}>
     */
    public static function validate(array $schema): array
    {
        return (new Validator())->validate($schema);
    }

    /**
     * Write a workbook to disk. Throws SchemaException on validation
     * failure (see `validate()` to dry-run instead).
     *
     * @param  array<string,mixed>  $schema
     * @return array{path:string,bytes:int,sheets:int}
     */
    public static function write(array $schema, string $path): array
    {
        (new Validator())->assert($schema);
        $workbook = (new Normalizer())->normalize($schema);
        (new XlsxWriter())->write($workbook, $path);
        return [
            'path' => $path,
            'bytes' => filesize($path) ?: 0,
            'sheets' => count($workbook->sheets),
        ];
    }

    /**
     * Return the xlsx bytes without writing to disk. Useful for
     * HTTP responses (Laravel `Response::make($bytes, 200, ...)`)
     * or piping through a CLI.
     *
     * @param  array<string,mixed>  $schema
     */
    public static function toBytes(array $schema): string
    {
        (new Validator())->assert($schema);
        $workbook = (new Normalizer())->normalize($schema);
        return (new XlsxWriter())->toBytes($workbook);
    }

    /**
     * JSON Schema describing the input format. Use as the `parameters`
     * field of an agent tool definition (Anthropic / OpenAI / any
     * framework that consumes JSON Schema). Loaded from
     * `skills/holy-sheet.schema.json` to keep one source of truth.
     *
     * @return array<string,mixed>
     */
    public static function toolDefinition(): array
    {
        $path = dirname(__DIR__).'/skills/holy-sheet.schema.json';
        if (!is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        return $contents === false ? [] : (json_decode($contents, true) ?? []);
    }

    /**
     * Read an existing spreadsheet back to a Holy Sheet schema: an xlsx
     * workbook or an OpenDocument spreadsheet (.ods), told apart by content,
     * never by extension, and described to the same schema. Lossy fields
     * (themes, foreign custom number formats, what ODS does not map) are
     * documented in docs/ReadPath.md; the returned schema is
     * feed-it-back-to-write compatible.
     *
     * @return array<string,mixed>
     *
     * @throws UnsupportedFormatException when the file is neither
     */
    public static function describe(string $path): array
    {
        if (!is_file($path)) {
            return ['error' => 'not_found', 'path' => $path];
        }
        return match (FormatSniffer::sniff($path)) {
            FormatSniffer::ODS => (new OdsReader())->describe($path),
            FormatSniffer::XLSX => (new XlsxReader())->describe($path),
        };
    }

    /**
     * Read a spreadsheet from raw bytes or a path: the sibling-named twin of
     * `describe()` (last-word, dark-slide and the JS/Python ports call it
     * `read`). Same schema, same refusals; bytes are told from a path the way
     * `convertTemplate()` tells them.
     *
     * @return array<string,mixed>
     *
     * @throws UnsupportedFormatException when the input is neither an xlsx nor an ods
     * @throws \HolySheet\Exceptions\ArchiveRefused through the archive guard
     */
    public static function read(string $bytesOrPath): array
    {
        if (Exporter::isPath($bytesOrPath)) {
            return self::describe($bytesOrPath);
        }
        [$zip, $kind] = FormatSniffer::sniffBytes($bytesOrPath);
        $workbook = $kind === FormatSniffer::ODS ? (new OdsReader())->readArchive($zip) : (new XlsxReader())->readArchive($zip);

        return WorkbookSchema::fromWorkbook($workbook);
    }

    /**
     * A cheap index of what an agent can grab: sheets (state, used range,
     * counts), defined names, Excel tables and charts, each with the selector
     * that grabs it. Read this instead of the whole workbook.
     *
     * @param  string|array<string,mixed>  $input  bytes, a path, or a model from read()
     * @return array<string,mixed>  `{ok: true, sheets, definedNames, tables, charts, guard}` or `{ok: false, refused: {reason}}`
     */
    public static function catalog(string|array $input): array
    {
        try {
            $loaded = Exporter::load($input);
        } catch (InputRefused $e) {
            return ['ok' => false, 'refused' => ['reason' => $e->reason]];
        }

        return ['ok' => true] + Exporter::catalog($loaded['model']);
    }

    /**
     * Grab part of a spreadsheet as portable content (`fancyContent: 1`), for
     * last-word, fine-print, dark-slide or another holy-sheet to place.
     *
     * Selector: `"Q4!A1:D20"`, `"'Q4 2026'!A1:D20"`, `"Q4"` (its used range),
     * `{sheet, range}`, `{name}`, `{table}`, or null for every visible sheet.
     * Formulas come back as their cached values and are never evaluated;
     * hidden sheets, rows and columns are skipped and reported; invisible
     * characters are stripped and counted. Not gated: it is a read.
     *
     * @param  string|array<string,mixed>  $input  bytes, a path, or a model from read()
     * @param  array<string,mixed>  $options  `headerRows`; and, from the HOST only, `includeHidden` and `limits`
     * @return array<string,mixed>  `{ok: true, content, report}` or `{ok: false, refused: {reason}}`
     *
     * @throws \HolySheet\Exchange\OptionsInvalid for an unknown or malformed option
     */
    public static function extract(string|array $input, mixed $selector = null, array $options = []): array
    {
        return Exporter::extract($input, $selector, $options);
    }

    /**
     * Portable content -> this package's sparse cell map, with its top-left at
     * `$at`. Every string is written as `{value: s}`, so a leading `=` stays
     * text; a formula is never written, only its value.
     *
     * To place content inside a workbook you are writing, put
     * `{"type": "portable", "at": "B4", "content": ...}` in a sheet's `insert`
     * list instead; validate, validateAndRepair and write expand it.
     *
     * @return array<string,mixed>  `{ok: true, sheet: {cells, mergedRegions?, columnWidths?}, report}` or `{ok: false, refused: {reason, path}}`
     */
    public static function fromPortable(mixed $content, string $at = 'A1'): array
    {
        return Inserter::fromPortable($content, $at);
    }

    /**
     * Export to another format. OFF unless the host's policy allows the target:
     * a null policy allows nothing, and the agent's `$options` (selector,
     * headerRows, display) can never enable anything; any other option throws.
     *
     * Targets: `csv` (one table; formula-injection escaping on unless the
     * policy's `csv.escapeFormulas` is false), `markdown`, `xlsx` (from ods,
     * or an xlsx template). `pdf` and `docx` need fine-print / last-word and
     * are not bridged in this release: they refuse `export_unavailable`.
     *
     * @param  string|array<string,mixed>  $input  bytes, a path, or a model from read()
     * @param  array<string,mixed>|null  $options  agent options
     * @param  ExportPolicy|array<string,mixed>|null  $policy  host policy
     * @return array<string,mixed>  `{ok: true, target, mime, text|bytes, report}` or `{ok: false, refused: {reason, target, missing?, cause?}}`
     *
     * @throws \HolySheet\Exchange\OptionsInvalid for a malformed policy or an option outside the three
     */
    public static function export(string|array $input, string $target, ?array $options = null, ExportPolicy|array|null $policy = null): array
    {
        return Exporter::export($input, $target, $options, $policy);
    }

    /**
     * The export tool for an agent: its `target` enum lists only what `$policy`
     * enables (and what is installed). Null when nothing is enabled, so no
     * export tool is offered at all.
     *
     * @return array{name:string,description:string,parameters:array<string,mixed>}|null
     */
    public static function exportToolDefinition(ExportPolicy|array|null $policy): ?array
    {
        return Exporter::toolDefinition($policy);
    }

    /**
     * Turn an Excel `.xltx` / LibreOffice `.ots` template into its `.xlsx` /
     * `.ods` twin, or back. `$to` is `document`, `template`, or null to flip.
     * Takes raw bytes or a path; returns bytes.
     *
     * Only the declaration moves: the workbook part's content type in
     * `[Content_Types].xml`, or the ODF `mimetype` entry together with the
     * manifest's root media-type (ODF 1.3 requires them to agree). Every other
     * member is copied as its original zip record, byte for byte; no reader or
     * writer is in the path, so nothing is reflowed or lost.
     *
     * Macro-enabled packages (.xltm, .xlsm) are refused, never converted.
     *
     * @throws \HolySheet\Exceptions\OfficeTemplateRefused
     * @throws \HolySheet\Exceptions\ArchiveRefused
     * @throws UnsupportedFormatException for anything that is not a spreadsheet package
     */
    public static function convertTemplate(string $bytesOrPath, ?string $to = null): string
    {
        $isPath = ! str_contains($bytesOrPath, "\0") && strlen($bytesOrPath) <= 4096 && is_file($bytesOrPath);
        $zip = $isPath ? SourceArchive::fromPath($bytesOrPath) : SourceArchive::open($bytesOrPath);
        $label = $isPath ? $bytesOrPath : '(bytes)';
        $mimetype = $zip->read('mimetype');
        $mimetype = is_string($mimetype) ? trim($mimetype) : null;
        $odf = $mimetype !== null && str_starts_with($mimetype, 'application/vnd.oasis.opendocument.spreadsheet');
        if (! $odf && ($mimetype !== null || $zip->read('xl/workbook.xml') === null)) {
            throw UnsupportedFormatException::unknownPackage($label, $mimetype);
        }

        return OfficeTemplate::convert($zip->toBytes(), $to);
    }

    /**
     * Validate a schema and attempt conservative repairs in one call.
     * Returns the (possibly repaired) schema along with the original
     * error list and a list of repairs applied.
     *
     * @param  array<string,mixed>  $schema
     * @return array{schema:array<string,mixed>,errors:list<array<string,mixed>>,repairs:list<string>}
     */
    public static function validateAndRepair(array $schema): array
    {
        return (new Validator())->validateAndRepair($schema);
    }

    /**
     * Build a schema from a flat array of rows. Optional headers; if
     * omitted the first row is treated as headers. Type inference looks
     * at column header names + sample values to pick reasonable types.
     *
     * @param  list<list<mixed>>  $rows
     * @param  list<string>|null  $headers
     * @param  array<string,mixed>  $options  passthrough: theme, currency, totals, frozenRows, frozenCols, sheetName
     * @return array<string,mixed>
     */
    public static function fromArray(
        array $rows,
        ?array $headers = null,
        string $sheetName = 'Sheet 1',
        array $options = [],
    ): array {
        return ArrayBuilder::build($rows, $headers, $sheetName, $options);
    }

    /**
     * Build a schema from a CSV string OR file path. First row is
     * treated as headers. Type inference runs the same as fromArray.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public static function fromCsv(string $csvOrPath, array $options = []): array
    {
        return CsvBuilder::build($csvOrPath, $options);
    }

    /**
     * Evaluate every formula in a schema and report cells that produce
     * Excel-style errors (`#VALUE!`, `#REF!`, `#DIV/0!`, `#NAME?`, `#CIRC!`).
     * Catches the bugs an LLM can introduce: referencing the header row
     * instead of a data row, passing a string to a numeric operator,
     * citing a cell that doesn't exist, or building a circular dependency.
     *
     * Empty list = all formulas evaluate cleanly.
     *
     * @param  array<string,mixed>  $schema
     * @return list<array{sheet:string,address:string,formula:string,error:string,hint:string}>
     */
    public static function lint(array $schema): array
    {
        return (new FormulaLinter())->lint($schema);
    }

    /**
     * Serialize a schema to JSON — the read-tool counterpart to describe().
     * describe() gives an agent the SHAPE of an existing file; dumpJson()
     * gives it the CONTENT (every value + formula) so it can make targeted
     * cell edits or fix existing formulas, then write back through the
     * validate → lint → repair loop.
     *
     * @param  array<string,mixed>  $schema
     */
    public static function dumpJson(array $schema, ?DumpOptions $opts = null): string
    {
        return (new Dumper())->dump($schema, $opts);
    }

    /**
     * The ops that turn schema `$a` into schema `$b`.
     *
     * - `reduce($a, diff($a, $b))` equals `$b` (key order aside).
     * - Schemas that write the same workbook diff to `[]`, so
     *   `diff($s, describe(<file of toBytes($s)>)) === []`: a save without a
     *   change records nothing.
     * - One changed cell is one `set_cell`; an inserted row is one
     *   `insert_rows` plus its cells.
     *
     * Store `diff($new, $old)` to keep a version as the ops that restore it.
     * Both schemas must be valid: the "same workbook" check writes them.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return list<array<string,mixed>>
     */
    public static function diff(array $a, array $b): array
    {
        return SheetDiff::diff($a, $b);
    }

    /**
     * Apply one op, or a list of them, to a schema; returns a new schema. An op
     * naming a sheet that is not there is skipped.
     *
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>|list<array<string,mixed>>  $opOrOps
     * @return array<string,mixed>
     */
    public static function reduce(array $schema, array $opOrOps): array
    {
        $ops = $opOrOps === [] || array_is_list($opOrOps) ? $opOrOps : [$opOrOps];

        return SheetReducer::applyAll($schema, $ops);
    }

    /**
     * JSON Schema for one op. `set_cell`, `set_range` and `set_workbook` are
     * fancy-sheets' `SheetOp` shapes.
     *
     * @return array<string,mixed>
     */
    public static function opSchema(): array
    {
        return SheetOpSchema::jsonSchema();
    }

    /**
     * Whether two schemas write the same workbook: a columns/rows sheet and the
     * cell map it becomes are equivalent, and so are a schema without
     * `meta.created` and its written copy.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public static function equivalent(array $a, array $b): bool
    {
        return SheetDiff::equivalent($a, $b);
    }

    /**
     * The agent guide: what makes this kind of file professional, and the schema
     * idioms that produce it, distilled from rubric-judged builds. Give it to a
     * model with the schema; it works with any agent loop. The same bytes ship in
     * the Node and Python packages (each pins the hash).
     */
    public static function guide(): string
    {
        $path = dirname(__DIR__).'/agent-guide/professional-workbooks.md';
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new \RuntimeException('[holy-sheet] agent-guide/professional-workbooks.md is missing from this install.');
        }

        return $text;
    }
}
