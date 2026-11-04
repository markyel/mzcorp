---
name: misc-durable
description: "Мелкие устойчивые факты mzcorp без своей темы — оплата аннулированных счетов, принудительная заявка из письма, период дашборда, мульти-фото позиций, поиск каталога, AI-блок дашборда"
metadata:
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-10-26T10:53:52.405Z
---

Перенесено из индекса MEMORY.md (26.10.2026), чтобы он оставался коротким.

- Оплата аннулированных счетов: PAYABLE_STATUSES включает Cancelled.
- Принудительное создание заявки из письма — EmailToRequestPromoter (кнопка в /dashboard/mail, privileged).
- Персональный дефолт периода дашборда — users.dashboard_period_days.
- Мульти-фото позиции — pivot request_item_photos (is_main/sort_order), image_attachment_id = главное фото; в Detail::render нужен loadCount('photos') (Livewire теряет withCount при гидратации).
- Поиск каталога включает name_en (trigram GIN + token-coverage tiebreaker); «Привязать к каталогу» → «Показать ещё» (base до 50 без повторного embed).
- Дашборд, AI-блок: считает ПИСЬМА (email_messages inbound за periodRange), не заявки; «Создано заявок» = Request::created тем же окном = funnel received; client_request → opened_new (новая) + added_existing (дополнили, есть новые позиции); thread_reply = ответ без новых позиций; emailProcessing() в Dashboard\Index; воронка «Закрыто» won/lost на шкале + всего; виджет ящиков только is_active.
