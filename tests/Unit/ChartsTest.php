<?php

declare(strict_types=1);

use HolySheet\Agent;

/**
 * Native charts.
 *
 * Every agent-built workbook reviewed for a board was marked down for having no
 * chart where the purpose was comparison, and holy-sheet had no way to make one.
 * A chart here is a real Excel chart over CELL RANGES, not a picture: edit a
 * figure and the chart follows, which is what "editable" means to a reviewer.
 * The cached values are written too, so a viewer that does not recalculate on
 * open still draws the numbers.
 */
function chWorkbook(array $charts, array $extra = []): array
{
    return ['sheets' => [[
        'name' => 'Q3 Revenue',
        'cells' => [
            'A4' => 'Region', 'B4' => 'Revenue', 'C4' => 'Growth',
            'A5' => 'North', 'B5' => 1250000.5, 'C5' => 0.184,
            'A6' => 'South', 'B6' => 980400.25, 'C6' => -0.042,
            'A7' => 'EMEA', 'B7' => 2100000, 'C7' => 0.311,
        ],
        'charts' => $charts,
        ...$extra,
    ]]];
}

/** @return array<string, string> */
function chParts(array $schema): array
{
    $path = tempnam(sys_get_temp_dir(), 'ch').'.xlsx';
    Agent::write($schema, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($path);

    return $parts;
}

const CH_REVENUE = [
    'type' => 'column',
    'title' => 'Q3 revenue by region',
    'categories' => 'A5:A7',
    'series' => [['name' => 'Revenue', 'values' => 'B5:B7']],
    'position' => ['from' => 'E2', 'to' => 'L18'],
];

it('writes a chart over live cell ranges, wired into the sheet', function () {
    $parts = chParts(chWorkbook([CH_REVENUE]));

    expect($parts['xl/worksheets/sheet1.xml'])->toContain('<drawing r:id="rId1"/>')
        ->and($parts['xl/worksheets/_rels/sheet1.xml.rels'])->toContain('Target="../drawings/drawing1.xml"')
        ->and($parts['xl/drawings/_rels/drawing1.xml.rels'])->toContain('Target="../charts/chart1.xml"')
        ->and($parts['[Content_Types].xml'])->toContain('PartName="/xl/drawings/drawing1.xml"')
        ->and($parts['[Content_Types].xml'])->toContain('PartName="/xl/charts/chart1.xml"');

    $chart = $parts['xl/charts/chart1.xml'];
    expect($chart)->toContain('<c:barChart><c:barDir val="col"/>')
        ->and($chart)->toContain("<c:f>'Q3 Revenue'!\$B\$5:\$B\$7</c:f>")
        ->and($chart)->toContain("<c:f>'Q3 Revenue'!\$A\$5:\$A\$7</c:f>")
        ->and($chart)->toContain('<c:pt idx="2"><c:v>2100000</c:v></c:pt>')
        ->and($chart)->toContain('<c:pt idx="0"><c:v>North</c:v></c:pt>')
        ->and($chart)->toContain('Q3 revenue by region')
        ->and($chart)->not->toContain('<c:legend>');
});

it('anchors the chart between the two cells it is given', function () {
    $drawing = chParts(chWorkbook([CH_REVENUE]))['xl/drawings/drawing1.xml'];

    expect($drawing)->toContain('<xdr:from><xdr:col>4</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>1</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>')
        ->and($drawing)->toContain('<xdr:to><xdr:col>11</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>17</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>');
});

it('draws data labels, a legend for several series, and a value format', function () {
    $chart = chParts(chWorkbook([[
        ...CH_REVENUE,
        'series' => [['name' => 'Revenue', 'values' => 'B5:B7', 'color' => '#F97316'], ['name' => 'Growth', 'values' => 'C5:C7']],
        'dataLabels' => true,
        'format' => '$#,##0',
    ]]))['xl/charts/chart1.xml'];

    expect($chart)->toContain('<c:showVal val="1"/>')
        ->and($chart)->toContain('<c:legend>')
        ->and($chart)->toContain('<a:srgbClr val="F97316"/>')
        ->and($chart)->toContain('formatCode="$#,##0"');
});

it('makes line and pie charts, and a horizontal bar', function () {
    $line = chParts(chWorkbook([[...CH_REVENUE, 'type' => 'line']]))['xl/charts/chart1.xml'];
    $pie = chParts(chWorkbook([[...CH_REVENUE, 'type' => 'pie']]))['xl/charts/chart1.xml'];
    $bar = chParts(chWorkbook([[...CH_REVENUE, 'type' => 'bar']]))['xl/charts/chart1.xml'];

    expect($line)->toContain('<c:lineChart>')
        ->and($pie)->toContain('<c:pieChart>')
        ->and($pie)->not->toContain('<c:valAx>')
        ->and($bar)->toContain('<c:barDir val="bar"/>');
});

it('numbers charts across sheets and keeps comment rels first', function () {
    $schema = chWorkbook([CH_REVENUE, [...CH_REVENUE, 'type' => 'line', 'position' => ['from' => 'E20', 'to' => 'L36']]]);
    $schema['sheets'][0]['cells']['A1'] = ['value' => 'Title', 'comment' => ['text' => 'note']];
    $schema['sheets'][] = ['name' => 'Second', 'cells' => ['A1' => 1, 'A2' => 2], 'charts' => [['type' => 'column', 'series' => [['values' => 'A1:A2']]]]];
    $parts = chParts($schema);

    expect($parts)->toHaveKeys(['xl/charts/chart1.xml', 'xl/charts/chart2.xml', 'xl/charts/chart3.xml', 'xl/drawings/drawing1.xml', 'xl/drawings/drawing2.xml'])
        ->and($parts['xl/worksheets/_rels/sheet1.xml.rels'])->toContain('<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>')
        ->and($parts['xl/worksheets/sheet1.xml'])->toContain('<drawing r:id="rId3"/><legacyDrawing r:id="rId1"/>')
        ->and($parts['xl/drawings/_rels/drawing2.xml.rels'])->toContain('Target="../charts/chart3.xml"');
});

it('adds nothing when there are no charts', function () {
    $parts = chParts(chWorkbook([]));

    expect(array_filter(array_keys($parts), static fn ($p) => str_contains($p, 'chart') || str_contains($p, 'drawing')))->toBe([])
        ->and($parts['xl/worksheets/sheet1.xml'])->not->toContain('<drawing');
});

it('reads a chart back as it was written', function () {
    $chart = [
        'type' => 'column',
        'title' => 'Q3 revenue by region',
        'categories' => 'A5:A7',
        'series' => [['name' => 'Revenue', 'values' => 'B5:B7', 'color' => '#F97316'], ['name' => 'Growth', 'values' => 'C5:C7']],
        'position' => ['from' => 'E2', 'to' => 'L18'],
        'dataLabels' => true,
        'legend' => true,
        'format' => '$#,##0',
    ];
    $path = tempnam(sys_get_temp_dir(), 'ch').'.xlsx';
    Agent::write(chWorkbook([$chart]), $path);
    $read = Agent::describe($path);
    @unlink($path);

    expect($read['sheets'][0]['charts'])->toBe([$chart]);
});

it('refuses a malformed chart with a hint', function () {
    $paths = array_column(Agent::validate(chWorkbook([
        ['type' => 'donut', 'series' => [['values' => 'B5:B7']]],
        ['type' => 'column', 'series' => []],
        ['type' => 'column', 'series' => [['values' => 'B5-B7']], 'categories' => 'nope', 'position' => ['from' => 'L18', 'to' => 'E2']],
    ])), 'path');

    expect($paths)->toContain('sheets[0].charts[0].type')
        ->and($paths)->toContain('sheets[0].charts[1].series')
        ->and($paths)->toContain('sheets[0].charts[2].series[0].values')
        ->and($paths)->toContain('sheets[0].charts[2].categories')
        ->and($paths)->toContain('sheets[0].charts[2].position');
});

it('publishes charts in the schema', function () {
    $sheet = Agent::toolDefinition()['definitions']['Sheet']['properties'];

    expect($sheet)->toHaveKey('charts')
        ->and(json_encode($sheet['charts']))->toContain('categories');
});

it('colours single points, to make the finding stand out', function () {
    // One colour for every bar is a chart that does not say which bar matters;
    // reviewers marked charts down for it on every comparison brief.
    $chart = [
        'type' => 'bar',
        'categories' => 'A5:A7',
        'series' => [['name' => 'Revenue', 'values' => 'B5:B7', 'color' => '#94A3B8', 'points' => [['index' => 2, 'color' => '#C2410C']]]],
        'position' => ['from' => 'E2', 'to' => 'L18'],
    ];
    $xml = chParts(chWorkbook([$chart]))['xl/charts/chart1.xml'];

    expect($xml)->toContain('<c:dPt><c:idx val="2"/><c:invertIfNegative val="0"/><c:bubble3D val="0"/><c:spPr><a:solidFill><a:srgbClr val="C2410C"/></a:solidFill></c:spPr></c:dPt>')
        // dPt sits after the series' own fill and before its categories.
        ->and(strpos($xml, '<c:dPt>'))->toBeGreaterThan(strpos($xml, 'val="94A3B8"'))
        ->and(strpos($xml, '<c:dPt>'))->toBeLessThan(strpos($xml, '<c:cat>'));

    $pie = chParts(chWorkbook([[...$chart, 'type' => 'pie']]))['xl/charts/chart1.xml'];
    expect($pie)->toContain('<c:dPt><c:idx val="2"/><c:bubble3D val="0"/><c:spPr><a:solidFill><a:srgbClr val="C2410C"/></a:solidFill></c:spPr></c:dPt>');

    $path = tempnam(sys_get_temp_dir(), 'ch').'.xlsx';
    Agent::write(chWorkbook([$chart]), $path);
    $read = Agent::describe($path)['sheets'][0]['charts'][0];
    @unlink($path);
    expect($read['series'][0]['points'])->toBe([['index' => 2, 'color' => '#C2410C']]);

    $paths = array_column(Agent::validate(chWorkbook([[...$chart, 'series' => [['values' => 'B5:B7', 'points' => [['index' => 3, 'color' => '#C2410C'], ['index' => 0, 'color' => 'red']]]]]])), 'path');
    expect($paths)->toContain('sheets[0].charts[0].series[0].points[0].index')
        ->and($paths)->toContain('sheets[0].charts[0].series[0].points[1].color');
});
