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
    public const VERSION = '0.1.0-dev';

    /** Package version (stable when tagged). */
    public static function version(): string
    {
        return self::VERSION;
    }
}
