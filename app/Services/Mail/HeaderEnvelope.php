<?php

namespace App\Services\Mail;

use Illuminate\Support\Collection;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Header;

/**
 * Письмо, от которого у нас только заголовки и флаги (история ящика: FETCH
 * HEADER.FIELDS без тела). Отдаёт те же геттеры, что webklex Message, — чтобы
 * MessagePersister извлекал адреса, тему и дату одним и тем же кодом.
 */
final class HeaderEnvelope
{
    /** @param  list<string>  $flags */
    public function __construct(
        private readonly Header $header,
        private readonly array $flags,
    ) {}

    public function getMessageId(): ?Attribute
    {
        return $this->attr('message_id');
    }

    public function getInReplyTo(): ?Attribute
    {
        return $this->attr('in_reply_to');
    }

    public function getReferences(): ?Attribute
    {
        return $this->attr('references');
    }

    public function getSubject(): ?Attribute
    {
        return $this->attr('subject');
    }

    public function getFrom(): ?Attribute
    {
        return $this->attr('from');
    }

    public function getTo(): ?Attribute
    {
        return $this->attr('to');
    }

    public function getCc(): ?Attribute
    {
        return $this->attr('cc');
    }

    public function getDate(): ?Attribute
    {
        return $this->attr('date');
    }

    public function getFlags(): Collection
    {
        return collect($this->flags);
    }

    /** multipart/mixed — почти всегда есть вложение: скрепка в списке до скачивания. */
    public function looksLikeWithAttachments(): bool
    {
        $ct = mb_strtolower(implode(' ', array_map('strval', $this->attr('content_type')?->toArray() ?? [])));

        return str_contains($ct, 'multipart/mixed') || str_contains($ct, 'multipart/report');
    }

    private function attr(string $name): ?Attribute
    {
        try {
            $a = $this->header->get($name);
        } catch (\Throwable) {
            return null;
        }

        return $a instanceof Attribute && $a->toArray() !== [] ? $a : null;
    }
}
