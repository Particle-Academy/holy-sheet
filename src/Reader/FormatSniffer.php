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

    /** @return self::XLSX|self::ODS */
    public static function sniff(string $path): string
    {
        try {
            $zip = SourceArchive::fromPath($path);
        } catch (ArchiveRefused $error) {
            if ($error->reason === 'archive_corrupt' && @file_get_contents($path, false, null, 0, 2) !== 'PK') {
                throw UnsupportedFormatException::notAZip($path);
            }
            throw $error;
        }
        return self::sniffArchive($zip, $path);
    }

    /**
     * The same decision for bytes: the opened archive and which reader it needs.
     *
     * @return array{0:SourceArchive,1:self::XLSX|self::ODS}
     */
    public static function sniffBytes(string $bytes): array
    {
        try {
            $zip = SourceArchive::open($bytes);
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
