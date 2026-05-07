<?php

declare(strict_types=1);

namespace HolySheet\Schema;

/**
 * Type inference for tabular data → Holy Sheet column types.
 *
 * Used by `Agent::fromArray()` and `Agent::fromCsv()` to pick a sensible
 * `type` per column when the caller doesn't supply one. Rules favor
 * predictability over cleverness — agents need to be able to anticipate
 * what type will be inferred from a given input.
 *
 * Inference looks at the column header AND a sample of values. Headers
 * give semantic intent (a "Revenue" column probably wants currency
 * formatting); sample values reject that intent if the data doesn't fit.
 */
final class Inference
{
    private const SAMPLE_SIZE = 50;

    private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}$/';
    private const ISO_DATETIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/';

    private const HEADER_INTEGER = '/(^|[\s_])(count|qty|quantity|num|number|id|n)([\s_]|$)/i';
    private const HEADER_CURRENCY = '/(price|amount|cost|revenue|fee|total|salary|budget|balance)/i';
    private const HEADER_PERCENT = '/(rate|percent|growth|yoy|margin|share|ratio)/i';

    /**
     * @param  list<mixed>  $columnValues  raw values from the column (any size; only the first SAMPLE_SIZE are inspected)
     * @return array<string,mixed>  schema-shaped column definition: at minimum {header, type}; may include `currency`, `decimals`, `format`
     */
    public static function detect(array $columnValues, string $headerName, array $options = []): array
    {
        $sample = self::nonNullSample($columnValues);

        // No values to inspect → fall back to auto.
        if ($sample === []) {
            return ['header' => $headerName, 'type' => 'auto'];
        }

        // Boolean column
        if (self::allBoolean($sample)) {
            return ['header' => $headerName, 'type' => 'boolean'];
        }

        $allNumeric = self::allNumeric($sample);
        $allInRange = $allNumeric && self::allInRange01($sample);
        $allInteger = $allNumeric && self::allInteger($sample);
        $allDate = self::allMatch($sample, self::ISO_DATE);
        $allDateTime = self::allMatch($sample, self::ISO_DATETIME);

        // Date / datetime
        if ($allDate) {
            return ['header' => $headerName, 'type' => 'date'];
        }
        if ($allDateTime) {
            return ['header' => $headerName, 'type' => 'datetime'];
        }

        // Numeric paths
        if ($allNumeric) {
            // Header-driven specialization
            if (preg_match(self::HEADER_PERCENT, $headerName) === 1 && $allInRange) {
                return ['header' => $headerName, 'type' => 'percent', 'decimals' => self::detectDecimals($sample) ?: 1];
            }
            if (preg_match(self::HEADER_CURRENCY, $headerName) === 1) {
                return [
                    'header' => $headerName,
                    'type' => 'currency',
                    'currency' => $options['currency'] ?? 'USD',
                    'decimals' => self::detectDecimals($sample),
                ];
            }
            if (preg_match(self::HEADER_INTEGER, $headerName) === 1 && $allInteger) {
                return ['header' => $headerName, 'type' => 'integer'];
            }
            if ($allInteger) {
                return ['header' => $headerName, 'type' => 'integer'];
            }

            $col = ['header' => $headerName, 'type' => 'number'];
            $decimals = self::detectDecimals($sample);
            if ($decimals !== null && $decimals > 0) {
                $col['decimals'] = $decimals;
            }
            return $col;
        }

        // All strings (or mix of bool + string treated as string for safety) → string.
        if (self::allStringish($sample)) {
            return ['header' => $headerName, 'type' => 'string'];
        }

        // Mixed → let the per-cell normalizer decide.
        return ['header' => $headerName, 'type' => 'auto'];
    }

    /** @param  list<mixed>  $values @return list<mixed> */
    private static function nonNullSample(array $values): array
    {
        $out = [];
        $count = 0;
        foreach ($values as $v) {
            if ($v === null) continue;
            $out[] = $v;
            if (++$count >= self::SAMPLE_SIZE) break;
        }
        return $out;
    }

    private static function allBoolean(array $sample): bool
    {
        foreach ($sample as $v) {
            if (!is_bool($v)) return false;
        }
        return true;
    }

    private static function allNumeric(array $sample): bool
    {
        foreach ($sample as $v) {
            if (is_bool($v)) return false;
            if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) return false;
        }
        return true;
    }

    private static function allInteger(array $sample): bool
    {
        foreach ($sample as $v) {
            if (is_int($v)) continue;
            if (is_float($v) && floor($v) === $v) continue;
            if (is_string($v) && preg_match('/^-?\d+$/', $v) === 1) continue;
            return false;
        }
        return true;
    }

    private static function allInRange01(array $sample): bool
    {
        foreach ($sample as $v) {
            $f = is_string($v) ? (float) $v : (float) $v;
            if ($f < 0 || $f > 1) return false;
        }
        return true;
    }

    private static function detectDecimals(array $sample): ?int
    {
        $max = 0;
        $sawFloat = false;
        foreach ($sample as $v) {
            $s = is_string($v) ? $v : (string) $v;
            if (str_contains($s, '.')) {
                $sawFloat = true;
                $decs = strlen(rtrim(explode('.', $s, 2)[1] ?? '', '0'));
                if ($decs > $max) $max = $decs;
            }
        }
        if (!$sawFloat) return 0;
        return max($max, 2); // never less than 2 once we see a float — looks reasonable
    }

    private static function allMatch(array $sample, string $regex): bool
    {
        foreach ($sample as $v) {
            if (!is_string($v)) return false;
            if (preg_match($regex, $v) !== 1) return false;
        }
        return true;
    }

    private static function allStringish(array $sample): bool
    {
        foreach ($sample as $v) {
            if (!is_string($v)) return false;
        }
        return true;
    }
}
