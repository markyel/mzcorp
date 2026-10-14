{{-- Тело письма: sandbox-iframe с оформлением письма или текст с
     сворачиваемой цитатой. Общий для вкладки «Переписка» и блока «Переписка»
     на «Обзоре» — письмо выглядит одинаково (заказчик, 14.10.2026).
     Параметры: $msg, $html (bodyHtmlFor), $splitQuote (splitPlainQuote). --}}
                                        @if($html)
                                            {{-- Письмо рендерится в sandbox-iframe (srcdoc), чтобы <style>-блоки
                                                 из тела письма не утекали на страницу и не ломали .btn / шрифты CRM.
                                                 sandbox без allow-scripts — JS из письма не выполнится.

                                                 wire:ignore.self ОБЯЗАТЕЛЕН: высоту ставит fit() в inline style,
                                                 а любой Livewire-морф Detail (открытие окна ответа, смена
                                                 фильтра…) без него сбрасывал style обратно в height:0 из
                                                 серверной разметки; load повторно не стреляет → письма
                                                 «схлопывались» до заголовков (на маке ResizeObserver не
                                                 спасал — баг-репорт 2026-07-09). Контент письма статичен,
                                                 элемент стабилен по wire:key — игнорировать морф безопасно. --}}
                                            <iframe
                                                wire:ignore.self
                                                sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                                srcdoc="{{ $html }}"
                                                loading="lazy"
                                                class="w-full block border-0 bg-surface"
                                                style="height: 0"
                                                x-data
                                                x-init="
                                                    const fit = () => {
                                                        try {
                                                            const doc = $el.contentDocument;
                                                            if (!doc || !doc.documentElement) return;
                                                            {{-- Сброс высоты перед замером: scrollHeight не бывает меньше
                                                                 viewport (= текущей высоты iframe), поэтому после сворачивания
                                                                 details высота «залипала» на раскрытом значении. Сброс+замер
                                                                 синхронные — браузер не успевает отрисовать промежуточное
                                                                 состояние, мигания нет. --}}
                                                            $el.style.height = '8px';
                                                            const h = doc.documentElement.scrollHeight;
                                                            $el.style.height = (h + 4) + 'px';
                                                        } catch (e) {}
                                                    };
                                                    $el.addEventListener('load', () => {
                                                        try {
                                                            const doc = $el.contentDocument;
                                                            if (!doc) return;
                                                            doc.querySelectorAll('a[href]').forEach(a => {
                                                                a.target = '_blank';
                                                                a.rel = 'noopener noreferrer';
                                                            });
                                                            const s = doc.createElement('style');
                                                            s.textContent = 'html,body{margin:0;padding:0}body{padding:8px 12px;font:13px/1.55 system-ui,-apple-system,Segoe UI,Inter,sans-serif;color:#0a0a0a;word-break:break-word}img{max-width:100%;height:auto}img[data-cid-missing]{width:14px!important;height:14px!important;opacity:.35}';
                                                            (doc.head || doc.documentElement).appendChild(s);
                                                            try { new ResizeObserver(fit).observe(doc.documentElement); } catch (e) {}
                                                            {{-- toggle не всплывает, ловим на capture: сворачивание цитаты
                                                                 меняет высоту мгновенно — не ждём ResizeObserver. --}}
                                                            doc.addEventListener('toggle', fit, true);
                                                            fit();
                                                        } catch (e) {}
                                                    });
                                                "
                                            ></iframe>
                                        @elseif($msg->body_plain)
                                            @php [$plainMain, $plainQuoted] = $splitQuote($msg->body_plain); @endphp
                                            <pre class="whitespace-pre-wrap font-sans text-[13px]">{{ $plainMain }}</pre>
                                            @if($plainQuoted !== null)
                                                <details class="mt-1.5">
                                                    <summary class="cursor-pointer select-none text-[12px] text-fg-4 hover:text-fg-2">· · · показать цитату</summary>
                                                    <pre class="whitespace-pre-wrap font-sans text-[13px] text-fg-3 mt-1">{{ $plainQuoted }}</pre>
                                                </details>
                                            @endif
                                        @else
                                            <div class="text-fg-3">(пустое тело)</div>
                                        @endif
