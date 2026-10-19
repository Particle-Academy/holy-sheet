<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use DOMDocument;
use DOMElement;
use HolySheet\Workbook\ChartRange;

/**
 * Charts back into the normalised shape the writer takes: a sheet's drawing
 * (where each chart sits) plus each chart part (what it plots).
 *
 * Reads what the writer writes and what Excel writes for the same four types
 * (clustered column / bar, line, pie). A chart of another type, or one that
 * plots no range, is skipped rather than guessed at. Namespace-aware lookups,
 * because a chart from another producer can use other prefixes.
 */
final class ChartParser
{
    private const NS_XDR = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';

    private const NS_C = 'http://schemas.openxmlformats.org/drawingml/2006/chart';

    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * The anchors in a drawing: [{from: [col,row], to: [col,row], rel: rId}].
     *
     * @return list<array{from: array{0:int,1:int}, to: array{0:int,1:int}, rel: string}>
     */
    public static function anchors(string $drawingXml): array
    {
        $dom = self::load($drawingXml);
        if ($dom === null) {
            return [];
        }
        $out = [];
        foreach ($dom->getElementsByTagNameNS(self::NS_XDR, 'twoCellAnchor') as $anchor) {
            $from = self::marker($anchor, 'from');
            $to = self::marker($anchor, 'to');
            $chart = $anchor->getElementsByTagNameNS(self::NS_C, 'chart')->item(0);
            $rel = $chart instanceof DOMElement ? $chart->getAttributeNS(self::NS_R, 'id') : '';
            if ($from !== null && $to !== null && $rel !== '') {
                $out[] = ['from' => $from, 'to' => $to, 'rel' => $rel];
            }
        }

        return $out;
    }

    /**
     * One chart part, normalised as Normalizer::normalizeCharts would leave it,
     * or null for a chart this reader does not model.
     *
     * @param  array{0:int,1:int}  $from
     * @param  array{0:int,1:int}  $to
     * @return array<string, mixed>|null
     */
    public static function chart(string $chartXml, string $sheetName, array $from, array $to): ?array
    {
        $dom = self::load($chartXml);
        if ($dom === null) {
            return null;
        }
        $plot = null;
        $type = null;
        foreach (['barChart', 'lineChart', 'pieChart'] as $tag) {
            $el = $dom->getElementsByTagNameNS(self::NS_C, $tag)->item(0);
            if ($el instanceof DOMElement) {
                $plot = $el;
                $type = match ($tag) {
                    'lineChart' => 'line',
                    'pieChart' => 'pie',
                    default => self::val($el, 'barDir') === 'bar' ? 'bar' : 'column',
                };
                break;
            }
        }
        if ($plot === null || $type === null) {
            return null;
        }

        $categories = null;
        $series = [];
        foreach (self::children($plot, 'ser') as $ser) {
            $valRef = self::formula($ser, 'val');
            $values = $valRef !== null ? ChartRange::parse($valRef, $sheetName) : null;
            if ($values === null) {
                continue;
            }
            if ($categories === null) {
                $catRef = self::formula($ser, 'cat');
                $categories = $catRef !== null ? ChartRange::parse($catRef, $sheetName) : null;
            }
            $series[] = ['name' => self::seriesName($ser), 'values' => $values, 'color' => self::seriesColor($ser), 'points' => self::points($ser)];
        }
        if ($series === []) {
            return null;
        }

        $labels = self::child($plot, 'dLbls');
        $dataLabels = $labels !== null && self::val($labels, 'showVal') === '1';
        $format = null;
        foreach ([$labels, $dom->getElementsByTagNameNS(self::NS_C, 'valAx')->item(0)] as $host) {
            $numFmt = $host instanceof DOMElement ? self::child($host, 'numFmt') : null;
            if ($numFmt !== null && $numFmt->getAttribute('sourceLinked') === '0' && $numFmt->getAttribute('formatCode') !== '') {
                $format = $numFmt->getAttribute('formatCode');
                break;
            }
        }

        $title = null;
        $titleEl = $dom->getElementsByTagNameNS(self::NS_C, 'title')->item(0);
        if ($titleEl instanceof DOMElement) {
            $text = '';
            foreach ($titleEl->getElementsByTagNameNS(self::NS_A, 't') as $t) {
                $text .= $t->textContent;
            }
            $title = $text !== '' ? $text : null;
        }

        return [
            'type' => $type,
            'title' => $title,
            'categories' => $categories,
            'series' => $series,
            'from' => $from,
            'to' => $to,
            'legend' => $dom->getElementsByTagNameNS(self::NS_C, 'legend')->length > 0 ? true : null,
            'dataLabels' => $dataLabels,
            'format' => $format,
        ];
    }

    private static function load(string $xml): ?DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $ok ? $dom : null;
    }

    /** @return array{0:int,1:int}|null */
    private static function marker(DOMElement $anchor, string $tag): ?array
    {
        foreach ($anchor->childNodes as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === self::NS_XDR && $node->localName === $tag) {
                $col = $node->getElementsByTagNameNS(self::NS_XDR, 'col')->item(0);
                $row = $node->getElementsByTagNameNS(self::NS_XDR, 'row')->item(0);
                if ($col !== null && $row !== null && ctype_digit(trim($col->textContent)) && ctype_digit(trim($row->textContent))) {
                    return [(int) trim($col->textContent), (int) trim($row->textContent)];
                }
            }
        }

        return null;
    }

    /** @return list<DOMElement> direct c: children with this local name */
    private static function children(DOMElement $parent, string $name): array
    {
        $out = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === self::NS_C && $node->localName === $name) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private static function child(DOMElement $parent, string $name): ?DOMElement
    {
        return self::children($parent, $name)[0] ?? null;
    }

    private static function val(DOMElement $parent, string $name): ?string
    {
        $el = self::child($parent, $name);

        return $el?->getAttribute('val');
    }

    /** The c:f under c:val / c:cat, through numRef or strRef. */
    private static function formula(DOMElement $ser, string $name): ?string
    {
        $host = self::child($ser, $name);
        if ($host === null) {
            return null;
        }
        foreach (['numRef', 'strRef'] as $ref) {
            $r = self::child($host, $ref);
            $f = $r !== null ? self::child($r, 'f') : null;
            if ($f !== null && trim($f->textContent) !== '') {
                return trim($f->textContent);
            }
        }

        return null;
    }

    private static function seriesName(DOMElement $ser): ?string
    {
        $tx = self::child($ser, 'tx');
        if ($tx === null) {
            return null;
        }
        $v = self::child($tx, 'v');
        if ($v !== null) {
            return $v->textContent;
        }
        $cached = $tx->getElementsByTagNameNS(self::NS_C, 'v')->item(0);

        return $cached?->textContent;
    }

    /**
     * Coloured points (c:dPt): the index and the first sRGB colour inside it,
     * by index; a point with no readable colour is skipped.
     *
     * @return list<array{index: int, color: string}>
     */
    private static function points(DOMElement $ser): array
    {
        $byIndex = [];
        foreach (self::children($ser, 'dPt') as $dPt) {
            $idx = self::val($dPt, 'idx');
            $clr = $dPt->getElementsByTagNameNS(self::NS_A, 'srgbClr')->item(0);
            $color = $clr instanceof DOMElement ? strtoupper($clr->getAttribute('val')) : '';
            if ($idx !== null && ctype_digit($idx) && preg_match('/^[0-9A-F]{6}$/', $color) === 1) {
                $byIndex[(int) $idx] = $color;
            }
        }
        ksort($byIndex);
        $out = [];
        foreach ($byIndex as $index => $color) {
            $out[] = ['index' => $index, 'color' => $color];
        }

        return $out;
    }

    private static function seriesColor(DOMElement $ser): ?string
    {
        $spPr = self::child($ser, 'spPr');
        if ($spPr === null) {
            return null;
        }
        $clr = $spPr->getElementsByTagNameNS(self::NS_A, 'srgbClr')->item(0);
        $val = $clr instanceof DOMElement ? strtoupper($clr->getAttribute('val')) : '';

        return preg_match('/^[0-9A-F]{6}$/', $val) === 1 ? $val : null;
    }
}
