<?php

declare(strict_types=1);

use HolySheet\Exceptions\ArchiveRefused;
use HolySheet\HolySheet;
use HolySheet\Laravel\Facades\HolySheet as HolySheetFacade;
use HolySheet\Reader\ReadLimits;
use Illuminate\Support\Facades\Facade;

/** Re-resolve the singleton so it sees the config set by the test. */
function rebindHolySheet(): void
{
    app()->forgetInstance(HolySheet::class);
    Facade::clearResolvedInstance(HolySheet::class);
    Facade::clearResolvedInstance('holy-sheet');
}

it('ships every read limit in the package config at its default, with no env()', function () {
    $config = require __DIR__.'/../../config/holy-sheet.php';
    $source = (string) file_get_contents(__DIR__.'/../../config/holy-sheet.php');

    expect($config['limits'])->toBe(ReadLimits::ARCHIVE_DEFAULTS + ReadLimits::DEFAULTS)
        ->and($config['query_limit'])->toBe(5000)
        ->and($source)->not->toContain('env(');
});

it('applies config limits to the facade', function () {
    config(['holy-sheet.limits.maxPartBytes' => 600]);
    rebindHolySheet();
    $path = __DIR__.'/../fixtures/read-options/two-sheets.xlsx';

    try {
        HolySheetFacade::describe($path);
        $this->fail('expected a refusal');
    } catch (ArchiveRefused $e) {
        expect($e->limit)->toBe('maxPartBytes');
    }
    expect(array_column(HolySheetFacade::describe($path, ['limits' => ['maxPartBytes' => 1000]])['sheets'], 'name'))
        ->toBe(['Data', 'Summary']);
});

it('refuses a bad config limit clearly', function () {
    config(['holy-sheet.limits.maxRows' => '1000000']);
    rebindHolySheet();

    expect(fn () => app(HolySheet::class))->toThrow(ArchiveRefused::class, 'read_limits (maxRows must be a positive integer)');
});

it('applies the config query limit to fromQuery()', function () {
    config(['holy-sheet.query_limit' => 2]);
    rebindHolySheet();

    $schema = HolySheetFacade::fromQuery([['a' => 1], ['a' => 2], ['a' => 3]]);
    expect($schema['sheets'][0]['rows'])->toHaveCount(2);
});
