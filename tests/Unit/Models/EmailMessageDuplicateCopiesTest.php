<?php

namespace Tests\Unit\Models;

use App\Models\EmailMessage;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Скрытие cross-mailbox копий в переписке заявки.
 *
 * Копия — то же письмо, доставленное в личный ящик менеджера. Показывать обе
 * не нужно, но если оригинал увели в другую заявку (клиент дописал позиции →
 * родилась новая заявка), копия остаётся единственным экземпляром письма в
 * старой заявке. Пряча её, мы теряли переписку целиком — кейс M-2026-15292.
 */
class EmailMessageDuplicateCopiesTest extends TestCase
{
    private function msg(int $id, ?int $copyOf = null): EmailMessage
    {
        $m = new EmailMessage;
        $m->id = $id;
        $m->detected_artifacts = $copyOf === null ? [] : ['cross_mailbox_copy_of' => $copyOf];

        return $m;
    }

    public function test_copy_is_hidden_when_its_original_is_in_the_thread(): void
    {
        $thread = new Collection([$this->msg(1), $this->msg(2, 1)]);

        $this->assertSame([1], EmailMessage::dropDuplicateCopies($thread)->pluck('id')->all());
    }

    public function test_copy_stays_when_the_original_belongs_elsewhere(): void
    {
        // Оригинал (#99) в этой заявке не числится — копия единственная.
        $thread = new Collection([$this->msg(1), $this->msg(2, 99)]);

        $this->assertSame([1, 2], EmailMessage::dropDuplicateCopies($thread)->pluck('id')->all());
    }

    public function test_thread_without_copies_is_untouched(): void
    {
        $thread = new Collection([$this->msg(1), $this->msg(2), $this->msg(3)]);

        $this->assertSame([1, 2, 3], EmailMessage::dropDuplicateCopies($thread)->pluck('id')->all());
    }

    public function test_order_is_preserved_and_keys_are_reindexed(): void
    {
        $thread = new Collection([$this->msg(5), $this->msg(6, 5), $this->msg(7)]);
        $result = EmailMessage::dropDuplicateCopies($thread);

        $this->assertSame([5, 7], $result->pluck('id')->all());
        $this->assertSame([0, 1], $result->keys()->all());
    }

    public function test_string_id_in_artifacts_still_matches(): void
    {
        // detected_artifacts — jsonb, id оттуда приходит и строкой.
        $copy = $this->msg(2);
        $copy->detected_artifacts = ['cross_mailbox_copy_of' => '1'];

        $this->assertSame([1], EmailMessage::dropDuplicateCopies(new Collection([$this->msg(1), $copy]))->pluck('id')->all());
    }
}
