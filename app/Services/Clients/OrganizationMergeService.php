<?php

namespace App\Services\Clients;

use App\Enums\OrganizationPricingMode;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Request as RequestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Слияние карточки-двойника в основную организацию.
 *
 * Двойники появлялись, пока реестр собирался из названий: «СП Интерлифт»
 * из веб-формы жила рядом с ООО СП "ИНТЕРЛИФТ" (ИНН 9701122589), «ЕнисейЛифт»
 * — рядом с ООО "ЕнисейЛифт". Всё, что висит на двойнике, переезжает в
 * основную карточку; условия работы (скидка, режим цены) переносятся,
 * только если у основной их нет, — договорённость основной главнее.
 */
class OrganizationMergeService
{
    /**
     * @return array{requests: int, contacts: int, discounts: int, snapshots: int, pinned: int, taken: array<int, string>}
     */
    public function merge(Organization $from, Organization $into): array
    {
        if ($from->id === $into->id) {
            throw new \InvalidArgumentException('Нельзя слить организацию саму в себя.');
        }

        return DB::transaction(function () use ($from, $into) {
            $stats = [
                'requests' => RequestModel::query()->where('organization_id', $from->id)
                    ->update(['organization_id' => $into->id]),
                'contacts' => 0,
                'discounts' => DB::table('client_discounts')->where('organization_id', $from->id)
                    ->update(['organization_id' => $into->id]),
                'snapshots' => DB::table('auto_quote_snapshots')->where('organization_id', $from->id)
                    ->update(['organization_id' => $into->id]),
                'pinned' => ClientContact::query()->where('pinned_organization_id', $from->id)
                    ->update(['pinned_organization_id' => $into->id]),
                'taken' => [],
            ];

            $contactIds = $from->contacts()->pluck('client_contacts.id')->all();
            if ($contactIds !== []) {
                $stats['contacts'] = count($into->contacts()->syncWithoutDetaching($contactIds)['attached']);
            }
            $from->contacts()->detach();

            if ((float) $into->discount_percent <= 0 && (float) $from->discount_percent > 0) {
                $into->discount_percent = $from->discount_percent;
                $stats['taken'][] = 'скидка '.$from->discount_percent.'%';
            }
            if ($into->pricing_mode === OrganizationPricingMode::Standard
                && $from->pricing_mode !== null && $from->pricing_mode !== OrganizationPricingMode::Standard) {
                $into->pricing_mode = $from->pricing_mode;
                $stats['taken'][] = 'режим цены';
            }
            foreach (['kpp', 'address', 'requisites_text', 'notes'] as $field) {
                if (trim((string) $into->{$field}) === '' && trim((string) $from->{$field}) !== '') {
                    $into->{$field} = $from->{$field};
                    $stats['taken'][] = $field;
                }
            }
            $into->save();
            $from->delete();

            Log::info('Organization merged', ['from' => $from->id, 'from_name' => $from->name, 'into' => $into->id] + $stats);

            return $stats;
        });
    }
}
