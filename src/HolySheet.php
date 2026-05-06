<?php

declare(strict_types=1);

namespace HolySheet;

/**
 * Holy Sheet — main entry point.
 *
 * The full xlsx writing surface, exposed as both static methods (for
 * direct PHP use) and instance methods (for the Laravel facade and
 * dependency-injection consumers). Every method is a thin wrapper over
 * the underlying services in `HolySheet\Schema\*` and `HolySheet\Writer\*`.
 *
 * Laravel apps get the same surface via the facade:
 *
 *   use HolySheet\Laravel\Facades\HolySheet;
 *   HolySheet::write($schema, $path);
 *   $bytes = HolySheet::toBytes($schema);
 *   $errors = HolySheet::validate($schema);
 *
 * The singleton bound by `HolySheetServiceProvider` resolves through the
 * facade, so this class is what your application's pipelines see.
 */
final class HolySheet
{
    public const VERSION = '1.0.1';

    /* ------------------------------------------------------------------ */
    /* Instance API (used by the Facade + DI consumers)                    */
    /* ------------------------------------------------------------------ */

    /**
     * Validate a schema without writing anything.
     *
     * @param  array<string,mixed>  $schema
     * @return list<array{path:string,expected:string,got:string,value:mixed,hint:string}>  empty list = valid
     */
    public function validate(array $schema): array
    {
        return Agent::validate($schema);
    }

    /**
     * Write a workbook to disk. Throws SchemaException on validation failure.
     *
     * @param  array<string,mixed>  $schema
     * @return array{path:string,bytes:int,sheets:int}
     */
    public function write(array $schema, string $path): array
    {
        return Agent::write($schema, $path);
    }

    /**
     * Return the xlsx bytes without writing to disk.
     *
     * @param  array<string,mixed>  $schema
     */
    public function toBytes(array $schema): string
    {
        return Agent::toBytes($schema);
    }

    /**
     * JSON Schema describing the input format. Drop into Anthropic
     * `tool_use`, OpenAI function-calling, or any framework that
     * consumes JSON Schema.
     *
     * @return array<string,mixed>
     */
    public function toolDefinition(): array
    {
        return Agent::toolDefinition();
    }

    /**
     * Round-trip an existing xlsx file back to a Holy Sheet schema.
     * Stubbed today; lands in 1.1 alongside the formula+style readers.
     *
     * @return array<string,mixed>
     */
    public function describe(string $path): array
    {
        return Agent::describe($path);
    }

    /** Package version (stable when tagged). */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /* ------------------------------------------------------------------ */
    /* Static aliases for non-Laravel / no-DI direct use                   */
    /* ------------------------------------------------------------------ */

    /** Static alias for {@see version()}. */
    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * Static convenience matching the original signature
     * (`HolySheet::write($path, $schema)`). Note the swapped argument
     * order vs the instance method — kept for backwards-compat with
     * 0.2.0 callers. Prefer the facade (`HolySheet::write($schema, $path)`)
     * in new code.
     *
     * @param  array<string,mixed>  $schema
     * @return array{path:string,bytes:int,sheets:int}
     */
    public static function writeFile(string $path, array $schema): array
    {
        return Agent::write($schema, $path);
    }
}
