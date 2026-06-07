<?php

declare(strict_types=1);

namespace HolySheet\Toolkit;

/**
 * The host's persistence seam for a single workbook's schema.
 *
 * The {@see Toolkit} reads and writes a workbook through this interface so
 * it stays model-agnostic — there is no Eloquent, no ORM, no framework
 * assumption. A Laravel host adapts its model in three lines; a plain-PHP
 * host uses {@see ArraySchemaStore}; a test passes closures.
 */
interface SchemaStore
{
    /**
     * The current workbook schema (Holy Sheet's `{sheets: [...]}` shape).
     *
     * @return array<string,mixed>
     */
    public function getSchema(): array;

    /**
     * Persist a new schema. Called by the `write` tool only after the schema
     * validates and lints clean.
     *
     * @param  array<string,mixed>  $schema
     */
    public function setSchema(array $schema): void;

    /** A stable identifier for this workbook, surfaced in tool results. */
    public function getId(): string;
}
