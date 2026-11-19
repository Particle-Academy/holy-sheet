<?php

declare(strict_types=1);

/**
 * The agent guide ships, and is the same bytes in all three engines.
 *
 * Each engine pins this hash over LF-normalised content, so a one-sided edit
 * fails a build instead of telling a model one thing in PHP and another in
 * Node. Edit all three copies, then all three constants.
 */
const AGENT_GUIDE_SHA256 = 'ba0c8cbc49b3bbfdfe877a2385ae138173502190337a68c8f4ca9cfea5b1a6c3';

it('returns the guide, and it is the shared one', function () {
    $guide = HolySheet\Agent::guide();

    expect(hash('sha256', str_replace("\r\n", "\n", $guide)))->toBe(AGENT_GUIDE_SHA256)
        ->and($guide)->toStartWith('# Agent guide:');
});
