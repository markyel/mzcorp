<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Services\Mail\FreeTextReplyEnricher;
use Illuminate\Console\Command;

/**
 * Прямой тест FreeTextReplyEnricher на конкретном email_message_id.
 *
 * Запускает Path C напрямую, минуя ParseRequestItemsJob и его гейты
 * (empty-items / active-batch / reset). Полезно когда хочется проверить
 * что enricher делает, не давая ему «утечь» в обычный pipeline.
 *
 * Использование:
 *   php artisan path-c:test 1777
 */
class TestPathC extends Command
{
    protected $signature = 'path-c:test {message_id}';

    protected $description = 'Принудительно запускает FreeTextReplyEnricher на указанном email_message_id';

    public function handle(FreeTextReplyEnricher $enricher): int
    {
        $msgId = (int) $this->argument('message_id');
        $msg = EmailMessage::find($msgId);
        if (! $msg) {
            $this->error("Сообщение #{$msgId} не найдено");
            return self::FAILURE;
        }
        if (! $msg->related_request_id) {
            $this->error("Сообщение #{$msgId} не привязано к Request (related_request_id=null)");
            return self::FAILURE;
        }
        $request = RequestModel::find($msg->related_request_id);
        if (! $request) {
            $this->error("Request #{$msg->related_request_id} не найден");
            return self::FAILURE;
        }

        $this->line("Сообщение #{$msg->id} | from={$msg->from_email} | subject «{$msg->subject}»");
        $body = (string) ($msg->body_plain ?? '');
        if (trim($body) === '' && ! empty($msg->body_html)) {
            $body = trim(strip_tags((string) $msg->body_html));
        }
        $this->line('body length: '.mb_strlen($body));
        $this->line('body preview: '.mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 500));
        $this->line('');
        $this->line('Request: '.$request->internal_code.' (items: '.$request->items()->where('is_active', true)->count().')');
        $this->line('');

        try {
            $result = $enricher->enrich($msg, $request);
            $this->info('Готово.');
            $this->line('  stored suggestions: '.$result['suggestions']);
            $this->line('  auto-applied: '.$result['auto_applied']);
        } catch (\Throwable $e) {
            $this->error('ОШИБКА: '.$e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
