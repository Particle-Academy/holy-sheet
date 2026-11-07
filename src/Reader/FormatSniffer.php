<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Exceptions\UnsupportedFormatException;
use HolySheet\Archive\SourceArchive;
use HolySheet\Exceptions\ArchiveRefused;

/**
 * Which reader a file needs, decided from its contents, never its extension.
 *
 * An OpenDocument package names itself: its first entry is `mimetype`, stored
 * uncompressed, holding the media type. An xlsx has no such entry and is
 * recognised by `xl/workbook.xml`. Anything else is refused by name.
 */
final class FormatSniffer
{
    public const XLSX = 'xlsx';

    public const ODS = 'ods';

    /** The spreadsheet media types, and the template variant (.ots) which is read the same way. */
    private const ODS_MIMETYPES = [
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.spreadsheet-template',
    ];

    /**
     * @param  array<string,int>  $archiveLimits  SourceArchive limits; the defaults when empty
     * @return self::XLSX|self::ODS
     */
    public static function sniff(string $path, array $archiveLimits = []): string
    {
        return self::sniffPath($path, $archiveLimits)[1];
    }

    /**
     * The opened archive and which reader it needs, from a path. A caller that
     * goes on to read the file reads this archive, rather than opening (and
     * inflating) the file a second time.
     *
     * @param  array<string,int>  $archiveLimits
     * @return array{0:SourceArchive,1:self::XLSX|self::ODS}
     */
    public static function sniffPath(string $path, array $archiveLimits = []): array
    {
        try {
            $zip = SourceArchive::fromPath($path, $archiveLimits);
        } catch (ArchiveRefused $error) {
            if ($error->reason === 'archive_corrupt' && @file_get_contents($path, false, null, 0, 2) !== 'PK') {
                throw UnsupportedFormatException::notAZip($path);
            }
            throw $error;
        }

        return [$zip, self::sniffArchive($zip, $path)];
    }

    /**
     * The same decision for bytes: the opened archive and which reader it needs.
     *
     * @param  array<string,int>  $archiveLimits
     * @return array{0:SourceArchive,1:self::XLSX|self::ODS}
     */
    public static function sniffBytes(string $bytes, array $archiveLimits = []): array
    {
        try {
            $zip = SourceArchive::open($bytes, $archiveLimits);
        } catch (ArchiveRefused $error) {
            if ($error->reason === 'archive_corrupt' && ! str_starts_with($bytes, 'PK')) {
                throw UnsupportedFormatException::notAZip('(bytes)');
            }
            throw $error;
        }

        return [$zip, self::sniffArchive($zip, '(bytes)')];
    }

    /** @return self::XLSX|self::ODS */
    public static function sniffArchive(SourceArchive $zip, string $label): string
    {
        $mimetype = $zip->read('mimetype');
        $mimetype = is_string($mimetype) ? trim($mimetype) : null;
        if ($mimetype !== null && in_array($mimetype, self::ODS_MIMETYPES, true)) {
            return self::ODS;
        }
        if ($zip->read('xl/workbook.xml') !== null) {
            return self::XLSX;
        }
        throw UnsupportedFormatException::unknownPackage($label, $mimetype);
    }
}
