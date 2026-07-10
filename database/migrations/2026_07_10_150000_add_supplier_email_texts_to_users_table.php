<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Персональные тексты письма поставщику (таб «Поставщики»).
 *
 * Общий (вступительный) текст и завершающий текст в форме рассылки можно
 * править вручную. Чтобы не набирать заново каждый раз, менеджер может
 * сохранить свой вариант — тогда в следующий раз подставится он, а не
 * системный дефолт. Храним per-user, отдельно рус./англ.
 * NULL = персонального нет, используется системный дефолт.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'supplier_intro_ru')) {
                $table->text('supplier_intro_ru')->nullable();
            }
            if (! Schema::hasColumn('users', 'supplier_intro_en')) {
                $table->text('supplier_intro_en')->nullable();
            }
            if (! Schema::hasColumn('users', 'supplier_closing_ru')) {
                $table->text('supplier_closing_ru')->nullable();
            }
            if (! Schema::hasColumn('users', 'supplier_closing_en')) {
                $table->text('supplier_closing_en')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['supplier_intro_ru', 'supplier_intro_en', 'supplier_closing_ru', 'supplier_closing_en'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
