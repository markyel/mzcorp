<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Импорт оплат из 1С (раздел «Счета»):
 *  - payment_imports — шапка загрузки (кто, когда, файл, счётчики исходов);
 *  - imported_payments — журнал строк: каждая строка выгрузки с исходом
 *    (оплачен / частично / уже был оплачен / неизвестный счёт / пропущен).
 *    Неизвестные (outcome=unknown) образуют вкладку «Внешние оплаты» и могут
 *    быть привязаны к заявке вручную или автоматически при появлении счёта.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_imports')) {
            Schema::create('payment_imports', function (Blueprint $table) {
                $table->id();
                $table->string('filename');
                $table->foreignId('uploaded_by_user_id')->constrained('users');
                $table->unsignedInteger('rows_total')->default(0);
                $table->unsignedInteger('marked_paid')->default(0);
                $table->unsignedInteger('marked_partial')->default(0);
                $table->unsignedInteger('already_paid')->default(0);
                $table->unsignedInteger('unknown_recorded')->default(0);
                $table->unsignedInteger('skipped_old')->default(0);
                $table->unsignedInteger('errors')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('imported_payments')) {
            Schema::create('imported_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_import_id')->constrained('payment_imports')->cascadeOnDelete();
                $table->string('invoice_number', 64);
                $table->unsignedBigInteger('invoice_number_int')->nullable()->index();
                $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
                // marked_paid | marked_partial | already_paid | unknown |
                // skipped_old | error | ignored | linked
                $table->string('outcome', 32)->index();
                $table->string('client_name')->nullable();
                $table->string('manager_name')->nullable();
                $table->text('payment_purpose')->nullable();
                $table->date('invoice_date')->nullable();
                $table->date('paid_date')->nullable();
                $table->unsignedSmallInteger('paid_percent')->nullable();
                $table->decimal('paid_sum', 14, 2)->nullable();
                $table->decimal('debt_sum', 14, 2)->nullable();
                $table->decimal('revenue_sum', 14, 2)->nullable();
                $table->decimal('cost_sum', 14, 2)->nullable();
                $table->decimal('profit_sum', 14, 2)->nullable();
                $table->text('note')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('imported_payments')) {
            Schema::drop('imported_payments');
        }
        if (Schema::hasTable('payment_imports')) {
            Schema::drop('payment_imports');
        }
    }
};
