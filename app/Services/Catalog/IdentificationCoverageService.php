<?php

namespace App\Services\Catalog;

use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\RequestItem;
use App\Services\AI\OpenAIChatService;
use App\Services\Mail\OutboundToneAuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Что из нужного для опознания детали у позиции уже есть (заказчик 29.11.2026: вопрос нужен ради данных —
 * «фото маркировки» ради маркировки; фото с читаемой маркировкой — ответ полный, фото без неё — неполный).
 *
 * Непривязанная позиция открытой заявки с известным типом детали → модель сверяет цели вопросов справочника
 * с тем, что о позиции известно (название, артикул, марка, прочитанное с фото, письма клиента) и отмечает
 * каждый: known / partial / missing. Результат — request_items.identification_coverage. Проверяется заново,
 * когда меняются позиция, письма клиента или вопросы её типа (basis — отпечаток входа).
 */
class IdentificationCoverageService
{
    public const STATUSES = [
        RequestStatus::New, RequestStatus::Assigned, RequestStatus::InProgress,
        RequestStatus::AwaitingClientClarification, RequestStatus::UnderReview, RequestStatus::PartiallyQuoted,
    ];

    private const LETTERS = 3;

    public function __construct(
        private readonly IdentificationHintService $hints,
        private readonly OpenAIChatService $chat,
        private readonly OutboundToneAuditService $tone,
    ) {}

    /**
     * Проверить позиции, у которых проверки нет или она устарела.
     *
     * @return array{checked: int, assessed: int, failed: int}
     */
    public function assessPending(int $limit = 40): array
    {
        $stats = ['checked' => 0, 'assessed' => 0, 'failed' => 0];
        $statuses = array_map(fn (RequestStatus $s) => $s->value, self::STATUSES);
        // Кандидаты: не проверялись или что-то могло поменяться после последней сверки (позиция, справочник,
        // новое письмо клиента). Сама сверка отпечатка — ниже, в PHP.
        $handbookAt = DB::table('identification_hints')->max('updated_at');
        $items = RequestItem::query()->from('request_items as ri')->select('ri.*')
            ->join('requests as r', 'r.id', '=', 'ri.request_id')
            ->where('ri.is_active', true)->whereNull('ri.catalog_item_id')->whereIn('r.status', $statuses)
            ->where(function ($q) use ($handbookAt) {
                $q->whereNull('ri.identification_coverage')
                    ->orWhereRaw("(ri.identification_coverage->>'checked_at')::timestamptz < ri.updated_at")
                    ->orWhereRaw("(ri.identification_coverage->>'checked_at')::timestamptz < ?", [$handbookAt ?? '1970-01-01'])
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('email_messages as m')
                        ->whereColumn('m.related_request_id', 'ri.request_id')->where('m.direction', 'inbound')
                        ->whereRaw("m.created_at > (ri.identification_coverage->>'checked_at')::timestamptz"));
            })
            ->orderByDesc('ri.id')->limit($limit)->get();

        foreach ($items as $item) {
            $stats['checked']++;
            try {
                $stats['assessed'] += $this->assess($item) ? 1 : 0;
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::warning('IdentificationCoverage: item failed, retry next run', ['request_item_id' => $item->id, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    /**
     * Проверить позицию. true — спрашивали модель; false — отпечаток не изменился или тип не известен.
     */
    public function assess(RequestItem $item): bool
    {
        $type = $this->hints->forName($item->parsed_name);
        $letters = $this->letters($item);
        $basis = self::basis($item, $type, $letters);
        $old = $item->identification_coverage;
        if (is_array($old) && ($old['basis'] ?? null) === $basis) {
            $this->save($item, array_merge($old, ['checked_at' => now()->toIso8601String()]));

            return false;
        }
        if ($type === null || $type['hints'] === []) {
            $this->save($item, ['type' => null, 'basis' => $basis, 'checked_at' => now()->toIso8601String()]);

            return false;
        }

        $known = [];
        foreach (array_values($type['hints']) as $i => $h) {
            $known[] = ($i + 1).') '.$h['label'].' — цель: '.($h['goal'] ?: $h['question']);
        }
        $photos = $item->image_attachment_id !== null ? max(1, (int) DB::table('request_item_photos')->where('request_item_id', $item->id)->count()) : 0;
        $input = "ПОЗИЦИЯ:\nназвание: ".$item->parsed_name
            ."\nартикул: ".($item->parsed_article ?: '—')
            ."\nмарка: ".($item->parsed_brand ?: '—')
            ."\nзаметка разбора (в т.ч. прочитанное с фото и предупреждения): ".(trim((string) $item->supplier_note) ?: '—')
            ."\nфото у позиции: ".($photos > 0 ? $photos : 'нет')
            ."\n\nПИСЬМА КЛИЕНТА (новые первыми):\n".($letters->isEmpty() ? '—' : $letters->map(fn ($l) => '— '.$l['text'])->implode("\n\n"))
            ."\n\nТИП ДЕТАЛИ: ".$type['name']."\nВОПРОСЫ ДЛЯ ОПОЗНАНИЯ:\n".implode("\n", $known);

        $r = $this->chat->chat([
            ['role' => 'system', 'content' => self::prompt()],
            ['role' => 'user', 'content' => $input],
        ], 'gpt-4o-mini', ['response_format' => ['type' => 'json_object'], 'temperature' => 0, 'max_tokens' => 1500]);
        $answers = collect((array) (json_decode((string) ($r['content'] ?? ''), true)['hints'] ?? []))
            ->filter(fn ($a) => is_array($a) && is_numeric($a['n'] ?? null))->keyBy(fn ($a) => (int) $a['n']);
        if ($answers->isEmpty()) {
            throw new \RuntimeException('empty coverage answer');
        }

        $this->save($item, [
            'type' => ['key' => $type['key'], 'name' => $type['name']],
            'hints' => self::merge($type['hints'], $answers->all()),
            'basis' => $basis, 'checked_at' => now()->toIso8601String(), 'assessed_at' => now()->toIso8601String(),
        ]);

        return true;
    }

    /**
     * Ответ модели по номерам вопросов → отметки. Непонятный статус — «нет данных» (лишний вопрос лучше
     * пропущенного).
     *
     * @param  list<array{label: string, question: string}>  $hints
     * @param  array<int, array<string, mixed>>  $answers
     * @return list<array{id: ?int, label: string, question: string, status: string, value: ?string, source: ?string, ask: ?string}>
     */
    public static function merge(array $hints, array $answers): array
    {
        $out = [];
        foreach (array_values($hints) as $i => $h) {
            $a = (array) ($answers[$i + 1] ?? []);
            $status = in_array($a['status'] ?? null, ['known', 'partial', 'missing'], true) ? $a['status'] : 'missing';
            $value = trim((string) ($a['value'] ?? '')) ?: null;
            if ($status === 'known' && $value === null) {
                $status = 'partial'; // «известно», но что — не сказано: не прячем вопрос
            }
            $out[] = [
                'id' => isset($h['id']) ? (int) $h['id'] : null, 'label' => (string) $h['label'], 'question' => (string) $h['question'],
                'status' => $status, 'value' => $status === 'missing' ? null : ($value !== null ? mb_substr($value, 0, 120) : null),
                'source' => in_array($a['source'] ?? null, ['name', 'article', 'photo', 'letter'], true) ? $a['source'] : null,
                'ask' => $status === 'partial' ? (trim((string) ($a['ask'] ?? '')) ?: null) : null,
            ];
        }

        return $out;
    }

    /**
     * Отпечаток входа проверки: позиция, вопросы типа, письма клиента.
     *
     * @param  Collection<int, array{id: int, text: string}>  $letters
     */
    public static function basis(RequestItem $item, ?array $type, Collection $letters): string
    {
        return sha1(json_encode([
            $item->parsed_name, $item->parsed_article, $item->parsed_brand, $item->supplier_note, $item->image_attachment_id,
            $type === null ? null : [$type['key'], array_map(fn ($h) => [$h['id'] ?? null, $h['label'], $h['question'], $h['goal'] ?? null], $type['hints'])],
            $letters->pluck('id')->all(),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Письма клиента по заявке: письмо-основание позиции и последние входящие.
     *
     * @return Collection<int, array{id: int, text: string}>
     */
    private function letters(RequestItem $item): Collection
    {
        return EmailMessage::query()->where('direction', 'inbound')
            ->where(fn ($q) => $q->where('related_request_id', $item->request_id)->when($item->source_email_message_id, fn ($q) => $q->orWhere('id', $item->source_email_message_id)))
            ->orderByDesc('id')->limit(self::LETTERS)->get()
            ->map(fn (EmailMessage $m) => ['id' => $m->id, 'text' => mb_substr($this->tone->ownText($m), 0, 1200)])
            ->filter(fn ($l) => trim($l['text']) !== '')->values();
    }

    private function save(RequestItem $item, array $coverage): void
    {
        // Мимо Eloquent: проверка — не правка позиции, updated_at не трогаем (иначе проверка будит сама себя).
        DB::table('request_items')->where('id', $item->id)->update(['identification_coverage' => json_encode($coverage, JSON_UNESCAPED_UNICODE)]);
        $item->setRawAttributes(array_merge($item->getAttributes(), ['identification_coverage' => json_encode($coverage, JSON_UNESCAPED_UNICODE)]), true);
    }

    private static function prompt(): string
    {
        return <<<'P'
Ты проверяешь, что уже известно о позиции заявки на запчасть для лифта или эскалатора, чтобы менеджер не
спрашивал у клиента лишнего. Дано: позиция (название, артикул, марка, заметка разбора — там текст, прочитанный
с фото и шильдика, и предупреждения о сомнительном чтении), сколько фото у позиции, письма клиента, тип детали
и вопросы для опознания с ЦЕЛЬЮ — какие данные вопрос должен дать.

По каждому вопросу (n — его номер):
- status:
  known — данные цели уже есть: в названии, артикуле, письме клиента или прочитаны с фото;
  partial — есть частично или ненадёжно: фото есть, но нужное на нём не прочитано; прочитано с сомнением
    (в заметке сказано, что модели прочитали по-разному или «не подтверждена»); названа только часть нужного;
  missing — данных нет;
- value: что известно, коротко (для known и partial), например «YG-30s», «Otis Gen2, левая»;
- source: name | article | photo | letter — откуда;
- ask: только для partial — короткий вежливый вопрос клиенту, что подтвердить или дополнить, без номеров
  позиций и имён («На фото читается YG-30s — подтвердите, пожалуйста, маркировку.»); иначе null.

Важно: вопрос «фото чего-то» нужен ради данных — если эти данные уже известны (из текста или прочитаны с фото),
это known, даже без фото. Точный артикул или код модели детали в позиции (не тип и не марка лифта) — ответ на
вопросы, цель которых — опознать модель, маркировку, артикул или саму деталь по виду: known, value — этот код,
source article или name. Вопросы про совместимость (марка лифта, станция, сторона, размеры) артикул не закрывает. Фото есть, но нужные данные с него не прочитаны — partial, не known. Не придумывай
данных, которых нет во входе; общие слова названия («для лифта») — не ответ на «где стоит / какой лифт».
Ответ строго JSON: {"hints": [{"n": 1, "status": "known", "value": "...", "source": "photo", "ask": null}]}.
P;
    }
}
