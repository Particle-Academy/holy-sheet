<?php

declare(strict_types=1);

namespace HolySheet;

use HolySheet\Schema\Normalizer;
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
     * Stubbed in 0.2 (returns ['error' => 'not yet implemented']) —
     * lands in 0.9 alongside the formula+style readers.
     *
     * @return array<string,mixed>
     */
    public static function describe(string $path): array
    {
        return [
            'error' => 'not yet implemented',
            'path' => $path,
            'available_in' => '0.9',
        ];
    }
}
