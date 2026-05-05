<?php

declare(strict_types=1);

use HolySheet\HolySheet;

it('registers HolySheet as a singleton in the Laravel container', function () {
    $a = app(HolySheet::class);
    $b = app(HolySheet::class);
    expect($a)->toBe($b);
});

it('binds the holy-sheet alias', function () {
    expect(app('holy-sheet'))->toBeInstanceOf(HolySheet::class);
});

it('merges the package config under the holy-sheet key', function () {
    expect(config('holy-sheet.default_writer'))->toBe('xlsx');
});
