<?php

namespace App\Services\Catalog;

use App\Models\EmailMessage;
use App\Models\Identification\IdentificationHint;
use App\Models\Identification\IdentificationObservation;
use App\Models\Identification\IdentificationPartType;
use App\Models\RequestItem;
use App\Services\AI\OpenAIChatService;
use App\Services\Mail\OutboundToneAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Справочник опознания учится на письмах-уточнениях менеджеров клиентам (заказчик 28.11.2026: «вести на
 * постоянной основе и пополнять автоматически»).
 *
 * Письмо (AiDecision outbound_clarification) → модель выделяет вопросы для опознания детали, узнаёт уже
 * известные (по типам деталей позиций заявки) или называет новые. Известный вопрос: +1 к счётчику. Новый —
 * кандидат; задан в PROMOTE_AFTER разных письмах — становится подсказкой сам. Деталь без известного типа,
 * о которой спросили в NEW_TYPE_AFTER письмах, — кандидат в типы: правило распознавания составлено
 * автоматически, поэтому включает его РОП на странице справочника.
 */
class IdentificationLearner
{
    public const PROMOTE_AFTER = 3;

    public const NEW_TYPE_AFTER = 3;

    /** Общие слова вместо типа детали — не тип. */
    private const GENERIC_TYPES = ['деталь', 'детали', 'запчасть', 'запчасти', 'лифт', 'оборудование', 'позиция', 'позиции', 'товар', 'изделие', 'эскалатор', 'деталь не указана'];

    public function __construct(
        private readonly OpenAIChatService $chat,
        private readonly OutboundToneAuditService $tone,
        private readonly IdentificationHintService $hints,
    ) {}

    /**
     * Разобрать ещё не разобранные письма-уточнения (старые — первыми).
     *
     * @return array{letters: int, questions: int, promoted: int, new_types: int}
     */
    public function learn(int $limit = 200): array
    {
        $stats = ['letters' => 0, 'questions' => 0, 'promoted' => 0, 'new_types' => 0];
        $decisions = DB::table('ai_decisions as d')
            ->where('d.detector_type', 'outbound_clarification')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('identification_observations as o')->whereColumn('o.email_message_id', 'd.email_message_id'))
            ->orderBy('d.id')->limit($limit)->get(['d.email_message_id', 'd.request_id']);

        foreach ($decisions as $d) {
            $m = EmailMessage::find($d->email_message_id);
            if ($m === null || $m->getRawOriginal('direction') !== 'outbound') {
                IdentificationObservation::create(['email_message_id' => $d->email_message_id, 'request_id' => $d->request_id, 'purpose' => 'none']);

                continue;
            }
            try {
                $r = $this->learnLetter($m, $d->request_id ? (int) $d->request_id : null);
                $stats['letters']++;
                $stats['questions'] += $r['questions'];
                $stats['promoted'] += $r['promoted'];
            } catch (\Throwable $e) {
                Log::warning('IdentificationLearner: letter failed, retry next run', ['email_message_id' => $m->id, 'error' => $e->getMessage()]);
            }
        }
        $stats['new_types'] = $this->proposeNewTypes();
        IdentificationHintService::forget();

        return $stats;
    }

    /**
     * @return array{questions: int, promoted: int}
     */
    public function learnLetter(EmailMessage $m, ?int $requestId): array
    {
        $text = $this->tone->ownText($m);
        $items = $requestId ? RequestItem::query()->where('request_id', $requestId)->where('is_active', true)->orderBy('position')->get(['id', 'position', 'parsed_name', 'parsed_brand', 'parsed_article']) : collect();
        $types = IdentificationPartType::query()->with(['hints' => fn ($q) => $q->whereIn('status', [IdentificationHint::ACTIVE, IdentificationHint::CANDIDATE])])->get();
        $typeRows = $types->map(fn ($t) => ['id' => $t->id, 'key' => $t->key, 'name' => $t->name, 'pattern' => $t->pattern, 'hints' => []])->all();

        // Тип детали у каждой позиции — и известные вопросы по этим типам для модели.
        $itemType = [];
        $lines = [];
        foreach ($items->values() as $k => $it) {
            $t = IdentificationHintService::typeOf((string) $it->parsed_name, $typeRows);
            $itemType[$k + 1] = $t ? $types->firstWhere('key', $t['key']) : null;
            $lines[] = ($k + 1).') '.trim($it->parsed_name.($it->parsed_brand ? ' · '.$it->parsed_brand : '').($it->parsed_article ? ' · арт. '.$it->parsed_article : ''));
        }
        $known = [];
        foreach (collect($itemType)->filter()->unique('id') as $t) {
            foreach ($t->hints as $h) {
                $known[] = $h->id.': ['.$t->name.'] '.$h->label.' — '.$h->question;
            }
        }

        if (mb_strlen($text) < 15) {
            IdentificationObservation::create(['email_message_id' => $m->id, 'request_id' => $requestId, 'purpose' => 'none']);

            return ['questions' => 0, 'promoted' => 0];
        }

        $r = $this->chat->chat([
            ['role' => 'system', 'content' => self::prompt()],
            ['role' => 'user', 'content' => "ПОЗИЦИИ ЗАЯВКИ:\n".($lines ? implode("\n", $lines) : '(нет)')
                ."\n\nИЗВЕСТНЫЕ ВОПРОСЫ (id: [тип] название — формулировка):\n".($known ? implode("\n", $known) : '(нет)')
                ."\n\nПИСЬМО МЕНЕДЖЕРА:\n".mb_substr($text, 0, 2500)],
        ], 'gpt-4o-mini', ['response_format' => ['type' => 'json_object'], 'temperature' => 0, 'max_tokens' => 2500]);
        $questions = (array) (json_decode((string) ($r['content'] ?? ''), true)['questions'] ?? []);

        $promoted = 0;
        $counted = [];
        DB::transaction(function () use ($questions, $m, $requestId, $itemType, $types, &$promoted, &$counted) {
            if ($questions === []) {
                IdentificationObservation::create(['email_message_id' => $m->id, 'request_id' => $requestId, 'purpose' => 'none']);

                return;
            }
            foreach ($questions as $q) {
                if (! is_array($q)) {
                    continue;
                }
                $purpose = in_array($q['purpose'] ?? null, ['identify', 'commercial', 'other'], true) ? $q['purpose'] : 'other';
                $rawType = mb_substr(trim((string) ($q['part_type'] ?? '')), 0, 120);
                $type = null;
                $hint = null;
                if ($purpose === 'identify') {
                    $pos = is_numeric($q['position'] ?? null) ? (int) $q['position'] : null;
                    $type = $pos !== null ? ($itemType[$pos] ?? null) : null;
                    $knownId = is_numeric($q['known_id'] ?? null) ? (int) $q['known_id'] : null;
                    if ($knownId !== null) {
                        $hint = IdentificationHint::query()->find($knownId);
                        $type ??= $hint?->partType;
                        if ($hint !== null && $type !== null && $hint->part_type_id !== $type->id) {
                            $hint = null; // узнанный вопрос — от другого типа: не смешиваем
                        }
                    }
                    if ($type === null && $rawType !== '') {
                        $t = IdentificationHintService::typeOf($rawType, $types->map(fn ($t) => ['key' => $t->key, 'name' => $t->name, 'pattern' => $t->pattern, 'hints' => []])->all());
                        $type = $t ? $types->firstWhere('key', $t['key']) : null;
                    }
                    if ($type !== null && $hint === null) {
                        $hint = $this->candidateHint($type, (string) ($q['label'] ?? $q['attribute'] ?? ''), (string) ($q['client_question'] ?? $q['question'] ?? ''));
                    }
                    if ($hint !== null && ! isset($counted[$hint->id])) {
                        $counted[$hint->id] = true;
                        $hint->asked_count++;
                        $hint->last_asked_at = $m->sent_at ?? now();
                        if ($hint->status === IdentificationHint::CANDIDATE && $hint->asked_count >= self::PROMOTE_AFTER) {
                            $hint->status = IdentificationHint::ACTIVE;
                            $promoted++;
                        }
                        $hint->save();
                    }
                }
                IdentificationObservation::create([
                    'email_message_id' => $m->id, 'request_id' => $requestId,
                    'part_type_id' => $type?->id, 'hint_id' => $hint?->id,
                    'part_type_raw' => $rawType ?: null,
                    'attribute_raw' => mb_substr(trim((string) ($q['attribute'] ?? $q['label'] ?? '')), 0, 160) ?: null,
                    'question' => mb_substr(trim((string) ($q['question'] ?? '')), 0, 1000) ?: null,
                    'purpose' => $purpose,
                ]);
            }
        });

        return ['questions' => count($questions), 'promoted' => $promoted];
    }

    /** Новый вопрос типа — кандидат; повтор того же названия — тот же кандидат. */
    private function candidateHint(IdentificationPartType $type, string $label, string $question): ?IdentificationHint
    {
        $label = mb_substr(trim($label), 0, 120);
        $question = trim($question);
        if ($label === '' || $question === '') {
            return null;
        }
        $same = IdentificationHint::query()->where('part_type_id', $type->id)->whereRaw('lower(label) = ?', [mb_strtolower($label)])->first();

        return $same ?? IdentificationHint::create([
            'part_type_id' => $type->id, 'label' => mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1),
            'question' => $question, 'status' => IdentificationHint::CANDIDATE, 'source' => 'auto', 'asked_count' => 0, 'sort' => 500,
        ]);
    }

    /**
     * Детали без известного типа, о которых спросили в NEW_TYPE_AFTER разных письмах, — кандидаты в типы
     * (выключены до подтверждения РОПом) с их вопросами.
     */
    private function proposeNewTypes(): int
    {
        $rows = DB::table('identification_observations')->where('purpose', 'identify')->whereNull('part_type_id')->whereNotNull('part_type_raw')
            ->selectRaw('lower(part_type_raw) as t, count(distinct email_message_id) as letters')->groupBy('t')
            ->havingRaw('count(distinct email_message_id) >= ?', [self::NEW_TYPE_AFTER])->get();
        $created = 0;
        foreach ($rows as $row) {
            $name = trim((string) $row->t);
            if ($name === '' || in_array($name, self::GENERIC_TYPES, true) || IdentificationPartType::query()->whereRaw('lower(name) = ?', [$name])->exists()) {
                continue;
            }
            $stem = mb_substr((string) preg_split('/\s+/u', $name)[0], 0, max(4, min(7, mb_strlen((string) preg_split('/\s+/u', $name)[0]) - 1)));
            $type = IdentificationPartType::create([
                'key' => 'auto_'.Str::slug(Str::transliterate($name), '_').'_'.Str::lower(Str::random(4)),
                'name' => $name, 'pattern' => '/'.preg_quote($stem, '/').'/iu', 'sort' => 900, 'is_active' => false, 'source' => 'auto',
            ]);
            $obs = DB::table('identification_observations')->where('purpose', 'identify')->whereNull('part_type_id')->whereRaw('lower(part_type_raw) = ?', [$name])->get();
            foreach ($obs->groupBy(fn ($o) => mb_strtolower((string) $o->attribute_raw)) as $attr => $group) {
                if ($attr === '') {
                    continue;
                }
                $letters = $group->pluck('email_message_id')->unique()->count();
                $hint = IdentificationHint::create([
                    'part_type_id' => $type->id, 'label' => mb_strtoupper(mb_substr($attr, 0, 1)).mb_substr($attr, 1),
                    'question' => (string) $group->first()->question, 'source' => 'auto', 'sort' => 500, 'asked_count' => $letters,
                    'status' => $letters >= self::PROMOTE_AFTER ? IdentificationHint::ACTIVE : IdentificationHint::CANDIDATE,
                ]);
                DB::table('identification_observations')->whereIn('id', $group->pluck('id'))->update(['part_type_id' => $type->id, 'hint_id' => $hint->id]);
            }
            $created++;
        }

        return $created;
    }

    private static function prompt(): string
    {
        return <<<'P'
Ты разбираешь письмо менеджера компании-поставщика запчастей для лифтов и эскалаторов клиенту. Менеджер
задаёт уточняющие вопросы по заявке. Даны позиции заявки, уже известные вопросы по типам деталей и текст письма.

Выдели ВСЕ вопросы и просьбы менеджера. Для каждого:
- position: номер позиции из списка, к которой вопрос (или null — ко всей заявке / непонятно);
- part_type: что за деталь, обобщённо, в именительном падеже (кнопка, плата управления, ролик, цепь привода…);
- purpose: identify — чтобы ОПОЗНАТЬ, какая именно деталь нужна (модель, размер, исполнение, фото, шильдик,
  маркировка, модель лифта, сторона, символ…); commercial — сроки, количество, цена, оплата, доставка, реквизиты,
  выбор аналога/оригинала; other — прочее;
- attribute: что спрашивают — коротко (символ на кнопке, длина, напряжение катушки, фото шильдика, марка лифта…);
- question: формулировка менеджера коротко;
- known_id: если по смыслу это один из ИЗВЕСТНЫХ ВОПРОСОВ того же типа детали — его id, иначе null;
- label: если known_id = null — короткое название вопроса, 2–4 слова (как «Фото таблички», «Напряжение катушки»);
- client_question: если known_id = null — вежливая формулировка для клиента в общем виде, без имён, номеров
  позиций и деталей этой заявки («Пришлите, пожалуйста, фото таблички с двигателя.»).
Ответ строго JSON: {"questions": [{"position": 1, "part_type": "...", "purpose": "identify", "attribute": "...",
"question": "...", "known_id": 12, "label": null, "client_question": null}]}. Вопросов нет — {"questions": []}.
P;
    }
}
