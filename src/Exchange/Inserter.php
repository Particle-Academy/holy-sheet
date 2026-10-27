<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * Portable content -> holy-sheet cells (SPEC 11.2).
 *
 * - **Every string goes in as `{value: s}`**, never as a bare string, so the
 *   normaliser's `=` promotion can never fire on imported text.
 * - A `formula` is never written: the cell gets its cached value, and the
 *   report says `formula-as-value`.
 * - Table items stack top to bottom from `at`, one empty row between them; a
 *   caption is a string cell above its table. Anything else is
 *   `non-table-item`.
 */
final class Inserter
{
    private const DISPLAY_FORMAT = ['number' => 'number', 'currency' => 'currency', 'percent' => 'percentage', 'date' => 'date', 'datetime' => 'datetime', 'text' => 'text'];

    /**
     * `fromPortable({content, at})`.
     *
     * @return array{ok:true,sheet:array<string,mixed>,report:array<string,mixed>}|array{ok:false,refused:array{reason:string,path:string}}
     */
    public static function fromPortable(mixed $content, mixed $at = 'A1', mixed $options = null): array
    {
        $v = Portable::validateContent($content, $options);
        if (! $v['ok']) {
            return ['ok' => false, 'refused' => ['reason' => $v['code'], 'path' => $v['path']]];
        }
        $content = Portable::arrays($content);
        $start = Range::parseCell($at);
        if (! $start['ok']) {
            return ['ok' => false, 'refused' => ['reason' => 'selector_invalid', 'path' => '']];
        }

        $rep = new Report();
        $col0 = $start['column'];
        $row = $start['row'];
        $cells = [];
        $merged = [];
        $widths = [];
        $first = true;

        foreach ($content['items'] as $i => $it) {
            $p = "/items/{$i}";
            if ($it['type'] !== 'table') {
                $rep->add('non-table-item', $p);

                continue;
            }
            if (! $first) {
                $row++;
            }
            $first = false;
            if (\array_key_exists('caption', $it)) {
                $cells[self::addr($col0, $row)] = ['value' => $it['caption']];
                $row++;
            }
            $g = Portable::layout($it['rows']);
            if ($col0 + $g['width'] - 1 > Range::MAX_COL || $row + \count($it['rows']) - 1 > Range::MAX_ROW) {
                // Placing it at `at` would run off the sheet.
                return ['ok' => false, 'refused' => ['reason' => 'selector_invalid', 'path' => $p]];
            }
            foreach ($it['rows'] as $ri => $r) {
                foreach ($r['cells'] as $ci => $cell) {
                    $cp = "{$p}/rows/{$ri}/cells/{$ci}";
                    [$ar, $ac] = $g['anchors'][$ri][$ci];
                    $a = self::addr($col0 + $ac, $row + $ar);
                    if (\array_key_exists('formula', $cell)) {
                        $rep->add('formula-as-value', $cp);
                    }
                    if (isset($cell['runs']) && self::styled($cell['runs'])) {
                        $rep->add('run-style-dropped', $cp);
                    }
                    $cs = (int) ($cell['colSpan'] ?? 1);
                    $rs = (int) ($cell['rowSpan'] ?? 1);
                    if ($cs > 1 || $rs > 1) {
                        $merged[] = ['start' => $a, 'end' => self::addr($col0 + $ac + $cs - 1, $row + $ar + $rs - 1)];
                    }
                    if ($cell['t'] === 'empty') {
                        continue;
                    }
                    if ($cell['t'] === 'error') {
                        $rep->add('typed-as-text', $cp);
                    }
                    // The object form, always. A bare string beginning `=` would
                    // be promoted to a formula by the normaliser.
                    $out = ['value' => $cell['v']];
                    $fmt = [];
                    if ($cell['t'] === 'number' && isset($cell['format'])) {
                        $fmt['displayFormat'] = self::DISPLAY_FORMAT[$cell['format']['kind']];
                        if (\array_key_exists('decimals', $cell['format'])) {
                            $fmt['decimals'] = (int) $cell['format']['decimals'];
                        }
                        if (\array_key_exists('currency', $cell['format'])) {
                            $fmt['currency'] = $cell['format']['currency'];
                        }
                    }
                    if ($cell['t'] === 'date' || $cell['t'] === 'datetime') {
                        $k = $cell['format']['kind'] ?? null;
                        $fmt['displayFormat'] = $k === 'date' || $k === 'datetime' ? $k : $cell['t'];
                    }
                    if (isset($cell['align'])) {
                        $fmt['textAlign'] = $cell['align'];
                    }
                    if ($fmt !== []) {
                        $out['format'] = $fmt;
                    }
                    $cells[$a] = $out;
                }
            }
            $cols = $it['columns'] ?? null;
            if (\is_array($cols) && $cols !== [] && self::allWidths($cols)) {
                $sum = array_sum(array_map(static fn (array $c) => $c['width'], $cols));
                foreach ($cols as $j => $c) {
                    $widths[(string) ($col0 - 1 + $j)] = (int) floor($c['width'] / $sum * 64 * \count($cols) + 0.5);
                }
            }
            $row += \count($it['rows']);
        }

        $sheet = ['cells' => $cells];
        if ($merged !== []) {
            $sheet['mergedRegions'] = $merged;
        }
        if ($widths !== []) {
            $sheet['columnWidths'] = $widths;
        }

        return ['ok' => true, 'sheet' => $sheet, 'report' => $rep->build()];
    }

    private static function addr(int $col, int $row): string
    {
        return Range::addr($col, $row);
    }

    /** @param list<array<string,mixed>> $cols */
    private static function allWidths(array $cols): bool
    {
        foreach ($cols as $c) {
            if (! \array_key_exists('width', $c)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string,mixed>> $runs */
    private static function styled(array $runs): bool
    {
        foreach ($runs as $r) {
            foreach (['bold', 'italic', 'underline', 'strike', 'code'] as $f) {
                if (($r[$f] ?? false) === true) {
                    return true;
                }
            }
            if (isset($r['link'])) {
                return true;
            }
        }

        return false;
    }
}
