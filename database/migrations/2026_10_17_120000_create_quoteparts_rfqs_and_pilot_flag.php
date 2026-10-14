<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'quoteparts_pilot')) {
            Schema::table('users', function (Blueprint $table) {
                // Участник пилота QuoteParts: видит канал «Запрос через QuoteParts»,
                // когда общий рубильник quoteparts.rfq_enabled включён.
                $table->boolean('quoteparts_pilot')->default(false);
            });
        }

        if (! Schema::hasTable('quoteparts_rfqs')) {
            // Запросы поставщикам, отправленные через QuoteParts. Ответы (офферы, отказы,
            // вопросы) живут в QuoteParts; здесь — последний снимок для показа в заявке.
            Schema::create('quoteparts_rfqs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('remote_id')->unique();
                $table->string('tag', 20)->nullable();
                $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('reply_by')->nullable();
                $table->string('status', 20)->default('open');
                $table->jsonb('snapshot')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->index('request_id');
                $table->index(['status', 'synced_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quoteparts_rfqs');

        if (Schema::hasColumn('users', 'quoteparts_pilot')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('quoteparts_pilot'));
        }
    }
};
