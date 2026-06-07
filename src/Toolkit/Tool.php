<?php

declare(strict_types=1);

namespace HolySheet\Toolkit;

use Closure;

/**
 * A single framework-agnostic tool descriptor.
 *
 * Four fields — exactly what every agent framework needs to register a tool:
 *  - `name`        — the tool's invocation name
 *  - `description` — what it does (shown to the model)
 *  - `parameters`  — a JSON Schema for the tool's input
 *  - `handler`     — a callable `(array $arguments): mixed` that does the work
 *
 * Map it onto whatever your framework wants:
 *
 *   // Anthropic / OpenAI raw tool spec
 *   $tool->toArray();              // {name, description, parameters}
 *   $result = $tool->call($args);  // run the handler
 *
 *   // laravel/ai — wrap in a Tool class (see README recipe)
 *   // any other SDK — same three fields, same handler
 */
final class Tool
{
    /**
     * @param  array<string,mixed>  $parameters  JSON Schema for the input
     * @param  Closure(array<string,mixed>): mixed  $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        public readonly Closure $handler,
    ) {}

    /**
     * Invoke the handler.
     *
     * @param  array<string,mixed>  $arguments
     */
    public function call(array $arguments = []): mixed
    {
        return ($this->handler)($arguments);
    }

    /**
     * The provider-neutral spec (name + description + JSON-Schema parameters).
     * The shape every tool-use API accepts.
     *
     * @return array{name:string,description:string,parameters:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->parameters,
        ];
    }
}
