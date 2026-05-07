<?php

declare(strict_types=1);

namespace HolySheet\Writer;

use HolySheet\Workbook\Cell;
use HolySheet\Workbook\CellAddress;
use HolySheet\Workbook\Sheet;
use HolySheet\Workbook\Workbook;
use RuntimeException;
use ZipArchive;

/**
 * XLSX writer — emits a complete OOXML SpreadsheetML package.
 *
 * Holy Sheet 1.0 surface:
 *   - Multiple sheets with inline-string text cells
 *   - styles.xml (deduped fonts/fills/borders/numFmts/xfs)
 *   - Merged cells, column widths, frozen rows/cols
 *   - Comments via comments1.xml + vmlDrawing1.vml
 *   - Formulas with optional cached values
 *   - Symbolic totals (resolved during normalization)
 */
final class XlsxWriter
{
    public function write(Workbook $workbook, string $path): void
    {
        $bytes = $this->toBytes($workbook);
        $written = file_put_contents($path, $bytes);
        if ($written === false) {
            throw new RuntimeException("[holy-sheet] failed to write {$path}");
        }
    }

    public function toBytes(Workbook $workbook): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'holy-sheet-');
        if ($tmp === false) {
            throw new RuntimeException('[holy-sheet] failed to allocate temp file');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('[holy-sheet] failed to open zip archive for writing');
        }

        $styles = new StylesRegistry();

        // Pre-render sheet xml so styles register before we serialize styles.xml
        $sheetXmls = [];
        foreach ($workbook->sheets as $i => $sheet) {
            $sheetXmls[$i] = $this->sheetXml($sheet, $styles);
        }

        $sheetsWithComments = [];
        foreach ($workbook->sheets as $i => $sheet) {
            if ($sheet->hasComments()) $sheetsWithComments[] = $i;
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml($workbook, $sheetsWithComments));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($workbook));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml($workbook));
        $zip->addFromString('xl/styles.xml', $styles->toXml());
        $zip->addFromString('docProps/core.xml', $this->coreXml($workbook));
        $zip->addFromString('docProps/app.xml', $this->appXml($workbook));

        foreach ($workbook->sheets as $i => $sheet) {
            $sheetNum = $i + 1;
            $zip->addFromString("xl/worksheets/sheet{$sheetNum}.xml", $sheetXmls[$i]);

            if ($sheet->hasComments()) {
                $zip->addFromString("xl/worksheets/_rels/sheet{$sheetNum}.xml.rels", $this->sheetRelsXml($sheetNum));
                $zip->addFromString("xl/comments{$sheetNum}.xml", $this->commentsXml($sheet));
                $zip->addFromString("xl/drawings/vmlDrawing{$sheetNum}.vml", $this->vmlDrawingXml($sheet));
            }
        }

        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        if ($bytes === false) {
            throw new RuntimeException('[holy-sheet] failed to read assembled archive');
        }
        return $bytes;
    }

    /** @param  list<int>  $sheetsWithComments */
    private function contentTypesXml(Workbook $workbook, array $sheetsWithComments): string
    {
        $overrides = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';

        foreach (array_keys($workbook->sheets) as $i) {
            $n = $i + 1;
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }

        foreach ($sheetsWithComments as $i) {
            $n = $i + 1;
            $overrides .= "<Override PartName=\"/xl/comments{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.comments+xml\"/>";
        }

        $defaults = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>';
        if ($sheetsWithComments !== []) {
            $defaults .= '<Default Extension="vml" ContentType="application/vnd.openxmlformats-officedocument.vmlDrawing"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .$defaults.$overrides
            .'</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(Workbook $workbook): string
    {
        $sheets = '';
        foreach ($workbook->sheets as $i => $sheet) {
            $name = $this->escape($sheet->name);
            $sheetId = $i + 1;
            $rId = 'rId'.($i + 1);
            $sheets .= "<sheet name=\"{$name}\" sheetId=\"{$sheetId}\" r:id=\"{$rId}\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            ."<sheets>{$sheets}</sheets>"
            .'</workbook>';
    }

    private function workbookRelsXml(Workbook $workbook): string
    {
        $rels = '';
        foreach (array_keys($workbook->sheets) as $i) {
            $rId = 'rId'.($i + 1);
            $rels .= '<Relationship Id="'.$rId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
        }
        $rels .= '<Relationship Id="rId'.(count($workbook->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels.'</Relationships>';
    }

    private function sheetRelsXml(int $sheetNum): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/vmlDrawing" Target="../drawings/vmlDrawing'.$sheetNum.'.vml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments" Target="../comments'.$sheetNum.'.xml"/>'
            .'</Relationships>';
    }

    private function sheetXml(Sheet $sheet, StylesRegistry $styles): string
    {
        $sheetViewsXml = '';
        if ($sheet->frozenRows > 0 || $sheet->frozenCols > 0) {
            $topLeft = CellAddress::letter($sheet->frozenCols).($sheet->frozenRows + 1);
            $pane = '<pane'
                .($sheet->frozenCols > 0 ? ' xSplit="'.$sheet->frozenCols.'"' : '')
                .($sheet->frozenRows > 0 ? ' ySplit="'.$sheet->frozenRows.'"' : '')
                .' topLeftCell="'.$topLeft.'" activePane="bottomRight" state="frozen"/>';
            $sheetViewsXml = '<sheetViews><sheetView workbookViewId="0">'.$pane.'</sheetView></sheetViews>';
        }

        $colsXml = '';
        if ($sheet->columnWidths !== []) {
            $colsXml = '<cols>';
            foreach ($sheet->columnWidths as $colIdx => $px) {
                $excelWidth = max(1.0, ($px - 5) / 7);
                $colNum = $colIdx + 1;
                $colsXml .= '<col min="'.$colNum.'" max="'.$colNum.'" width="'.number_format($excelWidth, 4, '.', '').'" customWidth="1"/>';
            }
            $colsXml .= '</cols>';
        }

        $rowsXml = '';
        foreach ($sheet->rows() as $rowIndex => $row) {
            $cellsXml = '';
            ksort($row);
            foreach ($row as $col => $cell) {
                $cellsXml .= $this->cellXml($cell, $styles);
            }
            $rowsXml .= "<row r=\"{$rowIndex}\">{$cellsXml}</row>";
        }

        $mergesXml = '';
        if ($sheet->mergedRegions !== []) {
            $mergesXml = '<mergeCells count="'.count($sheet->mergedRegions).'">';
            foreach ($sheet->mergedRegions as $merge) {
                $mergesXml .= '<mergeCell ref="'.$merge->ref().'"/>';
            }
            $mergesXml .= '</mergeCells>';
        }

        $legacyDrawing = $sheet->hasComments() ? '<legacyDrawing r:id="rId1"/>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$sheetViewsXml
            .$colsXml
            ."<sheetData>{$rowsXml}</sheetData>"
            .$mergesXml
            .$legacyDrawing
            .'</worksheet>';
    }

    private function cellXml(Cell $cell, StylesRegistry $styles): string
    {
        $ref = $cell->address;
        $type = $cell->excelType();
        $styleIdx = $styles->register($cell->format);
        $sAttr = $styleIdx > 0 ? " s=\"{$styleIdx}\"" : '';

        if ($cell->formula !== null) {
            $f = $this->escape(ltrim($cell->formula, '='));
            $cached = $cell->cachedValue ?? $cell->value;
            $v = $cached !== null ? '<v>'.$this->escape((string) $cached).'</v>' : '';
            return "<c r=\"{$ref}\"{$sAttr}><f>{$f}</f>{$v}</c>";
        }

        if ($type === 'inlineStr') {
            $t = $this->escape((string) $cell->value);
            return "<c r=\"{$ref}\"{$sAttr} t=\"inlineStr\"><is><t xml:space=\"preserve\">{$t}</t></is></c>";
        }

        if ($type === 'b') {
            $v = $cell->value === true ? '1' : '0';
            return "<c r=\"{$ref}\"{$sAttr} t=\"b\"><v>{$v}</v></c>";
        }

        if ($cell->value === null) {
            return "<c r=\"{$ref}\"{$sAttr}/>";
        }

        $v = is_float($cell->value)
            ? rtrim(rtrim(number_format($cell->value, 14, '.', ''), '0'), '.')
            : (string) $cell->value;
        if ($v === '') $v = '0';
        return "<c r=\"{$ref}\"{$sAttr}><v>{$v}</v></c>";
    }

    private function commentsXml(Sheet $sheet): string
    {
        $authors = [];
        $commentList = '';
        foreach ($sheet->comments() as $entry) {
            $address = $entry['address'];
            $comment = $entry['comment'];
            $author = $comment->author ?? 'Author';
            if (!in_array($author, $authors, true)) $authors[] = $author;
            $authorIdx = array_search($author, $authors, true);
            $text = $this->escape($comment->text);
            $commentList .= '<comment ref="'.$address.'" authorId="'.$authorIdx.'">'
                .'<text><r><t xml:space="preserve">'.$text.'</t></r></text>'
                .'</comment>';
        }
        $authorsXml = '<authors>';
        foreach ($authors as $a) {
            $authorsXml .= '<author>'.$this->escape($a).'</author>';
        }
        $authorsXml .= '</authors>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<comments xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .$authorsXml
            .'<commentList>'.$commentList.'</commentList>'
            .'</comments>';
    }

    private function vmlDrawingXml(Sheet $sheet): string
    {
        $shapes = '';
        $shapeId = 1024;
        foreach ($sheet->comments() as $entry) {
            $address = $entry['address'];
            preg_match('/^([A-Z]+)(\d+)$/', $address, $m);
            $col = CellAddress::index($m[1]);
            $row = (int) $m[2] - 1;
            $shapeId++;
            $shapes .= '<v:shape id="_x0000_s'.$shapeId.'" type="#_x0000_t202" '
                .'style="position:absolute;margin-left:60pt;margin-top:5pt;width:108pt;height:60pt;z-index:1;visibility:hidden" '
                .'fillcolor="#ffffe1" o:insetmode="auto">'
                .'<v:fill color2="#ffffe1"/>'
                .'<v:shadow on="t" color="black" obscured="t"/>'
                .'<v:path o:connecttype="none"/>'
                .'<v:textbox><div style="text-align:left"></div></v:textbox>'
                .'<x:ClientData ObjectType="Note">'
                .'<x:MoveWithCells/>'
                .'<x:SizeWithCells/>'
                .'<x:Anchor>'.($col + 1).', 15, '.$row.', 10, '.($col + 3).', 31, '.($row + 4).', 18</x:Anchor>'
                .'<x:AutoFill>False</x:AutoFill>'
                .'<x:Row>'.$row.'</x:Row>'
                .'<x:Column>'.$col.'</x:Column>'
                .'</x:ClientData>'
                .'</v:shape>';
        }
        return '<xml xmlns:v="urn:schemas-microsoft-com:vml" '
            .'xmlns:o="urn:schemas-microsoft-com:office:office" '
            .'xmlns:x="urn:schemas-microsoft-com:office:excel">'
            .'<o:shapelayout v:ext="edit"><o:idmap v:ext="edit" data="1"/></o:shapelayout>'
            .'<v:shapetype id="_x0000_t202" coordsize="21600,21600" o:spt="202" path="m,l,21600r21600,l21600,xe">'
            .'<v:stroke joinstyle="miter"/><v:path gradientshapeok="t" o:connecttype="rect"/></v:shapetype>'
            .$shapes
            .'</xml>';
    }

    private function coreXml(Workbook $workbook): string
    {
        $now = ($workbook->meta['created'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $creator = $this->escape((string) ($workbook->meta['creator'] ?? 'Holy Sheet'));
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            ."<dc:creator>{$creator}</dc:creator>"
            ."<cp:lastModifiedBy>{$creator}</cp:lastModifiedBy>"
            ."<dcterms:created xsi:type=\"dcterms:W3CDTF\">{$now}</dcterms:created>"
            ."<dcterms:modified xsi:type=\"dcterms:W3CDTF\">{$now}</dcterms:modified>"
            .'</cp:coreProperties>';
    }

    private function appXml(Workbook $workbook): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>Holy Sheet</Application>'
            .'<DocSecurity>0</DocSecurity>'
            .'<ScaleCrop>false</ScaleCrop>'
            .'<SharedDoc>false</SharedDoc>'
            .'<HyperlinksChanged>false</HyperlinksChanged>'
            .'<AppVersion>1.0</AppVersion>'
            .'</Properties>';
    }

    private function escape(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

}
