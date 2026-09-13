<?php

declare(strict_types=1);

namespace HolySheet\Reader\Ods;

/**
 * OpenDocument namespace URIs.
 *
 * Elements and attributes are matched by URI, never by prefix: `table:` is a
 * convention every producer happens to follow, not something the format
 * promises.
 */
final class Ns
{
    public const OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    public const TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

    public const TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    public const STYLE = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';

    public const FO = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';

    public const NUMBER = 'urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0';

    public const DC = 'http://purl.org/dc/elements/1.1/';

    public const META = 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0';

    /** LibreOffice's extension namespace; carries `value-type="error"` for a formula error. */
    public const CALCEXT = 'urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0';
}
