<?php

declare(strict_types=1);

namespace HolySheet;

/**
 * Holy Sheet — main entry point.
 *
 * Standalone spreadsheet writing tool for agentic document creation.
 * Framework-agnostic; the optional Laravel service provider lives at
 * `HolySheet\Laravel\HolySheetServiceProvider`.
 *
 * The actual writing API lands in subsequent commits — this scaffold
 * locks in the namespace, the package metadata, and the Laravel
 * auto-discovery wiring.
 */
final class HolySheet
{
    public const VERSION = '0.2.0-dev';

    /** Package version (stable when tagged). */
    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * Convenience facade over `Agent::write()` for the most common case:
     * one-call writing of a schema to a file path.
     *
     *   HolySheet::write('/tmp/q4.xlsx', ['sheets' => [['name' => 'Q4', ...]]]);
     *
     * @param  array<string,mixed>  $schema
     * @return array{path:string,bytes:int,sheets:int}
     */
    public static function write(string $path, array $schema): array
    {
        return Agent::write($schema, $path);
    }
}
