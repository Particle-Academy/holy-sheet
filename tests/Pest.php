<?php

declare(strict_types=1);

/**
 * Pest configuration for Holy Sheet tests.
 *
 * Tests under `tests/Unit/` run against pure PHP — no framework. Tests
 * under `tests/Laravel/` use tests/Laravel/TestCase.php, which boots a
 * minimal Laravel application from laravel/framework alone (no
 * orchestra/testbench), and exercise the service provider integration.
 */

uses()->in(__DIR__.'/Unit');

uses(\HolySheet\Tests\Laravel\TestCase::class)->in(__DIR__.'/Laravel');
