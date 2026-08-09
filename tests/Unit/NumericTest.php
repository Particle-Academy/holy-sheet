<?php

use HolySheet\Agent;

/**
 * The numeric hazards `.ai/plans/polyglot/parity/conformance-suite.md` §5 names,
 * pinned as cases on the PHP side. The Node port asserts the same table in
 * tests/numeric.test.ts.
 *
 * Every one of these was a live PHP<->JS disagreement in shipped packages, and
 * none was covered: the parity suites diff whole OOXML parts, so they only
 * catch a divergence if some fixture happens to contain the offending value.
 * None did. Numbers are the thing a spreadsheet writer exists to get right, so
 * they get their own table instead of waiting to be caught incidentally.
 */
function cellXmlFor(mixed $value): string
{
    $bytes = Agent::toBytes(['sheets' => [['name' => 'S', 'rows' => [[$value]]]]]);

    $tmp = tempnam(sys_get_temp_dir(), 'hs');
    file_put_contents($tmp, $bytes);

    $zip = new ZipArchive();
    $zip->open($tmp);
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($tmp);

    preg_match('/<c r="A1".*?(?:\/>|<\/c>)/', $xml, $m);

    return $m[0] ?? '(no A1)';
}

it('does not clamp a numeric string past PHP_INT_MAX', function () {
    // (int) "1e21" is 9223372036854775807 -- PHP_INT_MAX, not the number anyone
    // wrote. The dot-test coercion took the (int) branch because "1e21" has no
    // ".", so any magnitude beyond the int range silently became the clamp.
    // The Node port read the same string as 1 (parseInt stops at the "e"), so
    // the two engines wrote two different wrong numbers.
    $xml = cellXmlFor('1e21');

    expect($xml)->toContain('<v>1000000000000000000000</v>');
    expect($xml)->not->toContain('9223372036854775807');
});

it('does not clamp a long integer string past PHP_INT_MAX', function () {
    $xml = cellXmlFor('99999999999999999999');

    expect(str_contains($xml, '9223372036854775807'))->toBeFalse(
        'the value was clamped to PHP_INT_MAX instead of kept as a float'
    );
});

it('reads exponent notation the same as the Node port', function () {
    // These already worked on this side -- PHP has parsed exponents in numeric
    // strings since PHP 7. They are here as the regression half, because the
    // fix changes which branch runs for them.
    expect(cellXmlFor('1e5'))->toContain('<v>100000</v>');
    expect(cellXmlFor('1.5e3'))->toContain('<v>1500</v>');
    expect(cellXmlFor('2e-3'))->toContain('<v>0.002</v>');
});

it('keeps the plain cases exactly where they were', function () {
    expect(cellXmlFor('007'))->toContain('<v>7</v>');
    expect(cellXmlFor('.5'))->toContain('<v>0.5</v>');
    expect(cellXmlFor('-3.25'))->toContain('<v>-3.25</v>');
    expect(cellXmlFor(42))->toContain('<v>42</v>');
    expect(cellXmlFor(1.5))->toContain('<v>1.5</v>');
});

it('writes zero, not negative zero', function () {
    // The polyglot plan's section 5 lists this as a live divergence -- "PHP
    // <v>-0</v>, JS <v>0</v>". It is NOT one. number_format(-0.0, 14) returns
    // "0.00000000000000" with no sign, so this side already emitted 0 and
    // matched the Node port. This test passed before the numeric fix and is
    // kept as a regression guard, not as evidence of a bug.
    expect(cellXmlFor(-0.0))->toContain('<v>0</v>');
    expect(cellXmlFor(-0.0))->not->toContain('<v>-0</v>');
});

it('never writes a non-finite value into a cell', function () {
    // NAN/INF have no <v> representation; "NAN" in a cell is a corrupt sheet.
    foreach ([NAN, INF, -INF] as $v) {
        $xml = cellXmlFor($v);

        expect(preg_match('/nan|inf/i', $xml))->toBe(0, 'a non-finite value reached the sheet');
    }
});
