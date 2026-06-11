<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Срок действия / резерва исходящего счёта или КП, извлечённый парсером
 * из самого документа ИЛИ из сопроводительного письма.
 *
 * Раньше срок счёта всегда вычислялся как «дата документа + 5 рабочих дней»
 * (config services.invoices.default_validity_business_days), из-за чего
 * напоминание «срок счёта истекает» уходило раньше реального срока резерва,
 * указанного в счёте/письме (кейс M-2026-3307: резерв до 16.06, а система
 * считала 11.06). Теперь парсер вытаскивает явную дату «действителен до»,
 * и InvoiceService использует её для expires_at; +5 дней остаётся fallback'ом.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outbound_quotes')) {
            return;
        }
        if (Schema::hasColumn('outbound_quotes', 'valid_until')) {
            return;
        }

        Schema::table('outbound_quotes', function (Blueprint $table) {
            $table->date('valid_until')->nullable()->after('document_date');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('outbound_quotes')) {
            return;
        }
        if (! Schema::hasColumn('outbound_quotes', 'valid_until')) {
            return;
        }

        Schema::table('outbound_quotes', function (Blueprint $table) {
            $table->dropColumn('valid_until');
        });
    }
};
