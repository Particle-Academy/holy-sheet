<?php

declare(strict_types=1);

namespace HolySheet\Toolkit;

/**
 * In-memory {@see SchemaStore}. The zero-ceremony store for plain-PHP hosts,
 * tests, and one-shot agent sessions that don't need a database.
 */
final class ArraySchemaStore implements SchemaStore
{
    /**
     * @param  array<string,mixed>  $schema
     */
    public function __construct(
        private array $schema = ['sheets' => []],
        private readonly string $id = 'workbook',
    ) {}

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function setSchema(array $schema): void
    {
        $this->schema = $schema;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
