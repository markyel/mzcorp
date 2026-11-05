---
name: floating-composer
description: "Плавающее окно ответа в карточке заявки: ComposeForm вне @switch табов, drag/resize через Alpine-геометрию (:style), НЕ нативный CSS resize (Livewire morph затирает)"
metadata: 
  node_type: memory
  type: reference
  originSessionId: <скрыто>
---

Композер переписки (Requests\Mail\ComposeForm) с 2026-07-09 (commit 85ed5e5) — плавающее окно, а не inline-форма внизу таба «Переписка».

- **Регистрация**: `<livewire:requests.mail.compose-form>` в detail.blade ВНЕ `@switch($tab)` (внизу, перед лайтбоксом, под `@if($canReply)`) — переживает переключение вкладок; менеджер пишет письмо и параллельно смотрит «Позиции».
- Все точки входа больше НЕ переключают таб: Detail::composeReply / openClarificationDraft / openQuotationDraft просто диспатчат open-reply/open-compose/open-draft — окно ловит их с любой вкладки.
- **UI**: fixed bottom-right (z-index 60), drag за титульную строку, resize за уголок ◢, сворачивание в строку (▁/▢), ✕ = close (черновик сохраняется, бейдж в треде). Textarea тянется на высоту окна (flex).
- **Грабля :style строкой (ГЛАВНАЯ)**: Alpine `:style` со СТРОКОЙ заменяет весь атрибут style — статичные position:fixed/z-index/фон стирались, окно падало в поток «в подвал», drag «не работал» (left/top инертны на static). Фикс a3aa95d: styleStr() возвращает ОБЪЕКТ (мержится по-свойственно), неиспользуемые якоря сбрасываются в 'auto'. Диагностика: computed position=static + куцый style-атрибут; воспроизводится изолированной страницей с vendor livewire.js (Alpine.initTree вручную).
- **Грабля position:fixed**: у предков в layout есть CSS transform → fixed позиционировался относительно контейнера, окно «уезжало в подвал» страницы. Фикс (commit 2a4bc66): окно обёрнуто в `<template x-teleport="body">` — честный оверлей. Любой новый fixed-оверлей в detail-странице — сразу через x-teleport.
- **Грабля morph vs геометрия**: размеры/позиция (x/y/w/h) — в Alpine x-data и применяются через `:style="styleStr()"`. Нативный CSS `resize: both` НЕЛЬЗЯ: браузер пишет width/height в inline style, а Livewire morph затирает его при каждом autosave (debounce 1500ms). Alpine-состояние morph переживает, `:style` переприменяется после морфа.
- Все новые стили — inline style-атрибуты (прод не пересобирает Tailwind, см. [[tailwind-arbitrary-classes]]).
- **Превью цитаты (commit 6577914)**: блок «цитата исходного письма» рендерит РЕАЛЬНЫЙ HTML цитаты (MailQuoteBuilder — тот же, что при send) в sandbox-iframe fixed-height 240px, НЕ plain: body_plain клиентских писем бывает склеен без переносов, и превью выглядело не как во вкладке «Переписка».
- **Схлопывание треда на маке (commit 171a806)**: iframe писем в треде (height:0 + fit() на load) при ЛЮБОМ морфе Detail (открытие композера) получал сброс style в height:0 из серверной разметки; load не перестреливает → письма схлопывались до заголовков (Mac Safari/Chrome; на Windows спасал ResizeObserver). Фикс: `wire:ignore.self` на iframe (контент статичен) + `wire:key="thread-msg-{id}"` на контейнер письма. Паттерн: JS-управляемые inline-стили внутри Livewire — всегда wire:ignore.self или Alpine :style.
- **Живой бейдж черновика (commits f9894f6/b27b40e/b036885)**: Detail::$thread — mount-снапшот, сам не видит новые/удалённые черновики. Композер диспатчит composer-draft-closed/-discarded → Detail::showThreadDraft/hideThreadDraft правят коллекцию (push/reject). «Удалить черновик» и из треда (discardThreadDraft: автор или admin/РОП/директор), с чисткой коллекции. Грабля деплоя: список #[On]-слушателей встраивается в снапшот при ЗАГРУЗКЕ страницы — вкладки, открытые до деплоя, новые ивенты не ловят («не работает» до F5).
