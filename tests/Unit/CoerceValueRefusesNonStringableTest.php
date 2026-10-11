<?php

declare(strict_types=1);

use HolySheet\Exceptions\SchemaException;
use HolySheet\Schema\Normalizer;

/**
 * holy-sheet#9, reported from MOIC production (org 55).
 *
 * `coerceValue()` ended with "not a scalar, so it must be safe to cast":
 *
 *     if (!is_string($v) && !is_int($v) && !is_float($v) && !is_bool($v)) {
 *         return (string) $v;
 *     }
 *
 * An ARRAY reaches that line whenever a plain `rows` form is nested one level
 * too deep -- no object-cell syntax needed, which is exactly the shape an
 * AI-generated edit produces. The cast then has two outcomes and the quiet one
 * is the dangerous one:
 *
 *   - under an error handler that promotes warnings (Laravel), an
 *     ErrorException kills the whole normalize();
 *   - with warnings suppressed, the cell is written as the literal string
 *     "Array" and the workbook REPORTS SUCCESS while being corrupt.
 *
 * Both were reproduced at Normalizer.php:349 before the fix.
 *
 * The test asserts the REFUSAL AND THE CELL ADDRESS, because an exception that
 * does not say which cell is wrong leaves the caller grepping a spreadsheet by
 * hand -- that was the actual production complaint, not the crash.
 */
it('refuses an array cell and names the cell address', function () {
    $schema = ['sheets' => [['name' => 'S', 'rows' => [[['x'], 'ok']]]]];

    try {
        (new Normalizer())->normalize($schema);
        $this->fail('normalize() accepted an array cell instead of refusing it');
    } catch (SchemaException $e) {
        $errors = $e->getErrors();
        expect($errors)->toHaveCount(1);
        expect($errors[0]['path'])->toBe('S!A1');
        expect($errors[0]['got'])->toBe('array');
        expect($e->getMessage())->toContain('S!A1');
    }
});

it('refuses a non-Stringable object cell and names the cell address', function () {
    $schema = ['sheets' => [['name' => 'S', 'rows' => [[new stdClass()]]]]];

    try {
        (new Normalizer())->normalize($schema);
        $this->fail('normalize() accepted a non-Stringable object instead of refusing it');
    } catch (SchemaException $e) {
        $errors = $e->getErrors();
        expect($errors[0]['path'])->toBe('S!A1');
        expect($errors[0]['got'])->toBe('stdClass');
    }
});

/**
 * The guard must test "is this Stringable" rather than "is this a non-scalar",
 * or it trades one wrong answer for another: a Stringable object has always
 * been castable and is a legitimate cell value.
 */
it('still accepts a Stringable object', function () {
    $stringable = new class () implements Stringable {
        public function __toString(): string
        {
            return 'from-stringable';
        }
    };

    $wb = (new Normalizer())->normalize([
        'sheets' => [['name' => 'S', 'rows' => [[$stringable]]]],
    ]);

    expect($wb->sheets[0]->cells['A1']->value)->toBe('from-stringable');
});

/** A refusal must not become a refusal of things that already worked. */
it('still accepts the scalar and date cell values it always did', function () {
    $wb = (new Normalizer())->normalize([
        'sheets' => [['name' => 'S', 'rows' => [
            ['text', 42, 3.5, true, null],
        ]]],
    ]);

    $cells = $wb->sheets[0]->cells;
    expect($cells['A1']->value)->toBe('text');
    expect($cells['B1']->value)->toBe(42);
    expect($cells['C1']->value)->toBe(3.5);
    expect($cells['D1']->value)->toBeTrue();
});

/**
 * The two halves of the contract have to AGREE.
 *
 * Before this, `validate()` returned ZERO errors for the schema `normalize()`
 * throws on, so `Agent::validate()` told a caller the input was fine and the
 * write then failed. That is the shape MOIC actually hit: an AI-generated edit
 * is validated and then written, so a validator that passes bad input moves the
 * failure to the point where it is hardest to attribute.
 *
 * `Normalizer` has a comment about this exact class from an earlier bug -- "the
 * two halves of the contract disagreed and the writer was the one that was
 * wrong" -- so catching it in the validator is the house answer, not a new idea.
 */
it('validate() rejects a list-array cell rather than passing it to the writer', function () {
    $schema = ['sheets' => [['name' => 'S', 'rows' => [[['x'], 'ok']]]]];

    $errors = (new HolySheet\Schema\Validator())->validate($schema);

    expect($errors)->not->toBeEmpty();
    expect($errors[0]['path'])->toBe('sheets[0].rows[0][0]');
    expect($errors[0]['got'])->toBe('array');
});

/**
 * And must NOT reject the object cell form, which is an associative array and
 * has always been legal. Rejecting "any array" would trade this bug for a
 * worse one -- every formatted cell in every workbook.
 */
it('validate() still accepts the object cell form', function () {
    $schema = ['sheets' => [['name' => 'S', 'rows' => [[
        ['value' => 'x', 'format' => ['bold' => true]],
        'plain',
    ]]]]];

    expect((new HolySheet\Schema\Validator())->validate($schema))->toBe([]);
});

/**
 * The address has to be right for a cell that is not A1, or the message sends
 * the caller to the wrong place -- worse than no address at all.
 */
it('names the correct address for an array deeper in the sheet', function () {
    $schema = ['sheets' => [['name' => 'Data', 'rows' => [
        ['a', 'b'],
        ['c', ['nested']],
    ]]]];

    try {
        (new Normalizer())->normalize($schema);
        $this->fail('normalize() accepted an array cell instead of refusing it');
    } catch (SchemaException $e) {
        expect($e->getErrors()[0]['path'])->toBe('Data!B2');
    }
});
