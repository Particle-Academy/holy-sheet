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
    /** Canonical: no leading "=". See canonicalFormula(). */
    public readonly ?string $formula;

    public function __construct(
        public readonly string $address,
        public readonly string|int|float|bool|null $value,
        ?string $formula = null,
        public readonly ?CellFormat $format = null,
        public readonly ?CellComment $comment = null,
        public readonly string|int|float|bool|null $cachedValue = null,
    ) {
        $this->formula = $formula === null ? null : self::canonicalFormula($formula);
    }

    /**
     * The one normalisation of formula text: every leading "=" is stripped,
     * so "=A2*2" and "A2*2" are the same formula. It lives here, where every
     * Cell is made, so the writer, the linter and the reader all see the same
     * text; until 2.14.2 only the writer stripped it, and lint reported
     * "=A2*2" as a syntax error while write produced `<f>A2*2</f>`.
     */
    public static function canonicalFormula(string $formula): string
    {
        return ltrim($formula, '=');
    }

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
