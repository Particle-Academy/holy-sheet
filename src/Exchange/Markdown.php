<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * Portable content -> GitHub-flavoured markdown tables.
 *
 * Every cell is its display string with markdown's inline punctuation
 * backslash-escaped, so document text can never become a link, an image, raw
 * HTML or a broken table. Line breaks inside a cell become spaces. A caption is
 * a level-3 heading above its table.
 *
 * GFM has no spans and exactly one header row: a span writes its value in the
 * anchor and leaves the covered slots empty (`merge-flattened`); header rows
 * after the first become body rows (`header-rows-flattened`); a table with no
 * header row gets an empty one. Typed values become display text
 * (`typed-as-text`) and formulas their values (`formula-as-value`), as in
 * every conversion to a text format.
 */
final class Markdown
{
    /** Inline-significant ASCII punctuation; escaping is always safe in GFM. */
    private const ESCAPE = ['\\', '`', '*', '_', '[', ']', '<', '>', '|', '~', '&', '!', '#'];

    /**
     * @param  array<string,mixed>  $content  valid portable content
     * @return array{text:string,report:array<string,mixed>}
     */
    public static function fromPortable(array $content): array
    {
        $rep = new Report();
        $blocks = [];
        foreach ($content['items'] as $i => $it) {
            $p = "/items/{$i}";
            if ($it['type'] !== 'table') {
                $rep->add('non-table-item', $p);

                continue;
            }
            if (isset($it['caption'])) {
                $blocks[] = '### '.self::escape($it['caption']);
            }
            $blocks[] = self::table($it, $p, $rep);
        }

        return ['text' => $blocks === [] ? '' : implode("\n\n", $blocks)."\n", 'report' => $rep->build()];
    }

    /** @param array<string,mixed> $t */
    private static function table(array $t, string $p, Report $rep): string
    {
        $rows = $t['rows'];
        $g = Portable::layout($rows);
        $width = max(1, $g['width']);
        $grid = array_fill(0, \count($rows), array_fill(0, $width, ''));
        $numeric = array_fill(0, $width, true);
        $seen = array_fill(0, $width, false);
        $headerRows = (int) ($t['headerRows'] ?? 0);

        foreach ($rows as $ri => $r) {
            foreach ($r['cells'] as $ci => $cell) {
                $cp = "{$p}/rows/{$ri}/cells/{$ci}";
                [$ar, $ac] = $g['anchors'][$ri][$ci];
                if (($cell['colSpan'] ?? 1) > 1 || ($cell['rowSpan'] ?? 1) > 1) {
                    $rep->add('merge-flattened', $cp);
                }
                if (\array_key_exists('formula', $cell)) {
                    $rep->add('formula-as-value', $cp);
                }
                $t2 = $cell['t'];
                if ($t2 !== 'string' && $t2 !== 'empty') {
                    $rep->add('typed-as-text', $cp);
                }
                if ($ri >= $headerRows && $t2 !== 'empty') {
                    $seen[$ac] = true;
                    $numeric[$ac] = $numeric[$ac] && $t2 === 'number';
                }
                $text = self::escape(Portable::displayOf($cell));
                $run = $cell['runs'][0] ?? null;
                if ($text !== '' && \is_array($run) && \count($cell['runs']) === 1) {
                    if (! empty($run['bold'])) {
                        $text = '**'.$text.'**';
                    }
                    if (! empty($run['italic'])) {
                        $text = '*'.$text.'*';
                    }
                }
                $grid[$ar][$ac] = $text;
            }
        }

        for ($r = 1; $r < $headerRows; $r++) {
            $rep->add('header-rows-flattened', "{$p}/rows/{$r}");
        }
        $header = $headerRows >= 1 ? array_shift($grid) : array_fill(0, $width, '');
        $align = [];
        for ($c = 0; $c < $width; $c++) {
            $align[] = $seen[$c] && $numeric[$c] ? '---:' : '---';
        }
        $line = static fn (array $cells): string => '| '.implode(' | ', $cells).' |';
        $out = [$line($header), $line($align)];
        foreach ($grid as $row) {
            $out[] = $line($row);
        }

        return implode("\n", $out);
    }

    public static function escape(string $s): string
    {
        $s = str_replace(["\r\n", "\r", "\n"], ' ', $s);
        $out = '';
        $len = \strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $out .= (\in_array($s[$i], self::ESCAPE, true) ? '\\' : '').$s[$i];
        }

        return $out;
    }
}
