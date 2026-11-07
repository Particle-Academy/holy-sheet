<?php

declare(strict_types=1);

/**
 * Builds the read-option fixtures every port reads: `php generate.php`.
 *
 * The packages are hand-assembled rather than written by holy-sheet, so each
 * one holds exactly the shape a case needs (a shared-strings table, a `<row>`
 * past the last cell, a `<col>` past the last column, an ODS repeat) and
 * nothing the writer would add. The expectations in cases.json are written by
 * hand, NOT generated: a fixture that recorded what PHP happened to return
 * would pin the reference's bugs as the contract.
 *
 * The ports read these exact bytes (holy-sheet-js `tests/read-options.test.ts`,
 * holy-sheet-py `tests/test_read_options.py`), so a change here changes three
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
 * @param  list<array{name:string,xml:string}>  $sheets
 * @return array<string,string>
 */
function xlsx(array $sheets, ?string $sharedStrings = null): array
{
    $overrides = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $workbookSheets = '';
    $rels = '';
    foreach ($sheets as $i => $sheet) {
        $n = $i + 1;
        $overrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $workbookSheets .= '<sheet name="'.$sheet['name'].'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
        $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
    }
    if ($sharedStrings !== null) {
        $overrides .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        $rels .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    }

    $parts = [
        '[Content_Types].xml' => XML_HEAD.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'.$overrides.'</Types>',
        '_rels/.rels' => XML_HEAD.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => XML_HEAD.'<workbook xmlns="'.MAIN.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$workbookSheets.'</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => XML_HEAD.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>',
    ];
    foreach ($sheets as $i => $sheet) {
        $parts['xl/worksheets/sheet'.($i + 1).'.xml'] = XML_HEAD.'<worksheet xmlns="'.MAIN.'">'.$sheet['xml'].'</worksheet>';
    }
    if ($sharedStrings !== null) {
        $parts['xl/sharedStrings.xml'] = XML_HEAD.$sharedStrings;
    }

    return $parts;
}

$dir = __DIR__;

// Data: 3 rows x 1 column, 3 cells. Summary: 1 row x 2 columns, 2 cells.
zipParts($dir.'/two-sheets.xlsx', xlsx([
    ['name' => 'Data', 'xml' => '<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row><row r="2"><c r="A2"><v>1</v></c></row><row r="3"><c r="A3"><v>2</v></c></row></sheetData>'],
    ['name' => 'Summary', 'xml' => '<sheetData><row r="1"><c r="A1"><v>1</v></c><c r="B1"><v>2</v></c></row></sheetData>'],
]));

// A <row> element past the last cell still counts as a row the sheet has.
zipParts($dir.'/row-past-cells.xlsx', xlsx([
    ['name' => 'Rows', 'xml' => '<sheetData><row r="1"><c r="A1"><v>1</v></c></row><row r="7" ht="20" customHeight="1"/></sheetData>'],
]));

// A <col> element past the last cell still counts: it is how a reader is made
// to loop over columns nothing occupies.
zipParts($dir.'/col-past-cells.xlsx', xlsx([
    ['name' => 'Cols', 'xml' => '<cols><col min="1" max="40" width="10" customWidth="1"/></cols><sheetData><row r="1"><c r="A1"><v>1</v></c></row></sheetData>'],
]));

// The same under the DEFAULT limits: before 2.13.0 this one element drove the
// column-width reader through two billion iterations.
zipParts($dir.'/col-huge.xlsx', xlsx([
    ['name' => 'Cols', 'xml' => '<cols><col min="1" max="2000000000" width="10" customWidth="1"/></cols><sheetData><row r="1"><c r="A1"><v>1</v></c></row></sheetData>'],
]));

// One cell a row past Excel's last row, and one a column past its last column
// (XFE is column 16,385). The default maxRows / maxColumns refuse them; a raised
// limit reads them, which is what "every limit is configurable" has to mean.
zipParts($dir.'/past-excel-rows.xlsx', xlsx([
    ['name' => 'Rows', 'xml' => '<sheetData><row r="1048577"><c r="A1048577"><v>1</v></c></row></sheetData>'],
]));
zipParts($dir.'/past-excel-columns.xlsx', xlsx([
    ['name' => 'Cols', 'xml' => '<sheetData><row r="1"><c r="XFE1"><v>1</v></c></row></sheetData>'],
]));

// A worksheet and a shared-strings table cut off mid-document. The parser calls
// them malformed and each reads as empty, so a limit counted from the PARSED
// part never saw them; a limit counted while streaming, before the parse, does.
// That is the difference between a limit that bounds memory and one that does
// not: a parser builds its whole tree before it can be asked anything.
$cut = xlsx([['name' => 'Cut', 'xml' => '']]);
$cut['xl/worksheets/sheet1.xml'] = XML_HEAD.'<worksheet xmlns="'.MAIN.'"><sheetData><row r="1">'
    .'<c r="A1"><v>1</v></c><c r="B1"><v>2</v></c><c r="C1"><v>3</v></c>';
zipParts($dir.'/truncated-sheet.xlsx', $cut);
$cutStrings = xlsx(
    [['name' => 'Strings', 'xml' => '<sheetData><row r="1"><c r="A1"><v>1</v></c></row></sheetData>']],
    '<sst xmlns="'.MAIN.'" count="3" uniqueCount="3"><si><t>first</t></si><si><t>second</t></si><si><t>third</t></si>',
);
zipParts($dir.'/truncated-strings.xlsx', $cutStrings);

// Three shared strings, one referenced.
zipParts($dir.'/shared-strings.xlsx', xlsx(
    [['name' => 'Strings', 'xml' => '<sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData>']],
    '<sst xmlns="'.MAIN.'" count="3" uniqueCount="3"><si><t>first</t></si><si><t>second</t></si><si><t>third</t></si></sst>',
));

// ODS: one cell repeated 30 columns across and 40 rows down (1,200 cells from
// one element), then a second table holding one cell.
$ods = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
    .' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
    .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.3">'
    .'<office:body><office:spreadsheet>'
    .'<table:table table:name="Grid"><table:table-column table:number-columns-repeated="30"/>'
    .'<table:table-row table:number-rows-repeated="40"><table:table-cell table:number-columns-repeated="30" office:value-type="float" office:value="7"><text:p>7</text:p></table:table-cell></table:table-row>'
    .'</table:table>'
    .'<table:table table:name="Other"><table:table-row><table:table-cell office:value-type="string"><text:p>x</text:p></table:table-cell></table:table-row></table:table>'
    .'</office:spreadsheet></office:body></office:document-content>';
zipParts($dir.'/grid.ods', [
    'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
    'content.xml' => $ods,
    'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
        .'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
        .'<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>'
        .'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
        .'</manifest:manifest>',
], true);

echo "wrote the read-option fixtures to {$dir}\n";
