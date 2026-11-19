<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Archive\OfficeTemplate;
use HolySheet\Archive\PartXml;
use HolySheet\Archive\SourceArchive;
use HolySheet\Exceptions\SheetNotFoundException;
use HolySheet\Workbook\Workbook;
use RuntimeException;
use SimpleXMLElement;

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
    public function describe(string $path, ?string $sheet = null, ?ReadLimits $limits = null): array
    {
        return WorkbookSchema::fromWorkbook($this->readWorkbook($path, $sheet, $limits));
    }

    public function readWorkbook(string $path, ?string $sheet = null, ?ReadLimits $limits = null): Workbook
    {
        return $this->readArchive(SourceArchive::fromPath($path, $limits?->archive() ?? []), $path, $sheet, $limits);
    }

    /**
     * The same read, from an already-opened (and already limit-checked) archive.
     *
     * `$only` reads just the sheet of that name (exactly as written in the
     * file); the others are never parsed, so they cost nothing and count toward
     * no limit. An unknown name throws SheetNotFoundException.
     */
    public function readArchive(SourceArchive $zip, string $path = '(bytes)', ?string $only = null, ?ReadLimits $limits = null): Workbook
    {
        $limits ??= ReadLimits::from();
        $sourceKind = OfficeTemplate::kind($zip, $path);

        $stylesXml = $zip->read('xl/styles.xml');
        $stylesIndex = is_string($stylesXml) ? StylesParser::parse($stylesXml) : [];

        $sharedStringsXml = $zip->read('xl/sharedStrings.xml');
        $sharedStrings = is_string($sharedStringsXml) ? SharedStringsParser::parse($sharedStringsXml, $limits) : [];

        $workbookXml = $zip->read('xl/workbook.xml');
        if ($workbookXml === null) {
            throw new RuntimeException("[holy-sheet] missing xl/workbook.xml in {$path}");
        }

        $workbookRelsXml = $zip->read('xl/_rels/workbook.xml.rels');
        $workbookRels = is_string($workbookRelsXml) ? RelsParser::parse($workbookRelsXml) : [];

        $sheetsXml = PartXml::simple($workbookXml);
        if ($sheetsXml === false) {
            throw new RuntimeException("[holy-sheet] failed to parse xl/workbook.xml in {$path}");
        }

        if ($only !== null) {
            $names = [];
            if (isset($sheetsXml->sheets) && $sheetsXml->sheets->sheet) {
                foreach ($sheetsXml->sheets->sheet as $sheetEl) {
                    $names[] = (string) $sheetEl['name'];
                }
            }
            if (! in_array($only, $names, true)) {
                throw new SheetNotFoundException($only, $names);
            }
        }

        $sheets = [];
        // The position in workbook.xml of each described sheet: activeTab
        // counts the <sheet> elements, and a sheet whose part is missing is
        // skipped below, so the two orders can differ.
        $positions = [];
        $position = -1;
        if (isset($sheetsXml->sheets) && $sheetsXml->sheets->sheet) {
            $i = 0;
            foreach ($sheetsXml->sheets->sheet as $sheetEl) {
                $position++;
                $name = (string) $sheetEl['name'];
                if ($only !== null && $name !== $only) continue;
                $rId = (string) $sheetEl->attributes('r', true)->id;
                $target = $workbookRels[$rId]['Target'] ?? null;
                if ($target === null) continue;

                $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : self::resolveRelativePath('xl/workbook.xml', $target);
                $worksheetXml = $zip->read($sheetPath);
                if ($worksheetXml === null) continue;

                // The sheet's own rels part sits beside it, named after its
                // actual file -- not after its position in the tab strip, which
                // need not match (sheet3.xml can be the first tab).
                $sheetRelsPath = dirname($sheetPath).'/_rels/'.basename($sheetPath).'.rels';
                $sheetRelsXml = $zip->read($sheetRelsPath);
                $comments = [];
                if (is_string($sheetRelsXml)) {
                    $sheetRels = RelsParser::parse($sheetRelsXml);
                    $commentRels = RelsParser::byType($sheetRels, '/comments');
                    foreach ($commentRels as $cr) {
                        $commentsTarget = $cr['Target'];
                        $commentsPath = self::resolveRelativePath($sheetPath, $commentsTarget);
                        $commentsXml = $zip->read($commentsPath);
                        if (is_string($commentsXml)) {
                            $comments = array_replace($comments, CommentsParser::parse($commentsXml));
                        }
                    }
                }

                $sheet = WorksheetParser::parse($worksheetXml, $name, $stylesIndex, $comments, $sharedStrings, $limits);

                // Charts: sheet rels -> drawing -> drawing rels -> chart parts.
                if (is_string($sheetRelsXml)) {
                    $charts = [];
                    foreach (RelsParser::byType(RelsParser::parse($sheetRelsXml), '/drawing') as $dr) {
                        $drawingPath = self::resolveRelativePath($sheetPath, $dr['Target']);
                        $drawingXml = $zip->read($drawingPath);
                        $drawingRelsXml = $zip->read(dirname($drawingPath).'/_rels/'.basename($drawingPath).'.rels');
                        if (! is_string($drawingXml) || ! is_string($drawingRelsXml)) {
                            continue;
                        }
                        $drawingRels = RelsParser::parse($drawingRelsXml);
                        foreach (ChartParser::anchors($drawingXml) as $anchor) {
                            $target = $drawingRels[$anchor['rel']]['Target'] ?? null;
                            $chartXml = is_string($target) ? $zip->read(self::resolveRelativePath($drawingPath, $target)) : null;
                            $chart = is_string($chartXml) ? ChartParser::chart($chartXml, $name, $anchor['from'], $anchor['to']) : null;
                            if ($chart !== null) {
                                $charts[] = $chart;
                            }
                        }
                    }
                    if ($charts !== []) {
                        $sheet = $sheet->withCharts($charts);
                    }
                }

                // Excel tables: sheet rels -> table parts.
                if (is_string($sheetRelsXml)) {
                    $tables = [];
                    foreach (RelsParser::byType(RelsParser::parse($sheetRelsXml), '/table') as $tr) {
                        $tableXml = $zip->read(self::resolveRelativePath($sheetPath, $tr['Target']));
                        $table = is_string($tableXml) ? self::parseTable($tableXml) : null;
                        if ($table !== null) {
                            $tables[] = $table;
                        }
                    }
                    if ($tables !== []) {
                        $sheet = $sheet->with(['tables' => $tables]);
                    }
                }

                $state = (string) $sheetEl['state'];
                if ($state === 'hidden' || $state === 'veryHidden') {
                    $sheet = $sheet->with(['state' => $state]);
                }

                $sheets[] = $sheet;
                $positions[] = $position;
                $i++;
            }
        }

        $meta = $this->parseDocProps($zip);

        // A read narrowed to one sheet is not the workbook, so it names no active sheet.
        $active = $only === null && $sheets !== [] ? self::activeSheet($sheetsXml, $positions, $position) : null;

        return new Workbook($sheets, $meta, $sourceKind, self::parseDefinedNames($sheetsXml), self::usesDate1904($sheetsXml), $active);
    }

    /**
     * The described sheet that opens: the first `<workbookView>`'s `activeTab`,
     * clamped to the `<sheet>` elements workbook.xml lists. Absent, or anything
     * but a non-negative integer, is the default, 0. An active sheet the reader
     * skipped (its part is missing) falls back to the first described sheet.
     * CC-fancy-0050.
     *
     * @param  list<int>  $positions  the workbook.xml position of each described sheet
     */
    private static function activeSheet(SimpleXMLElement $workbook, array $positions, int $lastPosition): int
    {
        $tab = 0;
        if (isset($workbook->bookViews->workbookView[0])) {
            // xsd:unsignedInt collapses whitespace; only the four XML spaces.
            $raw = trim((string) $workbook->bookViews->workbookView[0]['activeTab'], " \t\n\r");
            if (preg_match('/^[0-9]+$/', $raw) === 1) {
                // Past PHP_INT_MAX the cast saturates, and the clamp takes it to the last sheet.
                $tab = min((int) $raw, max(0, $lastPosition));
            }
        }
        $index = array_search($tab, $positions, true);

        return $index === false ? 0 : $index;
    }

    /** Whether the workbook counts dates from 1904-01-01: `<workbookPr date1904="1"/>` (or "true"). */
    private static function usesDate1904(SimpleXMLElement $workbook): bool
    {
        return isset($workbook->workbookPr) && in_array((string) $workbook->workbookPr['date1904'], ['1', 'true'], true);
    }

    /**
     * Workbook defined names, `ref` verbatim. Excel's own `_xlnm.*` names
     * (print areas, filter ranges) are not names anyone chose and are left out.
     *
     * @return list<array{name:string,ref:string}>
     */
    private static function parseDefinedNames(SimpleXMLElement $workbook): array
    {
        $out = [];
        if (!isset($workbook->definedNames) || !$workbook->definedNames->definedName) {
            return $out;
        }
        foreach ($workbook->definedNames->definedName as $dn) {
            $name = (string) $dn['name'];
            if ($name === '' || str_starts_with(strtolower($name), '_xlnm.')) {
                continue;
            }
            $out[] = ['name' => $name, 'ref' => trim((string) $dn)];
        }

        return $out;
    }

    /** @return array{name:string,ref:string,headerRowCount:int}|null */
    private static function parseTable(string $xml): ?array
    {
        $t = PartXml::simple($xml);
        if ($t === false) {
            return null;
        }
        $name = (string) ($t['displayName'] ?? '');
        if ($name === '') {
            $name = (string) ($t['name'] ?? '');
        }
        $ref = (string) ($t['ref'] ?? '');
        if ($name === '' || $ref === '') {
            return null;
        }

        return [
            'name' => $name,
            'ref' => $ref,
            'headerRowCount' => isset($t['headerRowCount']) ? max(0, (int) $t['headerRowCount']) : 1,
        ];
    }

    /** @return array<string,mixed> */
    private function parseDocProps(SourceArchive $zip): array
    {
        $coreXml = $zip->read('docProps/core.xml');
        if ($coreXml === null) return [];

        // SimpleXML strips namespaces awkwardly; use children() with namespace URI for safety.
        $core = PartXml::simple($coreXml);
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
