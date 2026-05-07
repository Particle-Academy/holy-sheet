<?php

declare(strict_types=1);

use HolySheet\Laravel\Facades\HolySheet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('widgets', function ($t) {
        $t->id();
        $t->string('name');
        $t->decimal('price', 10, 2);
        $t->boolean('active')->default(true);
        $t->json('meta')->nullable();
        $t->datetime('shipped_at')->nullable();
    });
});

afterEach(function () {
    Schema::dropIfExists('widgets');
});

class HolySheetWidget extends Model
{
    protected $table = 'widgets';
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'price' => 'decimal:2',
        'active' => 'boolean',
        'meta' => 'array',
        'shipped_at' => 'datetime',
    ];
}

it('builds a schema from an Eloquent builder using $casts for type inference', function () {
    HolySheetWidget::create([
        'name' => 'A',
        'price' => 9.99,
        'active' => true,
        'meta' => ['color' => 'red'],
        'shipped_at' => '2024-05-01 12:00:00',
    ]);
    HolySheetWidget::create([
        'name' => 'B',
        'price' => 12.50,
        'active' => false,
        'meta' => null,
        'shipped_at' => null,
    ]);

    $schema = HolySheet::fromQuery(
        HolySheetWidget::query()->orderBy('id'),
        ['name', 'price', 'active', 'meta', 'shipped_at'],
    );

    $cols = $schema['sheets'][0]['columns'];
    expect($cols[1]['type'])->toBe('number')
        ->and($cols[1]['decimals'])->toBe(2)
        ->and($cols[2]['type'])->toBe('boolean')
        ->and($cols[4]['type'])->toBe('datetime');

    $rows = $schema['sheets'][0]['rows'];
    expect($rows[0][0])->toBe('A')
        ->and($rows[0][3])->toBe('{"color":"red"}'); // json-cast value json-encoded
});

it('writes the schema produced by fromQuery', function () {
    HolySheetWidget::create(['name' => 'A', 'price' => 1.00]);

    $schema = HolySheet::fromQuery(HolySheetWidget::query());
    $tmp = tempnam(sys_get_temp_dir(), 'hs-q-').'.xlsx';
    HolySheet::write($schema, $tmp);
    expect(filesize($tmp))->toBeGreaterThan(0);
    @unlink($tmp);
});

it('respects an associative columns map for header labels', function () {
    HolySheetWidget::create(['name' => 'A', 'price' => 1.00]);
    $schema = HolySheet::fromQuery(
        HolySheetWidget::query(),
        ['name' => 'Widget Name', 'price' => 'Price USD'],
    );
    $cols = $schema['sheets'][0]['columns'];
    expect($cols[0]['header'])->toBe('Widget Name')
        ->and($cols[1]['header'])->toBe('Price USD');
});

it('accepts a plain Collection', function () {
    $coll = new Collection([
        ['city' => 'NYC', 'pop' => 8_000_000],
        ['city' => 'LA', 'pop' => 4_000_000],
    ]);
    $schema = HolySheet::fromQuery($coll);
    expect($schema['sheets'][0]['rows'])->toHaveCount(2)
        ->and($schema['sheets'][0]['columns'][0]['header'])->toBe('City');
});

it('throws when row count exceeds the limit', function () {
    for ($i = 0; $i < 10; $i++) {
        HolySheetWidget::create(['name' => "n{$i}", 'price' => 1.00]);
    }
    expect(fn () => HolySheet::fromQuery(HolySheetWidget::query(), null, ['limit' => 5]))
        ->toThrow(RuntimeException::class);
});
