<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Archive\OfficeTemplate;
use HolySheet\Archive\PartXml;
use HolySheet\Archive\SourceArchive;
use HolySheet\Exceptions\MissingPartException;
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
     *
     * Every relationship target resolves per OPC (RelsParser::resolve), and a
     * relationship whose part the package does not contain throws
     * MissingPartException rather than being skipped.
     */
    public function readArchive(SourceArchive $zip, string $path = '(bytes)', ?string $only = null, ?ReadLimits $limits = null): Workbook
    {
        $limits ??= ReadLimits::from();
        $sourceKind = OfficeTemplate::kind($zip, $path);

        $workbookPath = 'xl/workbook.xml';
        $workbookXml = $zip->read($workbookPath);
        if ($workbookXml === null) {
            throw new RuntimeException("[holy-sheet] missing xl/workbook.xml in {$path}");
        }

        $workbookRelsXml = $zip->read(RelsParser::relsPathOf($workbookPath));
        $workbookRels = is_string($workbookRelsXml) ? RelsParser::parse($workbookRelsXml) : [];

        // Styles and shared strings are wherever the workbook's relationships
        // say; the conventional names are only the fallback for a package that
        // declares neither.
        $stylesXml = self::workbookPart($zip, $workbookRels, $workbookPath, 'styles', 'xl/styles.xml');
        $stylesIndex = is_string($stylesXml) ? StylesParser::parse($stylesXml) : [];

        $sharedStringsXml = self::workbookPart($zip, $workbookRels, $workbookPath, 'sharedStrings', 'xl/sharedStrings.xml');
        $sharedStrings = is_string($sharedStringsXml) ? SharedStringsParser::parse($sharedStringsXml, $limits) : [];

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
        // counts the <sheet> elements. Every listed sheet is described (a
        // missing part raises), but the mapping is kept explicit.
        $positions = [];
        $position = -1;
        if (isset($sheetsXml->sheets) && $sheetsXml->sheets->sheet) {
            foreach ($sheetsXml->sheets->sheet as $sheetEl) {
                $position++;
                $name = (string) $sheetEl['name'];
                if ($only !== null && $name !== $only) continue;
                $rId = (string) $sheetEl->attributes('r', true)->id;
                $sheetRel = $workbookRels[$rId] ?? null;
                if ($sheetRel === null || RelsParser::isExternal($sheetRel)) {
                    throw new MissingPartException(null, $workbookPath, $rId);
                }

                $sheetPath = RelsParser::resolve($workbookPath, $sheetRel['Target']);
                $worksheetXml = self::requirePart($zip, $sheetPath, $workbookPath, $rId);

                // The sheet's own rels part sits beside it, named after its
                // actual part -- not after its position in the tab strip, which
                // need not match (sheet3.xml can be the first tab).
                $sheetRelsXml = $zip->read(RelsParser::relsPathOf($sheetPath));
                $sheetRels = is_string($sheetRelsXml) ? RelsParser::parse($sheetRelsXml) : [];

                $comments = [];
                foreach (RelsParser::byType($sheetRels, '/comments') as $cId => $cr) {
                    $commentsXml = self::requirePart($zip, RelsParser::resolve($sheetPath, $cr['Target']), $sheetPath, (string) $cId);
                    $comments = array_replace($comments, CommentsParser::parse($commentsXml));
                }

                $sheet = WorksheetParser::parse($worksheetXml, $name, $stylesIndex, $comments, $sharedStrings, $limits);

                // Charts: sheet rels -> drawing -> drawing rels -> chart parts.
                $charts = [];
                foreach (RelsParser::byType($sheetRels, '/drawing') as $dId => $dr) {
                    $drawingPath = RelsParser::resolve($sheetPath, $dr['Target']);
                    $drawingXml = self::requirePart($zip, $drawingPath, $sheetPath, (string) $dId);
                    // A drawing with no rels part holds no chart; that is not a missing part.
                    $drawingRelsXml = $zip->read(RelsParser::relsPathOf($drawingPath));
                    if (! is_string($drawingRelsXml)) {
                        continue;
                    }
                    $drawingRels = RelsParser::parse($drawingRelsXml);
                    foreach (ChartParser::anchors($drawingXml) as $anchor) {
                        $chartRel = $drawingRels[$anchor['rel']] ?? null;
                        if ($chartRel === null || RelsParser::isExternal($chartRel)) {
                            continue;
                        }
                        $chartXml = self::requirePart($zip, RelsParser::resolve($drawingPath, $chartRel['Target']), $drawingPath, $anchor['rel']);
                        $chart = ChartParser::chart($chartXml, $name, $anchor['from'], $anchor['to']);
                        if ($chart !== null) {
                            $charts[] = $chart;
                        }
                    }
                }
                if ($charts !== []) {
                    $sheet = $sheet->withCharts($charts);
                }

                // Excel tables: sheet rels -> table parts.
                $tables = [];
                foreach (RelsParser::byType($sheetRels, '/table') as $tId => $tr) {
                    $tableXml = self::requirePart($zip, RelsParser::resolve($sheetPath, $tr['Target']), $sheetPath, (string) $tId);
                    $table = self::parseTable($tableXml);
                    if ($table !== null) {
                        $tables[] = $table;
                    }
                }
                if ($tables !== []) {
                    $sheet = $sheet->with(['tables' => $tables]);
                }

                $state = (string) $sheetEl['state'];
                if ($state === 'hidden' || $state === 'veryHidden') {
                    $sheet = $sheet->with(['state' => $state]);
                }

                $sheets[] = $sheet;
                $positions[] = $position;
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
     * but a non-negative integer, is the default, 0. A tab no described sheet
     * sits at falls back to the first described sheet. CC-fancy-0050.
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

    /**
     * A part a relationship points at. Missing, it raises: a part skipped
     * silently is how a workbook read as having no sheets and no error.
     */
    private static function requirePart(SourceArchive $zip, string $part, string $source, string $relId): string
    {
        $xml = $zip->read($part);
        if ($xml === null) {
            throw new MissingPartException($part, $source, $relId);
        }

        return $xml;
    }

    /**
     * The workbook-level part of relationship type `$type` (its last URI
     * segment, so the transitional and strict namespaces both match), or the
     * conventional `$fallback` when the workbook declares no such relationship.
     * A declared part that is missing raises.
     *
     * @param  array<string,array{Target:string,Type:string,TargetMode:string}>  $workbookRels
     */
    private static function workbookPart(SourceArchive $zip, array $workbookRels, string $workbookPath, string $type, string $fallback): ?string
    {
        foreach ($workbookRels as $id => $rel) {
            if (! RelsParser::isExternal($rel) && str_ends_with($rel['Type'], '/'.$type)) {
                return self::requirePart($zip, RelsParser::resolve($workbookPath, $rel['Target']), $workbookPath, (string) $id);
            }
        }

        return $zip->read($fallback);
    }
}
