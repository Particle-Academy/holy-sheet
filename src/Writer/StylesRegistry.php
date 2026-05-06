<?php

declare(strict_types=1);

namespace HolySheet\Writer;

use HolySheet\Workbook\CellFormat;
use HolySheet\Writer\Format\NumFmtBuilder;

/**
 * Deduplicates the four Excel style sub-records — fonts, fills, borders,
 * numFmts — and the cellXfs that combine them.
 *
 * Excel's style model is awkward: every distinct combination of font +
 * fill + border + numFmt + alignment is a single `<xf>` record, and
 * cells reference `xf` by index (`<c s="3">`). Without dedup, every
 * cell with formatting bloats styles.xml linearly with row count.
 *
 * The default (style index 0) is intentionally the "no formatting" xf,
 * matching Excel's expectation that index 0 is the unformatted base.
 */
final class StylesRegistry
{
    /** @var array<string,int> formatKey → xf index */
    private array $xfIndex = ['__default__' => 0];

    /** @var list<array<string,mixed>> raw xf descriptors */
    private array $xfs = [[
        'fontId' => 0, 'fillId' => 0, 'borderId' => 0, 'numFmtId' => 0, 'align' => null,
    ]];

    /** @var array<string,int> font key → id */
    private array $fontIndex = ['__default__' => 0];
    /** @var list<array<string,mixed>> */
    private array $fonts = [['size' => 11, 'name' => 'Calibri', 'color' => null, 'bold' => false, 'italic' => false]];

    /** @var array<string,int> fill key → id */
    private array $fillIndex = ['__none__' => 0, '__gray125__' => 1];
    /** @var list<array<string,mixed>> */
    private array $fills = [
        ['type' => 'none'],
        ['type' => 'gray125'],
    ];

    /** @var array<string,int> border key → id */
    private array $borderIndex = ['__default__' => 0];
    /** @var list<array<string,mixed>> */
    private array $borders = [['top' => null, 'right' => null, 'bottom' => null, 'left' => null]];

    /** @var array<string,int> numFmt code → id (custom ids start at 164) */
    private array $numFmtIndex = [];
    /** @var array<int,string> id → code */
    private array $numFmts = [];

    private int $nextNumFmtId = 164;

    /** Register a CellFormat and return the xf style index Excel will reference. */
    public function register(?CellFormat $format): int
    {
        if ($format === null || $format->isEmpty()) return 0;

        $key = $format->key();
        if (isset($this->xfIndex[$key])) return $this->xfIndex[$key];

        $fontId = $this->fontFor($format);
        $fillId = $this->fillFor($format);
        $borderId = $this->borderFor($format);
        $numFmtId = $this->numFmtFor($format);

        $this->xfs[] = [
            'fontId' => $fontId,
            'fillId' => $fillId,
            'borderId' => $borderId,
            'numFmtId' => $numFmtId,
            'align' => $format->textAlign,
        ];
        $idx = count($this->xfs) - 1;
        $this->xfIndex[$key] = $idx;
        return $idx;
    }

    private function fontFor(CellFormat $f): int
    {
        $rec = [
            'size' => $f->fontSize ?? 11,
            'name' => 'Calibri',
            'color' => $f->color,
            'bold' => $f->bold,
            'italic' => $f->italic,
        ];
        $key = md5(serialize($rec));
        if (isset($this->fontIndex[$key])) return $this->fontIndex[$key];
        $this->fonts[] = $rec;
        $idx = count($this->fonts) - 1;
        $this->fontIndex[$key] = $idx;
        return $idx;
    }

    private function fillFor(CellFormat $f): int
    {
        if ($f->backgroundColor === null) return 0;
        $key = strtoupper($f->backgroundColor);
        if (isset($this->fillIndex[$key])) return $this->fillIndex[$key];
        $this->fills[] = ['type' => 'solid', 'fg' => $key];
        $idx = count($this->fills) - 1;
        $this->fillIndex[$key] = $idx;
        return $idx;
    }

    private function borderFor(CellFormat $f): int
    {
        if (!$f->borderTop && !$f->borderRight && !$f->borderBottom && !$f->borderLeft) {
            return 0;
        }
        $rec = [
            'top' => $f->borderTop, 'right' => $f->borderRight,
            'bottom' => $f->borderBottom, 'left' => $f->borderLeft,
        ];
        $key = md5(serialize($rec));
        if (isset($this->borderIndex[$key])) return $this->borderIndex[$key];
        $this->borders[] = $rec;
        $idx = count($this->borders) - 1;
        $this->borderIndex[$key] = $idx;
        return $idx;
    }

    private function numFmtFor(CellFormat $f): int
    {
        $code = NumFmtBuilder::build($f);
        if ($code === null) return 0;
        if (isset($this->numFmtIndex[$code])) return $this->numFmtIndex[$code];
        $id = $this->nextNumFmtId++;
        $this->numFmts[$id] = $code;
        $this->numFmtIndex[$code] = $id;
        return $id;
    }

    public function toXml(): string
    {
        $numFmtsXml = '';
        if ($this->numFmts !== []) {
            $records = '';
            foreach ($this->numFmts as $id => $code) {
                $records .= '<numFmt numFmtId="'.$id.'" formatCode="'.htmlspecialchars($code, ENT_XML1 | ENT_QUOTES, 'UTF-8').'"/>';
            }
            $numFmtsXml = '<numFmts count="'.count($this->numFmts).'">'.$records.'</numFmts>';
        }

        $fontsXml = '<fonts count="'.count($this->fonts).'">';
        foreach ($this->fonts as $f) {
            $fontsXml .= '<font>';
            $fontsXml .= '<sz val="'.((float) $f['size']).'"/>';
            if (!empty($f['bold'])) $fontsXml .= '<b/>';
            if (!empty($f['italic'])) $fontsXml .= '<i/>';
            if (!empty($f['color'])) {
                $fontsXml .= '<color rgb="'.self::hexToArgb($f['color']).'"/>';
            }
            $fontsXml .= '<name val="'.htmlspecialchars((string) $f['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'"/>';
            $fontsXml .= '</font>';
        }
        $fontsXml .= '</fonts>';

        $fillsXml = '<fills count="'.count($this->fills).'">';
        foreach ($this->fills as $fill) {
            if (($fill['type'] ?? '') === 'none') {
                $fillsXml .= '<fill><patternFill patternType="none"/></fill>';
            } elseif (($fill['type'] ?? '') === 'gray125') {
                $fillsXml .= '<fill><patternFill patternType="gray125"/></fill>';
            } else {
                $fillsXml .= '<fill><patternFill patternType="solid"><fgColor rgb="'.self::hexToArgb($fill['fg']).'"/></patternFill></fill>';
            }
        }
        $fillsXml .= '</fills>';

        $bordersXml = '<borders count="'.count($this->borders).'">';
        foreach ($this->borders as $b) {
            $bordersXml .= '<border>';
            foreach (['left', 'right', 'top', 'bottom'] as $side) {
                if (empty($b[$side])) {
                    $bordersXml .= "<{$side}/>";
                } else {
                    $bordersXml .= "<{$side} style=\"thin\"><color rgb=\"".self::hexToArgb($b[$side])."\"/></{$side}>";
                }
            }
            $bordersXml .= '<diagonal/></border>';
        }
        $bordersXml .= '</borders>';

        $cellXfsXml = '<cellXfs count="'.count($this->xfs).'">';
        foreach ($this->xfs as $xf) {
            $apply = '';
            if ($xf['fontId'] > 0) $apply .= ' applyFont="1"';
            if ($xf['fillId'] > 0) $apply .= ' applyFill="1"';
            if ($xf['borderId'] > 0) $apply .= ' applyBorder="1"';
            if ($xf['numFmtId'] > 0) $apply .= ' applyNumberFormat="1"';
            if ($xf['align']) $apply .= ' applyAlignment="1"';
            $cellXfsXml .= '<xf numFmtId="'.$xf['numFmtId'].'" fontId="'.$xf['fontId'].'" fillId="'.$xf['fillId'].'" borderId="'.$xf['borderId'].'" xfId="0"'.$apply.'>';
            if ($xf['align']) {
                $cellXfsXml .= '<alignment horizontal="'.htmlspecialchars($xf['align'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'"/>';
            }
            $cellXfsXml .= '</xf>';
        }
        $cellXfsXml .= '</cellXfs>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .$numFmtsXml
            .$fontsXml
            .$fillsXml
            .$bordersXml
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .$cellXfsXml
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
            .'</styleSheet>';
    }

    /** Convert "#RRGGBB" → "FFRRGGBB" (Excel uses ARGB). */
    private static function hexToArgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 6) return 'FF'.strtoupper($hex);
        if (strlen($hex) === 8) return strtoupper($hex);
        return 'FF000000';
    }
}
