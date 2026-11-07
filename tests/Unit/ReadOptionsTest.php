<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Exceptions\ArchiveRefused;
use HolySheet\Exceptions\ReadLimitExceeded;
use HolySheet\Exceptions\SheetNotFoundException;
use HolySheet\Reader\ReadLimits;

/**
 * CC-fancy-0012 (sheet selection, the rows view) and CC-fancy-0013 (read
 * limits), driven by tests/fixtures/read-options/cases.json, which the JS and
 * Python ports read too. A case added there is a case all three must pass.
 */
function readOptionCases(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../fixtures/read-options/cases.json'), true, 512, JSON_THROW_ON_ERROR);
}

function readOptionFixture(string $file): string
{
    return (string) file_get_contents(__DIR__.'/../fixtures/read-options/'.$file);
}

it('has cases to run', function () {
    $cases = readOptionCases();
    expect(count($cases['read']))->toBeGreaterThan(20)
        ->and(count($cases['rows']))->toBeGreaterThan(4);
});

it('reads each shared case as written', function (array $case) {
    $bytes = readOptionFixture($case['file']);
    try {
        $schema = Agent::read($bytes, $case['options']);
    } catch (ArchiveRefused $e) {
        // ReadLimitExceeded is one too. A refusal that names a limit carries
        // which one, its value and the count; every refusal carries a message.
        expect($case)->toHaveKey('refused');
        $got = ['reason' => $e->reason];
        if ($e->limit !== null) {
            $got += ['limit' => $e->limit, 'max' => $e->max, 'actual' => $e->actual];
        }
        expect($got + ['message' => $e->getMessage()])->toBe($case['refused']);

        return;
    } catch (SheetNotFoundException $e) {
        expect($case)->toHaveKey('sheetNotFound');
        expect(['sheet' => $e->sheet, 'available' => $e->available, 'message' => $e->getMessage()])->toBe($case['sheetNotFound']);

        return;
    }
    expect($case)->toHaveKey('sheets');
    expect(array_column($schema['sheets'], 'name'))->toBe($case['sheets']);
})->with(fn () => array_map(fn ($c) => [$c], array_column(readOptionCases()['read'], null, 'name')));

it('turns each shared case into rows', function (array $case) {
    try {
        $rows = Agent::rows($case['sheet'], $case['options']);
    } catch (ReadLimitExceeded $e) {
        expect($case)->toHaveKey('refused');
        expect([
            'reason' => $e->reason, 'limit' => $e->limit, 'max' => $e->max, 'actual' => $e->actual, 'message' => $e->getMessage(),
        ])->toBe($case['refused']);

        return;
    }
    expect($case)->toHaveKey('rows');
    expect($rows)->toBe($case['rows']);
})->with(fn () => array_map(fn ($c) => [$c], array_column(readOptionCases()['rows'], null, 'name')));

it('pins the default limits the ports share', function () {
    expect(ReadLimits::DEFAULTS)->toBe(readOptionCases()['defaultLimits'])
        ->and(ReadLimits::ARCHIVE_DEFAULTS)->toBe(readOptionCases()['defaultArchiveLimits']);
});

it('is still an ArchiveRefused, so an existing catch keeps catching', function () {
    expect(fn () => Agent::read(readOptionFixture('two-sheets.xlsx'), ['limits' => ['maxRows' => 1]]))
        ->toThrow(ArchiveRefused::class);
});

it('reads only the selected sheet, with its cells', function () {
    $schema = Agent::read(readOptionFixture('two-sheets.xlsx'), ['sheet' => 'Summary']);
    expect($schema['sheets'])->toHaveCount(1)
        ->and($schema['sheets'][0]['cells'])->toBe(['A1' => ['value' => 1], 'B1' => ['value' => 2]]);
});

it('takes the same options from a path', function () {
    $path = __DIR__.'/../fixtures/read-options/two-sheets.xlsx';
    expect(array_column(Agent::read($path, ['sheet' => 'Data'])['sheets'], 'name'))->toBe(['Data'])
        ->and(array_column(Agent::describe($path, ['sheet' => 'Data'])['sheets'], 'name'))->toBe(['Data']);
    expect(fn () => Agent::describe($path, ['limits' => ['maxRows' => 1]]))->toThrow(ReadLimitExceeded::class);
});

it('refuses an unknown read option and a sheet that is not a name', function () {
    $bytes = readOptionFixture('two-sheets.xlsx');
    expect(fn () => Agent::read($bytes, ['sheets' => 'Data']))->toThrow(InvalidArgumentException::class, 'unknown read option: sheets');
    expect(fn () => Agent::read($bytes, ['sheet' => 1]))->toThrow(InvalidArgumentException::class, 'sheet must be a sheet name');
    expect(fn () => Agent::rows(['cells' => []], ['formulas' => 'cached']))->toThrow(InvalidArgumentException::class, 'formulas must be');
    expect(fn () => Agent::rows(['cells' => []], ['bogus' => 1]))->toThrow(InvalidArgumentException::class, 'unknown rows option: bogus');
});

it('reads a writer round trip into rows', function () {
    $bytes = Agent::toBytes(['sheets' => [['name' => 'T', 'columns' => [['header' => 'Name'], ['header' => 'Qty', 'type' => 'integer']], 'rows' => [['a', 1], ['b', 2]]]]]);
    $sheet = Agent::read($bytes, ['sheet' => 'T'])['sheets'][0];
    expect(Agent::rows($sheet))->toBe([['Name', 'Qty'], ['a', 1], ['b', 2]]);
});

// --- CC-fancy-0012 border styles -------------------------------------------

function roZip(array $parts): string
{
    $path = tempnam(sys_get_temp_dir(), 'hs_ro_');
    unlink($path);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($parts as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

function roUnzip(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'hs_ro_');
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

function borderStylesXml(array $format): string
{
    $parts = roUnzip(Agent::toBytes(['sheets' => [['name' => 'B', 'cells' => ['A1' => ['value' => 1, 'format' => $format]]]]]));

    return $parts['xl/styles.xml'];
}

it('writes a bare colour as a thin border, exactly as before', function () {
    expect(borderStylesXml(['borderTop' => '#FF0000']))
        ->toContain('<border><left/><right/><top style="thin"><color rgb="FFFF0000"/></top><bottom/><diagonal/></border>');
});

it('writes each border style per side', function (string $style) {
    expect(borderStylesXml(['borderBottom' => ['style' => $style, 'color' => '#00AA00'], 'borderLeft' => '#000000']))
        ->toContain('<border><left style="thin"><color rgb="FF000000"/></left><right/><top/><bottom style="'.$style.'"><color rgb="FF00AA00"/></bottom><diagonal/></border>');
})->with(['thin', 'medium', 'thick', 'dashed', 'dotted', 'double']);

it('defaults an object border to thin and black', function () {
    expect(borderStylesXml(['borderRight' => ['style' => 'dashed']]))->toContain('<right style="dashed"><color rgb="FF000000"/></right>');
    expect(borderStylesXml(['borderRight' => []]))->toContain('<right style="thin"><color rgb="FF000000"/></right>');
});

it('dedupes a thin object border with the bare colour it means', function () {
    $parts = roUnzip(Agent::toBytes(['sheets' => [['name' => 'B', 'cells' => [
        'A1' => ['value' => 1, 'format' => ['borderTop' => '#FF0000']],
        'A2' => ['value' => 2, 'format' => ['borderTop' => ['style' => 'thin', 'color' => '#FF0000']]],
    ]]]]));
    expect($parts['xl/styles.xml'])->toContain('<borders count="2">');
});

it('reads a border style back, and a thin one as the bare colour', function () {
    $schema = Agent::read(Agent::toBytes(['sheets' => [['name' => 'B', 'cells' => [
        'A1' => ['value' => 1, 'format' => ['borderTop' => '#FF0000', 'borderBottom' => ['style' => 'double', 'color' => '#0000FF']]],
        'A2' => ['value' => 2, 'format' => ['borderLeft' => ['style' => 'thin', 'color' => '#00FF00']]],
    ]]]]));
    expect($schema['sheets'][0]['cells']['A1']['format'])->toBe(['displayFormat' => 'auto', 'borderTop' => '#FF0000', 'borderBottom' => ['style' => 'double', 'color' => '#0000FF']])
        ->and($schema['sheets'][0]['cells']['A2']['format'])->toBe(['displayFormat' => 'auto', 'borderLeft' => '#00FF00']);
});

it('reads a style outside the six as the thin border it always read as', function () {
    $parts = roUnzip(Agent::toBytes(['sheets' => [['name' => 'B', 'cells' => ['A1' => ['value' => 1, 'format' => ['borderTop' => ['style' => 'dashed', 'color' => '#FF0000']]]]]]]));
    $parts['xl/styles.xml'] = str_replace('style="dashed"', 'style="mediumDashDot"', $parts['xl/styles.xml']);
    $schema = Agent::read(roZip($parts));
    expect($schema['sheets'][0]['cells']['A1']['format'])->toBe(['displayFormat' => 'auto', 'borderTop' => '#FF0000']);
});

it('round trips styled borders as the same workbook', function () {
    $schema = ['sheets' => [['name' => 'B', 'cells' => ['A1' => ['value' => 1, 'format' => ['borderTop' => ['style' => 'medium', 'color' => '#123456']]]]]]];
    expect(Agent::diff($schema, Agent::read(Agent::toBytes($schema))))->toBe([]);
});

it('validates a border as a colour or {style, color}', function () {
    $errors = fn (mixed $border) => Agent::validate(['sheets' => [['name' => 'B', 'cells' => ['A1' => ['value' => 1, 'format' => ['borderTop' => $border]]]]]]);
    expect($errors('#000000'))->toBe([])
        ->and($errors(['style' => 'thick', 'color' => '#00ff00']))->toBe([])
        ->and($errors(['style' => 'dotted']))->toBe([]);

    $bad = $errors(['style' => 'zigzag']);
    expect($bad)->toHaveCount(1)
        ->and($bad[0]['path'])->toBe('sheets[0].cells.A1.format.borderTop.style')
        ->and($bad[0]['hint'])->toContain('thin, medium, thick, dashed, dotted, double');
    expect($errors(['style' => 'thin', 'color' => 'red'])[0]['path'])->toBe('sheets[0].cells.A1.format.borderTop.color');
    expect($errors(['style' => 'thin', 'width' => 2])[0]['path'])->toBe('sheets[0].cells.A1.format.borderTop.width');
    expect($errors(5)[0]['path'])->toBe('sheets[0].cells.A1.format.borderTop');
    expect($errors(['thin'])[0]['path'])->toBe('sheets[0].cells.A1.format.borderTop');
});

it('validates borders in the row form too', function () {
    $errors = Agent::validate(['sheets' => [['name' => 'B', 'rows' => [[['value' => 1, 'format' => ['borderLeft' => ['style' => 'wavy']]]]]]]]);
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['path'])->toBe('sheets[0].rows[0][0].format.borderLeft.style');
});
