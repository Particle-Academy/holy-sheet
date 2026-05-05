<?php

declare(strict_types=1);

namespace HolySheet\Workbook;

/**
 * A single cell in a sheet. Format/comment land in 0.3+/0.6.
 */
final class Cell
{
    public function __construct(
        public readonly string $address,
        public readonly string|int|float|bool|null $value,
        public readonly ?string $formula = null,
    ) {}

    /**
     * Excel cell type code: `s` (shared string), `inlineStr` (inline),
     * `n` (numeric, default), `b` (boolean), `e` (error), `str` (formula
     * string result).
     *
     * v0.2 uses inline strings for all string values — sharedStrings.xml
     * arrives in 0.7 once text-heavy sheets matter.
     */
    public function excelType(): string
    {
        if ($this->formula !== null) {
            return 'str';
        }
        if (is_string($this->value)) {
            return 'inlineStr';
        }
        if (is_bool($this->value)) {
            return 'b';
        }
        return 'n';
    }
}
