<?php

declare(strict_types=1);

namespace HolySheet\Exceptions;

use RuntimeException;

/**
 * Thrown by `describe()` for a file that is neither an xlsx workbook nor an
 * OpenDocument spreadsheet.
 *
 * Extends RuntimeException because that is what an unreadable file threw
 * before this class existed, so a caller catching RuntimeException still
 * catches it. `$mimetype` carries what the package declared about itself when
 * it declared anything (an OpenDocument TEXT file, say), so a caller can say
 * "that is a document, not a spreadsheet" instead of "could not read".
 */
final class UnsupportedFormatException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $path,
        public readonly ?string $mimetype = null,
    ) {
        parent::__construct($message);
    }

    public static function notAZip(string $path): self
    {
        return new self(
            "[holy-sheet] cannot read {$path}: it is not a zip archive, and xlsx and ods both are",
            $path,
        );
    }

    public static function unknownPackage(string $path, ?string $mimetype): self
    {
        $what = $mimetype !== null && $mimetype !== ''
            ? "its mimetype is {$mimetype}"
            : 'it has neither xl/workbook.xml nor an OpenDocument mimetype';

        return new self(
            "[holy-sheet] cannot read {$path}: {$what}. Supported: xlsx, ods",
            $path,
            $mimetype !== '' ? $mimetype : null,
        );
    }
}
