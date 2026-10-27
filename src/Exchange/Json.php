<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * Small helpers for reading decoded JSON as the exchange spec sees it.
 *
 * PHP decodes a JSON object and a JSON array into the same `array`, so "is
 * this an object" is answered by shape: a non-list array, or the empty array
 * (which is either, and is read as whichever the caller needs).
 *
 * @internal
 */
final class Json
{
    public static function isObj(mixed $x): bool
    {
        return \is_array($x) && ($x === [] || ! array_is_list($x));
    }

    public static function isList(mixed $x): bool
    {
        return \is_array($x) && array_is_list($x);
    }

    /** A JSON number with no fractional part: `2.0` is an integer. */
    public static function isInt(mixed $x): bool
    {
        if (\is_int($x)) {
            return true;
        }

        return \is_float($x) && is_finite($x) && floor($x) === $x;
    }

    public static function isNum(mixed $x): bool
    {
        return \is_int($x) || (\is_float($x) && is_finite($x));
    }

    /** JSON Pointer escaping of one reference token (RFC 6901). */
    public static function esc(string|int $k): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], (string) $k);
    }

    /**
     * Compare two strings in UTF-16 code-unit order, as the spec's "code-unit
     * order" (and JavaScript's default sort) means.
     *
     * UTF-8 byte order is code POINT order, which differs from code-unit order
     * only between a character above U+FFFF (a surrogate pair, 0xD800..) and
     * one in U+E000..U+FFFF.
     */
    public static function cuCompare(string $a, string $b): int
    {
        return strcmp(self::cuKey($a), self::cuKey($b));
    }

    /**
     * @param  array<array-key,mixed>  $o
     * @return list<string>
     */
    public static function sortedKeys(array $o): array
    {
        $keys = array_map('strval', array_keys($o));
        usort($keys, self::cuCompare(...));

        return $keys;
    }

    private static function cuKey(string $s): string
    {
        if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $s) !== 1) {
            return $s;
        }
        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $cp = self::codePoint($ch);
            if ($cp >= 0x10000) {
                $v = $cp - 0x10000;
                $out .= self::utf8(0xD800 + ($v >> 10)).self::utf8(0xDC00 + ($v & 0x3FF));
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    private static function codePoint(string $ch): int
    {
        $b = array_values(unpack('C*', $ch) ?: []);

        return match (\count($b)) {
            1 => $b[0],
            2 => (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F),
            3 => (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F),
            default => (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F),
        };
    }

    /** UTF-8-style encoding of a BMP code unit; surrogates included, for ordering only. */
    private static function utf8(int $cp): string
    {
        return \chr(0xE0 | ($cp >> 12)).\chr(0x80 | (($cp >> 6) & 0x3F)).\chr(0x80 | ($cp & 0x3F));
    }

    /**
     * Turn a `json_decode($s)` (object-mode) tree into arrays, recording where
     * an EMPTY object and an EMPTY list stood.
     *
     * Assoc decoding makes `{}` and `[]` the same `[]`, so a validator handed
     * assoc input cannot tell `"items": {}` from `"items": []`. Object-mode
     * input keeps the difference, and these two pointer sets carry it through.
     *
     * @param  array<string,true>  $emptyObjects
     * @param  array<string,true>  $emptyLists
     */
    public static function fromObjects(mixed $x, array &$emptyObjects, array &$emptyLists, string $path = ''): mixed
    {
        if ($x instanceof \stdClass) {
            $vars = get_object_vars($x);
            if ($vars === []) {
                $emptyObjects[$path] = true;

                return [];
            }
            $out = [];
            foreach ($vars as $k => $v) {
                $out[$k] = self::fromObjects($v, $emptyObjects, $emptyLists, $path.'/'.self::esc($k));
            }

            return $out;
        }
        if (\is_array($x)) {
            if ($x === []) {
                $emptyLists[$path] = true;

                return [];
            }
            $out = [];
            foreach ($x as $k => $v) {
                $out[$k] = self::fromObjects($v, $emptyObjects, $emptyLists, $path.'/'.self::esc($k));
            }

            return $out;
        }

        return $x;
    }

    /** Whether a tree holds any stdClass (object-mode JSON). */
    public static function hasObjects(mixed $x): bool
    {
        if ($x instanceof \stdClass) {
            return true;
        }
        if (\is_array($x)) {
            foreach ($x as $v) {
                if (self::hasObjects($v)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Replace invalid UTF-8 with U+FFFD, so a hostile byte string cannot make a /u regex return null. */
    public static function scrub(string $s): string
    {
        if (preg_match('//u', $s) === 1) {
            return $s;
        }

        return htmlspecialchars_decode(htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES);
    }
}
