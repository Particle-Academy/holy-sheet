<?php

declare(strict_types=1);

namespace HolySheet\Tests\Laravel;

use HolySheet\Laravel\HolySheetServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case for the Laravel integration tests.
 *
 * A real Laravel application per test, built from laravel/framework alone
 * (no orchestra/testbench): the framework's default configuration under a
 * scratch base path, the service provider and facade alias registered, and
 * in-memory SQLite forced so nothing can reach a real database. Provider API
 * keys are blanked for the same reason.
 */
abstract class TestCase extends BaseTestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'holy-sheet-tests';
        if (! is_dir($base.'/bootstrap/cache')) {
            mkdir($base.'/bootstrap/cache', 0777, true);
        }
        // An empty .env: the environment bootstrapper reads one, and nothing
        // from a developer's or CI's environment file belongs in this app.
        if (! is_file($base.'/.env')) {
            file_put_contents($base.'/.env', '');
        }

        $app = Application::configure(basePath: $base)
            ->withProviders([HolySheetServiceProvider::class], withBootstrapProviders: false)
            ->create();

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set([
                'app.env' => 'testing',
                'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
                'database.default' => 'sqlite',
                'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
                'cache.default' => 'array',
                'session.driver' => 'array',
                'queue.default' => 'sync',
                'mail.default' => 'array',
                'logging.default' => 'null',
                'services.anthropic.key' => '',
                'services.openai.key' => '',
            ]);
        });

        $app->make(ConsoleKernel::class)->bootstrap();
        AliasLoader::getInstance(['HolySheet' => \HolySheet\Laravel\Facades\HolySheet::class])->register();

        $this->app = $app;
    }

    protected function tearDown(): void
    {
        $this->app->flush();
        // Booting installed Laravel's error and exception handlers; hand them back.
        HandleExceptions::flushState($this);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }
}
