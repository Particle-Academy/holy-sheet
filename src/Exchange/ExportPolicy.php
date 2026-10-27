<?php

declare(strict_types=1);

namespace HolySheet\Exchange;

/**
 * Which exports a HOST allows (SPEC 13). Exports are OFF by default.
 *
 * A policy is built by the host, never taken from an agent: a null policy
 * allows nothing, and an `allow` in the agent's options is an unknown option
 * that throws. Nothing an agent passes can widen what this allows.
 *
 * JSON form, identical in every language:
 * `{"allow": ["csv"], "limits": {...}, "csv": {"escapeFormulas": true}, "includeHidden": false, "pdf": {...}, "modules": {"fine-print": false}}`
 */
final class ExportPolicy
{
    public const TARGETS = ['csv', 'markdown', 'text', 'html', 'outline', 'xlsx', 'docx', 'pptx', 'pdf', 'ods', 'odt'];

    /** Supported targets per reader, in the order an agent is shown them. */
    public const SUPPORTED = [
        'holy-sheet' => ['csv', 'markdown', 'xlsx', 'pdf', 'docx'],
        'last-word' => ['markdown', 'text', 'html', 'docx', 'pdf'],
        'dark-slide' => ['markdown', 'outline', 'text', 'pptx', 'pdf'],
        'fine-print' => ['text', 'markdown'],
    ];

    private const POLICY_KEYS = ['allow', 'limits', 'pdf', 'csv', 'includeHidden', 'modules'];

    /** What an agent may pass. */
    public const OPTION_KEYS = ['selector', 'headerRows', 'display'];

    /**
     * @param  list<string>  $allow
     * @param  array<string,int>  $limits
     * @param  array<string,mixed>  $csv
     * @param  array<string,mixed>  $pdf
     * @param  array<string,bool>  $modules
     */
    private function __construct(
        public readonly array $allow,
        public readonly array $limits,
        public readonly array $csv,
        public readonly bool $includeHidden,
        public readonly array $pdf,
        public readonly array $modules,
    ) {}

    /** Allow nothing. The default. */
    public static function none(): self
    {
        return new self([], [], [], false, [], []);
    }

    /**
     * Allow these targets (and nothing else).
     *
     * @param  list<string>  $targets
     */
    public static function allow(array $targets): self
    {
        return self::fromArray(['allow' => $targets]);
    }

    /**
     * Build from the JSON form. Throws on an unknown key, an unknown target,
     * or a non-array `allow`.
     *
     * @throws OptionsInvalid
     */
    public static function fromArray(mixed $p): self
    {
        if ($p === null) {
            return self::none();
        }
        if (! Json::isObj($p)) {
            throw new OptionsInvalid('policy must be an object');
        }
        foreach (array_keys($p) as $k) {
            if (! \in_array($k, self::POLICY_KEYS, true)) {
                throw new OptionsInvalid("unknown policy key {$k}");
            }
        }
        $allow = $p['allow'] ?? [];
        if (! Json::isList($allow)) {
            throw new OptionsInvalid('allow must be an array');
        }
        foreach ($allow as $t) {
            if (! \in_array($t, self::TARGETS, true)) {
                throw new OptionsInvalid('unknown export target');
            }
        }
        $limits = $p['limits'] ?? null;
        if ($limits !== null) {
            Portable::mergeLimits(Portable::DEFAULT_LIMITS, $limits);
        }
        $csv = $p['csv'] ?? [];
        if (! Json::isObj($csv)) {
            throw new OptionsInvalid('csv must be an object');
        }
        foreach ($csv as $k => $v) {
            if ($k !== 'escapeFormulas' || ! \is_bool($v)) {
                throw new OptionsInvalid("bad csv policy key {$k}");
            }
        }
        if (\array_key_exists('includeHidden', $p) && ! \is_bool($p['includeHidden'])) {
            throw new OptionsInvalid('includeHidden must be a boolean');
        }
        $pdf = $p['pdf'] ?? [];
        if (! Json::isObj($pdf)) {
            throw new OptionsInvalid('pdf must be an object');
        }
        $modules = $p['modules'] ?? [];
        if (! Json::isObj($modules)) {
            throw new OptionsInvalid('modules must be an object');
        }
        foreach ($modules as $k => $v) {
            if (! \is_bool($v)) {
                throw new OptionsInvalid("module {$k} must be a boolean");
            }
        }

        return new self(array_values(array_unique($allow)), $limits ?? [], $csv, (bool) ($p['includeHidden'] ?? false), $pdf, $modules);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = ['allow' => $this->allow];
        if ($this->limits !== []) {
            $out['limits'] = $this->limits;
        }
        if ($this->csv !== []) {
            $out['csv'] = $this->csv;
        }
        if ($this->includeHidden) {
            $out['includeHidden'] = true;
        }
        if ($this->pdf !== []) {
            $out['pdf'] = $this->pdf;
        }
        if ($this->modules !== []) {
            $out['modules'] = $this->modules;
        }

        return $out;
    }

    /** The module a target needs, if any. */
    public static function needs(string $reader, string $target): ?string
    {
        if ($target === 'pdf') {
            return 'fine-print';
        }

        return $reader === 'holy-sheet' && $target === 'docx' ? 'last-word' : null;
    }

    /** @param array<string,bool> $environment */
    public function available(string $module, array $environment): bool
    {
        return $this->modules[$module] ?? (($environment[$module] ?? false) === true);
    }

    /**
     * `checkExport({reader, target, policy, options?, environment, inputRefused?, limitCode?})`:
     * the first of export_unsupported, export_disabled, export_unavailable,
     * export_refused, a limit code.
     *
     * @param  array<string,mixed>  $req
     * @return array{ok:true}|array{ok:false,refused:array<string,string>}
     *
     * @throws OptionsInvalid
     */
    public static function checkExport(array $req): array
    {
        self::checkOptions($req['options'] ?? null);
        $policy = ($req['policy'] ?? null) instanceof self ? $req['policy'] : self::fromArray($req['policy'] ?? null);

        return $policy->check(
            (string) $req['reader'],
            (string) $req['target'],
            \is_array($req['environment'] ?? null) ? $req['environment'] : [],
            isset($req['inputRefused']) ? (string) $req['inputRefused'] : null,
            isset($req['limitCode']) ? (string) $req['limitCode'] : null,
        );
    }

    /**
     * @param  array<string,bool>  $environment
     * @return array{ok:true}|array{ok:false,refused:array<string,string>}
     */
    public function check(string $reader, string $target, array $environment, ?string $inputRefused = null, ?string $limitCode = null): array
    {
        $no = static fn (string $reason, array $extra = []): array => ['ok' => false, 'refused' => ['reason' => $reason, 'target' => $target] + $extra];

        if (! \in_array($target, self::SUPPORTED[$reader] ?? [], true)) {
            return $no('export_unsupported');
        }
        if (! \in_array($target, $this->allow, true)) {
            return $no('export_disabled');
        }
        $need = self::needs($reader, $target);
        if ($need !== null && ! $this->available($need, $environment)) {
            return $no('export_unavailable', ['missing' => $need]);
        }
        if ($inputRefused !== null) {
            return $no('export_refused', ['cause' => $inputRefused]);
        }
        if ($limitCode !== null) {
            return $no($limitCode);
        }

        return ['ok' => true];
    }

    /**
     * `enabledTargets({reader, policy, environment})`: supported, allowed and
     * available, in the reader's order. Exactly what an agent is shown.
     *
     * @param  array<string,mixed>  $req
     * @return list<string>
     *
     * @throws OptionsInvalid
     */
    public static function enabledTargets(array $req): array
    {
        $policy = ($req['policy'] ?? null) instanceof self ? $req['policy'] : self::fromArray($req['policy'] ?? null);

        return $policy->enabled((string) $req['reader'], \is_array($req['environment'] ?? null) ? $req['environment'] : []);
    }

    /**
     * @param  array<string,bool>  $environment
     * @return list<string>
     */
    public function enabled(string $reader, array $environment): array
    {
        $out = [];
        foreach (self::SUPPORTED[$reader] ?? [] as $t) {
            if (! \in_array($t, $this->allow, true)) {
                continue;
            }
            $need = self::needs($reader, $t);
            if ($need === null || $this->available($need, $environment)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * Agent options: `selector`, `headerRows`, `display`. Anything else,
     * notably `allow`, throws.
     *
     * @throws OptionsInvalid
     */
    public static function checkOptions(mixed $options): void
    {
        if ($options === null) {
            return;
        }
        if (! Json::isObj($options)) {
            throw new OptionsInvalid('options must be an object');
        }
        foreach (array_keys($options) as $k) {
            if (! \in_array($k, self::OPTION_KEYS, true)) {
                throw new OptionsInvalid("unknown option {$k}");
            }
        }
    }
}
