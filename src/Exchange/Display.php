<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * Display strings (SPEC 6): canonical stringification, and the English-only
 * number display a spreadsheet shows.
 *
 * Rounding is done on DECIMAL DIGITS, never on the binary double: the shortest
 * round-trip decimal of the value is rounded half away from zero, so `1.005`
 * at 2 places is `1.01` and `2.675` is `2.68`. A percent moves that decimal
 * point two places; it is never a floating-point multiply, because
 * `0.145 * 100` is `14.499999999999998` and would display as `14%`
 * (SPEC 17 item 10).
 */
final class Display
{
    public const SYMBOLS = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'CNY' => '¥', 'INR' => '₹',
        'AUD' => 'A$', 'CAD' => 'C$', 'CHF' => 'CHF', 'KRW' => '₩',
    ];

    private const DEFAULT_DECIMALS = ['number' => 0, 'currency' => 2, 'percent' => 1];

    /** `shared/decimal` formatFloat: 14 places, trailing zeros and point removed, `-0` as `0`. */
    public static function formatFloat(int|float $v): string
    {
        if (\is_float($v) && ! is_finite($v)) {
            return '0';
        }
        $s = number_format($v, 14, '.', '');
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '-0' ? '0' : $s;
    }

    public static function canonical(string $t, mixed $v): string
    {
        return match ($t) {
            'number' => self::formatFloat(\is_int($v) || \is_float($v) ? $v : 0),
            'bool' => $v ? 'TRUE' : 'FALSE',
            'empty' => '',
            default => \is_string($v) ? $v : (string) json_encode($v),
        };
    }

    /** `display(t, v?, format?)`. */
    public static function display(string $t, mixed $v, mixed $format = null): string
    {
        $kind = \is_array($format) ? ($format['kind'] ?? null) : null;

        if ($t === 'number' && \in_array($kind, ['number', 'currency', 'percent'], true) && (\is_int($v) || \is_float($v))) {
            $places = isset($format['decimals']) && Json::isInt($format['decimals'])
                ? (int) $format['decimals']
                : self::DEFAULT_DECIMALS[$kind];
            $negative = $v < 0 || (\is_float($v) && $v == 0.0 && fdiv(1.0, $v) < 0);
            $d = self::plainDecimal($v);
            if ($kind === 'percent') {
                $d = self::shiftTwo($d);
            }
            $rounded = self::roundHalfAway($d, $places);
            if (preg_match('/^[0.]+$/', $rounded) === 1) {
                $negative = false;
            }
            $parts = explode('.', $rounded, 2);
            $whole = $parts[0];
            if ($kind !== 'percent') {
                $whole = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
            }
            $num = $whole.(isset($parts[1]) ? '.'.$parts[1] : '');
            $sign = $negative ? '-' : '';
            if ($kind === 'percent') {
                return $sign.$num.'%';
            }
            if ($kind === 'currency') {
                $code = \is_string($format['currency'] ?? null) ? $format['currency'] : 'USD';

                return $sign.(self::SYMBOLS[$code] ?? $code.' ').$num;
            }

            return $sign.$num;
        }

        if (($t === 'date' || $t === 'datetime') && ($kind === 'date' || $kind === 'datetime') && \is_string($v)) {
            $date = substr($v, 0, 10);
            if ($kind === 'date') {
                return $date;
            }

            return $date.' '.($t === 'datetime' ? substr($v, 11, 8) : '00:00:00');
        }

        return self::canonical($t, $v);
    }

    /**
     * The shortest decimal string that round-trips to |v|, without an exponent.
     * `serialize_precision = -1` is what makes PHP produce the shortest form
     * (what JavaScript's `String(v)` and Python's `repr` produce).
     */
    public static function plainDecimal(int|float $v): string
    {
        if (\is_int($v)) {
            return ltrim((string) $v, '-');
        }
        $old = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $s = (string) json_encode(abs($v));
        } finally {
            ini_set('serialize_precision', $old === false ? '-1' : $old);
        }
        if (preg_match('/^(\d+)(?:\.(\d+))?e([+-]?\d+)$/i', $s, $m) !== 1) {
            return $s;
        }
        $digits = $m[1].($m[2] ?? '');
        $point = \strlen($m[1]) + (int) $m[3];
        if ($point <= 0) {
            return '0.'.str_repeat('0', -$point).$digits;
        }
        if ($point >= \strlen($digits)) {
            return $digits.str_repeat('0', $point - \strlen($digits));
        }

        return substr($digits, 0, $point).'.'.substr($digits, $point);
    }

    /** x100 by moving the decimal point two places to the right. */
    private static function shiftTwo(string $d): string
    {
        $parts = explode('.', $d, 2);
        $f = str_pad($parts[1] ?? '', 2, '0');
        $whole = ltrim($parts[0].substr($f, 0, 2), '0');
        if ($whole === '') {
            $whole = '0';
        }

        return $whole.(\strlen($f) > 2 ? '.'.substr($f, 2) : '');
    }

    /** Round a plain non-negative decimal string to `$places`, half away from zero, on its digits. */
    private static function roundHalfAway(string $d, int $places): string
    {
        $parts = explode('.', $d, 2);
        $whole = $parts[0];
        $frac = $parts[1] ?? '';
        if (\strlen($frac) <= $places) {
            return $whole.($places > 0 ? '.'.str_pad($frac, $places, '0') : '');
        }
        $digits = $whole.substr($frac, 0, $places);
        $up = (int) $frac[$places] >= 5;
        if ($up) {
            $i = \strlen($digits) - 1;
            while ($i >= 0) {
                if ($digits[$i] === '9') {
                    $digits[$i] = '0';
                    $i--;
                } else {
                    $digits[$i] = (string) ((int) $digits[$i] + 1);
                    break;
                }
            }
            if ($i < 0) {
                $digits = '1'.$digits;
            }
        }
        $w = substr($digits, 0, \strlen($digits) - $places);
        if ($w === '') {
            $w = '0';
        }

        return $places > 0 ? $w.'.'.substr($digits, -$places) : $w;
    }
}
