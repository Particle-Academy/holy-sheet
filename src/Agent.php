<?php

declare(strict_types=1);

namespace HolySheet;

use HolySheet\Helpers\ArrayBuilder;
use HolySheet\Helpers\CsvBuilder;
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
     * Round-trip an existing xlsx file back to a Holy Sheet schema.
     * Lossy fields (themes, foreign custom number formats) are documented
     * in docs/ReadPath.md; the returned schema is feed-it-back-to-write
     * compatible.
     *
     * @return array<string,mixed>
     */
    public static function describe(string $path): array
    {
        if (!is_file($path)) {
            return ['error' => 'not_found', 'path' => $path];
        }
        return (new XlsxReader())->describe($path);
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
}
