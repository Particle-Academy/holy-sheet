<?php

declare(strict_types=1);

namespace HolySheet\Reader\Ods;

use HolySheet\Reader\Format\NumFmtParser;
use HolySheet\Workbook\CellFormat;
use SimpleXMLElement;

/**
 * Cell styles and data styles -> `CellFormat`.
 *
 * A cell's style is found by name in content.xml's automatic styles, then in
 * styles.xml's common styles, and inherits along `style:parent-style-name`
 * down to the `style:default-style` for table cells. The nearest style that
 * sets a property wins.
 *
 * Mapped: bold, italic, text colour, background, the four borders (colour only;
 * CellFormat has no width or line style), horizontal alignment, font size, and
 * the data style as displayFormat / decimals / currency.
 *
 * Font size is reported only when it differs from the document's `Default`
 * cell style, for the reason the xlsx reader omits Excel's 11pt: every styled
 * cell restates the default, and a schema that repeats it on every cell says
 * nothing. Sizes are whole points, truncated, as the xlsx reader reads them.
 *
 * Not mapped: fonts, underline and strike-through, wrapping, vertical
 * alignment, rotation, border widths and styles, conditional formats, and data
 * styles CellFormat cannot express (scientific, fraction, and the colour or
 * text of a negative sub-format).
 */
final class OdsStyles
{
    /** @var array<string,SimpleXMLElement> content.xml automatic styles, family table-cell */
    private array $automatic = [];

    /** @var array<string,SimpleXMLElement> styles.xml common + automatic styles, family table-cell */
    private array $common = [];

    private ?SimpleXMLElement $default = null;

    /** @var array<string,SimpleXMLElement> data styles by name */
    private array $dataStyles = [];

    /** @var array<string,array<string,mixed>> */
    private array $resolved = [];

    private ?int $defaultFontSize = null;

    public function __construct(?SimpleXMLElement $content, ?SimpleXMLElement $styles)
    {
        if ($content !== null) {
            $this->index(self::child($content, Ns::OFFICE, 'automatic-styles'), $this->automatic);
        }
        if ($styles !== null) {
            $this->index(self::child($styles, Ns::OFFICE, 'styles'), $this->common);
            $this->index(self::child($styles, Ns::OFFICE, 'automatic-styles'), $this->common);
        }
        $this->defaultFontSize = $this->properties('Default')['fontSize'];
    }

    /**
     * The format of one cell.
     *
     * @param  string  $styleName  the cell's effective style (cell, row default, column default, or "Default")
     * @param  string|null  $valueType  office:value-type
     * @param  string|null  $currency  office:currency, the ISO code a currency value carries
     * @param  bool  $dateHasTime  whether an office:date-value carries a time of day
     */
    public function cellFormat(string $styleName, ?string $valueType, ?string $currency, bool $dateHasTime): ?CellFormat
    {
        $p = $this->properties($styleName);
        $data = $p['data'];
        $display = $data['displayFormat'] ?? null;

        // The value type says what the value IS; the data style only says how
        // it is shown. A date is a date whatever style it wears.
        switch ($valueType) {
            case 'date':
                $data = ['displayFormat' => in_array($display, ['date', 'datetime'], true) ? $display : ($dateHasTime ? 'datetime' : 'date')];
                break;
            case 'time':
                $data = ['displayFormat' => 'datetime'];
                break;
            case 'percentage':
                if ($display !== 'percentage') {
                    $data = ['displayFormat' => 'percentage'] + (isset($data['decimals']) ? ['decimals' => $data['decimals']] : []);
                }
                break;
            case 'currency':
                if ($display !== 'currency') {
                    $data = ['displayFormat' => 'currency'] + (isset($data['decimals']) ? ['decimals' => $data['decimals']] : []);
                }
                if ($currency !== null && $currency !== '') {
                    $data['currency'] = $currency;
                }
                break;
        }

        $format = new CellFormat(
            bold: $p['bold'],
            italic: $p['italic'],
            textAlign: $p['textAlign'],
            displayFormat: $data['displayFormat'] ?? null,
            decimals: $data['decimals'] ?? null,
            color: $p['color'],
            backgroundColor: $p['backgroundColor'],
            fontSize: $p['fontSize'] !== null && $p['fontSize'] !== $this->defaultFontSize ? $p['fontSize'] : null,
            borderTop: $p['borderTop'],
            borderRight: $p['borderRight'],
            borderBottom: $p['borderBottom'],
            borderLeft: $p['borderLeft'],
            currency: $data['currency'] ?? null,
        );

        return $format->isEmpty() ? null : $format;
    }

    /** @param  array<string,SimpleXMLElement>  $into */
    private function index(?SimpleXMLElement $container, array &$into): void
    {
        if ($container === null) return;

        foreach ($container->children(Ns::STYLE) as $name => $el) {
            $family = (string) $el->attributes(Ns::STYLE)['family'];
            if ($name === 'default-style' && $family === 'table-cell') {
                $this->default ??= $el;
            } elseif ($name === 'style' && $family === 'table-cell') {
                $into[(string) $el->attributes(Ns::STYLE)['name']] ??= $el;
            }
        }
        foreach ($container->children(Ns::NUMBER) as $el) {
            $this->dataStyles[(string) $el->attributes(Ns::STYLE)['name']] ??= $el;
        }
    }

    /**
     * The style and its ancestors, nearest first, ending with the default style.
     *
     * @return list<SimpleXMLElement>
     */
    private function chain(string $name): array
    {
        $chain = [];
        $seen = [];
        // The cell's own style is normally automatic; a parent is always common.
        $el = $this->automatic[$name] ?? $this->common[$name] ?? null;
        while ($el !== null && !isset($seen[$name])) {
            $seen[$name] = true;
            $chain[] = $el;
            $name = (string) $el->attributes(Ns::STYLE)['parent-style-name'];
            $el = $name === '' ? null : ($this->common[$name] ?? $this->automatic[$name] ?? null);
        }
        if ($this->default !== null) {
            $chain[] = $this->default;
        }

        return $chain;
    }

    /** @return array<string,mixed> */
    private function properties(string $name): array
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $chain = $this->chain($name);

        $weight = self::first($chain, 'text-properties', Ns::FO, 'font-weight');
        $style = self::first($chain, 'text-properties', Ns::FO, 'font-style');
        $size = self::first($chain, 'text-properties', Ns::FO, 'font-size');

        $dataStyle = null;
        foreach ($chain as $el) {
            $candidate = (string) $el->attributes(Ns::STYLE)['data-style-name'];
            if ($candidate !== '') {
                $dataStyle = $candidate;
                break;
            }
        }

        return $this->resolved[$name] = [
            'bold' => $weight !== null && ($weight === 'bold' || (is_numeric($weight) && (int) $weight >= 600)),
            'italic' => $style === 'italic' || $style === 'oblique',
            'textAlign' => self::textAlign($chain),
            'color' => self::color($chain),
            'backgroundColor' => self::background($chain),
            'fontSize' => $size !== null && preg_match('/^([0-9]*\.?[0-9]+)pt$/', $size, $m) === 1 ? (int) (float) $m[1] : null,
            'borderTop' => self::border($chain, 'top'),
            'borderRight' => self::border($chain, 'right'),
            'borderBottom' => self::border($chain, 'bottom'),
            'borderLeft' => self::border($chain, 'left'),
            'data' => $this->dataFormat($dataStyle),
        ];
    }

    /**
     * The nearest value of one property attribute.
     *
     * @param  list<SimpleXMLElement>  $chain
     */
    private static function first(array $chain, string $properties, string $ns, string $attribute): ?string
    {
        foreach ($chain as $el) {
            $props = self::child($el, Ns::STYLE, $properties);
            if ($props !== null && isset($props->attributes($ns)[$attribute])) {
                return trim((string) $props->attributes($ns)[$attribute]);
            }
        }

        return null;
    }

    /** @param  list<SimpleXMLElement>  $chain */
    private static function color(array $chain): ?string
    {
        foreach ($chain as $el) {
            $props = self::child($el, Ns::STYLE, 'text-properties');
            if ($props === null) continue;
            // "Automatic" colour: whatever the viewer's window text is. No fixed colour.
            if ((string) $props->attributes(Ns::STYLE)['use-window-font-color'] === 'true') return null;
            if (isset($props->attributes(Ns::FO)['color'])) {
                return self::hex((string) $props->attributes(Ns::FO)['color']);
            }
        }

        return null;
    }

    /** @param  list<SimpleXMLElement>  $chain */
    private static function background(array $chain): ?string
    {
        $value = self::first($chain, 'table-cell-properties', Ns::FO, 'background-color');

        return $value === null || $value === 'transparent' ? null : self::hex($value);
    }

    /** @param  list<SimpleXMLElement>  $chain */
    private static function border(array $chain, string $side): ?string
    {
        foreach ($chain as $el) {
            $props = self::child($el, Ns::STYLE, 'table-cell-properties');
            if ($props === null) continue;
            $fo = $props->attributes(Ns::FO);
            $value = isset($fo['border-'.$side]) ? (string) $fo['border-'.$side] : (isset($fo['border']) ? (string) $fo['border'] : null);
            if ($value === null) continue;

            $value = strtolower(trim($value));
            if ($value === '' || preg_match('/\b(none|hidden)\b/', $value) === 1) return null;

            return preg_match('/#([0-9a-f]{6})\b/', $value, $m) === 1 ? '#'.strtoupper($m[1]) : '#000000';
        }

        return null;
    }

    /** @param  list<SimpleXMLElement>  $chain */
    private static function textAlign(array $chain): ?string
    {
        // text-align-source="value-type" means "align by what the value is"
        // (numbers right, text left): the stored fo:text-align is not in effect.
        if (self::first($chain, 'table-cell-properties', Ns::STYLE, 'text-align-source') === 'value-type') {
            return null;
        }

        return match ($align = self::first($chain, 'paragraph-properties', Ns::FO, 'text-align')) {
            null, '' => null,
            'start' => 'left',
            'end' => 'right',
            default => $align,
        };
    }

    /** The first child element with this namespace and local name, or null. */
    public static function child(SimpleXMLElement $parent, string $ns, string $name): ?SimpleXMLElement
    {
        $children = $parent->children($ns)->{$name};

        return isset($children[0]) ? $children[0] : null;
    }

    private static function hex(string $value): ?string
    {
        return preg_match('/^#([0-9a-fA-F]{6})$/', trim($value), $m) === 1 ? '#'.strtoupper($m[1]) : null;
    }

    /**
     * A data style -> displayFormat, decimals, currency.
     *
     * @return array{displayFormat:string,decimals?:int,currency?:string}|null
     */
    private function dataFormat(?string $name, int $depth = 0): ?array
    {
        if ($name === null || $depth > 4 || !isset($this->dataStyles[$name])) {
            return null;
        }
        $el = $this->dataStyles[$name];

        // LibreOffice writes a signed format as the NEGATIVE sub-format with a
        // map to the positive one; the positive one is the format as authored.
        foreach ($el->children(Ns::STYLE) as $child => $map) {
            if ($child !== 'map') continue;
            $condition = preg_replace('/\s+/', '', (string) $map->attributes(Ns::STYLE)['condition']);
            if ($condition === 'value()>=0' || $condition === 'value()>0') {
                $mapped = $this->dataFormat((string) $map->attributes(Ns::STYLE)['apply-style-name'], $depth + 1);
                if ($mapped !== null) return $mapped;
            }
        }

        $parts = $el->children(Ns::NUMBER);
        $decimals = null;
        if (isset($parts->number)) {
            $attrs = $parts->number->attributes(Ns::NUMBER);
            $places = $attrs['decimal-places'] ?? $attrs['min-decimal-places'] ?? null;
            $decimals = $places !== null ? (int) (string) $places : null;
        }

        switch ($el->getName()) {
            case 'number-style':
                if (isset($parts->{'scientific-number'}) || isset($parts->fraction)) {
                    return null;
                }
                // A currency written as literal text around a number: "$#,##0.00"
                // converted from xlsx arrives this way, not as a currency-style.
                foreach ($parts->text as $text) {
                    $iso = self::currencyCode(str_replace(['-', '(', ')', '"', "\u{00A0}"], '', (string) $text));
                    if ($iso !== null) {
                        return ['displayFormat' => 'currency', 'decimals' => $decimals ?? 0, 'currency' => $iso];
                    }
                }
                if (!isset($parts->number)) return null;

                // A number with no decimal places set is "General".
                return $decimals === null ? ['displayFormat' => 'auto'] : ['displayFormat' => 'number', 'decimals' => $decimals];

            case 'percentage-style':
                return ['displayFormat' => 'percentage', 'decimals' => $decimals ?? 0];

            case 'currency-style':
                $out = ['displayFormat' => 'currency', 'decimals' => $decimals ?? 0];
                $iso = isset($parts->{'currency-symbol'}) ? self::currencyCode((string) $parts->{'currency-symbol'}) : null;
                if ($iso !== null) $out['currency'] = $iso;

                return $out;

            case 'date-style':
                foreach (['hours', 'minutes', 'seconds', 'am-pm'] as $timePart) {
                    if (isset($parts->{$timePart})) return ['displayFormat' => 'datetime'];
                }

                return ['displayFormat' => 'date'];

            case 'time-style':
                return ['displayFormat' => 'datetime'];

            case 'text-style':
                return ['displayFormat' => 'text'];

            default: // boolean-style, and anything newer than this reader
                return null;
        }
    }

    private static function currencyCode(string $symbol): ?string
    {
        $symbol = trim($symbol);
        if ($symbol === '') return null;
        if (preg_match('/^[A-Z]{3}$/', $symbol) === 1) return $symbol;

        return NumFmtParser::SYMBOL_TO_ISO[$symbol] ?? null;
    }
}
