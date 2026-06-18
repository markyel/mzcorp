<?php

namespace App\Console\Commands;

use App\Models\ClientContact;
use App\Models\EmailAttachment;
use App\Models\Organization;
use App\Models\OutboundQuote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Извлечение реквизитов ПОКУПАТЕЛЯ (организации) из PDF внешних КП/счетов,
 * пойманных в исходящей почте (OutboundQuote). В документах 1С есть блок
 * «Покупатель: <Название>, ИНН …, КПП …, <адрес>» — оттуда тянем
 * Название / ИНН / КПП / адрес и наполняем реестр организаций.
 *
 * Покупатель = ИНН, отличный от нашего (config services.company.inn).
 * Организация апсёртится по ИНН; связывается с контактом (email заявки).
 *
 * Идемпотентно: обработанные OutboundQuote помечаются
 * payload.requisites_extracted=true (повторный прогон их пропускает, можно
 * докручивать частями через --limit).
 *
 *   php artisan clients:extract-requisites               # dry-run (оценка)
 *   php artisan clients:extract-requisites --apply
 *   php artisan clients:extract-requisites --apply --limit=300
 */
class ClientsExtractRequisitesCommand extends Command
{
    protected $signature = 'clients:extract-requisites
        {--apply : Реально писать организации/связи}
        {--limit=0 : Максимум документов за прогон (0 = все необработанные)}';

    protected $description = 'Достать реквизиты организаций-покупателей из PDF внешних КП/счетов (OutboundQuote)';

    private string $ourInn = '';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $this->ourInn = preg_replace('/\D+/', '', (string) config('services.company.inn', '')) ?? '';

        $base = OutboundQuote::query()
            ->with('request:id,client_email')
            ->whereNotNull('email_attachment_id')
            ->whereRaw("(payload->>'requisites_extracted') IS NULL");

        $pending = (clone $base)->count();
        $this->info(sprintf('Необработанных документов: %d. Mode: %s.', $pending, $apply ? 'APPLY' : 'DRY-RUN'));
        if (! $apply) {
            $this->warn('DRY-RUN — запусти с --apply, чтобы извлечь и записать.');

            return self::SUCCESS;
        }

        $stats = ['processed' => 0, 'with_buyer' => 0, 'orgs_new' => 0, 'links' => 0, 'no_text' => 0];

        (clone $base)->orderBy('id')->chunkById($limit > 0 ? min(200, $limit) : 200, function ($chunk) use (&$stats, $limit) {
            foreach ($chunk as $q) {
                if ($limit > 0 && $stats['processed'] >= $limit) {
                    return false;
                }
                $this->processOne($q, $stats);
            }

            return ! ($limit > 0 && $stats['processed'] >= $limit);
        });

        $this->newLine();
        $this->table(['metric', 'value'], collect($stats)->map(fn ($v, $k) => [$k, (string) $v])->values()->all());

        return self::SUCCESS;
    }

    private function processOne(OutboundQuote $q, array &$stats): void
    {
        $stats['processed']++;
        $text = $this->pdfText($q->email_attachment_id);

        if ($text === null) {
            $stats['no_text']++;
        } else {
            $buyer = $this->parseBuyer($text);
            if ($buyer['inn'] !== null) {
                $stats['with_buyer']++;
                $org = Organization::firstOrNew(['inn' => $buyer['inn']]);
                if (! $org->exists) {
                    $stats['orgs_new']++;
                }
                // Перезаписываем имя, если текущее пустое / плейсхолдер «ИНН N» /
                // мусорное (артикул) — хорошее имя из чистого документа важнее.
                $cur = (string) ($org->name ?? '');
                $replaceable = trim($cur) === ''
                    || preg_match('/^ИНН \d+$/u', $cur) === 1
                    || $this->isJunkName($cur);
                if ($replaceable && $buyer['name']) {
                    $org->name = $buyer['name'];
                }
                if (trim((string) ($org->kpp ?? '')) === '' && $buyer['kpp']) {
                    $org->kpp = $buyer['kpp'];
                }
                if (trim((string) ($org->address ?? '')) === '' && $buyer['address']) {
                    $org->address = $buyer['address'];
                }
                if (trim((string) ($org->name ?? '')) === '') {
                    $org->name = 'ИНН ' . $buyer['inn'];
                }
                $org->save();
                $this->linkEmail($org, (string) (optional($q->request)->client_email ?? ''), $stats);
            }
        }

        // Пометить обработанным (даже если покупателя не нашли — не парсить заново).
        $payload = is_array($q->payload) ? $q->payload : [];
        $payload['requisites_extracted'] = true;
        $q->forceFill(['payload' => $payload])->save();
    }

    /**
     * Парсинг блока «Покупатель: …, ИНН …, КПП …, адрес».
     *
     * @return array{name: ?string, inn: ?string, kpp: ?string, address: ?string}
     */
    private function parseBuyer(string $text): array
    {
        $res = ['name' => null, 'inn' => null, 'kpp' => null, 'address' => null];
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($flat === '') {
            return $res;
        }

        // 1) Чёткий блок «Покупатель: <Название>, ИНН …, КПП …, <адрес>» (счета 1С).
        if (preg_match('/Покупатель\s*:?\s*([^,]{2,90})(.{0,200})/iu', $flat, $m)
            && preg_match('/ИНН\D{0,4}(\d{10,12})/iu', $m[2], $mi)
            && $mi[1] !== $this->ourInn) {
            $res['inn'] = $mi[1];
            $nm = $this->cleanName($m[1]);
            // Отбраковываем «артикульные» имена (6311-2RS и т.п.): пусть имя
            // придёт из более чистого документа этого ИНН. ИНН/КПП/адрес — берём.
            $res['name'] = $this->isJunkName($nm) ? null : $nm;
            if (preg_match('/КПП\D{0,4}(\d{9})/iu', $m[2], $mk)) {
                $res['kpp'] = $mk[1];
            }
            if (preg_match('/(?:КПП\D{0,4}\d{9}|ИНН\D{0,4}\d{10,12})\s*,?\s*(.+)$/iu', $m[2], $ma)) {
                $res['address'] = trim(mb_substr(trim($ma[1]), 0, 160), " ,;");
            }

            return $res;
        }

        // Нет чёткого блока «Покупатель: <Название>, ИНН …» = это НЕ наш
        // клиентский счёт/КП (входящий счёт поставщика, банковская выписка,
        // инвойс иностранцу и т.п., пойманные в исходящей почте). Организацию-
        // покупателя НЕ создаём — иначе реестр забивается мусорными «ИНН N»
        // без названия (bare-ИНН фолбэк давал 17/18 мусора).
        return $res;
    }

    private function cleanName(string $s): string
    {
        $s = preg_replace('/^\s*Покупатель\s*:?\s*/iu', '', trim($s)) ?? $s;

        return trim($s, " ,;:\t\n");
    }

    /**
     * Имя похоже на мусор/артикул, а не на название организации:
     *  - есть кавычки «…»/"…" → настоящее название, НЕ мусор;
     *  - 3+ цифры подряд без кавычек («ип 6311-2RS») → артикул, мусор;
     *  - нет ни одного слова из ≥3 букв (кроме орг-формы) → мусор.
     */
    private function isJunkName(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return true;
        }
        if (preg_match('/[«»"„“]/u', $name) === 1) {
            return false;
        }
        if (preg_match('/\d{3,}/', $name) === 1) {
            return true;
        }
        $woForm = preg_replace('/^(?:ООО|ОАО|ЗАО|ПАО|НАО|АО|ИП|НКО|ФГУП|МУП|ГУП|ГБУ|МБУ|АНО|ТСЖ|СНТ)\b/iu', '', $name) ?? $name;

        return preg_match('/\p{L}{3,}/u', $woForm) !== 1;
    }

    private function linkEmail(Organization $org, string $email, array &$stats): void
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return;
        }
        $contact = ClientContact::firstOrCreate(['email' => $email]);
        if (! $org->contacts()->where('client_contacts.id', $contact->id)->exists()) {
            $org->contacts()->attach($contact->id);
            $stats['links']++;
        }
    }

    private function pdfText(int $attId): ?string
    {
        $att = EmailAttachment::find($attId);
        if (! $att || ! $att->file_path) {
            return null;
        }
        $disk = $att->disk ?: 'local';
        if (strtolower((string) pathinfo((string) $att->filename, PATHINFO_EXTENSION)) !== 'pdf'
            || ! Storage::disk($disk)->exists($att->file_path)) {
            return null;
        }
        try {
            $text = (new \Smalot\PdfParser\Parser())
                ->parseFile(Storage::disk($disk)->path($att->file_path))
                ->getText();
        } catch (\Throwable $e) {
            return null;
        }

        return trim((string) $text) !== '' ? $text : null;
    }
}
