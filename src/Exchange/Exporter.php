<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

use HolySheet\Archive\OfficeTemplate;
use HolySheet\Archive\SourceArchive;
use HolySheet\Exceptions\ArchiveRefused;
use HolySheet\Exceptions\OfficeTemplateRefused;
use HolySheet\Exceptions\SchemaException;
use HolySheet\Exceptions\UnsupportedFormatException;
use HolySheet\HolySheet;
use HolySheet\Reader\FormatSniffer;
use HolySheet\Reader\OdsReader;
use HolySheet\Reader\WorkbookSchema;
use HolySheet\Reader\XlsxReader;
use HolySheet\Schema\Normalizer;
use HolySheet\Schema\Validator;
use HolySheet\Writer\XlsxWriter;

/**
 * catalog / extract / export for holy-sheet: the reads an agent uses to grab
 * part of a spreadsheet, and the host-gated exports.
 *
 * `extract` is a read and is not gated. Every EXPORT is off until the host's
 * ExportPolicy allows it, and nothing an agent passes can widen that.
 */
final class Exporter
{
    public const READER = 'holy-sheet';

    private const MIME = [
        'csv' => 'text/csv; charset=utf-8',
        'markdown' => 'text/markdown; charset=utf-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Read bytes, a path, or an already-read model.
     *
     * @return array{model:array<string,mixed>,kind:?string,zip:?SourceArchive,sourceKind:string}
     *
     * @throws InputRefused
     */
    public static function load(string|array $input): array
    {
        if (\is_array($input)) {
            return ['model' => $input, 'kind' => null, 'zip' => null, 'sourceKind' => 'model'];
        }
        try {
            if (self::isPath($input)) {
                $zip = SourceArchive::fromPath($input);
                $kind = FormatSniffer::sniffArchive($zip, $input);
            } else {
                [$zip, $kind] = FormatSniffer::sniffBytes($input);
            }
            $workbook = $kind === FormatSniffer::ODS ? (new OdsReader())->readArchive($zip) : (new XlsxReader())->readArchive($zip);
        } catch (ArchiveRefused $e) {
            throw new InputRefused($e->reason, $e);
        } catch (OfficeTemplateRefused $e) {
            throw new InputRefused($e->reason, $e);
        } catch (UnsupportedFormatException $e) {
            throw new InputRefused('not_a_workbook', $e);
        } catch (\RuntimeException $e) {
            // A zip that names itself a workbook but has no readable workbook part.
            throw new InputRefused('not_a_workbook', $e);
        }

        return ['model' => WorkbookSchema::fromWorkbook($workbook), 'kind' => $kind, 'zip' => $zip, 'sourceKind' => 'document'];
    }

    public static function isPath(string $s): bool
    {
        return ! str_contains($s, "\0") && \strlen($s) <= 4096 && is_file($s);
    }

    /**
     * A cheap index of what can be grabbed, each entry carrying the selector
     * that grabs it. Names and titles are guarded like any extracted text.
     *
     * @param  array<string,mixed>  $model
     * @return array<string,mixed>
     */
    public static function catalog(array $model): array
    {
        $guard = new Guard();
        $sheets = [];
        $tables = [];
        $charts = [];
        foreach (array_values($model['sheets'] ?? []) as $si => $s) {
            $raw = (string) ($s['name'] ?? '');
            $name = $guard->strip($raw);
            $u = Extractor::usedRange($s);
            $cellCount = 0;
            foreach ($s['cells'] ?? [] as $cell) {
                $cellCount += $cell === null ? 0 : 1;
            }
            $entry = [
                'index' => $si,
                'name' => $name,
                'state' => \in_array($s['state'] ?? null, ['hidden', 'veryHidden'], true) ? $s['state'] : 'visible',
                'selector' => ['sheet' => $raw],
                'usedRange' => $u === null ? null : Range::addr($u[1], $u[0]).':'.Range::addr($u[3], $u[2]),
                'rows' => $u === null ? 0 : $u[2] - $u[0] + 1,
                'columns' => $u === null ? 0 : $u[3] - $u[1] + 1,
                'cells' => $cellCount,
                'hiddenRows' => \count($s['hiddenRows'] ?? []),
                'hiddenColumns' => \count($s['hiddenColumns'] ?? []),
                'frozenRows' => (int) ($s['frozenRows'] ?? 0),
            ];
            $sheets[] = $entry;
            foreach ($s['tables'] ?? [] as $t) {
                $tables[] = [
                    'name' => $guard->strip((string) $t['name']),
                    'sheet' => $name,
                    'ref' => (string) $t['ref'],
                    'headerRowCount' => (int) ($t['headerRowCount'] ?? 1),
                    'selector' => ['table' => (string) $t['name']],
                ];
            }
            foreach (array_values($s['charts'] ?? []) as $k => $ch) {
                $c = ['sheet' => $name, 'index' => $k, 'type' => (string) ($ch['type'] ?? '')];
                if (isset($ch['title']) && \is_string($ch['title'])) {
                    $c['title'] = $guard->strip($ch['title']);
                }
                if (isset($ch['position']['from'], $ch['position']['to'])) {
                    $c['anchor'] = $ch['position']['from'].':'.$ch['position']['to'];
                }
                $charts[] = $c;
            }
        }
        $names = [];
        foreach ($model['definedNames'] ?? [] as $dn) {
            $names[] = [
                'name' => $guard->strip((string) $dn['name']),
                'ref' => $guard->strip((string) $dn['ref']),
                'selector' => ['name' => (string) $dn['name']],
            ];
        }

        return [
            'sheets' => $sheets,
            'definedNames' => $names,
            'tables' => $tables,
            'charts' => $charts,
            'guard' => ['findings' => $guard->findings()],
        ];
    }

    /**
     * `extract(input, selector, options)`: portable content, with `source`
     * completed (`kind`, `version`, `sourceKind`).
     *
     * @param  array<string,mixed>  $options  `{headerRows?, includeHidden?, limits?}`; the last two are HOST options
     * @return array{ok:true,content:array<string,mixed>,report:array<string,mixed>}|array{ok:false,refused:array<string,string>}
     *
     * @throws OptionsInvalid
     */
    public static function extract(string|array $input, mixed $selector = null, array $options = []): array
    {
        try {
            $loaded = self::load($input);
        } catch (InputRefused $e) {
            return ['ok' => false, 'refused' => ['reason' => $e->reason]];
        }

        return self::extractFrom($loaded, $selector, $options);
    }

    /** @param array{model:array<string,mixed>,kind:?string,sourceKind:string} $loaded */
    private static function extractFrom(array $loaded, mixed $selector, array $options): array
    {
        $r = Extractor::toPortable($loaded['model'], $selector, $options);
        if (! $r['ok']) {
            return $r;
        }
        $c = $r['content'];
        $source = ['package' => self::READER];
        if ($loaded['kind'] !== null) {
            $source['kind'] = $loaded['kind'];
        }
        $source['version'] = HolySheet::VERSION;
        $source['sourceKind'] = $loaded['sourceKind'];
        $c['source'] = $source + $c['source'];

        return ['ok' => true, 'content' => $c, 'report' => $c['report']];
    }

    /**
     * Which optional modules are installed AND bridged by this release.
     *
     * The pdf (fine-print) and docx (last-word) bridges are not in this
     * release, so both report absent whatever is installed: an agent is never
     * shown a target that cannot run.
     *
     * @return array<string,bool>
     */
    public static function environment(): array
    {
        return ['fine-print' => false, 'last-word' => false];
    }

    /**
     * `export(input, target, options, policy)`.
     *
     * @param  array<string,mixed>|null  $options  agent options: `selector`, `headerRows`, `display`
     * @param  array<string,bool>|null  $environment  module presence; null detects
     * @return array<string,mixed>  `{ok:true, target, mime, text|bytes, report}` or `{ok:false, refused:{reason, target, ...}}`
     *
     * @throws OptionsInvalid for a malformed policy or any option outside the three
     */
    public static function export(string|array $input, string $target, ?array $options, ExportPolicy|array|null $policy, ?array $environment = null): array
    {
        ExportPolicy::checkOptions($options);
        $policy = $policy instanceof ExportPolicy ? $policy : ExportPolicy::fromArray($policy);
        $options ??= [];
        $env = $environment ?? self::environment();

        $gate = $policy->check(self::READER, $target, $env);
        if (! $gate['ok']) {
            return $gate;
        }
        $no = static fn (string $reason, array $extra = []): array => ['ok' => false, 'refused' => ['reason' => $reason, 'target' => $target] + $extra];

        try {
            $loaded = self::load($input);
        } catch (InputRefused $e) {
            return $no('export_refused', ['cause' => $e->reason]);
        }

        if ($target === 'pdf' || $target === 'docx') {
            // Reachable only when a host injects the module as present; the
            // bridge itself is not in this release.
            return $no('export_unavailable', ['missing' => (string) ExportPolicy::needs(self::READER, $target)]);
        }

        if ($target === 'xlsx') {
            return self::toXlsx($loaded, $no);
        }

        $extractOptions = ['includeHidden' => $policy->includeHidden];
        if ($policy->limits !== []) {
            $extractOptions['limits'] = $policy->limits;
        }
        if (isset($options['headerRows'])) {
            $extractOptions['headerRows'] = $options['headerRows'];
        }
        $x = self::extractFrom($loaded, $options['selector'] ?? null, $extractOptions);
        if (! $x['ok']) {
            $reason = $x['refused']['reason'];

            return str_starts_with($reason, 'content_') || $reason === 'image_too_large'
                ? $no($reason)
                : $no('export_refused', ['cause' => $reason]);
        }
        $content = $x['content'];

        if ($target === 'csv') {
            $tables = array_values(array_filter($content['items'], static fn (array $i): bool => $i['type'] === 'table'));
            if (\count($tables) > 1) {
                // A CSV holds one table; picking one silently would drop the rest.
                return $no('export_refused', ['cause' => 'selector_invalid']);
            }
            $csvOptions = ['display' => ($options['display'] ?? false) === true];
            if (\array_key_exists('escapeFormulas', $policy->csv)) {
                $csvOptions['escapeFormulas'] = $policy->csv['escapeFormulas'];
            }
            $out = $tables === [] ? ['text' => '', 'report' => (new Report())->build()] : Csv::toCsv($tables[0], $csvOptions);
        } else {
            $out = Markdown::fromPortable($content);
        }

        return [
            'ok' => true,
            'target' => $target,
            'mime' => self::MIME[$target],
            'text' => $out['text'],
            'report' => Report::merge($content['report'], $out['report']),
        ];
    }

    /**
     * xlsx from ods (or from an xlsx template): a real conversion through the
     * reader and writer, which carry sheet state and hidden rows and columns.
     * An xlsx document is already an xlsx and comes back byte for byte.
     */
    private static function toXlsx(array $loaded, callable $no): array
    {
        $empty = (new Report())->build();
        if ($loaded['kind'] === FormatSniffer::XLSX && $loaded['zip'] !== null) {
            $bytes = OfficeTemplate::kind($loaded['zip']) === 'template'
                ? OfficeTemplate::convert($loaded['zip']->toBytes(), 'document')
                : $loaded['zip']->toBytes();

            return ['ok' => true, 'target' => 'xlsx', 'mime' => self::MIME['xlsx'], 'bytes' => $bytes, 'report' => $empty];
        }
        $model = $loaded['model'];
        unset($model['sourceKind']);
        try {
            (new Validator())->assert($model);
            $bytes = (new XlsxWriter())->toBytes((new Normalizer())->normalize($model));
        } catch (SchemaException $e) {
            return $no('export_refused', ['cause' => 'schema_invalid']);
        }

        return ['ok' => true, 'target' => 'xlsx', 'mime' => self::MIME['xlsx'], 'bytes' => $bytes, 'report' => $empty];
    }

    /**
     * The tool an agent is given to export: `target` lists ONLY the enabled
     * targets. Null when nothing is enabled, so no tool is offered at all.
     *
     * @return array{name:string,description:string,parameters:array<string,mixed>}|null
     *
     * @throws OptionsInvalid
     */
    public static function toolDefinition(ExportPolicy|array|null $policy, ?array $environment = null): ?array
    {
        $policy = $policy instanceof ExportPolicy ? $policy : ExportPolicy::fromArray($policy);
        $targets = $policy->enabled(self::READER, $environment ?? self::environment());
        if ($targets === []) {
            return null;
        }

        return [
            'name' => 'export_spreadsheet',
            'description' => 'Export a spreadsheet, or one selected range of it, to another format. Only the targets listed are enabled. '
                .'csv and markdown hold computed values (never formulas); hidden sheets, rows and columns are left out and reported.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'target' => ['type' => 'string', 'enum' => $targets],
                    'selector' => [
                        'description' => 'What to export: "Sheet!A1:D20", "\'Sheet name\'!A1:D20", "Sheet" (its used range), {"sheet": "...", "range": "A1:D20"}, {"name": "<defined name>"} or {"table": "<Excel table>"}. Omit for every visible sheet.',
                        'oneOf' => [['type' => 'string'], ['type' => 'object']],
                    ],
                    'headerRows' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Header rows at the top of the selection; defaults to the table\'s, else the frozen rows, else 0.'],
                    'display' => ['type' => 'boolean', 'description' => 'csv only: write display strings ("$1,234.50") instead of values (1234.5).'],
                ],
                'required' => ['target'],
                'additionalProperties' => false,
            ],
        ];
    }
}
