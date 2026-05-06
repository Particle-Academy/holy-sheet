<?php

declare(strict_types=1);

namespace HolySheet\Workbook;

final class MergedRegion
{
    public function __construct(
        public readonly string $start,
        public readonly string $end,
    ) {}

    public function ref(): string
    {
        return "{$this->start}:{$this->end}";
    }
}
