<?php

declare(strict_types=1);

use HolySheet\Laravel\Http\HolySheetController;

beforeEach(function () {
    \Illuminate\Support\Facades\Route::post('/holy-sheet/export', HolySheetController::class);
});

it('returns xlsx bytes with attachment headers on a valid schema', function () {
    $response = $this->postJson('/holy-sheet/export', [
        'schema' => ['sheets' => [['name' => 'X', 'columns' => [['header' => 'A']], 'rows' => [[1]]]]],
        'filename' => 'test.xlsx',
    ]);

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('Content-Disposition'))->toContain('test.xlsx');
    expect(substr($response->getContent(), 0, 4))->toBe("PK\x03\x04");
});

it('returns 422 with structured errors on invalid schema', function () {
    $response = $this->postJson('/holy-sheet/export', ['schema' => ['sheets' => []]]);
    $response->assertStatus(422);
    expect($response->json('error'))->toBe('validation');
    expect($response->json('errors'))->toBeArray()->not->toBeEmpty();
});

it('coerces unsafe filenames before sending', function () {
    $response = $this->postJson('/holy-sheet/export', [
        'schema' => ['sheets' => [['name' => 'X', 'columns' => [['header' => 'A']], 'rows' => [[1]]]]],
        'filename' => '../../etc/passwd',
    ]);
    $response->assertStatus(200);
    $disposition = $response->headers->get('Content-Disposition');
    expect(str_contains($disposition, '/'))->toBeFalse('path separators must be stripped');
    expect(str_contains($disposition, '.xlsx'))->toBeTrue('extension must be enforced');
});
