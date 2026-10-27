<?php

declare(strict_types=1);

use HolySheet\Agent;

/**
 * What the reader gained for document exchange: sheet `state`, hidden rows and
 * columns, defined names, Excel tables and the raw code of a foreign number
 * format (SPEC 10.1 input). Plus `Agent::read()`, the sibling-named alias.
 *
 * Hidden content is the reason these exist. Before them, a hidden sheet's text
 * reached an agent unmarked: nothing in the read model said it was hidden.
 */

/**
 * A minimal xlsx from raw parts. Each sheet is [name, worksheet XML body,
 * workbook <sheet> extra attributes, sheet rels XML or null].
 *
 * @param  list<array{0:string,1:string,2?:string,3?:?string}>  $sheets
 * @param  array<string,string>  $extraParts
 */
function exch_xlsx(array $sheets, string $workbookExtra = '', string $styles = '', array $extraParts = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'holy_exch_').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $rels = '';
    $sheetEls = '';
    foreach ($sheets as $i => $s) {
        $n = $i + 1;
        $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
        $sheetEls .= '<sheet name="'.htmlspecialchars($s[0], ENT_XML1 | ENT_QUOTES).'" sheetId="'.$n.'" r:id="rId'.$n.'"'.($s[2] ?? '').'/>';
        $zip->addFromString("xl/worksheets/sheet{$n}.xml", '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.$s[1].'</worksheet>');
        if (($s[3] ?? null) !== null) {
            $zip->addFromString("xl/worksheets/_rels/sheet{$n}.xml.rels", '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$s[3].'</Relationships>');
        }
    }
    if ($styles !== '') {
        $rels .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$styles.'</styleSheet>');
    }
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets>'.$sheetEls.'</sheets>'.$workbookExtra.'</workbook>');
    foreach ($extraParts as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();

    return $path;
}

function exch_describe(string $path): array
{
    try {
        return Agent::describe($path);
    } finally {
        @unlink($path);
    }
}

$cellsAB = '<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c><c r="B1" t="inlineStr"><is><t>b</t></is></c></row></sheetData>';

it('reads a hidden and a very hidden sheet state, and says nothing for a visible one', function () use ($cellsAB) {
    $out = exch_describe(exch_xlsx([
        ['Shown', $cellsAB],
        ['Secret', $cellsAB, ' state="hidden"'],
        ['Deep', $cellsAB, ' state="veryHidden"'],
    ]));

    expect($out['sheets'][0])->not->toHaveKey('state');
    expect($out['sheets'][1]['state'])->toBe('hidden');
    expect($out['sheets'][2]['state'])->toBe('veryHidden');
});

it('reads hidden rows and hidden columns, clamped to what the sheet uses', function () {
    $sheet = '<cols><col min="2" max="2" width="9" hidden="1"/><col min="4" max="16384" width="9" hidden="1"/></cols>'
        .'<sheetData>'
        .'<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c><c r="B1" t="inlineStr"><is><t>secret</t></is></c><c r="C1" t="inlineStr"><is><t>c</t></is></c></row>'
        .'<row r="2" hidden="1"><c r="A2" t="inlineStr"><is><t>hidden row</t></is></c></row>'
        .'<row r="3"><c r="A3" t="inlineStr"><is><t>z</t></is></c></row>'
        .'<row r="9" hidden="1"/>'
        .'</sheetData>';
    $s = exch_describe(exch_xlsx([['S', $sheet]]))['sheets'][0];

    // Columns D.. (16381 of them) are hidden but hold nothing: listing them
    // would make one `<col>` element a 16k-entry array.
    expect($s['hiddenColumns'])->toBe([1]);
    expect($s['hiddenRows'])->toBe([2]);
});

it('reads workbook defined names, leaving out the _xlnm built-ins', function () use ($cellsAB) {
    $names = '<definedNames>'
        .'<definedName name="_xlnm.Print_Area" localSheetId="0">\'Q4\'!$A$1:$B$1</definedName>'
        .'<definedName name="Revenue">\'Q4\'!$A$1:$B$1</definedName>'
        .'<definedName name="Spread">Q4!$A$1,Q4!$B$1</definedName>'
        .'</definedNames>';
    $out = exch_describe(exch_xlsx([['Q4', $cellsAB]], $names));

    expect($out['definedNames'])->toBe([
        ['name' => 'Revenue', 'ref' => "'Q4'!\$A\$1:\$B\$1"],
        ['name' => 'Spread', 'ref' => 'Q4!$A$1,Q4!$B$1'],
    ]);
});

it('reads Excel tables from a sheet\'s tableParts', function () use ($cellsAB) {
    $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>';
    $table = '<?xml version="1.0" encoding="UTF-8"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="Table1" displayName="Sales" ref="A1:B3" headerRowCount="1" totalsRowCount="1"><tableColumns count="2"><tableColumn id="1" name="a"/><tableColumn id="2" name="b"/></tableColumns></table>';
    $noHeader = '<?xml version="1.0" encoding="UTF-8"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="2" name="Raw" displayName="Raw" ref="D1:D2" headerRowCount="0"/>';
    $rels .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table2.xml"/>';

    $s = exch_describe(exch_xlsx(
        [['S', $cellsAB.'<tableParts count="2"><tablePart r:id="rId1"/><tablePart r:id="rId2"/></tableParts>', '', $rels]],
        '',
        '',
        ['xl/tables/table1.xml' => $table, 'xl/tables/table2.xml' => $noHeader],
    ))['sheets'][0];

    expect($s['tables'])->toBe([
        ['name' => 'Sales', 'ref' => 'A1:B3', 'headerRowCount' => 1],
        ['name' => 'Raw', 'ref' => 'D1:D2', 'headerRowCount' => 0],
    ]);
});

it('finds a sheet\'s parts through the rels beside its own file, not its tab position', function () use ($cellsAB) {
    // Tab 1 is worksheets/sheet2.xml and tab 2 is sheet1.xml: legal, and what
    // a workbook looks like after its tabs are reordered. Looking rels up as
    // `sheet{tab}.xml.rels` gave the table to the wrong sheet.
    $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>';
    $table = '<?xml version="1.0" encoding="UTF-8"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="T" displayName="T" ref="A1:B1" headerRowCount="1"/>';
    $swapped = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';

    $out = exch_describe(exch_xlsx(
        [['First', $cellsAB], ['Second', $cellsAB.'<tableParts count="1"><tablePart r:id="rId1"/></tableParts>', '', $rels]],
        '',
        '',
        ['xl/tables/table1.xml' => $table, 'xl/_rels/workbook.xml.rels' => $swapped],
    ));

    expect($out['sheets'][0]['name'])->toBe('First');
    expect($out['sheets'][0]['tables'] ?? null)->toBe([['name' => 'T', 'ref' => 'A1:B1', 'headerRowCount' => 1]]);
    expect($out['sheets'][1])->not->toHaveKey('tables');
});

it('keeps the raw code of a foreign number format as format.formatCode', function () {
    $styles = '<numFmts count="1"><numFmt numFmtId="164" formatCode="# ?/?"/></numFmts>'
        .'<fonts count="1"><font><sz val="11"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        .'<borders count="1"><border/></borders>'
        .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/></cellXfs>';
    $sheet = '<sheetData><row r="1"><c r="A1" s="1"><v>0.5</v></c></row></sheetData>';
    $cell = exch_describe(exch_xlsx([['S', $sheet]], '', $styles))['sheets'][0]['cells']['A1'];

    expect($cell['format'])->toBe(['formatCode' => '# ?/?']);
});

it('writes state, hidden rows and columns, and a foreign format back, so describe round-trips them', function () {
    $schema = ['sheets' => [
        ['name' => 'Shown', 'cells' => ['A1' => ['value' => 1], 'B2' => ['value' => 2], 'C3' => ['value' => 0.5, 'format' => ['formatCode' => '# ?/?']]],
            'hiddenRows' => [2], 'hiddenColumns' => [1], 'columnWidths' => [1 => 120]],
        ['name' => 'Secret', 'cells' => ['A1' => ['value' => 'x']], 'state' => 'hidden'],
        ['name' => 'Deep', 'cells' => ['A1' => ['value' => 'y']], 'state' => 'veryHidden'],
    ]];
    $path = tempnam(sys_get_temp_dir(), 'holy_exch_rt_').'.xlsx';
    file_put_contents($path, Agent::toBytes($schema));
    $out = exch_describe($path);

    expect($out['sheets'][0]['hiddenRows'])->toBe([2]);
    expect($out['sheets'][0]['hiddenColumns'])->toBe([1]);
    expect($out['sheets'][0]['columnWidths'])->toBe([1 => 120.0]);
    expect($out['sheets'][0]['cells']['C3']['format'])->toBe(['formatCode' => '# ?/?']);
    expect($out['sheets'][0])->not->toHaveKey('state');
    expect($out['sheets'][1]['state'])->toBe('hidden');
    expect($out['sheets'][2]['state'])->toBe('veryHidden');
});

it('refuses a workbook in which every sheet is hidden, and a bad state or hidden index', function () {
    $errors = Agent::validate(['sheets' => [
        ['name' => 'A', 'cells' => [], 'state' => 'hidden'],
        ['name' => 'B', 'cells' => [], 'state' => 'veryHidden'],
    ]]);
    expect(array_column($errors, 'path'))->toContain('sheets');

    $errors = Agent::validate(['sheets' => [
        ['name' => 'A', 'cells' => [], 'state' => 'secret', 'hiddenRows' => [0], 'hiddenColumns' => [-1]],
    ]]);
    expect(array_column($errors, 'path'))->toBe(['sheets[0].state', 'sheets[0].hiddenRows[0]', 'sheets[0].hiddenColumns[0]']);

    $errors = Agent::validate(['sheets' => [['name' => 'A', 'cells' => ['A1' => ['value' => 1, 'format' => ['formatCode' => '']]]]]]);
    expect(array_column($errors, 'path'))->toBe(['sheets[0].cells.A1.format.formatCode']);
});

it('reads a hidden ods sheet, hidden rows and hidden columns', function () {
    $styles = '<style:style style:name="ta-hidden" style:family="table"><style:table-properties table:display="false"/></style:style>';
    $row = '<table:table-row><table:table-cell office:value-type="string"><text:p>a</text:p></table:table-cell><table:table-cell office:value-type="string"><text:p>b</text:p></table:table-cell></table:table-row>';
    $tables = '<table:table table:name="Shown">'
        .'<table:table-column/><table:table-column table:visibility="collapse"/><table:table-column table:number-columns-repeated="16382" table:visibility="collapse"/>'
        .$row
        .'<table:table-row table:visibility="collapse"><table:table-cell office:value-type="string"><text:p>hidden</text:p></table:table-cell></table:table-row>'
        .$row
        .'<table:table-row table:number-rows-repeated="1048000" table:visibility="collapse"><table:table-cell/></table:table-row>'
        .'</table:table>'
        .'<table:table table:name="Secret" table:style-name="ta-hidden">'.$row.'</table:table>';

    $path = tempnam(sys_get_temp_dir(), 'holy_exch_ods_').'.ods';
    $ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
        .'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
    $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
    $zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?>'."<office:document-content {$ns} office:version=\"1.3\">"
        ."<office:automatic-styles>{$styles}</office:automatic-styles><office:body><office:spreadsheet>{$tables}</office:spreadsheet></office:body></office:document-content>");
    $zip->close();
    $out = exch_describe($path);

    expect($out['sheets'][0]['hiddenRows'])->toBe([2]);
    expect($out['sheets'][0]['hiddenColumns'])->toBe([1]);
    expect($out['sheets'][0])->not->toHaveKey('state');
    expect($out['sheets'][1]['state'])->toBe('hidden');
});

it('reads bytes or a path through read(), the sibling-named alias of describe()', function () {
    $bytes = Agent::toBytes(['sheets' => [['name' => 'S', 'cells' => ['A1' => ['value' => 'x']]]]]);
    $path = tempnam(sys_get_temp_dir(), 'holy_exch_read_').'.xlsx';
    file_put_contents($path, $bytes);
    try {
        expect(Agent::read($bytes))->toBe(Agent::describe($path));
        expect(Agent::read($path))->toBe(Agent::describe($path));
    } finally {
        @unlink($path);
    }
});

it('says what read() was given when it is neither a spreadsheet nor a file', function () {
    expect(fn () => Agent::read('definitely not a workbook'))->toThrow(\HolySheet\Exceptions\UnsupportedFormatException::class);
});
