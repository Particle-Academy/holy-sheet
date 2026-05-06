<?php

declare(strict_types=1);

namespace HolySheet\Writer\Format;

use DateTimeInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * ISO string / DateTimeInterface → Excel serial number.
 *
 * Excel's epoch is 1900-01-01 with the legacy "1900 leap year" bug —
 * Excel believes 1900 was a leap year (it wasn't). To match Excel's
 * behavior for dates >= 1900-03-01, anchor to 1899-12-30 and add the
 * day count. Dates before 1900-03-01 are agentically rare; we accept
 * Excel's quirk for them.
 */
final class DateConverter
{
    public static function toSerial(string|DateTimeInterface $value, bool $includeTime = false): float
    {
        $dt = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : self::parseString($value);

        if ($dt === null) {
            return 0.0;
        }

        $epoch = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));
        $dtUtc = $dt->setTimezone(new DateTimeZone('UTC'));

        $diff = $dtUtc->getTimestamp() - $epoch->getTimestamp();
        $days = $diff / 86400;

        if (!$includeTime) {
            $days = floor($days);
        }
        return (float) $days;
    }

    private static function parseString(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') return null;
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
