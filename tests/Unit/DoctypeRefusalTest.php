<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Exceptions\OfficeTemplateRefused;
use HolySheet\Exceptions\UnsupportedFormatException;

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
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
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

/** A real package from the real writer, plus the parts it does not author. */
function dtXlsx(): array
{
    $parts = dtUnzip(Agent::toBytes([
        'meta' => ['creator' => DT_MARKER, 'created' => '2026-01-01T00:00:00Z'],
        'sheets' => [[
            'name' => 'Sheet1',
            'cells' => [
                'A1' => ['value' => DT_MARKER, 'comment' => ['text' => DT_MARKER, 'author' => 'Auth']],
                'A2' => ['value' => 1, 'format' => ['formatCode' => '# ?/? HSBASE']],
                'A3' => 2,
            ],
            'charts' => [['type' => 'column', 'title' => DT_MARKER, 'series' => [['values' => 'A2:A3']]]],
        ]],
    ]));

    $parts['xl/workbook.xml'] = str_replace('</sheets>', '</sheets><definedNames><definedName name="N">'.DT_MARKER.'</definedName></definedNames>', $parts['xl/workbook.xml']);
    $parts['xl/tables/table1.xml'] = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="T" displayName="'.DT_MARKER.'" ref="A1:A3"/>';
    $parts['xl/worksheets/_rels/sheet1.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="rId99" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/></Relationships>', $parts['xl/worksheets/_rels/sheet1.xml.rels']);
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
    return ['xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml', 'xl/styles.xml', 'xl/workbook.xml', 'docProps/core.xml', 'xl/comments1.xml', 'xl/tables/table1.xml', 'xl/charts/chart1.xml'];
}

/** The part with its marker swapped for the entity reference. */
function dtReferencing(array $parts, string $part): string
{
    return str_replace(DT_MARKER, '&x;', $parts[$part]);
}

function dtRead(array $parts): string
{
    try {
        return (string) json_encode(Agent::read(dtZip($parts)));
    } catch (RuntimeException) {
        return '(refused)';
    }
}

it('reads every marker from a clean file, so a missing one means refused', function () {
    $json = dtRead(dtXlsx());

    // A1 value, comment, foreign format code, A3 shared string, chart title,
    // table, defined name, creator: one from each part this file proves.
    expect(substr_count($json, DT_MARKER))->toBe(8);
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

it('does not follow a relationship whose target comes from an entity', function (string $part, string $target, string $probe) {
    $parts = dtXlsx();
    expect($parts[$part])->toContain($target);
    $parts[$part] = dtWithDoctype(str_replace($target, '&x;', $parts[$part]), '<!DOCTYPE r [<!ENTITY x "'.$target.'">]>');

    expect(dtRead($parts))->not->toContain($probe);
})->with([
    'workbook rels' => ['xl/_rels/workbook.xml.rels', 'worksheets/sheet1.xml', '"Sheet1"'],
    'sheet rels' => ['xl/worksheets/_rels/sheet1.xml.rels', '../comments1.xml', '"comment"'],
    'drawing rels' => ['xl/drawings/_rels/drawing1.xml.rels', '../charts/chart1.xml', '"charts"'],
]);

it('does not read a drawing that takes an anchor from an entity', function () {
    $parts = dtXlsx();
    $parts['xl/drawings/drawing1.xml'] = dtWithDoctype(str_replace('<xdr:col>2</xdr:col>', '<xdr:col>&x;</xdr:col>', $parts['xl/drawings/drawing1.xml']), '<!DOCTYPE r [<!ENTITY x "2">]>');

    expect(dtRead($parts))->not->toContain('"charts"');
});

describe('what a consumer sees', function () {
    it('throws for a refused workbook part, as for a malformed one', function () {
        $parts = dtXlsx();
        $parts['xl/workbook.xml'] = dtWithDoctype($parts['xl/workbook.xml']);

        expect(fn () => Agent::read(dtZip($parts)))->toThrow(RuntimeException::class, 'failed to parse xl/workbook.xml');
    });

    it('keeps the sheet, with no cells, for a refused worksheet part', function () {
        $parts = dtXlsx();
        $parts['xl/worksheets/sheet1.xml'] = dtWithDoctype($parts['xl/worksheets/sheet1.xml']);
        $sheet = Agent::read(dtZip($parts))['sheets'][0];

        expect($sheet['name'])->toBe('Sheet1')->and($sheet['cells'] ?? [])->toBe([]);
    });

    it('reads the other parts, without the refused one, for shared strings', function () {
        $parts = dtXlsx();
        $parts['xl/sharedStrings.xml'] = dtWithDoctype($parts['xl/sharedStrings.xml']);
        $cells = Agent::read(dtZip($parts))['sheets'][0]['cells'];

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

        expect(Agent::read(dtZip($parts))['sheets'][0]['cells']['A3']['value'])->toBe('<!DOCTYPE html>');
    });

    it('is kept in a chart title written as CDATA', function () {
        $parts = dtXlsx();
        $parts['xl/charts/chart1.xml'] = str_replace(DT_MARKER, '<![CDATA[<!DOCTYPE html>]]>', $parts['xl/charts/chart1.xml']);

        expect(Agent::read(dtZip($parts))['sheets'][0]['charts'][0]['title'])->toBe('<!DOCTYPE html>');
    });

    it('does not stop a part being read from a comment', function () {
        $parts = dtXlsx();
        $parts['xl/worksheets/sheet1.xml'] = dtWithDoctype($parts['xl/worksheets/sheet1.xml'], '<!-- <!DOCTYPE x> -->');

        expect(Agent::read(dtZip($parts))['sheets'][0]['cells']['A1']['value'])->toBe(DT_MARKER);
    });
});

function dtOds(string $text = DT_MARKER): array
{
    $ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"';
    $decl = '<?xml version="1.0" encoding="UTF-8"?>';

    return [
        'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
        'content.xml' => $decl.'<office:document-content '.$ns.'><office:body><office:spreadsheet><table:table table:name="S"><table:table-row><table:table-cell office:value-type="string"><text:p>'.$text.'</text:p></table:table-cell></table:table-row></table:table></office:spreadsheet></office:body></office:document-content>',
        'styles.xml' => $decl.'<office:document-styles '.$ns.'/>',
        'meta.xml' => $decl.'<office:document-meta '.$ns.'><office:meta><meta:initial-creator>'.$text.'</meta:initial-creator></office:meta></office:document-meta>',
    ];
}

describe('ods', function () {
    it('reads a clean file', function () {
        $json = dtRead(dtOds());

        expect(substr_count($json, DT_MARKER))->toBe(2);
    });

    it('throws for a refused content part', function (string $encoding) {
        $parts = dtOds('&x;');
        $parts['content.xml'] = dtEncodings()[$encoding](dtWithDoctype($parts['content.xml']));

        expect(fn () => Agent::read(dtZip($parts)))->toThrow(RuntimeException::class, 'failed to parse content.xml');
    })->with(array_keys(dtEncodings()));

    it('does not expand from meta', function (string $encoding) {
        $parts = dtOds();
        $parts['meta.xml'] = dtEncodings()[$encoding](dtWithDoctype(str_replace(DT_MARKER, '&x;', $parts['meta.xml'])));

        expect(dtRead($parts))->not->toContain(DT_ENTITY);
    })->with(array_keys(dtEncodings()));
});

describe('the package declaration', function () {
    /** A template that says it carries macros, which is refused outright when its declarations are read. */
    function dtMacroTemplate(): array
    {
        $parts = dtXlsx();
        $parts['[Content_Types].xml'] = str_replace(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
            'application/vnd.ms-excel.template.macroEnabled.main+xml',
            $parts['[Content_Types].xml'],
        );

        return $parts;
    }

    it('refuses a macro template for its declaration, so the cases below have something to skip', function () {
        $parts = dtMacroTemplate();
        expect($parts['[Content_Types].xml'])->toContain('template.macroEnabled');

        expect(fn () => Agent::read(dtZip($parts)))->toThrow(OfficeTemplateRefused::class, 'macro_enabled');
    });

    it('reads a clean declaration in every encoding', function (string $encoding) {
        $parts = dtXlsx();
        $clean = dtRead($parts);
        $parts['[Content_Types].xml'] = dtEncodings()[$encoding]($parts['[Content_Types].xml']);

        expect(dtRead($parts))->toBe($clean);
    })->with(array_keys(dtEncodings()));

    it('still refuses the macro template when its declaration carries a DOCTYPE', function (string $encoding) {
        $parts = dtMacroTemplate();
        $parts['[Content_Types].xml'] = dtEncodings()[$encoding](dtWithDoctype($parts['[Content_Types].xml']));

        expect(fn () => Agent::read(dtZip($parts)))->toThrow(UnsupportedFormatException::class);
    })->with(array_keys(dtEncodings()));

    it('refuses a plain workbook whose declaration carries a DOCTYPE', function (string $encoding) {
        $parts = dtXlsx();
        $parts['[Content_Types].xml'] = dtEncodings()[$encoding](dtWithDoctype($parts['[Content_Types].xml']));

        expect(fn () => Agent::read(dtZip($parts)))->toThrow(UnsupportedFormatException::class);
    })->with(array_keys(dtEncodings()));

    it('refuses one the prolog check cannot read, by asking the parser', function () {
        $parts = dtXlsx();
        $parts['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-7"?>'
            .'+ADw-+ACE-DOCTYPE r +AFs-+ADw-+ACE-ENTITY x +ACI-HSINT+ACI-+AD4-+AF0-+AD4-'
            .'+ADw-Types xmlns+AD0AIg-http://schemas.openxmlformats.org/package/2006/content-types+ACI-/+AD4-';
        $live = @simplexml_load_string($parts['[Content_Types].xml']);
        if ($live === false || $live->getName() !== 'Types') {
            $this->markTestSkipped('this libxml has no UTF-7 decoder, so there is nothing to refuse');
        }

        expect(fn () => Agent::read(dtZip($parts)))->toThrow(UnsupportedFormatException::class);
    });

    it('reports a refused declaration through every way in', function () {
        $parts = dtXlsx();
        $parts['[Content_Types].xml'] = dtWithDoctype($parts['[Content_Types].xml']);
        $bytes = dtZip($parts);

        expect(Agent::catalog($bytes))->toBe(['ok' => false, 'refused' => ['reason' => 'not_a_workbook']])
            ->and(fn () => Agent::convertTemplate($bytes))->toThrow(UnsupportedFormatException::class);
    });

    it('leaves a malformed declaration handled as it always was', function () {
        $parts = dtXlsx();
        $clean = dtRead($parts);
        $parts['[Content_Types].xml'] = '<Types>';

        expect(dtRead($parts))->toBe($clean);
    });

    it('does not refuse a declaration for text that only looks like a DOCTYPE', function () {
        $parts = dtXlsx();
        $clean = dtRead($parts);
        $parts['[Content_Types].xml'] = str_replace('</Types>', '<!-- <!DOCTYPE x> --></Types>', dtWithDoctype($parts['[Content_Types].xml'], '<!-- <!DOCTYPE x> -->'));

        expect(dtRead($parts))->toBe($clean);
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
