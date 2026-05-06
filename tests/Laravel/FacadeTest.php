<?php

declare(strict_types=1);

use HolySheet\Laravel\Facades\HolySheet;

it('writes through the facade — every Agent method is reachable', function () {
    $schema = ['sheets' => [['name' => 'X', 'columns' => [['header' => 'A']], 'rows' => [[1]]]]];

    expect(HolySheet::validate($schema))->toBe([]);

    $tmp = tempnam(sys_get_temp_dir(), 'hs-facade-').'.xlsx';
    $result = HolySheet::write($schema, $tmp);
    expect($result)->toMatchArray(['path' => $tmp, 'sheets' => 1]);
    expect(file_exists($tmp))->toBeTrue();
    @unlink($tmp);

    $bytes = HolySheet::toBytes($schema);
    expect(substr($bytes, 0, 4))->toBe("PK\x03\x04");

    $def = HolySheet::toolDefinition();
    expect($def)->toHaveKey('$schema');
    expect($def['title'])->toBe('Holy Sheet workbook schema');

    expect(HolySheet::getVersion())->toBeString()->not->toBeEmpty();

    $describe = HolySheet::describe('/whatever.xlsx');
    expect($describe)->toHaveKey('error'); // 1.0 stub
});

it('the facade resolves to the singleton bound by the service provider', function () {
    $a = app(\HolySheet\HolySheet::class);
    $b = app('holy-sheet');
    expect($a)->toBe($b);
});
