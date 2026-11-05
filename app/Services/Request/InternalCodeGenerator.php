<?php

namespace App\Services\Request;

use Illuminate\Support\Facades\DB;

/**
 * Атомарная генерация internal_code для заявок.
 *
 * Формат: MZ{year}-{NNNNN} — с перезапуска после аварии 02.11.2026 (решение заказчика 05.11):
 * новые заявки продолжают ряд заявок за дни аварии (retro:crash, MZ2026-00001…), а не прежний
 * «M-2026-NNNN» — его номера после восстановления ничего не значат для клиентов и 1С.
 * Номер в письмах распознаёт App\Support\RequestCode.
 *
 * Счётчик — строка request_code_sequences с ключом «минус год» (−2026; ключ — smallint, строка
 * прежнего ряда M- с ключом 2026 остаётся как была). UPSERT … RETURNING под блокировкой строки —
 * атомарно; GREATEST подтягивает счётчик к последнему номеру MZ, уже существующему в заявках
 * (ряд начат ретро-загрузкой, мимо счётчика).
 */
class InternalCodeGenerator
{
    public function next(?int $year = null): string
    {
        $year = $year ?: (int) now()->format('Y');

        $value = DB::transaction(function () use ($year): int {
            $row = DB::selectOne(
                "INSERT INTO request_code_sequences (year, last_value, created_at, updated_at)
                 VALUES (?, (SELECT COALESCE(MAX(CAST(split_part(internal_code, '-', 2) AS integer)), 0) + 1
                             FROM requests WHERE internal_code LIKE ?), NOW(), NOW())
                 ON CONFLICT (year) DO UPDATE
                    SET last_value = GREATEST(request_code_sequences.last_value + 1, EXCLUDED.last_value),
                        updated_at = NOW()
                 RETURNING last_value",
                [-$year, "MZ{$year}-%"]
            );

            return (int) $row->last_value;
        });

        return sprintf('MZ%d-%05d', $year, $value);
    }
}
