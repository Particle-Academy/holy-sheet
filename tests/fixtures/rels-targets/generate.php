<?php

declare(strict_types=1);

/**
 * Builds the relationship-target fixtures every port reads: `php generate.php`.
 *
 * OPC (ECMA-376 Part 2) resolves a relationship Target against the directory of
 * the part that OWNS the relationship, unless it starts with "/", which makes it
 * package-absolute. Excel writes relative targets; openpyxl, LibreOffice and
 * others write absolute ones. Until this set existed the Python and Node readers
 * turned `/xl/worksheets/sheet1.xml` into `xl/xl/worksheets/sheet1.xml`, found
 * nothing there and silently returned a workbook with no sheets.
 *
 * Every package is assembled by hand from the XML below -- never by holy-sheet's
 * writer, and never by openpyxl -- so each holds exactly the layout its case is
 * about. The expectations in cases.json are written by hand, NOT generated.
 *
 * Two sheets, Data then Summary:
 *   Data     A1 "Alpha" (shared string 0, bold), A2 1, A3 2, a comment on A1,
 *            and a column chart over A2:A3 (drawing -> chart).
 *   Summary  A1 "Beta" (shared string 1) and its own comment on A1.
 *
 * The ports read these exact bytes (holy-sheet-js `tests/rels-targets.test.ts`,
 * holy-sheet-py `tests/test_rels_targets.py`), so a change here changes three
 * suites. Re-run the script and commit the binaries with the change.
 */

const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const PKG = 'http://schemas.openxmlformats.org/package/2006/relationships';
const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const XML_HEAD = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

/** @param array<string,string> $parts  name => bytes, written in this order */
function zipParts(string $path, array $parts): void
{
    @unlink($path);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("cannot create {$path}");
    }
    foreach ($parts as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();
}

/** The relative reference from the part `$from` to the part `$to`, as Excel writes one. */
function relative(string $from, string $to): string
{
    $fromDir = explode('/', $from);
    array_pop($fromDir);
    $toParts = explode('/', $to);
    while ($fromDir !== [] && count($toParts) > 1 && $fromDir[0] === $toParts[0]) {
        array_shift($fromDir);
        array_shift($toParts);
    }

    return str_repeat('../', count($fromDir)).implode('/', $toParts);
}

/**
 * @param  list<array{0:string,1:string,2:string,3?:bool}>  $rels  [id, type (last segment), target, external]
 */
function relsXml(array $rels): string
{
    $out = '';
    foreach ($rels as $rel) {
        [$id, $type, $target] = $rel;
        $typeUri = str_contains($type, '://') ? $type : REL.'/'.$type;
        $mode = ($rel[3] ?? false) ? ' TargetMode="External"' : '';
        $out .= '<Relationship Id="'.$id.'" Type="'.$typeUri.'" Target="'.htmlspecialchars($target, ENT_XML1 | ENT_QUOTES).'"'.$mode.'/>';
    }

    return XML_HEAD.'<Relationships xmlns="'.PKG.'">'.$out.'</Relationships>';
}

function relsPathOf(string $part): string
{
    $slash = strrpos($part, '/');

    return $slash === false ? '_rels/'.$part.'.rels' : substr($part, 0, $slash).'/_rels/'.substr($part, $slash + 1).'.rels';
}

function commentsXml(string $text): string
{
    return XML_HEAD.'<comments xmlns="'.MAIN.'"><authors><author>Ann</author></authors><commentList>'
        .'<comment ref="A1" authorId="0"><text><r><t>'.$text.'</t></r></text></comment></commentList></comments>';
}

/**
 * The parts of one package.
 *
 * `$at` places each part: keys sheet1, sheet2, styles, strings, comments1,
 * comments2, vml, drawing, chart. `$mode` writes every internal target either
 * 'relative' (Excel) or 'absolute' (openpyxl). `$target` overrides one target
 * verbatim, by the same keys. `$missing` leaves those parts out of the zip while
 * every relationship to them stays. `$extraRels` adds relationships to a rels
 * part, keyed 'workbook', 'sheet1', 'sheet2' or 'drawing'. `$extraParts` adds
 * parts verbatim.
 *
 * @param  array<string,string>  $at
 * @param  array<string,string>  $target
 * @param  list<string>  $missing
 * @param  array<string,list<array{0:string,1:string,2:string,3?:bool}>>  $extraRels
 * @param  array<string,string>  $extraParts
 * @return array<string,string>
 */
function package(array $at = [], string $mode = 'relative', array $target = [], array $missing = [], array $extraRels = [], array $extraParts = [], array $sheetRelIds = []): array
{
    $at += [
        'sheet1' => 'xl/worksheets/sheet1.xml',
        'sheet2' => 'xl/worksheets/sheet2.xml',
        'styles' => 'xl/styles.xml',
        'strings' => 'xl/sharedStrings.xml',
        'comments1' => 'xl/comments1.xml',
        'comments2' => 'xl/comments2.xml',
        'vml' => 'xl/drawings/vmlDrawing1.vml',
        'drawing' => 'xl/drawings/drawing1.xml',
        'chart' => 'xl/charts/chart1.xml',
    ];
    $ref = static function (string $from, string $key) use ($at, $mode, $target): string {
        if (isset($target[$key])) {
            return $target[$key];
        }

        return $mode === 'absolute' ? '/'.$at[$key] : relative($from, $at[$key]);
    };

    $workbook = 'xl/workbook.xml';
    $sheetIds = $sheetRelIds + ['Data' => 'rId1', 'Summary' => 'rId2'];

    $parts = [
        '[Content_Types].xml' => XML_HEAD.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="vml" ContentType="application/vnd.openxmlformats-officedocument.vmlDrawing"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
        '_rels/.rels' => relsXml([['rId1', 'officeDocument', $mode === 'absolute' ? '/xl/workbook.xml' : 'xl/workbook.xml']]),
        $workbook => XML_HEAD.'<workbook xmlns="'.MAIN.'" xmlns:r="'.REL.'"><sheets>'
            .'<sheet name="Data" sheetId="1" r:id="'.$sheetIds['Data'].'"/>'
            .'<sheet name="Summary" sheetId="2" r:id="'.$sheetIds['Summary'].'"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => relsXml(array_merge([
            ['rId1', 'worksheet', $ref($workbook, 'sheet1')],
            ['rId2', 'worksheet', $ref($workbook, 'sheet2')],
            ['rId3', 'styles', $ref($workbook, 'styles')],
            ['rId4', 'sharedStrings', $ref($workbook, 'strings')],
        ], $extraRels['workbook'] ?? [])),
        $at['styles'] => XML_HEAD.'<styleSheet xmlns="'.MAIN.'">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>',
        $at['strings'] => XML_HEAD.'<sst xmlns="'.MAIN.'" count="2" uniqueCount="2"><si><t>Alpha</t></si><si><t>Beta</t></si></sst>',
        $at['sheet1'] => XML_HEAD.'<worksheet xmlns="'.MAIN.'" xmlns:r="'.REL.'"><sheetData>'
            .'<row r="1"><c r="A1" s="1" t="s"><v>0</v></c></row>'
            .'<row r="2"><c r="A2"><v>1</v></c></row>'
            .'<row r="3"><c r="A3"><v>2</v></c></row>'
            .'</sheetData><drawing r:id="rId2"/><legacyDrawing r:id="rId3"/></worksheet>',
        relsPathOf($at['sheet1']) => relsXml(array_merge([
            ['rId1', 'comments', $ref($at['sheet1'], 'comments1')],
            ['rId2', 'drawing', $ref($at['sheet1'], 'drawing')],
            ['rId3', 'vmlDrawing', $ref($at['sheet1'], 'vml')],
        ], $extraRels['sheet1'] ?? [])),
        $at['comments1'] => commentsXml('Check this'),
        $at['vml'] => '<xml xmlns:v="urn:schemas-microsoft-com:vml"/>',
        $at['drawing'] => XML_HEAD.'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<xdr:twoCellAnchor><xdr:from><xdr:col>2</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>1</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
            .'<xdr:to><xdr:col>10</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>17</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
            .'<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="2" name="Chart 1"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
            .'<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
            .'<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="'.REL.'" r:id="rId1"/>'
            .'</a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor></xdr:wsDr>',
        relsPathOf($at['drawing']) => relsXml(array_merge([
            ['rId1', 'chart', $ref($at['drawing'], 'chart')],
        ], $extraRels['drawing'] ?? [])),
        $at['chart'] => XML_HEAD.'<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart><c:plotArea>'
            .'<c:barChart><c:barDir val="col"/><c:grouping val="clustered"/><c:ser><c:idx val="0"/><c:order val="0"/>'
            .'<c:val><c:numRef><c:f>\'Data\'!$A$2:$A$3</c:f></c:numRef></c:val></c:ser></c:barChart>'
            .'</c:plotArea></c:chart></c:chartSpace>',
        $at['sheet2'] => XML_HEAD.'<worksheet xmlns="'.MAIN.'" xmlns:r="'.REL.'"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>1</v></c></row></sheetData></worksheet>',
        relsPathOf($at['sheet2']) => relsXml(array_merge([
            ['rId1', 'comments', $ref($at['sheet2'], 'comments2')],
        ], $extraRels['sheet2'] ?? [])),
        $at['comments2'] => commentsXml('Summary note'),
    ];

    foreach ($missing as $key) {
        unset($parts[$at[$key]]);
    }

    return $parts + $extraParts;
}

$dir = __DIR__;

// Excel's layout: every target relative to the part that owns the relationship.
zipParts($dir.'/relative.xlsx', package());

// openpyxl's layout: every target package-absolute, comments under xl/comments/.
zipParts($dir.'/absolute.xlsx', package([
    'comments1' => 'xl/comments/comment1.xml',
    'comments2' => 'xl/comments/comment2.xml',
    'vml' => 'xl/drawings/commentsDrawing1.vml',
], 'absolute'));

// "." and ".." segments, which normalise away.
zipParts($dir.'/dot-segments.xlsx', package([], 'relative', [
    'sheet1' => './worksheets/./sheet1.xml',
    'sheet2' => 'worksheets/../worksheets/sheet2.xml',
    'styles' => './styles.xml',
    'comments1' => '../charts/../comments1.xml',
    'drawing' => './../drawings/drawing1.xml',
    'chart' => '../charts/./chart1.xml',
]));

// Part names that are not sheet1..N, so nothing can be found by position.
zipParts($dir.'/named-parts.xlsx', package([
    'sheet1' => 'xl/worksheets/data.xml',
    'sheet2' => 'xl/worksheets/summary.xml',
    'comments1' => 'xl/notes/data-notes.xml',
    'comments2' => 'xl/notes/summary-notes.xml',
    'drawing' => 'xl/drawings/data-drawing.xml',
    'chart' => 'xl/charts/revenue.xml',
]));

// The first tab's part is sheet2.xml and the second's sheet1.xml, as after a
// tab reorder: a rels part found by POSITION hands each sheet the other's.
zipParts($dir.'/swapped-parts.xlsx', package([
    'sheet1' => 'xl/worksheets/sheet2.xml',
    'sheet2' => 'xl/worksheets/sheet1.xml',
    'comments1' => 'xl/comments2.xml',
    'comments2' => 'xl/comments1.xml',
]));

// Styles and shared strings somewhere other than xl/styles.xml and
// xl/sharedStrings.xml: only the workbook's relationships say where.
zipParts($dir.'/parts-elsewhere.xlsx', package([
    'styles' => 'xl/look/custom-styles.xml',
    'strings' => 'xl/text/strings.xml',
], 'absolute'));

// External relationships are URIs, never parts: none is read, none is missing.
zipParts($dir.'/external-targets.xlsx', package([], 'relative', [], [], [
    'workbook' => [
        ['rId9', 'sharedStrings', 'https://example.com/strings.xml', true],
        ['rId10', 'externalLinkPath', 'file:///C:/books/other.xlsx', true],
    ],
    'sheet1' => [
        ['rId8', 'hyperlink', 'https://example.com/report', true],
        ['rId9', 'drawing', 'https://example.com/drawing9.xml', true],
        ['rId10', 'comments', 'file:///C:/notes/comment9.xml', true],
    ],
    'drawing' => [
        ['rId9', 'image', 'https://example.com/logo.png', true],
    ],
]));

// A relationship whose part is not in the package must raise, never skip.
zipParts($dir.'/missing-sheet.xlsx', package([], 'relative', [], ['sheet2']));
zipParts($dir.'/missing-sheet-absolute.xlsx', package([], 'absolute', [], ['sheet1']));
zipParts($dir.'/missing-comments.xlsx', package([], 'relative', [], ['comments1']));
zipParts($dir.'/missing-drawing.xlsx', package([], 'relative', [], ['drawing']));
zipParts($dir.'/missing-chart.xlsx', package([], 'relative', [], ['chart']));
zipParts($dir.'/missing-styles.xlsx', package([], 'relative', [], ['styles']));
zipParts($dir.'/missing-shared-strings.xlsx', package([], 'relative', [], ['strings']));
// A <sheet> whose r:id names no relationship at all.
zipParts($dir.'/dangling-sheet-rel.xlsx', package([], 'relative', [], [], [], [], ['Summary' => 'rId99']));

echo "wrote the rels-target fixtures to {$dir}\n";
