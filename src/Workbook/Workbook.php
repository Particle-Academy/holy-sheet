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
     * @param  'document'|'template'  $sourceKind  what a reader found: an .xltx / .ots
     *   reads exactly like its .xlsx / .ods twin and is marked `template`
     */
    public function __construct(
        public readonly array $sheets,
        public readonly array $meta = [],
        public readonly string $sourceKind = 'document',
        /**
         * Workbook-level defined names, `ref` as the file wrote it (read only;
         * the writer does not author them).
         *
         * @var list<array{name:string,ref:string}>
         */
        public readonly array $definedNames = [],
        /**
         * Whether the file counts its date serials from 1904-01-01
         * (`<workbookPr date1904="1"/>`). Read only: the writer always writes
         * the 1900 system, and describe() turns every date into an ISO string.
         */
        public readonly bool $date1904 = false,
    ) {}
}
