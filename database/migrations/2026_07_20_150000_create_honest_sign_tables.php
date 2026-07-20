<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Честный знак»: журнал разборов PDF с кодами маркировки + сами коды.
 *
 * Две таблицы:
 *  - honest_sign_batches — одна загрузка (пачка PDF ± файл поставки),
 *    кто и когда, сводка;
 *  - honest_sign_codes — отдельный код маркировки (КИЗ). Отдельной строкой,
 *    чтобы работал поиск «в какую поставку ушёл этот КИЗ» и ловились повторы.
 *
 * Сами PDF/Excel НЕ храним (см. [[email-storage-pruning]] — квота БД). В базе
 * только распознанные коды и имена файлов.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('honest_sign_batches')) {
            Schema::create('honest_sign_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('title', 255)->nullable();          // имя файла поставки / метка
                $table->unsignedInteger('pdf_count')->default(0);
                $table->unsignedInteger('codes_count')->default(0);
                $table->unsignedInteger('rows_filled')->default(0); // сколько строк Excel заполнено
                $table->jsonb('warnings')->nullable();
                $table->timestamps();

                $table->index('user_id');
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('honest_sign_codes')) {
            Schema::create('honest_sign_codes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('honest_sign_batch_id')->constrained()->cascadeOnDelete();
                $table->string('code', 255);            // полный КИЗ
                $table->string('gtin', 14)->index();
                $table->string('serial', 255);          // серийник + крипто-хвост
                $table->string('article', 64)->nullable()->index();  // MZ-ID из PDF
                $table->string('product_name', 500)->nullable();
                $table->string('source_file', 255)->nullable();
                $table->unsignedInteger('page')->nullable();
                $table->timestamps();

                $table->index('honest_sign_batch_id');
                // Поиск по коду (точный и по хвосту) + детект повторной подачи.
                $table->index('code');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('honest_sign_codes');
        Schema::dropIfExists('honest_sign_batches');
    }
};
