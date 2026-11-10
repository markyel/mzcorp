<?php

namespace App\Services\QuoteParts;

use App\Models\Supplier;
use App\Models\SupplierOrganization;
use App\Services\Settings\SettingsService;
use App\Models\AppSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Копия поставщиков QuoteParts в mzcorp (решение заказчика 06.11.2026: поставщики живут в
 * QuoteParts, mzcorp показывает копию и правит через API). Поставщик — организация с ключом
 * qp_key; контакты QuoteParts — её адреса (suppliers) с темами, порядком, телефоном и языком.
 *
 * Адрес, которого у нас нет, заводится у своей организации; адрес, числящийся у другой нашей
 * организации, не переносится (пишем в лог — разбирать человеку). Наши адреса, которых нет в
 * QuoteParts, не удаляются: QuoteParts держит не больше 30 контактов, а часть адресов —
 * претенденты, не подтверждённые человеком.
 */
class SupplierMirrorService
{
    public const SYNCED_AT = 'quoteparts.suppliers_synced_at';

    public function __construct(
        private readonly QuotePartsClient $client,
        private readonly SettingsService $settings,
    ) {}

    /** Изменённые с прошлой сверки (или все). @return int сколько поставщиков обновлено */
    public function sync(bool $full = false): int
    {
        $started = now();
        $since = $full ? null : $this->settings->get(self::SYNCED_AT);
        $links = $this->client->allSupplierLinks($since ? (string) $since : null);
        $n = 0;
        foreach ($links as $link) {
            $n += $this->apply($link) ? 1 : 0;
        }
        // С нахлёстом в минуту: правка, пришедшая во время чтения, попадёт в следующую сверку.
        $this->settings->set(self::SYNCED_AT, $started->copy()->subMinute()->toIso8601String(), AppSetting::TYPE_STRING, null,
            'Когда копия поставщиков QuoteParts сверялась в последний раз');

        return $n;
    }

    /** Один поставщик по ключу — после правки из mzcorp или уведомления QuoteParts. */
    public function refresh(string $key): bool
    {
        $link = collect($this->client->supplierLinks([$key]))->first();

        return $link !== null && $this->apply($link);
    }

    /** @param  array<string, mixed>  $link  ответ QuoteParts (SupplierLinkController::present) */
    public function apply(array $link): bool
    {
        $org = SupplierOrganization::query()->where('qp_key', (string) ($link['external_id'] ?? ''))->first();
        if ($org === null) {
            return false;
        }

        try {
            DB::transaction(function () use ($org, $link) {
                $name = trim((string) ($link['company']['name'] ?? ''));
                $org->forceFill([
                    'name' => $name !== '' ? mb_substr($name, 0, 255) : $org->name,
                    'qp_status' => $link['status'] ?? null,
                    'qp_payload' => $link,
                    'qp_synced_at' => now(),
                ])->save();

                // Архив ведёт QuoteParts (07.11.2026). Старый ответ без поля архива копию не трогает.
                if (array_key_exists('archived_at', $link)) {
                    $archivedAt = $link['archived_at'] ? \Illuminate\Support\Carbon::parse($link['archived_at']) : null;
                    $org->forceFill([
                        'archived_at' => $archivedAt,
                        'archived_reason' => $archivedAt ? ($link['archived_reason'] ?? null) : null,
                        'archived_by_user_id' => $archivedAt ? $org->archived_by_user_id : null,
                    ])->save();
                }

                foreach ($this->contacts($link) as $email => $c) {
                    $row = Supplier::query()->where('supplier_organization_id', $org->id)->whereRaw('lower(email) = ?', [$email])->first();
                    if ($row === null) {
                        $elsewhere = Supplier::query()->whereRaw('lower(email) = ?', [$email])->value('supplier_organization_id');
                        if ($elsewhere !== null) {
                            Log::warning('QuoteParts mirror: contact belongs to another supplier here', [
                                'email' => $email, 'key' => $org->qp_key, 'other_organization_id' => $elsewhere,
                            ]);

                            continue;
                        }
                        $row = new Supplier(['email' => $email, 'domain' => Str::after($email, '@'), 'supplier_organization_id' => $org->id]);
                        $row->source = 'quoteparts';
                        // Новый адрес поставщика, не принявшего приглашение, — внесистемный, как и его остальные
                        // адреса (QuotePartsSupplierService::register): spareparts@paulschaab.de 10.11.2026 и ещё
                        // три адреса 07–08.11 заводились без отметки.
                        $row->quoteparts_offsystem = ($link['status'] ?? null) !== 'active';
                    }
                    $row->fill([
                        'contact_person' => $c['name'] ?? $row->contact_person,
                        'phone' => array_key_exists('phone', $c) ? $c['phone'] : $row->phone,
                        // Язык наших писем: выбранный нами для поставщика (letter_locale) главнее языка человека.
                        // Письма mzCorp — русские или английские: китайский пишем по-английски.
                        'language' => (($link['letter_locale'] ?? null) ?: ($c['locale'] ?? null)) === 'ru' ? 'ru'
                            : ((($link['letter_locale'] ?? null) ?: ($c['locale'] ?? null)) ? 'en' : ($row->language ?: 'ru')),
                    ]);
                    $row->forceFill([
                        'contact_topics' => $c['topics'],
                        'contact_position' => $c['position'],
                        'quoteparts_link_id' => $link['id'] ?? $row->quoteparts_link_id,
                        'quoteparts_status' => $link['status'] ?? $row->quoteparts_status,
                        'quoteparts_comment' => $link['supplier_comment'] ?? null,
                        'quoteparts_agent' => (bool) ($link['is_agent'] ?? false),
                        'quoteparts_groups' => $link['visible_groups'] ?? null,
                    ])->save();
                }

                // Статус и признаки — всем адресам организации, и тем, что не в контактах QuoteParts.
                Supplier::query()->where('supplier_organization_id', $org->id)->update([
                    'quoteparts_link_id' => $link['id'] ?? null,
                    'quoteparts_status' => $link['status'] ?? null,
                    'quoteparts_agent' => (bool) ($link['is_agent'] ?? false),
                ]);
                // Язык писем выбран нами — всем адресам организации (наши письма mzCorp — ru или en).
                if (! empty($link['letter_locale'])) {
                    Supplier::query()->where('supplier_organization_id', $org->id)->update(['language' => $link['letter_locale'] === 'ru' ? 'ru' : 'en']);
                }
                // Матрица и описание ассортимента — у поставщика в QuoteParts; подбор под позицию
                // (SupplierMatchService) читает их с адресов — раскладываем по всем.
                if (array_key_exists('assortment_matrix', $link) && $link['assortment_matrix'] !== null) {
                    Supplier::query()->where('supplier_organization_id', $org->id)->update([
                        'assortment_matrix' => json_encode($link['assortment_matrix'], JSON_UNESCAPED_UNICODE),
                        'matrix_built_at' => $link['matrix_updated_at'] ?? now(),
                    ]);
                }
                $description = $link['profile']['assortment_description'] ?? null;
                if ($description !== null) {
                    Supplier::query()->where('supplier_organization_id', $org->id)->update(['assortment_description' => $description]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('QuoteParts mirror: supplier not applied', ['key' => $org->qp_key, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    /**
     * Контакты связи по адресу: что расставили мы и что выбрал сам поставщик (темы объединяются,
     * порядок — наименьший, имя и телефон — что известно).
     *
     * @return array<string, array{name:?string, phone:?string, locale:?string, topics:list<string>, position:int}>
     */
    public function contacts(array $link): array
    {
        $out = [];
        foreach ((array) ($link['contacts'] ?? []) as $c) {
            $email = mb_strtolower(trim((string) ($c['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            $out[$email] = [
                'name' => ($c['name'] ?? null) && $c['name'] !== $c['email'] ? $c['name'] : null,
                'phone' => $c['phone'] ?? null,
                'locale' => $c['locale'] ?? null,
                'topics' => array_values((array) ($c['topics'] ?? [])),
                'position' => (int) ($c['position'] ?? 99),
            ];
        }
        foreach ((array) ($link['supplier_contacts'] ?? []) as $c) {
            $email = mb_strtolower(trim((string) ($c['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            $prev = $out[$email] ?? ['name' => null, 'phone' => null, 'locale' => null, 'topics' => [], 'position' => 99];
            $out[$email] = [
                'name' => $prev['name'] ?? (($c['name'] ?? null) !== $email ? ($c['name'] ?? null) : null),
                'phone' => $prev['phone'] ?? ($c['phone'] ?? null),
                'locale' => $prev['locale'],
                'topics' => array_values(array_unique([...$prev['topics'], (string) $c['topic']])),
                'position' => min($prev['position'], (int) ($c['position'] ?? 99)),
            ];
        }

        return $out;
    }
}
