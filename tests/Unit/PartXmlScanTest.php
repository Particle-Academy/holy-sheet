<?php

declare(strict_types=1);

use HolySheet\Archive\PartXml;
use HolySheet\Exceptions\UnsupportedFormatException;

/**
 * PartXml::scanTags, the libxml-free count of a part libxml calls malformed.
 *
 * Why it exists: what XMLReader delivers from a malformed part depends on the
 * libxml version. Measured on a 220-byte worksheet cut off after three cells:
 * libxml 2.11.9 delivered all three `<c>` elements, libxml 2.9.14 (Ubuntu
 * 24.04's, so CI's) delivered none, because it pushes the last chunk with
 * "terminate" and returns false before handing over what that chunk parsed.
 * A count that depended on that made a limit refuse a truncated part on one
 * machine and wave it through on another. These cases run on any libxml.
 */
function scanned(string $xml, int $maxDepth = 3): array
{
    $seen = [];
    PartXml::scanTags($xml, $maxDepth, function (int $depth, string $prefix, string $local, array $attrs) use (&$seen): void {
        $seen[] = [$depth, $prefix, $local, $attrs['r'] ?? null];
    });

    return $seen;
}

it('reports every element of a truncated part, whatever libxml would deliver', function () {
    $xml = '<?xml version="1.0"?><worksheet xmlns="m"><sheetData><row r="1"><c r="A1"><v>1</v></c><c r="B1"><v>2</v></c><c r="C1"><v>3</v></c>';

    expect(scanned($xml))->toBe([
        [0, '', 'worksheet', null], [1, '', 'sheetData', null], [2, '', 'row', '1'],
        [3, '', 'c', 'A1'], [3, '', 'c', 'B1'], [3, '', 'c', 'C1'],
    ]);
});

it('skips comments, CDATA and processing instructions, and reads quoted > in attributes', function () {
    $xml = '<w><!-- <c r="X1"/> --><s><![CDATA[<c r="Y1"/>]]><?pi <c r="Z1"/> ?><row a="x>y" r="2"/></s></w>';

    expect(scanned($xml))->toBe([[0, '', 'w', null], [1, '', 's', null], [2, '', 'row', '2']]);
});

it('splits the prefix from the local name and decodes attribute values', function () {
    expect(scanned('<x:w xmlns:x="m"><x:s r="&#65;1"/></x:w>'))->toBe([[0, 'x', 'w', null], [1, 'x', 's', 'A1']]);
});

it('does not descend past maxDepth, and stops after the first root element', function () {
    expect(scanned('<w><s><row><c r="A1"/></row></s></w><w2/>', 1))->toBe([[0, '', 'w', null], [1, '', 's', null]]);
});

it('reads UTF-16 parts', function () {
    $xml = "\xFF\xFE".mb_convert_encoding('<w><s r="5"/></w>', 'UTF-16LE', 'UTF-8');

    expect(scanned($xml))->toBe([[0, '', 'w', null], [1, '', 's', '5']]);
});

it('refuses a DOCTYPE before the root', function () {
    expect(fn () => scanned('<!DOCTYPE w [<!ENTITY a "b">]><w/>'))->toThrow(UnsupportedFormatException::class);
});
