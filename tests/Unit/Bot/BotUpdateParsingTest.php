<?php

namespace Tests\Unit\Bot;

use App\Enums\MessengerChannel;
use App\Services\Bot\BotPriceService;
use App\Services\Bot\BotRequestService;
use App\Services\Bot\BotUpdate;
use Tests\TestCase;

/** Разбор событий Telegram/MAX в общий вид, артикулы и строки черновика. */
class BotUpdateParsingTest extends TestCase
{
    public function test_telegram_private_message_and_callback(): void
    {
        $m = BotUpdate::fromTelegram(['update_id' => 5, 'message' => [
            'from' => ['id' => 77, 'first_name' => 'Иван', 'username' => 'ivan'],
            'chat' => ['id' => 77, 'type' => 'private'], 'text' => ' M12345 2 ',
        ]]);
        $this->assertSame(MessengerChannel::Telegram, $m->channel);
        $this->assertSame(['5', '77', '77', 'M12345 2'], [$m->updateId, $m->userId, $m->chatId, $m->text]);
        $this->assertFalse($m->isCallback());

        $cb = BotUpdate::fromTelegram(['update_id' => 6, 'callback_query' => [
            'id' => 'cb1', 'data' => 'add:M12345', 'from' => ['id' => 77], 'message' => ['chat' => ['id' => 77, 'type' => 'private']],
        ]]);
        $this->assertTrue($cb->isCallback());
        $this->assertSame('add:M12345', $cb->callbackData);
    }

    public function test_telegram_groups_and_foreign_contacts_are_ignored(): void
    {
        $this->assertNull(BotUpdate::fromTelegram(['update_id' => 1, 'message' => [
            'from' => ['id' => 1], 'chat' => ['id' => -100, 'type' => 'group'], 'text' => 'hi',
        ]]));

        $foreign = BotUpdate::fromTelegram(['update_id' => 2, 'message' => [
            'from' => ['id' => 1], 'chat' => ['id' => 1, 'type' => 'private'],
            'contact' => ['phone_number' => '+79001234567', 'user_id' => 999],
        ]]);
        $this->assertNull($foreign->contactPhone);

        $own = BotUpdate::fromTelegram(['update_id' => 3, 'message' => [
            'from' => ['id' => 1], 'chat' => ['id' => 1, 'type' => 'private'],
            'contact' => ['phone_number' => '+79001234567', 'user_id' => 1],
        ]]);
        $this->assertSame('+79001234567', $own->contactPhone);
    }

    public function test_max_message_callback_and_start(): void
    {
        $m = BotUpdate::fromMax(['update_type' => 'message_created', 'timestamp' => 1, 'message' => [
            'sender' => ['user_id' => 501, 'first_name' => 'Анна'],
            'recipient' => ['chat_id' => 9001, 'chat_type' => 'dialog'],
            'body' => ['mid' => 'mid.1', 'text' => 'привет'],
        ]]);
        $this->assertSame(MessengerChannel::Max, $m->channel);
        $this->assertSame(['msgmid.1', '501', '9001', 'привет'], [$m->updateId, $m->userId, $m->chatId, $m->text]);

        $cb = BotUpdate::fromMax(['update_type' => 'message_callback', 'callback' => [
            'callback_id' => 'c-1', 'payload' => 'send', 'user' => ['user_id' => 501],
        ], 'message' => ['recipient' => ['chat_id' => 9001]]]);
        $this->assertSame('send', $cb->callbackData);

        $start = BotUpdate::fromMax(['update_type' => 'bot_started', 'chat_id' => 9001, 'user' => ['user_id' => 501], 'timestamp' => 7]);
        $this->assertTrue($start->isStart);

        $this->assertNull(BotUpdate::fromMax(['update_type' => 'message_created', 'message' => [
            'sender' => ['user_id' => 501], 'recipient' => ['chat_id' => -5, 'chat_type' => 'chat'], 'body' => ['text' => 'x'],
        ]]));
    }

    public function test_max_contact_from_vcf(): void
    {
        $m = BotUpdate::fromMax(['update_type' => 'message_created', 'message' => [
            'sender' => ['user_id' => 501],
            'recipient' => ['chat_id' => 9001, 'chat_type' => 'dialog'],
            'body' => ['mid' => 'm2', 'attachments' => [['type' => 'contact', 'payload' => [
                'vcf_info' => "BEGIN:VCARD\nTEL;TYPE=cell:+7 900 123-45-67\nEND:VCARD", 'max_info' => ['user_id' => 501],
            ]]]],
        ]]);
        $this->assertSame('+7 900 123-45-67', $m->contactPhone);
    }

    public function test_telegram_photo_with_caption_and_document(): void
    {
        $photo = BotUpdate::fromTelegram(['update_id' => 9, 'message' => [
            'from' => ['id' => 77], 'chat' => ['id' => 77, 'type' => 'private'], 'media_group_id' => 'g1',
            'caption' => 'шильдик лифта',
            'photo' => [['file_id' => 'small', 'file_size' => 100], ['file_id' => 'big', 'file_size' => 90000]],
        ]]);
        $this->assertSame('шильдик лифта', $photo->text);
        $this->assertSame('big', $photo->files[0]['ref']);
        $this->assertSame('photo', $photo->files[0]['kind']);
        $this->assertSame('g1', $photo->mediaGroupId);

        $doc = BotUpdate::fromTelegram(['update_id' => 10, 'message' => [
            'from' => ['id' => 77], 'chat' => ['id' => 77, 'type' => 'private'],
            'document' => ['file_id' => 'd1', 'file_name' => 'список.xlsx', 'mime_type' => 'application/vnd.ms-excel', 'file_size' => 2048],
        ]]);
        $this->assertSame(['kind' => 'file', 'ref' => 'd1', 'name' => 'список.xlsx', 'mime' => 'application/vnd.ms-excel', 'size' => 2048], $doc->files[0]);
    }

    public function test_max_image_and_file_attachments(): void
    {
        $m = BotUpdate::fromMax(['update_type' => 'message_created', 'message' => [
            'sender' => ['user_id' => 501],
            'recipient' => ['chat_id' => 9001, 'chat_type' => 'dialog'],
            'body' => ['mid' => 'm3', 'text' => 'вот деталь', 'attachments' => [
                ['type' => 'image', 'payload' => ['url' => 'https://i.max/1.jpg']],
                ['type' => 'file', 'filename' => 'spec.pdf', 'payload' => ['url' => 'https://f.max/2']],
            ]],
        ]]);
        $this->assertSame('вот деталь', $m->text);
        $this->assertSame(['photo', 'file'], array_column($m->files, 'kind'));
        $this->assertSame('spec.pdf', $m->files[1]['name']);
    }

    public function test_skus_accept_cyrillic_m_and_ignore_invoice_numbers(): void
    {
        $this->assertSame(['M12345', 'M0083'], BotPriceService::skusIn('нужны м12345 и M0083, счёт М3-10672'));
        $this->assertSame([], BotPriceService::skusIn('ЗК-2026-1100'));
    }

    public function test_draft_line_reads_sku_and_quantity(): void
    {
        $this->assertSame(['sku' => 'M12345', 'qty' => 5.0, 'text' => 'M12345 x 5 шт'], BotRequestService::parseLine('M12345 x 5 шт'));
        $this->assertSame(['sku' => 'M12345', 'qty' => 1.0, 'text' => 'М12345'], BotRequestService::parseLine('М12345'));
        $this->assertNull(BotRequestService::parseLine('ролик двери Fermator 2 шт')['sku']);
    }
}
