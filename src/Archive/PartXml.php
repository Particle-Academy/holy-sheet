<?php

declare(strict_types=1);

namespace HolySheet\Archive;

use DOMDocument;
use HolySheet\Exceptions\UnsupportedFormatException;
use SimpleXMLElement;

/**
 * The one way an XML part of an uploaded file gets parsed.
 *
 * A part carrying a DOCTYPE is refused, because no spreadsheet part has one and
 * an internal subset is the entry point for entity expansion. A refusal RAISES
 * `UnsupportedFormatException`, as one in `[Content_Types].xml` always has:
 * failing it like malformed XML made a worksheet with a DOCTYPE read as an
 * EMPTY sheet, which a caller cannot tell from a sheet that holds nothing.
 * Malformed XML is not a refusal and still fails quietly (false / null), so
 * each caller keeps its own handling of a part it cannot read.
 *
 * The check has two halves and the second is the one that holds:
 *
 *  - {@see prologDeclaresDoctype()} looks at the bytes before anything is
 *    parsed, so the ordinary case never reaches the entity machinery. It reads
 *    only the prolog: searching the whole part for the word would refuse a
 *    legitimate cell whose CDATA text is an HTML page.
 *  - The parsed document is then asked whether it has a doctype. A part can
 *    declare any encoding libxml can decode, and no byte scan reads them all,
 *    so the parser's own answer is what decides. Nothing external is loaded in this parse, libxml's own limits
 *    bound its work, and the document is dropped before any value is read from it.
 *
 * @internal
 */
final class PartXml
{
    /**
     * @return SimpleXMLElement|false false when malformed, as `simplexml_load_string` reports it
     *
     * @throws UnsupportedFormatException when the part carries a DOCTYPE
     */
    public static function simple(string $xml): SimpleXMLElement|false
    {
        if (self::prologDeclaresDoctype($xml)) {
            throw UnsupportedFormatException::refusedPart();
        }
        $prev = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($element === false) {
            return false;
        }
        if (dom_import_simplexml($element)->ownerDocument?->doctype !== null) {
            throw UnsupportedFormatException::refusedPart();
        }

        return $element;
    }

    /**
     * @return DOMDocument|null null when absent, empty or malformed
     *
     * @throws UnsupportedFormatException when the part carries a DOCTYPE
     */
    public static function dom(?string $xml): ?DOMDocument
    {
        if ($xml === null || $xml === '') {
            return null;
        }
        if (self::prologDeclaresDoctype($xml)) {
            throw UnsupportedFormatException::refusedPart();
        }
        $dom = self::parsed($xml);
        if ($dom !== null && $dom->doctype !== null) {
            throw UnsupportedFormatException::refusedPart();
        }

        return $dom;
    }

    /**
     * Stream a part's elements without building a tree: `$visit($reader)` is
     * called for every element down to `$maxDepth` (the root is depth 0), with
     * the reader positioned on it, and an element AT `$maxDepth` has its
     * subtree skipped unread. Nothing is kept, so memory does not grow with the
     * part. This is how a read counts what a part holds BEFORE the parse that
     * would hold all of it: a 275 MB worksheet parses into a tree of about 6 GB.
     *
     * A DOCTYPE refuses, as everywhere else. Returns false when the part is
     * malformed: the elements up to the error were visited, which is what lets
     * a limit refuse a part the parser would only call malformed after
     * building most of it.
     *
     * @param  callable(\XMLReader):void  $visit
     *
     * @throws UnsupportedFormatException when the part carries a DOCTYPE
     */
    public static function stream(string $xml, int $maxDepth, callable $visit): bool
    {
        if ($xml === '') {
            return false;
        }
        if (self::prologDeclaresDoctype($xml)) {
            throw UnsupportedFormatException::refusedPart();
        }
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader();
        try {
            if (! $reader->XML($xml, null, LIBXML_NONET)) {
                return false;
            }
            $ok = $reader->read();
            while ($ok) {
                if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                    throw UnsupportedFormatException::refusedPart();
                }
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    $depth = $reader->depth;
                    if ($depth <= $maxDepth) {
                        $visit($reader);
                    }
                    if ($depth >= $maxDepth && ! $reader->isEmptyElement) {
                        $ok = $reader->next();

                        continue;
                    }
                }
                $ok = $reader->read();
            }

            return libxml_get_last_error() === false;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * Whether a part carries a DOCTYPE, for the one caller that must tell that
     * from malformed: a package's own declaration, where the difference decides
     * whether the package is read at all.
     */
    public static function declaresDoctype(string $xml): bool
    {
        if ($xml === '') {
            return false;
        }

        return self::prologDeclaresDoctype($xml) || self::parsed($xml)?->doctype !== null;
    }

    private static function parsed(string $xml): ?DOMDocument
    {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $loaded ? $dom : null;
    }

    /**
     * Whether a DOCTYPE comes before the root element, for the encodings a byte
     * walk can read: anything ASCII-compatible, and UTF-16 or UTF-32 once their
     * NUL bytes are dropped (which keeps the ASCII this looks for).
     */
    public static function prologDeclaresDoctype(string $xml): bool
    {
        if (str_contains(substr($xml, 0, 4), "\0")) {
            $xml = str_replace("\0", '', $xml);
        }
        $at = 0;
        foreach (["\xEF\xBB\xBF", "\xFF\xFE", "\xFE\xFF"] as $bom) {
            if (str_starts_with($xml, $bom)) {
                $at = strlen($bom);
                break;
            }
        }
        while (true) {
            $at += strspn($xml, " \t\r\n", $at);
            $close = match (true) {
                substr($xml, $at, 2) === '<?' => '?>',
                substr($xml, $at, 4) === '<!--' => '-->',
                default => null,
            };
            if ($close === null) {
                return strcasecmp(substr($xml, $at, 9), '<!DOCTYPE') === 0;
            }
            $end = strpos($xml, $close, $at);
            if ($end === false) {
                return false;
            }
            $at = $end + strlen($close);
        }
    }
}
