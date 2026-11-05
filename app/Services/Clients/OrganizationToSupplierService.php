<?php

namespace App\Services\Clients;

use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\SupplierOrganization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Клиент, который на деле поставщик (заказчик, 05.11.2026: после импорта клиентской базы из 1С
 * часть поставщиков оказалась в клиентах — «RED (R.E.D. Handelsgesellschaft mbH) WAGO дилер»).
 *
 * Перевод: организация поставщика — выбранная (тот же поставщик под другим названием: «ELDIN (ЭЛДИН)»
 * и «АО "ЭЛДИН"»), найденная по ИНН или по адресам контактов, иначе новая; ИНН уходит на неё —
 * по нему импорт из 1С больше не заводит клиента. Адреса контактов становятся поставщиками этой
 * организации (уже известные — привязываются, если ещё ни к кому не привязаны). Заявки, чаты,
 * скидка и прочие ссылки отвязываются от организации клиента, контакты без других организаций
 * удаляются, организация клиента удаляется. Заявки не закрываются и не меняются — только теряют
 * клиента; список отвязанных уходит в лог.
 */
class OrganizationToSupplierService
{
    /**
     * @return array{supplier_organization: SupplierOrganization, suppliers_created: int, suppliers_attached: int, requests_unlinked: int, contacts_removed: int}
     */
    public function convert(Organization $org, ?int $intoSupplierOrganizationId = null, ?User $by = null): array
    {
        return DB::transaction(function () use ($org, $intoSupplierOrganizationId, $by) {
            $contacts = $org->contacts()->get();
            $emails = $contacts->pluck('email')->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter()->unique()->values();
            $known = Supplier::query()->whereIn(DB::raw('lower(email)'), $emails->all())->get()->keyBy(fn ($s) => mb_strtolower($s->email));

            $target = $intoSupplierOrganizationId !== null
                ? SupplierOrganization::query()->findOrFail($intoSupplierOrganizationId)
                : ($org->inn ? SupplierOrganization::query()->where('inn', $org->inn)->first() : null)
                    ?? SupplierOrganization::query()->whereKey($known->pluck('supplier_organization_id')->filter()->countBy()->sortDesc()->keys()->first())->first();
            $note = sprintf('Переведён из клиентов %s%s: «%s»%s.', now()->format('d.m.Y'), $by ? ' ('.$by->name.')' : '', $org->name, $org->inn ? ', ИНН '.$org->inn : '');
            if ($target === null) {
                $target = SupplierOrganization::query()->create([
                    'name' => mb_substr((string) $org->name, 0, 255),
                    'inn' => $org->inn,
                    'notes' => $note,
                    'created_by_user_id' => $by?->id,
                ]);
            } else {
                $target->forceFill([
                    'inn' => $target->inn ?: $org->inn,
                    'notes' => trim(($target->notes ? $target->notes."\n" : '').$note),
                ])->save();
            }

            $created = 0;
            $attached = 0;
            foreach ($contacts as $contact) {
                $email = mb_strtolower(trim((string) $contact->email));
                if ($email === '') {
                    continue;
                }
                $supplier = $known[$email] ?? null;
                if ($supplier === null) {
                    Supplier::query()->create([
                        'email' => $email,
                        'domain' => substr(strrchr($email, '@') ?: '', 1) ?: null,
                        'name' => mb_substr((string) $target->name, 0, 255),
                        'contact_person' => $contact->full_name ?: null,
                        'phone' => $contact->phone ?: null,
                        'created_by_user_id' => $by?->id,
                        'supplier_organization_id' => $target->id,
                    ]);
                    $created++;
                } elseif ($supplier->supplier_organization_id === null) {
                    $supplier->forceFill(['supplier_organization_id' => $target->id])->save();
                    $attached++;
                }
            }

            $requestIds = DB::table('requests')->where('organization_id', $org->id)->pluck('internal_code')->all();
            DB::table('requests')->where('organization_id', $org->id)->update(['organization_id' => null]);
            foreach (['messenger_chats', 'auto_quote_snapshots', 'client_discounts'] as $table) {
                DB::table($table)->where('organization_id', $org->id)->update(['organization_id' => null]);
            }
            DB::table('client_contacts')->where('pinned_organization_id', $org->id)->update(['pinned_organization_id' => null, 'pinned_at' => null, 'pinned_by_user_id' => null]);
            DB::table('organization_link_requests')->where('organization_id', $org->id)->delete();

            $contactIds = $contacts->pluck('id')->all();
            $org->contacts()->detach();
            $orphans = ClientContact::query()->whereIn('id', $contactIds)->whereDoesntHave('organizations')->pluck('id')->all();
            DB::table('organization_link_requests')->whereIn('client_contact_id', $orphans)->delete();
            ClientContact::query()->whereIn('id', $orphans)->delete();

            Log::info('Client organization converted to supplier', [
                'organization_id' => $org->id, 'name' => $org->name, 'inn' => $org->inn, 'supplier_organization_id' => $target->id,
                'by_user_id' => $by?->id, 'emails' => $emails->all(), 'requests_unlinked' => $requestIds,
            ]);
            $org->delete();

            return [
                'supplier_organization' => $target,
                'suppliers_created' => $created,
                'suppliers_attached' => $attached,
                'requests_unlinked' => count($requestIds),
                'contacts_removed' => count($orphans),
            ];
        });
    }
}
