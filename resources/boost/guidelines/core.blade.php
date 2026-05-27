## Holy Sheet

`particle-academy/holy-sheet` — a framework-agnostic PHP 8.2+ library for writing real `.xlsx` (Office Open XML) spreadsheets and reading them back. Designed as a structured-tool surface for LLM agents: declarative schema in, file out, structured errors when something's wrong. Ships with an optional Laravel 10-13 service provider.

### Features

- **Framework-agnostic core**: Pure PHP, no dependencies on Laravel. Drop into any PHP project.
- **Agent-shaped API**: `HolySheet\Agent` is a single static class with `validate`, `write`, `toBytes`, `validateAndRepair`, `describe`, `fromArray`, `fromCsv`, `lint`, and `toolDefinition`. No DI container required.
- **JSON Schema export**: `Agent::toolDefinition()` returns a JSON Schema describing the expected `$schema` shape — feed it directly into LLM tool registration.
- **Validate-then-write semantics**: Every error is a structured array with `path`, `expected`, `got`, `value`, `hint` — easy for agents to feed back into their own next emission.
- **CSV import + round-trip**: `Agent::fromCsv($csvOrPath)` lifts a CSV string or path into a Holy Sheet schema; `Agent::describe($path)` reads an existing xlsx back into the same shape.
- **Real xlsx writer**: Writes proper Office Open XML — opens cleanly in Excel / Numbers / Google Sheets / LibreOffice Calc. No external office binaries; the writer is pure PHP.
- **Optional Laravel adapter**: Auto-discovered `HolySheetServiceProvider` registers a `holy-sheet` container alias and config publishing — but the core works fine without Laravel.

### Public surface

The package is intentionally thin. Everything an agent (or your app) needs lives on one of these:

- `HolySheet\Agent` — static facade for the validate / write / read / repair / describe loop
- `HolySheet\HolySheet` — instance-style entry point (same operations as Agent, useful when you want DI)
- `HolySheet\Schema\Validator` — pure validator; returns structured errors
- `HolySheet\Schema\Normalizer` — canonicalizes a schema (fills defaults, coerces shorthand)
- `HolySheet\Schema\Repairer` — heuristic repairs (used by `Agent::validateAndRepair`)
- `HolySheet\Writer\XlsxWriter` — low-level xlsx writer (Agent::write goes through this)
- `HolySheet\Reader\XlsxReader` — low-level xlsx reader (Agent::describe goes through this)

### Quick start (Agent surface)

<code-snippet name="Holy Sheet — write a workbook" lang="php">
use HolySheet\Agent;

$schema = [
    'sheets' => [[
        'name' => 'Q1',
        'columns' => [
            ['header' => 'Region',  'type' => 'string'],
            ['header' => 'Revenue', 'type' => 'currency', 'currency' => 'USD'],
        ],
        'rows' => [
            ['NA',   12000],
            ['EU',   18500],
            ['APAC',  9400],
        ],
    ]],
];

$result = Agent::write($schema, '/path/to/q1-report.xlsx');
// => ['path' => '/path/to/q1-report.xlsx', 'bytes' => 6291, 'sheets' => 1]
</code-snippet>

### Validate before writing (or after an agent emission)

<code-snippet name="Holy Sheet — validate" lang="php">
use HolySheet\Agent;

$errors = Agent::validate($schema);

if ($errors !== []) {
    // Each error: ['path' => '...', 'expected' => '...', 'got' => '...', 'value' => ..., 'hint' => '...']
    // Feed back to the agent verbatim — the hint is written for it.
    return ['needs_revision' => true, 'errors' => $errors];
}
</code-snippet>

### Repair-or-fail (the typical agentic loop)

<code-snippet name="Holy Sheet — validateAndRepair" lang="php">
use HolySheet\Agent;

$result = Agent::validateAndRepair($schema);

// $result = [
//   'ok'      => bool,
//   'schema'  => array, // repaired version (use this for write())
//   'errors'  => list<error>, // what was repaired (or what's still broken if !ok)
// ]

if (! $result['ok']) {
    return ['needs_revision' => true, 'errors' => $result['errors']];
}

Agent::write($result['schema'], $path);
</code-snippet>

### Round-trip a file back to schema

<code-snippet name="Holy Sheet — describe" lang="php">
use HolySheet\Agent;

$schema = Agent::describe('/path/to/q1-report.xlsx');
// Same shape Agent::write() accepts — round-trip safe for files this package wrote.
</code-snippet>

### CSV import

<code-snippet name="Holy Sheet — fromCsv" lang="php">
use HolySheet\Agent;

$schema = Agent::fromCsv("Region,Revenue\nNA,12000\nEU,18500\nAPAC,9400");
// or pass a file path:
$schema = Agent::fromCsv('/path/to/data.csv');

Agent::write($schema, '/path/to/data.xlsx');
</code-snippet>

### Register with an LLM as a tool

<code-snippet name="Holy Sheet — tool registration" lang="php">
use HolySheet\Agent;

// JSON Schema describing the expected $schema parameter shape.
$definition = Agent::toolDefinition();

// Hand to your LLM SDK as the input schema for a tool named, say, 'write_spreadsheet':
$tools = [[
    'name'        => 'write_spreadsheet',
    'description' => 'Write a spreadsheet to disk. Returns path + size + sheet count.',
    'input_schema' => $definition,
]];
</code-snippet>

### Laravel integration (optional)

The service provider auto-discovers when Holy Sheet is installed in a Laravel app. Bind the `HolySheet` instance into the container under `holy-sheet`; config-publish-able under `holy-sheet`.

<code-snippet name="Holy Sheet — Laravel usage" lang="php">
// In a controller or job:
$result = \HolySheet\Agent::write($schema, storage_path('app/q1-report.xlsx'));

return response()->download($result['path']);
</code-snippet>

### Conventions

- **Schemas are plain arrays** — agent-friendly, easy to log, easy to diff.
- **Errors are structured arrays**, not exceptions, unless something truly catastrophic happens (file IO, invalid pre-condition the agent couldn't see). Agents feed structured errors back into their next emission much better than they parse exception traces.
- **Static methods on `Agent`** for the agent-facing surface. Instance methods on the internal services if you need DI.
- **Round-trip-safe**: anything `Agent::write()` produces, `Agent::describe()` will read back into the same schema shape. Hand-authored xlsx files may drop styling the schema can't represent.
- **No external binaries**: writer + reader are pure PHP. No LibreOffice headless, no spout, no phpoffice dependency.
