<?php

namespace App\Services\Request;

use Illuminate\Support\Facades\DB;

/**
 * Атомарная генерация internal_code для заявок.
 *
 * Формат: M-{year}-{N}, где N — счётчик внутри года, начинается с 1.
 * Атомарность обеспечивается UPSERT с RETURNING + DB-транзакцией.
 */
class InternalCodeGenerator
{
    public function next(?int $year = null): string
    {
        $year = $year ?: (int) now()->format('Y');

        $value = DB::transaction(function () use ($year): int {
            // PostgreSQL: INSERT ... ON CONFLICT ... UPDATE ... RETURNING
            // даёт атомарную инкрементацию.
            $row = DB::selectOne(
                'INSERT INTO request_code_sequences (year, last_value, created_at, updated_at)
                 VALUES (?, 1, NOW(), NOW())
                 ON CONFLICT (year) DO UPDATE
                    SET last_value = request_code_sequences.last_value + 1,
                        updated_at = NOW()
                 RETURNING last_value',
                [$year]
            );

            return (int) $row->last_value;
        });

        return sprintf('M-%d-%04d', $year, $value);
    }
}
