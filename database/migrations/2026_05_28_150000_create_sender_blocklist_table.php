<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Стоп-лист отправителей: email-адреса и домены, письма от которых
 * MyLift полностью игнорирует (никаких Request, никаких уведомлений).
 *
 * Foundation §1.5 концепция `from_domain ∈ blacklist` доведена до
 * полноценной сущности с двумя путями ввода: ручной CRUD (РОП/admin)
 * и кнопка «Закрыть как спам» на карточке заявки.
 *
 * Семантика матчинга:
 *   - type=email  → точное совпадение по `normalized_value`. Plus-addressing
 *                   срезается при нормализации (foo+bar@x.ru → foo@x.ru).
 *   - type=domain → суффикс-матч: `paulschaab.de` ловит `mail.paulschaab.de`,
 *                   но НЕ `paulschaab.de.evil.com` (страж разделитель `.`).
 *
 * Применяется в `IncomingMailProcessor::processIfRequest()` ДО создания
 * Request. Старые открытые заявки не трогаем (см. MEMORY § «Стоп-лист»).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sender_blocklist')) {
            return;
        }

        Schema::create('sender_blocklist', function (Blueprint $table) {
            $table->id();

            $table->string('type', 16)
                ->comment('email | domain — App\Enums\BlocklistEntryType');

            $table->string('value')
                ->comment('Исходное значение как ввёл пользователь (для отображения)');

            $table->string('normalized_value')
                ->comment('Нормализованная форма для матчинга: lowercase, без plus-addressing');

            $table->string('source', 32)
                ->comment('manual | from_request — App\Enums\BlocklistEntrySource');

            $table->text('comment')->nullable()
                ->comment('Свободный комментарий: почему заблокировали');

            $table->foreignId('added_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignId('added_from_request_id')->nullable()
                ->constrained('requests')->nullOnDelete()
                ->comment('Ссылка на заявку, из которой добавлена запись (source=from_request)');

            $table->unsignedInteger('hit_count')->default(0)
                ->comment('Сколько раз правило сработало (для аналитики в админке)');

            $table->timestamp('last_hit_at')->nullable()
                ->comment('Когда последний раз письмо было отбито этой записью');

            $table->timestamps();

            // Уникальность по (type, normalized_value) — нельзя завести
            // одно и то же дважды. Деleted_at нет: при «снятии блока»
            // запись удаляется физически (для прозрачности — если в стоп-
            // листе пусто, никаких скрытых записей не блокирует).
            $table->unique(['type', 'normalized_value'], 'sender_blocklist_type_value_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_blocklist');
    }
};
