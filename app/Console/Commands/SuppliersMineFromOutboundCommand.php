<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\User;
use App\Prompts\Suppliers\SummarizeAssortmentFromRfqPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Supplier\SupplierMatrixBuilder;
use App\Services\Supplier\SupplierRegistry;
use Illuminate\Console\Command;

/**
 * Bootstrap реестра поставщиков из истории исходящих писем (Фаза 3.1).
 *
 * Находит наши ИСХОДЯЩИЕ письма-запросы расценки поставщикам (RFQ-сигнатура в
 * теме), группирует по получателю. Поставщик ≠ клиент: дискриминатор —
 * получатель НЕ шлёт нам входящих `client_request` (клиент инициирует заявки,
 * поставщик только отвечает на наши треды). Для подтверждённых поставщиков:
 * регистрирует в реестре, по номенклатуре запросов строит базовое описание
 * (LLM) и матрицу (SupplierMatrixBuilder → наши 36 категорий).
 *
 *   php artisan suppliers:mine-from-outbound                 # dry-run отчёт
 *   php artisan suppliers:mine-from-outbound --apply
 *   php artisan suppliers:mine-from-outbound --apply --limit=20
 *   php artisan suppliers:mine-from-outbound --apply --min-rfq=3
 */
class SuppliersMineFromOutboundCommand extends Command
{
    protected $signature = 'suppliers:mine-from-outbound
        {--apply : Реально регистрировать поставщиков + строить описания/матрицы}
        {--limit=0 : Максимум поставщиков за прогон (0 = все)}
        {--min-rfq=2 : Минимум RFQ-исходящих, чтобы счесть поставщиком}';

    protected $description = 'Найти поставщиков в исходящих RFQ и собрать описание/матрицу по запрошенной номенклатуре';

    // RFQ-сигнатура темы: «запрос», [NNNNN], (NNNNN), голый номер заказа.
    private const SUBJECT_RFQ = '(запрос|\[[0-9]{5,}\]|\([0-9]{5,}\)|^[[:space:]]*0*[0-9]{6,}[[:space:]]*$)';

    /** @var array<int, string> */
    private array $internalDomains = [];

    /** @var array<string, bool> */
    private array $staffEmails = [];

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly SummarizeAssortmentFromRfqPrompt $summarizePrompt,
        private readonly SupplierRegistry $registry,
        private readonly SupplierMatrixBuilder $matrixBuilder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $minRfq = max(1, (int) $this->option('min-rfq'));

        $this->internalDomains = array_values(array_filter(array_map(
            fn ($d) => mb_strtolower(trim((string) $d)),
            (array) config('services.mail.internal_domains', ['myzip.ru']),
        )));
        $this->staffEmails = User::query()->whereNotNull('email')->pluck('email')
            ->mapWithKeys(fn ($e) => [mb_strtolower(trim((string) $e)) => true])->all();

        // 1) Кандидаты: получатели RFQ-исходящих (внешние, не наши).
        $this->info('Сканирую исходящие с RFQ-сигнатурой…');
        $rfqOut = [];
        EmailMessage::query()
            ->where('direction', 'outbound')
            ->whereRaw('subject ~* ?', [self::SUBJECT_RFQ])
            ->select('id', 'to_recipients')
            ->orderByDesc('id')
            ->chunk(500, function ($chunk) use (&$rfqOut) {
                foreach ($chunk as $m) {
                    foreach ((array) $m->to_recipients as $r) {
                        $e = mb_strtolower(trim((string) ($r['email'] ?? '')));
                        if ($e !== '' && $this->isExternal($e)) {
                            $rfqOut[$e] = ($rfqOut[$e] ?? 0) + 1;
                        }
                    }
                }
            });

        // Доменный гард: домены, с которых к нам шли client_request — клиентские
        // (адрес с client_req=0 на клиентском домене, напр. meteor.ru, — не
        // поставщик). Считаем один раз group by домен.
        $domainClientReq = [];
        foreach (
            EmailMessage::query()->where('direction', 'inbound')->where('category', 'client_request')
                ->selectRaw("lower(split_part(from_email,'@',2)) dom, count(*) c")
                ->groupByRaw("lower(split_part(from_email,'@',2))")->get() as $d
        ) {
            if ((string) $d->dom !== '') {
                $domainClientReq[$d->dom] = (int) $d->c;
            }
        }
        $domainThreshold = 3;

        // 2) Классификация по client_request (клиент инициирует заявки).
        $rows = [];
        foreach ($rfqOut as $email => $cnt) {
            $clientReq = EmailMessage::query()->where('direction', 'inbound')
                ->whereRaw('lower(from_email) = ?', [$email])
                ->where('category', 'client_request')->count();
            // Заявки клиента с этого адреса — тоже клиент. Категория письма
            // не всегда client_request: idrisovaam@grk.ru переслала свой
            // «Запрос КП на KONE» (категория thread_reply), наш ответ с темой
            // «Запрос КП…» сошёл за RFQ, и клиент 18.06 попал в поставщики.
            $clientReq += \App\Models\Request::query()->whereRaw('lower(client_email) = ?', [$email])
                ->whereNull('merged_into_id')->count();
            $domain = substr($email, strpos($email, '@') + 1);
            $domClient = $domainClientReq[$domain] ?? 0;
            $isClientDomain = $domClient >= $domainThreshold;

            if ($clientReq > 0 || $isClientDomain) {
                $verdict = 'client';
            } elseif ($cnt >= $minRfq) {
                $verdict = 'supplier';
            } else {
                $verdict = 'low';
            }
            $rows[] = ['email' => $email, 'rfq_out' => $cnt, 'client_req' => $clientReq, 'dom_client' => $domClient, 'verdict' => $verdict];
        }
        usort($rows, fn ($a, $b) => $b['rfq_out'] <=> $a['rfq_out']);

        $suppliers = array_values(array_filter($rows, fn ($r) => $r['verdict'] === 'supplier'));
        $clients = array_filter($rows, fn ($r) => $r['verdict'] === 'client');
        $low = array_filter($rows, fn ($r) => $r['verdict'] === 'low');

        $this->newLine();
        $this->table(['итог', 'кол-во'], [
            ['кандидатов (получателей RFQ)', (string) count($rows)],
            ['поставщики (client_req=0, rfq>=' . $minRfq . ')', (string) count($suppliers)],
            ['клиенты (client_req>0) — пропуск', (string) count($clients)],
            ['мало RFQ (<' . $minRfq . ') — пропуск', (string) count($low)],
        ]);

        $this->newLine();
        $this->line('<info>ТОП поставщиков-кандидатов:</info>');
        $this->table(
            ['email', 'rfq_out', 'client_req', 'dom_client', 'уже в реестре'],
            collect($suppliers)->take(40)->map(fn ($r) => [
                $r['email'], (string) $r['rfq_out'], (string) $r['client_req'], (string) ($r['dom_client'] ?? 0),
                $this->registry->isSupplier($r['email']) ? 'да' : '—',
            ])->all(),
        );

        if (! $apply) {
            $this->warn('DRY-RUN. Запусти с --apply, чтобы зарегистрировать поставщиков + собрать описания/матрицы.');

            return self::SUCCESS;
        }

        // 3) APPLY (с 06.11.2026 поставщики живут в QuoteParts): по поставщику-организации — описание
        // по номенклатуре наших запросов на все его адреса и матрица; то и другое — в QuoteParts, копия
        // разложит по адресам. Адреса, которых нет у поставщиков, не трогаем — претендентов из всей
        // истории не плодим.
        $byOrg = [];
        foreach ($suppliers as $r) {
            $row = \App\Models\Supplier::query()->whereRaw('lower(email) = ?', [mb_strtolower($r['email'])])->first();
            if ($row !== null && $row->supplier_organization_id !== null) {
                $byOrg[(int) $row->supplier_organization_id][] = $r['email'];
            }
        }
        $qp = app(\App\Services\QuoteParts\QuotePartsClient::class);
        $mirror = app(\App\Services\QuoteParts\SupplierMirrorService::class);
        $done = 0;
        foreach ($byOrg as $orgId => $emails) {
            if ($limit > 0 && $done >= $limit) {
                break;
            }
            $org = \App\Models\SupplierOrganization::query()->find($orgId);
            if ($org === null || ! $org->qp_key || $org->qp_status === null) {
                continue;
            }
            // Описание, уже заполненное человеком, не перезатираем.
            $description = trim((string) (($org->qp_payload ?? [])['profile']['assortment_description'] ?? ''));
            try {
                if ($description === '') {
                    $text = implode("\n", array_map(fn ($e) => $this->gatherRfqText($e), array_unique($emails)));
                    $description = (string) $this->summarize(mb_substr($text, 0, 12000));
                    if ($description === '') {
                        continue;
                    }
                    $mirror->apply($qp->updateSupplier($org->qp_key, ['profile' => ['assortment_description' => mb_substr($description, 0, 5000)]]));
                }
                $rules = (array) ((((array) (($org->qp_payload ?? [])['assortment_matrix'] ?? []))['rules']) ?? []);
                $matrix = $this->matrixBuilder->compute($description, $rules);
                if ($matrix !== null) {
                    $mirror->apply($qp->setSupplierMatrix($org->qp_key, $matrix));
                }
                $this->line(sprintf('  ✓ %-40s категории: %s', mb_strimwidth($org->name, 0, 40, '…'), implode(', ', array_slice((array) ($matrix['categories'] ?? []), 0, 6)) ?: '—'));
                $done++;
            } catch (\Throwable $e) {
                $this->warn('  '.$org->name.': '.mb_substr($e->getMessage(), 0, 150));
            }
        }

        $this->newLine();
        $this->info("Готово. Обработано поставщиков: {$done}. Проверьте/поправьте в разделе «Поставщики» → «Реестр».");

        return self::SUCCESS;
    }

    private function isExternal(string $email): bool
    {
        if (! str_contains($email, '@')) {
            return false;
        }
        if (isset($this->staffEmails[$email])) {
            return false;
        }
        $domain = substr($email, strpos($email, '@') + 1);
        foreach ($this->internalDomains as $d) {
            if ($d !== '' && $domain === $d) {
                return false;
            }
        }

        return true;
    }

    /**
     * Темы + фрагменты тел наших RFQ-писем этому поставщику (для описания).
     */
    private function gatherRfqText(string $email): string
    {
        $msgs = EmailMessage::query()
            ->where('direction', 'outbound')
            ->where('to_recipients', 'ilike', '%' . $email . '%')
            ->whereRaw('subject ~* ?', [self::SUBJECT_RFQ])
            ->orderByDesc('id')
            ->limit(25)
            ->get(['subject', 'body_plain']);

        $parts = [];
        $total = 0;
        foreach ($msgs as $m) {
            $line = '• ' . trim((string) $m->subject);
            $body = trim((string) $m->body_plain);
            if ($body !== '') {
                $line .= ' — ' . mb_substr(preg_replace('/\s+/u', ' ', $body) ?? '', 0, 200);
            }
            $parts[] = $line;
            $total += mb_strlen($line);
            if ($total > 5000) {
                break;
            }
        }

        return implode("\n", $parts);
    }

    private function summarize(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }
        try {
            $result = $this->openai->chat(
                $this->summarizePrompt->build($text),
                config('services.openai.intent_model', 'gpt-4o-mini'),
                ['temperature' => 0, 'max_tokens' => 400, 'response_format' => ['type' => 'json_object']],
            );
        } catch (\Throwable) {
            return null;
        }
        $parsed = json_decode($result['content'] ?? '', true);

        return is_array($parsed) && isset($parsed['description']) ? trim((string) $parsed['description']) : null;
    }
}
