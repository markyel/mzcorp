---
name: vision-image-downscale
description: "Даунскейл фото-вложений перед OpenAI Vision (гард 413 Request Entity Too Large); только фото, PDF-страницы не трогаем"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

**Грабля 413 на Vision.** Полноразмерные фото с телефона (6–7 МБ JPEG) уходили в OpenAI Vision сырым base64 → несколько таких пробивали лимит размера запроса → `413 Request Entity Too Large`, парсинг падал (`parseItemsFromInboundContent: vision parse failed`). Канон-кейс **msg#26670 / M-2026-5320** («отель Метрополь, запчасти»): 4 фото `IMG_*.jpg` 3968×2976 ≈ 24 МБ сырья → ~33 МБ base64 → 413 и в парсере позиций, и в `PhotoSlotClassifierService` (там даже 12 картинок). До фикса даунскейла **не было вообще** (проверял: ни GD/Imagick-вызовов, ни image-либы в composer; комментарий `detail:high` стоял РАДИ читаемости артикулов, т.е. ПРОТИВ даунскейла).

**Фикс (commit 7a1a38a, 2026-06-22):** `App\Services\AI\VisionImageDownscaler::dataUri(bytes, mime, ?attId)` (static, без зависимостей). Если фото > **1.5 МБ** → GD ужимает длинную сторону до **2048px** + ре-энкод **JPEG q82**. Ключевой довод: при `detail:high` OpenAI и так режет длинную сторону до ~2048 на своей стороне, поэтому локальный ресайз до 2048 **не теряет качества для Vision**, но рубит байты ×~7–20. Прозрачность PNG → белый фон (imagecreatetruecolor+imagecopyresampled). **Fail-soft:** нет GD / битый кадр / нет выигрыша в байтах → отдаём оригинал. **OOM-гард:** >60 МП (по getimagesizefromstring до декода) пропускаем (прод `memory_limit=-1`, но защита на будущее).

**ТОЛЬКО фото (вложения-изображения), 3 точки:** `RequestItemParsingService::loadImageDataUris`, `Kb\PhotoSlotClassifierService::encodeImages`, `Quotes\OutboundQuoteParsingService::extractFromImage`. **Документы (PDF→PNG `pdftoppm 150 dpi`) НЕ трогаем** — по решению заказчика (`extractFromPdf`/`pdfToImages` оставлены как есть). См. [[item-parsing-pipeline]].

**Прод-верификация на реальных вложениях msg#26670:** 4 фото → 2048×1536/1536×2048, 526–764 КБ, валидные JPEG; мелкие inline-подписи нетронуты; суммарный base64 **24.2 МБ → 3.3 МБ**. GD на проде есть (JPEG+PNG), Imagick нет. Деплой: pull + queue:restart + fpm reload (PHP-only; без view/route/npm).
