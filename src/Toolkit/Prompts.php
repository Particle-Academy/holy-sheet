<?php

declare(strict_types=1);

namespace HolySheet\Toolkit;

/**
 * Access to the shipped agent prompts.
 *
 * Prompts live as overridable markdown under `resources/prompts/`. Hosts that
 * want a different voice, output style, or domain framing can ignore these and
 * pass their own string to their agent framework — nothing here is mandatory.
 */
final class Prompts
{
    /** The canonical system prompt for a Holy Sheet spreadsheet agent. */
    public static function agent(): string
    {
        return self::load('agent') ?? self::AGENT_FALLBACK;
    }

    /**
     * Load a named prompt from `resources/prompts/{name}.md`.
     * Returns null if the file is missing.
     */
    public static function load(string $name): ?string
    {
        $name = preg_replace('/[^a-z0-9_-]/i', '', $name) ?? '';
        if ($name === '') {
            return null;
        }

        $path = dirname(__DIR__, 2)."/resources/prompts/{$name}.md";
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /** Embedded fallback so the prompt is always available, even if the file is stripped. */
    private const AGENT_FALLBACK = <<<'MD'
        You build and edit Excel (.xlsx) spreadsheets through the Holy Sheet tools.

        Workbook shape:
        - A workbook is `{ "sheets": [ ... ] }`. Each sheet has a `name` plus either
          `{columns, rows}` (row-oriented) or `{cells}` (a sparse map keyed by A1
          addresses like "A1", "B2").
        - Row 1 is the header row when `columns` are present; data starts at row 2,
          so ranges over data start at row 2 (e.g. `SUM(B2:B10)`).
        - A formula cell is a bare string beginning with `=` (e.g. `"=SUM(B2:B10)"`),
          or the explicit object `{ "value": null, "formula": "SUM(B2:B10)" }`. To
          store a literal leading-"=" string, use `{ "value": "=text" }`.

        Loop:
        1. `read_schema` to see current content before editing.
        2. `build_schema` to validate + repair a draft.
        3. `write_xlsx` to persist. If it returns `ok:false`, read the `errors`/`issues`,
           fix your schema, and call `write_xlsx` again. Never give up after one failure —
           the lint stage catches real Excel errors (#REF!, #VALUE!, #DIV/0!, #NAME?, #CIRC!).
        MD;
}
