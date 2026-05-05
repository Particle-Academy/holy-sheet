<?php

declare(strict_types=1);

use HolySheet\HolySheet;

it('exposes a version constant', function () {
    expect(HolySheet::version())->toBeString()->not->toBeEmpty();
});
