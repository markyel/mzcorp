<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Почтовые ящики, подключенные к MyLift.
 *
 * Foundation §1: общие (sales@, info@) + личные ящики менеджеров.
 * Аутентификация на старте — Yandex App passwords (см. MEMORY.md, Phase 1).
 *
 * Креды (пароль app-password) — в encrypted_credentials (jsonb) с Laravel
 * encrypted cast, ключ из APP_KEY. Содержит { "password": "..." } и резерв
 * под будущий OAuth-токен.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();

            // Основное
            $table->string('name')->comment('Человекочитаемое имя для UI: "Sales (общий)", "Иванов (личный)"');
            $table->string('email')->unique()->comment('Адрес ящика, например sales@mylift.ru');
            $table->string('type')->index()->comment('shared | personal — см. App\Enums\MailboxType');
            $table->foreignId('owner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('NULL для shared; FK на users для personal');

            // IMAP
            $table->string('imap_host')->default('imap.yandex.ru');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 16)->default('ssl')->comment('ssl | tls | starttls | none');
            $table->string('imap_username')->comment('Обычно совпадает с email');

            // SMTP
            $table->string('smtp_host')->default('smtp.yandex.ru');
            $table->unsignedSmallInteger('smtp_port')->default(465);
            $table->string('smtp_encryption', 16)->default('ssl');
            $table->string('smtp_username')->comment('Обычно совпадает с email');

            // Креды (зашифровано через Laravel encrypted cast)
            $table->text('encrypted_credentials')
                ->comment('Encrypted JSON: { "password": "...", "oauth_token": "..." }');

            // Состояние
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable()->comment('Последний успешный sync любой папки');
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailboxes');
    }
};
