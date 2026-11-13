# Макет «Мой план» (ассистент менеджера)

Прототип ленты шагов менеджера на реальных данных — **только чтение**: в базу ничего не пишется,
кнопки на странице показывают поведение. Решения и история — в MEMORY.md, раздел «Сессия 2026-11-13».

Данные (`*.json`) и собранная страница (`*.html`) содержат письма и контакты клиентов —
**в git не попадают** (.gitignore), репозиторий публичный.

## Как собрать

1. Выгрузка открытых заявок менеджера (по умолчанию user_id 3 — Сергей Агрызков) и шаги с текстами ИИ — на проде,
   под www-data, файлы в `/tmp`:

   ```bash
   scp tools/plan-mockup/export.php tools/plan-mockup/steps.php mzcorp:/tmp/
   ssh mzcorp 'chown www-data /tmp/export.php /tmp/steps.php; cd /var/www/mzcorp && sudo -u www-data php artisan tinker --execute="require \"/tmp/export.php\";" && sudo -u www-data php artisan tinker --execute="require \"/tmp/steps.php\";"'
   scp mzcorp:/tmp/plan-steps.json tools/plan-mockup/
   ssh mzcorp 'rm -f /tmp/export.php /tmp/steps.php /tmp/plan-sergey.json /tmp/plan-steps.json'
   ```

   `steps.php` вызывает gpt-4o-mini по каждому шагу (~80 вызовов, несколько минут).

2. Страница: `php tools/plan-mockup/build.php` → `tools/plan-mockup/plan-mockup-v2.html`
   (оформление — `design/colors_and_type.css` и компоненты `design/ui_kits/crm`).

## Устройство

- `export.php` — заявки менеджера: статус, отметка внимания (AttentionService), КП/счёт, последние письма
  (ручное письмо менеджера отдельно от автописем `client_notifications_sent`), платёжная дисциплина клиента
  (доля оплаченных счетов, медиана дней до оплаты).
- `steps.php` — правила: заявка → тип шага и приоритет; **глагол шага задают правила**, ИИ пишет только
  предмет (2–6 слов) и комментарий; для писем клиента глагол — из списка (Ответить / Выставить счёт /
  Исправить счёт / Подготовить отгрузку / Прочитать и снять отметку). Уже отвеченные отметки — в «stale».
- `build.php` — одна последовательность шагов: текущий шаг крупно («Готово — следующий шаг», «Открыть заявку»,
  «Отложить», «Неверно»), ниже очередь «Дальше», внизу «Похоже, уже сделано».
