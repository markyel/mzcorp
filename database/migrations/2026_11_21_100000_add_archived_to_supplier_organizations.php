<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Архив поставщика (07.11.2026): неактуальные импортированные поставщики не видны в реестре и
 * не предлагаются в запросах цен. Признак ставится в QuoteParts (источник правды), здесь — копия;
 * поставщик без связи в QuoteParts архивируется только здесь.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_organizations')) {
            return;
        }
        Schema::table('supplier_organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_organizations', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->index();
            }
            if (! Schema::hasColumn('supplier_organizations', 'archived_reason')) {
                $table->string('archived_reason', 500)->nullable();
            }
            if (! Schema::hasColumn('supplier_organizations', 'archived_by_user_id')) {
                $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('supplier_organizations')) {
            return;
        }
        Schema::table('supplier_organizations', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_organizations', 'archived_by_user_id')) {
                $table->dropConstrainedForeignId('archived_by_user_id');
            }
            if (Schema::hasColumn('supplier_organizations', 'archived_reason')) {
                $table->dropColumn('archived_reason');
            }
            if (Schema::hasColumn('supplier_organizations', 'archived_at')) {
                $table->dropIndex(['archived_at']);
                $table->dropColumn('archived_at');
            }
        });
    }
};
