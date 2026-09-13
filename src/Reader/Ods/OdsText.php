<?php

declare(strict_types=1);

namespace HolySheet\Reader\Ods;

use SimpleXMLElement;

/**
 * The text of a cell or annotation: its paragraphs, one line each.
 *
 * A paragraph is mixed content — runs, links, `text:s` for extra spaces,
 * `text:tab`, `text:line-break` — and its meaning depends on ORDER, which
 * SimpleXML does not expose. So each paragraph is serialised and walked as a
 * token stream. Inside a paragraph, elements are matched by local name: the
 * only elements that change the text are all in the text namespace, and no
 * other namespace defines one with the same name.
 *
 * White space follows ODF 1.3 §6.1.2, the way LibreOffice applies it: a run of
 * space, tab, CR and LF characters in the XML is ONE space, and white space at
 * the start of a paragraph or straight after another collapsed space is
 * dropped. Spaces that matter are written as `text:s`, which is never collapsed.
 */
final class OdsText
{
    /** Elements whose text is not part of the paragraph (a note, a comment). */
    private const SKIPPED = ['annotation', 'note'];

    /**
     * The `text:p` / `text:h` children joined with "\n", or null when there are none.
     */
    public static function paragraphs(SimpleXMLElement $element): ?string
    {
        $lines = [];
        foreach ($element->children(Ns::TEXT) as $name => $paragraph) {
            if ($name === 'p' || $name === 'h') {
                $lines[] = self::paragraph($paragraph);
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    public static function paragraph(SimpleXMLElement $paragraph): string
    {
        $xml = (string) $paragraph->asXML();

        preg_match_all(
            '/<!--.*?-->|<!\[CDATA\[(.*?)\]\]>|<\?.*?\?>|<(\/?)(?:[^\s\/>:]+:)?([^\s\/>]+)([^>]*?)(\/?)>|([^<]+)/s',
            $xml,
            $tokens,
            PREG_SET_ORDER,
        );

        $out = '';
        $depth = 0;          // element depth, the paragraph itself being 1
        $skipUntil = null;   // depth at which a skipped element closes
        $ignoreSpace = true; // at the start of a paragraph, white space is dropped

        foreach ($tokens as $t) {
            $isText = isset($t[6]) && $t[6] !== '';
            $isCdata = !$isText && isset($t[1]) && $t[1] !== '' && ($t[3] ?? '') === '';

            if ($isText || $isCdata) {
                if ($skipUntil !== null) continue;
                $chars = $isText ? html_entity_decode($t[6], ENT_QUOTES | ENT_XML1, 'UTF-8') : $t[1];
                self::appendCharacters($out, $chars, $ignoreSpace);
                continue;
            }

            $name = $t[3] ?? '';
            if ($name === '') continue; // a comment or processing instruction

            $closing = $t[2] === '/';
            $selfClosing = $t[5] === '/';

            if ($closing) {
                if ($skipUntil === $depth) $skipUntil = null;
                $depth--;
                continue;
            }

            $depth++;
            if ($skipUntil === null && $depth > 1) {
                if (in_array($name, self::SKIPPED, true)) {
                    $skipUntil = $depth;
                } elseif ($name === 's') {
                    $count = preg_match('/(?:^|\s)(?:[^\s=:]+:)?c\s*=\s*["\'](\d+)["\']/', $t[4], $m) === 1 ? (int) $m[1] : 1;
                    $out .= str_repeat(' ', max(1, $count));
                    $ignoreSpace = false;
                } elseif ($name === 'tab') {
                    $out .= "\t";
                    $ignoreSpace = false;
                } elseif ($name === 'line-break') {
                    $out .= "\n";
                    $ignoreSpace = false;
                }
            }

            if ($selfClosing) {
                if ($skipUntil === $depth) $skipUntil = null;
                $depth--;
            }
        }

        return $out;
    }

    private static function appendCharacters(string &$out, string $chars, bool &$ignoreSpace): void
    {
        $length = strlen($chars);
        for ($i = 0; $i < $length; $i++) {
            $c = $chars[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                if (!$ignoreSpace) {
                    $out .= ' ';
                    $ignoreSpace = true;
                }
                continue;
            }
            $out .= $c;
            $ignoreSpace = false;
        }
    }
}
