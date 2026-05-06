<?php

declare(strict_types=1);

namespace HolySheet\Writer\Format;

use HolySheet\Workbook\CellFormat;

/**
 * Builds the Excel number format string for a CellFormat.
 *
 * Returns null if the format requires no numFmt (e.g. plain text). The
 * returned string is what goes into <numFmt formatCode="...">. Built-in
 * Excel formats use ids < 164 — we always emit custom format codes
 * (>= 164) for predictability across Excel versions.
 */
final class NumFmtBuilder
{
    public static function build(CellFormat $format): ?string
    {
        $df = $format->displayFormat;
        $decimals = $format->decimals;

        if ($df === null || $df === 'auto' || $df === 'text') {
            return null;
        }

        return match ($df) {
            'number' => self::numberFormat($decimals),
            'percentage' => self::percentFormat($decimals),
            'currency' => self::currencyFormat($format->currency, $decimals),
            'date' => 'yyyy-mm-dd',
            'datetime' => 'yyyy-mm-dd hh:mm:ss',
            default => null,
        };
    }

    private static function numberFormat(?int $decimals): string
    {
        if ($decimals === null || $decimals <= 0) {
            return '#,##0';
        }
        return '#,##0.' . str_repeat('0', $decimals);
    }

    private static function percentFormat(?int $decimals): string
    {
        $d = $decimals ?? 1;
        if ($d <= 0) return '0%';
        return '0.' . str_repeat('0', $d) . '%';
    }

    private static function currencyFormat(?string $currency, ?int $decimals): string
    {
        $symbol = self::currencySymbol($currency ?? 'USD');
        $d = $decimals ?? 2;
        $body = $d <= 0 ? '#,##0' : ('#,##0.' . str_repeat('0', $d));
        // Format like:  "$"#,##0.00;-"$"#,##0.00
        return "\"{$symbol}\"{$body};-\"{$symbol}\"{$body}";
    }

    private static function currencySymbol(string $iso): string
    {
        return match (strtoupper($iso)) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'CNY' => '¥',
            'INR' => '₹',
            'AUD' => 'A$',
            'CAD' => 'C$',
            'CHF' => 'CHF',
            'KRW' => '₩',
            default => $iso . ' ',
        };
    }
}
