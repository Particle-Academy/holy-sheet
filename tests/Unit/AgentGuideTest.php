<?php

declare(strict_types=1);

/**
 * The agent guide ships, and is the same bytes in all three engines.
 *
 * Each engine pins this hash over LF-normalised content, so a one-sided edit
 * fails a build instead of telling a model one thing in PHP and another in
 * Node. Edit all three copies, then all three constants.
 */
const AGENT_GUIDE_SHA256 = '06aeb56a5555330ac87cc67455c6c7f1533194b669e0d0359e364ce20a4daaa0';

it('returns the guide, and it is the shared one', function () {
    $guide = HolySheet\Agent::guide();

    expect(hash('sha256', str_replace("\r\n", "\n", $guide)))->toBe(AGENT_GUIDE_SHA256)
        ->and($guide)->toStartWith('# Agent guide:');
});
