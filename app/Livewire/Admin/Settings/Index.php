<?php

namespace App\Livewire\Admin\Settings;

use App\Models\AppSetting;
use App\Services\Settings\SettingsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Phase 2 шаг 2 UI «Настройки»: редактирование значений `app_settings`
 * с fallback на config/env, если override отсутствует.
 *
 * Доступ — middleware role:head_of_sales,director (см. routes/web.php).
 *
 * Каждая редактируемая настройка описана в SCHEMA — тип, дефолт из config,
 * подпись/подсказка. UI рендерит соответствующий control:
 *   - bool   → checkbox
 *   - float  → input type=number step=0.01
 *   - int    → input type=number step=1
 *   - string → input type=text (или select, если есть `options`)
 *
 * Save кнопка пишет ВСЕ изменённые поля одной партией через
 * SettingsService и сбрасывает кэш — изменения видны на следующем
 * запросе (без queue:restart / config:clear).
 */
class Index extends Component
{
    /**
     * Текущие значения формы. Ключ — dot-нотация настройки, значение —
     * введённое пользователем (приведённое к типу при сохранении).
     */
    public array $values = [];

    /**
     * Schema editable-настроек.
     *
     * @return array<string, array{
     *   group: string,
     *   label: string,
     *   help: string,
     *   type: 'string'|'int'|'float'|'bool',
     *   default: mixed,
     *   step?: float,
     *   min?: float|int,
     *   max?: float|int,
     *   options?: array<string, string>,
     * }>
     */
    public static function schema(): array
    {
        return [
            // ─── Каталог: matching по name ────────────────────────────────
            'catalog.name_match.enabled' => [
                'group' => 'Каталог · матчинг по name',
                'label' => 'Включить семантический матчинг (C-step)',
                'help' => 'Если выключено — позиции без точного M-SKU или brand_article не сматчиваются с каталогом через эмбеддинги.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => true,
            ],
            'catalog.name_match.threshold' => [
                'group' => 'Каталог · матчинг по name',
                'label' => 'Порог cosine similarity',
                'help' => 'Минимальное сходство (0..1) для попадания в C-step. Ниже — vector top-1 отклоняется.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => 0.75,
                'step' => 0.01,
                'min' => 0.0,
                'max' => 1.0,
            ],
            'catalog.name_match.hc_threshold' => [
                'group' => 'Каталог · матчинг по name',
                'label' => 'High-confidence порог (без LLM-проверки)',
                'help' => 'Similarity ≥ этого значения → LLM-валидация пропускается, vector считается достоверным. Между threshold и hc — обязательная LLM-проверка.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => 0.90,
                'step' => 0.01,
                'min' => 0.0,
                'max' => 1.0,
            ],
            'catalog.name_match.llm_validation_enabled' => [
                'group' => 'Каталог · матчинг по name',
                'label' => 'Включить LLM-валидацию',
                'help' => 'Третий stage two-stage retrieval: gpt-4o-mini проверяет, действительно ли это один и тот же товар. Без неё precision падает.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => true,
            ],
            'catalog.name_match.llm_fail_action' => [
                'group' => 'Каталог · матчинг по name',
                'label' => 'Поведение при сбое LLM',
                'help' => '«reject» — match отклоняем (precision приоритет). «accept» — принимаем без проверки (recall приоритет).',
                'type' => AppSetting::TYPE_STRING,
                'default' => 'reject',
                'options' => ['reject' => 'reject (отклонять)', 'accept' => 'accept (принимать)'],
            ],

            // ─── Каталог: импорт ──────────────────────────────────────────
            'catalog.import.min_full_rows' => [
                'group' => 'Каталог · импорт',
                'label' => 'Минимум строк в full snapshot',
                'help' => 'POST /api/catalog/import с rows < этого значения отвергается 422 без записи в БД. Защита от случайного обнуления каталога.',
                'type' => AppSetting::TYPE_INT,
                'default' => 1,
                'step' => 1,
                'min' => 1,
            ],

            // ─── Налоги ───────────────────────────────────────────────────
            'tax.vat_percent' => [
                'group' => 'Налоги',
                'label' => 'Ставка НДС, %',
                'help' => 'Используется в hero и table-footer карточки заявки для расчёта итога. 2026+: 22. Поддерживает дробные значения.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => 22,
                'step' => 0.5,
                'min' => 0,
                'max' => 50,
            ],

            // ─── Распределение заявок ────────────────────────────────────
            'assignment.newbie_boost' => [
                'group' => 'Распределение заявок',
                'label' => 'Скорость догона отстающих (X)',
                'help' => 'Во сколько раз больше заявок получает самый отстающий менеджер по сравнению с самым загруженным. 1 = плоская раздача (равенство), 2 = ×2 (рекомендуем), 5 = агрессивный onboarding. Промежуточные коэффициенты считаются линейно. Sticky-привязки (по каталогу/клиенту/тексту) идут отдельно и приоритетнее этой балансировки.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => 2.0,
                'step' => 0.5,
                'min' => 1.0,
                'max' => 10.0,
            ],
            'dealer.auto_threshold' => [
                'group' => 'Распределение заявок',
                'label' => 'Порог автопометки дилера (заявок за окно)',
                'help' => 'Если у одного client_email пришло столько заявок или больше за «окно» (см. ниже, любой статус) — email автоматически помечается «дилерским», и client-sticky (1b) для него отключается (заявки уходят через round-robin, а не липнут к одному менеджеру). Catalog/text-sticky продолжают работать. 0 = выключить автопометку.',
                'type' => AppSetting::TYPE_INT,
                'default' => 8,
                'step' => 1,
                'min' => 0,
                'max' => 100,
            ],
            'dealer.auto_window_days' => [
                'group' => 'Распределение заявок',
                'label' => 'Окно автопометки дилера (дней)',
                'help' => 'За сколько последних дней считать поток заявок клиента для порога выше. По умолчанию 30. Окно (а не «N одновременно открытых») ловит и дистрибьюторов с быстрым оборотом. 0 = считать по всей истории.',
                'type' => AppSetting::TYPE_INT,
                'default' => 30,
                'step' => 1,
                'min' => 0,
                'max' => 365,
            ],

            // ─── Attention — дедлайны (Foundation §5.5) ──────────────────
            // Раб. часы = Пн-Пт 9-18 Europe/Moscow. Раб. дни — Пн-Пт.
            'attention.new_hours' => [
                'group' => 'Attention · дедлайны',
                'label' => 'Новая заявка → действие (раб. часов)',
                'help' => 'Сколько раб. часов после попадания заявки в статус «Новая» считается нормой. Дольше — sla_breach.',
                'type' => AppSetting::TYPE_INT,
                'default' => 1,
                'step' => 1,
                'min' => 1,
            ],
            'attention.assigned_hours' => [
                'group' => 'Attention · дедлайны',
                'label' => 'Назначена → менеджер открыл (раб. часов)',
                'help' => 'Foundation §5.5: 4 раб. часа на первый отклик менеджера после назначения.',
                'type' => AppSetting::TYPE_INT,
                'default' => 4,
                'step' => 1,
                'min' => 1,
            ],
            'attention.in_progress_hours' => [
                'group' => 'Attention · дедлайны',
                'label' => 'В работе без активности (раб. часов)',
                'help' => 'Сколько раб. часов разрешено заявке висеть в InProgress без новых state-changes / переходов.',
                'type' => AppSetting::TYPE_INT,
                'default' => 24,
                'step' => 1,
                'min' => 1,
            ],
            'attention.awaiting_clarification_days' => [
                'group' => 'Attention · дедлайны',
                'label' => 'Жду клиента — первый ремайндер (раб. дней)',
                'help' => 'Через сколько раб. дней без ответа клиента в AwaitingClientClarification менеджеру нужно его пинговать.',
                'type' => AppSetting::TYPE_INT,
                'default' => 2,
                'step' => 1,
                'min' => 1,
            ],
            'attention.quoted_first_followup_days' => [
                'group' => 'Attention · дедлайны',
                'label' => 'КП отправлено — первый нудж (раб. дней)',
                'help' => 'Через сколько раб. дней после отправки КП без реакции клиента менеджер должен нагнать.',
                'type' => AppSetting::TYPE_INT,
                'default' => 3,
                'step' => 1,
                'min' => 1,
            ],
            'attention.under_review_days' => [
                'group' => 'Attention · дедлайны',
                'label' => 'На согласовании (раб. дней)',
                'help' => 'Дефолтный дедлайн в статусе UnderReview, если у клиента нет конкретной даты ответа.',
                'type' => AppSetting::TYPE_INT,
                'default' => 3,
                'step' => 1,
                'min' => 1,
            ],
            'attention.awaiting_invoice_hours' => [
                'group' => 'Attention · дедлайны',
                'label' => 'Ждём счёт (раб. часов)',
                'help' => 'Сколько раб. часов на выставление счёта от клиента/корп-базы.',
                'type' => AppSetting::TYPE_INT,
                'default' => 24,
                'step' => 1,
                'min' => 1,
            ],
            'attention.invoiced_followup_days' => [
                'group' => 'Attention · дедлайны',
                'label' => 'Счёт отправлен — нудж (раб. дней)',
                'help' => 'Через сколько дней после отправки счёта без оплаты — напомнить клиенту.',
                'type' => AppSetting::TYPE_INT,
                'default' => 5,
                'step' => 1,
                'min' => 1,
            ],

            // ─── Авто-закрытие по молчанию клиента ──────────────────────
            // Крон requests:auto-close-inactive (раз в сутки). РАБОЧИЕ дни.
            // 0 = правило выключено. Не закрывает, если клиент писал в последние
            // N раб. дней (гард активности). Закрытие — closed_lost; восстановление
            // вручную кнопкой «↻ Реанимировать».
            'auto_close.clarification_days' => [
                'group' => 'Авто-закрытие по молчанию',
                'label' => 'Молчание после уточнения → закрыть (раб. дней)',
                'help' => 'Если клиент не ответил на уточняющий вопрос дольше N рабочих дней — заявка авто-закрывается как «Клиент молчит после уточнения». Не сработает, если клиент писал в последние N раб. дней. 0 = выключить правило.',
                'type' => AppSetting::TYPE_INT,
                'default' => 4,
                'step' => 1,
                'min' => 0,
            ],
            'auto_close.quote_days' => [
                'group' => 'Авто-закрытие по молчанию',
                'label' => 'Молчание после КП → закрыть (раб. дней)',
                'help' => 'Если клиент не отреагировал на отправленное КП дольше N рабочих дней — заявка авто-закрывается как «Клиент молчит после КП». Не сработает, если клиент писал в последние N раб. дней. 0 = выключить правило.',
                'type' => AppSetting::TYPE_INT,
                'default' => 5,
                'step' => 1,
                'min' => 0,
            ],
            'auto_close.invoice_days' => [
                'group' => 'Авто-закрытие по молчанию',
                'label' => 'Счёт не оплачен → закрыть (раб. дней от выставления)',
                'help' => 'Если счёт не оплачен дольше N рабочих дней с даты выставления — заявка авто-закрывается как «Счёт не оплачен в срок». Не сработает, если клиент писал в последние N раб. дней. 0 = выключить правило.',
                'type' => AppSetting::TYPE_INT,
                'default' => 5,
                'step' => 1,
                'min' => 0,
            ],

            // ─── DocumentDetector — auto-mode (Foundation §7.3) ──────────
            // Включается РОПом ПОСЛЕ накопления ~1000 решений и проверки
            // error_rate. До этого все детектирования идут как suggestion.
            'detector.confidence_threshold' => [
                'group' => 'DocumentDetector · auto-mode',
                'label' => 'Минимальная confidence для auto-apply',
                'help' => 'Общий порог 0..1. Ниже — даже включённый auto-mode оставит решение как suggestion. Дефолт 0.85.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => 0.85,
                'step' => 0.01,
                'min' => 0.0,
                'max' => 1.0,
            ],
            'detector.auto_mode.outbound_quotation_full' => [
                'group' => 'DocumentDetector · auto-mode',
                'label' => 'Auto: КП отправлено → quoted',
                'help' => 'При обнаружении исходящего КП автоматически переводить заявку в «КП отправлено». Без подтверждения оператора. Включать после валидации.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => false,
            ],
            'detector.auto_mode.outbound_invoice' => [
                'group' => 'DocumentDetector · auto-mode',
                'label' => 'Auto: Счёт отправлен → invoiced',
                'help' => 'Автоматически переводить в «Счёт отправлен» при обнаружении outbound-счёта.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => false,
            ],
            'detector.auto_mode.outbound_clarification' => [
                'group' => 'DocumentDetector · auto-mode',
                'label' => 'Auto: Запрос уточнения → awaiting_client_clarification',
                'help' => 'Автоматически переводить в «Жду уточнения клиента» при обнаружении исходящего запроса.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => false,
            ],
            'detector.auto_mode.outbound_declined' => [
                'group' => 'DocumentDetector · auto-mode',
                'label' => 'Auto: Менеджер отказал «не наш профиль» → closed_lost (off_topic)',
                'help' => 'Опасно — закрывает заявку с reason=off_topic при коротком отказе менеджера («не наша номенклатура», «не наш профиль» и т.п.). Detector проверяет анти-followup: если рядом есть «но я попробую», «пришлите фото», «предложить аналог» — НЕ срабатывает (это clarification). Включать после проверки precision на исторических кейсах.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => false,
            ],
            'detector.auto_mode.inbound_under_review' => [
                'group' => 'DocumentDetector · auto-mode',
                'label' => 'Auto: Клиент «на согласовании» → under_review',
                'help' => 'Автоматически переводить в «На согласовании» при ответе клиента «получили, изучаем».',
                'type' => AppSetting::TYPE_BOOL,
                'default' => false,
            ],
            'detector.auto_mode.inbound_postponed' => [
                'group' => 'DocumentDetector · auto-mode',
                'label' => 'Auto: Клиент отложил → postponed_until',
                'help' => 'Автоматически переводить в «Отложено» с датой клиента (если AI извлёк её).',
                'type' => AppSetting::TYPE_BOOL,
                'default' => false,
            ],
            'detector.auto_mode.inbound_invoice_request' => [
                'group' => 'DocumentDetector · auto-mode',
                'label' => 'Auto: Клиент просит счёт → awaiting_invoice',
                'help' => 'Автоматически переводить в «Ждём счёт» при явном invoice_request.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => false,
            ],
            'detector.auto_mode.inbound_decline' => [
                'group' => 'DocumentDetector · auto-mode',
                'label' => 'Auto: Клиент отказался → closed_lost',
                'help' => 'Опасно — закрывает заявку с reason. Включать только после очень тщательной валидации precision (терминальное действие).',
                'type' => AppSetting::TYPE_BOOL,
                'default' => false,
            ],
            'detector.auto_mode.inbound_clarification_response' => [
                'group' => 'DocumentDetector · auto-mode',
                'label' => 'Auto: Клиент ответил на уточнение → in_progress',
                'help' => 'Автоматически возвращать в «В работе» после ответа клиента (статус был «Жду уточнения»).',
                'type' => AppSetting::TYPE_BOOL,
                'default' => false,
            ],
            'detector.auto_mode.inbound_extension' => [
                'group' => 'DocumentDetector · auto-mode',
                'label' => 'Auto: Клиент добавил позиции → in_progress',
                'help' => 'Автоматически возвращать заявку с КП/счётом в «В работе», если клиент в ответе запросил новые позиции (расширение сделки). По умолчанию — только подсказка менеджеру.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => false,
            ],

            // ─── IQOT · анализ цен конкурентов ───────────────────────────
            'iqot.enabled' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Включить интеграцию IQOT',
                'help' => 'Главный выключатель. Пока выключено — система не отправляет позиции в IQOT и не тратит баланс. Нужен заполненный API-ключ.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => false,
            ],
            'iqot.api_key' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'API-ключ IQOT',
                'help' => 'Ключ Public API (iqot.ru). Хранится в app_settings. Без него интеграция не работает.',
                'type' => AppSetting::TYPE_STRING,
                'default' => '',
            ],
            'iqot.daily_limit' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Дневной лимит позиций',
                'help' => 'Сколько позиций в сутки максимум отправляем на анализ в IQOT (защита баланса). 0 = не отправлять.',
                'type' => AppSetting::TYPE_INT,
                'default' => 50,
                'step' => 1,
                'min' => 0,
            ],
            'iqot.runs_per_day' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Заходов в день (окно 8–18)',
                'help' => 'Дневной лимит делится на это число = порция за один заход (чтобы не израсходовать лимит сразу, а первоочередные позиции уходили с первого утреннего захода). По умолчанию 6 — каждые 2 часа с 8 до 18. Менять вместе с расписанием крона.',
                'type' => AppSetting::TYPE_INT,
                'default' => 6,
                'step' => 1,
                'min' => 1,
                'max' => 24,
            ],
            'iqot.report_fresh_days' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Актуальность отчёта (дней)',
                'help' => 'Позиция со свежим (моложе N дней) отчётом IQOT повторно не анализируется. По умолчанию 90.',
                'type' => AppSetting::TYPE_INT,
                'default' => 90,
                'step' => 1,
                'min' => 1,
            ],
            'iqot.root_category' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Корневая категория IQOT',
                'help' => 'Передаётся в client_category каждой позиции (бизнес-домен). Напр. «Лифтовое оборудование».',
                'type' => AppSetting::TYPE_STRING,
                'default' => 'Лифтовое оборудование',
            ],
            'iqot.fx_auto_update' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Авто-обновление курсов из ЦБ РФ',
                'help' => 'Раз в день (07:30 MSK) тянуть курсы USD/EUR/CNY из ЦБ РФ в поля ниже. Выключите, чтобы «запинить» ручные курсы — тогда автозагрузка их не перезапишет.',
                'type' => AppSetting::TYPE_BOOL,
                'default' => true,
            ],
            'iqot.fx_usd' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Курс USD → ₽',
                'help' => 'Курс доллара для сравнения офферов в долларах (currency=USD). Без него «80 USD» сравнивается как «80 ₽». Обновляется автоматически из ЦБ РФ (если включён тумблер выше) — правьте вручную только при выключенном авто-обновлении.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => (float) config('services.iqot.fx_rates.USD', 90),
                'step' => 0.5,
                'min' => 0,
            ],
            'iqot.fx_eur' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Курс EUR → ₽',
                'help' => 'Курс евро для сравнения офферов в евро (currency=EUR). Обновляется автоматически из ЦБ РФ.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => (float) config('services.iqot.fx_rates.EUR', 100),
                'step' => 0.5,
                'min' => 0,
            ],
            'iqot.fx_cny' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Курс CNY (юань) → ₽',
                'help' => 'Курс юаня для сравнения офферов в юанях (currency=CNY/RMB) — актуально для китайских поставщиков. Обновляется автоматически из ЦБ РФ.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => (float) config('services.iqot.fx_rates.CNY', 12.5),
                'step' => 0.1,
                'min' => 0,
            ],
            'iqot.attention_min_rank' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Внимание: место нашей цены ≥',
                'help' => 'Позиция в списке «Готов отчёт» помечается «⚠ пересмотреть цену», если наша цена занимает это место ИЛИ ниже (по цене без НДС). По умолчанию 3.',
                'type' => AppSetting::TYPE_INT,
                'default' => (int) config('services.iqot.attention.min_rank', 3),
                'step' => 1,
                'min' => 2,
            ],
            'iqot.attention_min_deviation_pct' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Внимание: отклонение от мин. цены, % >',
                'help' => 'Второе условие пометки: наша цена дороже лучшей цены IQOT (без НДС) больше чем на этот процент. Помечаются только позиции, где выполнены ОБА условия. По умолчанию 10.',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => (float) config('services.iqot.attention.min_deviation_pct', 10),
                'step' => 1,
                'min' => 0,
            ],
            'iqot.critical_top_pct' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Критично: топ-N% самых дорогих',
                'help' => 'Строка подсвечивается красным фоном (приоритет ценообразования), если наша цена попадает в этот % самых дорогих на рынке. По умолчанию 20 (топ-20% дороговизны, напр. 5-е место из 5).',
                'type' => AppSetting::TYPE_FLOAT,
                'default' => (float) config('services.iqot.critical.top_pct', 20),
                'step' => 5,
                'min' => 1,
                'max' => 100,
            ],
            'iqot.critical_min_suppliers' => [
                'group' => 'IQOT · анализ цен',
                'label' => 'Критично: минимум поставщиков',
                'help' => 'Красный фон ставится только при выборке от этого числа поставщиков (офферов IQOT) — чтобы алерт был статистически значим. По умолчанию 4.',
                'type' => AppSetting::TYPE_INT,
                'default' => (int) config('services.iqot.critical.min_suppliers', 4),
                'step' => 1,
                'min' => 1,
            ],
        ];
    }

    public function mount(SettingsService $svc): void
    {
        foreach (self::schema() as $key => $meta) {
            // Текущее значение = app_setting() (DB-override → fallback config()).
            $current = $svc->get($key, $this->configDefault($key, $meta['default']));
            // ВАЖНО: Livewire трактует точки в wire:model="values.X.Y.Z" как
            // вложенный доступ к массиву ($values[X][Y][Z]), а у нас ключи
            // плоские с точками (catalog.name_match.threshold). Поэтому в
            // $values используем подчёркивания (form-key), а при save переводим
            // обратно через configKeyFor / schema().
            $formKey = self::formKey($key);
            $this->values[$formKey] = $meta['type'] === AppSetting::TYPE_BOOL
                ? (bool) $current
                : ($current === null ? '' : (string) $current);
        }
    }

    /**
     * Перевести dot-key («catalog.name_match.threshold») в underscored
     * form-key («catalog_name_match_threshold») и обратно. Используется
     * только в Livewire-биндингах.
     */
    public static function formKey(string $dotKey): string
    {
        return str_replace('.', '_', $dotKey);
    }

    /**
     * Параллельный config-ключ для дефолта. Большинство наших настроек
     * мапятся в `services.<...>` — берём этот префикс.
     */
    private function configKeyFor(string $key): string
    {
        // catalog.name_match.threshold → services.catalog_name_match.threshold
        // catalog.import.min_full_rows → services.catalog_import.min_full_rows
        // tax.vat_percent              → services.tax.vat_percent
        $configMap = [
            'catalog.name_match.enabled' => 'services.catalog_name_match.enabled',
            'catalog.name_match.threshold' => 'services.catalog_name_match.threshold',
            'catalog.name_match.hc_threshold' => 'services.catalog_name_match.hc_threshold',
            'catalog.name_match.llm_validation_enabled' => 'services.catalog_name_match.llm_validation_enabled',
            'catalog.name_match.llm_fail_action' => 'services.catalog_name_match.llm_fail_action',
            'catalog.import.min_full_rows' => 'services.catalog_import.min_full_rows',
            'tax.vat_percent' => 'services.tax.vat_percent',
            'assignment.newbie_boost' => 'services.assignment.newbie_boost',
            'dealer.auto_threshold' => 'services.dealer.auto_threshold',
            'dealer.auto_window_days' => 'services.dealer.auto_window_days',
            'iqot.enabled' => 'services.iqot.enabled',
            'iqot.api_key' => 'services.iqot.api_key',
            'iqot.daily_limit' => 'services.iqot.daily_limit',
            'iqot.runs_per_day' => 'services.iqot.runs_per_day',
            'iqot.report_fresh_days' => 'services.iqot.report_fresh_days',
            'iqot.root_category' => 'services.iqot.root_category',
            'iqot.fx_usd' => 'services.iqot.fx_rates.USD',
            'iqot.fx_eur' => 'services.iqot.fx_rates.EUR',
            'iqot.fx_cny' => 'services.iqot.fx_rates.CNY',
            'iqot.attention_min_rank' => 'services.iqot.attention.min_rank',
            'iqot.attention_min_deviation_pct' => 'services.iqot.attention.min_deviation_pct',
            'iqot.critical_top_pct' => 'services.iqot.critical.top_pct',
            'iqot.critical_min_suppliers' => 'services.iqot.critical.min_suppliers',
        ];

        return $configMap[$key] ?? '';
    }

    /**
     * Безопасный config-default: если configKeyFor вернул пустую строку
     * (для настройки нет параллели в config/) — отдаём schema-default,
     * не дёргая config(''), который вернул бы весь репозиторий.
     */
    private function configDefault(string $key, mixed $schemaDefault): mixed
    {
        $configKey = $this->configKeyFor($key);
        if ($configKey === '') {
            return $schemaDefault;
        }

        return config($configKey, $schemaDefault);
    }

    /**
     * @return array<string, array<string, array>>  group → [key → meta]
     */
    #[Computed]
    public function grouped(): array
    {
        $out = [];
        foreach (self::schema() as $key => $meta) {
            $out[$meta['group']][$key] = $meta;
        }

        return $out;
    }

    public function save(SettingsService $svc): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasAnyRole(['head_of_sales', 'director', 'admin'])) {
            abort(403);
        }

        $schema = self::schema();
        foreach ($schema as $key => $meta) {
            $formKey = self::formKey($key);
            if (! array_key_exists($formKey, $this->values)) {
                continue;
            }
            $rawValue = $this->values[$formKey];
            $typed = $this->coerceForType($rawValue, $meta['type']);

            $configDefault = $this->configDefault($key, $meta['default']);
            $defaultTyped = $this->coerceForType($configDefault, $meta['type']);

            // Если значение совпадает с config-defaults — удаляем override
            // (чтобы не плодить мусор и легко вернуться к defaults).
            if ($this->valuesEqual($typed, $defaultTyped, $meta['type'])) {
                $svc->unset($key);
                continue;
            }

            $svc->set($key, $typed, $meta['type'], $user->id, $meta['label']);
        }

        $this->dispatch('settings-saved');
        session()->flash('settings-flash', 'Настройки сохранены.');
    }

    private function coerceForType(mixed $raw, string $type): mixed
    {
        if ($type === AppSetting::TYPE_BOOL) {
            return (bool) $raw;
        }
        if ($type === AppSetting::TYPE_INT) {
            return (int) $raw;
        }
        if ($type === AppSetting::TYPE_FLOAT) {
            return (float) $raw;
        }

        return (string) $raw;
    }

    private function valuesEqual(mixed $a, mixed $b, string $type): bool
    {
        if ($type === AppSetting::TYPE_FLOAT) {
            return abs(((float) $a) - ((float) $b)) < 1e-9;
        }

        return $a === $b;
    }

    public function render()
    {
        return view('livewire.admin.settings.index');
    }
}
