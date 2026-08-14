{{-- ────────── PDF-ПРЕДПРОСМОТР ──────────
     Модалка предпросмотра PDF во вкладке (как лайтбокс для картинок).
     Открывается событием window:open-pdf с detail: {src, name, dl}.
       - src — inline preview URL (route attachments.preview, отдаёт application/pdf inline);
       - dl  — download URL (кнопка «Скачать»).
     Рендер — <iframe> штатным вьюером браузера. src монтируется только пока
     открыто (не тянем PDF в фоне и перечитываем свежим при каждом открытии).
     Закрытие: Esc, клик по бэкдропу, «Закрыть». --}}
<div x-data="{
        pdfOpen: false,
        pdfSrc: '',
        pdfName: '',
        pdfDl: '',
        openPdf(detail) {
            if (! detail || ! detail.src) return;
            this.pdfSrc = detail.src;
            this.pdfName = detail.name || '';
            this.pdfDl = detail.dl || '';
            this.pdfOpen = true;
        },
     }"
     x-on:open-pdf.window="openPdf($event.detail)"
     x-on:keydown.escape.window="pdfOpen = false">
    <div x-show="pdfOpen"
         x-transition.opacity.duration.150ms
         style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.82);"
         x-on:click.self="pdfOpen = false">
        <div style="position: absolute; top: 12px; left: 16px; right: 16px; display: flex; align-items: center; gap: 8px; z-index: 2;">
            <span style="color: rgba(255,255,255,0.92); font-size: 12px; font-family: var(--font-mono); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="pdfName"></span>
            <a :href="pdfDl" download class="btn btn-sm" x-on:click.stop>Скачать</a>
            <button type="button" class="btn btn-sm" x-on:click.stop="pdfOpen = false">Закрыть</button>
        </div>
        <iframe :src="pdfOpen ? pdfSrc : ''"
                title="PDF preview"
                style="position: absolute; top: 52px; left: 16px; right: 16px; bottom: 16px; width: calc(100vw - 32px); height: calc(100vh - 68px); border: none; background: white; border-radius: 4px; box-shadow: 0 8px 32px rgba(0,0,0,0.5);"
                x-on:click.stop></iframe>
    </div>
</div>
