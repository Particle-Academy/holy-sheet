<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Workbook\Cell;
use HolySheet\Workbook\CellAddress;
use HolySheet\Workbook\CellComment;
use HolySheet\Workbook\CellFormat;
use HolySheet\Workbook\MergedRegion;
use HolySheet\Workbook\Sheet;
use SimpleXMLElement;

/**
 * Parses one xl/worksheets/sheetN.xml → `Sheet` value object.
 *
 * Inputs:
 *   - $worksheetXml — the sheetN.xml content
 *   - $name         — sheet name from workbook.xml
 *   - $stylesIndex  — list<?CellFormat> indexed by xf id (from StylesParser)
 *   - $comments     — array<address,CellComment> from CommentsParser (or empty)
 */
final class WorksheetParser
{
    /**
     * @param  list<?CellFormat>  $stylesIndex
     * @param  array<string,CellComment>  $comments
     * @param  list<string>  $sharedStrings
     */
    public static function parse(string $worksheetXml, string $name, array $stylesIndex, array $comments = [], array $sharedStrings = []): Sheet
    {
        $xml = @simplexml_load_string($worksheetXml);
        if ($xml === false) {
            return new Sheet(name: $name);
        }

        $cells = self::parseCells($xml, $stylesIndex, $comments, $sharedStrings);
        $merges = self::parseMerges($xml);
        $columnWidths = self::parseColumnWidths($xml);
        [$frozenRows, $frozenCols] = self::parseFrozen($xml);

        return new Sheet(
            name: $name,
            cells: $cells,
            mergedRegions: $merges,
            columnWidths: $columnWidths,
            frozenRows: $frozenRows,
            frozenCols: $frozenCols,
        );
    }

    /**
     * @param  list<?CellFormat>  $stylesIndex
     * @param  array<string,CellComment>  $comments
     * @param  list<string>  $sharedStrings
     * @return array<string,Cell>
     */
    private static function parseCells(SimpleXMLElement $xml, array $stylesIndex, array $comments, array $sharedStrings): array
    {
        $cells = [];
        if (!isset($xml->sheetData) || !$xml->sheetData->row) return $cells;

        foreach ($xml->sheetData->row as $row) {
            if (!$row->c) continue;
            foreach ($row->c as $c) {
                $address = (string) $c['r'];
                if ($address === '') continue;

                $type = (string) ($c['t'] ?? 'n');
                $styleIdx = isset($c['s']) ? (int) $c['s'] : 0;
                $format = $stylesIndex[$styleIdx] ?? null;

                $formula = null;
                $cachedValue = null;
                $value = null;

                if (isset($c->f)) {
                    $formula = (string) $c->f;
                    $cachedValue = isset($c->v) ? self::coerceValue((string) $c->v, $type) : null;
                } elseif ($type === 'inlineStr' && isset($c->is) && isset($c->is->t)) {
                    $value = (string) $c->is->t;
                } elseif ($type === 's' && isset($c->v)) {
                    $idx = (int) (string) $c->v;
                    $value = $sharedStrings[$idx] ?? '';
                } elseif (isset($c->v)) {
                    $value = self::coerceValue((string) $c->v, $type);
                }

                $comment = $comments[$address] ?? null;

                $cells[$address] = new Cell(
                    address: $address,
                    value: $value,
                    formula: $formula,
                    format: $format,
                    comment: $comment,
                    cachedValue: $cachedValue,
                );
            }
        }
        return $cells;
    }

    private static function coerceValue(string $raw, string $type): string|int|float|bool|null
    {
        if ($type === 'b') return $raw === '1' || $raw === 'true' || $raw === 'TRUE';
        if ($type === 'str') return $raw; // formula-result string
        if ($type === 'inlineStr') return $raw;
        // numeric default
        if ($raw === '') return null;
        if (preg_match('/^-?\d+$/', $raw) === 1) return (int) $raw;
        if (is_numeric($raw)) return (float) $raw;
        return $raw;
    }

    /** @return list<MergedRegion> */
    private static function parseMerges(SimpleXMLElement $xml): array
    {
        $out = [];
        if (isset($xml->mergeCells) && $xml->mergeCells->mergeCell) {
            foreach ($xml->mergeCells->mergeCell as $m) {
                $ref = (string) $m['ref'];
                if (str_contains($ref, ':')) {
                    [$start, $end] = explode(':', $ref, 2);
                    $out[] = new MergedRegion($start, $end);
                }
            }
        }
        return $out;
    }

    /** @return array<int,float>  0-based column index → pixels */
    private static function parseColumnWidths(SimpleXMLElement $xml): array
    {
        $out = [];
        if (isset($xml->cols) && $xml->cols->col) {
            foreach ($xml->cols->col as $col) {
                if (!isset($col['customWidth'])) continue;
                $min = (int) $col['min'];
                $max = (int) $col['max'];
                $excelWidth = (float) $col['width'];
                // Inverse of (px - 5) / 7
                $px = ($excelWidth * 7) + 5;
                for ($i = $min; $i <= $max; $i++) {
                    $out[$i - 1] = round($px);
                }
            }
        }
        return $out;
    }

    /** @return array{0:int,1:int}  [frozenRows, frozenCols] */
    private static function parseFrozen(SimpleXMLElement $xml): array
    {
        if (!isset($xml->sheetViews) || !$xml->sheetViews->sheetView) return [0, 0];
        foreach ($xml->sheetViews->sheetView as $view) {
            if (!isset($view->pane)) continue;
            $rows = isset($view->pane['ySplit']) ? (int) $view->pane['ySplit'] : 0;
            $cols = isset($view->pane['xSplit']) ? (int) $view->pane['xSplit'] : 0;
            return [$rows, $cols];
        }
        return [0, 0];
    }
}
