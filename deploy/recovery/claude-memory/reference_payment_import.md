---
name: payment-import
description: "Импорт оплат из 1С (xlsx) в разделе «Счета»: отметка оплат/частичных, журнал внешних оплат /dashboard/invoices/external, автопривязка"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Фича «Импорт оплат из 1С» (2026-07-04, commits d015a4b + a8c7f1e), на проде.

- **Вход**: кнопка «⬇ Оплаты из 1С» в /dashboard/invoices (РОП/директор/секретарь/админ). Двухшаговый flow: upload xlsx → превью-классификация (кэш `payment-import:{uuid}`, 2ч) → «Применить». Компонент `Invoices\Index` (import* методы), сервис `App\Services\Invoices\PaymentImportService`.
- **Парсер**: PhpSpreadsheet, колонки по заголовкам (оба формата 1С — месячный «Продажи…» и ежедневный «Оплаты МЛ…»). Номер «НФ00-005513» → int 5513 (CRM invoice_number — голые цифры). **Грабля: 1С отдаёт суммы ТЕКСТОМ с NBSP** («22 787,46») — toNumber режет все пробелоподобные (U+00A0/202F) + запятая→точка.
- **Классификация**: mark_paid (найден, Оп%=100) / mark_partial (Оп%<100) / already_paid / unknown (нет в CRM, счёт от ≥2026-05-25 = SYSTEM_LAUNCH_DATE) / skipped_old / already_recorded (дедуп unknown по номер+дата+сумма). Расхождение сумм >1₽ — предупреждение в комментарий аудита, не блокирует.
- **Частичная оплата**: `InvoiceStatus::PartiallyPaid` ('partially_paid', chip-sky), колонки invoices.partially_paid_at + paid_amount (paid_at НЕ ставится — отчёты по paid_at не считают частичные полными). Заявка закрывается **ClosedWon** (решение заказчика). Доплата → markPaid (PartiallyPaid входит в PAYABLE_STATUSES).
- **markPaid расширен**: paidAt (дата из файла!), paidAmount, note, systemTransition (секретарь не имеет прав на переходы чужих заявок — импорт идёт мимо permission-гейта). `transitionAfterPayment`: заявка в closed_lost (напр. invoice_unpaid) → `reanimate` (event reanimated_by_payment) → Paid/ClosedWon.
- **Журнал**: payment_imports (шапка+счётчики) + imported_payments (строки, включая затраты/прибыль из 1С — задел под аналитику маржи). Внешние оплаты (outcome=unknown) → страница **/dashboard/invoices/external** (`Invoices\ExternalPayments`): привязать по коду заявки (создаёт счёт+оплату, `linkExternalToRequest`), игнорировать, вернуть.
- **Автопривязка**: хук `InvoiceService::applyExternalPaymentIfAny` после issue()/autoIssueFromOutboundQuote() — появившийся в CRM счёт с номером из unknown-журнала оплачивается автоматически (outcome→linked).
- Проверено на реальных файлах: июньская выгрузка 829 строк → 658 already / 22 mark_paid / 1 partial / 72 unknown / 76 skipped_old; суммы сошлись с ручной сверкой.
- **Автопривязка через исходящие (0cb4484)**: unknown-оплата + распознанный outbound_quote с тем же document_number → `tryLinkViaOutboundQuote` создаёт оплаченный счёт (в apply() и разово для бэклога: 14/72 привязано). Строка итогов: «по 1С поступило» (sum paid_amount) + красное «⚠ дубли номеров» (один номер счёта на 2 заявках задваивает сумму периода; 16 дублей за июнь).
- **Мост статусов при оплате (d3f8f3f, 2026-07-09)**: стейт-машина допускает Paid ТОЛЬКО из Invoiced, а оплата приходит на заявку в любом статусе (внешний счёт из 1С задним числом; реанимация из closed_lost даёт in_progress) → transitionAfterPayment падал non-fatal «Запрещённый переход: in_progress → paid», заявка оставалась открытой с оплаченным счётом (кейс M-2026-1530 + 24 шт.). Фикс: skip если уже closed_won; иначе промежуточный хоп в Invoiced (event payment_status_bridge). Переход в Paid дальше сам автозакрывает заявку в ClosedWon. Разовое выравнивание 25 застрявших 09.07 (event payment_status_remediation), все → closed_won. Симптом в логе: «InvoiceService: post-payment transition failed».
- **Защита от дублей номеров (5da55a6)**: «первая привязка выигрывает» — `InvoiceService::findDuplicateOnOtherRequest` (по числовой части, cancelled не считается); autoIssue молча скипает с audit-row `invoice_duplicate_skipped` в активности заявки, ручной issue()/linkExternalToRequest кидают DomainException с кодом заявки-оригинала. Разовая чистка 2026-07-05: 40 групп, 47 копий → cancelled с причиной «Дубль счёта — оригинал в M-…» (paid-поля очищены, paid_amount перенесён на оригинал ×5, бэкап /tmp/dedup_backup_*.json на проде). Статусы заявок-копий не трогались. Метрика «⚠ дубли» в итогах не считает cancelled.
