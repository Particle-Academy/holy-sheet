<?php

declare(strict_types=1);

namespace HolySheet\Workbook;

/**
 * A single cell in a sheet.
 *
 * Holds intent — value, optional formula, optional CellFormat, optional
 * CellComment, optional cached formula value. The writer's StylesRegistry
 * resolves formats to xf indexes during serialization.
 */
final class Cell
{
    public function __construct(
        public readonly string $address,
        public readonly string|int|float|bool|null $value,
        public readonly ?string $formula = null,
        public readonly ?CellFormat $format = null,
        public readonly ?CellComment $comment = null,
        public readonly string|int|float|bool|null $cachedValue = null,
    ) {}

    public function excelType(): string
    {
        if ($this->formula !== null) return 'str';
        if (is_string($this->value)) return 'inlineStr';
        if (is_bool($this->value)) return 'b';
        return 'n';
    }

    public function withFormat(?CellFormat $format): self
    {
        return new self($this->address, $this->value, $this->formula, $format, $this->comment, $this->cachedValue);
    }

    public function withValue(string|int|float|bool|null $value): self
    {
        return new self($this->address, $value, $this->formula, $this->format, $this->comment, $this->cachedValue);
    }
}
