<?php

declare(strict_types=1);

namespace HolySheet\Exceptions;

use RuntimeException;

/**
 * A relationship in the package points at a part the package does not contain,
 * or a `<sheet>` names a relationship that does not exist.
 *
 * The reader used to skip such a part silently, so a workbook whose
 * every sheet was unreachable read as a workbook with no sheets and no error.
 * `$part` is the resolved part name (null when the relationship itself is
 * missing), `$source` the part that owns the relationship, `$relId` its id.
 * A RuntimeException, as a missing xl/workbook.xml always was, so an existing
 * `catch (RuntimeException)` still catches it.
 */
final class MissingPartException extends RuntimeException
{
    public function __construct(public readonly ?string $part, public readonly string $source, public readonly string $relId)
    {
        parent::__construct($part === null
            ? "[holy-sheet] {$source} names relationship {$relId}, which its rels part does not have"
            : "[holy-sheet] missing part {$part}: {$source} relationship {$relId} points to it, but the package does not contain it");
    }
}
