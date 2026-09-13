<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Exceptions\UnsupportedFormatException;
use ZipArchive;

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
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw UnsupportedFormatException::notAZip($path);
        }

        try {
            $mimetype = $zip->getFromName('mimetype');
            $mimetype = is_string($mimetype) ? trim($mimetype) : null;

            if ($mimetype !== null && in_array($mimetype, self::ODS_MIMETYPES, true)) {
                return self::ODS;
            }
            if ($zip->locateName('xl/workbook.xml') !== false) {
                return self::XLSX;
            }

            throw UnsupportedFormatException::unknownPackage($path, $mimetype);
        } finally {
            $zip->close();
        }
    }
}
