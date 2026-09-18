<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\MailLabel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Метки писем в НАШЕМ почтовом клиенте: словарь и развешивание по письмам.
 *
 * Не путать с MailLabelService — тот ставит IMAP-keyword на сервере Яндекса,
 * чтобы секретарь видел пометку в вебе. Здесь метки живут только в mzCorp,
 * их может быть у письма сколько угодно, и они ничего не шлют наружу.
 *
 * Словарь общий на компанию, поэтому создание идёт через нормализацию имени и
 * поиск существующей метки без учёта регистра — иначе из «Тендер» и «тендер»
 * получились бы две разные метки с одинаковым смыслом.
 */
class MessageLabelService
{
    /** Найти существующую метку по имени (без учёта регистра) или создать. */
    public function findOrCreate(string $name, ?string $color, ?User $by): ?MailLabel
    {
        $name = MailLabel::normalizeName($name);
        if ($name === '') {
            return null;
        }

        $existing = MailLabel::query()->whereRaw('lower(name) = lower(?)', [$name])->first();
        if ($existing !== null) {
            return $existing;
        }

        return MailLabel::create([
            'name' => $name,
            'color' => MailLabel::isValidColor($color) ? $color : 'sky',
            'sort_order' => (int) MailLabel::query()->max('sort_order') + 1,
            'created_by_user_id' => $by?->id,
        ]);
    }

    public function rename(MailLabel $label, string $name): bool
    {
        $name = MailLabel::normalizeName($name);
        if ($name === '') {
            return false;
        }
        $taken = MailLabel::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->whereKeyNot($label->id)
            ->exists();
        if ($taken) {
            return false;
        }
        $label->name = $name;
        $label->save();

        return true;
    }

    public function recolor(MailLabel $label, string $color): void
    {
        if (MailLabel::isValidColor($color)) {
            $label->color = $color;
            $label->save();
        }
    }

    /** Удалить метку вместе со всеми её привязками (каскад в FK). */
    public function delete(MailLabel $label): void
    {
        $label->delete();
    }

    /**
     * Повесить или снять метку на письмах. Возвращает число затронутых писем.
     *
     * @param  array<int, int>  $messageIds  уже проверенные на доступ id
     */
    public function apply(array $messageIds, MailLabel $label, bool $on, ?User $by): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
        if ($ids === []) {
            return 0;
        }

        if (! $on) {
            return DB::table('email_message_labels')
                ->where('mail_label_id', $label->id)
                ->whereIn('email_message_id', $ids)
                ->delete();
        }

        // Уже помеченные пропускаем: unique-индекс всё равно не даст дубля,
        // но вставка на каждое письмо дороже одного SELECT.
        $already = DB::table('email_message_labels')
            ->where('mail_label_id', $label->id)
            ->whereIn('email_message_id', $ids)
            ->pluck('email_message_id')
            ->all();
        $fresh = array_values(array_diff($ids, $already));
        if ($fresh === []) {
            return 0;
        }

        $now = now();
        DB::table('email_message_labels')->insert(array_map(fn (int $id) => [
            'email_message_id' => $id,
            'mail_label_id' => $label->id,
            'assigned_by_user_id' => $by?->id,
            'created_at' => $now,
        ], $fresh));

        return count($fresh);
    }

    /** Переключить метку на одном письме. Возвращает новое состояние. */
    public function toggle(EmailMessage $message, MailLabel $label, ?User $by): bool
    {
        $has = DB::table('email_message_labels')
            ->where('mail_label_id', $label->id)
            ->where('email_message_id', $message->id)
            ->exists();

        $this->apply([$message->id], $label, ! $has, $by);

        return ! $has;
    }

    /**
     * Сколько писем помечено каждой меткой — для счётчиков в фильтре.
     *
     * @param  array<int, int>  $mailboxIds
     * @return array<int, int> label_id => количество
     */
    public function counts(array $mailboxIds): array
    {
        if ($mailboxIds === []) {
            return [];
        }

        return DB::table('email_message_labels as eml')
            ->join('email_messages as m', 'm.id', '=', 'eml.email_message_id')
            ->whereIn('m.mailbox_id', $mailboxIds)
            ->groupBy('eml.mail_label_id')
            ->selectRaw('eml.mail_label_id, count(*) as n')
            ->pluck('n', 'eml.mail_label_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
