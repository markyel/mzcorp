<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Раздел «Маркетинг» (только админ): рабочее место по договору оказания
 * маркетинговых услуг.
 *
 *  - marketing_services — доступы к внешним сервисам (Яндекс.Директ, рассылки,
 *    аналитика). Секреты лежат в encrypted_secrets (Crypt::encryptString),
 *    в открытом виде в БД не хранится ничего.
 *  - marketing_entries  — план, заметки и журнал выполненных работ; раздел
 *    (section) совпадает с разделом формы ежемесячного отчёта, поэтому отчёт
 *    собирается из журнала без ручного переноса.
 *  - marketing_reports  — сам ежемесячный отчёт по форме Приложения № 1:
 *    payload хранит заполненную форму, чтобы выгрузка в .docx была
 *    воспроизводимой после правок журнала.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_services')) {
            Schema::create('marketing_services', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                // ads | email | analytics | social | site | other — см. MarketingService::CATEGORIES
                $t->string('category', 32)->default('other')->index();
                $t->string('url', 500)->nullable();
                $t->string('login', 255)->nullable();
                // json {password, api_key, extra} под Crypt::encryptString
                $t->text('encrypted_secrets')->nullable();
                // На кого оформлен аккаунт (ИП/ООО/сотрудник) — не секрет.
                $t->string('account_owner', 160)->nullable();
                $t->text('notes')->nullable();
                $t->boolean('is_active')->default(true)->index();
                $t->timestamp('last_verified_at')->nullable();
                $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('marketing_entries')) {
            Schema::create('marketing_entries', function (Blueprint $t) {
                $t->id();
                // plan | work | note — см. MarketingEntry::KINDS
                $t->string('kind', 16)->index();
                // Раздел формы отчёта — см. App\Enums\MarketingSection
                $t->string('section', 32)->index();
                // Первый день отчётного месяца.
                $t->date('period');
                $t->string('title', 200);
                $t->text('body')->nullable();
                // Показатели раздела «Реклама и продвижение»: spend, impressions,
                // clicks, leads, cpl и произвольные пары.
                $t->jsonb('metrics')->nullable();
                // planned | in_progress | done | dropped
                $t->string('status', 16)->default('planned')->index();
                $t->unsignedTinyInteger('priority')->default(2);
                // Дата факта для kind=work (в отчёт попадает по периоду).
                $t->date('happened_on')->nullable();
                $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();

                $t->index(['period', 'kind'], 'marketing_entries_period_kind_idx');
            });
        }

        if (! Schema::hasTable('marketing_reports')) {
            Schema::create('marketing_reports', function (Blueprint $t) {
                $t->id();
                $t->date('period')->unique();
                // draft | final
                $t->string('status', 16)->default('draft')->index();
                // Заполненная форма Приложения № 1 (разделы + показатели + план).
                $t->jsonb('payload')->nullable();
                $t->timestamp('finalized_at')->nullable();
                $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_reports');
        Schema::dropIfExists('marketing_entries');
        Schema::dropIfExists('marketing_services');
    }
};
