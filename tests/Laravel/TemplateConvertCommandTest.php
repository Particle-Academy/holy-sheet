<?php

declare(strict_types=1);

use HolySheet\Agent;
use Illuminate\Support\Facades\Artisan;

it('converts a template through the holy-sheet:template-convert artisan command', function () {
    $schema = ['sheets' => [['name' => 'X', 'columns' => [['header' => 'A']], 'rows' => [[1]]]]];
    $dir = sys_get_temp_dir().'/hst-artisan-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/in.xlsx", Agent::toBytes($schema));

    expect(Artisan::call('holy-sheet:template-convert', ['input' => "{$dir}/in.xlsx", 'output' => "{$dir}/out.xltx"]))->toBe(0)
        ->and(Agent::describe("{$dir}/out.xltx")['sourceKind'] ?? null)->toBe('template')
        ->and(Artisan::output())->toContain('template');

    // Asking for what it already is: refused, nothing written.
    expect(Artisan::call('holy-sheet:template-convert', ['input' => "{$dir}/out.xltx", 'output' => "{$dir}/again.xltx", '--to' => 'template']))->toBe(1)
        ->and(Artisan::output())->toContain('already_template')
        ->and(file_exists("{$dir}/again.xltx"))->toBeFalse();

    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);
});
