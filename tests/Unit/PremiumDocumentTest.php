<?php

declare(strict_types=1);

use HolySheet\Agent;

/**
 * Can this package produce a document someone would be PROUD to send?
 *
 * ## Why this is not covered by the tests next to it
 *
 * `StylesTest` proves each feature works ON ITS OWN — a currency column, a
 * merge, a frozen pane. Every one passes, and none of them asks the question a
 * user actually has, which is whether you can turn all of it on at once and get
 * a document with flair rather than a grid of plain text.
 *
 * Those are different questions, and the second is where the failures live.
 * Styles in xlsx are a DEDUPED TABLE — fonts, fills, borders and number formats
 * are pooled and referenced by index. A per-feature test writes one styled cell,
 * so index 1 is always the thing it just made. Combine eight features and the
 * indices start colliding: the classic result is a workbook where the last
 * style silently wins and the earlier ones read as unstyled.
 *
 * **A dropped style is invisible.** The file opens, Excel shows no error, and
 * the document is merely plain. Nobody files a bug against a spreadsheet that
 * looks boring — they conclude the library is boring. So these assert the
 * ARTIFACT: unzip, and look for the feature in the XML. "It wrote a file" is a
 * check that passes just as happily for a document with no formatting at all.
 */

/**
 * Everything a rich workbook uses, on ONE sheet.
 *
 * The table occupies rows 1-5 (header, three data rows, totals). The styled
 * cells sit below it, plus one that reaches INTO the table to highlight a
 * figure. Both halves in a single sheet is the point: see the composition test.
 */
function premiumWorkbook(): array
{
    return ['sheets' => [[
        'name' => 'Q3 Revenue',
        'theme' => 'default',
        'columns' => [
            ['header' => 'Region', 'width' => 24],
            ['header' => 'Revenue', 'type' => 'currency', 'currency' => 'USD', 'decimals' => 2],
            ['header' => 'Growth', 'type' => 'percent', 'decimals' => 1],
            ['header' => 'Closed', 'type' => 'date'],
        ],
        'rows' => [
            ['North', 1250000.5, 0.184, '2026-09-30'],
            ['South', 980400.25, -0.042, '2026-09-30'],
            ['EMEA', 2100000.0, 0.311, '2026-09-30'],
        ],
        'totals' => ['Revenue' => 'sum', 'Growth' => 'avg'],
        'mergedRegions' => [['start' => 'A7', 'end' => 'D7']],
        'frozenRows' => 1,
        'frozenCols' => 1,
        'columnWidths' => [0 => 200, 1 => 150],
        'cells' => [
            // A title band beneath the table: large, bold, reversed out of a
            // dark fill, merged across all four columns.
            'A7' => ['value' => 'Q3 REVENUE BY REGION', 'format' => [
                'bold' => true,
                'fontSize' => 20,
                'color' => '#FFFFFF',
                'backgroundColor' => '#1F3864',
                'textAlign' => 'center',
            ]],
            // A callout: italic, coloured, ruled above and below.
            'A9' => ['value' => 'EMEA led on growth', 'format' => [
                'italic' => true,
                'fontSize' => 11,
                'color' => '#C00000',
                'borderTop' => '#C00000',
                'borderBottom' => '#C00000',
            ]],
            'B9' => ['value' => 'see note', 'comment' => 'Reviewed by finance 2026-10-01'],
            // Reaches INTO the table: emphasise one figure without restating
            // the column's currency format. See the inheritance test.
            'B4' => ['value' => 2100000.0, 'format' => ['bold' => true]],
        ],
    ]]];
}

/** Read one part out of a written workbook, without leaving a file behind. */
function partOf(array $schema, string $part): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'hs-premium-').'.xlsx';

    try {
        Agent::write($schema, $tmp);

        $zip = new ZipArchive();
        expect($zip->open($tmp))->toBeTrue();
        $xml = $zip->getFromName($part);
        $zip->close();

        // A missing part and an empty one are different failures, and the
        // difference is the whole diagnosis: absent means the writer never
        // emitted it, empty means it emitted nothing into it.
        expect($xml)->not->toBeFalse("workbook has no {$part}");

        return (string) $xml;
    } finally {
        @unlink($tmp);
    }
}

function has(string $haystack, string $needle): bool
{
    return str_contains($haystack, $needle);
}

describe('a rich workbook keeps EVERY styling feature, together', function () {
    it('keeps all eight per-cell format fields in one document', function () {
        // The composition check. Each of these has a home in the deduped style
        // table, and they are asserted in one workbook precisely because the
        // per-feature tests cannot see an index collision between them.
        $styles = partOf(premiumWorkbook(), 'xl/styles.xml');

        $expected = [
            'bold' => '<b/>',
            'italic' => '<i/>',
            'font size 20' => 'val="20"',
            'font size 11' => 'val="11"',
            'white text' => 'FFFFFFFF',
            'dark navy fill' => 'FF1F3864',
            'red text' => 'FFC00000',
            'centred' => 'center',
        ];

        $missing = [];
        foreach ($expected as $label => $needle) {
            if (! has($styles, $needle)) {
                $missing[] = $label;
            }
        }

        expect($missing, 'these styles were accepted and never reached styles.xml: '.implode(', ', $missing))
            ->toBe([]);
    });

    it('rules the callout above AND below, not just one edge', function () {
        // Borders are the easiest thing to half-implement: an edge that is
        // written for `top` and dropped for `bottom` still produces a document
        // with borders in it, so a loose assertion passes.
        $styles = partOf(premiumWorkbook(), 'xl/styles.xml');

        expect(has($styles, '<top'))->toBeTrue('no top border element');
        expect(has($styles, '<bottom'))->toBeTrue('no bottom border element');
        expect(substr_count($styles, 'FFC00000'))->toBeGreaterThanOrEqual(2);
    });

    it('lays the sheet out: merge, freeze and widths survive together', function () {
        $sheet = partOf(premiumWorkbook(), 'xl/worksheets/sheet1.xml');

        expect(has($sheet, '<mergeCell ref="A7:D7"/>'))->toBeTrue('title band did not merge');
        expect(has($sheet, 'state="frozen"'))->toBeTrue('panes not frozen');
        expect(has($sheet, '<col min="1" max="1"'))->toBeTrue('column widths not applied');
    });

    it('formats numbers as money, percent and dates rather than raw digits', function () {
        // The difference between a premium document and a boring one is often
        // exactly this: 1250000.5 versus $1,250,000.50. A number format that
        // silently does not apply leaves a correct spreadsheet nobody wants.
        $styles = partOf(premiumWorkbook(), 'xl/styles.xml');

        expect(has($styles, '<numFmts'))->toBeTrue('no custom number formats at all');
        expect(has($styles, '$'))->toBeTrue('currency column produced no currency format');
        expect(has($styles, '%'))->toBeTrue('percent column produced no percent format');
    });

    it('carries the reviewer comment as a real comment part', function () {
        $comments = partOf(premiumWorkbook(), 'xl/comments1.xml');

        expect(has($comments, 'Reviewed by finance'))->toBeTrue();
    });

    it('writes totals as FORMULAS, so the document recalculates', function () {
        // A totals row of baked numbers looks identical and is dead on arrival:
        // edit a cell above it and the total lies. This is the difference
        // between a report and a picture of a report.
        $sheet = partOf(premiumWorkbook(), 'xl/worksheets/sheet1.xml');

        expect(has($sheet, '<f>'))->toBeTrue('totals row contains no formulas');
        expect(has($sheet, 'SUM('))->toBeTrue('no SUM in the totals row');
    });
});

describe('the guard against a style that is accepted and dropped', function () {
    it('proves the assertions can FAIL — an unstyled workbook has none of it', function () {
        // Without this, every assertion above could be passing on boilerplate
        // that appears in any workbook, and the suite would be green for a
        // document with no formatting whatsoever. This is the control.
        $plain = ['sheets' => [[
            'name' => 'Plain',
            'columns' => [['header' => 'A']],
            'rows' => [['just text']],
        ]]];

        $styles = partOf($plain, 'xl/styles.xml');
        $sheet = partOf($plain, 'xl/worksheets/sheet1.xml');

        expect(has($styles, 'FF1F3864'))->toBeFalse('a plain workbook somehow contains the premium fill');
        expect(has($styles, 'val="20"'))->toBeFalse('a plain workbook somehow contains the title font size');
        expect(has($sheet, '<mergeCell'))->toBeFalse('a plain workbook somehow contains a merge');
    });

    it('IGNORES a border given as a style name rather than a colour', function () {
        // Documented deliberately, because it cost time to find and the schema
        // does not say it: `borderTop` takes a HEX COLOUR. Passing "thick" —
        // which is what the OOXML border vocabulary uses, and what anyone would
        // reasonably try — produces no border and no error.
        //
        // Pinned as CURRENT BEHAVIOUR, not endorsed. If this ever starts
        // working, or starts rejecting the value, this test fails and someone
        // updates the note rather than discovering it the way I did.
        $schema = ['sheets' => [[
            'name' => 'Bad border',
            'columns' => [['header' => 'A']],
            'rows' => [['x']],
            'cells' => ['A1' => ['value' => 'x', 'format' => ['borderTop' => 'thick']]],
        ]]];

        $styles = partOf($schema, 'xl/styles.xml');

        expect(has($styles, 'thick'))->toBeFalse(
            'if this now passes, `borderTop` gained style-name support — say so in the schema description'
        );
    });
});

/**
 * Resolve the style a written cell actually ended up with.
 *
 * The `s="N"` on a cell is an index into `cellXfs`, whose entry points at a
 * font, a fill and a number format by index in turn. Following that chain is
 * the only way to assert what a cell LOOKS like — the style is nowhere near the
 * cell in the file, which is exactly why a dropped format is so easy to miss.
 *
 * @return array{numFmt: string, bold: bool}
 */
function styleOfCell(array $schema, string $address): array
{
    $sheet = new SimpleXMLElement(partOf($schema, 'xl/worksheets/sheet1.xml'));
    $styles = new SimpleXMLElement(partOf($schema, 'xl/styles.xml'));

    $found = null;
    foreach ($sheet->sheetData->row as $row) {
        foreach ($row->c as $c) {
            if ((string) $c['r'] === $address) {
                $found = $c;
            }
        }
    }

    expect($found)->not->toBeNull("no cell {$address} in the written sheet");

    $xf = $styles->cellXfs->xf[(int) $found['s']];
    $numFmtId = (int) $xf['numFmtId'];

    $code = '';
    foreach ($styles->numFmts->numFmt ?? [] as $fmt) {
        if ((int) $fmt['numFmtId'] === $numFmtId) {
            $code = (string) $fmt['formatCode'];
        }
    }

    $font = $styles->fonts->font[(int) $xf['fontId']];

    return ['numFmt' => $code, 'bold' => isset($font->b)];
}

describe('a sheet keeps BOTH its table and its explicit cells', function () {
    // ## The defect these pin
    //
    // `Normalizer::normalizeSheet()` returned the moment `cells` was set, which
    // threw away `columns`, `rows`, `totals` and `theme`. A four-row table with
    // one styled title collapsed to a one-cell workbook.
    //
    // Nothing reported it. `Agent::validate()` returned no errors, because
    // `Validator::validateSheet()` says in as many words that a sheet may carry
    // "columns + rows OR cells (sparse map) OR both". The validator permitted
    // the combination and the writer silently dropped half of it — so the file
    // opened cleanly, and was simply missing the report.
    //
    // That blocked the most ordinary premium layout there is: a styled band and
    // a formatted table on one sheet.

    it('does not discard the table when a styled cell is added beside it', function () {
        $sheet = partOf(premiumWorkbook(), 'xl/worksheets/sheet1.xml');

        // Every region, the header row, and the title band — one sheet.
        foreach (['Region', 'North', 'South', 'EMEA', 'Q3 REVENUE BY REGION'] as $needle) {
            expect(has($sheet, $needle))->toBeTrue("`{$needle}` was dropped from the composed sheet");
        }

        // Rows 1-5 are the table, 7 and 9 the styled cells. Counting them is
        // what catches the collapse: the old code wrote exactly the `cells`.
        expect(substr_count($sheet, '<row '))->toBeGreaterThanOrEqual(7);
    });

    it('keeps the totals FORMULAS when explicit cells are present', function () {
        // The formulas are generated by the same branch the early return
        // skipped, so they went with the table.
        $sheet = partOf(premiumWorkbook(), 'xl/worksheets/sheet1.xml');

        expect(has($sheet, 'SUM(B2:B4)'))->toBeTrue('the totals SUM did not survive');
        expect(has($sheet, 'AVERAGE(C2:C4)'))->toBeTrue('the totals AVERAGE did not survive');
    });

    it('lets an explicit cell INHERIT the column format it sits in', function () {
        // B4 asks only for `bold`. It must keep the currency format from its
        // column — otherwise emphasising one figure silently turns it back into
        // a raw number, and "2100000" in a column of dollars is a worse
        // document than the one before the change.
        $style = styleOfCell(premiumWorkbook(), 'B4');

        expect($style['bold'])->toBeTrue('the explicit format was not applied');
        // NOT `toContain('$', '...')` — Pest's toContain is VARIADIC, so a
        // second argument is another needle, not a failure message. Written
        // that way it asserts the format contains the message text, which is
        // a check that can only fail.
        expect(has($style['numFmt'], '$'))
            ->toBeTrue("the column currency format was lost under the overlay: got `{$style['numFmt']}`");
    });

    it('lets an explicit cell OVERRIDE the format beneath it', function () {
        // The other direction, or "inherit" would just mean "ignore".
        $schema = ['sheets' => [[
            'name' => 'Override',
            'columns' => [['header' => 'Amount', 'type' => 'currency', 'currency' => 'USD']],
            'rows' => [[42.0]],
            'cells' => ['A2' => ['value' => 42.0, 'format' => ['backgroundColor' => '#FFFF00']]],
        ]]];

        $styles = partOf($schema, 'xl/styles.xml');

        expect(has($styles, 'FFFFFF00'))->toBeTrue('the overlay fill never reached the style table');
    });
});

describe('a comment written the obvious way is not dropped', function () {
    it('accepts a plain string, not only a {text: ...} object', function () {
        // `comment` was read only as an array, so the string form was accepted
        // by the validator, normalized to null, and lost. No error, no comment.
        $schema = ['sheets' => [[
            'name' => 'Noted',
            'cells' => ['A1' => ['value' => 'x', 'comment' => 'a bare string comment']],
        ]]];

        expect(has(partOf($schema, 'xl/comments1.xml'), 'a bare string comment'))->toBeTrue();
    });

    it('still accepts the object form, with its author', function () {
        $schema = ['sheets' => [[
            'name' => 'Noted',
            'cells' => ['A1' => ['value' => 'x', 'comment' => ['text' => 'object form', 'author' => 'Finance']]],
        ]]];

        $comments = partOf($schema, 'xl/comments1.xml');

        expect(has($comments, 'object form'))->toBeTrue();
        expect(has($comments, 'Finance'))->toBeTrue('the author was dropped');
    });
});
