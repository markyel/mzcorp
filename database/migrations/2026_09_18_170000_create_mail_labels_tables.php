<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Метки писем в почтовом клиенте (как в Яндексе): цветная пометка, которых у
 * письма может быть сколько угодно, в отличие от папки — она одна.
 *
 * Словарь меток общий на компанию, а не личный: почта по большей части общая
 * (info@, rfq@), и метка «Тендер» должна значить одно и то же у всех, кто
 * открыл тот же ящик. Кто повесил метку — храним в связующей таблице.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_labels')) {
            Schema::create('mail_labels', function (Blueprint $t) {
                $t->id();
                $t->string('name', 40);
                // Ключ палитры дизайн-системы — см. MailLabel::COLORS.
                $t->string('color', 16)->default('sky');
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
            // Имена уникальны без учёта регистра: «Тендер» и «тендер» — одна метка.
            DB::statement(
                'CREATE UNIQUE INDEX mail_labels_name_lower_idx ON mail_labels (lower(name))'
            );
        }

        if (! Schema::hasTable('email_message_labels')) {
            Schema::create('email_message_labels', function (Blueprint $t) {
                $t->id();
                $t->foreignId('email_message_id')->constrained('email_messages')->cascadeOnDelete();
                $t->foreignId('mail_label_id')->constrained('mail_labels')->cascadeOnDelete();
                $t->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('created_at')->nullable();

                $t->unique(['email_message_id', 'mail_label_id'], 'email_message_labels_unique');
                $t->index('mail_label_id', 'email_message_labels_label_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_message_labels');
        Schema::dropIfExists('mail_labels');
    }
};
