<?php

declare(strict_types=1);

namespace HolySheet\Workbook;

/**
 * Internal canonical workbook value object.
 *
 * The schema validator + adapters (row-oriented, fancy-sheets) all
 * normalize their input into a Workbook before the writer touches it.
 * This keeps the writer dumb — it walks Workbook → xlsx without
 * needing to handle alternative input shapes.
 */
final class Workbook
{
    /**
     * @param  list<Sheet>  $sheets
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public readonly array $sheets,
        public readonly array $meta = [],
    ) {}
}
