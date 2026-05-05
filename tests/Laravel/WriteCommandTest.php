<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('writes an xlsx via the holy-sheet:write artisan command', function () {
    $schema = ['sheets' => [['name' => 'X', 'columns' => [['header' => 'A']], 'rows' => [[1]]]]];

    $in = tempnam(sys_get_temp_dir(), 'hs-in-').'.json';
    $out = tempnam(sys_get_temp_dir(), 'hs-out-').'.xlsx';
    file_put_contents($in, json_encode($schema));

    $exit = Artisan::call('holy-sheet:write', ['--in' => $in, '--out' => $out]);

    expect($exit)->toBe(0);
    expect(file_exists($out))->toBeTrue();
    expect(filesize($out))->toBeGreaterThan(100);

    @unlink($in);
    @unlink($out);
});

it('returns a structured error on invalid schema', function () {
    $in = tempnam(sys_get_temp_dir(), 'hs-in-').'.json';
    file_put_contents($in, '{}');

    $exit = Artisan::call('holy-sheet:write', ['--in' => $in, '--validate' => true]);

    expect($exit)->toBe(1);

    @unlink($in);
});
