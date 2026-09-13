<?php

declare(strict_types=1);

/**
 * Regenerate the ODS reader fixtures.
 *
 *   php tests/fixtures/ods/generate.php [path to soffice]
 *
 * 1. Writes `workbook.xlsx` from the schema below with THIS package's writer.
 * 2. Converts it to `workbook.ods` with LibreOffice (`soffice --headless
 *    --convert-to ods`), in a throwaway profile so a running LibreOffice is
 *    never touched.
 * 3. Converts `native.fods`, a hand-authored flat OpenDocument file, to
 *    `native.ods` the same way.
 * 4. Zips `edge/` into `edge.ods` WITHOUT LibreOffice, because what it holds is
 *    exactly what LibreOffice normalises away when it saves.
 *
 * Every output is committed. workbook.ods and native.ods are what LibreOffice
 * actually wrote, not hand-built approximations, so the reader is tested against
 * real OpenDocument output: repeated-cell compression, trailing 1048576-row
 * repeats, automatic styles and all. workbook.xlsx is the SAME authored
 * workbook, which is what lets a test assert that both formats describe to the
 * same schema.
 *
 * No third-party file is involved: the schema, the flat file and the edge
 * package are ours, and LibreOffice is only the converter.
 */

require __DIR__.'/../../../vendor/autoload.php';

use HolySheet\Agent;

$dir = __DIR__;
$soffice = $argv[1] ?? (PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/LibreOffice/program/soffice.com' : 'soffice');

$bold = ['bold' => true];

$schema = [
    'meta' => ['creator' => 'Holy Sheet ODS fixture', 'created' => '2026-09-13T12:00:00Z'],
    'sheets' => [
        [
            'name' => 'Types',
            'cells' => [
                'A1' => ['value' => 'Kind', 'format' => $bold],
                'B1' => ['value' => 'Value', 'format' => ['bold' => true, 'italic' => true, 'textAlign' => 'center']],
                'C1' => ['value' => 'Styled', 'format' => ['color' => '#1D4ED8', 'backgroundColor' => '#FEF3C7', 'fontSize' => 14, 'borderBottom' => '#111827']],

                'A2' => ['value' => 100, 'comment' => ['text' => 'An integer', 'author' => 'Fixture']],
                'B2' => ['value' => 9800.5],
                'C2' => ['value' => -42],

                'A3' => ['value' => 0.25, 'format' => ['displayFormat' => 'percentage', 'decimals' => 1]],
                'B3' => ['value' => 1234.5, 'format' => ['displayFormat' => 'currency', 'currency' => 'USD', 'decimals' => 2]],
                'C3' => ['value' => 99, 'format' => ['displayFormat' => 'currency', 'currency' => 'EUR', 'decimals' => 0]],

                'A4' => ['value' => '2024-03-15', 'format' => ['displayFormat' => 'date']],
                'B4' => ['value' => '2024-03-15T10:30:00Z', 'format' => ['displayFormat' => 'datetime']],
                'C4' => ['value' => 1234.5678, 'format' => ['displayFormat' => 'number', 'decimals' => 2]],

                'A5' => ['value' => true],
                'B5' => ['value' => false],

                'A6' => ['value' => "first line\nsecond line"],
                'B6' => ['value' => 'spaced   out'],
                'C6' => ['value' => 'Fish & Chips <tasty>'],

                'A7' => ['formula' => 'SUM(A2:C2)', 'computedValue' => 9858.5],
                'B7' => ['formula' => "'Other Sheet'!A1*2", 'computedValue' => 42],
                // Semicolons inside a string literal must survive the ODF `;` -> `,`
                // argument-separator translation. Numeric result on purpose: a
                // STRING cached result did not survive LibreOffice's xlsx import.
                'C7' => ['formula' => 'IF(A5,LEN("a;b"),0)', 'computedValue' => 3],
                'D7' => ['formula' => 'ROUND(B2/4,1)', 'computedValue' => 2450.1],
            ],
            'frozenRows' => 1,
            'frozenCols' => 1,
        ],
        [
            'name' => 'Repeats',
            'cells' => [
                // Five identical cells in a row: LibreOffice stores ONE cell with
                // table:number-columns-repeated="5".
                'A1' => ['value' => 7], 'B1' => ['value' => 7], 'C1' => ['value' => 7], 'D1' => ['value' => 7], 'E1' => ['value' => 7],
                // Three identical rows: one row with table:number-rows-repeated="3".
                'A2' => ['value' => 'x'], 'B2' => ['value' => 'y'],
                'A3' => ['value' => 'x'], 'B3' => ['value' => 'y'],
                'A4' => ['value' => 'x'], 'B4' => ['value' => 'y'],
                // A far cell, so the gap before it is repeated EMPTY rows and cells.
                'H20' => ['value' => 'far'],
            ],
        ],
        [
            'name' => 'Merged',
            'cells' => [
                'A1' => ['value' => 'Across', 'format' => $bold],
                'A2' => ['value' => 'Down'],
                'B2' => ['value' => 1],
                'B3' => ['value' => 2],
            ],
            'mergedRegions' => [
                ['start' => 'A1', 'end' => 'C1'],
                ['start' => 'A2', 'end' => 'A3'],
            ],
        ],
        [
            'name' => 'Other Sheet',
            'cells' => ['A1' => ['value' => 21]],
        ],
        [
            // No content at all. `cells: []` is a PHP list the validator
            // (rightly) rejects as not a map, so the empty sheet is row-mode.
            'name' => 'Empty',
            'rows' => [],
        ],
    ],
];

$errors = Agent::validate($schema);
if ($errors !== []) {
    fwrite(STDERR, json_encode($errors, JSON_PRETTY_PRINT)."\n");
    exit(1);
}

$xlsx = $dir.'/workbook.xlsx';
Agent::write($schema, $xlsx);

echo "wrote {$xlsx}\n";

$convert = function (string $source, string $expected) use ($soffice, $dir): void {
    $profile = sys_get_temp_dir().'/holy-sheet-ods-fixture-'.bin2hex(random_bytes(4));
    $profileUrl = 'file:///'.ltrim(str_replace('\\', '/', $profile), '/');
    @unlink($expected);
    $process = proc_open(
        [$soffice, "-env:UserInstallation={$profileUrl}", '--headless', '--convert-to', 'ods', '--outdir', $dir, $source],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    $code = proc_close($process);

    if ($code !== 0 || ! is_file($expected)) {
        fwrite(STDERR, "LibreOffice conversion of {$source} failed ({$code}):\n{$out}\n");
        exit(1);
    }
    echo "wrote {$expected}\n";
};

$convert($xlsx, $dir.'/workbook.ods');
$convert($dir.'/native.fods', $dir.'/native.ods');

// edge.ods: the OpenDocument package layout by hand. `mimetype` goes first and
// uncompressed, as the format requires of a package.
$edge = $dir.'/edge.ods';
@unlink($edge);
$zip = new ZipArchive();
$zip->open($edge, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
$zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
foreach (['content.xml', 'styles.xml', 'meta.xml'] as $part) {
    $zip->addFile($dir.'/edge/'.$part, $part);
}
$zip->addFromString(
    'META-INF/manifest.xml',
    '<?xml version="1.0" encoding="UTF-8"?>'
    .'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
    .'<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>'
    .'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
    .'<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
    .'<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'
    .'</manifest:manifest>',
);
$zip->close();
echo "wrote {$edge}\n";
