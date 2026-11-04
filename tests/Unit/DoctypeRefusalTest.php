<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Reader\XlsxReader;

/**
 * Every XML part of an uploaded file is read through one gate, which refuses a
 * DOCTYPE. All of it goes through the public reader, because what matters is
 * what a hostile upload gets back, not what a helper returns.
 *
 * MARKER is the visible text of each part in a clean file, and ENTITY the value
 * its replacement declares, so "ENTITY in the result" means "it expanded".
 */
const DT_MARKER = 'HSBASE';
const DT_ENTITY = 'HSINT';
const DT_DOCTYPE = '<!DOCTYPE r [<!ENTITY x "'.DT_ENTITY.'">]>';

function dtZip(array $parts): string
{
    $path = tempnam(sys_get_temp_dir(), 'hs_dt_');
    unlink($path);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    foreach ($parts as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();

    return $path;
}

function dtUnzip(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'hs_dt_');
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

/** A real package from the real writer, plus the shared strings part it does not author. */
function dtXlsx(): array
{
    $parts = dtUnzip(Agent::toBytes([
        'meta' => ['creator' => DT_MARKER, 'created' => '2026-01-01T00:00:00Z'],
        'sheets' => [[
            'name' => DT_MARKER,
            'cells' => [
                'A1' => ['value' => DT_MARKER, 'comment' => ['text' => DT_MARKER, 'author' => 'Auth'], 'format' => ['color' => '#ABCDEF']],
                'A3' => 2,
            ],
        ]],
    ]));

    $parts['xl/sharedStrings.xml'] = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1"><si><t>'.DT_MARKER.'</t></si></sst>';
    $parts['xl/worksheets/sheet1.xml'] = preg_replace('#<c r="A3"([^>]*)><v>2</v></c>#', '<c r="A3"$1 t="s"><v>0</v></c>', $parts['xl/worksheets/sheet1.xml']);

    return $parts;
}

function dtWithDoctype(string $xml, string $doctype = DT_DOCTYPE): string
{
    if (preg_match('/^<\?xml[^>]*\?>\s*/', $xml, $m) === 1) {
        return $m[0].$doctype.substr($xml, strlen($m[0]));
    }

    return $doctype.$xml;
}

function dtDeclare(string $xml, string $encoding): string
{
    return preg_replace('/^<\?xml[^>]*\?>/', '<?xml version="1.0" encoding="'.$encoding.'"?>', $xml);
}

/** @return array<string, callable(string): string> */
function dtEncodings(): array
{
    return [
        'plain UTF-8' => static fn (string $x): string => $x,
        'UTF-8 with a BOM' => static fn (string $x): string => "\xEF\xBB\xBF".$x,
        'UTF-16LE with a BOM' => static fn (string $x): string => "\xFF\xFE".mb_convert_encoding(dtDeclare($x, 'UTF-16'), 'UTF-16LE', 'UTF-8'),
        'UTF-16BE with a BOM' => static fn (string $x): string => "\xFE\xFF".mb_convert_encoding(dtDeclare($x, 'UTF-16'), 'UTF-16BE', 'UTF-8'),
        'UTF-16LE without a BOM' => static fn (string $x): string => mb_convert_encoding(dtDeclare($x, 'UTF-16'), 'UTF-16LE', 'UTF-8'),
        'past a long comment' => static fn (string $x): string => preg_replace('/^(<\?xml[^>]*\?>\s*)/', '$1<!-- '.str_repeat('p', 5000).' -->', $x, 1),
    ];
}

/** Parts whose marker reaches the result. */
function dtMarkerParts(): array
{
    return ['xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml', 'xl/workbook.xml', 'docProps/core.xml', 'xl/comments1.xml'];
}

/** The part with its marker swapped for the entity reference. */
function dtReferencing(array $parts, string $part): string
{
    return str_replace(DT_MARKER, '&x;', $parts[$part]);
}

function dtRead(array $parts): string
{
    $path = dtZip($parts);
    try {
        return (string) json_encode((new XlsxReader())->describe($path));
    } catch (RuntimeException) {
        return '(refused)';
    } finally {
        unlink($path);
    }
}

function dtDescribe(array $parts): array
{
    $path = dtZip($parts);
    try {
        return (new XlsxReader())->describe($path);
    } finally {
        unlink($path);
    }
}

it('reads every marker from a clean file, so a missing one means refused', function () {
    $json = dtRead(dtXlsx());

    // Sheet name, A1 value, comment, A3 shared string, creator: one from each part.
    expect(substr_count($json, DT_MARKER))->toBe(5)
        ->and($json)->toContain('#ABCDEF');
});

it('uses a fixture whose entity is live, so its absence is the gate and not the fixture', function () {
    $sx = @simplexml_load_string('<?xml version="1.0"?>'.DT_DOCTYPE.'<r>&x;</r>');

    expect((string) $sx)->toBe(DT_ENTITY);
});

it('does not expand an internal entity from any part, in any encoding', function (string $part, string $encoding) {
    $parts = dtXlsx();
    $parts[$part] = dtEncodings()[$encoding](dtWithDoctype(dtReferencing($parts, $part)));

    expect(dtRead($parts))->not->toContain(DT_ENTITY);
})->with(dtMarkerParts())->with(array_keys(dtEncodings()));

it('still reads each encoding without a DOCTYPE', function (string $part, string $encoding) {
    $parts = dtXlsx();
    $clean = dtRead($parts);
    $parts[$part] = dtEncodings()[$encoding]($parts[$part]);

    expect(dtRead($parts))->toBe($clean);
})->with(dtMarkerParts())->with(array_keys(dtEncodings()));

it('does not read a value that comes from an entity', function (string $part, string $value, string $probe) {
    $parts = dtXlsx();
    expect($parts[$part])->toContain($value);
    $parts[$part] = dtWithDoctype(str_replace($value, '&x;', $parts[$part]), '<!DOCTYPE r [<!ENTITY x "'.$value.'">]>');

    expect(dtRead($parts))->not->toContain($probe);
})->with([
    'workbook rels' => ['xl/_rels/workbook.xml.rels', 'worksheets/sheet1.xml', '"cells"'],
    'sheet rels' => ['xl/worksheets/_rels/sheet1.xml.rels', '../comments1.xml', '"comment"'],
    'styles' => ['xl/styles.xml', 'FFABCDEF', '#ABCDEF'],
]);

describe('what a consumer sees', function () {
    it('throws for a refused workbook part, as for a malformed one', function () {
        $parts = dtXlsx();
        $parts['xl/workbook.xml'] = dtWithDoctype($parts['xl/workbook.xml']);

        expect(fn () => dtDescribe($parts))->toThrow(RuntimeException::class, 'failed to parse xl/workbook.xml');
    });

    it('keeps the sheet, with no cells, for a refused worksheet part', function () {
        $parts = dtXlsx();
        $parts['xl/worksheets/sheet1.xml'] = dtWithDoctype($parts['xl/worksheets/sheet1.xml']);
        $sheet = dtDescribe($parts)['sheets'][0];

        expect($sheet['name'])->toBe(DT_MARKER)->and($sheet['cells'] ?? [])->toBe([]);
    });

    it('reads the other parts, without the refused one, for shared strings', function () {
        $parts = dtXlsx();
        $parts['xl/sharedStrings.xml'] = dtWithDoctype($parts['xl/sharedStrings.xml']);
        $cells = dtDescribe($parts)['sheets'][0]['cells'];

        expect($cells['A1']['value'])->toBe(DT_MARKER)
            ->and($cells['A3']['value'] ?? '')->not->toBe(DT_MARKER);
    });
});

describe('external entities', function () {
    beforeEach(function () {
        $this->canary = tempnam(sys_get_temp_dir(), 'hs_dt_canary_');
        file_put_contents($this->canary, 'HSCANARY');
        $this->url = 'file:///'.str_replace(chr(92), '/', $this->canary);
        $this->loads = [];
        $loads = &$this->loads;
        libxml_set_external_entity_loader(function ($public, $system) use (&$loads) {
            $loads[] = $system;

            return null;
        });
    });

    afterEach(function () {
        libxml_set_external_entity_loader(null);
        unlink($this->canary);
    });

    it('catches a load when one happens, so zero loads below means something', function () {
        $prev = libxml_use_internal_errors(true);
        simplexml_load_string('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "'.$this->url.'">]><r>&x;</r>', SimpleXMLElement::class, LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        expect($this->loads)->toHaveCount(1);
    });

    it('neither loads nor leaks a file entity', function (string $part) {
        $parts = dtXlsx();
        $parts[$part] = dtWithDoctype(dtReferencing($parts, $part), '<!DOCTYPE r [<!ENTITY x SYSTEM "'.$this->url.'">]>');

        expect(dtRead($parts))->not->toContain('HSCANARY')
            ->and($this->loads)->toBe([]);
    })->with(dtMarkerParts());
});

describe('text that only looks like a DOCTYPE', function () {
    it('is kept in a shared string written as CDATA', function () {
        $parts = dtXlsx();
        $parts['xl/sharedStrings.xml'] = str_replace('<t>'.DT_MARKER.'</t>', '<t><![CDATA[<!DOCTYPE html>]]></t>', $parts['xl/sharedStrings.xml']);

        expect(dtDescribe($parts)['sheets'][0]['cells']['A3']['value'])->toBe('<!DOCTYPE html>');
    });

    it('does not stop a part being read from a comment', function () {
        $parts = dtXlsx();
        $parts['xl/worksheets/sheet1.xml'] = dtWithDoctype($parts['xl/worksheets/sheet1.xml'], '<!-- <!DOCTYPE x> -->');

        expect(dtDescribe($parts)['sheets'][0]['cells']['A1']['value'])->toBe(DT_MARKER);
    });
});

it('leaves no way to a part except through the gate', function () {
    $offenders = [];
    $root = dirname(__DIR__, 2).'/src';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'PartXml.php') {
            continue;
        }
        if (preg_match('/simplexml_load_(string|file)|->loadXML\(|->load\(.*LIBXML|new XMLReader|xml_parser_create/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
