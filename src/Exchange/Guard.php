<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * Injection guard, v1 (SPEC 10): strip the characters fine-print's
 * `AgentReadGuard::INVISIBLE` matches from every string taken from a source,
 * and count one `invisible-chars` finding per maximal run removed.
 *
 * The count is all that is kept. A report never carries the text.
 */
final class Guard
{
    /** Zero-width, bidi embeddings/overrides/isolates, word joiners, BOM, Mongolian vowel separator, Arabic letter mark, Unicode tags. */
    public const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{180E}\x{061C}\x{E0000}-\x{E007F}]+/u';

    /** @var array<string,int> */
    private array $counts = [];

    public function strip(string $s): string
    {
        $s = Json::scrub($s);
        $n = 0;
        $out = preg_replace(self::INVISIBLE, '', $s, -1, $n);
        if ($n > 0) {
            $this->add('invisible-chars', $n);
        }

        return $out ?? $s;
    }

    public function add(string $rule, int $n): void
    {
        $this->counts[$rule] = ($this->counts[$rule] ?? 0) + $n;
    }

    /** @return list<array{rule:string,count:int}> */
    public function findings(): array
    {
        $out = [];
        foreach (Json::sortedKeys($this->counts) as $rule) {
            $out[] = ['rule' => $rule, 'count' => $this->counts[$rule]];
        }

        return $out;
    }
}
