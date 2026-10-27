<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * Bad options or a malformed export policy (`options_invalid`).
 *
 * Thrown rather than returned: a bad option is a programming error in the
 * host, not something wrong with the document. An `allow` passed in an agent's
 * options is an unknown option, and lands here; it never enables anything.
 */
final class OptionsInvalid extends \InvalidArgumentException
{
    public const CODE = 'options_invalid';

    public function __construct(string $message = '')
    {
        parent::__construct('[holy-sheet] options_invalid'.($message === '' ? '' : ': '.$message));
    }
}
