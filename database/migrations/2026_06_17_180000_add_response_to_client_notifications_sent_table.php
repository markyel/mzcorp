<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отметка ответа клиента на оживляющее письмо (RevivalOffer): когда ответил и
 * как классифицирован интент (positive / negative / unclear). Нужна, чтобы не
 * перезапускать LLM-классификацию на каждый последующий reply клиента и для
 * аудита результата рассылки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_notifications_sent', function (Blueprint $table) {
            if (! Schema::hasColumn('client_notifications_sent', 'responded_at')) {
                $table->timestamp('responded_at')->nullable()->after('sent_at');
            }
            if (! Schema::hasColumn('client_notifications_sent', 'response_intent')) {
                $table->string('response_intent', 32)->nullable()->after('responded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_notifications_sent', function (Blueprint $table) {
            foreach (['responded_at', 'response_intent'] as $col) {
                if (Schema::hasColumn('client_notifications_sent', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
