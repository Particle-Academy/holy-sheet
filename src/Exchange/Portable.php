<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * Portable content (`fancyContent: 1`): validation (SPEC 7), limits (SPEC 9)
 * and the HTML-style table grid (SPEC 3.1).
 *
 * Every object is CLOSED. Hostile content is the expected case, and an open
 * object is a place to hide things. The first failure is reported, in the
 * order SPEC 7 lists.
 */
final class Portable
{
    public const DEFAULT_LIMITS = [
        'items' => 5000, 'rows' => 2000, 'columns' => 64, 'cells' => 10000, 'images' => 16,
        'imageBytes' => 5242880, 'stringBytes' => 4194304, 'cellBytes' => 32768,
    ];

    public const LINK_PATTERN = '/^(https?:\/\/|mailto:)[!-~]+$/';

    private const RUN_FLAGS = ['bold', 'italic', 'underline', 'strike', 'code'];

    private const CELL_KEYS = ['t', 'v', 'text', 'format', 'formula', 'colSpan', 'rowSpan', 'align', 'runs'];

    private const TYPES = ['string', 'number', 'bool', 'date', 'datetime', 'empty', 'error'];

    private const ALIGN = ['left', 'center', 'right'];

    /**
     * `validateContent(content, options?)`.
     *
     * @return array{ok:true}|array{ok:false,code:string,path:string}
     *
     * @throws OptionsInvalid for unknown options or limits, or a limit that is not a positive integer
     */
    public static function validateContent(mixed $content, mixed $options = null): array
    {
        $limits = self::resolveLimits(self::arrays($options));
        $content = self::prepare($content);
        try {
            self::structural($content);
        } catch (Invalid $e) {
            return ['ok' => false, 'code' => 'content_invalid', 'path' => $e->path];
        } finally {
            self::$emptyObjects = [];
            self::$emptyLists = [];
        }
        $lf = self::limitFailure($content, $limits);

        return $lf === null ? ['ok' => true] : ['ok' => false, 'code' => $lf[0], 'path' => $lf[1]];
    }

    /**
     * Resolve `{limits: {...}}` over the defaults.
     *
     * @return array<string,int>
     *
     * @throws OptionsInvalid
     */
    public static function resolveLimits(mixed $options): array
    {
        $limits = self::DEFAULT_LIMITS;
        if ($options === null) {
            return $limits;
        }
        if (! Json::isObj($options)) {
            throw new OptionsInvalid('options must be an object');
        }
        foreach (array_keys($options) as $k) {
            if ($k !== 'limits') {
                throw new OptionsInvalid("unknown option {$k}");
            }
        }

        return self::mergeLimits($limits, $options['limits'] ?? null);
    }

    /**
     * @param  array<string,int>  $limits
     * @return array<string,int>
     *
     * @throws OptionsInvalid
     */
    public static function mergeLimits(array $limits, mixed $given): array
    {
        if ($given === null) {
            return $limits;
        }
        if (! Json::isObj($given)) {
            throw new OptionsInvalid('limits must be an object');
        }
        foreach ($given as $k => $v) {
            if (! \array_key_exists((string) $k, self::DEFAULT_LIMITS) || ! Json::isInt($v) || $v <= 0) {
                throw new OptionsInvalid("bad limit {$k}");
            }
            $limits[(string) $k] = (int) $v;
        }

        return $limits;
    }

    /**
     * Cut a cell string to the longest prefix of whole code points that fits
     * in `$maxBytes` UTF-8 bytes.
     *
     * @return array{text:string,truncated:bool}
     */
    public static function truncateCell(string $text, int $maxBytes = 32768): array
    {
        if (\strlen($text) <= $maxBytes) {
            return ['text' => $text, 'truncated' => false];
        }
        $cut = substr($text, 0, $maxBytes);
        // Back off any partial trailing code point: drop continuation bytes, then
        // the lead byte they belonged to if the sequence was incomplete.
        $i = \strlen($cut);
        $j = $i;
        while ($j > 0 && (\ord($cut[$j - 1]) & 0xC0) === 0x80) {
            $j--;
        }
        if ($j > 0) {
            $lead = \ord($cut[$j - 1]);
            $need = $lead < 0x80 ? 1 : ($lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : 2));
            if ($i - ($j - 1) < $need) {
                $cut = substr($cut, 0, $j - 1);
            }
        }

        return ['text' => $cut, 'truncated' => true];
    }

    /**
     * Lay a table's rows out HTML-style.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array{ok:bool,badRow:?int,width:int,anchors:list<list<array{0:int,1:int}>>}
     */
    public static function layout(array $rows): array
    {
        $covered = [];
        $anchors = [];
        $width = -1;
        $n = \count($rows);
        foreach (array_values($rows) as $r => $row) {
            $occ = $covered[$r] ?? [];
            $coveredCount = \count($occ);
            $placed = [];
            $c = 0;
            $span = 0;
            foreach ((\is_array($row['cells'] ?? null) ? $row['cells'] : []) as $cell) {
                while (isset($occ[$c])) {
                    $c++;
                }
                $cs = (int) ($cell['colSpan'] ?? 1);
                $rs = (int) ($cell['rowSpan'] ?? 1);
                if ($r + $rs > $n) {
                    return ['ok' => false, 'badRow' => $r, 'width' => $width, 'anchors' => $anchors];
                }
                $placed[] = [$r, $c];
                for ($dr = 0; $dr < $rs; $dr++) {
                    for ($dc = 0; $dc < $cs; $dc++) {
                        if ($dr === 0) {
                            $occ[$c + $dc] = true;
                        } else {
                            $covered[$r + $dr][$c + $dc] = true;
                        }
                    }
                }
                $span += $cs;
                $c += $cs;
            }
            $anchors[] = $placed;
            $max = $occ === [] ? 0 : max(array_keys($occ)) + 1;
            $w = $coveredCount + $span;
            if ($w !== $max) {
                return ['ok' => false, 'badRow' => $r, 'width' => $width, 'anchors' => $anchors];
            }
            if ($width === -1) {
                $width = $w;
            } elseif ($w !== $width) {
                return ['ok' => false, 'badRow' => $r, 'width' => $width, 'anchors' => $anchors];
            }
        }

        return ['ok' => true, 'badRow' => null, 'width' => max($width, 0), 'anchors' => $anchors];
    }

    /**
     * The limit checks of SPEC 9, in order: `[code, path]` or null.
     *
     * @param  array<string,int>  $limits
     * @return array{0:string,1:string}|null
     */
    public static function limitFailure(array $c, array $limits): ?array
    {
        $items = \is_array($c['items'] ?? null) ? array_values($c['items']) : [];
        if (\count($items) > $limits['items']) {
            return ['content_too_many_items', '/items'];
        }
        $tables = [];
        foreach ($items as $i => $it) {
            if (($it['type'] ?? null) === 'table') {
                $tables[$i] = $it;
            }
        }
        foreach ($tables as $i => $t) {
            if (\count($t['rows']) > $limits['rows']) {
                return ['content_too_many_rows', "/items/{$i}"];
            }
        }
        $widths = [];
        foreach ($tables as $i => $t) {
            $widths[$i] = self::layout($t['rows'])['width'];
            if ($widths[$i] > $limits['columns']) {
                return ['content_too_many_columns', "/items/{$i}"];
            }
        }
        $slots = 0;
        foreach ($tables as $i => $t) {
            $slots += \count($t['rows']) * $widths[$i];
        }
        if ($slots > $limits['cells']) {
            return ['content_too_many_cells', '/items'];
        }
        $images = \is_array($c['images'] ?? null) ? $c['images'] : [];
        if (\count($images) > $limits['images']) {
            return ['content_too_many_images', '/images'];
        }
        foreach (Json::sortedKeys($images) as $k) {
            $bytes = self::decodeBase64($images[$k]['data'] ?? null);
            if ($bytes !== null && \strlen($bytes) > $limits['imageBytes']) {
                return ['image_too_large', '/images/'.Json::esc($k)];
            }
        }
        if (self::stringBytes($items) > $limits['stringBytes']) {
            return ['content_too_large', ''];
        }

        return null;
    }

    /** UTF-8 bytes of every string VALUE under `$v` (keys excluded). */
    public static function stringBytes(mixed $v): int
    {
        if (\is_string($v)) {
            return \strlen($v);
        }
        if (\is_array($v)) {
            $n = 0;
            foreach ($v as $x) {
                $n += self::stringBytes($x);
            }

            return $n;
        }

        return 0;
    }

    public static function decodeBase64(mixed $s): ?string
    {
        if (! \is_string($s) || \strlen($s) % 4 !== 0 || preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $s) !== 1) {
            return null;
        }
        $out = base64_decode($s, true);

        return $out === false ? null : $out;
    }

    public static function linkOk(mixed $link): bool
    {
        return \is_string($link) && \strlen($link) <= 2048 && preg_match(self::LINK_PATTERN, $link) === 1;
    }

    public static function isDate(mixed $v): bool
    {
        return \is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1
            && self::dateOk((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    public static function isDateTime(mixed $v): bool
    {
        return \is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z$/', $v, $m) === 1
            && self::dateOk((int) $m[1], (int) $m[2], (int) $m[3]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60;
    }

    private static function dateOk(int $y, int $mo, int $d): bool
    {
        if ($mo < 1 || $mo > 12) {
            return false;
        }
        $leap = ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0;
        $max = [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$mo - 1];

        return $d >= 1 && $d <= $max;
    }

    /** The display string of a cell: its `text`, else canonical(v). */
    public static function displayOf(array $cell): string
    {
        return \is_string($cell['text'] ?? null) ? $cell['text'] : Display::canonical((string) $cell['t'], $cell['v'] ?? null);
    }

    // -- structural validation ------------------------------------------------

    /** @var array<string,true> pointers where an empty JSON object stood (object-mode input only) */
    private static array $emptyObjects = [];

    /** @var array<string,true> pointers where an empty JSON list stood (object-mode input only) */
    private static array $emptyLists = [];

    /**
     * Content as arrays. Object-mode JSON (`json_decode($s)`) is accepted too,
     * and keeps the one distinction assoc decoding loses: `{}` against `[]`.
     */
    private static function prepare(mixed $content): mixed
    {
        self::$emptyObjects = [];
        self::$emptyLists = [];
        if (! Json::hasObjects($content)) {
            return $content;
        }

        return Json::fromObjects($content, self::$emptyObjects, self::$emptyLists);
    }

    /** Content (or options) as plain arrays, for callers past validation. */
    public static function arrays(mixed $x): mixed
    {
        if (! Json::hasObjects($x)) {
            return $x;
        }
        $o = [];
        $l = [];

        return Json::fromObjects($x, $o, $l);
    }

    private static function obj(mixed $x, string $path): bool
    {
        return Json::isObj($x) && ! isset(self::$emptyLists[$path]);
    }

    private static function list(mixed $x, string $path): bool
    {
        return Json::isList($x) && ! isset(self::$emptyObjects[$path]);
    }

    /** @throws Invalid */
    private static function structural(mixed $c): void
    {
        if (! self::obj($c, '')) {
            throw new Invalid('');
        }
        if (! \array_key_exists('fancyContent', $c) || ! Json::isInt($c['fancyContent']) || $c['fancyContent'] != 1) {
            throw new Invalid('/fancyContent');
        }
        self::checkKeys($c, ['fancyContent', 'source', 'items', 'images', 'report'], '');

        $images = \array_key_exists('images', $c) && Json::isObj($c['images']) ? $c['images'] : [];
        if (\array_key_exists('images', $c)) {
            if (! self::obj($c['images'], '/images')) {
                throw new Invalid('/images');
            }
            foreach (Json::sortedKeys($c['images']) as $k) {
                $ip = '/images/'.Json::esc($k);
                if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $k) !== 1) {
                    throw new Invalid($ip);
                }
                $e = $c['images'][$k];
                if (! self::obj($e, $ip)) {
                    throw new Invalid($ip);
                }
                self::checkKeys($e, ['mime', 'data'], $ip);
                $mime = $e['mime'] ?? null;
                if (! \in_array($mime, ['image/png', 'image/jpeg'], true)) {
                    throw new Invalid("{$ip}/mime");
                }
                $bytes = self::decodeBase64($e['data'] ?? null);
                if ($bytes === null || ! self::magicOk($mime, $bytes)) {
                    throw new Invalid("{$ip}/data");
                }
            }
        }

        if (! self::list($c['items'] ?? null, '/items')) {
            throw new Invalid('/items');
        }
        foreach ($c['items'] as $i => $it) {
            self::checkItem($it, "/items/{$i}", $images);
        }

        if (\array_key_exists('images', $c)) {
            $used = [];
            foreach ($c['items'] as $it) {
                if (Json::isObj($it) && ($it['type'] ?? null) === 'image') {
                    $used[(string) $it['ref']] = true;
                }
            }
            foreach (Json::sortedKeys($c['images']) as $k) {
                if (! isset($used[$k])) {
                    throw new Invalid('/images/'.Json::esc($k));
                }
            }
        }

        if (\array_key_exists('source', $c)) {
            $s = $c['source'];
            if (! self::obj($s, '/source')) {
                throw new Invalid('/source');
            }
            self::checkKeys($s, ['package', 'kind', 'version', 'sourceKind', 'selector'], '/source');
            foreach (['package', 'kind', 'version'] as $k) {
                if (\array_key_exists($k, $s) && ! \is_string($s[$k])) {
                    throw new Invalid("/source/{$k}");
                }
            }
            if (\array_key_exists('sourceKind', $s) && ! \in_array($s['sourceKind'], ['document', 'model'], true)) {
                throw new Invalid('/source/sourceKind');
            }
        }
        if (\array_key_exists('report', $c)) {
            self::checkReport($c['report']);
        }
    }

    private static function magicOk(string $mime, string $bytes): bool
    {
        $magic = $mime === 'image/png' ? "\x89PNG\r\n\x1A\n" : "\xFF\xD8\xFF";

        return str_starts_with($bytes, $magic);
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws Invalid
     */
    private static function checkKeys(array $o, array $allowed, string $path): void
    {
        foreach (Json::sortedKeys($o) as $k) {
            if (! \in_array($k, $allowed, true)) {
                throw new Invalid($path.'/'.Json::esc($k));
            }
        }
    }

    /** @throws Invalid */
    private static function checkRun(mixed $run, string $p): void
    {
        if (! self::obj($run, $p)) {
            throw new Invalid($p);
        }
        self::checkKeys($run, ['text', ...self::RUN_FLAGS, 'link'], $p);
        if (! \is_string($run['text'] ?? null)) {
            throw new Invalid("{$p}/text");
        }
        foreach (self::RUN_FLAGS as $f) {
            if (\array_key_exists($f, $run) && ! \is_bool($run[$f])) {
                throw new Invalid("{$p}/{$f}");
            }
        }
        if (\array_key_exists('link', $run) && ! self::linkOk($run['link'])) {
            throw new Invalid("{$p}/link");
        }
    }

    /** @throws Invalid */
    private static function checkRuns(mixed $runs, string $p): void
    {
        if (! self::list($runs, $p)) {
            throw new Invalid($p);
        }
        foreach ($runs as $i => $r) {
            self::checkRun($r, "{$p}/{$i}");
        }
    }

    /** @throws Invalid */
    private static function checkCell(mixed $cell, string $p): void
    {
        if (! self::obj($cell, $p)) {
            throw new Invalid($p);
        }
        $t = $cell['t'] ?? null;
        if (! \in_array($t, self::TYPES, true)) {
            throw new Invalid("{$p}/t");
        }
        self::checkKeys($cell, self::CELL_KEYS, $p);
        $has = \array_key_exists('v', $cell);
        $v = $cell['v'] ?? null;
        $vOk = match (true) {
            $t === 'empty' => ! $has,
            ! $has => false,
            $t === 'string' => \is_string($v),
            $t === 'number' => Json::isNum($v),
            $t === 'bool' => \is_bool($v),
            $t === 'date' => self::isDate($v),
            $t === 'datetime' => self::isDateTime($v),
            default => \is_string($v) && str_starts_with($v, '#'),
        };
        if (! $vOk) {
            throw new Invalid("{$p}/v");
        }
        if (\array_key_exists('text', $cell) && ! \is_string($cell['text'])) {
            throw new Invalid("{$p}/text");
        }
        if (\array_key_exists('format', $cell)) {
            $f = $cell['format'];
            $fp = "{$p}/format";
            if (! self::obj($f, $fp)) {
                throw new Invalid($fp);
            }
            if (! \in_array($f['kind'] ?? null, ['number', 'currency', 'percent', 'date', 'datetime', 'text'], true)) {
                throw new Invalid("{$fp}/kind");
            }
            self::checkKeys($f, ['kind', 'decimals', 'currency'], $fp);
            if (\array_key_exists('decimals', $f) && ! (Json::isInt($f['decimals']) && $f['decimals'] >= 0 && $f['decimals'] <= 10)) {
                throw new Invalid("{$fp}/decimals");
            }
            if (\array_key_exists('currency', $f) && ! ($f['kind'] === 'currency' && \is_string($f['currency']) && preg_match('/^[A-Z]{3}$/', $f['currency']) === 1)) {
                throw new Invalid("{$fp}/currency");
            }
        }
        if (\array_key_exists('formula', $cell) && ! (\is_string($cell['formula']) && $cell['formula'] !== '')) {
            throw new Invalid("{$p}/formula");
        }
        foreach (['colSpan', 'rowSpan'] as $k) {
            if (\array_key_exists($k, $cell) && ! (Json::isInt($cell[$k]) && $cell[$k] >= 1)) {
                throw new Invalid("{$p}/{$k}");
            }
        }
        if (\array_key_exists('align', $cell) && ! \in_array($cell['align'], self::ALIGN, true)) {
            throw new Invalid("{$p}/align");
        }
        if (\array_key_exists('runs', $cell)) {
            self::checkRuns($cell['runs'], "{$p}/runs");
            $joined = implode('', array_map(static fn (array $r): string => $r['text'], $cell['runs']));
            if ($joined !== self::displayOf($cell)) {
                throw new Invalid("{$p}/runs");
            }
        }
    }

    /** @throws Invalid */
    private static function checkListItem(mixed $it, string $p): void
    {
        if (! self::obj($it, $p)) {
            throw new Invalid($p);
        }
        self::checkKeys($it, ['runs', 'children'], $p);
        self::checkRuns($it['runs'] ?? null, "{$p}/runs");
        if (\array_key_exists('children', $it)) {
            if (! self::list($it['children'], "{$p}/children")) {
                throw new Invalid("{$p}/children");
            }
            foreach ($it['children'] as $i => $c) {
                self::checkListItem($c, "{$p}/children/{$i}");
            }
        }
    }

    /**
     * @param  array<array-key,mixed>  $images
     *
     * @throws Invalid
     */
    private static function checkItem(mixed $item, string $p, array $images): void
    {
        if (! self::obj($item, $p)) {
            throw new Invalid($p);
        }
        $type = $item['type'] ?? null;
        if (! \in_array($type, ['table', 'text', 'list', 'image'], true)) {
            throw new Invalid("{$p}/type");
        }
        if ($type === 'text') {
            if (! \in_array($item['role'] ?? null, ['heading', 'paragraph'], true)) {
                throw new Invalid("{$p}/role");
            }
            self::checkKeys($item, ['type', 'role', 'level', 'runs'], $p);
            $levelOk = $item['role'] === 'heading'
                ? Json::isInt($item['level'] ?? null) && $item['level'] >= 1 && $item['level'] <= 6
                : ! \array_key_exists('level', $item);
            if (! $levelOk) {
                throw new Invalid("{$p}/level");
            }
            self::checkRuns($item['runs'] ?? null, "{$p}/runs");

            return;
        }
        if ($type === 'list') {
            self::checkKeys($item, ['type', 'ordered', 'items'], $p);
            if (\array_key_exists('ordered', $item) && ! \is_bool($item['ordered'])) {
                throw new Invalid("{$p}/ordered");
            }
            if (! self::list($item['items'] ?? null, "{$p}/items") || $item['items'] === []) {
                throw new Invalid("{$p}/items");
            }
            foreach ($item['items'] as $i => $it) {
                self::checkListItem($it, "{$p}/items/{$i}");
            }

            return;
        }
        if ($type === 'image') {
            self::checkKeys($item, ['type', 'ref', 'alt', 'widthPx', 'heightPx'], $p);
            if (! \is_string($item['ref'] ?? null) || ! \array_key_exists($item['ref'], $images)) {
                throw new Invalid("{$p}/ref");
            }
            if (\array_key_exists('alt', $item) && ! \is_string($item['alt'])) {
                throw new Invalid("{$p}/alt");
            }
            foreach (['widthPx', 'heightPx'] as $k) {
                if (\array_key_exists($k, $item) && ! (Json::isNum($item[$k]) && $item[$k] > 0)) {
                    throw new Invalid("{$p}/{$k}");
                }
            }

            return;
        }

        self::checkKeys($item, ['type', 'caption', 'headerRows', 'columns', 'rows'], $p);
        if (\array_key_exists('caption', $item) && ! \is_string($item['caption'])) {
            throw new Invalid("{$p}/caption");
        }
        if (\array_key_exists('headerRows', $item)) {
            if (! (Json::isInt($item['headerRows']) && $item['headerRows'] >= 0)) {
                throw new Invalid("{$p}/headerRows");
            }
            if (self::list($item['rows'] ?? null, "{$p}/rows") && $item['headerRows'] > \count($item['rows'])) {
                throw new Invalid("{$p}/headerRows");
            }
        }
        if (\array_key_exists('columns', $item)) {
            if (! self::list($item['columns'], "{$p}/columns")) {
                throw new Invalid("{$p}/columns");
            }
            foreach ($item['columns'] as $i => $col) {
                $cp = "{$p}/columns/{$i}";
                if (! self::obj($col, $cp)) {
                    throw new Invalid($cp);
                }
                self::checkKeys($col, ['width', 'align'], $cp);
                if (\array_key_exists('width', $col) && ! (Json::isNum($col['width']) && $col['width'] > 0)) {
                    throw new Invalid("{$cp}/width");
                }
                if (\array_key_exists('align', $col) && ! \in_array($col['align'], self::ALIGN, true)) {
                    throw new Invalid("{$cp}/align");
                }
            }
        }
        if (! self::list($item['rows'] ?? null, "{$p}/rows") || $item['rows'] === []) {
            throw new Invalid("{$p}/rows");
        }
        foreach ($item['rows'] as $ri => $r) {
            $rp = "{$p}/rows/{$ri}";
            if (! self::obj($r, $rp)) {
                throw new Invalid($rp);
            }
            self::checkKeys($r, ['cells'], $rp);
            if (! self::list($r['cells'] ?? null, "{$rp}/cells") || $r['cells'] === []) {
                throw new Invalid("{$rp}/cells");
            }
            foreach ($r['cells'] as $ci => $cell) {
                self::checkCell($cell, "{$rp}/cells/{$ci}");
            }
        }
        $g = self::layout($item['rows']);
        if (! $g['ok']) {
            throw new Invalid("{$p}/rows/{$g['badRow']}");
        }
        if (\array_key_exists('columns', $item) && \count($item['columns']) !== $g['width']) {
            throw new Invalid("{$p}/columns");
        }
    }

    /** @throws Invalid */
    private static function checkReport(mixed $rep): void
    {
        $p = '/report';
        if (! self::obj($rep, $p)) {
            throw new Invalid($p);
        }
        self::checkKeys($rep, ['dropped', 'approximated', 'guard'], $p);
        foreach (['dropped', 'approximated'] as $list) {
            if (! \array_key_exists($list, $rep)) {
                continue;
            }
            $lp = "{$p}/{$list}";
            if (! self::list($rep[$list], $lp)) {
                throw new Invalid($lp);
            }
            foreach ($rep[$list] as $i => $e) {
                $ep = "{$lp}/{$i}";
                if (! self::obj($e, $ep)) {
                    throw new Invalid($ep);
                }
                self::checkKeys($e, ['kind', 'count', 'where'], $ep);
                if (Report::classifyKind($e['kind'] ?? null) !== $list) {
                    throw new Invalid("{$ep}/kind");
                }
                if (! (Json::isInt($e['count'] ?? null) && $e['count'] >= 1)) {
                    throw new Invalid("{$ep}/count");
                }
                if (\array_key_exists('where', $e)) {
                    $w = $e['where'];
                    $ok = self::list($w, "{$ep}/where") && \count($w) <= Report::WHERE_CAP;
                    foreach ($ok ? $w : [] as $x) {
                        $ok = $ok && \is_string($x);
                    }
                    if (! $ok) {
                        throw new Invalid("{$ep}/where");
                    }
                }
            }
        }
        if (\array_key_exists('guard', $rep)) {
            $g = $rep['guard'];
            if (! self::obj($g, "{$p}/guard")) {
                throw new Invalid("{$p}/guard");
            }
            self::checkKeys($g, ['findings'], "{$p}/guard");
            if (! self::list($g['findings'] ?? null, "{$p}/guard/findings")) {
                throw new Invalid("{$p}/guard/findings");
            }
            foreach ($g['findings'] as $i => $f) {
                $fp = "{$p}/guard/findings/{$i}";
                if (! self::obj($f, $fp)) {
                    throw new Invalid($fp);
                }
                self::checkKeys($f, ['rule', 'count'], $fp);
                if (! \is_string($f['rule'] ?? null) || $f['rule'] === '') {
                    throw new Invalid("{$fp}/rule");
                }
                if (! (Json::isInt($f['count'] ?? null) && $f['count'] >= 1)) {
                    throw new Invalid("{$fp}/count");
                }
            }
        }
    }
}
