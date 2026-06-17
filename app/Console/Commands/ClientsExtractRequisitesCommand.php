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
                if (trim((string) ($org->name ?? '')) === '' && $buyer['name']) {
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

        $block = null;
        if (preg_match('/Покупатель\s*:?\s*(.{0,250})/iu', $text, $m)) {
            $block = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');
        }

        // ИНН покупателя: внутри блока — первый; если блока нет — любой не наш.
        $haystack = $block ?? trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($block !== null && preg_match('/ИНН\D{0,3}(\d{10,12})/iu', $block, $mm)) {
            $res['inn'] = $mm[1];
        } elseif (preg_match_all('/\b(\d{10}|\d{12})\b/', $haystack, $all)) {
            foreach ($all[1] as $inn) {
                if ($inn !== $this->ourInn) {
                    $res['inn'] = $inn;
                    break;
                }
            }
        }
        if ($res['inn'] === $this->ourInn) {
            $res['inn'] = null;
        }
        if ($res['inn'] === null) {
            return $res;
        }

        if ($block !== null) {
            if (preg_match('/^(.+?),?\s*ИНН/iu', $block, $mm)) {
                $res['name'] = trim($mm[1], " ,\t\n");
            }
            if (preg_match('/КПП\D{0,3}(\d{9})/iu', $block, $mm)) {
                $res['kpp'] = $mm[1];
            }
            if (preg_match('/КПП\D{0,3}\d{9}\s*,?\s*(.+)$/iu', $block, $mm)) {
                $res['address'] = trim(mb_substr(trim($mm[1]), 0, 200), " ,");
            }
        }

        return $res;
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
