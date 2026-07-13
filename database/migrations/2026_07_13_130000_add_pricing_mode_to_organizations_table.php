<?php

use App\Enums\OrganizationPricingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Режим расчёта цены для покупателя.
 *
 *   standard  — обычное ценообразование (каталог − скидка, пол price_min).
 *   cost_plus — спец-режим «Себестоимость + наценка» (см. OrganizationPricingMode).
 *
 * Дефолт standard, чтобы существующие организации не поменяли поведение.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organizations', 'pricing_mode')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->string('pricing_mode', 16)
                    ->default(OrganizationPricingMode::Standard->value)
                    ->index()
                    ->after('discount_percent');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('organizations', 'pricing_mode')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('pricing_mode');
            });
        }
    }
};
