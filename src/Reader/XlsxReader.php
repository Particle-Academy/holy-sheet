<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Exceptions\UnsupportedFormatException;
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
        return WorkbookSchema::fromWorkbook($this->readWorkbook($path));
    }

    public function readWorkbook(string $path): Workbook
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw UnsupportedFormatException::notAZip($path);
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
