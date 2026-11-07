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
     * `$visit($depth, $prefix, $localName, $attributes)` gets the reader as
     * `$attributes` (read one with `getAttribute()`); `scanTags()` calls the
     * same visitor with an array, so one visitor serves both.
     *
     * Only a WELL-FORMED part's count can be trusted from here: what XMLReader
     * delivers before a malformed part's error depends on the libxml version
     * (see scanTags()), so a caller that gets false recounts with scanTags().
     *
     * @param  callable(int, string, string, \XMLReader|array<string,string>):void  $visit
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
                        $visit($depth, $reader->prefix, $reader->localName, $reader);
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
     * The elements of a part libxml calls MALFORMED, found without libxml: a
     * byte tokenizer that is a line-for-line port of holy-sheet-js `scanXml`,
     * so a truncated part counts the same in all three ports.
     *
     * It exists because what XMLReader delivers before a malformed part's error
     * depends on the libxml version. Measured on a 220-byte worksheet cut off
     * after three cells: libxml 2.11.9 delivered all three `<c>` elements,
     * libxml 2.9.14 (Ubuntu 24.04's, and so CI's) delivered none, because it
     * pushes the last chunk with "terminate" and returns false before handing
     * over what that chunk parsed. A limit counted from that refused the part
     * on one machine and waved it through on another.
     *
     * `$visit($depth, $prefix, $localName, $attributes)` for every element of
     * the first root (depth 0) down to `$maxDepth`; attributes are keyed by
     * qualified name, values decoded. Comments, CDATA and processing
     * instructions are stepped over; a `DOCTYPE` before the root refuses, and
     * any other declaration ends the scan. UTF-16 and UTF-32 parts are
     * transcoded first; every other encoding a part can declare keeps `<`, `>`,
     * quotes and names in ASCII, so the bytes are scanned as they are.
     *
     * @param  callable(int, string, string, array<string,string>):void  $visit
     *
     * @throws UnsupportedFormatException when a DOCTYPE comes before the root
     */
    public static function scanTags(string $xml, int $maxDepth, callable $visit): void
    {
        $src = self::asciiCompatible($xml);
        $n = strlen($src);
        $i = 0;
        $depth = -1;
        $seenRoot = false;
        while ($i < $n) {
            $lt = strpos($src, '<', $i);
            if ($lt === false) {
                return;
            }
            $i = $lt;
            if (substr_compare($src, '<!--', $i, 4) === 0) {
                $end = strpos($src, '-->', $i);
                $i = $end === false ? $n : $end + 3;

                continue;
            }
            if (substr_compare($src, '<![CDATA[', $i, 9) === 0) {
                $end = strpos($src, ']]>', $i);
                $i = $end === false ? $n : $end + 3;

                continue;
            }
            if (substr_compare($src, '<?', $i, 2) === 0) {
                $end = strpos($src, '?>', $i);
                $i = $end === false ? $n : $end + 2;

                continue;
            }
            if (substr_compare($src, '<!', $i, 2) === 0) {
                if (! $seenRoot && strcasecmp(substr($src, $i, 9), '<!doctype') === 0) {
                    throw UnsupportedFormatException::refusedPart();
                }

                return;
            }
            if (($src[$i + 1] ?? '') === '/') {
                $end = strpos($src, '>', $i);
                if ($depth >= 0) {
                    $depth--;
                }
                // Only the first top-level element is the document, as when parsed.
                if ($depth < 0 && $seenRoot) {
                    return;
                }
                $i = $end === false ? $n : $end + 1;

                continue;
            }
            $end = self::tagEnd($src, $i + 1);
            $selfClosing = $end > 0 && ($src[$end - 1] ?? '') === '/';
            $depth++;
            if ($depth === 0 && $seenRoot) {
                return;
            }
            $seenRoot = true;
            if ($depth <= $maxDepth) {
                $inner = substr($src, $i + 1, ($selfClosing ? $end - 1 : $end) - $i - 1);
                [$qname, $attrs] = self::tag($inner);
                $colon = strpos($qname, ':');
                $visit($depth, $colon === false ? '' : substr($qname, 0, $colon), $colon === false ? $qname : substr($qname, $colon + 1), $attrs);
            }
            if ($selfClosing) {
                $depth--;
                if ($depth < 0) {
                    return;
                }
            }
            $i = $end + 1;
        }
    }

    /** The first `>` outside a quoted attribute value, or the end. */
    private static function tagEnd(string $src, int $from): int
    {
        $n = strlen($src);
        $quote = null;
        for ($i = $from; $i < $n; $i++) {
            $ch = $src[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
            } elseif ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '>') {
                return $i;
            }
        }

        return $n;
    }

    /**
     * A start tag's qualified name and attributes, as holy-sheet-js `parseTag`
     * reads them.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private static function tag(string $inner): array
    {
        $inner = trim($inner);
        $nameLength = strcspn($inner, " \t\r\n");
        $attrs = [];
        if (preg_match_all('/([^\s=\/]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', substr($inner, $nameLength), $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $a) {
                $value = ($a[2] ?? '') !== '' ? $a[2] : ($a[3] ?? '');
                $attrs[$a[1]] = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        return [substr($inner, 0, $nameLength), $attrs];
    }

    /** The part in an encoding whose markup is ASCII: UTF-16 and UTF-32 become UTF-8. */
    private static function asciiCompatible(string $xml): string
    {
        $b = substr($xml, 0, 4);
        $from = match (true) {
            str_starts_with($b, "\xFF\xFE\x00\x00"), str_starts_with($b, "\x3C\x00\x00\x00") => 'UTF-32LE',
            str_starts_with($b, "\x00\x00\xFE\xFF"), str_starts_with($b, "\x00\x00\x00\x3C") => 'UTF-32BE',
            str_starts_with($b, "\xFF\xFE"), str_starts_with($b, "\x3C\x00") => 'UTF-16LE',
            str_starts_with($b, "\xFE\xFF"), str_starts_with($b, "\x00\x3C") => 'UTF-16BE',
            default => null,
        };
        if ($from === null) {
            return $xml;
        }
        $bom = match ($from) {
            'UTF-32LE' => "\xFF\xFE\x00\x00", 'UTF-32BE' => "\x00\x00\xFE\xFF", 'UTF-16LE' => "\xFF\xFE", default => "\xFE\xFF",
        };

        return (string) mb_convert_encoding(str_starts_with($xml, $bom) ? substr($xml, strlen($bom)) : $xml, 'UTF-8', $from);
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
