<?php

namespace App\Services\Clients;

use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Официальные реквизиты организации по ИНН — из ЕГРЮЛ/ЕГРИП через DaData.
 *
 * Реестр клиентов собирается из наших же документов, и названия там живут
 * своей жизнью: «ип DCSS5-E» при ИНН ООО «ГРИНЛИФТ», «ОБЩЕСТВО С
 * ОГРАНИЧЕННОЙ ОТВЕТСТВЕННО…» обрезанное, «ИНН 7810609964» вместо имени.
 * ИНН при этом почти всегда настоящий — по нему и сверяемся.
 *
 * Политика записи осторожная:
 *   — официальные данные ложатся в отдельные поля registry_* всегда;
 *   — КПП, ОГРН и пустой адрес дописываем — там спорить не о чем;
 *   — рабочее название меняем, только если оно мусорное; хорошее название,
 *     которое поправил человек, выпиской не затираем — это делает кнопка.
 */
class OrganizationRegistryService
{
    /**
     * Реквизиты из реестра по ИНН. null — ключа нет или сервис недоступен;
     * ['status' => 'NOT_FOUND'] — сервис ответил, но такого ИНН нет.
     *
     * @return array{status: string, short_name?: string, full_name?: string, kpp?: ?string, ogrn?: ?string, address?: ?string, director?: ?string}|null
     */
    public function lookup(string $inn): ?array
    {
        $key = (string) config('services.dadata.api_key');
        $inn = preg_replace('/\D+/', '', $inn) ?? '';
        if ($key === '' || $inn === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('services.dadata.timeout', 10))
                ->withHeaders([
                    'Authorization' => 'Token '.$key,
                    'Accept' => 'application/json',
                ])
                ->asJson()
                ->post((string) config('services.dadata.party_url'), [
                    'query' => $inn,
                    // Филиалы не нужны: реквизиты договора — головной организации.
                    'branch_type' => 'MAIN',
                ]);
        } catch (\Throwable $e) {
            Log::warning('OrganizationRegistryService: dadata unreachable', ['inn' => $inn, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('OrganizationRegistryService: dadata error', ['inn' => $inn, 'status' => $response->status()]);

            return null;
        }

        $data = $response->json('suggestions.0.data');
        if (! is_array($data)) {
            return ['status' => 'NOT_FOUND'];
        }

        $management = $data['management'] ?? null;
        $director = is_array($management) && ! empty($management['name'])
            ? trim(($management['post'] ? mb_strtolower((string) $management['post']).' ' : '').$management['name'])
            : ($data['type'] === 'INDIVIDUAL' ? ($data['name']['full'] ?? null) : null);

        return [
            'status' => (string) ($data['state']['status'] ?? 'ACTIVE'),
            'short_name' => (string) ($data['name']['short_with_opf'] ?? $response->json('suggestions.0.value')),
            'full_name' => (string) ($data['name']['full_with_opf'] ?? ''),
            'kpp' => $data['kpp'] ?? null,
            'ogrn' => $data['ogrn'] ?? null,
            'address' => $data['address']['unrestricted_value'] ?? $data['address']['value'] ?? null,
            'director' => $director,
        ];
    }

    /**
     * Сверить одну организацию и записать то, что можно записать без спроса.
     *
     * @return array{ok: bool, changed: array<string, array{from: ?string, to: ?string}>, status: ?string, message: string}
     */
    public function sync(Organization $org): array
    {
        if (trim((string) $org->inn) === '') {
            return ['ok' => false, 'changed' => [], 'status' => null, 'message' => 'У организации нет ИНН — сверять не по чему.'];
        }

        $reg = $this->lookup((string) $org->inn);
        if ($reg === null) {
            return ['ok' => false, 'changed' => [], 'status' => null, 'message' => 'Реестр не ответил — попробуйте позже.'];
        }

        $changed = [];
        $set = function (string $field, ?string $value) use ($org, &$changed): void {
            $value = $value !== null && trim($value) !== '' ? trim($value) : null;
            if ((string) $org->{$field} !== (string) $value) {
                $changed[$field] = ['from' => $org->{$field}, 'to' => $value];
                $org->{$field} = $value;
            }
        };

        $org->registry_status = $reg['status'];
        $org->registry_checked_at = now();

        if ($reg['status'] !== 'NOT_FOUND') {
            $set('registry_short_name', $reg['short_name'] ?? null);
            $set('registry_full_name', $reg['full_name'] ?? null);
            $set('registry_address', $reg['address'] ?? null);
            $set('registry_director', $reg['director'] ?? null);
            $set('ogrn', $reg['ogrn'] ?? null);

            // КПП у юрлица один на головную организацию — выписка правее
            // нашего разборщика. У ИП КПП нет: пустое значение не пишем.
            if (! empty($reg['kpp'])) {
                $set('kpp', $reg['kpp']);
            }
            if (trim((string) $org->address) === '' && ! empty($reg['address'])) {
                $set('address', $reg['address']);
            }
            if (self::isJunkName((string) $org->name) && ! empty($reg['short_name'])) {
                $set('name', $reg['short_name']);
            }
        }

        $org->save();

        return [
            'ok' => true,
            'changed' => $changed,
            'status' => $reg['status'],
            'message' => $reg['status'] === 'NOT_FOUND'
                ? 'ИНН в ЕГРЮЛ/ЕГРИП не найден.'
                : ($changed === [] ? 'Реквизиты совпадают с реестром.' : 'Обновлено полей: '.count($changed).'.'),
        ];
    }

    /**
     * Взять официальные название и адрес целиком — по решению человека.
     */
    public function adoptOfficial(Organization $org): void
    {
        if ($org->registry_short_name) {
            $org->name = $org->registry_short_name;
        }
        if ($org->registry_address) {
            $org->address = $org->registry_address;
        }
        $org->save();
    }

    /**
     * Название, которое заведомо не название: пусто, «ИНН N», артикул,
     * метка почтового правила, обрывок полной формы капсом.
     */
    public static function isJunkName(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^ИНН\s*\d+$/u', $name) === 1) {
            return true;
        }
        if (str_contains($name, '[') || str_contains($name, '/')) {
            return true;
        }
        // «ип DCSS5-E», «ООО AGH (стандарт EN81-20…» — латиница с цифрами вместо имени.
        if (preg_match('/^(?:ип|ооо|ао|зао)\s+[A-Za-z0-9][A-Za-z0-9\-\s().,\/]*$/iu', $name) === 1) {
            return true;
        }

        // Полная форма, обрезанная посередине: «ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННО».
        return preg_match('/^(ОБЩЕСТВО С ОГРАНИЧЕННОЙ|ТОВАРИЩЕСТВО СОБСТВЕННИКОВ|ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ)/u', $name) === 1
            && preg_match('/[«"„]/u', $name) !== 1;
    }
}
