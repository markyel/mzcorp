<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Точная привязка заявки к организации-клиенту (раздел «Клиенты»).
 *
 * Раньше связь заявки с организацией выводилась только косвенно — через
 * requests.client_email ∈ email'ы контактов организации. Это неточно (один
 * email может относиться к нескольким организациям) и не поддаётся ручной
 * правке. Новый FK requests.organization_id даёт явную, переопределяемую
 * привязку: автоматически проставляется RequestOrganizationResolver'ом
 * (по client_company / по единственной организации контакта), бэкфиллится
 * командой clients:backfill, всегда поправима руками.
 *
 * nullOnDelete: удаление организации в UI обнуляет ссылку у заявок (заявки
 * не удаляются — это уже декларируется в карточке организации).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('requests', 'organization_id')) {
            return;
        }
        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()
                ->constrained('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('requests', 'organization_id')) {
            return;
        }
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
