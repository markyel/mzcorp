<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    {{-- Email verification отключена в MyLift Phase 1 --}}

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <x-input-label for="name" value="Имя (РУС)" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" placeholder="Илья Курзаев" />
                <x-input-error class="mt-2" :messages="$errors->get('name')" />
            </div>
            <div>
                <x-input-label for="name_en" value="Имя (EN, опц.)" />
                <x-text-input id="name_en" name="name_en" type="text" class="mt-1 block w-full" :value="old('name_en', $user->name_en)" placeholder="Ilya Kurzaev" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Латиницей — для подписи в зарубежной переписке.</p>
                <x-input-error class="mt-2" :messages="$errors->get('name_en')" />
            </div>
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" placeholder="ilya.kurzaev@myzip.ru" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />
        </div>

        <fieldset class="border border-gray-300 dark:border-gray-700 rounded-md px-4 pt-3 pb-4 space-y-3">
            <legend class="text-sm font-medium text-gray-700 dark:text-gray-200 px-1">Контакты для подписи</legend>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <x-input-label for="phone" value="Офисный телефон" />
                    <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="old('phone', $user->phone)" placeholder="+7 (495) 565-37-72" />
                    <x-input-error class="mt-2" :messages="$errors->get('phone')" />
                </div>
                <div>
                    <x-input-label for="phone_extension" value="Доб. номер" />
                    <x-text-input id="phone_extension" name="phone_extension" type="text" class="mt-1 block w-full" :value="old('phone_extension', $user->phone_extension)" placeholder="210" />
                    <x-input-error class="mt-2" :messages="$errors->get('phone_extension')" />
                </div>
            </div>
            <div>
                <x-input-label for="mobile_phone" value="Мобильный / Telegram" />
                <x-text-input id="mobile_phone" name="mobile_phone" type="text" class="mt-1 block w-full" :value="old('mobile_phone', $user->mobile_phone)" placeholder="+7 (909) 690-03-54" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">В подписи отображается как «моб/Telegram: ...».</p>
                <x-input-error class="mt-2" :messages="$errors->get('mobile_phone')" />
            </div>
        </fieldset>

        <div>
            <x-input-label for="email_signature" value="Подпись override (опц.)" />
            <textarea id="email_signature" name="email_signature" rows="4"
                      class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm font-mono text-sm"
                      placeholder="Оставьте пустым, чтобы использовать шаблонную подпись по полям выше.&#10;&#10;Заполните только если нужна полностью кастомная подпись.">{{ old('email_signature', $user->email_signature) }}</textarea>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Если поле пустое — подпись собирается автоматически из полей выше и реквизитов компании (с логотипом, ЭДО, общими телефонами).
                Заполните только если нужна нестандартная подпись (используется как есть, без шаблона).
            </p>
            <x-input-error class="mt-2" :messages="$errors->get('email_signature')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600 dark:text-gray-400"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
