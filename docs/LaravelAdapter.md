# Laravel adapter

The optional Laravel layer at `HolySheet\Laravel\*`. Everything here is loaded on-demand — non-Laravel projects ignore the directory entirely.

| | What you get |
|---|---|
| Service provider | `HolySheet\Laravel\HolySheetServiceProvider` — auto-registered via `extra.laravel.providers` |
| Facade | `HolySheet\Laravel\Facades\HolySheet` — full Agent surface, singleton-resolved |
| Artisan command | `php artisan holy-sheet:write --in=schema.json --out=q4.xlsx` |
| Publishable config | `config/holy-sheet.php` (default writer, output path, locale) |

The adapter requires `illuminate/support` 10.x–13.x. Apps not running Laravel skip everything in this doc.

## Auto-discovery

Laravel reads `extra.laravel.providers` from the package's `composer.json` and registers `HolySheetServiceProvider` automatically. No app-level wiring required.

```bash
composer require particle-academy/holy-sheet
# Done. Facade + command + config are live.
```

## Facade methods

```php
use HolySheet\Laravel\Facades\HolySheet;
```

| Method | Returns | Purpose |
|--------|---------|---------|
| `HolySheet::validate(array $schema)` | `array` of errors (empty = valid) | Dry-run before writing. |
| `HolySheet::write(array $schema, string $path)` | `['path', 'bytes', 'sheets']` | Write to disk. Throws `SchemaException` on validation failure. |
| `HolySheet::toBytes(array $schema)` | `string` | Raw xlsx bytes for streaming/queue/S3. |
| `HolySheet::toolDefinition()` | `array` | JSON Schema for agent tool wiring. |
| `HolySheet::describe(string $path)` | `array` | (1.1+) Round-trip an xlsx back to schema. |
| `HolySheet::getVersion()` | `string` | Package version. |

The facade resolves to a singleton bound by the service provider — safe across queue workers, scheduler, listeners, controllers.

## Service provider — what it does

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__.'/../../config/holy-sheet.php', 'holy-sheet');
    $this->app->singleton(HolySheet::class, fn () => new HolySheet());
    $this->app->alias(HolySheet::class, 'holy-sheet');
}
```

Apps can override the singleton — bind a subclass / decorator at the same key:

```php
$this->app->extend(\HolySheet\HolySheet::class, function ($holySheet, $app) {
    return new \App\Services\AuditedHolySheet($holySheet, $app->make(\App\Audit::class));
});
```

The facade still works; pipelines see whichever object you bound.

## Artisan command

```bash
# stdin (default)
echo '{"sheets":[{"name":"X","columns":[{"header":"A"}],"rows":[[1]]}]}' \
  | php artisan holy-sheet:write --out=q4.xlsx

# file input
php artisan holy-sheet:write --in=schema.json --out=q4.xlsx

# dry-run validation (returns JSON error list, exits non-zero on failure)
php artisan holy-sheet:write --in=schema.json --validate
```

Successful writes emit a JSON status line on stdout: `{"path":"...","bytes":N,"sheets":N}`.

Validation failures emit a structured error envelope and exit code 1:

```json
{
  "error": "validation",
  "message": "[holy-sheet] schema invalid at sheets[0].rows[2][1]: ...",
  "errors": [
    { "path": "sheets[0].rows[2][1]", "expected": "number", "got": "string", "value": "n/a", "hint": "..." }
  ]
}
```

Agents pipe stdout to JSON parsers and read `errors` to recover.

## Config

```bash
php artisan vendor:publish --tag=holy-sheet-config
```

Writes `config/holy-sheet.php`. Three keys today:

| Key | Default | Description |
|-----|---------|-------------|
| `default_writer` | `'xlsx'` | Reserved for multi-format support in 1.2+ (currently xlsx-only). |
| `output_path` | `null` | Default disk-relative path for app-level helpers; `null` means callers supply paths. |
| `locale` | `'en_US'` | Default locale for currency/date formatting when not specified per column. |

## HTTP controller pattern

Holy Sheet does **not** ship a controller. The recommended shape for an Export endpoint, in your app:

```php
namespace App\Http\Controllers;

use HolySheet\Exceptions\SchemaException;
use HolySheet\Laravel\Facades\HolySheet;
use Illuminate\Http\Request;

final class XlsxExportController
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'schema'   => 'required|array',
            'filename' => 'nullable|string|max:120',
        ]);

        try {
            $bytes = HolySheet::toBytes($request->input('schema'));
        } catch (SchemaException $e) {
            return response()->json(['errors' => $e->getErrors()], 422);
        }

        $name = $request->input('filename', 'export.xlsx');
        if (!str_ends_with(strtolower($name), '.xlsx')) $name .= '.xlsx';

        return response($bytes, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.addslashes($name).'"',
            'Cache-Control'       => 'no-store',
        ]);
    }
}
```

Why no built-in controller:

- Your auth / rate limit / validation rules are app-specific.
- Your error response shape (JSON / view / redirect) is app-specific.
- Your filename policy (sanitization, signed paths) is app-specific.
- A package controller would either be too opinionated or too generic to be useful.

## Queue jobs

The facade is queue-safe — the singleton has no per-write state.

```php
use App\Jobs\GenerateXlsxJob;
GenerateXlsxJob::dispatch($schema, $userId, 'q4.xlsx');
```

Inside the job:

```php
public function handle(): void
{
    $bytes = HolySheet::toBytes($this->schema);
    Storage::disk('s3')->put("exports/{$this->userId}/{$this->filename}", $bytes);
    Notification::route('mail', $this->email)->notify(new XlsxReady($this->filename));
}
```

## Testing the adapter

`Orchestra\Testbench` works out of the box. Holy Sheet's own test suite uses it for the facade + command tests — copy the patterns from `tests/Laravel/`:

```php
use HolySheet\Laravel\Facades\HolySheet;

it('writes xlsx via the facade', function () {
    $bytes = HolySheet::toBytes($schema);
    expect(substr($bytes, 0, 4))->toBe("PK\x03\x04");
});
```

## See also

- [Schema reference](./Schema.md)
- [Recipes](./Recipes.md) — patterns 8-10 are Laravel-specific
- [SKILL](../skills/holy-sheet.md) — agent prompt
