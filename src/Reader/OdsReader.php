<?php

declare(strict_types=1);

namespace HolySheet\Reader;

use HolySheet\Exceptions\UnsupportedFormatException;
use HolySheet\Reader\Ods\Ns;
use HolySheet\Reader\Ods\OdsFormula;
use HolySheet\Reader\Ods\OdsStyles;
use HolySheet\Reader\Ods\OdsText;
use HolySheet\Workbook\Cell;
use HolySheet\Workbook\CellAddress;
use HolySheet\Workbook\CellComment;
use HolySheet\Workbook\MergedRegion;
use HolySheet\Workbook\Sheet;
use HolySheet\Workbook\Workbook;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * OpenDocument Spreadsheet (.ods) reader.
 *
 * Reads content.xml, styles.xml and meta.xml into the same `Workbook` value
 * objects the xlsx reader builds, so `describe()` returns the same schema for
 * either format and a caller needs no branch on the file type.
 *
 * Mapped:
 *   - every value type: float, percentage and currency as numbers, boolean,
 *     string (paragraphs joined with "\n"), date and time as ISO strings in UTC;
 *   - formulas, translated from OpenFormula to A1 (see OdsFormula), with the
 *     cached result as `computedValue`, typed as the xlsx reader types it (a date
 *     result is its serial number);
 *   - repeated cells and rows, expanded only when they hold something: the
 *     million-row, 16384-column tails every producer writes to pad a sheet out
 *     are skipped, never materialised;
 *   - merged cells (`number-columns-spanned` / `number-rows-spanned`), and
 *     content that sits in a covered cell;
 *   - comments (`office:annotation`) with their author;
 *   - cell formatting and data styles (see OdsStyles);
 *   - document creator and creation date.
 *
 * Not mapped:
 *   - frozen panes and column widths. Panes live in settings.xml as VIEW state,
 *     which a headless conversion does not even write, so nothing here could be
 *     verified; widths are stored for every column whether or not anyone set
 *     one, so reporting them would mark every column as sized;
 *   - row heights, hidden rows and columns, charts, images, named ranges,
 *     conditional formatting, validation, and cells that carry formatting but
 *     no value (the xlsx reader drops those too).
 *
 * LibreOffice stores a typed TRUE or FALSE as the formula `TRUE()` / `FALSE()`;
 * that is read back as the boolean it was entered as, not as a formula.
 */
final class OdsReader
{
    /** Sheet bounds. A repeat past them is padding, and is not followed. */
    private const MAX_ROWS = 1048576;

    private const MAX_COLUMNS = 16384;

    /** Days from the spreadsheet epoch (1899-12-30) to the Unix epoch. */
    private const UNIX_EPOCH_SERIAL = 25569;

    /** @return array<string,mixed> */
    public function describe(string $path): array
    {
        return WorkbookSchema::fromWorkbook($this->readWorkbook($path));
    }

    public function readWorkbook(string $path): Workbook
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw UnsupportedFormatException::notAZip($path);
        }
        $content = $zip->getFromName('content.xml');
        $styles = $zip->getFromName('styles.xml');
        $meta = $zip->getFromName('meta.xml');
        $zip->close();

        if ($content === false) {
            throw new RuntimeException("[holy-sheet] missing content.xml in {$path}");
        }
        $contentXml = @simplexml_load_string($content);
        if ($contentXml === false) {
            throw new RuntimeException("[holy-sheet] failed to parse content.xml in {$path}");
        }
        $stylesXml = is_string($styles) ? @simplexml_load_string($styles) : false;

        $styleIndex = new OdsStyles($contentXml, $stylesXml === false ? null : $stylesXml);

        $sheets = [];
        $body = OdsStyles::child($contentXml, Ns::OFFICE, 'body');
        $spreadsheet = $body === null ? null : OdsStyles::child($body, Ns::OFFICE, 'spreadsheet');
        if ($spreadsheet !== null) {
            foreach ($spreadsheet->children(Ns::TABLE) as $name => $table) {
                if ($name === 'table') {
                    $sheets[] = $this->readSheet($table, $styleIndex);
                }
            }
        }

        return new Workbook($sheets, is_string($meta) ? self::readMeta($meta) : []);
    }

    private function readSheet(SimpleXMLElement $table, OdsStyles $styles): Sheet
    {
        $columns = [];
        $next = 0;
        self::collectColumns($table, $columns, $next);

        $state = ['row' => 0, 'cells' => [], 'merges' => []];
        $this->walkRows($table, $styles, $columns, $state);

        return new Sheet(
            name: (string) $table->attributes(Ns::TABLE)['name'],
            cells: $state['cells'],
            mergedRegions: $state['merges'],
        );
    }

    /**
     * Column default cell styles as [first index, last index, style name].
     *
     * @param  list<array{0:int,1:int,2:string}>  $columns
     */
    private static function collectColumns(SimpleXMLElement $container, array &$columns, int &$next): void
    {
        foreach ($container->children(Ns::TABLE) as $name => $el) {
            if ($name === 'table-column') {
                $attrs = $el->attributes(Ns::TABLE);
                $repeat = max(1, (int) (string) ($attrs['number-columns-repeated'] ?? '1'));
                $style = (string) ($attrs['default-cell-style-name'] ?? '');
                if ($style !== '') {
                    $columns[] = [$next, $next + $repeat - 1, $style];
                }
                $next += $repeat;
            } elseif (in_array($name, ['table-columns', 'table-header-columns', 'table-column-group'], true)) {
                self::collectColumns($el, $columns, $next);
            }
            if ($next >= self::MAX_COLUMNS) return;
        }
    }

    /**
     * @param  list<array{0:int,1:int,2:string}>  $columns
     * @param  array{row:int,cells:array<string,Cell>,merges:list<MergedRegion>}  $state
     */
    private function walkRows(SimpleXMLElement $container, OdsStyles $styles, array $columns, array &$state): void
    {
        foreach ($container->children(Ns::TABLE) as $name => $el) {
            if ($state['row'] >= self::MAX_ROWS) return;

            if ($name === 'table-row') {
                $this->readRow($el, $styles, $columns, $state);
            } elseif (in_array($name, ['table-header-rows', 'table-rows', 'table-row-group'], true)) {
                $this->walkRows($el, $styles, $columns, $state);
            }
        }
    }

    /**
     * @param  list<array{0:int,1:int,2:string}>  $columns
     * @param  array{row:int,cells:array<string,Cell>,merges:list<MergedRegion>}  $state
     */
    private function readRow(SimpleXMLElement $row, OdsStyles $styles, array $columns, array &$state): void
    {
        $rowAttrs = $row->attributes(Ns::TABLE);
        $repeat = max(1, (int) (string) ($rowAttrs['number-rows-repeated'] ?? '1'));
        $rowStyle = (string) ($rowAttrs['default-cell-style-name'] ?? '');

        // One pass over the cells, reading each one that holds anything ONCE,
        // however many columns and rows it repeats across.
        $entries = [];
        $column = 0;
        foreach ($row->children(Ns::TABLE) as $name => $cell) {
            if ($name !== 'table-cell' && $name !== 'covered-table-cell') continue;

            $attrs = $cell->attributes(Ns::TABLE);
            $span = $name === 'table-cell'
                ? [max(1, (int) (string) ($attrs['number-columns-spanned'] ?? '1')), max(1, (int) (string) ($attrs['number-rows-spanned'] ?? '1'))]
                : [1, 1];
            $read = self::hasContent($cell) ? $this->readCell($cell) : null;

            $count = max(1, (int) (string) ($attrs['number-columns-repeated'] ?? '1'));
            if ($read !== null || $span !== [1, 1]) {
                $style = (string) ($attrs['style-name'] ?? '');
                for ($i = 0; $i < $count && $column + $i < self::MAX_COLUMNS; $i++) {
                    $entries[] = [$column + $i, $read, $span, $style];
                }
            }

            $column += $count;
            if ($column >= self::MAX_COLUMNS) break;
        }

        if ($entries === []) {
            $state['row'] += $repeat;
            return;
        }

        for ($r = 0; $r < $repeat && $state['row'] < self::MAX_ROWS; $r++) {
            $rowNumber = $state['row'] + 1;
            foreach ($entries as [$col, $read, $span, $style]) {
                $address = CellAddress::letter($col).$rowNumber;

                if ($span !== [1, 1]) {
                    $state['merges'][] = new MergedRegion(
                        $address,
                        CellAddress::letter(min($col + $span[0], self::MAX_COLUMNS) - 1).min($rowNumber + $span[1] - 1, self::MAX_ROWS),
                    );
                }
                if ($read === null) continue;

                $styleName = $style !== '' ? $style : ($rowStyle !== '' ? $rowStyle : self::columnStyle($columns, $col));
                $format = $styles->cellFormat($styleName, $read['type'], $read['currency'], $read['dateHasTime']);
                $value = $read['value'];
                if ($read['seconds'] !== null) {
                    // A date or time value always has a date or datetime format by now.
                    $value = gmdate($format?->displayFormat === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i:s\Z', $read['seconds']);
                }
                $state['cells'][$address] = new Cell(
                    address: $address,
                    value: $value,
                    formula: $read['formula'],
                    format: $format,
                    comment: $read['comment'],
                    cachedValue: $read['cached'],
                );
            }
            $state['row']++;
        }
    }

    /** @param  list<array{0:int,1:int,2:string}>  $columns */
    private static function columnStyle(array $columns, int $column): string
    {
        foreach ($columns as [$first, $last, $style]) {
            if ($column >= $first && $column <= $last) return $style;
        }

        return 'Default';
    }

    /**
     * A cell holds something when it has a value type, a formula, a comment, or
     * text. A cell with only a style is formatting over nothing, as in xlsx.
     */
    private static function hasContent(SimpleXMLElement $cell): bool
    {
        if (isset($cell->attributes(Ns::OFFICE)['value-type'])) return true;
        if (isset($cell->attributes(Ns::TABLE)['formula'])) return true;
        if (OdsStyles::child($cell, Ns::OFFICE, 'annotation') !== null) return true;

        return (OdsText::paragraphs($cell) ?? '') !== '';
    }

    /**
     * Everything about a cell that does not depend on where it is.
     *
     * A date or time value becomes an ISO string only once the cell's format has
     * decided date or datetime, so it is carried as `seconds` (Unix, UTC) until
     * then, and `value` is null.
     *
     * @return array{value:string|int|float|bool|null,formula:?string,cached:string|int|float|bool|null,type:?string,currency:?string,dateHasTime:bool,comment:?CellComment,seconds:?int}
     */
    private function readCell(SimpleXMLElement $cell): array
    {
        $office = $cell->attributes(Ns::OFFICE);
        $type = isset($office['value-type']) ? (string) $office['value-type'] : null;
        $text = OdsText::paragraphs($cell);

        $seconds = null;
        $dateHasTime = false;
        switch ($type) {
            case 'float':
            case 'percentage':
            case 'currency':
                $value = self::number((string) ($office['value'] ?? ''));
                break;
            case 'boolean':
                $value = in_array(strtolower(trim((string) ($office['boolean-value'] ?? ''))), ['true', '1'], true);
                break;
            case 'date':
                $raw = trim((string) ($office['date-value'] ?? ''));
                $seconds = self::dateSeconds($raw);
                $dateHasTime = str_contains($raw, 'T');
                $value = null;
                break;
            case 'time':
                $seconds = self::durationSeconds((string) ($office['time-value'] ?? ''));
                $seconds = $seconds === null ? null : $seconds - self::UNIX_EPOCH_SERIAL * 86400;
                $value = null;
                break;
            case 'string':
                $error = (string) ($cell->attributes(Ns::CALCEXT)['value-type'] ?? '') === 'error';
                $value = isset($office['string-value']) && !$error ? (string) $office['string-value'] : ($text ?? '');
                break;
            default:
                $value = $text !== null && $text !== '' ? $text : null;
        }

        $formula = null;
        $cached = null;
        $formulaAttr = $cell->attributes(Ns::TABLE)['formula'] ?? null;
        if ($formulaAttr !== null) {
            $translated = OdsFormula::toA1((string) $formulaAttr);
            $literalBoolean = $type === 'boolean' && preg_match('/^\s*(TRUE|FALSE)\(\)\s*$/i', $translated) === 1;
            if (!$literalBoolean) {
                $formula = $translated;
                $cached = $seconds !== null ? self::serial($seconds) : $value;
                $value = null;
                $seconds = null;
            }
        }

        $comment = null;
        $annotation = OdsStyles::child($cell, Ns::OFFICE, 'annotation');
        if ($annotation !== null) {
            $creator = OdsStyles::child($annotation, Ns::DC, 'creator');
            $author = $creator === null ? '' : trim((string) $creator);
            $comment = new CellComment(OdsText::paragraphs($annotation) ?? '', $author === '' ? null : $author);
        }

        return [
            'value' => $value,
            'formula' => $formula,
            'cached' => $cached,
            'type' => $type,
            'currency' => isset($office['currency']) ? trim((string) $office['currency']) : null,
            'dateHasTime' => $dateHasTime,
            'comment' => $comment,
            'seconds' => $seconds,
        ];
    }

    /** `office:value` as the xlsx reader coerces `<v>`: an integer when it is one. */
    private static function number(string $raw): int|float|null
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('/^-?\d+$/', $raw) === 1) return (int) $raw;

        return is_numeric($raw) ? (float) $raw : null;
    }

    /**
     * `office:date-value` -> whole seconds since the Unix epoch, in UTC.
     *
     * `2024-03-15`, `2024-03-15T10:30:00`, fractional seconds, and a trailing
     * `Z` or `+02:00`. A value with no zone is taken as written, which is how
     * both LibreOffice and Excel treat a cell's date. Fractions round to the
     * nearest second, as the xlsx reader rounds a serial.
     */
    private static function dateSeconds(string $raw): ?int
    {
        if (preg_match('/^(-?\d{4,})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(\.\d+)?)?)?(Z|[+-]\d{2}:?\d{2})?$/', $raw, $m) !== 1) {
            return null;
        }
        $days = self::daysFromCivil((int) $m[1], (int) $m[2], (int) $m[3]);
        $seconds = $days * 86400
            + (int) ($m[4] ?? 0) * 3600
            + (int) ($m[5] ?? 0) * 60
            + (int) ($m[6] ?? 0);
        $fraction = isset($m[7]) && $m[7] !== '' ? (float) ('0'.$m[7]) : 0.0;

        $zone = $m[8] ?? '';
        if ($zone !== '' && $zone !== 'Z') {
            $digits = str_replace(':', '', substr($zone, 1));
            $offset = (int) substr($digits, 0, 2) * 3600 + (int) substr($digits, 2, 2) * 60;
            $seconds -= $zone[0] === '-' ? -$offset : $offset;
        }

        return $seconds + (int) round($fraction);
    }

    /** `office:time-value` (`PT10H30M00S`, `P1DT2H`, `-PT1H`) -> whole seconds. */
    private static function durationSeconds(string $raw): ?int
    {
        if (preg_match('/^(-)?P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', trim($raw), $m) !== 1) {
            return null;
        }
        $seconds = (int) ($m[2] ?? 0) * 86400
            + (int) ($m[3] ?? 0) * 3600
            + (int) ($m[4] ?? 0) * 60
            + (float) (($m[5] ?? '') === '' ? 0 : $m[5]);
        $seconds = (int) round($seconds);

        return ($m[1] ?? '') === '-' ? -$seconds : $seconds;
    }

    /** Days since 1970-01-01 in the proleptic Gregorian calendar (Hinnant's algorithm). */
    private static function daysFromCivil(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yoe = $year - $era * 400;
        $doy = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /** Unix seconds -> spreadsheet serial, an integer when it is a whole day. */
    private static function serial(int $seconds): int|float
    {
        $total = $seconds + self::UNIX_EPOCH_SERIAL * 86400;

        return $total % 86400 === 0 ? intdiv($total, 86400) : $total / 86400;
    }

    /** @return array<string,string> */
    private static function readMeta(string $xml): array
    {
        $doc = @simplexml_load_string($xml);
        if ($doc === false) return [];
        $meta = OdsStyles::child($doc, Ns::OFFICE, 'meta');
        if ($meta === null) return [];

        $out = [];
        $creator = OdsStyles::child($meta, Ns::META, 'initial-creator') ?? OdsStyles::child($meta, Ns::DC, 'creator');
        if ($creator !== null && trim((string) $creator) !== '') {
            $out['creator'] = trim((string) $creator);
        }
        $created = OdsStyles::child($meta, Ns::META, 'creation-date');
        if ($created !== null && trim((string) $created) !== '') {
            $value = trim((string) $created);
            // ODF dates carry no zone unless one is written; the schema's are UTC.
            $out['created'] = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1 ? $value : $value.'Z';
        }

        return $out;
    }
}
