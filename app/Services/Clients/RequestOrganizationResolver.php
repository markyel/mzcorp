<?php

namespace App\Services\Clients;

use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Request as RequestModel;

/**
 * Определяет и проставляет точную привязку заявки к организации-клиенту
 * (requests.organization_id, раздел «Клиенты»).
 *
 * Принцип — консервативно: привязываем только при однозначном сигнале,
 * иначе оставляем null (привязку всегда можно поставить/поправить руками).
 * Источники сигнала по убыванию специфичности:
 *   1) requests.client_company — точное (case-insensitive) совпадение ровно
 *      с одной организацией по названию (поле компании из веб-формы);
 *   2) requests.client_email — контакт привязан ровно к одной организации
 *      (M:N organization_contact). Несколько организаций → неоднозначно → null.
 *
 * Организации в реестре в основном появляются ПОЗЖЕ заявки (из реквизитов
 * отправленного КП через clients:* команды), поэтому привязка «доезжает»
 * двумя путями: (а) для повторных клиентов — сразу при создании заявки, если
 * организация+связь уже есть; (б) при появлении связи email↔организация —
 * backfillForEmailLink() подтягивает уже накопленные заявки этого email.
 */
class RequestOrganizationResolver
{
    /**
     * Подобрать организацию для заявки по уже известным данным. null —
     * данных нет либо сигнал неоднозначный (несколько кандидатов).
     */
    public function resolve(RequestModel $request): ?Organization
    {
        $company = trim((string) $request->client_company);
        if ($company !== '') {
            $matches = Organization::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($company)])
                ->limit(2)
                ->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        $org = $this->singleOrgForEmail((string) $request->client_email);
        if ($org !== null) {
            return $org;
        }

        return null;
    }

    /**
     * Проставить organization_id заявке, если он ещё не задан (или $force).
     * Возвращает true, если значение реально изменилось.
     */
    public function attach(RequestModel $request, bool $force = false): bool
    {
        if ($request->organization_id !== null && ! $force) {
            return false;
        }

        $org = $this->resolve($request);
        if ($org === null || $request->organization_id === $org->id) {
            return false;
        }

        $request->forceFill(['organization_id' => $org->id])->save();

        return true;
    }

    /**
     * При появлении связи email↔организация привязать к ней все ещё НЕ
     * привязанные (organization_id IS NULL) заявки с этим email — но только
     * если этот email относится ровно к одной организации (= $org). Иначе
     * неоднозначно, не трогаем. Возвращает число обновлённых заявок.
     */
    public function backfillForEmailLink(Organization $org, string $email): int
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return 0;
        }

        $single = $this->singleOrgForEmail($email);
        if ($single === null || $single->id !== $org->id) {
            return 0;
        }

        return RequestModel::query()
            ->whereNull('organization_id')
            ->whereRaw('lower(client_email) = ?', [$email])
            ->update(['organization_id' => $org->id]);
    }

    /**
     * Организация, к которой однозначно (ровно одна) привязан контакт с этим
     * email. null — контакта нет, связей нет, либо их несколько.
     */
    private function singleOrgForEmail(string $email): ?Organization
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $contact = ClientContact::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->with('organizations')
            ->first();

        if ($contact === null || $contact->organizations->count() !== 1) {
            return null;
        }

        return $contact->organizations->first();
    }
}
