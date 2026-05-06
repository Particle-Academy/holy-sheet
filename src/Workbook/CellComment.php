<?php

declare(strict_types=1);

namespace HolySheet\Workbook;

final class CellComment
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $author = null,
        public readonly ?string $color = null,    // hex; corner triangle (display-only — Excel ignores)
    ) {}
}
