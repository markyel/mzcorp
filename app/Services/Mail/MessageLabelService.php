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
 * Набор меток ЛИЧНЫЙ: у каждого менеджера свой. Поэтому имя уникально в
 * пределах владельца («Срочно» может быть у каждого), а поиск существующей
 * метки идёт по владельцу и имени без учёта регистра — иначе у одного человека
 * из «Тендер» и «тендер» получились бы две метки с одинаковым смыслом.
 */
class MessageLabelService
{
    /** Найти метку пользователя по имени (без учёта регистра) или создать. */
    public function findOrCreate(string $name, ?string $color, ?User $owner): ?MailLabel
    {
        $name = MailLabel::normalizeName($name);
        if ($name === '' || $owner === null) {
            return null;
        }

        $existing = MailLabel::query()
            ->ownedBy($owner)
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return MailLabel::create([
            'owner_user_id' => $owner->id,
            'name' => $name,
            'color' => MailLabel::isValidColor($color) ? $color : 'sky',
            'sort_order' => (int) MailLabel::query()->ownedBy($owner)->max('sort_order') + 1,
            'created_by_user_id' => $owner->id,
        ]);
    }

    public function rename(MailLabel $label, string $name): bool
    {
        $name = MailLabel::normalizeName($name);
        if ($name === '') {
            return false;
        }
        $taken = MailLabel::query()
            ->where('owner_user_id', $label->owner_user_id)
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
     * Сколько писем помечено каждой меткой ВЛАДЕЛЬЦА — для счётчиков в фильтре.
     *
     * @param  array<int, int>  $mailboxIds
     * @return array<int, int> label_id => количество
     */
    public function counts(array $mailboxIds, ?User $owner): array
    {
        if ($mailboxIds === [] || $owner === null) {
            return [];
        }

        return DB::table('email_message_labels as eml')
            ->join('email_messages as m', 'm.id', '=', 'eml.email_message_id')
            ->join('mail_labels as l', 'l.id', '=', 'eml.mail_label_id')
            ->where('l.owner_user_id', $owner->id)
            ->whereIn('m.mailbox_id', $mailboxIds)
            ->groupBy('eml.mail_label_id')
            ->selectRaw('eml.mail_label_id, count(*) as n')
            ->pluck('n', 'eml.mail_label_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
