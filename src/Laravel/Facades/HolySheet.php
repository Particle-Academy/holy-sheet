<?php

declare(strict_types=1);

namespace HolySheet\Laravel\Facades;

use HolySheet\HolySheet as HolySheetCore;
use Illuminate\Support\Facades\Facade;

/**
 * Holy Sheet facade — the full xlsx writing surface for Laravel apps.
 *
 *   use HolySheet\Laravel\Facades\HolySheet;
 *
 *   HolySheet::write($schema, '/tmp/q4.xlsx');           // write to disk
 *   $bytes = HolySheet::toBytes($schema);                // get raw xlsx bytes
 *   $errors = HolySheet::validate($schema);              // dry-run, returns error list
 *   $jsonSchema = HolySheet::toolDefinition();           // for agent tool-use wiring
 *   $version = HolySheet::getVersion();                  // package version
 *
 * Pipeline-friendly: every method is exposed, all return values are
 * arrays / strings (no special objects to import), and validation
 * surfaces structured errors via SchemaException for graceful recovery.
 *
 * @method static list<array{path:string,expected:string,got:string,value:mixed,hint:string}> validate(array $schema)
 * @method static array{path:string,bytes:int,sheets:int} write(array $schema, string $path)
 * @method static string toBytes(array $schema)
 * @method static array<string,mixed> toolDefinition()
 * @method static array<string,mixed> describe(string $path)
 * @method static array{schema:array<string,mixed>,errors:list<array<string,mixed>>,repairs:list<string>} validateAndRepair(array $schema)
 * @method static array<string,mixed> fromArray(array $rows, ?array $headers = null, string $sheetName = 'Sheet 1', array $options = [])
 * @method static array<string,mixed> fromCsv(string $csvOrPath, array $options = [])
 * @method static array<string,mixed> fromQuery(mixed $source, array|null $columns = null, array $options = [])
 * @method static list<array{sheet:string,address:string,formula:string,error:string,hint:string}> lint(array $schema)
 * @method static string dumpJson(array $schema, ?\HolySheet\Schema\DumpOptions $opts = null)
 * @method static string getVersion()
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
