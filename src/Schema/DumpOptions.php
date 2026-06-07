<?php

declare(strict_types=1);

namespace HolySheet\Schema;

/**
 * Options for {@see Dumper::dump()} / {@see \HolySheet\HolySheet::dumpJson()}.
 *
 * Defaults are tuned for agent read-tools: minified, formats kept (so the
 * agent sees number/currency intent), empties dropped, and a 64 KB ceiling
 * so a single read never blows the token budget.
 */
final class DumpOptions
{
    public function __construct(
        /** Pretty-print with indentation. Agents rarely need this; humans do. */
        public readonly bool $prettyPrint = false,
        /** Keep cell `format` objects. Set false to strip styling noise. */
        public readonly bool $includeFormats = true,
        /** Drop empty cells and fully-empty sheets. */
        public readonly bool $compactEmpty = true,
        /** Soft ceiling. Over this, dump() returns a compact shape index instead. 0 = unbounded. */
        public readonly int $maxBytes = 65536,
    ) {}

    /** Smallest agent-friendly output: minified, formats stripped, empties dropped. */
    public static function compact(): self
    {
        return new self(prettyPrint: false, includeFormats: false, compactEmpty: true);
    }

    /** Human-readable: pretty-printed, formats kept, empties preserved, unbounded. */
    public static function verbose(): self
    {
        return new self(prettyPrint: true, includeFormats: true, compactEmpty: false, maxBytes: 0);
    }
}
