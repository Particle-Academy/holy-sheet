<?php

declare(strict_types=1);

namespace HolySheet\Workbook;

/**
 * Per-cell format. Mirrors fancy-sheets' CellFormat shape so direct
 * passthrough from a `<Spreadsheet>` workbook works without translation.
 *
 * The writer's StylesRegistry deduplicates equal formats — every unique
 * combination becomes one entry in styles.xml referenced by all cells
 * that share it. A 10k-row sheet with one bold-header style records the
 * style once, not 10k times.
 */
final class CellFormat
{
    public function __construct(
        public readonly bool $bold = false,
        public readonly bool $italic = false,
        public readonly ?string $textAlign = null,         // 'left' | 'center' | 'right'
        public readonly ?string $displayFormat = null,     // 'auto' | 'text' | 'number' | 'date' | 'datetime' | 'percentage' | 'currency'
        public readonly ?int $decimals = null,
        public readonly ?string $color = null,             // hex
        public readonly ?string $backgroundColor = null,   // hex
        public readonly ?int $fontSize = null,
        public readonly ?string $borderTop = null,
        public readonly ?string $borderRight = null,
        public readonly ?string $borderBottom = null,
        public readonly ?string $borderLeft = null,
        public readonly ?string $currency = null,          // ISO-4217 (USD/EUR/JPY)
    ) {}

    /** Stable key used for dedup in StylesRegistry. */
    public function key(): string
    {
        return md5(serialize([
            'b' => $this->bold,
            'i' => $this->italic,
            'a' => $this->textAlign,
            'df' => $this->displayFormat,
            'd' => $this->decimals,
            'c' => $this->color,
            'bg' => $this->backgroundColor,
            'fs' => $this->fontSize,
            'bt' => $this->borderTop,
            'br' => $this->borderRight,
            'bb' => $this->borderBottom,
            'bl' => $this->borderLeft,
            'cu' => $this->currency,
        ]));
    }

    /** Returns true when no fields are set — caller can skip emitting a style record. */
    public function isEmpty(): bool
    {
        return !$this->bold && !$this->italic
            && $this->textAlign === null
            && $this->displayFormat === null
            && $this->decimals === null
            && $this->color === null
            && $this->backgroundColor === null
            && $this->fontSize === null
            && $this->borderTop === null
            && $this->borderRight === null
            && $this->borderBottom === null
            && $this->borderLeft === null
            && $this->currency === null;
    }

    /** @param  array<string,mixed>  $a */
    public static function fromArray(array $a): self
    {
        return new self(
            bold: (bool) ($a['bold'] ?? false),
            italic: (bool) ($a['italic'] ?? false),
            textAlign: $a['textAlign'] ?? null,
            displayFormat: $a['displayFormat'] ?? null,
            decimals: isset($a['decimals']) ? (int) $a['decimals'] : null,
            color: $a['color'] ?? null,
            backgroundColor: $a['backgroundColor'] ?? null,
            fontSize: isset($a['fontSize']) ? (int) $a['fontSize'] : null,
            borderTop: $a['borderTop'] ?? null,
            borderRight: $a['borderRight'] ?? null,
            borderBottom: $a['borderBottom'] ?? null,
            borderLeft: $a['borderLeft'] ?? null,
            currency: $a['currency'] ?? null,
        );
    }

    /** Merge another format on top of this one (other wins where set). */
    public function mergeWith(?CellFormat $other): self
    {
        if ($other === null) return $this;
        return new self(
            bold: $other->bold || $this->bold,
            italic: $other->italic || $this->italic,
            textAlign: $other->textAlign ?? $this->textAlign,
            displayFormat: $other->displayFormat ?? $this->displayFormat,
            decimals: $other->decimals ?? $this->decimals,
            color: $other->color ?? $this->color,
            backgroundColor: $other->backgroundColor ?? $this->backgroundColor,
            fontSize: $other->fontSize ?? $this->fontSize,
            borderTop: $other->borderTop ?? $this->borderTop,
            borderRight: $other->borderRight ?? $this->borderRight,
            borderBottom: $other->borderBottom ?? $this->borderBottom,
            borderLeft: $other->borderLeft ?? $this->borderLeft,
            currency: $other->currency ?? $this->currency,
        );
    }
}
