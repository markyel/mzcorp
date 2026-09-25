<?php

namespace App\Services\Clients;

use App\Models\Organization;
use Illuminate\Http\Client\Response;
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
        $inn = preg_replace('/\D+/', '', $inn) ?? '';
        if ($inn === '') {
            return null;
        }
        if (self::isBelarusUnp($inn)) {
            return $this->lookupBelarus($inn);
        }

        $response = $this->ask((string) config('services.dadata.party_url'), [
            'query' => $inn,
            // Филиалы не нужны: реквизиты договора — головной организации.
            'branch_type' => 'MAIN',
        ], $inn);
        if ($response === null) {
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
     * УНП — регистрационный номер плательщика Беларуси, 9 цифр. Российский
     * ИНН всегда 10 или 12, так что по длине они не путаются. В наших КП и
     * счетах белорусский покупатель подписан тем же «ИНН 101439542».
     */
    public static function isBelarusUnp(string $inn): bool
    {
        return preg_match('/^\d{9}$/', $inn) === 1;
    }

    /**
     * Выписка из ЕГР Беларуси. КПП, ОГРН и руководителя там нет — только
     * названия, адрес и статус; остальное карточка берёт из документов.
     *
     * @return array{status: string, short_name?: string, full_name?: string, kpp?: ?string, ogrn?: ?string, address?: ?string, director?: ?string}|null
     */
    private function lookupBelarus(string $unp): ?array
    {
        $response = $this->ask((string) config('services.dadata.party_by_url'), ['query' => $unp], $unp);
        if ($response === null) {
            return null;
        }

        $data = $response->json('suggestions.0.data');
        if (! is_array($data)) {
            return ['status' => 'NOT_FOUND'];
        }

        // У ИП нет названия, есть ФИО.
        $individual = ($data['type'] ?? null) === 'INDIVIDUAL';
        // Краткого названия в ЕГР бывает нет (ЗАО «Гомельлифт») — тогда полное:
        // строка подсказки несёт в себе ещё и УНП.
        $short = ($data['short_name_ru'] ?? null)
            ?: ($individual && ! empty($data['fio_ru']) ? 'ИП '.$data['fio_ru'] : ($data['full_name_ru'] ?? null))
            ?: trim(str_replace($unp, '', (string) $response->json('suggestions.0.value')));

        return [
            'status' => (string) ($data['status'] ?? 'ACTIVE'),
            'short_name' => (string) $short,
            'full_name' => (string) ($data['full_name_ru'] ?? ''),
            'kpp' => null,
            'ogrn' => null,
            'address' => $data['address'] ?? null,
            'director' => $individual ? ($data['fio_ru'] ?? null) : null,
        ];
    }

    /** Запрос к DaData. null — ключа нет, сервис недоступен или ответил ошибкой. */
    private function ask(string $url, array $payload, string $inn): ?Response
    {
        $key = (string) config('services.dadata.api_key');
        if ($key === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('services.dadata.timeout', 10))
                ->withHeaders([
                    'Authorization' => 'Token '.$key,
                    'Accept' => 'application/json',
                ])
                ->asJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('OrganizationRegistryService: dadata unreachable', ['inn' => $inn, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('OrganizationRegistryService: dadata error', ['inn' => $inn, 'status' => $response->status()]);

            return null;
        }

        return $response;
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

        $changed = $this->apply($org, $reg);
        $org->save();

        return [
            'ok' => true,
            'changed' => $changed,
            'status' => $reg['status'],
            'message' => $reg['status'] === 'NOT_FOUND'
                ? (self::isBelarusUnp((string) $org->inn) ? 'УНП в реестре Беларуси не найден.' : 'ИНН в ЕГРЮЛ/ЕГРИП не найден.')
                : ($changed === [] ? 'Реквизиты совпадают с реестром.' : 'Обновлено полей: '.count($changed).'.'),
        ];
    }

    /** Реестр перепроверяем не чаще раза в месяц: реквизиты меняются редко. */
    public const FRESH_DAYS = 30;

    /** Ответы реестра в пределах одного прогона: 1928 документов Liftway — один ИНН. */
    private array $cache = [];

    /**
     * Покупатель из нашего документа (КП, счёт) — через реестр.
     *
     * Это главный вход в реестр клиентов: реквизиты мы достаём из отправленных
     * документов, и всё, что разборщик понял неверно, раньше так и оседало —
     * название, склеенное с артикулом, обрезанный адрес, КПП соседней колонки.
     * Теперь ИНН из документа проверяется по ЕГРЮЛ, и организация заводится
     * уже с официальными данными.
     *
     *   ok          — организация есть в реестре; возвращаем её (новую или
     *                 существующую), реквизиты взяты из выписки;
     *   ours        — это наш собственный ИНН, продавец, а не покупатель;
     *   not_found   — такого ИНН в реестре нет: организацию не заводим, иначе
     *                 в реестр клиентов попадёт мусор;
     *   unavailable — реестр не ответил; решение за вызывающим (повторить
     *                 позже или завести по данным документа).
     *
     * @return array{status: 'ok'|'ours'|'not_found'|'unavailable', org: ?Organization, created: bool}
     */
    public function resolveForIngest(string $inn, ?string $parsedName = null): array
    {
        $inn = preg_replace('/\D+/', '', $inn) ?? '';
        if ($inn === '') {
            return ['status' => 'not_found', 'org' => null, 'created' => false];
        }

        $ours = array_merge(
            [preg_replace('/\D+/', '', (string) config('services.company.inn', '')) ?? ''],
            (array) config('services.company.own_inns', []),
        );
        if (in_array($inn, $ours, true)) {
            return ['status' => 'ours', 'org' => null, 'created' => false];
        }

        $org = Organization::query()->where('inn', $inn)->first();

        // Уже сверена недавно — в реестр не ходим, решение прежнее.
        if ($org !== null && $org->registry_checked_at?->gt(now()->subDays(self::FRESH_DAYS))) {
            return $org->registry_status === 'NOT_FOUND'
                ? ['status' => 'not_found', 'org' => null, 'created' => false]
                : ['status' => 'ok', 'org' => $org, 'created' => false];
        }

        $reg = $this->cache[$inn] ??= $this->lookup($inn);
        if ($reg === null) {
            unset($this->cache[$inn]); // сбой не запоминаем — пусть следующий документ спросит снова

            return ['status' => 'unavailable', 'org' => $org, 'created' => false];
        }

        if ($reg['status'] === 'NOT_FOUND') {
            // Существующую карточку помечаем, но не удаляем: у неё может быть история.
            if ($org !== null) {
                $org->forceFill(['registry_status' => 'NOT_FOUND', 'registry_checked_at' => now()])->save();
            }

            return ['status' => 'not_found', 'org' => null, 'created' => false];
        }

        $created = $org === null;
        if ($created) {
            $org = new Organization(['inn' => $inn]);
            // Новая карточка сразу получает официальное имя, а не догадку
            // разборщика. Разобранное имя — только на крайний случай.
            $official = trim((string) ($reg['short_name'] ?? ''));
            $parsed = trim((string) $parsedName);
            $org->name = $official !== '' ? $official : ($parsed !== '' ? $parsed : 'ИНН '.$inn);
        }

        $this->apply($org, $reg);
        $org->save();

        return ['status' => 'ok', 'org' => $org, 'created' => $created];
    }

    /**
     * Единая политика записи выписки в карточку — и для ручной сверки, и для
     * входящих документов.
     *
     * @param  array<string, mixed>  $reg
     * @return array<string, array{from: ?string, to: ?string}>
     */
    private function apply(Organization $org, array $reg): array
    {
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

        if ($reg['status'] === 'NOT_FOUND') {
            return $changed;
        }

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

        return $changed;
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
        // «slava.alshevski@chasti-stock.by» — адрес получателя из черновика КП.
        if (str_contains($name, '[') || str_contains($name, '/') || str_contains($name, '@')) {
            return true;
        }
        // «ип DCSS5-E», «ООО AGH (стандарт EN81-20…» — латиница с цифрами вместо имени.
        if (preg_match('/^(?:ип|ооо|ао|зао)\s+[A-Za-z0-9][A-Za-z0-9\-\s().,\/]*$/iu', $name) === 1) {
            return true;
        }
        // «ип DDE со шкивом V», «ип - белые звен» — строчное «ип» ставит только
        // разборщик, склеивая форму с куском строки товара. Настоящее — «ИП».
        if (preg_match('/^ип\s/u', $name) === 1) {
            return true;
        }
        // Подписи и банк из соседних колонок документа; одна форма без имени.
        if (preg_match('/Аноним|Карта клиента|Ответственный:|\bБанк\b.*\bг\.\s/u', $name) === 1
            || preg_match('/^(?:общества?|общество)\s+с\s+ограниченной\s+ответственностью$/iu', $name) === 1) {
            return true;
        }

        // Полная форма, обрезанная посередине: «ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННО».
        return preg_match('/^(ОБЩЕСТВО С ОГРАНИЧЕННОЙ|ТОВАРИЩЕСТВО СОБСТВЕННИКОВ|ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ)/u', $name) === 1
            && preg_match('/[«"„]/u', $name) !== 1;
    }
}
