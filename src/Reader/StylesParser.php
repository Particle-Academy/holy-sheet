<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Reader\Format\NumFmtParser;
use HolySheet\Workbook\CellFormat;
use SimpleXMLElement;

/**
 * Parses xl/styles.xml into:
 *   - `numFmts`: id → format code (custom + built-in 0..49)
 *   - `fonts`:   list of {bold, italic, color, size}
 *   - `fills`:   list of {bg color or null}
 *   - `borders`: list of {top,right,bottom,left}
 *   - `cellXfs`: list of {fontId, fillId, borderId, numFmtId, align}
 *
 * The terminal output is `cellXfs[i] → CellFormat`. The reader
 * subsequently looks up each cell's `s="N"` attribute against this
 * indexed array.
 */
final class StylesParser
{
    /**
     * @return list<?CellFormat>  index = xf id; null = no style (unformatted)
     */
    public static function parse(string $stylesXml): array
    {
        $xml = @simplexml_load_string($stylesXml);
        if ($xml === false) return [];

        $numFmts = self::parseNumFmts($xml);
        $fonts = self::parseFonts($xml);
        $fills = self::parseFills($xml);
        $borders = self::parseBorders($xml);

        $xfs = [];
        if ($xml->cellXfs && $xml->cellXfs->xf) {
            foreach ($xml->cellXfs->xf as $xf) {
                $fontId = isset($xf['fontId']) ? (int) $xf['fontId'] : 0;
                $fillId = isset($xf['fillId']) ? (int) $xf['fillId'] : 0;
                $borderId = isset($xf['borderId']) ? (int) $xf['borderId'] : 0;
                $numFmtId = isset($xf['numFmtId']) ? (int) $xf['numFmtId'] : 0;

                $align = null;
                if (isset($xf->alignment) && isset($xf->alignment['horizontal'])) {
                    $align = (string) $xf->alignment['horizontal'];
                }

                $font = $fonts[$fontId] ?? null;
                $fill = $fills[$fillId] ?? null;
                $border = $borders[$borderId] ?? null;
                $customCode = $numFmts[$numFmtId] ?? null;
                $numFmtParsed = $customCode !== null
                    ? NumFmtParser::parse($customCode)
                    : NumFmtParser::parseBuiltin($numFmtId);

                if ($xfs === [] && $font === null && $fill === null && $border === null && !$numFmtParsed && $align === null) {
                    $xfs[] = null;
                    continue;
                }

                $xfs[] = self::buildCellFormat($font, $fill, $border, $numFmtParsed, $align);
            }
        }

        return $xfs;
    }

    private static function parseNumFmts(SimpleXMLElement $xml): array
    {
        $map = [];
        if (isset($xml->numFmts) && $xml->numFmts->numFmt) {
            foreach ($xml->numFmts->numFmt as $nf) {
                $map[(int) $nf['numFmtId']] = (string) $nf['formatCode'];
            }
        }
        return $map;
    }

    /** @return list<array<string,mixed>> */
    private static function parseFonts(SimpleXMLElement $xml): array
    {
        $fonts = [];
        if (isset($xml->fonts) && $xml->fonts->font) {
            foreach ($xml->fonts->font as $f) {
                $fonts[] = [
                    'bold' => isset($f->b),
                    'italic' => isset($f->i),
                    'size' => isset($f->sz) ? (int) (float) $f->sz['val'] : 11,
                    'color' => isset($f->color) && isset($f->color['rgb'])
                        ? self::argbToHex((string) $f->color['rgb'])
                        : null,
                ];
            }
        }
        return $fonts;
    }

    /** @return list<?string>  hex color or null */
    private static function parseFills(SimpleXMLElement $xml): array
    {
        $fills = [];
        if (isset($xml->fills) && $xml->fills->fill) {
            foreach ($xml->fills->fill as $fill) {
                $pattern = isset($fill->patternFill) ? (string) $fill->patternFill['patternType'] : 'none';
                if ($pattern !== 'solid') {
                    $fills[] = null;
                    continue;
                }
                $fg = isset($fill->patternFill->fgColor) && isset($fill->patternFill->fgColor['rgb'])
                    ? self::argbToHex((string) $fill->patternFill->fgColor['rgb'])
                    : null;
                $fills[] = $fg;
            }
        }
        return $fills;
    }

    /** @return list<array<string,?string>> */
    private static function parseBorders(SimpleXMLElement $xml): array
    {
        $borders = [];
        if (isset($xml->borders) && $xml->borders->border) {
            foreach ($xml->borders->border as $b) {
                $rec = ['top' => null, 'right' => null, 'bottom' => null, 'left' => null];
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    if (isset($b->{$side}) && isset($b->{$side}['style']) && (string) $b->{$side}['style'] !== ''
                        && isset($b->{$side}->color) && isset($b->{$side}->color['rgb'])) {
                        $rec[$side] = self::argbToHex((string) $b->{$side}->color['rgb']);
                    }
                }
                $borders[] = $rec;
            }
        }
        return $borders;
    }

    private static function buildCellFormat(?array $font, ?string $fill, ?array $border, ?array $numFmt, ?string $align): CellFormat
    {
        return new CellFormat(
            bold: (bool) ($font['bold'] ?? false),
            italic: (bool) ($font['italic'] ?? false),
            textAlign: $align,
            displayFormat: $numFmt['displayFormat'] ?? null,
            decimals: $numFmt['decimals'] ?? null,
            color: $font['color'] ?? null,
            backgroundColor: $fill,
            fontSize: ($font && ($font['size'] ?? null) !== 11) ? $font['size'] : null,
            borderTop: $border['top'] ?? null,
            borderRight: $border['right'] ?? null,
            borderBottom: $border['bottom'] ?? null,
            borderLeft: $border['left'] ?? null,
            currency: $numFmt['currency'] ?? null,
        );
    }

    private static function argbToHex(string $argb): string
    {
        $argb = strtoupper($argb);
        if (strlen($argb) === 8) return '#'.substr($argb, 2);
        if (strlen($argb) === 6) return '#'.$argb;
        return '#000000';
    }

    private static function buildinFormatCode(int $id): ?string
    {
        return null; // Reserved for future use; NumFmtParser::parseBuiltin handles built-ins directly.
    }
}
