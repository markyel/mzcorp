<?php

use App\Enums\OrganizationPricingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снапшот режима ценообразования на КП (immutability).
 *
 * pricing_mode копируется из Organization при формировании КП. cost_markup_percent
 * фиксирует наценку config('services.pricing.cost_plus_markup') на момент создания —
 * если глобальную наценку поменяют, исторические КП останутся с той, что были
 * посчитаны. См. QuotationService::recalcTotals / applyOrganization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'pricing_mode')) {
                $table->string('pricing_mode', 16)
                    ->default(OrganizationPricingMode::Standard->value)
                    ->index()
                    ->after('discount_percent');
            }
            if (! Schema::hasColumn('quotations', 'cost_markup_percent')) {
                // Наценка (%) режима cost_plus, зафиксированная на момент расчёта.
                // NULL для standard-КП.
                $table->decimal('cost_markup_percent', 6, 2)->nullable()->after('pricing_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            foreach (['pricing_mode', 'cost_markup_percent'] as $col) {
                if (Schema::hasColumn('quotations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
