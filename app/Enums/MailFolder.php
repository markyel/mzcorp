<?php

namespace App\Enums;

/**
 * Папки почтового клиента менеджера — это СМАРТ-ВИДЫ поверх колонок
 * email_messages (direction / is_draft / related_request_id) и персонального
 * read-state, а не серверные IMAP-папки. Физически письмо лежит в 'Inbox' /
 * 'Sent' (колонка folder), но пользователю показываем логические папки.
 *
 * См. App\Livewire\Mail\Client::applyFolder().
 */
enum MailFolder: string
{
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Flagged = 'flagged';
    case WithRequest = 'with_request';
    case WithoutRequest = 'without_request';

    public function label(): string
    {
        return match ($this) {
            self::Inbox => 'Входящие',
            self::Sent => 'Отправленные',
            self::Drafts => 'Черновики',
            self::Flagged => 'Помеченные',
            self::WithRequest => 'С заявкой',
            self::WithoutRequest => 'Без заявки',
        };
    }

    /** Показывать ли бейдж непрочитанного у папки. */
    public function showsUnread(): bool
    {
        return match ($this) {
            self::Inbox, self::WithoutRequest => true,
            self::Sent, self::Drafts, self::Flagged, self::WithRequest => false,
        };
    }

    /** Порядок в списке папок панели A. */
    public static function ordered(): array
    {
        return [self::Inbox, self::Sent, self::Drafts, self::Flagged, self::WithRequest, self::WithoutRequest];
    }

    public static function tryFromOrDefault(?string $value): self
    {
        return ($value !== null ? self::tryFrom($value) : null) ?? self::Inbox;
    }
}
