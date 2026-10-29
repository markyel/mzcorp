{{-- Меню шаблонов писем в панели богатого редактора (слот tools у x-mail.rich-editor): вставка — insertTemplateById,
     «Сохранить как шаблон» — saveAsTemplate (App\Livewire\Concerns\InsertsLetterTemplates). Та же личная библиотека,
     что во вкладке «Переписка» карточки заявки; шаблон хранится текстом и вставляется абзацами в конец письма. --}}
<span class="rte-pop" x-data="{ tplOpen: false, tplSaving: false, tplName: '', tplParent: '' }"
      @click.outside="tplOpen = false; tplSaving = false">
    <button type="button" class="dd" @click="tplOpen = ! tplOpen; tplSaving = false" title="Шаблоны писем">
        <span>📄 Шаблоны</span><x-rte-icon name="chevron" class="rte-ico sm"/>
    </button>
    <div class="rte-menu tplmenu" x-show="tplOpen" x-cloak>
        <div x-show="! tplSaving">
            @php $tplTree = $this->templateTree; @endphp
            @if($tplTree->isEmpty())
                <div class="tplhint">Библиотека пуста — сохраните это письмо как шаблон.</div>
            @else
                <div class="tpltree">
                    @foreach($tplTree as $node)
                        @include('livewire.mail._composer-template-node', ['node' => $node, 'depth' => 0])
                    @endforeach
                </div>
            @endif
            <div class="tplsep"></div>
            <button type="button" @click="tplSaving = true">☆ Сохранить как шаблон</button>
            <a href="{{ route('letter-templates.index') }}" target="_blank" rel="noopener" class="tpllink">⚙ Управление шаблонами →</a>
        </div>
        <div x-show="tplSaving" x-cloak class="tplsave">
            <input type="text" x-model="tplName" placeholder="Название шаблона" @keydown.stop>
            <select x-model="tplParent">
                <option value="">— в корень —</option>
                @foreach($this->templateFolders as $folder)
                    <option value="{{ $folder->id }}">{{ $folder->name }}</option>
                @endforeach
            </select>
            <div class="tplbtns">
                <button type="button" class="prim"
                        @click="window.dispatchEvent(new CustomEvent('mail-editor-flush'));
                                $wire.saveAsTemplate(tplName, tplParent === '' ? null : parseInt(tplParent));
                                tplOpen = false; tplSaving = false; tplName = ''; tplParent = '';">Сохранить</button>
                <button type="button" @click="tplSaving = false">Отмена</button>
            </div>
        </div>
    </div>
</span>
