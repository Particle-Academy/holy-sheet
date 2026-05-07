<?php

declare(strict_types=1);

namespace HolySheet\Helpers;

/**
 * CsvBuilder — CSV string OR file path → Holy Sheet schema.
 *
 *   $schema = CsvBuilder::build("Name,Age\nAlice,30\nBob,42");
 *   $schema = CsvBuilder::build('/tmp/users.csv');
 *
 * First row is always treated as headers. Uses PHP's built-in
 * `str_getcsv()` so quoting + embedded newlines + commas-in-fields
 * round-trip correctly. No third-party CSV dependency.
 */
final class CsvBuilder
{
    /**
     * @param  array<string,mixed>  $options  passthrough to ArrayBuilder (theme, currency, totals, etc.) plus:
     *   - 'delimiter' (default ',')
     *   - 'enclosure' (default '"')
     *   - 'escape' (default '\\')
     *   - 'sheetName' (default 'Sheet 1')
     */
    public static function build(string $csvOrPath, array $options = []): array
    {
        $csv = self::resolveContent($csvOrPath);

        $delimiter = $options['delimiter'] ?? ',';
        $enclosure = $options['enclosure'] ?? '"';
        $escape = $options['escape'] ?? '\\';
        $sheetName = $options['sheetName'] ?? 'Sheet 1';

        $rows = self::parseRows($csv, $delimiter, $enclosure, $escape);

        if ($rows === []) {
            return ['sheets' => [['name' => $sheetName, 'columns' => [], 'rows' => []]]];
        }

        $headers = array_map(fn ($v) => (string) $v, $rows[0]);
        $dataRows = array_slice($rows, 1);

        // Coerce numeric strings to native types so type inference works on raw values.
        foreach ($dataRows as $r => $row) {
            foreach ($row as $c => $cell) {
                if (is_string($cell) && is_numeric($cell)) {
                    $dataRows[$r][$c] = str_contains($cell, '.') ? (float) $cell : (int) $cell;
                }
            }
        }

        return ArrayBuilder::build($dataRows, $headers, $sheetName, $options);
    }

    /**
     * Decide whether the input is a path or raw CSV content. A leading
     * "/" or drive letter ("C:\\"), the absence of newlines, AND a
     * readable file at that path → treat as path; otherwise as content.
     */
    private static function resolveContent(string $csvOrPath): string
    {
        $looksLikePath = !str_contains($csvOrPath, "\n") && strlen($csvOrPath) < 4096;
        if ($looksLikePath && is_file($csvOrPath) && is_readable($csvOrPath)) {
            $contents = file_get_contents($csvOrPath);
            if ($contents === false) {
                throw new \RuntimeException("[holy-sheet] failed to read CSV at {$csvOrPath}");
            }
            return $contents;
        }
        return $csvOrPath;
    }

    /**
     * @return list<list<string>>
     */
    private static function parseRows(string $csv, string $delimiter, string $enclosure, string $escape): array
    {
        // Normalize line endings and split into logical rows. `str_getcsv` on each
        // line handles embedded delimiters but not embedded newlines — so we use
        // fgetcsv via a memory stream to handle multi-line fields properly.
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('[holy-sheet] failed to allocate memory stream for CSV parsing');
        }
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter, $enclosure, $escape)) !== false) {
            // fgetcsv returns [null] for blank lines — skip those.
            if ($row === [null] || $row === false) continue;
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }
}
