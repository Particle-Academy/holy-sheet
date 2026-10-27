<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * One portable table -> RFC 4180 CSV (SPEC 12).
 *
 * Formula-injection escaping is ON by default: a `string` or `error` field
 * beginning with `=`, `+`, `-`, `@`, TAB or CR is prefixed with `'` before it
 * is quoted, and reported `csv-escaped`. Numbers are never escaped. Only the
 * HOST turns it off (`escapeFormulas: false`).
 */
final class Csv
{
    private const LEADS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * `toCsv(table, options?)`; options `{escapeFormulas?: bool = true, display?: bool = false}`.
     *
     * @param  array<string,mixed>  $table
     * @return array{text:string,report:array<string,mixed>}
     *
     * @throws OptionsInvalid
     */
    public static function toCsv(array $table, ?array $options = null): array
    {
        $options ??= [];
        foreach ($options as $k => $v) {
            if (! \in_array($k, ['escapeFormulas', 'display'], true) || ! \is_bool($v)) {
                throw new OptionsInvalid("bad csv option {$k}");
            }
        }
        $escape = ($options['escapeFormulas'] ?? true) !== false;
        $showDisplay = ($options['display'] ?? false) === true;

        $rep = new Report();
        $rows = array_values($table['rows'] ?? []);
        $g = Portable::layout($rows);
        $grid = array_fill(0, \count($rows), array_fill(0, $g['width'], ''));

        foreach ($rows as $ri => $r) {
            foreach (array_values($r['cells']) as $ci => $cell) {
                $cp = "/rows/{$ri}/cells/{$ci}";
                [$ar, $ac] = $g['anchors'][$ri][$ci];
                if (($cell['colSpan'] ?? 1) > 1 || ($cell['rowSpan'] ?? 1) > 1) {
                    $rep->add('merge-flattened', $cp);
                }
                $t = (string) $cell['t'];
                if ($showDisplay) {
                    $f = \is_string($cell['text'] ?? null)
                        ? $cell['text']
                        : (isset($cell['format']) ? Display::display($t, $cell['v'] ?? null, $cell['format']) : Display::canonical($t, $cell['v'] ?? null));
                } else {
                    $f = Display::canonical($t, $cell['v'] ?? null);
                }
                if ($escape && ($t === 'string' || $t === 'error') && $f !== '' && \in_array($f[0], self::LEADS, true)) {
                    $f = "'".$f;
                    $rep->add('csv-escaped', $cp);
                }
                if (strpbrk($f, "\",\r\n") !== false) {
                    $f = '"'.str_replace('"', '""', $f).'"';
                }
                $grid[$ar][$ac] = $f;
            }
        }

        $text = '';
        foreach ($grid as $line) {
            $text .= implode(',', $line)."\r\n";
        }

        return ['text' => $text, 'report' => $rep->build()];
    }
}
