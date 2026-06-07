<?php

declare(strict_types=1);

use HolySheet\Toolkit\ArraySchemaStore;
use HolySheet\Toolkit\Toolkit;

it('exposes five well-formed tool descriptors', function () {
    $kit = Toolkit::for(new ArraySchemaStore());
    $tools = $kit->tools();

    expect($tools)->toHaveCount(5);

    foreach ($tools as $tool) {
        expect($tool->name)->toBeString()->not->toBeEmpty()
            ->and($tool->description)->toBeString()->not->toBeEmpty()
            ->and($tool->parameters)->toBeArray()
            ->and($tool->parameters['type'])->toBe('object')
            ->and($tool->toArray())->toHaveKeys(['name', 'description', 'parameters']);
    }

    expect(array_keys($kit->byName()))
        ->toContain('read_schema', 'build_schema', 'lint_schema', 'write_xlsx', 'describe_file');
});

it('write_xlsx persists a valid schema to the store', function () {
    $store = new ArraySchemaStore();
    $schema = ['sheets' => [['name' => 'T', 'columns' => [['header' => 'A']], 'rows' => [[1], [2]]]]];

    $result = Toolkit::for($store)->byName()['write_xlsx']->call(['schema' => $schema]);

    expect($result['ok'])->toBeTrue()
        ->and($result['sheets'])->toBe(1)
        ->and($result['bytes'])->toBeGreaterThan(0)
        ->and($store->getSchema())->toBe($schema);
});

it('write_xlsx refuses to persist a formula-error schema and returns the lint issues', function () {
    $store = new ArraySchemaStore();
    $schema = ['sheets' => [['name' => 'T', 'cells' => ['A1' => '=10/0']]]];

    $result = Toolkit::for($store)->byName()['write_xlsx']->call(['schema' => $schema]);

    expect($result['ok'])->toBeFalse()
        ->and($result['stage'])->toBe('lint')
        ->and($result['issues'])->not->toBeEmpty()
        ->and($store->getSchema())->toBe(['sheets' => []]); // unchanged
});

it('write_xlsx returns structural errors without persisting', function () {
    $store = new ArraySchemaStore();

    $result = Toolkit::for($store)->byName()['write_xlsx']->call(['schema' => ['sheets' => []]]);

    expect($result['ok'])->toBeFalse()
        ->and($result['stage'])->toBe('validate')
        ->and($result['errors'])->not->toBeEmpty();
});

it('read_schema returns the stored schema as JSON', function () {
    $schema = ['sheets' => [['name' => 'T', 'cells' => ['A1' => ['value' => 42]]]]];
    $kit = Toolkit::for(new ArraySchemaStore($schema));

    $json = $kit->byName()['read_schema']->call([]);
    $decoded = json_decode($json, true);

    expect($json)->toBeJson()
        ->and($decoded['sheets'][0]['cells']['A1']['value'])->toBe(42);
});

it('lint_schema reports ok for a clean workbook', function () {
    $store = new ArraySchemaStore(['sheets' => [['name' => 'T', 'cells' => ['A1' => ['value' => 1]]]]]);

    $result = Toolkit::for($store)->byName()['lint_schema']->call([]);

    expect($result['ok'])->toBeTrue()
        ->and($result['issues'])->toBe([]);
});

it('build_schema validates and repairs a draft', function () {
    $store = new ArraySchemaStore();
    $draft = ['sheets' => [['name' => 'T', 'columns' => [['header' => 'A']], 'rows' => [[1]]]]];

    $result = Toolkit::for($store)->byName()['build_schema']->call(['schema' => $draft]);

    expect($result)->toHaveKeys(['schema', 'errors', 'repairs']);
});

it('ships an agent prompt via instructions()', function () {
    expect(Toolkit::instructions())->toBeString()->toContain('Holy Sheet');
});
