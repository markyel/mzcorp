{{--
    Окно адресной книги почты — одно на страницу (layouts/app). Открывается
    кнопкой у поля адресатов (window-событие address-book-open) и дописывает
    выбранных в поля «Кому / Копия / Скрытая» той формы письма, откуда открыто.
    Логика — resources/js/address-book.js (Alpine addressBook).
--}}
<div x-data="addressBook" x-show="open" x-cloak class="ab-overlay"
     x-on:address-book-open.window="show($event.detail)"
     x-on:keydown.escape.window="open && close()"
     x-on:click.self="close()">
    <div class="ab-modal" role="dialog" aria-modal="true" aria-label="Адресная книга">
        <div class="ab-head">
            <span class="ab-title">Адресная книга</span>
            <button type="button" class="ab-x" x-on:click="close()" title="Закрыть">×</button>
        </div>

        <div class="ab-search">
            <input type="search" x-ref="search" x-model="q" x-on:input.debounce.250ms="load()"
                   placeholder="Имя, адрес или организация">
        </div>

        <div class="ab-tabs">
            <template x-for="t in tabs" :key="t.key">
                <button type="button" class="ab-tab" :class="{ 'is-active': tab === t.key }"
                        x-on:click="setTab(t.key)" x-text="t.label"></button>
            </template>
        </div>

        <form class="ab-form" x-show="tab === 'mine'" x-on:submit.prevent="saveContact(null)">
            <input type="email" x-model="form.email" placeholder="email" required>
            <input type="text" x-model="form.name" placeholder="Имя">
            <input type="text" x-model="form.organization" placeholder="Организация">
            <button type="submit">Добавить</button>
            <div class="ab-form-err" x-show="formError" x-text="formError"></div>
        </form>

        <div class="ab-list">
            <div class="ab-empty" x-show="loading">Загрузка…</div>
            <div class="ab-empty" x-show="!loading && error" x-text="error"></div>
            <div class="ab-empty" x-show="!loading && !error && items.length === 0"
                 x-text="tab === 'mine' ? 'Своих контактов пока нет — добавьте выше или звёздочкой из других вкладок.' : 'Ничего не найдено.'"></div>

            <template x-for="it in items" :key="it.source + it.email">
                <div class="ab-row">
                    <div class="ab-who">
                        <div class="ab-name" x-text="it.name || it.email"></div>
                        <div class="ab-meta">
                            <span x-show="it.name" x-text="it.email"></span>
                            <span x-show="it.org" x-text="(it.name ? ' · ' : '') + it.org"></span>
                        </div>
                    </div>
                    <div class="ab-acts">
                        <template x-for="f in fields" :key="f.key">
                            <button type="button" class="ab-add" x-show="hasField(f.key)"
                                    :class="{ 'is-added': isAdded(it, f.key) }"
                                    x-on:click="add(it, f.key)"
                                    x-text="isAdded(it, f.key) ? '✓ ' + f.label : f.label"></button>
                        </template>
                        <button type="button" class="ab-icon" x-show="it.source !== 'mine'"
                                :class="{ 'is-saved': it.saved }"
                                x-on:click="saveContact(it)" title="В мои контакты">★</button>
                        <button type="button" class="ab-icon" x-show="it.source === 'mine'"
                                x-on:click="removeContact(it)" title="Удалить из моих контактов">🗑</button>
                    </div>
                </div>
            </template>
        </div>

        <div class="ab-foot">
            <span>Недавние — кому вы писали за полгода. Клиенты и поставщики — из реестров CRM.</span>
            <button type="button" class="ab-done" x-on:click="close()">Готово</button>
        </div>
    </div>
</div>
