<?php

declare(strict_types=1);

namespace HolySheet\Ops;

/**
 * JSON Schema for one sheet op — validate ops on the wire, or register the op
 * vocabulary as an LLM tool.
 *
 * `set_cell`, `set_range` and `set_workbook` are fancy-sheets' `SheetOp`
 * variants, same `type` and same fields, so a stored op stream can drive a live
 * `useSheetSync` session. One difference: `set_workbook.data` here is a Holy
 * Sheet schema, not fancy-sheets' `WorkbookData`.
 */
final class SheetOpSchema
{
    /** Every op type, in the order the variants are listed. */
    public const TYPES = [
        'set_cell', 'set_range', 'set_workbook',
        'clear_cell',
        'insert_rows', 'delete_rows', 'insert_columns', 'delete_columns',
        'add_sheet', 'remove_sheet', 'rename_sheet', 'move_sheet', 'replace_sheet',
        'set_merged_regions', 'set_column_widths', 'set_frozen',
        'set_meta',
    ];

    /** @return array<string,mixed> */
    public static function jsonSchema(): array
    {
        $address = ['type' => 'string', 'pattern' => '^[A-Za-z]+[0-9]+$'];
        $sheet = ['type' => 'string', 'minLength' => 1];
        $count = ['type' => 'integer', 'minimum' => 1];
        $position = ['type' => 'integer', 'minimum' => 1];
        $object = ['type' => 'object'];
        $nullableObject = ['type' => ['object', 'null']];
        $value = ['type' => ['string', 'number', 'boolean', 'null']];

        $variants = [
            self::variant('set_cell', ['sheet' => $sheet, 'address' => $address, 'value' => $value, 'formula' => ['type' => 'string'], 'computedValue' => $value, 'format' => $nullableObject, 'comment' => $nullableObject], ['sheet', 'address'],
                'Write one cell. Omitting formula or computedValue clears it; omitting format or comment keeps it; null clears it.'),
            self::variant('set_range', ['sheet' => $sheet, 'start' => $address, 'end' => $address, 'values' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => $value]]], ['sheet', 'start', 'values'],
                'Write a block of values row-major from start, each as a set_cell with no formula.'),
            self::variant('set_workbook', ['data' => $object], ['data'], 'Replace the whole workbook schema.'),
            self::variant('clear_cell', ['sheet' => $sheet, 'address' => $address], ['sheet', 'address'], 'Remove one cell.'),
            self::variant('insert_rows', ['sheet' => $sheet, 'at' => $position, 'count' => $count], ['sheet', 'at', 'count'], 'Insert rows before 1-based row `at`; cells, merges below move down.'),
            self::variant('delete_rows', ['sheet' => $sheet, 'at' => $position, 'count' => $count], ['sheet', 'at', 'count'], 'Delete rows starting at 1-based row `at`; cells, merges below move up.'),
            self::variant('insert_columns', ['sheet' => $sheet, 'at' => $position, 'count' => $count], ['sheet', 'at', 'count'], 'Insert columns before 1-based column `at` (A = 1); cells, merges and widths move right.'),
            self::variant('delete_columns', ['sheet' => $sheet, 'at' => $position, 'count' => $count], ['sheet', 'at', 'count'], 'Delete columns starting at 1-based column `at`; cells, merges and widths move left.'),
            self::variant('add_sheet', ['index' => ['type' => 'integer', 'minimum' => 0], 'sheet' => $object], ['index', 'sheet'], 'Insert a sheet at a 0-based position.'),
            self::variant('remove_sheet', ['sheet' => $sheet], ['sheet'], 'Remove a sheet by name.'),
            self::variant('rename_sheet', ['sheet' => $sheet, 'name' => $sheet], ['sheet', 'name'], 'Rename a sheet.'),
            self::variant('move_sheet', ['sheet' => $sheet, 'toIndex' => ['type' => 'integer', 'minimum' => 0]], ['sheet', 'toIndex'], 'Move a sheet to a 0-based position.'),
            self::variant('replace_sheet', ['sheet' => $sheet, 'data' => $object], ['sheet', 'data'], 'Replace one sheet whole.'),
            self::variant('set_merged_regions', ['sheet' => $sheet, 'mergedRegions' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['start', 'end'], 'properties' => ['start' => $address, 'end' => $address]]]], ['sheet', 'mergedRegions'], 'Set every merged region of a sheet.'),
            // An EMPTY array too: PHP encodes an empty map as `[]`, and a diff
            // that removes every width emits exactly that. The Node port, which
            // found this, emits `{}`. Both mean no widths.
            self::variant('set_column_widths', ['sheet' => $sheet, 'columnWidths' => ['type' => ['object', 'array'], 'maxItems' => 0]], ['sheet', 'columnWidths'], 'Set every column width of a sheet (0-based column index to pixels); empty removes them.'),
            self::variant('set_frozen', ['sheet' => $sheet, 'rows' => ['type' => 'integer', 'minimum' => 0], 'cols' => ['type' => 'integer', 'minimum' => 0]], ['sheet', 'rows', 'cols'], 'Set frozen rows and columns.'),
            self::variant('set_meta', ['meta' => $nullableObject], ['meta'], 'Replace the workbook meta, or remove it with null.'),
        ];

        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'title' => 'Holy Sheet op',
            'description' => 'One op from Agent::diff, applied by Agent::reduce.',
            'oneOf' => $variants,
        ];
    }

    /**
     * @param  array<string,mixed>  $properties
     * @param  list<string>  $required
     * @return array<string,mixed>
     */
    private static function variant(string $type, array $properties, array $required, string $description): array
    {
        return [
            'type' => 'object',
            'description' => $description,
            'required' => ['type', ...$required],
            'additionalProperties' => false,
            'properties' => ['type' => ['const' => $type], ...$properties],
        ];
    }
}
