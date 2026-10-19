<?php

declare(strict_types=1);

namespace HolySheet\Writer;

use HolySheet\Workbook\ChartRange;
use HolySheet\Workbook\Workbook;

/**
 * The parts a native chart needs: a drawing per sheet (where each chart sits,
 * anchored between two cells), and a chart part per chart (DrawingML over CELL
 * RANGES, with the values cached so a viewer that does not recalculate still
 * draws them).
 *
 * String-built like the rest of the writer: the bytes are the cross-engine
 * contract, and the Node and Python ports emit the same ones.
 *
 * Styling is deliberately quiet: 9pt grey axis text, no tick marks, light
 * horizontal gridlines, no axis line on the value axis, a bold 12pt title.
 * A chart in a board workbook should read as part of the sheet, not as the
 * spreadsheet application's demo.
 */
final class ChartXml
{
    private const NS_C = 'http://schemas.openxmlformats.org/drawingml/2006/chart';

    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * One sheet's drawing: an anchor per chart. Chart k on this sheet is rId{k}.
     *
     * @param  list<array<string, mixed>>  $charts
     */
    public static function drawing(array $charts, int $firstChartNum): string
    {
        $anchors = '';
        foreach ($charts as $k => $chart) {
            $id = $k + 2;
            $num = $firstChartNum + $k;
            $anchors .= '<xdr:twoCellAnchor editAs="oneCell">'
                .self::marker('from', $chart['from'])
                .self::marker('to', $chart['to'])
                .'<xdr:graphicFrame macro="">'
                .'<xdr:nvGraphicFramePr><xdr:cNvPr id="'.$id.'" name="Chart '.$num.'"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
                .'<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
                .'<a:graphic><a:graphicData uri="'.self::NS_C.'">'
                .'<c:chart xmlns:c="'.self::NS_C.'" xmlns:r="'.self::NS_R.'" r:id="rId'.($k + 1).'"/>'
                .'</a:graphicData></a:graphic>'
                .'</xdr:graphicFrame>'
                .'<xdr:clientData/>'
                .'</xdr:twoCellAnchor>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="'.self::NS_A.'">'
            .$anchors
            .'</xdr:wsDr>';
    }

    /** @param  list<array<string, mixed>>  $charts */
    public static function drawingRels(array $charts, int $firstChartNum): string
    {
        $rels = '';
        foreach (array_keys($charts) as $k) {
            $rels .= '<Relationship Id="rId'.($k + 1).'" Type="'.self::NS_R.'/chart" Target="../charts/chart'.($firstChartNum + $k).'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    /** @param  array<string, mixed>  $chart */
    public static function chart(array $chart, Workbook $workbook): string
    {
        $type = $chart['type'];
        $series = '';
        foreach ($chart['series'] as $i => $s) {
            $series .= self::series($i, $s, $chart, $workbook);
        }

        $labels = $chart['dataLabels'] ? self::dataLabels($type, $chart['format']) : '';
        $plot = match ($type) {
            'line' => '<c:lineChart><c:grouping val="standard"/><c:varyColors val="0"/>'.$series.$labels.'<c:marker val="1"/><c:axId val="10"/><c:axId val="20"/></c:lineChart>',
            'pie' => '<c:pieChart><c:varyColors val="1"/>'.$series.$labels.'<c:firstSliceAng val="0"/></c:pieChart>',
            default => '<c:barChart><c:barDir val="'.($type === 'bar' ? 'bar' : 'col').'"/><c:grouping val="clustered"/><c:varyColors val="0"/>'
                .$series.$labels.'<c:gapWidth val="80"/><c:axId val="10"/><c:axId val="20"/></c:barChart>',
        };
        $axes = $type === 'pie' ? '' : self::axes($type === 'bar', $chart['format']);

        $legend = $chart['legend'] ?? (count($chart['series']) > 1 || $type === 'pie');
        $title = $chart['title'] !== null
            ? '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1200" b="1"/></a:pPr>'
                .'<a:r><a:rPr lang="en-US" sz="1200" b="1"/><a:t>'.self::escape($chart['title']).'</a:t></a:r></a:p></c:rich></c:tx>'
                .'<c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/>'
            : '<c:autoTitleDeleted val="1"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<c:chartSpace xmlns:c="'.self::NS_C.'" xmlns:a="'.self::NS_A.'" xmlns:r="'.self::NS_R.'">'
            .'<c:roundedCorners val="0"/>'
            .'<c:chart>'.$title
            .'<c:plotArea><c:layout/>'.$plot.$axes.'</c:plotArea>'
            .($legend ? '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>' : '')
            .'<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/>'
            .'</c:chart>'
            .'<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>'
            .self::textProps('595959', 900)
            .'</c:chartSpace>';
    }

    /** @param  array{0: int, 1: int}  $at */
    private static function marker(string $tag, array $at): string
    {
        return '<xdr:'.$tag.'><xdr:col>'.$at[0].'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$at[1].'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:'.$tag.'>';
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array<string, mixed>  $chart
     */
    private static function series(int $i, array $s, array $chart, Workbook $workbook): string
    {
        $type = $chart['type'];
        $name = $s['name'] !== null ? '<c:tx><c:v>'.self::escape($s['name']).'</c:v></c:tx>' : '';
        $fill = '';
        if ($s['color'] !== null && $type !== 'pie') {
            $fill = $type === 'line'
                ? '<c:spPr><a:ln w="28575" cap="rnd"><a:solidFill><a:srgbClr val="'.$s['color'].'"/></a:solidFill><a:round/></a:ln></c:spPr>'
                : '<c:spPr><a:solidFill><a:srgbClr val="'.$s['color'].'"/></a:solidFill></c:spPr>';
        }
        $invert = $type === 'column' || $type === 'bar' ? '<c:invertIfNegative val="0"/>' : '';
        $marker = $type === 'line' ? '<c:marker><c:symbol val="circle"/><c:size val="6"/></c:marker>' : '';
        $cat = $chart['categories'] !== null ? '<c:cat>'.self::strRef($chart['categories'], $workbook).'</c:cat>' : '';
        $smooth = $type === 'line' ? '<c:smooth val="0"/>' : '';

        // A coloured point (dPt) follows the series' own fill, invert and
        // marker, and precedes its categories (CT_BarSer / CT_LineSer / CT_PieSer).
        $points = '';
        foreach ($s['points'] ?? [] as $p) {
            $solid = '<a:solidFill><a:srgbClr val="'.$p['color'].'"/></a:solidFill>';
            $points .= '<c:dPt><c:idx val="'.$p['index'].'"/>'.match ($type) {
                'line' => '<c:marker><c:symbol val="circle"/><c:size val="6"/><c:spPr>'.$solid.'<a:ln>'.$solid.'</a:ln></c:spPr></c:marker><c:bubble3D val="0"/>',
                'pie' => '<c:bubble3D val="0"/><c:spPr>'.$solid.'</c:spPr>',
                default => '<c:invertIfNegative val="0"/><c:bubble3D val="0"/><c:spPr>'.$solid.'</c:spPr>',
            }.'</c:dPt>';
        }

        return '<c:ser><c:idx val="'.$i.'"/><c:order val="'.$i.'"/>'.$name.$fill.$invert.$marker.$points
            .$cat.'<c:val>'.self::numRef($s['values'], $workbook).'</c:val>'.$smooth.'</c:ser>';
    }

    private static function strRef(ChartRange $range, Workbook $workbook): string
    {
        $pts = '';
        $values = self::values($range, $workbook);
        foreach ($values as $idx => $value) {
            if ($value !== null) {
                $pts .= '<c:pt idx="'.$idx.'"><c:v>'.self::escape(self::text($value)).'</c:v></c:pt>';
            }
        }

        return '<c:strRef><c:f>'.self::escape($range->formula()).'</c:f><c:strCache><c:ptCount val="'.count($values).'"/>'.$pts.'</c:strCache></c:strRef>';
    }

    private static function numRef(ChartRange $range, Workbook $workbook): string
    {
        $pts = '';
        $values = self::values($range, $workbook);
        foreach ($values as $idx => $value) {
            if (is_int($value) || is_float($value)) {
                $pts .= '<c:pt idx="'.$idx.'"><c:v>'.self::number($value).'</c:v></c:pt>';
            }
        }

        return '<c:numRef><c:f>'.self::escape($range->formula()).'</c:f><c:numCache><c:formatCode>General</c:formatCode><c:ptCount val="'.count($values).'"/>'.$pts.'</c:numCache></c:numRef>';
    }

    /**
     * The cached value of each cell in the range, in order: a formula cell's
     * cached value when it has one, else null (no point is cached for it).
     *
     * @return list<string|int|float|bool|null>
     */
    private static function values(ChartRange $range, Workbook $workbook): array
    {
        $cells = [];
        foreach ($workbook->sheets as $sheet) {
            if ($sheet->name === $range->sheet) {
                $cells = $sheet->cells;
                break;
            }
        }
        $out = [];
        foreach ($range->addresses() as $address) {
            $cell = $cells[$address] ?? null;
            $out[] = $cell === null ? null : ($cell->formula !== null ? $cell->cachedValue : $cell->value);
        }

        return $out;
    }

    private static function dataLabels(string $type, ?string $format): string
    {
        $pos = match ($type) {
            'line' => 't',
            'pie' => 'bestFit',
            default => 'outEnd',
        };

        return '<c:dLbls>'
            .($format !== null ? '<c:numFmt formatCode="'.self::attr($format).'" sourceLinked="0"/>' : '')
            .'<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>'
            .self::textProps('404040', 900)
            .'<c:dLblPos val="'.$pos.'"/><c:showLegendKey val="0"/><c:showVal val="1"/><c:showCatName val="0"/>'
            .'<c:showSerName val="0"/><c:showPercent val="0"/><c:showBubbleSize val="0"/>'
            .'</c:dLbls>';
    }

    private static function axes(bool $horizontal, ?string $format): string
    {
        $catPos = $horizontal ? 'l' : 'b';
        $valPos = $horizontal ? 'b' : 'l';
        $numFmt = $format !== null
            ? '<c:numFmt formatCode="'.self::attr($format).'" sourceLinked="0"/>'
            : '<c:numFmt formatCode="General" sourceLinked="1"/>';

        return '<c:catAx><c:axId val="10"/><c:scaling><c:orientation val="'.($horizontal ? 'maxMin' : 'minMax').'"/></c:scaling>'
            .'<c:delete val="0"/><c:axPos val="'.$catPos.'"/><c:numFmt formatCode="General" sourceLinked="1"/>'
            // "low": category labels sit at the edge, clear of a negative bar.
            .'<c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="low"/>'
            .'<c:spPr><a:ln w="9525"><a:solidFill><a:srgbClr val="BFBFBF"/></a:solidFill></a:ln></c:spPr>'
            .'<c:crossAx val="20"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/><c:noMultiLvlLbl val="0"/></c:catAx>'
            .'<c:valAx><c:axId val="20"/><c:scaling><c:orientation val="minMax"/></c:scaling>'
            .'<c:delete val="0"/><c:axPos val="'.$valPos.'"/>'
            .'<c:majorGridlines><c:spPr><a:ln w="9525"><a:solidFill><a:srgbClr val="E5E7EB"/></a:solidFill></a:ln></c:spPr></c:majorGridlines>'
            .$numFmt
            .'<c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
            .'<c:spPr><a:ln><a:noFill/></a:ln></c:spPr>'
            .'<c:crossAx val="10"/><c:crosses val="'.($horizontal ? 'max' : 'autoZero').'"/><c:crossBetween val="between"/></c:valAx>';
    }

    private static function textProps(string $color, int $size): string
    {
        return '<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="'.$size.'"><a:solidFill><a:srgbClr val="'.$color.'"/></a:solidFill></a:defRPr></a:pPr><a:endParaRPr lang="en-US"/></a:p></c:txPr>';
    }

    /** A category as text: numbers as the writer prints them, booleans as TRUE / FALSE. */
    private static function text(string|int|float|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value), is_float($value) => self::number($value),
            default => $value,
        };
    }

    /** A number as the cells print it: the shortest exact decimal, never an exponent. */
    private static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (! is_finite($value)) {
            return '0';
        }
        $out = rtrim(rtrim(number_format($value, 14, '.', ''), '0'), '.');

        return $out === '' || $out === '-0' ? '0' : $out;
    }

    /** Element text: & < > only, as Excel writes it (a formula's 'Sheet' stays literal). */
    private static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_NOQUOTES, 'UTF-8');
    }

    /** An attribute value: also both quotes. */
    private static function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
