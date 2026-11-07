<?php

declare(strict_types=1);

use HolySheet\Agent;

/**
 * A worksheet's `<col min max>` range is refused past Excel's column limit,
 * 16,384, before the reader walks it. Goes through the public reader, because
 * what matters is what a hostile upload gets back.
 */
function clXlsxWithCols(string $cols): string
{
    $src = tempnam(sys_get_temp_dir(), 'hs_cl_');
    file_put_contents($src, Agent::toBytes([
        'sheets' => [['name' => 'Sheet1', 'cells' => ['A1' => ['value' => 1]]]],
    ]));
    $in = new ZipArchive();
    $in->open($src);
    $parts = [];
    for ($i = 0; $i < $in->numFiles; $i++) {
        $parts[$in->getNameIndex($i)] = $in->getFromIndex($i);
    }
    $in->close();
    unlink($src);

    $sheet = $parts['xl/worksheets/sheet1.xml'];
    expect($sheet)->toContain('<sheetData');
    $parts['xl/worksheets/sheet1.xml'] = str_replace('<sheetData', '<cols>'.$cols.'</cols><sheetData', $sheet);

    $path = tempnam(sys_get_temp_dir(), 'hs_cl_');
    unlink($path);
    $out = new ZipArchive();
    $out->open($path, ZipArchive::CREATE);
    foreach ($parts as $name => $body) {
        $out->addFromString($name, $body);
    }
    $out->close();

    return $path;
}

it('refuses a <col> whose max is past 16,384', function () {
    $path = clXlsxWithCols('<col min="1" max="2000000000" width="10" customWidth="1"/>');
    try {
        expect(fn () => Agent::describe($path))->toThrow(RuntimeException::class, '16,384');
    } finally {
        unlink($path);
    }
});

it('refuses a <col> whose min is past 16,384', function () {
    $path = clXlsxWithCols('<col min="16385" max="16385" width="10" customWidth="1"/>');
    try {
        expect(fn () => Agent::describe($path))->toThrow(RuntimeException::class, '16,384');
    } finally {
        unlink($path);
    }
});

it('still reads a <col> that ends exactly at column 16,384', function () {
    $path = clXlsxWithCols('<col min="16384" max="16384" width="10" customWidth="1"/>');
    try {
        expect(Agent::describe($path)['sheets'][0]['name'])->toBe('Sheet1');
    } finally {
        unlink($path);
    }
});
