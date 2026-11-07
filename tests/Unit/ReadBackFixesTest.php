<?php

declare(strict_types=1);

use HolySheet\Agent;

/**
 * Four read/write defects a consumer (LiftPoint) hit, each pinned here in the
 * shape the two ports mirror case for case:
 *
 *  - the 1904 date system (`<workbookPr date1904="1"/>`) was ignored, so every
 *    date in such a workbook read 1462 days early;
 *  - a rich inline string (`<is><r><t>..</t></r></is>`) read as null;
 *  - a date-formatted formula's cached value came back as a raw serial;
 *  - a column's custom `format` code was ignored by the normalizer.
 *
 * Fixtures are built here, from the writer's own output with the parts a
 * producer like Excel writes patched in, so nothing depends on a binary file.
 */
function rbZip(array $parts): string
{
    $path = tempnam(sys_get_temp_dir(), 'hs_rb_');
    unlink($path);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($parts as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

function rbUnzip(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'hs_rb_');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    $zip->open($path);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($path);

    return $parts;
}

/**
 * A1 a date, A2 a datetime, A3 a date formula, A4 a plain number formula, A5 an
 * inline string, all written by the real writer.
 */
function rbParts(): array
{
    return rbUnzip(Agent::toBytes(['sheets' => [[
        'name' => 'S',
        'cells' => [
            'A1' => ['value' => '2026-11-07', 'format' => ['displayFormat' => 'date']],
            'A2' => ['value' => '2026-11-07T12:00:00Z', 'format' => ['displayFormat' => 'datetime']],
            'A3' => ['formula' => 'A1+1', 'computedValue' => 46334, 'format' => ['displayFormat' => 'date']],
            'A4' => ['formula' => '1+1', 'computedValue' => 2],
            'A5' => 'plain',
        ],
    ]]]));
}

/** The same workbook in the 1904 date system: the flag, and every serial 1462 days smaller. */
function rb1904(string $flag = '1'): array
{
    $parts = rbParts();
    $parts['xl/workbook.xml'] = str_replace('<sheets>', '<workbookPr date1904="'.$flag.'"/><sheets>', $parts['xl/workbook.xml']);
    $parts['xl/worksheets/sheet1.xml'] = strtr($parts['xl/worksheets/sheet1.xml'], [
        '<v>46333</v>' => '<v>44871</v>',
        '<v>46333.5</v>' => '<v>44871.5</v>',
        '<v>46334</v>' => '<v>44872</v>',
    ]);

    return $parts;
}

function rbCells(array $parts): array
{
    return Agent::read(rbZip($parts))['sheets'][0]['cells'];
}

describe('the 1904 date system', function () {
    it('fixes the fixture: the 1900-system serials are the ones the 1904 copy rewrites', function () {
        $sheet = rbParts()['xl/worksheets/sheet1.xml'];

        expect($sheet)->toContain('<v>46333</v>')->toContain('<v>46333.5</v>')->toContain('<v>46334</v>');
    });

    it('reads a date from a workbook that declares date1904', function (string $flag) {
        $cells = rbCells(rb1904($flag));

        expect($cells['A1']['value'])->toBe('2026-11-07')
            ->and($cells['A2']['value'])->toBe('2026-11-07T12:00:00Z');
    })->with(['1', 'true']);

    it('reads the 1900 system when date1904 is off or absent', function (string $flag) {
        $parts = rbParts();
        if ($flag !== 'absent') {
            $parts['xl/workbook.xml'] = str_replace('<sheets>', '<workbookPr date1904="'.$flag.'"/><sheets>', $parts['xl/workbook.xml']);
        }
        $cells = rbCells($parts);

        expect($cells['A1']['value'])->toBe('2026-11-07')
            ->and($cells['A2']['value'])->toBe('2026-11-07T12:00:00Z');
    })->with(['0', 'false', 'absent']);

    it('converts the cached value of a date formula with the same epoch', function () {
        expect(rbCells(rb1904())['A3']['computedValue'])->toBe('2026-11-08');
    });

    it('writes a read 1904 workbook back as the same dates in the 1900 system', function () {
        $again = rbUnzip(Agent::toBytes(Agent::read(rbZip(rb1904()))));

        expect($again['xl/workbook.xml'])->not->toContain('date1904')
            ->and($again['xl/worksheets/sheet1.xml'])->toContain('<c r="A1" s="1"><v>46333</v></c>');
    });
});

describe('the cached value of a date-formatted formula', function () {
    it('reads as the ISO date the cell displays, not as a serial', function () {
        $cells = rbCells(rbParts());

        expect($cells['A3']['formula'])->toBe('A1+1')
            ->and($cells['A3']['computedValue'])->toBe('2026-11-08');
    });

    it('reads as an ISO datetime for a datetime format', function () {
        $parts = rbUnzip(Agent::toBytes(['sheets' => [['name' => 'S', 'cells' => [
            'A1' => ['formula' => 'NOW()', 'computedValue' => 46333.25, 'format' => ['displayFormat' => 'datetime']],
        ]]]]));

        expect(rbCells($parts)['A1']['computedValue'])->toBe('2026-11-07T06:00:00Z');
    });

    it('stays a number when the formula is not date-formatted', function () {
        expect(rbCells(rbParts())['A4']['computedValue'])->toBe(2);
    });

    it('is written back as the serial, so describe() output round-trips', function () {
        $parts = rbUnzip(Agent::toBytes(['sheets' => [['name' => 'S', 'cells' => [
            'A1' => ['formula' => 'A2+1', 'computedValue' => '2026-11-08', 'format' => ['displayFormat' => 'date']],
        ]]]]));

        expect($parts['xl/worksheets/sheet1.xml'])->toContain('<c r="A1" s="1"><f>A2+1</f><v>46334</v></c>');
    });

    it('survives a read and a write unchanged', function () {
        $bytes = rbZip(rbParts());
        $again = rbUnzip(Agent::toBytes(Agent::read($bytes)));

        expect($again['xl/worksheets/sheet1.xml'])->toContain('<f>A1+1</f><v>46334</v>')
            ->and(Agent::read(rbZip($again))['sheets'][0]['cells']['A3']['computedValue'])->toBe('2026-11-08');
    });
});

describe('a rich inline string', function () {
    it('reads as the text of its runs, as a rich shared string does', function () {
        $parts = rbParts();
        $rich = '<c r="A5" t="inlineStr"><is><r><t xml:space="preserve">Hello </t></r><r><rPr><b/></rPr><t>world</t></r></is></c>';
        $parts['xl/worksheets/sheet1.xml'] = preg_replace('#<c r="A5"[^>]*><is>.*?</is></c>#', $rich, $parts['xl/worksheets/sheet1.xml'], 1, $count);
        expect($count)->toBe(1);

        expect(rbCells($parts)['A5']['value'])->toBe('Hello world');
    });

    it('still reads a plain inline string', function () {
        expect(rbCells(rbParts())['A5']['value'])->toBe('plain');
    });
});

describe('a column format code', function () {
    /** [the numFmt id written for $code, the numFmt id of the xf cell $ref uses]. */
    function rbNumFmtOf(array $parts, string $code, string $ref): array
    {
        $escaped = htmlspecialchars($code, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        preg_match('#<numFmt numFmtId="(\d+)" formatCode="'.preg_quote($escaped, '#').'"/>#', $parts['xl/styles.xml'], $fmt);
        preg_match('#<c r="'.$ref.'" s="(\d+)"#', $parts['xl/worksheets/sheet1.xml'], $cell);
        preg_match('#<cellXfs[^>]*>(.*)</cellXfs>#', $parts['xl/styles.xml'], $xfs);
        preg_match_all('#<xf [^>]*numFmtId="(\d+)"#', $xfs[1] ?? '', $ids);

        return [$fmt[1] ?? null, isset($cell[1]) ? ($ids[1][(int) $cell[1]] ?? null) : null];
    }

    it('is written as a custom numFmt and applied to the column', function () {
        $code = '#,##0.000" kg"';
        $parts = rbUnzip(Agent::toBytes(['sheets' => [[
            'name' => 'S',
            'columns' => [['header' => 'Weight', 'type' => 'number', 'format' => $code]],
            'rows' => [[1.5], [2.25]],
        ]]]));
        [$written, $a2] = rbNumFmtOf($parts, $code, 'A2');
        [, $a3] = rbNumFmtOf($parts, $code, 'A3');

        expect($written)->not->toBeNull()
            ->and($a2)->toBe($written)
            ->and($a3)->toBe($written);
    });

    it('overrides the type-derived code but keeps the type deciding the value', function () {
        $parts = rbUnzip(Agent::toBytes(['sheets' => [[
            'name' => 'S',
            'columns' => [['header' => 'When', 'type' => 'date', 'format' => 'dd/mm/yyyy']],
            'rows' => [['2026-11-07']],
        ]]]));
        [$written, $a2] = rbNumFmtOf($parts, 'dd/mm/yyyy', 'A2');

        expect($a2)->not->toBeNull()->toBe($written)
            ->and($parts['xl/styles.xml'])->not->toContain('yyyy-mm-dd')
            ->and($parts['xl/worksheets/sheet1.xml'])->toContain('<v>46333</v>');
    });

    it('applies to an auto column too', function () {
        $parts = rbUnzip(Agent::toBytes(['sheets' => [[
            'name' => 'S',
            'columns' => [['header' => 'Ratio', 'format' => '0.0000']],
            'rows' => [[0.5]],
        ]]]));
        [$written, $a2] = rbNumFmtOf($parts, '0.0000', 'A2');

        expect($written)->not->toBeNull()->and($a2)->toBe($written);
    });

    it('carries onto the totals row', function () {
        $parts = rbUnzip(Agent::toBytes(['sheets' => [[
            'name' => 'S',
            'columns' => [['header' => 'Weight', 'type' => 'number', 'format' => '0.000']],
            'rows' => [[1.5], [2.5]],
            'totals' => ['Weight' => 'sum'],
        ]]]));
        [$written, $total] = rbNumFmtOf($parts, '0.000', 'A4');

        expect($total)->not->toBeNull()->toBe($written);
    });

    it('gives way to a displayFormat a cell sets itself', function () {
        $parts = rbUnzip(Agent::toBytes(['sheets' => [[
            'name' => 'S',
            'columns' => [['header' => 'Weight', 'type' => 'number', 'format' => '0.000']],
            'rows' => [[['value' => 0.5, 'format' => ['displayFormat' => 'percentage']]]],
        ]]]));

        expect($parts['xl/styles.xml'])->not->toContain('formatCode="0.000"')->toContain('formatCode="0.0%"');
    });

    it('is refused by the validator when it cannot be written', function (mixed $format) {
        $errors = Agent::validate(['sheets' => [[
            'name' => 'S',
            'columns' => [['header' => 'X', 'format' => $format]],
            'rows' => [[1]],
        ]]]);

        expect(array_column($errors, 'path'))->toContain('sheets[0].columns[0].format');
    })->with(['empty' => [''], 'a number' => [12], 'too long' => [str_repeat('0', 256)]]);

    it('is accepted by the validator when it is a code', function () {
        expect(Agent::validate(['sheets' => [[
            'name' => 'S',
            'columns' => [['header' => 'X', 'format' => '0.00']],
            'rows' => [[1]],
        ]]]))->toBe([]);
    });
});
