---
name: openai-proxy
description: "MyLift OpenAI идёт через прокси ai3.lazylift.ru:8443 (порт!) + X-Proxy-Key; предыдущие ai2.lazylift.ru, Cloudflare Worker, ai.lazylift.ru — история"
metadata:
  node_type: memory
  type: reference
  originSessionId: <скрыто>
  modified: 2026-08-03T10:15:33.263Z
---

Все вызовы OpenAI из MyLift идут через **прокси** (Россия не может напрямую в api.openai.com). Конфиг — `config('services.openai')`: `base_url` (env `OPENAI_BASE_URL`, БЕЗ `/v1`) + `proxy_key` (env `OPENAI_PROXY_KEY`) + `api_key` (env `OPENAI_API_KEY`, наш ключ). `OpenAIChatService`/`OpenAIEmbeddingService` шлют `Authorization: Bearer {api_key}` + `X-Proxy-Key: {proxy_key}`, путь строят сами (`rtrim(base_url,'/').'/v1/chat/completions'` / `/v1/embeddings`).

**Миграция 2026-07-24 (санкции убили старый прокси):** старый `https://ai.lazylift.ru` (VPS Beget Рига) отключён → новый **Cloudflare Worker `https://<аккаунт>`** (тупой форвардер к api.openai.com, gated по `X-Proxy-Key`, пересылает любой путь). **proxy-key РОТИРОВАН** (старый `8cdb46…` → 403). Мигрировали правкой prod `.env` (base_url + proxy_key), КОД не меняли; бэкап `.env.bak.aiproxy`. Проверка: curl `/v1/models` (200) + `OpenAIChatService::chat` ping + embed dim=1536.

**Миграция 2026-07-31 (Cloudflare Worker гео-заблокирован):** с 30.07 Worker `…workers.dev` начал выходить к OpenAI из РФ-колокации Cloudflare → OpenAI `403 unsupported_country_region_territory` на ВСЕХ вызовах (chat+embed; 2905 сбоев 30.07, 4013 за 31.07; до 30.07 — ноль). Переключили на **`https://ai2.lazylift.ru`** — nginx reverse-proxy на VPS `<IP>` (Алматы, KZ — OpenAI поддерживает egress), DNS A-record **DNS-only без Cloudflare** (иначе снова region-block). Эндпоинты: `/v1/chat/completions`, `/v1/embeddings`, `/health` (без ключей). proxy_key ТОТ ЖЕ (`6352419c…`, не ротировался). Лимит **60 req/мин на IP** (burst chat 10 / embed 20), тело ≤20 МБ. Переключение: правка prod `.env` `OPENAI_BASE_URL` (без /v1) + config:cache + queue:restart + fpm reload; бэкап `.env.bak.<ts>`. Дока клиента: `C:\Work\LazyLift\docs\ai-proxy\CLIENT-SETUP.md`. Проверено chat «Да.» + embed 1536.

**Переезд ai2→ai3:8443 (2026-08-03):** прокси сменился на **`https://ai3.lazylift.ru:8443`** (ВНИМАНИЕ: нестандартный порт `:8443` в base_url). Меняется ТОЛЬКО `OPENAI_BASE_URL` (без `/v1`) → итог `https://ai3.lazylift.ru:8443/v1/chat/completions` + `/v1/embeddings` + `/health`. proxy_key ТОТ ЖЕ (`6352419c…`, X-Proxy-Key), api_key свой. Переключение: правка prod `.env` + config:cache + queue:restart + fpm reload; бэкап `.env.bak.<ts>`. Проверено chat «Да.» + embed 1536.

**Если AI встал (403/сбои):** сначала `curl -m10 https://ai3.lazylift.ru:8443/health` (ok?) и живой `OpenAIChatService::chat` ping; (1) proxy_key снова ротировали → взять новый из Cloudflare Worker → Settings → Variables and Secrets → `PROXY_KEY` (он же в prod `.env` LazyLift); (2) Worker/лимит Cloudflare (free 100k req/сутки); (3) баланс OpenAI (429 `rate_limit_exceeded` бывает и при исчерпании баланса — не только rate limit; OpenaiCircuitBreaker). Диагностика пайплайна почта→заявки при «заявки не создаются»: [[archived-seed-accounts]] нет; смотреть 429 в логах mail:categorize.
