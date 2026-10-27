<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * The input could not be read as a spreadsheet. `reason` is the reader's own
 * code (an archive refusal such as `archive_limit`, `macro_enabled`, or
 * `not_a_workbook`); an export reports it as `export_refused` with that cause.
 *
 * @internal
 */
final class InputRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct('[holy-sheet] input refused: '.$reason, 0, $previous);
    }
}
