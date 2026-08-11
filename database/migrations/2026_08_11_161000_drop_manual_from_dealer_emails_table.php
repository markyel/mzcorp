<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Откат ручного управления в dealer_emails: «дилер» и «перепродавец» — РАЗНЫЕ
 * понятия. Дилер = много заявок (может быть и прямой заказчик), авто, влияет на
 * распределение — остаётся чисто авто (как было). Перепродавец = ручная
 * бизнес-классификация → отдельная таблица reseller_emails. Убираем ошибочно
 * добавленные marked_by_user_id / manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealer_emails', function (Blueprint $table) {
            if (Schema::hasColumn('dealer_emails', 'marked_by_user_id')) {
                $table->dropConstrainedForeignId('marked_by_user_id');
            }
            if (Schema::hasColumn('dealer_emails', 'manual')) {
                $table->dropColumn('manual');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dealer_emails', function (Blueprint $table) {
            if (! Schema::hasColumn('dealer_emails', 'marked_by_user_id')) {
                $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('dealer_emails', 'manual')) {
                $table->boolean('manual')->nullable()->index();
            }
        });
    }
};
