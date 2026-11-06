<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поставщики переезжают в QuoteParts (решение заказчика 06.11.2026): поставщик — организация,
 * её адреса — контакты, как в QuoteParts.
 *
 * Ключ поставщика в QuoteParts (qp_key) — код контрагента в 1С, у кого его ещё нет — временный
 * «mz:{id}». Прежний ключ собирался из внутренних номеров («org:5», «sup:12») и менялся при каждом
 * слиянии адресов; новый не меняется. onec_candidates — коды 1С, между которыми человек выбирает
 * сам (в 1С несколько карточек одной фирмы). qp_* — копия того, что о поставщике знает QuoteParts.
 * У адреса — темы контакта (quoting, finance…), порядок и откуда он взялся.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_organizations', 'qp_key')) {
                $table->string('qp_key', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('supplier_organizations', 'onec_code')) {
                $table->string('onec_code', 32)->nullable()->unique();
            }
            if (! Schema::hasColumn('supplier_organizations', 'onec_name')) {
                $table->string('onec_name', 255)->nullable();
            }
            if (! Schema::hasColumn('supplier_organizations', 'onec_candidates')) {
                $table->jsonb('onec_candidates')->nullable();
            }
            if (! Schema::hasColumn('supplier_organizations', 'qp_status')) {
                $table->string('qp_status', 24)->nullable();
            }
            if (! Schema::hasColumn('supplier_organizations', 'qp_payload')) {
                $table->jsonb('qp_payload')->nullable();
            }
            if (! Schema::hasColumn('supplier_organizations', 'qp_synced_at')) {
                $table->timestamp('qp_synced_at')->nullable();
            }
        });

        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'contact_topics')) {
                $table->jsonb('contact_topics')->nullable();
            }
            if (! Schema::hasColumn('suppliers', 'contact_position')) {
                $table->unsignedSmallInteger('contact_position')->nullable();
            }
            if (! Schema::hasColumn('suppliers', 'source')) {
                // mail — из переписки, onec — из контактов 1С, quoteparts — заведён в QuoteParts.
                $table->string('source', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            foreach (['source', 'contact_position', 'contact_topics'] as $column) {
                if (Schema::hasColumn('suppliers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('supplier_organizations', function (Blueprint $table) {
            foreach (['qp_synced_at', 'qp_payload', 'qp_status', 'onec_candidates', 'onec_name', 'onec_code', 'qp_key'] as $column) {
                if (Schema::hasColumn('supplier_organizations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
