<?php

declare(strict_types=1);

namespace HolySheet\Laravel\Facades;

use HolySheet\HolySheet as HolySheetCore;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string version()
 *
 * @see \HolySheet\HolySheet
 */
final class HolySheet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HolySheetCore::class;
    }
}
