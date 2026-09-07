#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Does the PUBLISHED package work?
 *
 * ## Why this exists when there is already a test suite
 *
 * The suite proves the CODE is right. It cannot prove the PACKAGE is, because
 * it never loads the package the way a consumer does.
 *
 * `composer test` runs against `../src` through the dev autoloader, with every
 * `require-dev` package installed. A consumer runs `composer require
 * particle-academy/holy-sheet`, gets a zip of the repo, and wires classes up
 * through the `autoload` map in `composer.json` with none of the dev
 * dependencies present. Those two paths usually agree — and nothing checked
 * that they did.
 *
 * Three ways they stop agreeing, each of which leaves the suite green:
 *
 *  1. A file exists locally but was never committed, so it is not in the zip.
 *  2. `src/` uses a class from a `require-dev` package — present when we test,
 *     absent when they install.
 *  3. A helper file needs naming in `autoload.files`; the test bootstrap loads
 *     it, a consumer's autoloader does not.
 *
 * In all three the consumer gets a package that installs cleanly and fatals on
 * first use. `kit:dogfood` does not catch it either: that compares version
 * NUMBERS, not whether the installed thing runs.
 *
 * ## Why it ships INSIDE the package
 *
 * Because it has to run where the consumer stands. A checker that lives in CI
 * and reads the repo is testing the repo again. This file is installed into
 * `vendor/particle-academy/holy-sheet/verify/published.php` and boots through
 * the consumer's own autoloader — the real one, with the real map.
 *
 * It is the same lesson `fancy-flow` learned when `/engine` promised "zero
 * React" and shipped 444 KB of it: the source said one thing, the artifact did
 * another, and only a check against the artifact could tell.
 *
 * ## Running it
 *
 *   php vendor/particle-academy/holy-sheet/verify/published.php
 *
 * Exit 0 means the installed package boots and works. Non-zero names what
 * broke. It writes only into the system temp directory and cleans up after
 * itself.
 */

// ── Find the consumer's autoloader ───────────────────────────────────────────
//
// Installed, this file sits at vendor/particle-academy/holy-sheet/verify/, so
// the autoloader is four levels up. Run from a clone it is ../vendor/. Both are
// tried so the script is runnable in development too — but note which one it
// found, because a pass against the DEV autoloader proves much less and saying
// so is the difference between a check and a reassurance.

$candidates = [
    'installed' => __DIR__.'/../../../autoload.php',
    'local dev' => __DIR__.'/../vendor/autoload.php',
];

$mode = null;
foreach ($candidates as $label => $path) {
    if (file_exists($path)) {
        require $path;
        $mode = $label;
        break;
    }
}

if ($mode === null) {
    fwrite(STDERR, "FAIL: found no autoloader. Run this from an installed package or a repo with vendor/.\n");
    exit(1);
}

$failures = [];
$checks = 0;

/** Assert, collecting failures rather than dying on the first one. */
function check(string $what, callable $fn): void
{
    global $failures, $checks;
    $checks++;

    try {
        $result = $fn();
        if ($result !== true) {
            $failures[] = "{$what}: ".(is_string($result) ? $result : 'returned false');
        }
    } catch (\Throwable $e) {
        $failures[] = "{$what}: ".get_class($e).' — '.$e->getMessage();
    }
}

echo "holy-sheet — verifying the installed package ({$mode} autoloader)\n\n";

// ── 1. The autoload map actually resolves the public surface ─────────────────
//
// Not `class_exists` on one class: the map can be right for the root namespace
// and wrong for a subdirectory, which is precisely the failure that looks fine
// until a consumer touches the one class nobody imported in a test.

$publicClasses = [
    \HolySheet\Agent::class,
    \HolySheet\HolySheet::class,
    \HolySheet\Writer\XlsxWriter::class,
    \HolySheet\Reader\XlsxReader::class,
    \HolySheet\Schema\WorkbookSchema::class,
    \HolySheet\Workbook\Workbook::class,
];

foreach ($publicClasses as $class) {
    check("autoloads {$class}", fn () => class_exists($class) ?: "not found through the installed autoload map");
}

// ── 2. The runtime requirements the package DECLARES are actually there ──────

check('php satisfies the declared ^8.4', fn () => PHP_VERSION_ID >= 80400 ?: 'running '.PHP_VERSION);
check('ext-zip is loaded', fn () => extension_loaded('zip') ?: 'declared in require, not present');

// ── 3. It does the thing it exists to do ─────────────────────────────────────
//
// A boot check that only proves classes load would pass a package whose writer
// is broken. This writes a real workbook and reads it back.

$tmp = sys_get_temp_dir().'/holy-sheet-verify-'.bin2hex(random_bytes(4)).'.xlsx';

check('writes a workbook to disk', function () use ($tmp) {
    $schema = \HolySheet\Agent::fromArray(
        [['Region', 'Revenue'], ['North', 1200], ['South', 950]],
        ['sheet' => 'Sales'],
    );

    \HolySheet\Agent::write($schema, $tmp);

    if (! file_exists($tmp)) {
        return 'Agent::write returned without error but produced no file';
    }

    // An xlsx is a zip: "PK\x03\x04". A zero-byte or HTML-error file would pass
    // a bare file_exists, which is how a broken writer reads as a working one.
    $magic = file_get_contents($tmp, false, null, 0, 4);

    return $magic === "PK\x03\x04" ?: 'wrote a file that is not a zip archive — got '.bin2hex((string) $magic);
});

check('reads its own output back', function () use ($tmp) {
    if (! file_exists($tmp)) {
        return 'nothing to read — the write check already failed';
    }

    $described = \HolySheet\Agent::describe($tmp);

    return is_array($described) && $described !== [] ?: 'describe() returned nothing for a file it just wrote';
});

check('emits a tool definition an agent can consume', function () {
    $definition = \HolySheet\Agent::toolDefinition();

    return isset($definition['name']) ?: 'toolDefinition() has no name key';
});

if (file_exists($tmp)) {
    @unlink($tmp);
}

// ── 4. Vacuity guard ─────────────────────────────────────────────────────────
//
// Every check above could be skipped by an early failure, and "0 failures out
// of 0 checks" is the shape of a green tick that asserted nothing. This kit has
// been bitten by exactly that — a `git grep` that returned no files made every
// assertion in a test pass by checking nothing.

if ($checks < 10) {
    fwrite(STDERR, "FAIL: only {$checks} checks ran; this script defines more. Something stopped it early.\n");
    exit(1);
}

// ── Report ───────────────────────────────────────────────────────────────────

if ($failures !== []) {
    fwrite(STDERR, "\nFAIL — ".count($failures)." of {$checks} checks failed on the INSTALLED package:\n\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    fwrite(STDERR, "\nThe test suite can pass while this fails: it loads src/ directly with every\n");
    fwrite(STDERR, "dev dependency present, which is not how a consumer gets this package.\n");
    exit(1);
}

echo "\nOK — {$checks} checks passed. The installed package boots and writes a workbook.\n";
exit(0);
