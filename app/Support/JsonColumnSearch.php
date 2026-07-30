<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Portable substring matching against JSON-cast columns.
 *
 * Columns like `external_case_numbers` and `extra_target_ips` are stored
 * as JSON. Searching their serialized text needs dialect-specific SQL —
 * PostgreSQL requires a `::text` cast plus `ILIKE`, while MySQL/MariaDB
 * need `CAST(... AS CHAR)`. This keeps that branch in one place so the
 * search works on every supported database.
 */
class JsonColumnSearch
{
    /**
     * Add an `OR` substring match against the text form of a JSON column.
     */
    public static function orWhereContains(Builder $query, string $column, string $needle): void
    {
        $connection = $query->getConnection();
        $wrapped = $connection->getQueryGrammar()->wrap($column);
        $pattern = '%'.$needle.'%';

        $sql = match ($connection->getDriverName()) {
            'pgsql' => "{$wrapped}::text ILIKE ?",
            'mysql', 'mariadb' => "CAST({$wrapped} AS CHAR) LIKE ?",
            default => "{$wrapped} LIKE ?",
        };

        $query->orWhereRaw($sql, [$pattern]);
    }
}
