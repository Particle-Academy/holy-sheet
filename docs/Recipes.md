# Recipes

End-to-end patterns. Each one is a complete, runnable shape — copy, change the data, ship.

- [1. Sales report with totals](#1-sales-report-with-totals)
- [2. Multi-sheet workbook](#2-multi-sheet-workbook)
- [3. Cross-sheet formula reference](#3-cross-sheet-formula-reference)
- [4. Date column with mixed inputs](#4-date-column-with-mixed-inputs)
- [5. Highlight specific cells](#5-highlight-specific-cells)
- [6. Frozen header + column widths](#6-frozen-header--column-widths)
- [7. Title row merged across columns](#7-title-row-merged-across-columns)
- [8. Export from a Laravel query builder](#8-export-from-a-laravel-query-builder)
- [9. Stream as an HTTP download (your controller)](#9-stream-as-an-http-download-your-controller)
- [10. Async export via a queue job](#10-async-export-via-a-queue-job)

---

## 1. Sales report with totals

```php
use HolySheet\Agent;

Agent::write([
    'sheets' => [[
        'name' => 'Q4 Sales',
        'columns' => [
            ['header' => 'Region', 'type' => 'string'],
            ['header' => 'Revenue', 'type' => 'currency', 'currency' => 'USD'],
            ['header' => 'YoY', 'type' => 'percent', 'decimals' => 1],
        ],
        'rows' => [
            ['North America', 4_820_000, 0.124],
            ['Europe',        3_210_000, 0.081],
            ['APAC',          2_895_000, 0.227],
        ],
        'totals' => ['Revenue' => 'sum', 'YoY' => 'avg'],
        'theme' => 'default',
    ]],
], '/tmp/q4.xlsx');
```

## 2. Multi-sheet workbook

```php
Agent::write([
    'sheets' => [
        [
            'name' => 'Summary',
            'columns' => [['header' => 'Metric'], ['header' => 'Value', 'type' => 'number']],
            'rows' => [['Revenue', 10_925_000], ['Customers', 12_840]],
        ],
        [
            'name' => 'By Region',
            'columns' => [['header' => 'Region'], ['header' => 'Revenue', 'type' => 'currency']],
            'rows' => [['North America', 4_820_000], ['Europe', 3_210_000]],
        ],
    ],
], '/tmp/multi.xlsx');
```

## 3. Cross-sheet formula reference

```php
Agent::write([
    'sheets' => [
        [
            'name' => 'Detail',
            'columns' => [['header' => 'Amount', 'type' => 'number']],
            'rows' => [[100], [200], [300]],
        ],
        [
            'name' => 'Summary',
            'cells' => [
                'A1' => ['value' => 'Total'],
                'B1' => ['formula' => "SUM(Detail!A2:A4)"],
            ],
        ],
    ],
], '/tmp/cross.xlsx');
```

Sheet names with spaces use single quotes inside the formula: `'Detail Sheet'!A2:A4`.

## 4. Date column with mixed inputs

```php
Agent::write([
    'sheets' => [[
        'name' => 'Events',
        'columns' => [
            ['header' => 'When', 'type' => 'date'],
            ['header' => 'Title', 'type' => 'string'],
            ['header' => 'Stamp', 'type' => 'datetime'],
        ],
        'rows' => [
            ['2026-05-01', 'Launch', new DateTimeImmutable('2026-05-01 09:00:00')],
            ['2026-06-15', 'Review', new DateTimeImmutable('2026-06-15 14:30:00')],
        ],
    ]],
], '/tmp/events.xlsx');
```

ISO strings AND `DateTimeInterface` instances both work. Don't pre-convert — Holy Sheet handles the Excel-serial math.

## 5. Highlight specific cells

```php
Agent::write([
    'sheets' => [[
        'name' => 'Status',
        'columns' => [['header' => 'Service'], ['header' => 'State']],
        'rows' => [
            ['API', 'Healthy'],
            ['DB',  ['value' => 'WARNING', 'format' => [
                'bold' => true,
                'backgroundColor' => '#fee2e2',
                'color' => '#991b1b',
            ]]],
            ['Cache', 'Healthy'],
        ],
    ]],
], '/tmp/status.xlsx');
```

Replace any primitive value with `{ value, format }` to override per-cell styling.

## 6. Frozen header + column widths

```php
Agent::write([
    'sheets' => [[
        'name' => 'Inventory',
        'frozenRows' => 1,
        'columnWidths' => [0 => 220, 1 => 100, 2 => 100],
        'theme' => 'default',
        'columns' => [
            ['header' => 'SKU'],
            ['header' => 'Stock', 'type' => 'integer'],
            ['header' => 'Reorder', 'type' => 'boolean'],
        ],
        'rows' => array_map(
            fn ($i) => ["A-".str_pad((string) $i, 4, '0', STR_PAD_LEFT), random_int(0, 200), random_int(0, 1) === 1],
            range(1, 50),
        ),
    ]],
], '/tmp/inventory.xlsx');
```

## 7. Title row merged across columns

```php
Agent::write([
    'sheets' => [[
        'name' => 'Report',
        'cells' => [
            'A1' => ['value' => 'Q4 2026 Performance Report', 'format' => [
                'bold' => true, 'fontSize' => 16, 'textAlign' => 'center',
                'backgroundColor' => '#1F2937', 'color' => '#FFFFFF',
            ]],
            'A3' => ['value' => 'Region',  'format' => ['bold' => true]],
            'B3' => ['value' => 'Revenue', 'format' => ['bold' => true]],
            'A4' => ['value' => 'North America'], 'B4' => ['value' => 4_820_000],
            'A5' => ['value' => 'Europe'],        'B5' => ['value' => 3_210_000],
        ],
        'mergedRegions' => [
            ['start' => 'A1', 'end' => 'B1'],
        ],
    ]],
], '/tmp/report.xlsx');
```

## 8. Export from a Laravel query builder

```php
use HolySheet\Laravel\Facades\HolySheet;

$rows = User::query()
    ->where('subscribed', true)
    ->orderBy('created_at')
    ->get(['name', 'email', 'plan', 'mrr', 'created_at'])
    ->map(fn ($u) => [$u->name, $u->email, $u->plan, $u->mrr, $u->created_at->toISOString()])
    ->all();

HolySheet::write([
    'sheets' => [[
        'name' => 'Subscribers',
        'frozenRows' => 1,
        'theme' => 'default',
        'columns' => [
            ['header' => 'Name'],
            ['header' => 'Email'],
            ['header' => 'Plan'],
            ['header' => 'MRR', 'type' => 'currency', 'currency' => 'USD'],
            ['header' => 'Joined', 'type' => 'date'],
        ],
        'rows' => $rows,
        'totals' => ['MRR' => 'sum'],
    ]],
], storage_path('app/exports/subscribers.xlsx'));
```

## 9. Stream as an HTTP download (your controller)

Holy Sheet doesn't ship a controller. Add one to your app:

```php
namespace App\Http\Controllers;

use HolySheet\Exceptions\SchemaException;
use HolySheet\Laravel\Facades\HolySheet;
use Illuminate\Http\Request;

class XlsxExportController
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'schema' => 'required|array',
            'filename' => 'nullable|string|max:120',
        ]);

        try {
            $bytes = HolySheet::toBytes($request->input('schema'));
        } catch (SchemaException $e) {
            return response()->json(['errors' => $e->getErrors()], 422);
        }

        $name = $request->input('filename', 'export.xlsx');
        if (!str_ends_with($name, '.xlsx')) $name .= '.xlsx';

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.addslashes($name).'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
```

```php
// routes/web.php
Route::post('/exports/xlsx', \App\Http\Controllers\XlsxExportController::class)
    ->middleware(['auth', 'throttle:exports'])
    ->name('exports.xlsx');
```

Your app owns auth, rate-limiting, validation, and response shape. Holy Sheet just writes the bytes.

## 10. Async export via a queue job

Big exports? Push to a queue, write to S3, email a signed link.

```php
namespace App\Jobs;

use HolySheet\Laravel\Facades\HolySheet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateXlsxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly array $schema,
        public readonly string $userId,
        public readonly string $filename,
    ) {}

    public function handle(): void
    {
        $bytes = HolySheet::toBytes($this->schema);
        $path = "exports/{$this->userId}/".$this->filename;
        Storage::disk('s3')->put($path, $bytes);

        // notify user with signed URL
        \App\Notifications\XlsxReady::dispatch($this->userId, $path);
    }
}
```

```php
GenerateXlsxJob::dispatch($schema, $user->id, 'subscribers.xlsx');
```

The facade is queue-safe — the singleton is cheap, no per-write state, deterministic output.

## 11. Round-trip an existing xlsx

Read a file you (or an end user) wrote, mutate the schema, write it back. Now an agent can iterate on its own output instead of regenerating from scratch.

```php
use HolySheet\Agent;

$schema = Agent::describe('/tmp/sales.xlsx');

// Update one cell + recalc the totals row
$schema['sheets'][0]['cells']['B2']['value'] = 5_500_000;
$schema['sheets'][0]['cells']['B5']['formula'] = 'SUM(B2:B4)';

Agent::write($schema, '/tmp/sales.xlsx');
```

Lossy fields (themes, foreign custom number formats) are documented in [docs/ReadPath.md](./ReadPath.md). Holy-Sheet-authored files round-trip without loss for every documented feature.

## 12. Build a workbook from arbitrary rows or a CSV

No more hand-crafting schema arrays. The helpers infer types from headers and sample values:

```php
use HolySheet\Agent;

// From rows + explicit headers
$schema = Agent::fromArray(
    rows:    [['NA', 4_820_000, 0.124], ['EU', 3_210_000, 0.081]],
    headers: ['Region', 'Revenue', 'YoY'],
);
// Inferred: Region → string, Revenue → currency USD, YoY → percent

Agent::write($schema, '/tmp/regions.xlsx');
```

Or first row as headers when omitted:

```php
$schema = Agent::fromArray([
    ['Name', 'Email', 'Plan'],
    ['Alice', 'alice@example.com', 'Pro'],
    ['Bob',   'bob@example.com',   'Free'],
]);
```

Or directly from CSV (string OR file path):

```php
$schema = Agent::fromCsv('/tmp/customers.csv');
$schema = Agent::fromCsv("name,age\nAlice,30\nBob,42");
Agent::write($schema, '/tmp/customers.xlsx');
```

Type-inference rules are listed in [docs/Schema.md](./Schema.md#schema-builders). Override with `$options['currency'] => 'EUR'` when you need a different default.

## 13. Export from an Eloquent / Query Builder via `fromQuery`

Laravel facade only — the core package stays framework-agnostic.

```php
use HolySheet\Laravel\Facades\HolySheet;

$schema = HolySheet::fromQuery(
    User::query()->where('subscribed', true)->orderBy('created_at'),
    ['name', 'email', 'plan', 'mrr', 'created_at'],
);
HolySheet::write($schema, storage_path('app/exports/users.xlsx'));
```

Eloquent `$casts` win for type inference: `decimal:2` becomes a `number` column with two decimals, `datetime` becomes `datetime`, `boolean` becomes `boolean`, `array`/`json` becomes a JSON-encoded string.

For custom header labels, pass an associative columns map:

```php
HolySheet::fromQuery($query, ['name' => 'User Name', 'mrr' => 'Monthly Revenue']);
```

Memory guard: defaults to a 5000-row cap; raise via `['limit' => 50_000]` or scope the query. Streaming over very large tables is on the 1.2 roadmap.

## 14. The full agentic recovery loop

When an agent generates a schema that *almost* validates, `validateAndRepair` cuts the recovery loop from 3 turns to 1:

```php
$schema = $agentOutput; // potentially imperfect

$result = Agent::validateAndRepair($schema);

if ($result['errors'] === []) {
    Agent::write($result['schema'], '/tmp/out.xlsx');
} else {
    // re-prompt the agent with the (smaller) remaining error list
    $remaining = $result['errors'];
    $repaired = $result['schema']; // the parts that *were* fixed are already applied
}
```

The `repairs` list names every applied fix as a human-readable string — log these so the agent can learn from auto-corrections rather than coming to depend on them.

## 15. Read a workbook's full content back (`dumpJson`)

`describe()` returns an existing file's *shape*; `dumpJson()` returns the full
cell-level *content* (every value + formula) so an agent can make targeted edits or
fix existing formulas. It's the read half of the loop:

```php
use HolySheet\Agent;
use HolySheet\Schema\DumpOptions;

$json = Agent::dumpJson($schema);                    // defaults: formats kept, empties dropped, 64K ceiling
$json = Agent::dumpJson($schema, DumpOptions::compact());  // minified, formats stripped — smallest token cost
$json = Agent::dumpJson($schema, new DumpOptions(maxBytes: 0)); // unbounded
```

Over the `maxBytes` ceiling, `dumpJson` returns a compact shape index
(`{ "_truncated": true, "sheets": [{ "name", "rows", "cells" }] }`) instead of an
unbounded blob — so a single read never blows the token budget; the agent narrows
its read instead.

## 16. Drop-in agent toolkit (any framework)

Skip hand-writing Build / Write / Read / Lint / Describe tools — `HolySheet\Toolkit`
ships them as framework-agnostic descriptors plus the canonical agent prompt:

```php
use HolySheet\Toolkit\Toolkit;
use HolySheet\Toolkit\ArraySchemaStore;

$kit = Toolkit::for(new ArraySchemaStore($existingSchema)); // or your own SchemaStore
$system = Toolkit::instructions();

foreach ($kit->tools() as $tool) {
    $sdk->registerTool($tool->name, $tool->description, $tool->parameters, $tool->handler);
}
```

Implement `SchemaStore` (`getSchema`/`setSchema`/`getId`) to back the toolkit with
your own model — an Eloquent workbook, a Redis blob, a file, anything. `write_xlsx`
runs the validate → lint → persist loop and only saves when clean; on error it
returns the issues for the agent to fix and retry. The README has a `laravel/ai`
mapping recipe.
