---
name: telegram-blocked-route-via-mzhk
description: "С сервера Reg.ru (mzcorp) Telegram недоступен в обе стороны; исходящие идут SOCKS-туннелем tg-tunnel, вебхук — через quoteparts.net на mzhk"
metadata:
  node_type: memory
  type: project
  originSessionId: c87a27b2-8e4a-405b-a74b-e84fde0264d7
  modified: 2026-11-06T09:45:03.583Z
---

С 06.11.2026 api.telegram.org не открывается с сервера mzcorp (<IP>), и Telegram не может постучаться к mzcorp.ru (вебхук «Connection timed out»). MAX работает напрямую.

- Исходящие: systemd `tg-tunnel` на mzcorp → `ssh -D 127.0.0.1:1080 tgtunnel@mzhk` (ключ /root/.ssh/tg_tunnel; на mzhk ключ ограничен `permitopen="api.telegram.org:443"`). В `.env` `TELEGRAM_PROXY=socks5h://127.0.0.1:1080`, код — `App\Support\TelegramHttp::options()` (боты и медиаплан).
- Входящие: вебхук `TELEGRAM_WEBHOOK_URL=https://quoteparts.net/relay/mzcorp/bot/telegram` — quoteparts.net смотрит на mzhk (SNI passthrough на :8443 core), в nginx-сайте quoteparts-net.conf на core `location = /relay/mzcorp/bot/telegram` → https://127.0.0.1/api/bot/telegram, Host mzcorp.ru.
- Сообщения ботов — своя очередь `bot` + отдельный `mzcorp-bot-worker` (в default клиент ждал до минуты).

**Why:** без этого бот молчит; при падении mzhk замолчит Telegram-бот и пропадёт quoteparts.net.
**How to apply:** «бот Telegram молчит» — сначала `systemctl status tg-tunnel` и getWebhookInfo через прокси; «Подключить» в настройках ставит relay-адрес и сбрасывает ждущие апдейты (drop_pending_updates), переставлять вебхук лучше вручную без сброса. Связано: [[beget-db-loss-recovery]].
