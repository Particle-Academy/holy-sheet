<?php

declare(strict_types=1);

use HolySheet\Agent;

/**
 * Print setup and row heights.
 *
 * Agent-built workbooks reviewed for a board printed across two pages (a wide
 * sheet split after its third column) and had rows packed tight against their
 * header fill. The schema could ask for neither. `print.fitToWidth` scales the
 * sheet to one page wide, `print.orientation` picks the page, `print.footer`
 * prints "Page {page} of {pages}" as Excel's own &P / &N codes, and
 * `rowHeights` sets a row's height in pixels (the unit `columnWidths` uses).
 * Without them nothing changes, byte for byte.
 */
function prSheet(array $extra = []): array
{
    return ['sheets' => [[
        'name' => 'Q3',
        'cells' => ['A1' => 'Title', 'B2' => 42],
        ...$extra,
    ]]];
}

function prSheetXml(array $schema): string
{
    $path = tempnam(sys_get_temp_dir(), 'pr').'.xlsx';
    Agent::write($schema, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($path);

    return $xml;
}

function prRead(array $schema): array
{
    $path = tempnam(sys_get_temp_dir(), 'pr').'.xlsx';
    Agent::write($schema, $path);
    $read = Agent::describe($path);
    @unlink($path);

    return $read;
}

it('fits the sheet to one page wide and picks the orientation', function () {
    $xml = prSheetXml(prSheet(['print' => ['orientation' => 'landscape', 'fitToWidth' => true]]));

    expect($xml)->toContain('<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>')
        ->and($xml)->toContain('<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>')
        ->and(strpos($xml, '<sheetPr>'))->toBeLessThan(strpos($xml, '<sheetData>'));
});

it('prints a footer with live page codes, escaping a literal ampersand', function () {
    $xml = prSheetXml(prSheet(['print' => ['footer' => ['left' => 'Q3 Review & Forecast', 'right' => 'Page {page} of {pages}']]]));

    expect($xml)->toContain('<headerFooter><oddFooter>&amp;LQ3 Review &amp;&amp; Forecast&amp;RPage &amp;P of &amp;N</oddFooter></headerFooter>');
});

it('sets row heights in pixels, on rows with and without cells', function () {
    $xml = prSheetXml(prSheet(['rowHeights' => ['1' => 32, '3' => 20]]));

    expect($xml)->toContain('<row r="1" ht="24" customHeight="1">')
        ->and($xml)->toContain('<row r="3" ht="15" customHeight="1"/>')
        ->and($xml)->toContain('<row r="2">');
});

it('adds nothing when neither is asked for', function () {
    $xml = prSheetXml(prSheet());

    expect($xml)->not->toContain('sheetPr')
        ->and($xml)->not->toContain('pageSetup')
        ->and($xml)->not->toContain('headerFooter')
        ->and($xml)->not->toContain('customHeight');
});

it('reads both back as it wrote them', function () {
    $extra = [
        'rowHeights' => ['1' => 32, '3' => 20],
        'print' => ['orientation' => 'landscape', 'fitToWidth' => true, 'footer' => ['left' => 'Q3 & Q4', 'center' => 'Confidential', 'right' => 'Page {page} of {pages}']],
    ];
    $sheet = prRead(prSheet($extra))['sheets'][0];

    expect($sheet['rowHeights'])->toBe(['1' => 32, '3' => 20])
        ->and($sheet['print'])->toBe($extra['print']);
});

it('refuses a malformed print or row height with a hint', function () {
    $errors = Agent::validate(prSheet([
        'rowHeights' => ['0' => 20, '2' => -1],
        'print' => ['orientation' => 'sideways', 'fitToWidth' => 'yes', 'footer' => ['left' => 7]],
    ]));
    $paths = array_column($errors, 'path');

    expect($paths)->toContain('sheets[0].rowHeights.0')
        ->and($paths)->toContain('sheets[0].rowHeights.2')
        ->and($paths)->toContain('sheets[0].print.orientation')
        ->and($paths)->toContain('sheets[0].print.fitToWidth')
        ->and($paths)->toContain('sheets[0].print.footer.left');
});

it('publishes both in the schema', function () {
    $sheet = Agent::toolDefinition()['definitions']['Sheet']['properties'];

    expect($sheet)->toHaveKeys(['rowHeights', 'print'])
        ->and(json_encode($sheet['print']))->toContain('{pages}');
});

it('fits a short sheet onto one page, so a chart below the table is never cut', function () {
    // A board summary is a table, notes and a chart. Printed one page wide it
    // is as tall as it needs, and the page break fell through the chart in
    // three of four agent-built workbooks reviewed. fitToPage scales it all
    // onto one sheet of paper.
    $xml = prSheetXml(prSheet(['print' => ['orientation' => 'portrait', 'fitToPage' => true]]));

    expect($xml)->toContain('<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>')
        ->and($xml)->toContain('<pageSetup orientation="portrait" fitToWidth="1" fitToHeight="1"/>');

    $read = prRead(prSheet(['print' => ['fitToPage' => true, 'footer' => ['right' => 'Page {page} of {pages}']]]))['sheets'][0];
    expect($read['print'])->toBe(['fitToPage' => true, 'footer' => ['right' => 'Page {page} of {pages}']]);

    expect(array_column(Agent::validate(prSheet(['print' => ['fitToPage' => 1]])), 'path'))->toBe(['sheets[0].print.fitToPage'])
        ->and(json_encode(Agent::toolDefinition()['definitions']['Sheet']['properties']['print']))->toContain('fitToPage');
});
