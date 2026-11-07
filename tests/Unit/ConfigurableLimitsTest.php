<?php

declare(strict_types=1);

use HolySheet\Agent;
use HolySheet\Exceptions\ArchiveRefused;
use HolySheet\HolySheet;
use HolySheet\Reader\ReadLimits;

/**
 * Every limit a read enforces can be raised by the app (owner, 2026-11-07:
 * "all limitations need to be a config option so apps can build to their use
 * case"). For each archive limit: a file the DEFAULT refuses, refused by name,
 * then read once that one limit is raised. The files are built here because
 * they are too big to commit; the small cases (lowering each limit) are shared
 * with the ports in tests/fixtures/read-options/cases.json.
 */

/** two-sheets.xlsx plus `$extra` members (name => [bytes, stored?]), as bytes. */
function limitsArchive(array $extra): string
{
    $path = tempnam(sys_get_temp_dir(), 'hs_limits_');
    copy(__DIR__.'/../fixtures/read-options/two-sheets.xlsx', $path);
    $zip = new ZipArchive();
    $zip->open($path);
    foreach ($extra as $name => [$bytes, $stored]) {
        $zip->addFromString($name, $bytes);
        $zip->setCompressionName($name, $stored ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE, 9);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/** `$bytes` long, a 4 KiB pseudo-random block repeated: deflates about 100:1, so no ratio limit decides. */
function limitsFiller(int $bytes): string
{
    mt_srand(7);
    $block = '';
    for ($i = 0; $i < 4096; $i++) {
        $block .= chr(mt_rand(0, 255));
    }

    return substr(str_repeat($block, intdiv($bytes, 4096) + 1), 0, $bytes);
}

function limitsRefusal(callable $read): ?array
{
    try {
        $read();
    } catch (ArchiveRefused $e) {
        return ['reason' => $e->reason, 'limit' => $e->limit];
    }

    return null;
}

function limitsSheets(array $schema): array
{
    return array_column($schema['sheets'], 'name');
}

it('raises maxEntries past a file the default refuses', function () {
    $extra = [];
    for ($i = 0; $i <= ReadLimits::ARCHIVE_DEFAULTS['maxEntries']; $i++) {
        $extra['pad/'.$i] = ['', true];
    }
    $bytes = limitsArchive($extra);

    expect(limitsRefusal(fn () => Agent::read($bytes)))->toBe(['reason' => 'archive_limit', 'limit' => 'maxEntries'])
        ->and(limitsSheets(Agent::read($bytes, ['limits' => ['maxEntries' => 20000]])))->toBe(['Data', 'Summary']);
});

it('raises maxCompressionRatio past a file the default refuses', function () {
    $bytes = limitsArchive(['pad.bin' => [str_repeat("\0", 4 * 1048576), false]]);

    expect(limitsRefusal(fn () => Agent::read($bytes)))->toBe(['reason' => 'archive_limit', 'limit' => 'maxCompressionRatio'])
        ->and(limitsSheets(Agent::read($bytes, ['limits' => ['maxCompressionRatio' => 2000]])))->toBe(['Data', 'Summary']);
});

it('raises maxPartBytes past a file the default refuses', function () {
    ini_set('memory_limit', '2G');
    $bytes = limitsArchive(['pad.bin' => [limitsFiller(65 * 1048576), false]]);

    expect(limitsRefusal(fn () => Agent::read($bytes)))->toBe(['reason' => 'archive_limit', 'limit' => 'maxPartBytes'])
        ->and(limitsSheets(Agent::read($bytes, ['limits' => ['maxPartBytes' => 128 * 1048576]])))->toBe(['Data', 'Summary']);
});

it('raises maxExpandedBytes past a file the default refuses', function () {
    ini_set('memory_limit', '2G');
    $filler = limitsFiller(55 * 1048576);
    $extra = [];
    for ($i = 1; $i <= 5; $i++) {
        $extra["pad{$i}.bin"] = [$filler, false];
    }
    $bytes = limitsArchive($extra);
    unset($filler, $extra);

    expect(limitsRefusal(fn () => Agent::read($bytes)))->toBe(['reason' => 'archive_limit', 'limit' => 'maxExpandedBytes'])
        ->and(limitsSheets(Agent::read($bytes, ['limits' => ['maxExpandedBytes' => 512 * 1048576]])))->toBe(['Data', 'Summary']);
});

it('raises maxArchiveBytes past a file the default refuses, from bytes and from a path', function () {
    ini_set('memory_limit', '2G');
    mt_srand(11);
    $random = static function (int $n): string {
        return random_bytes($n);
    };
    $bytes = limitsArchive(['a.bin' => [$random(33 * 1048576), true], 'b.bin' => [$random(33 * 1048576), true]]);
    $path = tempnam(sys_get_temp_dir(), 'hs_limits_').'.xlsx';
    file_put_contents($path, $bytes);
    $raised = ['limits' => ['maxArchiveBytes' => 128 * 1048576]];

    try {
        expect(limitsRefusal(fn () => Agent::read($bytes)))->toBe(['reason' => 'archive_limit', 'limit' => 'maxArchiveBytes'])
            ->and(limitsRefusal(fn () => Agent::describe($path)))->toBe(['reason' => 'archive_limit', 'limit' => 'maxArchiveBytes'])
            ->and(limitsSheets(Agent::read($bytes, $raised)))->toBe(['Data', 'Summary'])
            ->and(limitsSheets(Agent::describe($path, $raised)))->toBe(['Data', 'Summary']);
    } finally {
        @unlink($path);
    }
});

it('applies every limit to describe() from a path, as read() does', function () {
    $path = __DIR__.'/../fixtures/read-options/two-sheets.xlsx';
    foreach (['maxArchiveBytes' => 1000, 'maxEntries' => 5, 'maxPartBytes' => 600, 'maxExpandedBytes' => 1000, 'maxCompressionRatio' => 2] as $limit => $value) {
        expect(limitsRefusal(fn () => Agent::describe($path, ['limits' => [$limit => $value]])))
            ->toBe(['reason' => 'archive_limit', 'limit' => $limit]);
    }
});

it('takes default limits on the instance, which a call can override', function () {
    $path = __DIR__.'/../fixtures/read-options/two-sheets.xlsx';
    $sheet = new HolySheet(['maxPartBytes' => 600]);

    expect(limitsRefusal(fn () => $sheet->describe($path)))->toBe(['reason' => 'archive_limit', 'limit' => 'maxPartBytes'])
        ->and(limitsRefusal(fn () => $sheet->read((string) file_get_contents($path))))->toBe(['reason' => 'archive_limit', 'limit' => 'maxPartBytes'])
        ->and(limitsSheets($sheet->describe($path, ['limits' => ['maxPartBytes' => 1000]])))->toBe(['Data', 'Summary']);
});

it('feeds the instance maxCells to rows()', function () {
    $sheet = new HolySheet(['maxCells' => 2]);
    $data = ['name' => 'S', 'cells' => ['A1' => ['value' => 1], 'B1' => ['value' => 2], 'C1' => ['value' => 3]]];

    expect(limitsRefusal(fn () => $sheet->rows($data)))->toBe(['reason' => 'read_limit', 'limit' => 'maxCells'])
        ->and($sheet->rows($data, ['maxCells' => 3]))->toBe([[1, 2, 3]]);
});

it('refuses a bad instance limit when it is made, not on the first read', function () {
    expect(fn () => new HolySheet(['maxRows' => 0]))->toThrow(ArchiveRefused::class, 'read_limits (maxRows must be a positive integer)')
        ->and(fn () => new HolySheet(['maxPartBytes' => '64M']))->toThrow(ArchiveRefused::class, 'archive_limits (maxPartBytes must be a positive integer)')
        ->and(fn () => new HolySheet(['maxPartByte' => 1]))->toThrow(ArchiveRefused::class, 'read_limits (unknown limit "maxPartByte")');
});
