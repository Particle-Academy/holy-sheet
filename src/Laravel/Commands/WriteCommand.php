<?php

declare(strict_types=1);

namespace HolySheet\Laravel\Commands;

use HolySheet\Agent;
use HolySheet\Exceptions\SchemaException;
use Illuminate\Console\Command;

/**
 * `php artisan holy-sheet:write --out=path [--in=path]`
 *
 * Reads a Holy Sheet schema as JSON (from stdin by default, or --in=path)
 * and writes the resulting xlsx file to --out. The lowest-friction entry
 * point for agents that have shell access.
 *
 *   echo '{...}' | php artisan holy-sheet:write --out=q4.xlsx
 *   php artisan holy-sheet:write --in=schema.json --out=q4.xlsx
 *
 * Outputs a JSON status line on success: {"path":"...","bytes":N,"sheets":N}.
 * On schema validation failure, prints the structured error list to
 * stderr and exits 1 — agents can re-read and recover.
 */
final class WriteCommand extends Command
{
    protected $signature = 'holy-sheet:write
                            {--out= : Output xlsx path (required)}
                            {--in= : Input JSON path (defaults to stdin)}
                            {--validate : Dry-run — validate only, don\'t write}';

    protected $description = 'Write an xlsx workbook from a Holy Sheet JSON schema';

    public function handle(): int
    {
        $out = $this->option('out');
        $in = $this->option('in');
        $validateOnly = (bool) $this->option('validate');

        if (!$validateOnly && !$out) {
            $this->error('Either --validate or --out=<path> is required.');
            return self::FAILURE;
        }

        $json = $in
            ? @file_get_contents($in)
            : @file_get_contents('php://stdin');

        if ($json === false || $json === '') {
            $this->error('No schema input. Pipe JSON to stdin or pass --in=<path>.');
            return self::FAILURE;
        }

        $schema = json_decode($json, true);
        if (!is_array($schema)) {
            $this->error('Input is not valid JSON.');
            return self::FAILURE;
        }

        if ($validateOnly) {
            $errors = Agent::validate($schema);
            $this->line(json_encode(['errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }

        try {
            $result = Agent::write($schema, (string) $out);
        } catch (SchemaException $e) {
            $this->line(json_encode([
                'error' => 'validation',
                'message' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
