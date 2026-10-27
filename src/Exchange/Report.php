<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * What a conversion lost or approximated (SPEC 5).
 *
 * Every conversion returns all three keys, even empty. Entries are one per
 * kind, sorted by kind; `where` holds up to 16 JSON Pointers into the
 * conversion's INPUT. A location, never content.
 */
final class Report
{
    public const DROPPED = [
        'cell-style-dropped', 'chart', 'comment', 'composite-element', 'embedded-object', 'hidden-column', 'hidden-element',
        'hidden-row', 'hidden-sheet', 'hidden-slide', 'image', 'link', 'nested-block', 'non-table-item', 'run-style-dropped',
        'shape', 'table-clipped',
    ];

    public const APPROXIMATED = [
        'cell-truncated', 'chart-as-table', 'code-as-text', 'csv-escaped', 'format-approximated', 'formula-as-value',
        'formula-uncomputed', 'header-rows-flattened', 'layout-not-preserved', 'markup-as-text', 'merge-flattened',
        'merge-unread', 'quote-flattened', 'structure-not-recovered', 'typed-as-text',
    ];

    public const WHERE_CAP = 16;

    /** @var array{dropped: array<string,array{count:int,where:?list<string>}>, approximated: array<string,array{count:int,where:?list<string>}>} */
    private array $lists = ['dropped' => [], 'approximated' => []];

    public readonly Guard $guard;

    public function __construct(?Guard $guard = null)
    {
        $this->guard = $guard ?? new Guard();
    }

    /** `dropped`, `approximated`, or null for a kind not on the closed list. */
    public static function classifyKind(mixed $kind): ?string
    {
        if (! \is_string($kind)) {
            return null;
        }
        if (\in_array($kind, self::DROPPED, true)) {
            return 'dropped';
        }

        return \in_array($kind, self::APPROXIMATED, true) ? 'approximated' : null;
    }

    public function add(string $kind, ?string $where = null, int $count = 1): void
    {
        $list = self::classifyKind($kind);
        if ($list === null) {
            throw new \LogicException("[holy-sheet] unknown report kind {$kind}");
        }
        $e = $this->lists[$list][$kind] ?? ['count' => 0, 'where' => null];
        $e['count'] += $count;
        if ($where !== null) {
            $e['where'] ??= [];
            if (\count($e['where']) < self::WHERE_CAP) {
                $e['where'][] = $where;
            }
        }
        $this->lists[$list][$kind] = $e;
    }

    /** Fold another report (array form) into this one. */
    public function absorb(array $report): void
    {
        foreach (['dropped', 'approximated'] as $list) {
            foreach ($report[$list] ?? [] as $e) {
                $cur = $this->lists[$list][$e['kind']] ?? ['count' => 0, 'where' => null];
                $cur['count'] += $e['count'];
                if (isset($e['where'])) {
                    $cur['where'] ??= [];
                    foreach ($e['where'] as $w) {
                        if (\count($cur['where']) < self::WHERE_CAP) {
                            $cur['where'][] = $w;
                        }
                    }
                }
                $this->lists[$list][$e['kind']] = $cur;
            }
        }
        foreach ($report['guard']['findings'] ?? [] as $f) {
            $this->guard->add($f['rule'], $f['count']);
        }
    }

    /** @return array{dropped:list<array<string,mixed>>,approximated:list<array<string,mixed>>,guard:array{findings:list<array{rule:string,count:int}>}} */
    public function build(): array
    {
        $out = static function (array $list): array {
            $rows = [];
            foreach (Json::sortedKeys($list) as $kind) {
                $e = $list[$kind];
                $rows[] = $e['where'] === null
                    ? ['kind' => $kind, 'count' => $e['count']]
                    : ['kind' => $kind, 'count' => $e['count'], 'where' => $e['where']];
            }

            return $rows;
        };

        return [
            'dropped' => $out($this->lists['dropped']),
            'approximated' => $out($this->lists['approximated']),
            'guard' => ['findings' => $this->guard->findings()],
        ];
    }

    /** `mergeReports(a, b)`: counts add, `where` concatenates under the cap. */
    public static function merge(array $a, array $b): array
    {
        $r = new self();
        $r->absorb($a);
        $r->absorb($b);

        return $r->build();
    }
}
