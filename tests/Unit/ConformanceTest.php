<?php

declare(strict_types=1);

use HolySheet\Exchange\Csv;
use HolySheet\Exchange\Display;
use HolySheet\Exchange\ExportPolicy;
use HolySheet\Exchange\Extractor;
use HolySheet\Exchange\Inserter;
use HolySheet\Exchange\OptionsInvalid;
use HolySheet\Exchange\Portable;
use HolySheet\Exchange\Range;
use HolySheet\Exchange\Report;
use ParticleAcademy\Conformance\Conformance;

/**
 * Every `exchange/*` suite in fancy-conformance that names holy-sheet (PHP) as
 * an implementation, run against this package.
 *
 * The suites are the normative spec (`suites/exchange/SPEC.md`); this file only
 * binds each suite's function names to ours. A row that throws is a failure,
 * not a crash, and every skip is printed with its reason.
 *
 * The vacuity floor below is the point of the counts: a loader that found no
 * rows, a suite renamed upstream, or a runner that silently skipped everything
 * would otherwise read as green.
 */
const EXCHANGE_PIN = '0.37.0';

/** Suites that list particle-academy/holy-sheet, and the rows each held at the pin. */
const EXCHANGE_SUITES = [
    'exchange/content' => 56,
    'exchange/limits' => 32,
    'exchange/report-kinds' => 40,
    'exchange/range' => 51,
    'exchange/number-display' => 44,
    'exchange/from-holy-sheet' => 32,
    'exchange/to-holy-sheet' => 9,
    'exchange/csv' => 12,
    'exchange/policy' => 25,
];

/**
 * A case's input decoded in OBJECT mode, so `{}` stays distinct from `[]`.
 *
 * The loader decodes assoc, where the two are the same `[]`; a validator handed
 * that cannot tell `"items": {}` from `"items": []`. Portable content is what
 * an agent writes, so the suites that validate it get the faithful decoding.
 */
function exchangeObjectInput(string $suite, string $id): mixed
{
    static $byId = [];
    if (! isset($byId[$suite])) {
        $raw = json_decode((string) file_get_contents(Conformance::root().'/suites/'.$suite.'/cases.json'), false, 512, JSON_THROW_ON_ERROR);
        foreach ($raw->cases as $c) {
            $byId[$suite][$c->id] = $c->input;
        }
    }

    return $byId[$suite][$id];
}

/** @param array<string,mixed> $case */
function exchangeRun(string $suite, array $case): mixed
{
    $in = $case['input'];
    if (in_array($suite, ['exchange/content', 'exchange/limits', 'exchange/to-holy-sheet'], true) && ($case['fn'] ?? null) !== 'truncateCell') {
        $obj = exchangeObjectInput($suite, $case['id']);
        $in['content'] = $obj->content ?? null;
        if (isset($obj->options)) {
            $in['options'] = $obj->options;
        }
    }
    $fn = $case['fn'] ?? null;
    $throws = static function (callable $f): mixed {
        try {
            return $f();
        } catch (OptionsInvalid) {
            return ['throws' => 'options_invalid'];
        }
    };

    return match ($suite) {
        'exchange/content' => $throws(fn () => Portable::validateContent($in['content'] ?? null, $in['options'] ?? null)),
        'exchange/limits' => $fn === 'truncateCell'
            ? Portable::truncateCell($in['text'], $in['maxBytes'] ?? Portable::DEFAULT_LIMITS['cellBytes'])
            : $throws(fn () => Portable::validateContent($in['content'] ?? null, $in['options'] ?? null)),
        'exchange/report-kinds' => $fn === 'classifyKind'
            ? Report::classifyKind($in['kind'])
            : Report::merge($in['a'], $in['b']),
        'exchange/range' => match ($fn) {
            'parseCell' => Range::parseCell($in['ref']),
            'parseSelector' => Range::parseSelector($in['selector'] ?? null, $in['options'] ?? null),
            default => Range::headerRowsFor($in),
        },
        'exchange/number-display' => Display::display($in['t'], $in['v'] ?? null, $in['format'] ?? null),
        'exchange/from-holy-sheet' => Extractor::toPortable($in['model'], $in['selector'] ?? null, $in['options'] ?? null),
        'exchange/to-holy-sheet' => Inserter::fromPortable($in['content'] ?? null, $in['at']),
        'exchange/csv' => Csv::toCsv($in['table'], $in['options'] ?? null),
        'exchange/policy' => $throws(fn () => $fn === 'checkExport'
            ? ExportPolicy::checkExport($in)
            : ExportPolicy::enabledTargets($in)),
    };
}

it('runs fancy-conformance at the exact pinned version', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($composer['require-dev']['particle-academy/fancy-conformance'] ?? null)->toBe(EXCHANGE_PIN);
    expect(Conformance::version())->toBe(EXCHANGE_PIN);
});

it('lists exactly the exchange suites that name holy-sheet PHP', function () {
    $named = [];
    foreach (Conformance::listSuites() as $suite) {
        if (! str_starts_with($suite, 'exchange/')) {
            continue;
        }
        foreach (Conformance::manifest($suite)['contract']['implementations'] ?? [] as $impl) {
            if (($impl['language'] ?? null) === 'php' && ($impl['package'] ?? null) === 'particle-academy/holy-sheet') {
                $named[] = $suite;
            }
        }
    }
    sort($named);
    $ours = array_keys(EXCHANGE_SUITES);
    sort($ours);

    expect($named)->toBe($ours);
});

it('passes every applicable exchange suite', function () {
    $passed = 0;
    $total = 0;
    $lines = [];
    $failures = [];
    $short = [];

    foreach (EXCHANGE_SUITES as $suite => $floor) {
        $summary = Conformance::runTable($suite, fn (array $case): mixed => exchangeRun($suite, $case));
        $rows = $summary['passed'] + $summary['failed'] + $summary['skipped'];
        $passed += $summary['passed'];
        $total += $rows;
        $lines[] = sprintf('  %-26s %3d/%-3d passed, %d failed, %d skipped', $suite, $summary['passed'], $rows, $summary['failed'], $summary['skipped']);

        // Vacuity floor, per suite: every row the pin shipped ran and passed.
        // No row skips PHP at this pin, so a skip appearing is itself a change
        // to look at, and counts against the floor.
        if ($rows < $floor || $summary['passed'] < $floor) {
            $short[] = "{$suite}: {$summary['passed']} passed of {$rows} loaded; the pin has {$floor}";
        }
        if (! $summary['ok']) {
            $failures[] = Conformance::formatSummary($summary);
        }
    }

    // Printed BEFORE any assertion, so a red run still shows every count.
    fwrite(STDOUT, "\nfancy-conformance ".Conformance::version()." exchange/* [php holy-sheet]\n".implode("\n", $lines)
        ."\n  total: {$passed}/{$total} passed\n");

    expect($failures)->toBe([], implode("\n\n", $failures));
    expect($short)->toBe([]);
    expect($total)->toBe(array_sum(EXCHANGE_SUITES));
    expect($passed)->toBe($total);
});
