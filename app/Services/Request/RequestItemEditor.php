<?php

namespace App\Services\Request;

use App\Models\Request;
use App\Models\RequestItem;
use Illuminate\Support\Facades\DB;

/**
 * Ручные операции менеджера над позициями заявки.
 *
 * Парсер пишет позиции через RequestItemPersister (data_source='inbound_message').
 * Здесь — операции, инициированные оператором из UI: добавление новой позиции
 * в существующую заявку (split позиций / удаление пока не реализованы — см.
 * текущий scope).
 *
 * data_source='manual' — маркер для аналитики и аудита: позиция не из письма
 * клиента, не от Vision/parser, а введена менеджером (или РОПом) вручную.
 */
class RequestItemEditor
{
    /**
     * Добавить позицию в заявку. Позиция получает next-номер (max position+1)
     * среди is_active=true позиций заявки. Source-tag = 'manual', чтобы в
     * UI/аналитике видеть происхождение.
     *
     * Permission-чек выполняется на уровне Livewire-компонента (assigned +
     * delegate + privileged). Здесь — чистая запись.
     *
     * @param array{
     *     name: string,
     *     brand?: ?string,
     *     article?: ?string,
     *     qty?: float|int|string|null,
     *     unit?: ?string,
     *     note?: ?string,
     * } $data
     */
    public function addManual(Request $request, array $data): RequestItem
    {
        return DB::transaction(function () use ($request, $data): RequestItem {
            // max+1 среди ВСЕХ позиций заявки (включая is_active=false),
            // чтобы позиция-номер не повторился если когда-нибудь добавим
            // soft-deactivate (нумерация не должна «прыгать на старую»).
            $maxPosition = (int) $request->items()->max('position');

            return RequestItem::create([
                'request_id' => $request->id,
                'position' => $maxPosition + 1,
                'parsed_name' => trim($data['name']),
                'parsed_brand' => $this->nullableTrim($data['brand'] ?? null),
                'parsed_article' => $this->nullableTrim($data['article'] ?? null),
                'parsed_qty' => $this->normalizeQty($data['qty'] ?? null),
                'parsed_unit' => $this->nullableTrim($data['unit'] ?? null) ?? 'шт.',
                'supplier_note' => $this->nullableTrim($data['note'] ?? null),
                'data_source' => 'manual',
                'status' => 'parsed',
                'is_active' => true,
            ]);
        });
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeQty(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 1.0;
        }
        $normalized = (float) str_replace(',', '.', (string) $value);

        return $normalized > 0 ? $normalized : 1.0;
    }
}
