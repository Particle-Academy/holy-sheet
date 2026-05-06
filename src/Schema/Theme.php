<?php

declare(strict_types=1);

namespace HolySheet\Schema;

use HolySheet\Workbook\CellFormat;

/**
 * Theme presets — pre-baked CellFormat sets applied during normalization.
 *
 * Each theme exposes:
 *   - headerFormat() — applied to row 1 when sheet has columns
 *   - dataFormat($rowIndex) — applied to each data row (banded rows etc.)
 *   - totalsFormat() — applied to the totals row when present
 *
 * Returning null = no formatting (the "plain" theme).
 */
final class Theme
{
    public function __construct(public readonly string $key) {}

    public function headerFormat(): ?CellFormat
    {
        return match ($this->key) {
            'default', 'business' => new CellFormat(
                bold: true,
                color: '#FFFFFF',
                backgroundColor: $this->key === 'business' ? '#1F2937' : '#374151',
            ),
            'minimal' => new CellFormat(bold: true, borderBottom: '#000000'),
            default => null,
        };
    }

    public function dataFormat(int $rowIndexZeroBased): ?CellFormat
    {
        // Banded rows on default + business
        if (($this->key === 'default' || $this->key === 'business') && $rowIndexZeroBased % 2 === 1) {
            return new CellFormat(backgroundColor: '#F3F4F6');
        }
        return null;
    }

    public function totalsFormat(): ?CellFormat
    {
        return match ($this->key) {
            'default', 'business' => new CellFormat(bold: true, borderTop: '#000000'),
            'minimal' => new CellFormat(bold: true, borderTop: '#000000'),
            default => null,
        };
    }
}
