<?php

declare(strict_types=1);

namespace HolySheet\Exceptions;

/**
 * A read stopped because the spreadsheet is bigger than a read limit allows.
 *
 * `reason` is always `read_limit`; `limit` names which one (`maxRows`,
 * `maxColumns`, `maxCells`, `maxSharedStrings`), `max` is its value and
 * `actual` what the file holds. The message says the same in plain words, for
 * showing to a person: "Spreadsheet refused: a sheet has 1,200,000 rows, over
 * the limit of 1,048,576 rows (maxRows) by 151,424."
 *
 * It extends `ArchiveRefused`, so a caller that already catches the archive
 * guard's refusals catches this one too. Like the archive guard it echoes no
 * sheet name and no content. The JS and Python ports build the same message.
 */
final class ReadLimitExceeded extends ArchiveRefused
{
    private const WHAT = [
        'maxRows' => ['a sheet has', 'row', 'rows', ''],
        'maxColumns' => ['a sheet has', 'column', 'columns', ''],
        'maxCells' => ['the workbook has at least', 'cell', 'cells', 'at least '],
        'maxSharedStrings' => ['the workbook has', 'shared string', 'shared strings', ''],
    ];

    public function __construct(
        string $limit,
        int $max,
        int $actual,
        ?string $subject = null,
    ) {
        parent::__construct('read_limit', $limit, $max, $actual);
        [$what, $one, $many, $by] = self::WHAT[$limit];
        if ($subject !== null) {
            [$what, $by] = [$subject, ''];
        }
        $unit = static fn (int $n): string => $n === 1 ? $one : $many;
        $this->message = 'Spreadsheet refused: '.$what.' '.number_format($actual).' '.$unit($actual)
            .', over the limit of '.number_format($max).' '.$unit($max).' ('.$limit.') by '.$by.number_format($actual - $max).'.';
    }
}
