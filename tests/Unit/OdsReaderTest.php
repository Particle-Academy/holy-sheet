<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Exceptions\UnsupportedFormatException;

/**
 * OpenDocument Spreadsheet (.ods) describes to the same schema as .xlsx.
 *
 * Asked for by a Laravel host that kept a whole second spreadsheet library
 * alive for one branch of one `match`: xlsx went through holy-sheet, ods through
 * something else with its own row/cell model and a converter to make it look
 * like a holy-sheet schema. The point of this reader is that the caller's
 * special case disappears, so the strongest test here is the equivalence one:
 * the SAME authored workbook, saved as xlsx by this package and as ods by
 * LibreOffice, describes to the same schema.
 *
 * Fixtures (tests/fixtures/ods, see README.md there):
 *   - workbook.xlsx / workbook.ods — one schema, written by holy-sheet, converted
 *     by LibreOffice 26. Real OpenDocument output, repeated-cell compression and
 *     all.
 *   - native.ods — converted by LibreOffice from native.fods, a flat ODS authored
 *     by hand for what an xlsx conversion never produces (time values, a native
 *     currency cell, spans and links in a paragraph, OpenFormula edge cases).
 *   - edge.ods — zipped by generate.php from hand-written XML, WITHOUT
 *     LibreOffice, for constructs LibreOffice normalises away on save (rows
 *     repeated WITH content, content in covered cells, grouped rows, raw white
 *     space) or never writes (msoxl/oooc formulas, 3D ranges, date offsets). The
 *     Node and Python ports diff their output against this reader on it.
 *   - The rest is built in memory below, so the XML under test sits next to the
 *     assertion about it.
 */
function ods_fixture(string $name): string
{
    return __DIR__.'/../fixtures/ods/'.$name;
}

/**
 * Build a minimal .ods from table XML, for constructs no fixture carries.
 *
 * @param  string  $tables  one or more <table:table> elements
 * @param  string  $automaticStyles  children of <office:automatic-styles>
 */
function ods_package(string $tables, string $automaticStyles = '', string $mimetype = 'application/vnd.oasis.opendocument.spreadsheet'): string
{
    $ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
        .'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
        .'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
        .'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
        .'xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" '
        .'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" '
        .'xmlns:dc="http://purl.org/dc/elements/1.1/" '
        .'xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0"';

    $content = '<?xml version="1.0" encoding="UTF-8"?>'
        ."<office:document-content {$ns} office:version=\"1.3\">"
        ."<office:automatic-styles>{$automaticStyles}</office:automatic-styles>"
        ."<office:body><office:spreadsheet>{$tables}</office:spreadsheet></office:body>"
        .'</office:document-content>';

    $path = tempnam(sys_get_temp_dir(), 'holy_ods_').'.ods';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('mimetype', $mimetype);
    $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
    $zip->addFromString('content.xml', $content);
    $zip->close();

    return $path;
}

/** Describe an in-memory package and delete it. */
function ods_describe(string $tables, string $automaticStyles = ''): array
{
    $path = ods_package($tables, $automaticStyles);
    try {
        return Agent::describe($path);
    } finally {
        @unlink($path);
    }
}

/**
 * The xlsx reader reports `displayFormat: auto` for every cell, because Excel's
 * built-in style 0 is the "General" number format. OpenDocument has no such
 * default on an unstyled cell, so the ods reader does not invent one. For the
 * comparison, `auto` (and a format left empty without it) counts as no format.
 */
function ods_without_auto(array $schema): array
{
    foreach ($schema['sheets'] as &$sheet) {
        foreach ($sheet['cells'] as &$cell) {
            if (($cell['format']['displayFormat'] ?? null) === 'auto') {
                unset($cell['format']['displayFormat']);
                if ($cell['format'] === []) {
                    unset($cell['format']);
                }
            }
        }
    }

    return $schema;
}

// ─── The xlsx-converted fixture ─────────────────────────────────────────────

it('reads every value type from a LibreOffice-written ods', function () {
    $cells = Agent::describe(ods_fixture('workbook.ods'))['sheets'][0]['cells'];

    expect($cells['A2'])->toBe(['value' => 100, 'comment' => ['text' => 'An integer', 'author' => 'Fixture']])
        ->and($cells['B2'])->toBe(['value' => 9800.5])
        ->and($cells['C2'])->toBe(['value' => -42])
        ->and($cells['A3'])->toBe(['value' => 0.25, 'format' => ['displayFormat' => 'percentage', 'decimals' => 1]])
        ->and($cells['B3'])->toBe(['value' => 1234.5, 'format' => ['displayFormat' => 'currency', 'decimals' => 2, 'currency' => 'USD']])
        ->and($cells['C3'])->toBe(['value' => 99, 'format' => ['displayFormat' => 'currency', 'decimals' => 0, 'currency' => 'EUR']])
        ->and($cells['A4'])->toBe(['value' => '2024-03-15', 'format' => ['displayFormat' => 'date']])
        ->and($cells['B4'])->toBe(['value' => '2024-03-15T10:30:00Z', 'format' => ['displayFormat' => 'datetime']])
        ->and($cells['C4'])->toBe(['value' => 1234.5678, 'format' => ['displayFormat' => 'number', 'decimals' => 2]])
        // LibreOffice stores a typed TRUE/FALSE as the formula TRUE()/FALSE().
        // What the author entered was a boolean, and that is what xlsx reports.
        ->and($cells['A5'])->toBe(['value' => true])
        ->and($cells['B5'])->toBe(['value' => false])
        ->and($cells['A6'])->toBe(['value' => "first line\nsecond line"])
        ->and($cells['B6'])->toBe(['value' => 'spaced   out'])
        ->and($cells['C6'])->toBe(['value' => 'Fish & Chips <tasty>']);
});

it('reads cell formatting from automatic styles, parents and column defaults', function () {
    $sheets = Agent::describe(ods_fixture('workbook.ods'))['sheets'];

    expect($sheets[0]['cells']['A1'])->toBe(['value' => 'Kind', 'format' => ['bold' => true]])
        ->and($sheets[0]['cells']['B1'])->toBe(['value' => 'Value', 'format' => ['bold' => true, 'italic' => true, 'textAlign' => 'center']])
        // 14pt is reported because it differs from the document's Default cell
        // style (11pt here, imported from the xlsx); every styled cell repeats
        // the 11pt, and reporting that would be noise.
        ->and($sheets[0]['cells']['C1'])->toBe(['value' => 'Styled', 'format' => [
            'color' => '#1D4ED8', 'backgroundColor' => '#FEF3C7', 'fontSize' => 14, 'borderBottom' => '#111827',
        ]])
        // No table:style-name on this cell: it is bold through its COLUMN's
        // table:default-cell-style-name.
        ->and($sheets[2]['cells']['A1'])->toBe(['value' => 'Across', 'format' => ['bold' => true]]);
});

it('translates OpenFormula into the A1 syntax the xlsx reader returns', function () {
    $cells = Agent::describe(ods_fixture('workbook.ods'))['sheets'][0]['cells'];

    expect($cells['A7'])->toBe(['value' => null, 'formula' => 'SUM(A2:C2)', 'computedValue' => 9858.5])
        ->and($cells['B7'])->toBe(['value' => null, 'formula' => "'Other Sheet'!A1*2", 'computedValue' => 42])
        // `;` separates arguments in OpenFormula; the one inside the string stays.
        ->and($cells['C7'])->toBe(['value' => null, 'formula' => 'IF(A5,LEN("a;b"),0)', 'computedValue' => 3])
        ->and($cells['D7'])->toBe(['value' => null, 'formula' => 'ROUND(B2/4,1)', 'computedValue' => 2450.1]);
});

it('expands repeated cells and rows without inventing empty ones', function () {
    $cells = Agent::describe(ods_fixture('workbook.ods'))['sheets'][1]['cells'];

    // table:number-columns-repeated="5" is five cells; the 16376-wide and
    // 15-row empty repeats contribute nothing.
    expect(array_keys($cells))->toBe(['A1', 'B1', 'C1', 'D1', 'E1', 'A2', 'B2', 'A3', 'B3', 'A4', 'B4', 'H20'])
        ->and($cells['E1'])->toBe(['value' => 7])
        ->and($cells['H20'])->toBe(['value' => 'far']);
});

it('reads merges, sheet names, an empty sheet and document metadata', function () {
    $schema = Agent::describe(ods_fixture('workbook.ods'));

    expect(array_column($schema['sheets'], 'name'))->toBe(['Types', 'Repeats', 'Merged', 'Other Sheet', 'Empty'])
        ->and($schema['sheets'][2]['mergedRegions'])->toBe([['start' => 'A1', 'end' => 'C1'], ['start' => 'A2', 'end' => 'A3']])
        ->and($schema['sheets'][2]['cells']['B3'])->toBe(['value' => 2])
        ->and($schema['sheets'][4])->toBe(['name' => 'Empty', 'cells' => []])
        // ODF dates carry no zone; the xlsx side reports UTC with a Z.
        ->and($schema['meta'])->toBe(['creator' => 'Holy Sheet ODS fixture', 'created' => '2026-09-13T12:00:00Z']);
});

it('describes the same workbook the same way whether it was saved as xlsx or ods', function () {
    $fromXlsx = ods_without_auto(Agent::describe(ods_fixture('workbook.xlsx')));
    $fromOds = Agent::describe(ods_fixture('workbook.ods'));

    // The one field that does not survive: frozen panes are LibreOffice VIEW
    // settings, and a headless conversion writes no view. The ods reader does not
    // read them either (see OdsReader), so they are excluded rather than faked.
    unset($fromXlsx['sheets'][0]['frozenRows'], $fromXlsx['sheets'][0]['frozenCols']);

    expect($fromOds)->toBe($fromXlsx);
});

it('returns a schema that writes straight back out', function () {
    $schema = Agent::describe(ods_fixture('workbook.ods'));

    // The empty sheet stays in: it describes as `cells: []`, which the validator
    // used to reject as "not a map". See ValidatorTest.

    expect(Agent::validate($schema))->toBe([]);

    $path = tempnam(sys_get_temp_dir(), 'holy_ods_rt_').'.xlsx';
    Agent::write($schema, $path);
    try {
        expect(ods_without_auto(Agent::describe($path))['sheets'][0]['cells']['B3'])->toBe($schema['sheets'][0]['cells']['B3']);
    } finally {
        @unlink($path);
    }
});

// ─── The hand-authored native fixture ───────────────────────────────────────

it('reads native OpenDocument values: time, currency, runs, links, tabs and breaks', function () {
    $schema = Agent::describe(ods_fixture('native.ods'));
    $cells = $schema['sheets'][0]['cells'];

    expect($cells['A1'])->toBe(['value' => 'Hello bold and a link', 'format' => [
        'bold' => true, 'italic' => true, 'color' => '#F8FAFC', 'backgroundColor' => '#0F172A', 'fontSize' => 16,
        'borderTop' => '#334155', 'borderRight' => '#334155', 'borderBottom' => '#334155', 'borderLeft' => '#334155',
    ]])
        // A time is a time of day on the spreadsheet epoch, as the xlsx reader
        // renders a serial fraction under a time format.
        ->and($cells['B1'])->toBe(['value' => '1899-12-30T10:30:00Z', 'format' => ['displayFormat' => 'datetime']])
        ->and($cells['C1'])->toBe(['value' => 19.99, 'format' => ['textAlign' => 'right', 'displayFormat' => 'currency', 'decimals' => 2, 'currency' => 'EUR']])
        ->and($cells['D1'])->toBe(['value' => 0.125, 'format' => ['displayFormat' => 'percentage', 'decimals' => 2]])
        ->and($cells['A2'])->toBe(['value' => false])
        ->and($cells['B2'])->toBe(['value' => '1999-12-31', 'format' => ['displayFormat' => 'date']])
        ->and($cells['C2'])->toBe(['value' => "a\tb\nc", 'comment' => ['text' => "First note paragraph\nSecond note paragraph"]])
        ->and($cells['D2'])->toBe(['value' => 1500])
        ->and($schema['meta'])->toBe(['creator' => 'Holy Sheet native ODS fixture', 'created' => '2026-09-13T08:15:00Z']);
});

it('translates sheet references, absolute cells, quoted strings and inline arrays', function () {
    $cells = Agent::describe(ods_fixture('native.ods'))['sheets'][0]['cells'];

    expect($cells['A3'])->toBe(['value' => null, 'formula' => 'SUM(Data!A1:A3)', 'computedValue' => 6])
        ->and($cells['B3'])->toBe(['value' => null, 'formula' => '$D$2*2', 'computedValue' => 3000])
        ->and($cells['C3'])->toBe(['value' => null, 'formula' => 'CONCATENATE("x;y","""q""")', 'computedValue' => 'x;y"q"'])
        // OpenFormula inline arrays separate columns with ; and rows with |.
        ->and($cells['D3'])->toBe(['value' => null, 'formula' => 'SUMPRODUCT({1,2;3,4},{1,1;1,1})', 'computedValue' => 10]);
});

// ─── edge.ods: hand-built, and the fixture the Node and Python ports diff ───

it('resolves styles through parents, row and column defaults, and ignores what does not apply', function () {
    $cells = Agent::describe(ods_fixture('edge.ods'))['sheets'][0]['cells'];

    expect($cells)->toBe([
        // Parent "Heading" (styles.xml) gives weight 600, a green colour, a
        // background, 18pt and a red border on every side. The child turns the
        // colour back to automatic, removes the left border, and puts an
        // uncoloured rule on top (which is black).
        'A1' => ['value' => 'inherit', 'format' => [
            'bold' => true, 'italic' => true, 'backgroundColor' => '#123456', 'fontSize' => 18,
            'borderTop' => '#000000', 'borderRight' => '#FF0000', 'borderBottom' => '#FF0000',
        ]],
        // text-align-source="value-type": the stored "center" is not in effect.
        'B1' => ['value' => 1],
        'C1' => ['value' => 'start', 'format' => ['textAlign' => 'left']],
        // A number style with no decimal places is "General".
        'D1' => ['value' => 1234.5, 'format' => ['displayFormat' => 'auto']],
        // Scientific notation has no displayFormat, so none is claimed.
        'E1' => ['value' => 12345],
        'F1' => ['value' => '007', 'format' => ['displayFormat' => 'text']],
        'G1' => ['value' => 9.5, 'format' => ['displayFormat' => 'currency', 'decimals' => 2, 'currency' => 'CHF']],
        'H1' => ['value' => 3.25, 'format' => ['displayFormat' => 'currency', 'decimals' => 1, 'currency' => 'GBP']],
        'I1' => ['value' => '2024-02-29T15:00:00Z', 'format' => ['displayFormat' => 'datetime']],
        // Transparent background, hidden border, normal weight and the default
        // 10pt all override the parent; the parent's colour still shows.
        'J1' => ['value' => 'clear', 'format' => ['color' => '#00FF00']],
        'K1' => ['value' => 2, 'format' => ['fontSize' => 12]],
        // A relative size is not a point size.
        'L1' => ['value' => 3],
        // Two styles naming each other as parent: read once each, no hang.
        'M1' => ['value' => 'loop', 'format' => ['bold' => true, 'italic' => true]],
        // The style is the NEGATIVE part, with no symbol; its map to the positive
        // part is what says currency. Reading the negative part alone says number.
        'N1' => ['value' => -5, 'format' => ['displayFormat' => 'currency', 'decimals' => 2, 'currency' => 'USD']],
        'A2' => ['value' => 'row default wins', 'format' => ['italic' => true]],
        'B2' => ['value' => 'cell style wins', 'format' => ['textAlign' => 'left']],
        'A3' => ['value' => 'column default', 'format' => ['textAlign' => 'left']],
        // styles.xml also has a GRAPHIC style called Default, 30pt bold. It is
        // not a cell style and must not leak into one.
        'B3' => ['value' => 'unknown style'],
    ]);
});

it('collapses white space the way ODF defines it, skips comments in a paragraph, and matches namespaces by URI', function () {
    $cells = Agent::describe(ods_fixture('edge.ods'))['sheets'][1]['cells'];

    expect($cells)->toBe([
        'A1' => ['value' => 'leading and inner tabs newline '],
        // text:s is never collapsed, and a space either side of it survives as one.
        'B1' => ['value' => 'a   b   '],
        'C1' => ['value' => "Heading\nbody span nested"],
        'D1' => ['value' => 'xyz ☺ & <raw>'],
        // office:string-value is the value; the paragraph is only its display.
        'E1' => ['value' => 'stored'],
        // F1 is an untyped cell with an empty paragraph: nothing.
        'G1' => ['value' => null, 'comment' => ['text' => 'only a note', 'author' => 'Ann']],
        'H1' => ['value' => ''],
        // office: under the prefix "o", beside a decoy namespace reusing the
        // same local names. By URI it is 42; by local name it would be ''.
        'I1' => ['value' => 42],
    ]);
});

it('translates 3D ranges, whole rows and columns, errors and sheet names Excel must quote', function () {
    $cells = Agent::describe(ods_fixture('edge.ods'))['sheets'][2]['cells'];

    expect($cells)->toBe([
        'A1' => ['value' => null, 'formula' => 'SUM(Sheet1:Sheet3!A1:B2)', 'computedValue' => 0],
        'B1' => ['value' => null, 'formula' => "SUM('Q1 Data:Q4 Data'!A1:A1)", 'computedValue' => 1.5],
        // A formula that evaluates to an error caches the error text, as xlsx does.
        'C1' => ['value' => null, 'formula' => '#REF!+1', 'computedValue' => '#REF!'],
        'D1' => ['value' => null, 'formula' => 'SUM(A:A,1:1)', 'computedValue' => 12],
        'E1' => ['value' => null, 'formula' => 'IF(A1>0,"a;b|c",{1;2})', 'computedValue' => 1],
        // A digit-led name, a plain identifier, and a name that reads as R1C1.
        'F1' => ['value' => null, 'formula' => "'2024'!A1&Sheet_1!A1&'R1C1'!A1", 'computedValue' => 'x'],
        'G1' => ['value' => null, 'formula' => 'NA()'],
        // Union is documented as untranslated; this pins it in all three runtimes.
        'H1' => ['value' => null, 'formula' => 'A1~B1', 'computedValue' => 0],
        'I1' => ['value' => null, 'formula' => 'SUM(A1:B1, 2)', 'computedValue' => 3],
        'J1' => ['value' => null, 'formula' => 'SUM(A1:B1;2)', 'computedValue' => 3],
        'K1' => ['value' => null, 'formula' => "['file:///C:/x.ods'#\$Sheet1.A1]", 'computedValue' => 4],
        // FALSE() is a literal only when the cell says the value IS a boolean.
        'L1' => ['value' => null, 'formula' => 'FALSE()', 'computedValue' => 0],
    ]);
});

it('expands a repeated row group with a spanned empty cell and content under the span', function () {
    $sheet = Agent::describe(ods_fixture('edge.ods'))['sheets'][3];

    expect($sheet['cells'])->toBe([
        'A1' => ['value' => 'head'],
        'A2' => ['value' => 4], 'B2' => ['value' => 4], 'D2' => ['value' => 'under'],
        'A3' => ['value' => 4], 'B3' => ['value' => 4], 'D3' => ['value' => 'under'],
    ])
        // A merge with nothing in its anchor is still a merge.
        ->and($sheet['mergedRegions'])->toBe([['start' => 'C2', 'end' => 'D2'], ['start' => 'C3', 'end' => 'D3']]);
});

it('rounds half a second up, applies offsets, and lets the cell currency beat the style', function () {
    $schema = Agent::describe(ods_fixture('edge.ods'));

    expect($schema['sheets'][4]['cells'])->toBe([
        'A1' => ['value' => '1899-12-29T23:00:00Z', 'format' => ['displayFormat' => 'datetime']],
        'B1' => ['value' => '2024-03-10T06:30:00Z', 'format' => ['displayFormat' => 'datetime']],
        'C1' => ['value' => '1900-01-01', 'format' => ['displayFormat' => 'date']],
        // Half a second rounds away from zero, as PHP's round() does; a port using
        // banker's rounding gets 2024-12-31T23:59:59Z here.
        'D1' => ['value' => '2025-01-01T00:00:00Z', 'format' => ['displayFormat' => 'datetime']],
        'E1' => ['value' => '1899-12-30T00:00:03Z', 'format' => ['displayFormat' => 'datetime']],
        // A date-only data style shows the day of a value that has a time.
        'F1' => ['value' => '2024-05-05', 'format' => ['displayFormat' => 'date']],
        'G1' => ['value' => 1200, 'format' => ['displayFormat' => 'currency', 'currency' => 'JPY']],
        'H1' => ['value' => 0.5, 'format' => ['displayFormat' => 'percentage']],
        'I1' => ['value' => 1500.0],
        'J1' => ['value' => 7, 'format' => ['displayFormat' => 'currency', 'decimals' => 2, 'currency' => 'EUR']],
        // An unparseable date is not guessed at.
        'K1' => ['value' => null, 'format' => ['displayFormat' => 'date']],
        'L1' => ['value' => null, 'formula' => 'A1', 'computedValue' => 1.25, 'format' => ['displayFormat' => 'datetime']],
    ])
        // No initial-creator: dc:creator. A creation date with a zone keeps it.
        ->and($schema['meta'])->toBe(['creator' => 'Edge Author', 'created' => '2026-01-02T03:04:05.123+01:00']);
});

// ─── Constructs LibreOffice normalises away, built in memory ────────────────

it('expands rows repeated with content, and skips a trailing million-row repeat', function () {
    $schema = ods_describe(
        '<table:table table:name="R">'
        .'<table:table-row table:number-rows-repeated="3">'
        .'<table:table-cell office:value-type="float" office:value="5"/>'
        .'<table:table-cell table:number-columns-repeated="2"/>'
        .'<table:table-cell office:value-type="string"><text:p>tail</text:p></table:table-cell>'
        .'</table:table-row>'
        .'<table:table-row table:number-rows-repeated="1048573"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
        .'</table:table>'
    );

    expect($schema['sheets'][0]['cells'])->toBe([
        'A1' => ['value' => 5], 'D1' => ['value' => 'tail'],
        'A2' => ['value' => 5], 'D2' => ['value' => 'tail'],
        'A3' => ['value' => 5], 'D3' => ['value' => 'tail'],
    ]);
});

it('reads rows inside header-row and row groups, in document order', function () {
    $schema = ods_describe(
        '<table:table table:name="G">'
        .'<table:table-header-rows><table:table-row><table:table-cell office:value-type="string"><text:p>head</text:p></table:table-cell></table:table-row></table:table-header-rows>'
        .'<table:table-row-group><table:table-row><table:table-cell office:value-type="float" office:value="1"/></table:table-row>'
        .'<table:table-row-group><table:table-row><table:table-cell office:value-type="float" office:value="2"/></table:table-row></table:table-row-group>'
        .'</table:table-row-group>'
        .'<table:table-row><table:table-cell office:value-type="float" office:value="3"/></table:table-row>'
        .'</table:table>'
    );

    expect($schema['sheets'][0]['cells'])->toBe(['A1' => ['value' => 'head'], 'A2' => ['value' => 1], 'A3' => ['value' => 2], 'A4' => ['value' => 3]]);
});

it('keeps content that sits in a covered cell, and records both span directions', function () {
    $schema = ods_describe(
        '<table:table table:name="M"><table:table-row>'
        .'<table:table-cell table:number-columns-spanned="2" table:number-rows-spanned="2" office:value-type="string"><text:p>big</text:p></table:table-cell>'
        .'<table:covered-table-cell office:value-type="string"><text:p>hidden</text:p></table:covered-table-cell>'
        .'</table:table-row></table:table>'
    );

    expect($schema['sheets'][0]['cells'])->toBe(['A1' => ['value' => 'big'], 'B1' => ['value' => 'hidden']])
        ->and($schema['sheets'][0]['mergedRegions'])->toBe([['start' => 'A1', 'end' => 'B2']]);
});

it('reports a formula result of every type as the xlsx reader would cache it', function () {
    $cells = ods_describe(
        '<table:table table:name="F"><table:table-row>'
        .'<table:table-cell table:formula="of:=DATE(2024;3;15)" office:value-type="date" office:date-value="2024-03-15"/>'
        .'<table:table-cell table:formula="of:=[.A1]+0.5" office:value-type="date" office:date-value="2024-03-15T12:00:00"/>'
        .'<table:table-cell table:formula="of:=TIME(6;0;0)" office:value-type="time" office:time-value="PT6H"/>'
        .'<table:table-cell table:formula="of:=1=1" office:value-type="boolean" office:boolean-value="true"/>'
        .'<table:table-cell table:formula="of:=&quot;a&quot;" office:value-type="string"><text:p>a</text:p></table:table-cell>'
        .'</table:table-row></table:table>'
    )['sheets'][0]['cells'];

    // A date result is cached as the serial number, as in an xlsx <v>, and the
    // value type says it is a date even with no data style on the cell.
    expect($cells['A1'])->toBe(['value' => null, 'formula' => 'DATE(2024,3,15)', 'computedValue' => 45366, 'format' => ['displayFormat' => 'date']])
        ->and($cells['B1'])->toBe(['value' => null, 'formula' => 'A1+0.5', 'computedValue' => 45366.5, 'format' => ['displayFormat' => 'datetime']])
        ->and($cells['C1'])->toBe(['value' => null, 'formula' => 'TIME(6,0,0)', 'computedValue' => 0.25, 'format' => ['displayFormat' => 'datetime']])
        // Only a bare TRUE()/FALSE() is a boolean literal; any other boolean formula stays a formula.
        ->and($cells['D1'])->toBe(['value' => null, 'formula' => '1=1', 'computedValue' => true])
        ->and($cells['E1'])->toBe(['value' => null, 'formula' => '"a"', 'computedValue' => 'a']);
});

it('passes Excel-syntax formulas through, and translates the older OpenOffice prefix', function () {
    $cells = ods_describe(
        '<table:table table:name="P"><table:table-row>'
        .'<table:table-cell table:formula="msoxl:=SUM(A1:B2,C3)" office:value-type="float" office:value="0"/>'
        .'<table:table-cell table:formula="oooc:=SUM([.A1:.B2];[.C3])" office:value-type="float" office:value="0"/>'
        .'<table:table-cell table:formula="of:=SUM([&apos;It&apos;&apos;s here&apos;.A1:.A2];[$Other.$B$1:.$B$9])" office:value-type="float" office:value="0"/>'
        .'</table:table-row></table:table>'
    )['sheets'][0]['cells'];

    expect($cells['A1']['formula'])->toBe('SUM(A1:B2,C3)')
        ->and($cells['B1']['formula'])->toBe('SUM(A1:B2,C3)')
        ->and($cells['C1']['formula'])->toBe("SUM('It''s here'!A1:A2,Other!\$B\$1:\$B\$9)");
});

it('converts date and time values exactly', function () {
    $cells = ods_describe(
        '<table:table table:name="D"><table:table-row>'
        .'<table:table-cell office:value-type="time" office:time-value="PT36H15M30S"/>'
        .'<table:table-cell office:value-type="date" office:date-value="2024-01-01T23:59:59.6"/>'
        .'<table:table-cell office:value-type="date" office:date-value="2024-06-01T10:00:00+02:00"/>'
        .'<table:table-cell office:value-type="string"><text:p/></table:table-cell>'
        .'<table:table-cell><text:p>untyped</text:p></table:table-cell>'
        .'</table:table-row></table:table>'
    )['sheets'][0]['cells'];

    expect($cells['A1'])->toBe(['value' => '1899-12-31T12:15:30Z', 'format' => ['displayFormat' => 'datetime']])
        // Seconds round to the nearest whole second, as a serial does in xlsx.
        ->and($cells['B1'])->toBe(['value' => '2024-01-02T00:00:00Z', 'format' => ['displayFormat' => 'datetime']])
        // An explicit offset is honoured and the result reported in UTC.
        ->and($cells['C1'])->toBe(['value' => '2024-06-01T08:00:00Z', 'format' => ['displayFormat' => 'datetime']])
        // A string cell with an empty paragraph is an empty string, not absent.
        ->and($cells['D1'])->toBe(['value' => ''])
        // A paragraph with no value type is still text.
        ->and($cells['E1'])->toBe(['value' => 'untyped']);
});

// ─── Dispatch ───────────────────────────────────────────────────────────────

it('still describes xlsx through the same entry point', function () {
    expect(Agent::describe(ods_fixture('workbook.xlsx'))['sheets'][0]['cells']['A2']['value'])->toBe(100);
});

it('names what it cannot read instead of failing on a zip it does not know', function () {
    $path = ods_package('<table:table table:name="X"/>', '', 'application/vnd.oasis.opendocument.text');

    try {
        expect(fn () => Agent::describe($path))
            ->toThrow(UnsupportedFormatException::class, 'application/vnd.oasis.opendocument.text');
    } finally {
        @unlink($path);
    }
});

it('refuses a file that is not a zip at all, and the exception is still a RuntimeException', function () {
    $path = tempnam(sys_get_temp_dir(), 'holy_notzip_').'.csv';
    file_put_contents($path, "a,b\n1,2\n");

    try {
        // "zip archive" is the phrase the old RuntimeException carried; kept for
        // anyone matching on it.
        expect(fn () => Agent::describe($path))->toThrow(UnsupportedFormatException::class, 'zip archive')
            ->and(is_subclass_of(UnsupportedFormatException::class, RuntimeException::class))->toBeTrue();
    } finally {
        @unlink($path);
    }
});
