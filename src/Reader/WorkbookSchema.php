<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Reader\Format\DateInverter;
use HolySheet\Workbook\Cell;
use HolySheet\Workbook\CellComment;
use HolySheet\Workbook\CellFormat;
use HolySheet\Workbook\MergedRegion;
use HolySheet\Workbook\Sheet;
use HolySheet\Workbook\Workbook;

/**
 * A read `Workbook` -> the Holy Sheet schema array `describe()` returns.
 *
 * Shared by every reader (xlsx, ods) so the output shape cannot drift between
 * formats: key order, which empty things are dropped, and how a date-formatted
 * serial becomes an ISO string are decided here and only here. A reader's job
 * ends at the value objects.
 */
final class WorkbookSchema
{
    /** @return array<string,mixed> */
    public static function fromWorkbook(Workbook $workbook): array
    {
        $schema = ['sheets' => []];
        foreach ($workbook->sheets as $sheet) {
            $schema['sheets'][] = self::sheetToSchema($sheet);
        }
        if ($workbook->meta !== []) {
            $schema['meta'] = $workbook->meta;
        }
        return $schema;
    }

    /** @return array<string,mixed> */
    private static function sheetToSchema(Sheet $sheet): array
    {
        $cells = [];
        foreach ($sheet->cells as $address => $cell) {
            $cellSchema = self::cellToSchema($cell);
            // Drop wholly-empty cells.
            if ($cellSchema === null) continue;
            $cells[$address] = $cellSchema;
        }

        $out = [
            'name' => $sheet->name,
            'cells' => $cells,
        ];
        if ($sheet->mergedRegions !== []) {
            $out['mergedRegions'] = array_map(
                fn (MergedRegion $m) => ['start' => $m->start, 'end' => $m->end],
                $sheet->mergedRegions,
            );
        }
        if ($sheet->columnWidths !== []) {
            $out['columnWidths'] = $sheet->columnWidths;
        }
        if ($sheet->frozenRows > 0) $out['frozenRows'] = $sheet->frozenRows;
        if ($sheet->frozenCols > 0) $out['frozenCols'] = $sheet->frozenCols;
        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function cellToSchema(Cell $cell): ?array
    {
        $value = $cell->value;
        $format = $cell->format;

        // Convert serial dates back to ISO strings when the format flags it as a date.
        if ($format !== null && in_array($format->displayFormat, ['date', 'datetime'], true)
            && is_numeric($value)) {
            $value = DateInverter::toIso((float) $value, $format->displayFormat === 'datetime');
        }

        $out = ['value' => $value];
        if ($cell->formula !== null) $out['formula'] = $cell->formula;
        if ($cell->cachedValue !== null) $out['computedValue'] = $cell->cachedValue;
        if ($format !== null && !$format->isEmpty()) {
            $out['format'] = self::formatToArray($format);
        }
        if ($cell->comment !== null) {
            $out['comment'] = self::commentToArray($cell->comment);
        }

        // Strip null-only cells: { value: null } with no formula/format/comment is equivalent to absent.
        if ($out === ['value' => null]) return null;
        return $out;
    }

    /** @return array<string,mixed> */
    private static function formatToArray(CellFormat $f): array
    {
        $out = [];
        if ($f->bold) $out['bold'] = true;
        if ($f->italic) $out['italic'] = true;
        if ($f->textAlign !== null) $out['textAlign'] = $f->textAlign;
        if ($f->displayFormat !== null) $out['displayFormat'] = $f->displayFormat;
        if ($f->decimals !== null) $out['decimals'] = $f->decimals;
        if ($f->color !== null) $out['color'] = $f->color;
        if ($f->backgroundColor !== null) $out['backgroundColor'] = $f->backgroundColor;
        if ($f->fontSize !== null) $out['fontSize'] = $f->fontSize;
        if ($f->borderTop !== null) $out['borderTop'] = $f->borderTop;
        if ($f->borderRight !== null) $out['borderRight'] = $f->borderRight;
        if ($f->borderBottom !== null) $out['borderBottom'] = $f->borderBottom;
        if ($f->borderLeft !== null) $out['borderLeft'] = $f->borderLeft;
        if ($f->currency !== null) $out['currency'] = $f->currency;
        return $out;
    }

    /** @return array<string,mixed> */
    private static function commentToArray(CellComment $c): array
    {
        $out = ['text' => $c->text];
        if ($c->author !== null) $out['author'] = $c->author;
        if ($c->color !== null) $out['color'] = $c->color;
        return $out;
    }
}
