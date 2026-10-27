<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * A holy-sheet read model -> portable content (SPEC 10.1).
 *
 * The model is what `Agent::describe()` / `Agent::read()` return, including
 * the fields added for exchange: sheet `state`, `hiddenRows`, `hiddenColumns`,
 * `tables`, workbook `definedNames`, and `format.formatCode`.
 *
 * Security rules this enforces (SPEC 14):
 *   - formulas are metadata; `v` is the cached value, never an evaluation;
 *   - hidden sheets, rows and columns, and values under a merge, are skipped
 *     and reported unless the HOST passes `includeHidden: true`;
 *   - every string taken from the document is stripped of invisible
 *     characters, and the report counts them without the text;
 *   - output over a limit is refused, never cut (one cell string over
 *     `cellBytes` is the single lossy exception, and is reported).
 */
final class Extractor
{
    public const ERRORS = ['#NULL!', '#DIV/0!', '#VALUE!', '#REF!', '#NAME?', '#NUM!', '#N/A', '#SPILL!', '#CALC!'];

    private const STYLE_DROPPED = ['color', 'backgroundColor', 'fontSize', 'borderTop', 'borderRight', 'borderBottom', 'borderLeft'];

    private const KINDS = ['number' => 'number', 'currency' => 'currency', 'percentage' => 'percent', 'date' => 'date', 'datetime' => 'datetime', 'text' => 'text'];

    private const OPTIONS = ['includeHidden', 'headerRows', 'limits'];

    /** @var list<array<string,mixed>> */
    private array $sheets;

    private Report $rep;

    private Guard $guard;

    /** @var array<string,int> */
    private array $limits;

    private bool $includeHidden;

    /**
     * `toPortable(model, selector, options?)`.
     *
     * @param  array<string,mixed>  $model
     * @param  array<string,mixed>|null  $options  `{includeHidden?, headerRows?, limits?}`
     * @return array{ok:true,content:array<string,mixed>}|array{ok:false,refused:array{reason:string}}
     *
     * @throws OptionsInvalid
     */
    public static function toPortable(array $model, mixed $selector, ?array $options = null): array
    {
        $options ??= [];
        foreach (array_keys($options) as $k) {
            if (! \in_array($k, self::OPTIONS, true)) {
                throw new OptionsInvalid("unknown option {$k}");
            }
        }
        if (\array_key_exists('includeHidden', $options) && ! \is_bool($options['includeHidden'])) {
            throw new OptionsInvalid('includeHidden must be a boolean');
        }
        if (isset($options['headerRows']) && ! (Json::isInt($options['headerRows']) && $options['headerRows'] >= 0)) {
            throw new OptionsInvalid('headerRows must be an integer >= 0');
        }

        $x = new self();
        $x->sheets = array_values(\is_array($model['sheets'] ?? null) ? $model['sheets'] : []);
        $x->guard = new Guard();
        $x->rep = new Report($x->guard);
        $x->limits = Portable::mergeLimits(Portable::DEFAULT_LIMITS, $options['limits'] ?? null);
        $x->includeHidden = ($options['includeHidden'] ?? false) === true;

        return $x->run($model, $selector, $options);
    }

    private function run(array $model, mixed $selector, array $options): array
    {
        if ($selector === null) {
            $items = [];
            foreach ($this->sheets as $si => $s) {
                if (self::isHidden($s) && ! $this->includeHidden) {
                    $this->rep->add('hidden-sheet', "/sheets/{$si}");

                    continue;
                }
                $u = self::usedRange($s);
                if ($u === null) {
                    continue;
                }
                $b = $this->buildTable($si, $u, fn (int $rowCount): array => Range::headerRowsFor([
                    'frozenRows' => $s['frozenRows'] ?? null, 'startRow' => $u[0], 'rowCount' => $rowCount,
                ]));
                $items[] = $b['table'] + ['caption' => $this->guard->strip((string) ($s['name'] ?? ''))];
            }

            return $this->finish($items, ['package' => 'holy-sheet']);
        }

        $p = Range::parseSelector($selector, isset($options['headerRows']) ? ['headerRows' => $options['headerRows']] : null);
        if (! $p['ok']) {
            return self::no('selector_invalid');
        }
        $sel = $p['selector'];
        $si = -1;
        $range = null;
        $tableHeader = null;

        if ($sel['kind'] === 'range' || $sel['kind'] === 'sheet') {
            $si = $this->findSheet($sel['sheet']);
        } elseif ($sel['kind'] === 'name') {
            $dn = null;
            foreach (\is_array($model['definedNames'] ?? null) ? $model['definedNames'] : [] as $d) {
                if (\is_array($d) && \is_string($d['name'] ?? null) && self::eq($d['name'], $sel['name'])) {
                    $dn = $d;
                    break;
                }
            }
            if ($dn === null) {
                return self::no('selector_not_found');
            }
            $ref = (string) ($dn['ref'] ?? '');
            if (str_contains($ref, ',')) {
                return self::no('selector_unsupported');
            }
            $sp = Range::splitSheet($ref);
            $r = $sp !== null && $sp['range'] !== null ? Range::parseRange($sp['range']) : null;
            if ($sp === null || $r === null) {
                return self::no('selector_unsupported');
            }
            $si = $this->findSheet($sp['sheet']);
            $range = self::bounds($r);
        } else {
            foreach ($this->sheets as $i => $s) {
                foreach (\is_array($s['tables'] ?? null) ? $s['tables'] : [] as $t) {
                    if (\is_array($t) && \is_string($t['name'] ?? null) && self::eq($t['name'], $sel['table'])) {
                        $r = Range::parseRange((string) ($t['ref'] ?? ''));
                        if ($r === null) {
                            return self::no('selector_unsupported');
                        }
                        $si = $i;
                        $range = self::bounds($r);
                        $tableHeader = (int) ($t['headerRowCount'] ?? 1);
                        break 2;
                    }
                }
            }
        }
        if ($si < 0) {
            return self::no('selector_not_found');
        }
        $s = $this->sheets[$si];
        if (self::isHidden($s) && ! $this->includeHidden) {
            return self::no('selector_hidden');
        }
        if ($sel['kind'] === 'range') {
            $range = self::bounds($sel);
        } elseif ($sel['kind'] === 'sheet') {
            $range = self::usedRange($s);
        }

        $src = $sel;
        $src['sheet'] = $this->guard->strip((string) ($s['name'] ?? ''));
        if ($range === null) {
            return $this->finish([], ['package' => 'holy-sheet', 'selector' => $src]);
        }
        $applied = 0;
        $b = $this->buildTable($si, $range, function (int $rowCount) use ($sel, $tableHeader, $s, $range, &$applied): array {
            $h = Range::headerRowsFor([
                'selector' => $sel['headerRows'] ?? null,
                'table' => $tableHeader,
                'frozenRows' => $s['frozenRows'] ?? null,
                'startRow' => $range[0],
                'rowCount' => $rowCount,
            ]);
            if ($h['ok']) {
                $applied = $h['headerRows'];
            }

            return $h;
        });
        if (! $b['ok']) {
            return self::no($b['code']);
        }
        $src['start'] = Range::addr($range[1], $range[0]);
        $src['end'] = Range::addr($range[3], $range[2]);
        $src['headerRows'] = $applied;

        return $this->finish([$b['table']], ['package' => 'holy-sheet', 'selector' => $src]);
    }

    /** @return array{ok:false,refused:array{reason:string}} */
    private static function no(string $reason): array
    {
        return ['ok' => false, 'refused' => ['reason' => $reason]];
    }

    /** ASCII case-insensitive, as Excel matches sheet, name and table names. */
    private static function eq(string $a, string $b): bool
    {
        return strtolower($a) === strtolower($b);
    }

    private function findSheet(string $name): int
    {
        foreach ($this->sheets as $i => $s) {
            if (\is_string($s['name'] ?? null) && self::eq($s['name'], $name)) {
                return $i;
            }
        }

        return -1;
    }

    public static function isHidden(array $s): bool
    {
        return \in_array($s['state'] ?? null, ['hidden', 'veryHidden'], true);
    }

    /** @return array{0:int,1:int}  [col, row] */
    private static function ref(string $a): array
    {
        $p = Range::parseCell($a);

        return $p['ok'] ? [$p['column'], $p['row']] : [0, 0];
    }

    /**
     * @param  array{start:string,end:string}  $r
     * @return array{0:int,1:int,2:int,3:int}  [r1, c1, r2, c2]
     */
    private static function bounds(array $r): array
    {
        [$c1, $r1] = self::ref($r['start']);
        [$c2, $r2] = self::ref($r['end']);

        return [$r1, $c1, $r2, $c2];
    }

    /**
     * The bounding box of every cell and merged region.
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    public static function usedRange(array $s): ?array
    {
        $r1 = PHP_INT_MAX;
        $c1 = PHP_INT_MAX;
        $r2 = 0;
        $c2 = 0;
        $touch = static function (string $a) use (&$r1, &$c1, &$r2, &$c2): void {
            [$col, $row] = self::ref($a);
            if ($row === 0) {
                return;
            }
            $r1 = min($r1, $row);
            $c1 = min($c1, $col);
            $r2 = max($r2, $row);
            $c2 = max($c2, $col);
        };
        foreach (\is_array($s['cells'] ?? null) ? $s['cells'] : [] as $a => $cell) {
            if ($cell !== null) {
                $touch((string) $a);
            }
        }
        foreach (\is_array($s['mergedRegions'] ?? null) ? $s['mergedRegions'] : [] as $mr) {
            $touch((string) ($mr['start'] ?? ''));
            $touch((string) ($mr['end'] ?? ''));
        }

        return $r2 === 0 ? null : [$r1, $c1, $r2, $c2];
    }

    /**
     * @param  array{0:int,1:int,2:int,3:int}  $range
     * @param  callable(int):array<string,mixed>  $header
     * @return array{ok:true,table:array<string,mixed>}|array{ok:false,code:string}
     */
    private function buildTable(int $si, array $range, callable $header): array
    {
        [$r1, $c1, $r2, $c2] = $range;
        $s = $this->sheets[$si];
        $sp = "/sheets/{$si}";
        $hiddenRows = $this->includeHidden ? [] : array_values(\is_array($s['hiddenRows'] ?? null) ? $s['hiddenRows'] : []);
        $hiddenCols = $this->includeHidden ? [] : array_values(\is_array($s['hiddenColumns'] ?? null) ? $s['hiddenColumns'] : []);
        // index of the FIRST entry naming each row / column: the report points at it
        $hiddenRowAt = [];
        foreach ($hiddenRows as $k => $r) {
            $hiddenRowAt[(int) $r] ??= $k;
        }
        $hiddenColAt = [];
        foreach ($hiddenCols as $k => $c) {
            $hiddenColAt[(int) $c] ??= $k;
        }

        $rows = [];
        $cols = [];
        for ($c = $c1; $c <= $c2; $c++) {
            if (isset($hiddenColAt[$c - 1])) {
                $this->rep->add('hidden-column', "{$sp}/hiddenColumns/".$hiddenColAt[$c - 1]);
            } else {
                $cols[] = $c;
            }
        }
        for ($r = $r1; $r <= $r2; $r++) {
            if (isset($hiddenRowAt[$r])) {
                $this->rep->add('hidden-row', "{$sp}/hiddenRows/".$hiddenRowAt[$r]);
            } else {
                $rows[] = $r;
            }
        }

        $kept = [];
        $coveredKept = [];
        $coveredFlat = [];
        foreach (\is_array($s['mergedRegions'] ?? null) ? array_values($s['mergedRegions']) : [] as $k => $mr) {
            [$ac, $ar] = self::ref((string) ($mr['start'] ?? ''));
            [$bc, $br] = self::ref((string) ($mr['end'] ?? ''));
            if ($ar === 0 || $br === 0) {
                continue;
            }
            if (! ($ar <= $r2 && $br >= $r1 && $ac <= $c2 && $bc >= $c1)) {
                continue;
            }
            $inside = $ar >= $r1 && $br <= $r2 && $ac >= $c1 && $bc <= $c2;
            $touchesHidden = false;
            for ($r = $ar; $r <= $br && ! $touchesHidden; $r++) {
                $touchesHidden = isset($hiddenRowAt[$r]);
            }
            for ($c = $ac; $c <= $bc && ! $touchesHidden; $c++) {
                $touchesHidden = isset($hiddenColAt[$c - 1]);
            }
            $keep = $inside && ! $touchesHidden;
            if (! $keep) {
                $this->rep->add('merge-flattened', "{$sp}/mergedRegions/{$k}");
            }
            for ($r = max($ar, $r1); $r <= min($br, $r2); $r++) {
                for ($c = max($ac, $c1); $c <= min($bc, $c2); $c++) {
                    if ($r === $ar && $c === $ac) {
                        continue;
                    }
                    if ($keep) {
                        $coveredKept[Range::addr($c, $r)] = true;
                    } else {
                        $coveredFlat[Range::addr($c, $r)] = true;
                    }
                }
            }
            if ($keep) {
                $kept[Range::addr($ac, $ar)] = [$br - $ar + 1, $bc - $ac + 1];
            }
        }

        $cellsIn = \is_array($s['cells'] ?? null) ? $s['cells'] : [];
        $outRows = [];
        foreach ($rows as $r) {
            $cells = [];
            foreach ($cols as $c) {
                $a = Range::addr($c, $r);
                $raw = $cellsIn[$a] ?? null;
                $cp = "{$sp}/cells/{$a}";
                if (isset($coveredKept[$a])) {
                    if ($raw !== null) {
                        $this->rep->add('hidden-element', $cp);
                    }

                    continue;
                }
                if (isset($coveredFlat[$a])) {
                    if ($raw !== null) {
                        $this->rep->add('hidden-element', $cp);
                    }
                    $cells[] = ['t' => 'empty'];

                    continue;
                }
                $cell = $this->mapCell(\is_array($raw) ? $raw : ($raw === null ? null : ['value' => $raw]), $cp);
                if (isset($kept[$a])) {
                    [$rs, $cs] = $kept[$a];
                    if ($cs > 1) {
                        $cell['colSpan'] = $cs;
                    }
                    if ($rs > 1) {
                        $cell['rowSpan'] = $rs;
                    }
                }
                $cells[] = $cell;
            }
            $outRows[] = ['cells' => $cells];
        }

        $h = $header(\count($rows));
        if (! $h['ok']) {
            return $h;
        }
        $t = ['type' => 'table', 'headerRows' => $h['headerRows'], 'rows' => $outRows];

        $widths = \is_array($s['columnWidths'] ?? null) ? $s['columnWidths'] : [];
        $any = false;
        foreach ($cols as $c) {
            $any = $any || \array_key_exists($c - 1, $widths);
        }
        if ($any) {
            $px = array_map(static fn (int $c): float|int => Json::isNum($widths[$c - 1] ?? null) ? $widths[$c - 1] : 64, $cols);
            $sum = array_sum($px);
            $t['columns'] = array_map(static fn ($p): array => ['width' => $p / $sum], $px);
        }

        foreach (\is_array($s['charts'] ?? null) ? array_values($s['charts']) : [] as $k => $ch) {
            $from = \is_array($ch) ? ($ch['position']['from'] ?? null) : null;
            if (! \is_string($from)) {
                continue;
            }
            [$col, $row] = self::ref($from);
            if ($row >= $r1 && $row <= $r2 && $col >= $c1 && $col <= $c2) {
                $this->rep->add('chart', "{$sp}/charts/{$k}");
            }
        }

        return ['ok' => true, 'table' => $t];
    }

    /**
     * @param  array<string,mixed>|null  $raw
     * @return array<string,mixed>
     */
    private function mapCell(?array $raw, string $cp): array
    {
        if ($raw === null) {
            return ['t' => 'empty'];
        }
        $f = \is_array($raw['format'] ?? null) ? $raw['format'] : [];
        $hasFormula = \is_string($raw['formula'] ?? null);
        $value = $raw['value'] ?? null;
        if ($hasFormula) {
            // A formula's value is its CACHED result. Nothing evaluates it, and
            // a `value` sitting beside an uncached formula is not trusted either.
            if (! isset($raw['computedValue'])) {
                $this->rep->add('formula-uncomputed', $cp);

                return ['t' => 'empty', 'formula' => $raw['formula']];
            }
            $value = $raw['computedValue'];
        }
        if (\is_string($value)) {
            $value = $this->guard->strip($value);
        }
        $display = $f['displayFormat'] ?? null;

        $out = match (true) {
            $value === null => ['t' => 'empty'],
            \is_bool($value) => ['t' => 'bool', 'v' => $value],
            \is_int($value), \is_float($value) => is_finite((float) $value) ? ['t' => 'number', 'v' => $value] : ['t' => 'error', 'v' => '#NUM!'],
            ! \is_string($value) => ['t' => 'empty'],
            $hasFormula && \in_array($value, self::ERRORS, true) => ['t' => 'error', 'v' => $value],
            $display === 'date' && Portable::isDate($value) => ['t' => 'date', 'v' => $value],
            $display === 'datetime' && Portable::isDateTime($value) => ['t' => 'datetime', 'v' => $value],
            default => ['t' => 'string', 'v' => $value],
        };
        if ($hasFormula) {
            $out['formula'] = $raw['formula'];
        }

        $approximated = false;
        if ($out['t'] === 'number') {
            if (\is_string($f['formatCode'] ?? null)) {
                $out['format'] = ['kind' => 'number'];
                $approximated = true;
                $this->rep->add('format-approximated', $cp);
            } elseif (\is_string($display) && isset(self::KINDS[$display])) {
                $out['format'] = ['kind' => self::KINDS[$display]];
                if (isset($f['decimals']) && Json::isInt($f['decimals']) && $f['decimals'] >= 0 && $f['decimals'] <= 10) {
                    $out['format']['decimals'] = (int) $f['decimals'];
                }
                if (self::KINDS[$display] === 'currency' && \is_string($f['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', $f['currency']) === 1) {
                    $out['format']['currency'] = $f['currency'];
                }
            }
        } elseif (($out['t'] === 'date' || $out['t'] === 'datetime') && \is_string($display) && isset(self::KINDS[$display])) {
            $out['format'] = ['kind' => self::KINDS[$display]];
        }

        $truncated = false;
        if ($out['t'] === 'string' && \strlen($out['v']) > $this->limits['cellBytes']) {
            $out['v'] = Portable::truncateCell($out['v'], $this->limits['cellBytes'])['text'];
            $this->rep->add('cell-truncated', $cp);
            $truncated = true;
        }

        // A foreign format's best-effort text is canonical(v), never the
        // approximated {kind: "number"} at its 0-place default.
        $canonical = Display::canonical($out['t'], $out['v'] ?? null);
        $shown = isset($out['format']) && ! $approximated ? Display::display($out['t'], $out['v'], $out['format']) : $canonical;
        if (isset($out['format']) && $shown !== $canonical) {
            $out['text'] = $shown;
        }
        if ((! empty($f['bold']) || ! empty($f['italic'])) && $shown !== '' && ! $truncated) {
            $run = ['text' => $shown];
            if (! empty($f['bold'])) {
                $run['bold'] = true;
            }
            if (! empty($f['italic'])) {
                $run['italic'] = true;
            }
            $out['runs'] = [$run];
        }
        if (\in_array($f['textAlign'] ?? null, ['left', 'center', 'right'], true)) {
            $out['align'] = $f['textAlign'];
        }
        foreach (self::STYLE_DROPPED as $k) {
            if (\array_key_exists($k, $f)) {
                $this->rep->add('cell-style-dropped', $cp);
                break;
            }
        }
        if (isset($raw['comment'])) {
            $this->rep->add('comment', $cp);
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  array<string,mixed>  $source
     */
    private function finish(array $items, array $source): array
    {
        $c = ['fancyContent' => 1, 'source' => $source, 'items' => $items, 'report' => $this->rep->build()];
        $lf = Portable::limitFailure($c, $this->limits);
        if ($lf !== null) {
            return self::no($lf[0]);
        }

        return ['ok' => true, 'content' => $c];
    }
}
