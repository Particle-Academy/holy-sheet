<?php

declare(strict_types=1);

namespace HolySheet\Exceptions;

use InvalidArgumentException;

/**
 * `read($bytes, ['sheet' => $name])` named a sheet the workbook does not have.
 * Names match exactly, as written in the file; the message lists every one, so
 * the caller can pick again without a second read.
 */
final class SheetNotFoundException extends InvalidArgumentException
{
    /** @param  list<string>  $available */
    public function __construct(public readonly string $sheet, public readonly array $available)
    {
        $quote = static fn (string $s): string => '"'.$s.'"';
        parent::__construct('[holy-sheet] no sheet named '.$quote($sheet).'; this workbook has: '.implode(', ', array_map($quote, $available)));
    }
}
