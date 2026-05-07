<?php

declare(strict_types=1);

namespace HolySheet\Laravel\Helpers;

use HolySheet\Helpers\ArrayBuilder;
use HolySheet\Schema\Inference;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * QueryAdapter — Eloquent / Query Builder / Collection → Holy Sheet schema.
 *
 * Lives behind the Laravel facade as `HolySheet::fromQuery($builder, $columns?)`.
 * The core PHP package stays framework-agnostic; this adapter is the only
 * place that talks to Illuminate.
 *
 * Type inference uses Eloquent `$casts` first when an Eloquent builder is
 * supplied — so `decimal:2` becomes `number` with two decimals, `datetime`
 * becomes `datetime`, etc. Plain query builders fall through to value-
 * sampling via `Schema\Inference`.
 *
 * Memory guard: a default 5000-row cap, configurable via
 * `$options['limit']`. Streaming over large tables is on the 1.2 roadmap.
 */
final class QueryAdapter
{
    private const DEFAULT_LIMIT = 5000;

    /**
     * @param  EloquentBuilder|QueryBuilder|Collection|EloquentCollection|iterable<int,mixed>  $source
     * @param  list<string>|array<string,string>|null  $columns  numeric list = column names; assoc = ['key' => 'Header']
     * @param  array<string,mixed>  $options  passthrough: theme, currency, sheetName, limit
     * @return array<string,mixed>
     */
    public static function fromQuery(mixed $source, array|null $columns = null, array $options = []): array
    {
        $limit = (int) ($options['limit'] ?? self::DEFAULT_LIMIT);
        $sheetName = (string) ($options['sheetName'] ?? 'Sheet 1');

        [$records, $model] = self::materialize($source, $limit);

        // Resolve column key list + header labels
        [$keys, $headers] = self::resolveColumns($columns, $records, $model);

        // Build rows
        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($keys as $key) {
                $row[] = self::extract($record, $key);
            }
            $rows[] = $row;
        }

        // If we have a model, use $casts for type inference where possible
        $columnDefs = self::inferColumns($rows, $keys, $headers, $model, $options);

        $sheet = [
            'name' => $sheetName,
            'columns' => $columnDefs,
            'rows' => $rows,
        ];
        if (isset($options['theme'])) $sheet['theme'] = $options['theme'];
        if (isset($options['totals']) && is_array($options['totals'])) $sheet['totals'] = $options['totals'];
        if (isset($options['frozenRows'])) $sheet['frozenRows'] = (int) $options['frozenRows'];
        if (isset($options['frozenCols'])) $sheet['frozenCols'] = (int) $options['frozenCols'];

        return ['sheets' => [$sheet]];
    }

    /**
     * @return array{0:array<int,mixed>,1:?Model}
     */
    private static function materialize(mixed $source, int $limit): array
    {
        if ($source instanceof EloquentBuilder) {
            $model = $source->getModel();
            $count = (clone $source)->toBase()->count();
            if ($count > $limit) {
                throw new RuntimeException(
                    "[holy-sheet] fromQuery() refused to materialize {$count} rows (limit {$limit}). ".
                    'Pass [\'limit\' => N] to raise the cap or scope the query.'
                );
            }
            return [$source->limit($limit)->get()->all(), $model];
        }
        if ($source instanceof QueryBuilder) {
            $count = (clone $source)->count();
            if ($count > $limit) {
                throw new RuntimeException(
                    "[holy-sheet] fromQuery() refused to materialize {$count} rows (limit {$limit})."
                );
            }
            return [$source->limit($limit)->get()->all(), null];
        }
        if ($source instanceof EloquentCollection) {
            $arr = $source->all();
            $model = $arr === [] ? null : ($arr[0] instanceof Model ? $arr[0] : null);
            return [array_slice($arr, 0, $limit), $model];
        }
        if ($source instanceof Collection) {
            return [array_slice($source->all(), 0, $limit), null];
        }
        if (is_iterable($source)) {
            $arr = [];
            $i = 0;
            foreach ($source as $row) {
                if ($i++ >= $limit) break;
                $arr[] = $row;
            }
            $model = $arr !== [] && $arr[0] instanceof Model ? $arr[0] : null;
            return [$arr, $model];
        }

        throw new InvalidArgumentException(
            '[holy-sheet] fromQuery() expects Eloquent Builder, Query Builder, Collection, or iterable.'
        );
    }

    /**
     * @return array{0:list<string>,1:list<string>}  [keys, headers]
     */
    private static function resolveColumns(array|null $columns, array $records, ?Model $model): array
    {
        if (is_array($columns) && $columns !== []) {
            $isAssoc = array_keys($columns) !== range(0, count($columns) - 1);
            if ($isAssoc) {
                return [array_keys($columns), array_values($columns)];
            }
            $keys = array_values($columns);
            return [$keys, array_map(fn ($k) => self::humanize($k), $keys)];
        }

        // Discover keys from first record
        if ($records === []) return [[], []];
        $first = $records[0];
        if ($first instanceof Model) {
            $keys = array_keys($first->attributesToArray());
        } elseif ($first instanceof Arrayable) {
            $keys = array_keys($first->toArray());
        } elseif (is_object($first)) {
            $keys = array_keys(get_object_vars($first));
        } elseif (is_array($first)) {
            $keys = array_keys($first);
        } else {
            $keys = ['value'];
        }
        $keys = array_map(fn ($k) => (string) $k, $keys);
        return [$keys, array_map(fn ($k) => self::humanize($k), $keys)];
    }

    private static function extract(mixed $record, string $key): mixed
    {
        $value = null;
        if ($record instanceof Model) {
            $value = $record->getAttribute($key);
        } elseif (is_object($record)) {
            if (isset($record->{$key})) {
                $value = $record->{$key};
            } elseif ($record instanceof Arrayable) {
                $arr = $record->toArray();
                $value = $arr[$key] ?? null;
            }
        } elseif (is_array($record)) {
            $value = $record[$key] ?? null;
        } else {
            $value = $record;
        }
        return self::coerce($value);
    }

    private static function coerce(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            // Datetime if non-zero time, date otherwise — writer handles both via ISO
            $isMidnight = $value->format('H:i:s') === '00:00:00';
            return $isMidnight ? $value->format('Y-m-d') : $value->format('Y-m-d\TH:i:s');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($value instanceof Arrayable) {
            return json_encode($value->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        return $value;
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<string>  $keys
     * @param  list<string>  $headers
     * @return list<array<string,mixed>>
     */
    private static function inferColumns(array $rows, array $keys, array $headers, ?Model $model, array $options): array
    {
        $casts = $model !== null ? $model->getCasts() : [];

        $columns = [];
        foreach ($keys as $i => $key) {
            $headerName = $headers[$i] ?? self::humanize($key);
            $cast = $casts[$key] ?? null;

            $col = self::columnFromCast($headerName, $cast, $options);
            if ($col !== null) {
                $columns[] = $col;
                continue;
            }

            $values = [];
            foreach ($rows as $row) {
                $values[] = $row[$i] ?? null;
            }
            $columns[] = Inference::detect($values, $headerName, $options);
        }
        return $columns;
    }

    private static function columnFromCast(string $headerName, ?string $cast, array $options): ?array
    {
        if ($cast === null) return null;

        // Strip parameter suffix: 'decimal:2' → ['decimal', '2']
        $parts = explode(':', $cast, 2);
        $type = $parts[0];
        $param = $parts[1] ?? null;

        return match ($type) {
            'int', 'integer' => ['header' => $headerName, 'type' => 'integer'],
            'real', 'float', 'double' => ['header' => $headerName, 'type' => 'number', 'decimals' => 2],
            'decimal' => [
                'header' => $headerName,
                'type' => 'number',
                'decimals' => $param !== null ? (int) $param : 2,
            ],
            'bool', 'boolean' => ['header' => $headerName, 'type' => 'boolean'],
            'date' => ['header' => $headerName, 'type' => 'date'],
            'datetime', 'immutable_datetime', 'custom_datetime', 'immutable_custom_datetime', 'timestamp' => [
                'header' => $headerName,
                'type' => 'datetime',
            ],
            'string' => ['header' => $headerName, 'type' => 'string'],
            'array', 'json', 'object', 'collection', 'encrypted:array', 'encrypted:json' => [
                'header' => $headerName,
                'type' => 'string',
            ],
            default => null,
        };
    }

    private static function humanize(string $key): string
    {
        return Str::headline($key);
    }
}
