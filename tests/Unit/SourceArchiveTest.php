<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Archive\SourceArchive;
use HolySheet\Exceptions\ArchiveRefused;

/** A small deterministic ZIP fixture builder, independent of production readers. */
function editing_source_zip(array $entries, string $comment = ''): string
{
    $locals = '';
    $central = '';
    foreach ($entries as $entry) {
        $name = $entry['name'];
        $data = $entry['data'];
        $method = $entry['method'] ?? 0;
        $descriptor = $entry['descriptor'] ?? false;
        $flags = $entry['flags'] ?? ($descriptor ? 0x0808 : 0x0800);
        $compressed = $method === 8 ? gzdeflate($data) : $data;
        $crc = $entry['crc'] ?? crc32($data);
        $size = $entry['size'] ?? strlen($data);
        $offset = strlen($locals);
        $locals .= pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, $method, 0, 33,
            $descriptor ? 0 : $crc, $descriptor ? 0 : strlen($compressed), $descriptor ? 0 : $size, strlen($name), 0).$name.$compressed;
        if ($descriptor) {
            $locals .= pack('VVVV', 0x08074B50, $crc, strlen($compressed), $size);
        }
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, $flags, $method, 0, 33,
            $crc, strlen($compressed), $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
    }

    return $locals.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries), strlen($central), strlen($locals), strlen($comment)).$comment;
}

function editing_archive_refused(callable $action, string $reason): void
{
    try {
        $action();
    } catch (ArchiveRefused $error) {
        expect($error->reason)->toBe($reason);

        return;
    }
    throw new RuntimeException('The unsafe source archive was accepted.');
}

it('retains original container bytes, central order and opaque payloads', function () {
    $payload = "\0\xffopaque\r\n";
    $bytes = editing_source_zip([
        ['name' => 'unknown/payload.bin', 'data' => $payload],
        ['name' => '[Content_Types].xml', 'data' => '<Types/>'],
        ['name' => 'xl/worksheets/custom.xml', 'data' => '<x value="&#65;">🦊</x>'],
    ], "original archive comment PK\x05\x06".str_repeat("\0", 22));
    $source = SourceArchive::open($bytes);
    expect($source->toBytes())->toBe($bytes)
        ->and($source->names())->toBe(['unknown/payload.bin', '[Content_Types].xml', 'xl/worksheets/custom.xml'])
        ->and($source->read('unknown/payload.bin'))->toBe($payload)
        ->and($source->read('absent.xml'))->toBeNull();
    $names = $source->names();
    $names[0] = 'changed';
    expect($source->names()[0])->toBe('unknown/payload.bin');
});

it('reads bounded DEFLATE and data-descriptor members with exact bytes', function (bool $descriptor) {
    $payload = str_repeat('Spreadsheet 🦊 ', 120);
    $bytes = editing_source_zip([['name' => 'opaque.xml', 'data' => $payload, 'method' => 8, 'descriptor' => $descriptor]]);
    $source = SourceArchive::open($bytes);
    expect($source->read('opaque.xml'))->toBe($payload)->and($source->toBytes())->toBe($bytes);
})->with([false, true]);

it('refuses duplicate members rather than silently replacing one', function () {
    editing_archive_refused(fn () => SourceArchive::open(editing_source_zip([
        ['name' => 'same.xml', 'data' => 'first'], ['name' => 'same.xml', 'data' => 'second'],
    ])), 'archive_duplicate');
});

it('refuses unsafe member names before opening payloads', function (string $name) {
    editing_archive_refused(fn () => SourceArchive::open(editing_source_zip([['name' => $name, 'data' => 'x']])), 'archive_path');
})->with(['', '../escape', '/absolute', 'a/../b', 'a\\b', 'C:/part.xml', 'a//b', './a', 'a/./b', "nul\0part"]);

it('refuses encryption and unsupported compression', function (array $attributes) {
    editing_archive_refused(fn () => SourceArchive::open(editing_source_zip([['name' => 'x.xml', 'data' => 'x'] + $attributes])), 'archive_unsupported');
})->with([[['flags' => 0x0801]], [['method' => 99]]]);

it('checks CRC and declared size independently of a successful ZIP read', function (array $attributes) {
    editing_archive_refused(fn () => SourceArchive::open(editing_source_zip([['name' => 'x.xml', 'data' => 'abc'] + $attributes])), 'archive_corrupt');
})->with([[['crc' => 123]], [['size' => 99]]]);

it('refuses disagreement between local and central member names', function () {
    $bytes = editing_source_zip([['name' => 'item.xml', 'data' => 'abc']]);
    $bytes[30] = 'X';
    editing_archive_refused(fn () => SourceArchive::open($bytes), 'archive_corrupt');
});

it('refuses a truncated archive', function () {
    $bytes = editing_source_zip([['name' => 'x.xml', 'data' => 'abc']]);
    editing_archive_refused(fn () => SourceArchive::open(substr($bytes, 0, -4)), 'archive_corrupt');
});

it('checks configurable compressed, entry, part and cumulative limits', function (array $limits) {
    $bytes = editing_source_zip([['name' => 'a.xml', 'data' => 'abcd'], ['name' => 'b.xml', 'data' => 'efgh']]);
    editing_archive_refused(fn () => SourceArchive::open($bytes, $limits), 'archive_limit');
})->with([[['maxArchiveBytes' => 10]], [['maxEntries' => 1]], [['maxPartBytes' => 3]], [['maxExpandedBytes' => 7]]]);

it('rejects an excessive expansion ratio before inflating', function () {
    $bytes = editing_source_zip([['name' => 'large.xml', 'data' => str_repeat('a', 10000), 'method' => 8]]);
    editing_archive_refused(fn () => SourceArchive::open($bytes, ['maxCompressionRatio' => 2]), 'archive_limit');
});

it('bounds actual inflation even when both headers understate expansion', function () {
    $bytes = editing_source_zip([['name' => 'large.xml', 'data' => str_repeat('a', 10000), 'method' => 8, 'size' => 20]]);
    editing_archive_refused(fn () => SourceArchive::open($bytes), 'archive_corrupt');
});

function editing_read_attack(string $attack): string
{
    $entries = [
        ['name' => 'xl/workbook.xml', 'data' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>'],
        ['name' => 'xl/_rels/workbook.xml.rels', 'data' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>'],
        ['name' => 'xl/worksheets/sheet1.xml', 'data' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>ok</t></is></c></row></sheetData></worksheet>'],
    ];
    $entries[] = match ($attack) {
        'bomb' => ['name' => 'unknown/bomb.bin', 'data' => str_repeat('a', 2097152), 'method' => 8],
        'duplicate' => $entries[0],
        'crc' => ['name' => 'unknown/corrupt.bin', 'data' => 'opaque', 'crc' => 123],
        'path' => ['name' => '../outside.xml', 'data' => 'opaque'],
        default => ['name' => 'unknown/valid.bin', 'data' => 'opaque'],
    };
    $bytes = editing_source_zip($entries);

    return $attack === 'truncated' ? substr($bytes, 0, -4) : $bytes;
}

it('refuses dangerous archives through the published read API with a typed error', function (string $attack, string $reason) {
    $path = tempnam(sys_get_temp_dir(), 'holy_archive_read_');
    file_put_contents($path, editing_read_attack($attack));
    try {
        editing_archive_refused(fn () => Agent::describe($path), $reason);
    } finally {
        unlink($path);
    }
})->with([
    ['bomb', 'archive_limit'], ['duplicate', 'archive_duplicate'], ['crc', 'archive_corrupt'],
    ['truncated', 'archive_corrupt'], ['path', 'archive_path'],
]);

it('retains every existing fixture payload against independent native ZIP digests', function () {
    $root = __DIR__.'/../fixtures/ods/';
    $cases = json_decode(file_get_contents($root.'archive-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($cases as $name => $expected) {
        $bytes = file_get_contents($root.$name);
        $source = SourceArchive::open($bytes);
        $parts = [];
        foreach ($source->names() as $part) {
            $parts[] = [$part, hash('sha256', $source->read($part))];
        }
        usort($parts, static fn ($a, $b) => strcmp($a[0], $b[0]));
        expect(hash('sha256', $bytes))->toBe($expected['sourceSha256'])
            ->and(count($parts))->toBe($expected['entries'])
            ->and(hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))->toBe($expected['payloadsSha256'])
            ->and($source->toBytes())->toBe($bytes);
    }
});

it('refuses oversized file input before reading the archive into memory', function () {
    $path = tempnam(sys_get_temp_dir(), 'holy_archive_limit_');
    $stream = fopen($path, 'r+b');
    ftruncate($stream, 67108865);
    fclose($stream);
    try {
        editing_archive_refused(fn () => Agent::describe($path), 'archive_limit');
    } finally {
        unlink($path);
    }
});

it('does not add stream-wrapper reads to the file API', function () {
    editing_archive_refused(fn () => SourceArchive::fromPath('php://memory'), 'archive_path');
});
