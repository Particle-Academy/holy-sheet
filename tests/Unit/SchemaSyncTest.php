<?php

declare(strict_types=1);

/**
 * The JSON Schema is duplicated in the Node repo and kept in sync BY HAND.
 *
 * `holy-sheet/skills/holy-sheet.schema.json` and
 * `holy-sheet-js/src/holy-sheet.schema.json` are byte-identical copies of one
 * contract, maintained by remembering to edit both. Nothing checked that, and
 * this is the file handed to an LLM as the tool definition — so a one-sided
 * edit does not fail a build, it changes what an agent is told the API is on
 * one backend and not the other.
 *
 * The shared conformance policy names this case directly:
 *
 *   > Contracts that are byte-identical copies get a checksum test. A five-line
 *   > "this file hashes to X" test in each repo makes an unsynchronised edit a
 *   > build failure instead of a discovery.
 *
 * ## How to change the schema
 *
 * Edit BOTH copies, run either suite, and paste the new hash into BOTH tests.
 * The mild annoyance IS the mechanism: update one side and the other repo goes
 * red on its next push. The alternative is silence.
 *
 * The twin lives at `holy-sheet-js/tests/schema-sync.test.ts` and pins the same
 * constant.
 *
 * ## Why the content is normalised before hashing
 *
 * Neither repo has a `.gitattributes`, so the checkout decides line endings.
 * The file is stored LF in git and lands CRLF on a Windows working tree. A
 * checksum over the RAW bytes would pass on Linux CI and fail on the
 * maintainer's own machine, for a reason that has nothing to do with the two
 * copies being out of sync — a worse failure than the one being prevented,
 * because it teaches people to distrust the test.
 */
const SHARED_SCHEMA_SHA256 = 'a09d491ee67c32d9c37e8608a6bc3334993a6c77f978e5ffe158f769e77e2d9c';

function schemaPath(): string
{
    return __DIR__.'/../../skills/holy-sheet.schema.json';
}

/** Hash of the content, independent of how the checkout wrote the newlines. */
function normalisedSha256(string $text): string
{
    return hash('sha256', str_replace("\r\n", "\n", $text));
}

it('matches the shared checksum', function () {
    expect(normalisedSha256((string) file_get_contents(schemaPath())))
        ->toBe(SHARED_SCHEMA_SHA256);
});

it('is valid JSON with the fields a tool definition needs', function () {
    // A checksum alone would happily pin a corrupt file. This asserts the thing
    // being pinned is still the thing we mean.
    $schema = json_decode((string) file_get_contents(schemaPath()), true, 512, JSON_THROW_ON_ERROR);

    expect($schema)->toBeArray()
        ->and($schema['$schema'] ?? null)->toBeString()
        ->and($schema['type'] ?? null)->toBe('object')
        ->and($schema['properties'] ?? null)->toBeArray();
});
