<?php

declare(strict_types=1);

namespace HolySheet\Tests\Laravel;

use HolySheet\Laravel\HolySheetServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base test case for the Laravel integration tests.
 *
 * Uses Orchestra Testbench so we can spin up a real Laravel app per
 * test against the service provider — without requiring a host
 * Laravel app to live alongside the package.
 */
abstract class TestCase extends BaseTestCase
{
    /** @return array<int, class-string<\Illuminate\Support\ServiceProvider>> */
    protected function getPackageProviders($app): array
    {
        return [
            HolySheetServiceProvider::class,
        ];
    }

    /** @return array<string, class-string> */
    protected function getPackageAliases($app): array
    {
        return [
            'HolySheet' => \HolySheet\Laravel\Facades\HolySheet::class,
        ];
    }
}
