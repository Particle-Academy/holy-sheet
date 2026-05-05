<?php

declare(strict_types=1);

namespace HolySheet\Writer;

use HolySheet\Workbook\Cell;
use HolySheet\Workbook\Sheet;
use HolySheet\Workbook\Workbook;
use RuntimeException;
use ZipArchive;

/**
 * XLSX writer — produces a minimum-viable OOXML SpreadsheetML package.
 *
 * v0.2 scope (matches the phase plan):
 *   - Multiple sheets with inline-string text cells
 *   - Numeric, boolean, formula cells
 *   - The five mandatory package files: [Content_Types].xml,
 *     _rels/.rels, xl/workbook.xml, xl/_rels/workbook.xml.rels,
 *     xl/worksheets/sheetN.xml
 *   - docProps/core.xml + docProps/app.xml for Office's strict expectations
 *
 * No styles, no shared strings, no merged cells, no comments yet —
 * those land in 0.3 → 0.7 per the phase plan.
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

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml($workbook));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($workbook));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml($workbook));
        $zip->addFromString('docProps/core.xml', $this->coreXml($workbook));
        $zip->addFromString('docProps/app.xml', $this->appXml($workbook));

        foreach ($workbook->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $this->sheetXml($sheet));
        }

        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        if ($bytes === false) {
            throw new RuntimeException('[holy-sheet] failed to read assembled archive');
        }
        return $bytes;
    }

    private function contentTypesXml(Workbook $workbook): string
    {
        $overrides = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';

        foreach (array_keys($workbook->sheets) as $i) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .$overrides
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
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels
            .'</Relationships>';
    }

    private function sheetXml(Sheet $sheet): string
    {
        $rowsXml = '';
        foreach ($sheet->rows() as $rowIndex => $row) {
            $cellsXml = '';
            ksort($row);
            foreach ($row as $col => $cell) {
                $cellsXml .= $this->cellXml($cell);
            }
            $rowsXml .= "<row r=\"{$rowIndex}\">{$cellsXml}</row>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            ."<sheetData>{$rowsXml}</sheetData>"
            .'</worksheet>';
    }

    private function cellXml(Cell $cell): string
    {
        $ref = $cell->address;
        $type = $cell->excelType();

        if ($cell->formula !== null) {
            // Formulas without cached values let Excel evaluate on open.
            $f = $this->escape(ltrim($cell->formula, '='));
            $v = $cell->value !== null ? '<v>'.$this->escape((string) $cell->value).'</v>' : '';
            return "<c r=\"{$ref}\"><f>{$f}</f>{$v}</c>";
        }

        if ($type === 'inlineStr') {
            $t = $this->escape((string) $cell->value);
            return "<c r=\"{$ref}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">{$t}</t></is></c>";
        }

        if ($type === 'b') {
            $v = $cell->value === true ? '1' : '0';
            return "<c r=\"{$ref}\" t=\"b\"><v>{$v}</v></c>";
        }

        if ($cell->value === null) {
            return "<c r=\"{$ref}\"/>";
        }

        // Numeric default — int or float
        $v = is_float($cell->value) ? rtrim(rtrim(number_format($cell->value, 14, '.', ''), '0'), '.') : (string) $cell->value;
        if ($v === '') {
            $v = '0';
        }
        return "<c r=\"{$ref}\"><v>{$v}</v></c>";
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
        // XML-safe + drop control chars that are illegal in xlsx (Excel rejects \x01..\x08 etc.)
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
