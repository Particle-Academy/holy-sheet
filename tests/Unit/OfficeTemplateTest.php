<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Exceptions\ArchiveRefused;
use HolySheet\Exceptions\OfficeTemplateRefused;
use HolySheet\Exceptions\UnsupportedFormatException;

/*
 * Official template formats: Excel .xltx and LibreOffice .ots.
 *
 * A template IS its document twin with a different declared type: the main
 * part's content type in [Content_Types].xml (OOXML), or the `mimetype` entry
 * plus the manifest's root entry (ODF 1.3 Part 2 section 3.3 requires the two to
 * be equal). describe() marks `sourceKind: "template"`; the converter flips the
 * declaration and copies every other part byte for byte.
 *
 * Every fixture is built here with ZipArchive, independently of the production
 * converter, so a converter bug cannot also forge its own evidence.
 */

const HST_DOC = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
const HST_TEMPLATE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.template.main+xml';
const HST_MACRO_TEMPLATE = 'application/vnd.ms-excel.template.macroEnabled.main+xml';
const HST_MACRO_DOC = 'application/vnd.ms-excel.sheet.macroEnabled.main+xml';
const HST_PPT_TEMPLATE = 'application/vnd.openxmlformats-officedocument.presentationml.template.main+xml';
const HST_ODS = 'application/vnd.oasis.opendocument.spreadsheet';
const HST_OTS = 'application/vnd.oasis.opendocument.spreadsheet-template';

function hstSchema(): array
{
    return ['sheets' => [['name' => 'Budget', 'columns' => [['header' => 'Item'], ['header' => 'Cost']],
        'rows' => [['Rent', 1200], ['Power', 85]]]]];
}

/** describe() takes a path, so every byte string goes through a temp file. */
function hstDescribe(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'hst_');
    file_put_contents($path, $bytes);
    try {
        return Agent::describe($path);
    } finally {
        unlink($path);
    }
}

/** @return array<string, string> entry name => uncompressed bytes, in archive order */
function hstEntries(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'hst_');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    expect($zip->open($tmp, ZipArchive::RDONLY))->toBeTrue();
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($tmp);

    return $out;
}

function hstMethods(string $bytes): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'hst_');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::RDONLY);
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $out[$stat['name']] = $stat['comp_method'];
    }
    $zip->close();
    unlink($tmp);

    return $out;
}

/** Re-zip name => bytes in order; `mimetype` is written STORED as ODF requires. */
function hstZip(array $entries): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'hst_').'.zip';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $data) {
        $zip->addFromString($name, $data);
        if ($name === 'mimetype') {
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
    }
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

function hstXlsx(): string
{
    return Agent::toBytes(hstSchema());
}

/** The xlsx with its main part re-declared; nothing else differs. */
function hstRetyped(string $contentType, array $extra = [], ?string $base = null): string
{
    // The writer stamps the creation time, so compare a template with the very
    // bytes it was derived from rather than a second write.
    $entries = hstEntries($base ?? hstXlsx());
    $entries['[Content_Types].xml'] = str_replace(HST_DOC, $contentType, $entries['[Content_Types].xml']);

    return hstZip($entries + $extra);
}

function hstXltx(): string
{
    return hstRetyped(HST_TEMPLATE);
}

function hstOdf(string $mimetype, string $body = 'spreadsheet', ?string $manifestType = null): string
{
    $manifestType ??= $mimetype;
    $inner = $body === 'spreadsheet'
        ? '<table:table table:name="Budget"><table:table-row>'
            .'<table:table-cell office:value-type="string"><text:p>Rent</text:p></table:table-cell>'
            .'<table:table-cell office:value-type="float" office:value="1200"><text:p>1200</text:p></table:table-cell>'
            .'</table:table-row></table:table>'
        : '<text:p>Not a spreadsheet</text:p>';

    return hstZip([
        'mimetype' => $mimetype,
        'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" office:version="1.3">'
            ."<office:body><office:{$body}>{$inner}</office:{$body}></office:body>"
            .'</office:document-content>',
        'Thumbnails/opaque.bin' => "\0\x01\x02binary\xff",
        'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'."\n"
            .' <manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="'.$manifestType.'"/>'."\n"
            .' <manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'."\n"
            .'</manifest:manifest>',
    ]);
}

function hstRefused(callable $action, string $reason): void
{
    try {
        $action();
    } catch (OfficeTemplateRefused $error) {
        expect($error->reason)->toBe($reason);

        return;
    }
    throw new RuntimeException("Expected a {$reason} refusal; the input was accepted.");
}

function hstArchiveRefused(callable $action, string $reason): void
{
    try {
        $action();
    } catch (ArchiveRefused $error) {
        expect($error->reason)->toBe($reason);

        return;
    }
    throw new RuntimeException("Expected archive refusal {$reason}; the input was accepted.");
}

// ─── Reading ──────────────────────────────────────────────────────────────

it('describes an Excel .xltx and marks it as a template', function () {
    $xlsx = hstXlsx();
    $document = hstDescribe($xlsx);
    $template = hstDescribe(hstRetyped(HST_TEMPLATE, [], $xlsx));

    expect($template['sourceKind'] ?? null)->toBe('template');
    unset($template['sourceKind']);
    expect($template)->toBe($document);
});

it('leaves an .xlsx description exactly as it was: no sourceKind key', function () {
    expect(hstDescribe(hstXlsx()))->not->toHaveKey('sourceKind');
});

it('describes a LibreOffice .ots and marks it as a template', function () {
    $document = hstDescribe(hstOdf(HST_ODS));
    $template = hstDescribe(hstOdf(HST_OTS));

    expect($document)->not->toHaveKey('sourceKind')
        ->and($template['sourceKind'] ?? null)->toBe('template');
    unset($template['sourceKind']);
    expect($template)->toBe($document);
});

it('keeps a template description valid and writable', function () {
    $template = hstDescribe(hstXltx());
    expect(Agent::validate($template))->toBe([])
        ->and(hstDescribe(Agent::toBytes($template)))->not->toHaveKey('sourceKind');
});

it('refuses a macro-enabled .xltm by name rather than reading around the VBA', function () {
    hstRefused(fn () => hstDescribe(hstRetyped(HST_MACRO_TEMPLATE)), 'macro_enabled');
});

it('refuses an .xltx that carries a VBA project anyway', function () {
    $bytes = hstRetyped(HST_TEMPLATE, ['xl/vbaProject.bin' => "\xD0\xCF\x11\xE0 not really"]);
    hstRefused(fn () => hstDescribe($bytes), 'macro_enabled');
});

it('still describes a macro-enabled WORKBOOK (.xlsm) as before: only templates changed', function () {
    expect(hstDescribe(hstRetyped(HST_MACRO_DOC)))->not->toHaveKey('sourceKind');
});

// ─── Hostile input ────────────────────────────────────────────────────────

it('refuses a template whose content type claims another family', function () {
    hstRefused(fn () => hstDescribe(hstRetyped(HST_PPT_TEMPLATE)), 'family_mismatch');

    $entries = hstEntries(hstXlsx());
    $entries['[Content_Types].xml'] = str_replace('</Types>',
        '<Override PartName="/ppt/presentation.xml" ContentType="'.HST_PPT_TEMPLATE.'"/></Types>',
        $entries['[Content_Types].xml']);
    hstRefused(fn () => hstDescribe(hstZip($entries)), 'family_mismatch');

    $entries = hstEntries(hstXlsx());
    $entries['[Content_Types].xml'] = str_replace('ContentType="application/xml"',
        'ContentType="'.HST_TEMPLATE.'"', $entries['[Content_Types].xml']);
    hstRefused(fn () => hstDescribe(hstZip($entries)), 'family_mismatch');
});

it('refuses an ODF spreadsheet template whose body is text', function () {
    hstRefused(fn () => hstDescribe(hstOdf(HST_OTS, 'text')), 'family_mismatch');
});

it('refuses an ODF template of another family by name', function () {
    expect(fn () => hstDescribe(hstOdf('application/vnd.oasis.opendocument.text-template', 'text')))
        ->toThrow(UnsupportedFormatException::class);
});

it('refuses an ODF template whose manifest names another family', function () {
    hstRefused(fn () => hstDescribe(hstOdf(HST_OTS, 'spreadsheet', 'application/vnd.oasis.opendocument.text-template')), 'family_mismatch');
});

it('refuses a template zip bomb through the archive guard, reading and converting', function () {
    $bomb = hstRetyped(HST_TEMPLATE, ['xl/media/bomb.bin' => str_repeat("\0", 65 * 1024 * 1024)]);
    hstArchiveRefused(fn () => hstDescribe($bomb), 'archive_limit');
    hstArchiveRefused(fn () => Agent::convertTemplate($bomb), 'archive_limit');
});

it('refuses a template with a traversing entry name, reading and converting', function () {
    $evil = hstRetyped(HST_TEMPLATE, ['../evil.xml' => '<x/>']);
    hstArchiveRefused(fn () => hstDescribe($evil), 'archive_path');
    hstArchiveRefused(fn () => Agent::convertTemplate($evil), 'archive_path');
});

// ─── Converting ───────────────────────────────────────────────────────────

it('converts an .xltx to an .xlsx changing only the content-type value', function () {
    $xltx = hstXltx();
    $xlsx = Agent::convertTemplate($xltx, 'document');

    $before = hstEntries($xltx);
    $after = hstEntries($xlsx);
    expect(array_keys($after))->toBe(array_keys($before));
    foreach ($before as $name => $data) {
        if ($name === '[Content_Types].xml') {
            expect($after[$name])->toBe(str_replace(HST_TEMPLATE, HST_DOC, $data));
        } else {
            expect($after[$name])->toBe($data, "{$name} changed");
        }
    }
    expect(hstDescribe($xlsx))->not->toHaveKey('sourceKind');
});

it('round-trips xlsx to xltx and back with every part byte-identical', function () {
    $xlsx = hstXlsx();
    $xltx = Agent::convertTemplate($xlsx, 'template');
    $back = Agent::convertTemplate($xltx, 'document');

    expect(hstDescribe($xltx)['sourceKind'])->toBe('template')
        ->and(hstEntries($back))->toBe(hstEntries($xlsx));

    $methods = hstMethods($xlsx);
    unset($methods['[Content_Types].xml']);
    $converted = hstMethods($xltx);
    unset($converted['[Content_Types].xml']);
    expect($converted)->toBe($methods);
});

it('flips direction when no target is given, and takes a path', function () {
    $path = tempnam(sys_get_temp_dir(), 'hst_');
    file_put_contents($path, hstXltx());
    expect(hstDescribe(Agent::convertTemplate(hstXlsx()))['sourceKind'])->toBe('template')
        ->and(hstDescribe(Agent::convertTemplate($path)))->not->toHaveKey('sourceKind');
    unlink($path);
});

it('converts .ods to .ots keeping mimetype first, STORED and without an extra field', function () {
    $ods = hstOdf(HST_ODS);
    $ots = Agent::convertTemplate($ods, 'template');

    $before = hstEntries($ods);
    $after = hstEntries($ots);
    expect(array_keys($after))->toBe(array_keys($before))
        ->and(array_key_first($after))->toBe('mimetype')
        ->and($after['mimetype'])->toBe(HST_OTS)
        ->and(hstMethods($ots)['mimetype'])->toBe(ZipArchive::CM_STORE)
        ->and(substr($ots, 0, 4))->toBe("PK\x03\x04")
        ->and(unpack('v', substr($ots, 28, 2))[1])->toBe(0)
        ->and(substr($ots, 30, 8))->toBe('mimetype')
        ->and(substr($ots, 38, strlen(HST_OTS)))->toBe(HST_OTS);

    // ODF 1.3 Part 2 section 3.3: mimetype SHALL equal the manifest's root
    // media-type, so that one attribute value moves with it and nothing else.
    expect($after['META-INF/manifest.xml'])->toBe(str_replace(
        'manifest:full-path="/" manifest:version="1.3" manifest:media-type="'.HST_ODS.'"',
        'manifest:full-path="/" manifest:version="1.3" manifest:media-type="'.HST_OTS.'"',
        $before['META-INF/manifest.xml'],
    ));
    foreach ($before as $name => $data) {
        if (! in_array($name, ['mimetype', 'META-INF/manifest.xml'], true)) {
            expect($after[$name])->toBe($data, "{$name} changed");
        }
    }

    expect(hstDescribe($ots)['sourceKind'])->toBe('template')
        ->and(hstEntries(Agent::convertTemplate($ots, 'document')))->toBe($before);
});

it('refuses conversions it must not make', function () {
    hstRefused(fn () => Agent::convertTemplate(hstXltx(), 'template'), 'already_template');
    hstRefused(fn () => Agent::convertTemplate(hstXlsx(), 'document'), 'already_document');
    hstRefused(fn () => Agent::convertTemplate(hstRetyped(HST_MACRO_TEMPLATE), 'document'), 'macro_enabled');
    hstRefused(fn () => Agent::convertTemplate(hstRetyped(HST_MACRO_DOC), 'template'), 'macro_enabled');
    hstRefused(fn () => Agent::convertTemplate(hstXlsx(), 'presentation'), 'bad_target');
    hstRefused(fn () => Agent::convertTemplate(hstRetyped(HST_PPT_TEMPLATE)), 'family_mismatch');
});

it('refuses an ODF package whose mimetype is not stored first', function () {
    $entries = hstEntries(hstOdf(HST_ODS));
    $mimetype = ['mimetype' => $entries['mimetype']];
    unset($entries['mimetype']);
    hstRefused(fn () => Agent::convertTemplate(hstZip($entries + $mimetype)), 'odf_mimetype');
});

it('refuses a content-type declaration it cannot rewrite unambiguously', function () {
    $entries = hstEntries(hstXltx());
    $entries['[Content_Types].xml'] = str_replace(
        '<Override PartName="/xl/workbook.xml"',
        '<!-- <Override PartName="/xl/workbook.xml" ContentType="'.HST_TEMPLATE.'"/> --><Override PartName="/xl/workbook.xml"',
        $entries['[Content_Types].xml'],
    );
    hstRefused(fn () => Agent::convertTemplate(hstZip($entries)), 'not_convertible');
});

it('names a word-processing package instead of converting it', function () {
    $docx = hstZip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>']);
    expect(fn () => Agent::convertTemplate($docx))->toThrow(UnsupportedFormatException::class);
    expect(fn () => Agent::convertTemplate(hstOdf('application/vnd.oasis.opendocument.text', 'text')))
        ->toThrow(UnsupportedFormatException::class);
});

// ─── CLI ──────────────────────────────────────────────────────────────────

function hstCli(array $args): array
{
    $command = array_merge([PHP_BINARY, dirname(__DIR__, 2).'/bin/holy-sheet'], $args);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

it('converts through the template:convert CLI', function () {
    $dir = sys_get_temp_dir().'/hst-cli-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/in.xltx", hstXltx());

    [$code, $stdout] = hstCli(['template:convert', "{$dir}/in.xltx", "{$dir}/out.xlsx"]);
    expect($code)->toBe(0)
        ->and($stdout)->toContain('document')
        ->and(Agent::describe("{$dir}/out.xlsx"))->not->toHaveKey('sourceKind');

    [$code, , $stderr] = hstCli(['template:convert', "{$dir}/out.xlsx", "{$dir}/out.xlsx", '--to=template']);
    expect($code)->toBe(1)->and($stderr)->toContain('exists');

    [$code] = hstCli(['template:convert', "{$dir}/out.xlsx", "{$dir}/back.xltx", '--to=template']);
    expect($code)->toBe(0)->and(Agent::describe("{$dir}/back.xltx")['sourceKind'])->toBe('template');

    file_put_contents("{$dir}/macro.xltm", hstRetyped(HST_MACRO_TEMPLATE));
    [$code, , $stderr] = hstCli(['template:convert', "{$dir}/macro.xltm", "{$dir}/macro.xlsx"]);
    expect($code)->toBe(1)->and($stderr)->toContain('macro_enabled')
        ->and(file_exists("{$dir}/macro.xlsx"))->toBeFalse();

    [$code, , $stderr] = hstCli(['template:convert']);
    expect($code)->toBe(2)->and($stderr)->toContain('Usage');

    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);
});

// ─── LibreOffice as an oracle (skipped where it is not installed) ─────────

function hstSoffice(): ?string
{
    $candidates = array_filter([getenv('LIBREOFFICE_BIN') ?: null,
        'C:/Program Files/LibreOffice/program/soffice.com', '/usr/bin/soffice', '/usr/local/bin/soffice',
        '/Applications/LibreOffice.app/Contents/MacOS/soffice']);
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/** Headless convert; the profile lives in a SHORT temp path (long ones crash LO on Windows). */
function hstLibreOffice(string $soffice, string $input, string $format, string $outDir): string
{
    $profile = sys_get_temp_dir().'/hslo'.bin2hex(random_bytes(3));
    $url = 'file:///'.ltrim(str_replace(chr(92), '/', $profile), '/');
    $process = proc_open([$soffice, '-env:UserInstallation='.$url, '--headless', '--norestore',
        '--convert-to', $format, '--outdir', $outDir, $input], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $out = $outDir.'/'.pathinfo($input, PATHINFO_FILENAME).'.'.explode(':', $format)[0];
    expect($code)->toBe(0)->and(is_file($out))->toBeTrue("LibreOffice produced no {$format}");

    return $out;
}

it('reads what LibreOffice writes as .xltx and .ots, and LibreOffice reads our conversions', function () {
    $soffice = hstSoffice();
    if ($soffice === null) {
        $this->markTestSkipped('LibreOffice is not installed; the oracle check runs where it is.');
    }
    $dir = sys_get_temp_dir().'/hso'.bin2hex(random_bytes(3));
    mkdir($dir);
    file_put_contents("{$dir}/src.xlsx", hstXlsx());

    foreach (['xltx' => 'xlsx', 'ots' => 'ods'] as $template => $document) {
        $loTemplate = hstLibreOffice($soffice, "{$dir}/src.xlsx", $template, $dir);
        $read = Agent::describe($loTemplate);
        expect($read['sourceKind'] ?? null)->toBe('template', "LibreOffice .{$template} not marked")
            ->and(json_encode($read))->toContain('Rent');

        mkdir("{$dir}/{$template}");
        $loBack = hstLibreOffice($soffice, $loTemplate, $document, "{$dir}/{$template}");
        expect(Agent::describe($loBack))->not->toHaveKey('sourceKind');

        file_put_contents("{$dir}/ours.{$document}", Agent::convertTemplate($loTemplate, 'document'));
        mkdir("{$dir}/ours-{$template}");
        $csv = hstLibreOffice($soffice, "{$dir}/ours.{$document}", 'csv', "{$dir}/ours-{$template}");
        expect(file_get_contents($csv))->toContain('Rent,1200');
    }
})->group('libreoffice');
