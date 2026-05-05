<?php

declare(strict_types=1);

namespace HolySheet\Laravel;

use HolySheet\HolySheet;
use HolySheet\Laravel\Commands\WriteCommand;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel integration for Holy Sheet.
 *
 * Auto-registered via composer.json `extra.laravel.providers` on Laravel
 * 10–13. Apps NOT using Laravel can ignore this file entirely; the
 * `HolySheet\HolySheet` class is fully usable on its own.
 *
 * The service provider is intentionally minimal during the scaffold
 * phase — it registers the singleton and stubs out the publishable
 * config slot. Real bindings (writers, default formats, agent helpers)
 * land alongside the writing API in subsequent commits.
 */
final class HolySheetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/holy-sheet.php', 'holy-sheet');

        $this->app->singleton(HolySheet::class, fn (Application $app) => new HolySheet());
        $this->app->alias(HolySheet::class, 'holy-sheet');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/holy-sheet.php' => $this->app->configPath('holy-sheet.php'),
            ], 'holy-sheet-config');

            $this->commands([
                WriteCommand::class,
            ]);
        }
    }
}
