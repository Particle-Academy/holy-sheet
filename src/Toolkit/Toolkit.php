<?php

declare(strict_types=1);

namespace HolySheet\Toolkit;

use HolySheet\Agent;
use HolySheet\Schema\DumpOptions;

/**
 * The canonical Holy Sheet agent toolkit — framework-agnostic.
 *
 * Every team building a spreadsheet agent on Holy Sheet hand-writes the same
 * Build / Write / Read / Lint / Describe tools plus the canonical
 * validate → lint → repair loop. This is that layer, shipped: a set of
 * {@see Tool} descriptors (name + description + JSON-Schema parameters +
 * callable handler) you map onto any agent SDK in a few lines.
 *
 *   use HolySheet\Toolkit\Toolkit;
 *   use HolySheet\Toolkit\ArraySchemaStore;
 *
 *   $kit = Toolkit::for(new ArraySchemaStore());
 *   foreach ($kit->tools() as $tool) {
 *       $sdk->registerTool($tool->name, $tool->description, $tool->parameters, $tool->handler);
 *   }
 *   $system = Toolkit::instructions();   // the shipped agent prompt
 *
 * The host provides the {@see SchemaStore} (where the workbook lives), the
 * agent loop, and the UI. The toolkit provides the tools, the prompts, and
 * the lint-correction behavior. A laravel/ai mapping is a README recipe —
 * no coupling, no extra package.
 */
final class Toolkit
{
    public function __construct(private readonly SchemaStore $store) {}

    public static function for(SchemaStore $store): self
    {
        return new self($store);
    }

    /**
     * All five tools, in agent-loop order: read → build → lint → write,
     * plus describe for reading an existing .xlsx file back.
     *
     * @return list<Tool>
     */
    public function tools(): array
    {
        return [
            $this->readSchemaTool(),
            $this->buildSchemaTool(),
            $this->lintSchemaTool(),
            $this->writeTool(),
            $this->describeFileTool(),
        ];
    }

    /**
     * The same tools keyed by name, for selective registration.
     *
     * @return array<string,Tool>
     */
    public function byName(): array
    {
        $out = [];
        foreach ($this->tools() as $tool) {
            $out[$tool->name] = $tool;
        }

        return $out;
    }

    /** The canonical system prompt for a Holy Sheet spreadsheet agent. */
    public static function instructions(): string
    {
        return Prompts::agent();
    }

    /* ------------------------------------------------------------------ */
    /* Tools                                                               */
    /* ------------------------------------------------------------------ */

    private function readSchemaTool(): Tool
    {
        $store = $this->store;

        return new Tool(
            name: 'read_schema',
            description: 'Read the workbook\'s full cell-level content (every value and formula) as JSON. Use before editing existing cells or fixing formulas. Pass compact=true to strip formats and whitespace.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'compact' => ['type' => 'boolean', 'description' => 'Strip formats + whitespace for the smallest output.'],
                    'includeFormats' => ['type' => 'boolean', 'description' => 'Keep cell format objects (default true).'],
                    'prettyPrint' => ['type' => 'boolean', 'description' => 'Indent the JSON (default false).'],
                ],
            ],
            handler: function (array $args) use ($store): string {
                $opts = ($args['compact'] ?? false) === true
                    ? DumpOptions::compact()
                    : new DumpOptions(
                        prettyPrint: (bool) ($args['prettyPrint'] ?? false),
                        includeFormats: (bool) ($args['includeFormats'] ?? true),
                    );

                return Agent::dumpJson($store->getSchema(), $opts);
            },
        );
    }

    private function buildSchemaTool(): Tool
    {
        $store = $this->store;

        return new Tool(
            name: 'build_schema',
            description: 'Validate and conservatively repair a draft workbook schema without writing anything. Returns the repaired schema, any remaining structural errors, and a list of repairs applied. Draft here, then call write_xlsx.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'schema' => $this->schemaProperty(),
                ],
                'required' => ['schema'],
            ],
            handler: fn (array $args): array => Agent::validateAndRepair(
                is_array($args['schema'] ?? null) ? $args['schema'] : $store->getSchema(),
            ),
        );
    }

    private function lintSchemaTool(): Tool
    {
        $store = $this->store;

        return new Tool(
            name: 'lint_schema',
            description: 'Evaluate every formula and report cells that produce Excel errors (#VALUE!, #REF!, #DIV/0!, #NAME?, #CIRC!). Defaults to the current workbook; pass a schema to lint a draft. Empty issues = all formulas evaluate cleanly.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'schema' => $this->schemaProperty(),
                ],
            ],
            handler: function (array $args) use ($store): array {
                $schema = is_array($args['schema'] ?? null) ? $args['schema'] : $store->getSchema();
                $issues = Agent::lint($schema);

                return ['ok' => $issues === [], 'issues' => $issues];
            },
        );
    }

    private function writeTool(): Tool
    {
        $store = $this->store;

        return new Tool(
            name: 'write_xlsx',
            description: 'Validate → lint → persist a workbook schema. On structural errors or formula errors it does NOT persist — it returns them so you can fix and call write_xlsx again (the self-correction loop). On success the schema is saved and the xlsx byte size is returned. Omit schema to (re)write the current workbook.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'schema' => $this->schemaProperty(),
                ],
                'required' => ['schema'],
            ],
            handler: function (array $args) use ($store): array {
                $schema = is_array($args['schema'] ?? null) ? $args['schema'] : $store->getSchema();

                $errors = Agent::validate($schema);
                if ($errors !== []) {
                    return [
                        'ok' => false,
                        'stage' => 'validate',
                        'errors' => $errors,
                        'hint' => 'Fix the structural errors and call write_xlsx again.',
                    ];
                }

                $issues = Agent::lint($schema);
                if ($issues !== []) {
                    return [
                        'ok' => false,
                        'stage' => 'lint',
                        'issues' => $issues,
                        'hint' => 'One or more formulas evaluate to an Excel error. Fix them and call write_xlsx again.',
                    ];
                }

                $store->setSchema($schema);

                return [
                    'ok' => true,
                    'id' => $store->getId(),
                    'sheets' => is_array($schema['sheets'] ?? null) ? count($schema['sheets']) : 0,
                    'bytes' => strlen(Agent::toBytes($schema)),
                ];
            },
        );
    }

    private function describeFileTool(): Tool
    {
        return new Tool(
            name: 'describe_file',
            description: 'Round-trip an existing .xlsx file on disk back into a Holy Sheet schema (values, formulas, formats). Use to read a file the workbook did not author.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Absolute path to an .xlsx file.'],
                ],
                'required' => ['path'],
            ],
            handler: fn (array $args): array => Agent::describe((string) ($args['path'] ?? '')),
        );
    }

    /**
     * The JSON Schema for a Holy Sheet workbook, reused for the `schema`
     * input of build/lint/write. Sourced from the package's single schema
     * file; falls back to a permissive object if it's unavailable.
     *
     * @return array<string,mixed>
     */
    private function schemaProperty(): array
    {
        $definition = Agent::toolDefinition();

        return $definition !== [] ? $definition : ['type' => 'object'];
    }
}
