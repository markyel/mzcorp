---
name: staff-access-link
description: "Доступ сотрудникам в MyLift — users:send-access (ссылка «Задать пароль», 24 ч); «Забыли пароль?» шлётся через общий ящик (на проде MAIL_MAILER=log)"
metadata:
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-11-08T14:15:22.765Z
---

`php artisan users:send-access <email…> [--dry-run]` (коммит 9cd1fd3a, 08.11.2026): письмо `AccessLinkMail` со ссылкой сброса пароля через `SystemNotificationMailer` (общий ящик, SMTP Яндекса); пароли не генерируются. Срок ссылки — `AUTH_PASSWORD_RESET_EXPIRE` (по умолчанию 1440 мин). `User::sendPasswordResetNotification` тоже идёт этим путём: до 08.11 «Забыли пароль?» на проде молча писал в лог (MAIL_MAILER=log, from <адрес>) — любое письмо через `Mail::` на проде не уходит.

08.11 разосланы: 4 менеджера (кроме Агрызкова и уволенного Ежова), РОП Курзаев, снабжение Милючихин и Петрова; директорам не слали.

Связано: [[ops-mode-switch]].
