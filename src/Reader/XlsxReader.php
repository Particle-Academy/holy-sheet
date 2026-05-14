<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Reader\Format\DateInverter;
use HolySheet\Workbook\Cell;
use HolySheet\Workbook\CellComment;
use HolySheet\Workbook\CellFormat;
use HolySheet\Workbook\MergedRegion;
use HolySheet\Workbook\Sheet;
use HolySheet\Workbook\Workbook;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * XLSX reader — orchestrates the full read path.
 *
 * Walks the OOXML package via ZipArchive, parses parts in dependency
 * order (rels → styles → comments → worksheets), and assembles a
 * `Workbook` value object that mirrors what `Schema\Normalizer` would
 * produce. The terminal output (after `describe()`) is a Holy Sheet
 * schema array equivalent to the original input — round-trip safe for
 * every feature documented in `docs/ReadPath.md`.
 */
final class XlsxReader
{
    /**
     * Read an xlsx file and return a Holy Sheet schema array.
     *
     * @return array<string,mixed>
     */
    public function describe(string $path): array
    {
        $workbook = $this->readWorkbook($path);
        return $this->workbookToSchema($workbook);
    }

    public function readWorkbook(string $path): Workbook
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("[holy-sheet] cannot open {$path} as a zip archive");
        }

        $stylesXml = $zip->getFromName('xl/styles.xml');
        $stylesIndex = is_string($stylesXml) ? StylesParser::parse($stylesXml) : [];

        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        $sharedStrings = is_string($sharedStringsXml) ? SharedStringsParser::parse($sharedStringsXml) : [];

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false) {
            $zip->close();
            throw new RuntimeException("[holy-sheet] missing xl/workbook.xml in {$path}");
        }

        $workbookRelsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $workbookRels = is_string($workbookRelsXml) ? RelsParser::parse($workbookRelsXml) : [];

        $sheetsXml = @simplexml_load_string($workbookXml);
        if ($sheetsXml === false) {
            $zip->close();
            throw new RuntimeException("[holy-sheet] failed to parse xl/workbook.xml in {$path}");
        }

        $sheets = [];
        if (isset($sheetsXml->sheets) && $sheetsXml->sheets->sheet) {
            $i = 0;
            foreach ($sheetsXml->sheets->sheet as $sheetEl) {
                $name = (string) $sheetEl['name'];
                $rId = (string) $sheetEl->attributes('r', true)->id;
                $target = $workbookRels[$rId]['Target'] ?? null;
                if ($target === null) continue;

                $sheetPath = 'xl/'.ltrim($target, '/');
                $worksheetXml = $zip->getFromName($sheetPath);
                if ($worksheetXml === false) continue;

                // Comments — sheet-specific rels file points to commentsN.xml
                $sheetNum = $i + 1;
                $sheetRelsPath = "xl/worksheets/_rels/sheet{$sheetNum}.xml.rels";
                $sheetRelsXml = $zip->getFromName($sheetRelsPath);
                $comments = [];
                if (is_string($sheetRelsXml)) {
                    $sheetRels = RelsParser::parse($sheetRelsXml);
                    $commentRels = RelsParser::byType($sheetRels, '/comments');
                    foreach ($commentRels as $cr) {
                        $commentsTarget = $cr['Target'];
                        $commentsPath = self::resolveRelativePath($sheetPath, $commentsTarget);
                        $commentsXml = $zip->getFromName($commentsPath);
                        if (is_string($commentsXml)) {
                            $comments = array_replace($comments, CommentsParser::parse($commentsXml));
                        }
                    }
                }

                $sheets[] = WorksheetParser::parse($worksheetXml, $name, $stylesIndex, $comments, $sharedStrings);
                $i++;
            }
        }

        $meta = $this->parseDocProps($zip);
        $zip->close();

        return new Workbook($sheets, $meta);
    }

    /** @return array<string,mixed> */
    private function parseDocProps(ZipArchive $zip): array
    {
        $coreXml = $zip->getFromName('docProps/core.xml');
        if ($coreXml === false) return [];

        // SimpleXML strips namespaces awkwardly; use children() with namespace URI for safety.
        $core = @simplexml_load_string($coreXml);
        if ($core === false) return [];

        $meta = [];
        $dc = $core->children('http://purl.org/dc/elements/1.1/');
        if (isset($dc->creator)) {
            $meta['creator'] = (string) $dc->creator;
        }
        $dcterms = $core->children('http://purl.org/dc/terms/');
        if (isset($dcterms->created)) {
            $meta['created'] = (string) $dcterms->created;
        }
        return $meta;
    }

    /**
     * Convert a `Workbook` back to a Holy Sheet schema array.
     *
     * @return array<string,mixed>
     */
    private function workbookToSchema(Workbook $workbook): array
    {
        $schema = ['sheets' => []];
        foreach ($workbook->sheets as $sheet) {
            $schema['sheets'][] = $this->sheetToSchema($sheet);
        }
        if ($workbook->meta !== []) {
            $schema['meta'] = $workbook->meta;
        }
        return $schema;
    }

    /** @return array<string,mixed> */
    private function sheetToSchema(Sheet $sheet): array
    {
        $cells = [];
        foreach ($sheet->cells as $address => $cell) {
            $cellSchema = $this->cellToSchema($cell);
            // Drop wholly-empty cells.
            if ($cellSchema === null) continue;
            $cells[$address] = $cellSchema;
        }

        $out = [
            'name' => $sheet->name,
            'cells' => $cells,
        ];
        if ($sheet->mergedRegions !== []) {
            $out['mergedRegions'] = array_map(
                fn (MergedRegion $m) => ['start' => $m->start, 'end' => $m->end],
                $sheet->mergedRegions,
            );
        }
        if ($sheet->columnWidths !== []) {
            $out['columnWidths'] = $sheet->columnWidths;
        }
        if ($sheet->frozenRows > 0) $out['frozenRows'] = $sheet->frozenRows;
        if ($sheet->frozenCols > 0) $out['frozenCols'] = $sheet->frozenCols;
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function cellToSchema(Cell $cell): ?array
    {
        $value = $cell->value;
        $format = $cell->format;

        // Convert serial dates back to ISO strings when the format flags it as a date.
        if ($format !== null && in_array($format->displayFormat, ['date', 'datetime'], true)
            && is_numeric($value)) {
            $value = DateInverter::toIso((float) $value, $format->displayFormat === 'datetime');
        }

        $out = ['value' => $value];
        if ($cell->formula !== null) $out['formula'] = $cell->formula;
        if ($cell->cachedValue !== null) $out['computedValue'] = $cell->cachedValue;
        if ($format !== null && !$format->isEmpty()) {
            $out['format'] = $this->formatToArray($format);
        }
        if ($cell->comment !== null) {
            $out['comment'] = $this->commentToArray($cell->comment);
        }

        // Strip null-only cells: { value: null } with no formula/format/comment is equivalent to absent.
        if ($out === ['value' => null]) return null;
        return $out;
    }

    /** @return array<string,mixed> */
    private function formatToArray(CellFormat $f): array
    {
        $out = [];
        if ($f->bold) $out['bold'] = true;
        if ($f->italic) $out['italic'] = true;
        if ($f->textAlign !== null) $out['textAlign'] = $f->textAlign;
        if ($f->displayFormat !== null) $out['displayFormat'] = $f->displayFormat;
        if ($f->decimals !== null) $out['decimals'] = $f->decimals;
        if ($f->color !== null) $out['color'] = $f->color;
        if ($f->backgroundColor !== null) $out['backgroundColor'] = $f->backgroundColor;
        if ($f->fontSize !== null) $out['fontSize'] = $f->fontSize;
        if ($f->borderTop !== null) $out['borderTop'] = $f->borderTop;
        if ($f->borderRight !== null) $out['borderRight'] = $f->borderRight;
        if ($f->borderBottom !== null) $out['borderBottom'] = $f->borderBottom;
        if ($f->borderLeft !== null) $out['borderLeft'] = $f->borderLeft;
        if ($f->currency !== null) $out['currency'] = $f->currency;
        return $out;
    }

    /** @return array<string,mixed> */
    private function commentToArray(CellComment $c): array
    {
        $out = ['text' => $c->text];
        if ($c->author !== null) $out['author'] = $c->author;
        if ($c->color !== null) $out['color'] = $c->color;
        return $out;
    }

    /** Resolve `../comments1.xml` relative to `xl/worksheets/sheet1.xml`. */
    private static function resolveRelativePath(string $base, string $target): string
    {
        // Drop filename from base
        $baseDir = dirname($base);
        $combined = $baseDir.'/'.$target;
        // Normalize ../
        $parts = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '' && $segment !== '.') {
                $parts[] = $segment;
            }
        }
        return implode('/', $parts);
    }
}
