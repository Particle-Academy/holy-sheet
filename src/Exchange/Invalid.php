<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * The first structural failure of portable content, at a JSON Pointer.
 *
 * @internal Caught inside Portable::validateContent; never escapes it.
 */
final class Invalid extends \Exception
{
    public function __construct(public readonly string $path)
    {
        parent::__construct('content_invalid at '.($path === '' ? '(root)' : $path));
    }
}
