<?php

declare(strict_types=1);

namespace HolySheet\Schema;

use HolySheet\Workbook\CellAddress;

/**
 * What a `columnWidths` entry may be — one rule for the validator, the repairer
 * and the normalizer, and the same rule in the Node and Python ports.
 *
 * - A KEY is a 0-based column index, 0 to {@see self::MAX_INDEX} (Excel's last
 *   column, XFD): an int, or a string of one to five digits.
 * - A WIDTH is a non-negative number of pixels: an int or float, or a string of
 *   digits with an optional decimal part (`"120"`, `"80.5"`).
 *
 * Before 2.3.4 the three writers disagreed on anything else: PHP cast a key
 * `"abc"` to 0 and overwrote column A's width, Python raised `ValueError`, and
 * Node wrote a `NaN` column.
 */
final class ColumnWidths
{
    public const MAX_INDEX = 16383;

    public static function index(mixed $key): ?int
    {
        if (is_int($key)) {
            return $key >= 0 && $key <= self::MAX_INDEX ? $key : null;
        }

        if (is_string($key) && preg_match('/^[0-9]{1,5}$/', $key) === 1 && (int) $key <= self::MAX_INDEX) {
            return (int) $key;
        }

        return null;
    }

    public static function width(mixed $px): ?float
    {
        if (is_int($px) || is_float($px)) {
            return is_finite((float) $px) && $px >= 0 ? (float) $px : null;
        }

        if (is_string($px) && preg_match('/^[0-9]+(\.[0-9]+)?$/', $px) === 1) {
            return (float) $px;
        }

        return null;
    }

    /**
     * A column letter key (`"B"`, `"aa"`) as an index, for repair only. One or two
     * letters (A to ZZ): a longer run like `"abc"` is more likely a mistake than
     * column ABC, and a repair must not guess.
     */
    public static function fromLetters(mixed $key): ?int
    {
        if (! is_string($key) || preg_match('/^[A-Za-z]{1,2}$/', trim($key)) !== 1) {
            return null;
        }

        return CellAddress::index(trim($key));
    }
}
