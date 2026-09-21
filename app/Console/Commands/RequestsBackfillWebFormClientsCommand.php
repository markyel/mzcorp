<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Clients\RequestOrganizationResolver;
use App\Services\Mail\WebFormSubmissionParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Починка клиента у заявок с сайта: в `client_email` стоит адрес самой формы
 * (order@), а покупатель указан в теле.
 *
 * Такие заявки попадали в базу, когда письмо превращали в заявку РУКАМИ:
 * автоматический путь форму разбирает, ручной — нет (исправлено в
 * EmailToRequestPromoter). Последствия у подмены неочевидные и дорогие: КП
 * уходит покупателю, а детектор исходящих документов сверяет адресата с
 * `client_email`, не находит совпадения и не признаёт письмо адресованным
 * клиенту — документ не распознаётся, статус заявки не двигается, и она висит
 * «в работе» с уже отправленным КП (кейс M-2026-16283).
 *
 * Организацию перепривязываем: по настоящему адресу клиент часто находится в
 * реестре, а с адресом формы — никогда.
 */
class RequestsBackfillWebFormClientsCommand extends Command
{
    protected $signature = 'requests:backfill-webform-clients
        {--apply : Применить изменения (по умолчанию сухой прогон)}
        {--limit=500 : Максимум заявок за прогон}';

    protected $description = 'Заявки с сайта: подставить реального покупателя вместо адреса формы';

    public function handle(WebFormSubmissionParser $parser, RequestOrganizationResolver $orgResolver): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        $requests = Request::query()
            ->whereNotNull('email_message_id')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $fixed = 0;
        $skipped = 0;

        foreach ($requests as $request) {
            $message = EmailMessage::find($request->email_message_id);
            if ($message === null || ! $parser->isWebFormSubmission($message)) {
                continue;
            }
            // Чиним только те, где в заявке стоит адрес отправителя формы:
            // если менеджер уже поправил клиента руками, не трогаем.
            if (mb_strtolower(trim((string) $request->client_email)) !== mb_strtolower(trim((string) $message->from_email))) {
                continue;
            }

            $parsed = $parser->parse($message);
            if ($parsed === null || ($parsed['email'] ?? '') === '') {
                $skipped++;
                $this->warn("  {$request->internal_code}: форма не разобралась — оставляю как есть");

                continue;
            }

            $this->line(sprintf(
                '  %s  %s → %s  (%s)',
                $request->internal_code,
                $request->client_email,
                $parsed['email'],
                $parsed['company'] ?: ($parsed['name'] ?: '—'),
            ));

            if (! $apply) {
                $fixed++;

                continue;
            }

            $request->forceFill(array_filter([
                'client_email' => $parsed['email'],
                'client_name' => $parsed['name'] ?: ($parsed['company'] ?: $request->client_name),
                'client_phone' => $parsed['phone'] ?? null,
                'client_company' => $parsed['company'] ?? null,
                'client_address' => $parsed['address'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''))->save();

            // По настоящему адресу организация часто находится — а по адресу
            // формы не находилась никогда.
            $orgResolver->attach($request->fresh());

            Log::info('RequestsBackfillWebFormClients: client replaced', [
                'request_id' => $request->id,
                'internal_code' => $request->internal_code,
                'was' => $message->from_email,
                'now' => $parsed['email'],
            ]);
            $fixed++;
        }

        $this->info(sprintf(
            '%s заявок: %d%s',
            $apply ? 'Исправлено' : 'К исправлению',
            $fixed,
            $skipped ? ", не разобралось: {$skipped}" : '',
        ));

        if (! $apply && $fixed > 0) {
            $this->comment('Сухой прогон. Запусти с --apply, чтобы применить.');
        }

        return self::SUCCESS;
    }
}
