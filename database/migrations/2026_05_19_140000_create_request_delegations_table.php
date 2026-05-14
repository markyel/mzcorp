<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation Фаза 2 — delegation механизм для отсутствующих менеджеров.
 *
 * Реальный кейс: менеджер ушёл в отпуск/командировку. Заявка остаётся
 * за ним (`requests.assigned_user_id` НЕ меняется), но на время его
 * отсутствия другой менеджер получает доступ — видит её в своём Pool,
 * может работать (отвечать клиенту, менять статус, править позиции).
 * Когда оригинал вернулся — delegation закрывается, второй больше не
 * видит заявку, оригинал продолжает работу.
 *
 * Семантика отличается от reassignment'а (там assigned_user_id меняется
 * и заявка фактически переходит к новому). Здесь — временный shared
 * access, оригинальный owner сохраняется.
 *
 * Поля:
 *  - request_id — на какую заявку.
 *  - original_user_id — кто настоящий владелец (тот кто в отпуске).
 *  - acting_user_id — кому выдан временный доступ.
 *  - started_at — когда delegation открыт.
 *  - ended_at — когда закрыт (NULL = active). Закрытие происходит при
 *    ManagerUnavailabilityService::markAvailable.
 *  - reason — текст «отпуск Иванова до 25.05», для audit'а.
 *
 * Индексы:
 *  - (acting_user_id, ended_at) — Pool менеджера ищет active delegations
 *    где он acting.
 *  - (original_user_id, ended_at) — markAvailable ищет active delegations
 *    конкретного отсутствующего менеджера, чтобы закрыть.
 *  - (request_id, ended_at) — UI Detail быстро проверяет «есть ли
 *    active delegation у этой заявки».
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('request_delegations')) {
            return;
        }

        Schema::create('request_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignId('original_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('acting_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['acting_user_id', 'ended_at']);
            $table->index(['original_user_id', 'ended_at']);
            $table->index(['request_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_delegations');
    }
};
