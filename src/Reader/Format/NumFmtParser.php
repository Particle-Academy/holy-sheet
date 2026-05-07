<?php

declare(strict_types=1);

namespace HolySheet\Reader\Format;

/**
 * Reverse of Writer\Format\NumFmtBuilder.
 *
 * Maps an Excel number format code (or built-in numFmtId) back to a
 * Holy Sheet `displayFormat + decimals + currency` triple. Designed to
 * recognize:
 *   - All format codes Holy Sheet's writer emits (the common path)
 *   - The 50 standard built-in numFmtIds (so foreign Excel files still describe cleanly)
 *
 * Returns null when the format code doesn't match any recognized
 * pattern; the caller stashes the raw code in `CellFormat::$format` /
 * the schema's column `format` field as an escape hatch.
 */
final class NumFmtParser
{
    /** Built-in Excel numFmtIds (0–49). */
    private const BUILTIN = [
        0 => 'General',
        1 => '0',
        2 => '0.00',
        3 => '#,##0',
        4 => '#,##0.00',
        9 => '0%',
        10 => '0.00%',
        11 => '0.00E+00',
        12 => '# ?/?',
        13 => '# ??/??',
        14 => 'mm-dd-yy',
        15 => 'd-mmm-yy',
        16 => 'd-mmm',
        17 => 'mmm-yy',
        18 => 'h:mm AM/PM',
        19 => 'h:mm:ss AM/PM',
        20 => 'h:mm',
        21 => 'h:mm:ss',
        22 => 'm/d/yy h:mm',
        37 => '#,##0 ;(#,##0)',
        38 => '#,##0 ;[Red](#,##0)',
        39 => '#,##0.00;(#,##0.00)',
        40 => '#,##0.00;[Red](#,##0.00)',
        45 => 'mm:ss',
        46 => '[h]:mm:ss',
        47 => 'mmss.0',
        48 => '##0.0E+0',
        49 => '@',
    ];

    /** Currency symbol → ISO code lookup, mirroring NumFmtBuilder::currencySymbol(). */
    private const SYMBOL_TO_ISO = [
        '$' => 'USD',
        '€' => 'EUR',
        '£' => 'GBP',
        '¥' => 'JPY', // also CNY in writer, but JPY is the more common single-byte read
        '₹' => 'INR',
        '₩' => 'KRW',
    ];

    /**
     * @return array{displayFormat:string,decimals?:int,currency?:string}|null
     */
    public static function parse(?string $formatCode): ?array
    {
        if ($formatCode === null) return null;
        $code = $formatCode;

        // General / text / number-without-format
        if ($code === '' || $code === 'General' || $code === '@') {
            return ['displayFormat' => $code === '@' ? 'text' : 'auto'];
        }

        // Date / datetime
        if (self::looksLikeDate($code)) {
            return ['displayFormat' => self::hasTimeComponent($code) ? 'datetime' : 'date'];
        }

        // Percent
        if (str_contains($code, '%')) {
            $decimals = self::decimalsAfter($code, '0');
            return ['displayFormat' => 'percentage', 'decimals' => $decimals];
        }

        // Currency — look for a quoted symbol prefix
        if (preg_match('/^"([^"]+)"/u', $code, $m) === 1) {
            $symbol = $m[1];
            $iso = self::SYMBOL_TO_ISO[$symbol] ?? null;
            $decimals = self::decimalsAfter($code, '0');
            $result = ['displayFormat' => 'currency', 'decimals' => $decimals];
            if ($iso !== null) $result['currency'] = $iso;
            return $result;
        }

        // Plain number with thousands separator
        if (preg_match('/^#?,?#?#?0(\.0+)?$/', explode(';', $code)[0])) {
            return ['displayFormat' => 'number', 'decimals' => self::decimalsAfter($code, '0')];
        }

        return null;
    }

    /**
     * @return array{displayFormat:string,decimals?:int,currency?:string}|null
     */
    public static function parseBuiltin(int $id): ?array
    {
        $code = self::BUILTIN[$id] ?? null;
        if ($code === null) return null;
        return self::parse($code);
    }

    private static function looksLikeDate(string $code): bool
    {
        // Strip quoted literals + locale prefixes before checking date tokens
        $stripped = preg_replace('/"[^"]*"|\[[^\]]*\]/u', '', $code) ?? $code;
        return preg_match('/[ymd]/i', $stripped) === 1;
    }

    private static function hasTimeComponent(string $code): bool
    {
        $stripped = preg_replace('/"[^"]*"|\[[^\]]*\]/u', '', $code) ?? $code;
        return preg_match('/[hHsS]/', $stripped) === 1
            || stripos($stripped, 'mm:') !== false; // m: typically minutes (not month) in time formats
    }

    private static function decimalsAfter(string $code, string $needle): int
    {
        // First positive section before any ;
        $section = explode(';', $code)[0];
        if (preg_match('/0\.(0+)/', $section, $m) === 1) {
            return strlen($m[1]);
        }
        return 0;
    }
}
