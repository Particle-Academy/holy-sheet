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

        // Charts are numbered across the workbook in sheet order; a sheet's
        // drawing shares its sheet's number, as its vmlDrawing does.
        $firstChart = [];
        $next = 1;
        foreach ($workbook->sheets as $i => $sheet) {
            $firstChart[$i] = $next;
            $next += count($sheet->charts);
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

            if ($sheet->hasComments() || $sheet->charts !== []) {
                $zip->addFromString("xl/worksheets/_rels/sheet{$sheetNum}.xml.rels", $this->sheetRelsXml($sheetNum, $sheet->hasComments(), $sheet->charts !== []));
            }
            if ($sheet->hasComments()) {
                $zip->addFromString("xl/comments{$sheetNum}.xml", $this->commentsXml($sheet));
                $zip->addFromString("xl/drawings/vmlDrawing{$sheetNum}.vml", $this->vmlDrawingXml($sheet));
            }
            if ($sheet->charts !== []) {
                $zip->addFromString("xl/drawings/drawing{$sheetNum}.xml", ChartXml::drawing($sheet->charts, $firstChart[$i]));
                $zip->addFromString("xl/drawings/_rels/drawing{$sheetNum}.xml.rels", ChartXml::drawingRels($sheet->charts, $firstChart[$i]));
                foreach ($sheet->charts as $k => $chart) {
                    $zip->addFromString('xl/charts/chart'.($firstChart[$i] + $k).'.xml', ChartXml::chart($chart, $workbook));
                }
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

        $chartNum = 1;
        foreach ($workbook->sheets as $i => $sheet) {
            if ($sheet->charts === []) {
                continue;
            }
            $n = $i + 1;
            $overrides .= "<Override PartName=\"/xl/drawings/drawing{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.drawing+xml\"/>";
            foreach ($sheet->charts as $_) {
                $overrides .= "<Override PartName=\"/xl/charts/chart{$chartNum}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.drawingml.chart+xml\"/>";
                $chartNum++;
            }
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
            $state = $sheet->state === 'hidden' || $sheet->state === 'veryHidden' ? " state=\"{$sheet->state}\"" : '';
            $sheets .= "<sheet name=\"{$name}\" sheetId=\"{$sheetId}\"{$state} r:id=\"{$rId}\"/>";
        }
        // The active tab must be a visible sheet. It defaults to the first, so
        // only a hidden first sheet needs saying (the validator guarantees at
        // least one visible sheet exists).
        $bookViews = '';
        if (($workbook->sheets[0]->state ?? 'visible') !== 'visible') {
            foreach ($workbook->sheets as $i => $sheet) {
                if ($sheet->state === 'visible') {
                    $bookViews = '<bookViews><workbookView firstSheet="'.$i.'" activeTab="'.$i.'"/></bookViews>';
                    break;
                }
            }
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$bookViews
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

    /**
     * A sheet's rels. Comments keep rId1 (vml) and rId2 (comments), as they
     * always have; the chart drawing takes the next id.
     */
    private function sheetRelsXml(int $sheetNum, bool $comments, bool $charts): string
    {
        $rels = '';
        if ($comments) {
            $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/vmlDrawing" Target="../drawings/vmlDrawing'.$sheetNum.'.vml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments" Target="../comments'.$sheetNum.'.xml"/>';
        }
        if ($charts) {
            $rels .= '<Relationship Id="'.self::drawingRelId($comments).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing'.$sheetNum.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels
            .'</Relationships>';
    }

    private static function drawingRelId(bool $comments): string
    {
        return $comments ? 'rId3' : 'rId1';
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

        // One <col> per sized or hidden column, ascending (CT_Cols requires the
        // ranges in order and non-overlapping). A hidden column without a width
        // keeps Excel's default width, so un-hiding it shows a normal column.
        $hiddenCols = array_flip($sheet->hiddenColumns);
        $colIndexes = array_unique(array_merge(array_keys($sheet->columnWidths), $sheet->hiddenColumns));
        sort($colIndexes);
        $colsXml = '';
        if ($colIndexes !== []) {
            $colsXml = '<cols>';
            foreach ($colIndexes as $colIdx) {
                $colNum = $colIdx + 1;
                $px = $sheet->columnWidths[$colIdx] ?? null;
                $width = $px !== null
                    ? number_format(max(1.0, ($px - 5) / 7), 4, '.', '').'" customWidth="1'
                    : '8.43';
                $colsXml .= '<col min="'.$colNum.'" max="'.$colNum.'" width="'.$width.'"'.(isset($hiddenCols[$colIdx]) ? ' hidden="1"' : '').'/>';
            }
            $colsXml .= '</cols>';
        }

        // Rows with cells, rows with only a height, and hidden rows, in row
        // order. A height is written in points (pixels x 0.75), as Excel stores it.
        $rows = $sheet->rows();
        foreach (array_keys($sheet->rowHeights) as $rowIndex) {
            $rows[$rowIndex] ??= [];
        }
        $hiddenRows = array_flip($sheet->hiddenRows);
        foreach ($sheet->hiddenRows as $rowIndex) {
            $rows[$rowIndex] ??= [];
        }
        ksort($rows);
        $rowsXml = '';
        foreach ($rows as $rowIndex => $row) {
            $cellsXml = '';
            ksort($row);
            foreach ($row as $col => $cell) {
                $cellsXml .= $this->cellXml($cell, $styles);
            }
            $ht = isset($sheet->rowHeights[$rowIndex])
                ? ' ht="'.self::decimal($sheet->rowHeights[$rowIndex] * 0.75).'" customHeight="1"'
                : '';
            if (isset($hiddenRows[$rowIndex])) {
                $ht .= ' hidden="1"';
            }
            $rowsXml .= $cellsXml === '' ? "<row r=\"{$rowIndex}\"{$ht}/>" : "<row r=\"{$rowIndex}\"{$ht}>{$cellsXml}</row>";
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

        // Print setup. CT_Worksheet orders these: sheetPr first of all, then
        // pageSetup and headerFooter after mergeCells and before legacyDrawing.
        $print = $sheet->print;
        $fits = ($print['fitToWidth'] ?? false) || ($print['fitToPage'] ?? false);
        $sheetPrXml = $fits ? '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>' : '';
        $pageSetupXml = '';
        if ($print !== null && ($print['orientation'] !== null || $fits)) {
            $pageSetupXml = '<pageSetup'
                .($print['orientation'] !== null ? ' orientation="'.$print['orientation'].'"' : '')
                // fitToPage: one page wide AND tall. fitToWidth: one wide, as tall as it needs.
                .($print['fitToPage'] ? ' fitToWidth="1" fitToHeight="1"' : ($print['fitToWidth'] ? ' fitToWidth="1" fitToHeight="0"' : ''))
                .'/>';
        }
        $headerFooterXml = ($print['footer'] ?? null) !== null
            ? '<headerFooter><oddFooter>'.$this->escape(self::footerCodes($print['footer'])).'</oddFooter></headerFooter>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$sheetPrXml
            .$sheetViewsXml
            .$colsXml
            ."<sheetData>{$rowsXml}</sheetData>"
            .$mergesXml
            .$pageSetupXml
            .$headerFooterXml
            .($sheet->charts !== [] ? '<drawing r:id="'.self::drawingRelId($sheet->hasComments()).'"/>' : '')
            .$legacyDrawing
            .'</worksheet>';
    }

    /**
     * A footer as Excel's header/footer codes: &L / &C / &R start the three
     * sections, &P is the page number and &N the page count, and a literal "&"
     * is written "&&" so it cannot start a code.
     *
     * @param  array{left: string, center: string, right: string}  $footer
     */
    public static function footerCodes(array $footer): string
    {
        $codes = '';
        foreach (['left' => '&L', 'center' => '&C', 'right' => '&R'] as $slot => $code) {
            if ($footer[$slot] !== '') {
                $codes .= $code.str_replace(['{pages}', '{page}'], ['&N', '&P'], str_replace('&', '&&', $footer[$slot]));
            }
        }

        return $codes;
    }

    /** A number as the shortest decimal: 24.0 -> "24", 15.75 -> "15.75". */
    private static function decimal(float $value): string
    {
        $out = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $out === '' || $out === '-0' ? '0' : $out;
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

        $value = $cell->value;

        // NAN/INF have no `<v>` representation -- "NAN" in a cell is a corrupt
        // sheet, not a big number. The Node port makes the same substitution.
        if (is_float($value) && ! is_finite($value)) {
            $value = 0;
        }

        $v = is_float($value)
            ? rtrim(rtrim(number_format($value, 14, '.', ''), '0'), '.')
            : (string) $value;
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
