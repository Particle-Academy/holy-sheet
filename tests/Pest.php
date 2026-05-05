<?php

declare(strict_types=1);

/**
 * Pest configuration for Holy Sheet tests.
 *
 * Tests under `tests/Unit/` run against pure PHP — no framework. Tests
 * under `tests/Laravel/` extend the Orchestra Testbench TestCase and
 * exercise the Laravel service provider integration.
 */

uses()->in(__DIR__.'/Unit');

uses(\HolySheet\Tests\Laravel\TestCase::class)->in(__DIR__.'/Laravel');
