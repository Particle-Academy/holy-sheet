<?php

declare(strict_types=1);

/**
 * Builds the active-sheet fixtures every port reads: `php generate.php`.
 *
 * CC-fancy-0050. The packages are hand-assembled rather than written by
 * holy-sheet, so each one holds exactly the `workbookView` (or ODS
 * `ActiveTable`) a case needs and nothing the writer would add. The
 * expectations in cases.json are written by hand, NOT generated: a fixture that
 * recorded what PHP happened to return would pin the reference's bugs as the
 * contract.
 *
 * The ports read these exact bytes (holy-sheet-js `tests/active-sheet.test.ts`,
 * holy-sheet-py `tests/test_active_sheet.py`), so a change here changes three
 * suites. Re-run the script and commit the binaries with the change.
 */

const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const XML_HEAD = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

/** @param array<string,string> $parts  name => bytes, written in this order */
function zipParts(string $path, array $parts, bool $storeFirst = false): void
{
    @unlink($path);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("cannot create {$path}");
    }
    $first = true;
    foreach ($parts as $name => $body) {
        $zip->addFromString($name, $body);
        if ($first && $storeFirst) {
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        $first = false;
    }
    $zip->close();
}

/**
 * Three sheets, First / Second / Third, each holding its own name in A1.
 * `$bookViews` goes into workbook.xml before `<sheets>`, verbatim.
 *
 * @param  list<string>  $missing  sheet names whose worksheet part is left out
 * @return array<string,string>
 */
function xlsx(string $bookViews, array $missing = []): array
{
    $names = ['First', 'Second', 'Third'];
    $overrides = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $workbookSheets = '';
    $rels = '';
    foreach ($names as $i => $name) {
        $n = $i + 1;
        $overrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookSheets .= '<sheet name="'.$name.'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
        $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
    }

    $parts = [
        '[Content_Types].xml' => XML_HEAD.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'.$overrides.'</Types>',
        '_rels/.rels' => XML_HEAD.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => XML_HEAD.'<workbook xmlns="'.MAIN.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$bookViews.'<sheets>'.$workbookSheets.'</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => XML_HEAD.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>',
    ];
    foreach ($names as $i => $name) {
        if (in_array($name, $missing, true)) {
            continue;
        }
        $parts['xl/worksheets/sheet'.($i + 1).'.xml'] = XML_HEAD.'<worksheet xmlns="'.MAIN.'"><sheetData>'
            .'<row r="1"><c r="A1" t="inlineStr"><is><t>'.$name.'</t></is></c></row></sheetData></worksheet>';
    }

    return $parts;
}

function view(string $attributes): string
{
    return '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="16000" windowHeight="9000"'.$attributes.'/></bookViews>';
}

/** @return array<string,string> an ods with tables First / Second / Third and the given settings.xml (null: none) */
function ods(?string $settings): array
{
    $tables = '';
    foreach (['First', 'Second', 'Third'] as $name) {
        $tables .= '<table:table table:name="'.$name.'"><table:table-row><table:table-cell office:value-type="string"><text:p>'.$name.'</text:p></table:table-cell></table:table-row></table:table>';
    }
    $content = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
        .' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
        .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.3">'
        .'<office:body><office:spreadsheet>'.$tables.'</office:spreadsheet></office:body></office:document-content>';
    $entries = '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>'
        .'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>';
    $parts = [
        'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
        'content.xml' => $content,
    ];
    if ($settings !== null) {
        $parts['settings.xml'] = $settings;
        $entries .= '<manifest:file-entry manifest:full-path="settings.xml" manifest:media-type="text/xml"/>';
    }
    $parts['META-INF/manifest.xml'] = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
        .$entries.'</manifest:manifest>';

    return $parts;
}

/** settings.xml as LibreOffice writes it: the active table sits in the first Views entry. */
function settings(string $viewEntry, string $extraViewSettings = ''): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<office:document-settings xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
        .' xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0" office:version="1.3"><office:settings>'
        .'<config:config-item-set config:name="ooo:view-settings">'
        .'<config:config-item config:name="VisibleAreaTop" config:type="int">0</config:config-item>'
        .$extraViewSettings
        .'<config:config-item-map-indexed config:name="Views"><config:config-item-map-entry>'
        .'<config:config-item config:name="ViewId" config:type="string">view1</config:config-item>'
        .$viewEntry
        .'</config:config-item-map-entry></config:config-item-map-indexed>'
        .'</config:config-item-set>'
        .'<config:config-item-set config:name="ooo:configuration-settings">'
        .'<config:config-item config:name="ActiveTable" config:type="string">First</config:config-item>'
        .'</config:config-item-set>'
        .'</office:settings></office:document-settings>';
}

function activeTable(string $name): string
{
    return '<config:config-item config:name="ActiveTable" config:type="string">'.$name.'</config:config-item>';
}

$dir = __DIR__;

zipParts($dir.'/no-book-views.xlsx', xlsx(''));
zipParts($dir.'/book-view-no-tab.xlsx', xlsx(view('')));
zipParts($dir.'/active-1.xlsx', xlsx(view(' activeTab="1"')));
zipParts($dir.'/active-2.xlsx', xlsx(view(' firstSheet="1" activeTab="2"')));
zipParts($dir.'/active-past-end.xlsx', xlsx(view(' activeTab="7"')));
zipParts($dir.'/active-huge.xlsx', xlsx(view(' activeTab="99999999999999999999"')));
zipParts($dir.'/active-junk.xlsx', xlsx(view(' activeTab="two"')));
zipParts($dir.'/active-negative.xlsx', xlsx(view(' activeTab="-1"')));
zipParts($dir.'/active-padded.xlsx', xlsx(view(' activeTab=" 1 "')));
zipParts($dir.'/two-book-views.xlsx', xlsx('<bookViews><workbookView activeTab="2"/><workbookView activeTab="1"/></bookViews>'));
// The active tab's worksheet part is missing. Until the rels-targets fix the
// reader skipped that sheet and fell back to the first; a missing part now
// raises MissingPartException instead (cases.json `readRaises`).
zipParts($dir.'/active-sheet-missing.xlsx', xlsx(view(' activeTab="1"'), ['Second']));
// A sheet before the active one is missing: that raises too, rather than
// shifting the index onto the sheets that happen to be present.
zipParts($dir.'/earlier-sheet-missing.xlsx', xlsx(view(' activeTab="2"'), ['First']));

zipParts($dir.'/no-settings.ods', ods(null), true);
zipParts($dir.'/active-third.ods', ods(settings(activeTable('Third'))), true);
zipParts($dir.'/active-unknown.ods', ods(settings(activeTable('Nowhere'))), true);
// No ActiveTable in the view: the configuration-settings one is not it.
zipParts($dir.'/no-active-table.ods', ods(settings('')), true);

echo "wrote the active-sheet fixtures to {$dir}\n";
