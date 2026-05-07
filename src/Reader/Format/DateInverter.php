<?php

declare(strict_types=1);

namespace HolySheet\Reader\Format;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Excel serial number → ISO-8601 string (companion to Writer\Format\DateConverter).
 *
 * Anchors to 1899-12-30 (matches Excel's 1900 leap-year quirk for
 * dates after 1900-03-01). Fractional days encode time-of-day —
 * `$includeTime` controls whether the result includes the time portion.
 */
final class DateInverter
{
    public static function toIso(float|int $serial, bool $includeTime = false): string
    {
        $epoch = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));
        $secondsSinceEpoch = (int) round(((float) $serial) * 86400);
        $dt = $epoch->modify("+{$secondsSinceEpoch} seconds");
        return $includeTime
            ? $dt->format('Y-m-d\TH:i:s\Z')
            : $dt->format('Y-m-d');
    }
}
