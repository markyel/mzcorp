<?php

namespace App\Services\Bot;

use App\Models\MessengerChat;
use App\Models\MessengerMessage;
use App\Models\OrganizationLinkRequest;
use App\Services\Clients\OrganizationLinkGuard;
use App\Services\Clients\OrganizationRegistryService;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;

/**
 * Бот заявок MyZip в Telegram и MAX — без модели, кнопками и командами.
 *
 * Регистрация: e-mail → код из письма → имя → телефон → ИНН (организация из
 * реестра, клиент подтверждает) или название без ИНН. После неё: цена по
 * M-артикулу (BotPriceService) и заявка (черновик из строк → BotRequestService).
 *
 * Шаг диалога — state.awaiting: email | code | name | phone | inn | org_confirm |
 * company | draft. Регистрация не пройдена — любое сообщение ведёт к следующему
 * её шагу; артикулы, присланные до неё, запоминаются и показываются после.
 */
class BotConversationService
{
    /** Ограничение от злоупотреблений, сообщений клиента. */
    public const LIMIT_PER_10_MIN = 30;

    public const LIMIT_PER_DAY = 200;

    /** Строк в черновике заявки. */
    public const DRAFT_MAX_LINES = 100;

    private const MENU = [
        [['text' => '💰 Цена по артикулу', 'data' => 'price'], ['text' => '📝 Новая заявка', 'data' => 'req']],
        [['text' => '👤 Мои данные', 'data' => 'profile']],
    ];

    private ?BotMessenger $messenger = null;

    public function __construct(
        private readonly BotMessengers $messengers,
        private readonly BotEmailVerifier $verifier,
        private readonly BotPriceService $prices,
        private readonly BotRequestService $requests,
        private readonly OrganizationRegistryService $registry,
        private readonly OrganizationLinkGuard $linkGuard,
    ) {}

    public function handle(BotUpdate $u): void
    {
        $this->messenger = $this->messengers->for($u->channel);
        if ($this->messenger === null) {
            Log::warning('BotConversation: no token for channel', ['channel' => $u->channel->value]);

            return;
        }

        $chat = MessengerChat::query()->firstOrNew(['channel' => $u->channel->value, 'external_user_id' => $u->userId]);
        $chat->fill([
            'chat_id' => $u->chatId,
            'username' => $u->username ?? $chat->username,
            'first_name' => $u->firstName ?? $chat->first_name,
            'last_name' => $u->lastName ?? $chat->last_name,
            'last_message_at' => now(),
        ]);
        $chat->messages_count = (int) $chat->messages_count + 1;
        $chat->save();

        MessengerMessage::create([
            'messenger_chat_id' => $chat->id,
            'direction' => 'in',
            'kind' => match (true) {
                $u->isCallback() => 'callback',
                $u->isStart => 'start',
                $u->contactPhone !== null => 'contact',
                default => 'text',
            },
            'text' => $u->isCallback() ? $u->callbackData : ($u->contactPhone ?? mb_substr($u->text, 0, 4000)),
        ]);

        if ($chat->blocked_at !== null) {
            return;
        }
        if ($u->isCallback()) {
            $this->messenger->answerCallback((string) $u->callbackId);
        }
        if ($this->overLimit($chat)) {
            return;
        }

        try {
            $this->route($chat, $u);
        } catch (\Throwable $e) {
            Log::error('BotConversation: handling failed', ['chat_id' => $chat->id, 'error' => $e->getMessage()]);
            $this->say($chat, 'Что-то пошло не так. Попробуйте ещё раз чуть позже или напишите нам на почту.');
        }
    }

    private function route(MessengerChat $chat, BotUpdate $u): void
    {
        $text = trim($u->text);
        $lower = mb_strtolower($text);

        if ($u->isCallback()) {
            $this->onButton($chat, (string) $u->callbackData);

            return;
        }
        if ($u->isStart) {
            $chat->stateForget('awaiting');
            $chat->save();
            $this->say($chat, 'Здравствуйте! Это бот MyZip — запчасти для лифтов и эскалаторов. '
                .'Здесь можно узнать цену по артикулу M… и оформить заявку.');
            $this->nextStep($chat);

            return;
        }
        if (in_array($lower, ['/cancel', 'отмена', 'отменить'], true)) {
            $chat->stateForget('awaiting');
            $chat->save();
            $this->nextStep($chat, 'Хорошо, отменили.');

            return;
        }
        if ($u->contactPhone !== null && $chat->stateGet('awaiting') === 'phone') {
            $this->savePhone($chat, $u->contactPhone);

            return;
        }

        $awaiting = (string) $chat->stateGet('awaiting', '');
        // Посреди регистрации прислали артикул — запоминаем, напоминаем шаг.
        $registrationSteps = ['email', 'code', 'name', 'phone', 'inn', 'org_confirm', 'company'];
        if (in_array($awaiting, $registrationSteps, true) && BotPriceService::skusIn($text) !== []) {
            $chat->stateSet('after_registration', ['price' => BotPriceService::skusIn($text)]);
            $chat->save();
            // Код и выбор организации не сбрасываем — иначе пропадёт отправленный код.
            if ($awaiting === 'code' || $awaiting === 'org_confirm') {
                $this->say($chat, 'Цену покажу сразу после регистрации. '.($awaiting === 'code'
                    ? 'Сейчас пришлите код из письма.' : 'Сейчас подтвердите организацию кнопкой выше.'));

                return;
            }
            $chat->stateForget('awaiting');
            $chat->save();
            $this->nextStep($chat, 'Цену покажу сразу после регистрации — осталось немного.');

            return;
        }
        if ($awaiting !== '' && ! str_starts_with($text, '/')) {
            $this->onAwaiting($chat, $awaiting, $text);

            return;
        }

        if (! $chat->isRegistered()) {
            // Артикулы до регистрации запоминаем — покажем цену сразу после неё.
            $skus = BotPriceService::skusIn($text);
            if ($skus !== []) {
                $chat->stateSet('after_registration', ['price' => $skus]);
                $chat->save();
            }
            $this->nextStep($chat, 'Цены и заявки доступны после короткой регистрации.');

            return;
        }

        match (true) {
            $lower === '/price' => $this->onButton($chat, 'price'),
            $lower === '/request' => $this->onButton($chat, 'req'),
            $lower === '/profile' => $this->onButton($chat, 'profile'),
            BotPriceService::skusIn($text) !== [] => $this->showPrices($chat, BotPriceService::skusIn($text)),
            $text !== '' && ! str_starts_with($text, '/') => $this->offerDraftFromText($chat, $text),
            default => $this->menu($chat),
        };
    }

    /* ───────────────────────────── Кнопки ───────────────────────────── */

    private function onButton(MessengerChat $chat, string $data): void
    {
        [$action, $arg] = array_pad(explode(':', $data, 2), 2, null);

        // До регистрации доступны только кнопки самой регистрации.
        $registrationButtons = ['code_resend', 'email_change', 'org_yes', 'org_no', 'no_inn'];
        if (! $chat->isRegistered() && ! in_array($action, $registrationButtons, true)) {
            $this->nextStep($chat);

            return;
        }

        match ($action) {
            'menu' => $this->menu($chat),
            'price' => $this->askPrice($chat),
            'req' => $this->startDraft($chat),
            'add' => $this->addToDraft($chat, (string) $arg),
            'draft_text' => $this->draftFromSavedText($chat),
            'send' => $this->submitDraft($chat),
            'clear' => $this->clearDraft($chat),
            'profile' => $this->profile($chat),
            'email_change' => $this->askEmail($chat),
            'org_change' => $this->askInn($chat),
            'code_resend' => $this->resendCode($chat),
            'org_yes' => $this->confirmOrganization($chat),
            'org_no' => $this->askInn($chat, 'Хорошо. Проверьте ИНН и пришлите ещё раз, или нажмите «У меня нет ИНН».'),
            'no_inn' => $this->askCompany($chat),
            default => $this->menu($chat),
        };
    }

    /* ─────────────────────────── Шаги диалога ─────────────────────────── */

    private function onAwaiting(MessengerChat $chat, string $awaiting, string $text): void
    {
        match ($awaiting) {
            'email' => $this->onEmail($chat, $text),
            'code' => $this->onCode($chat, $text),
            'name' => $this->onName($chat, $text),
            'phone' => $this->savePhone($chat, $text),
            'inn' => $this->onInn($chat, $text),
            'org_confirm' => $this->say($chat, 'Нажмите «Да» или «Нет» под сообщением с организацией.'),
            'company' => $this->onCompany($chat, $text),
            'draft' => $this->appendToDraft($chat, $text),
            default => $this->nextStep($chat),
        };
    }

    /** Следующий шаг регистрации, а если пройдена — отложенное действие или меню. */
    private function nextStep(MessengerChat $chat, ?string $prefix = null): void
    {
        if ($prefix !== null) {
            $this->say($chat, $prefix);
        }
        if (! $chat->isVerified()) {
            $this->askEmail($chat);
        } elseif (trim((string) $chat->contact_name) === '') {
            $this->setAwaiting($chat, 'name');
            $this->say($chat, 'Как к вам обращаться? Напишите имя и фамилию.');
        } elseif (trim((string) $chat->phone) === '') {
            $this->setAwaiting($chat, 'phone');
            $this->say($chat, 'Ваш телефон для связи — нажмите кнопку или напишите номер.', [[['text' => '📱 Отправить номер', 'contact' => true]]]);
        } elseif ($chat->organization_id === null && trim((string) $chat->company_name) === '') {
            $this->askInn($chat);
        } else {
            $after = (array) $chat->stateGet('after_registration', []);
            $chat->stateForget('after_registration');
            $chat->save();
            if (! empty($after['price'])) {
                $this->showPrices($chat, (array) $after['price']);

                return;
            }
            $this->menu($chat);
        }
    }

    private function askEmail(MessengerChat $chat): void
    {
        $this->setAwaiting($chat, 'email');
        $this->say($chat, 'Укажите рабочий e-mail — пришлём на него код подтверждения. На этот адрес придут предложения и счета.');
    }

    private function onEmail(MessengerChat $chat, string $text): void
    {
        $email = mb_strtolower(trim($text));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->say($chat, 'Не похоже на e-mail. Пришлите адрес вида name@company.ru.');

            return;
        }
        $res = $this->verifier->send($chat, $email);
        if (! $res['ok']) {
            $this->say($chat, ($res['reason'] ?? '') === 'throttled'
                ? 'Слишком много писем с кодом. Попробуйте через полчаса.'
                : 'Не получилось отправить письмо. Проверьте адрес или попробуйте позже.');

            return;
        }
        $this->setAwaiting($chat, 'code');
        $this->say($chat, 'Отправили код на <b>'.e($email).'</b>. Пришлите его сюда (6 цифр). Письмо может прийти через минуту, проверьте «Спам».', [[
            ['text' => '🔁 Отправить ещё раз', 'data' => 'code_resend'],
            ['text' => '✉️ Другой e-mail', 'data' => 'email_change'],
        ]]);
    }

    private function resendCode(MessengerChat $chat): void
    {
        $email = (string) $chat->stateGet('pending_email', '');
        $email !== '' ? $this->onEmail($chat, $email) : $this->askEmail($chat);
    }

    private function onCode(MessengerChat $chat, string $text): void
    {
        if (! preg_match('/^\D*(\d\D*){6}$/u', $text)) {
            $this->say($chat, 'Пришлите 6 цифр из письма. Или нажмите «Другой e-mail».', [[['text' => '✉️ Другой e-mail', 'data' => 'email_change']]]);

            return;
        }
        $res = $this->verifier->verify($chat, $text);
        if (! $res['ok']) {
            match ($res['reason'] ?? '') {
                'mismatch' => $this->say($chat, 'Код не подошёл. Проверьте и пришлите ещё раз.'),
                'expired' => $this->say($chat, 'Код устарел.', [[['text' => '🔁 Отправить новый', 'data' => 'code_resend']]]),
                'too_many_attempts' => $this->say($chat, 'Слишком много попыток.', [[['text' => '🔁 Отправить новый код', 'data' => 'code_resend']]]),
                default => $this->askEmail($chat),
            };

            return;
        }
        $chat->stateForget('awaiting');
        $chat->save();
        $this->nextStep($chat, '✅ E-mail подтверждён.');
    }

    private function onName(MessengerChat $chat, string $text): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 200) {
            $this->say($chat, 'Напишите, пожалуйста, имя и фамилию.');

            return;
        }
        $chat->contact_name = $name;
        $chat->stateForget('awaiting');
        $chat->save();
        $this->nextStep($chat);
    }

    private function savePhone(MessengerChat $chat, string $text): void
    {
        $digits = preg_replace('/\D+/', '', $text) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '7'.$digits;
        }
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            $this->say($chat, 'Не похоже на номер телефона. Пришлите номер вида +7 900 123-45-67.');

            return;
        }
        $chat->phone = '+'.$digits;
        $chat->stateForget('awaiting');
        $chat->save();
        $this->nextStep($chat);
    }

    private function askInn(MessengerChat $chat, ?string $text = null): void
    {
        $this->setAwaiting($chat, 'inn');
        $this->say($chat, $text ?? 'ИНН вашей организации — найдём её в реестре.', [[['text' => 'У меня нет ИНН', 'data' => 'no_inn']]]);
    }

    private function onInn(MessengerChat $chat, string $text): void
    {
        $inn = preg_replace('/\D+/', '', $text) ?? '';
        if (! in_array(strlen($inn), [9, 10, 12], true)) {
            $this->say($chat, 'ИНН — 10 цифр у организации, 12 у ИП. Пришлите ещё раз или нажмите «У меня нет ИНН».', [[['text' => 'У меня нет ИНН', 'data' => 'no_inn']]]);

            return;
        }

        $res = $this->registry->resolveForIngest($inn);
        $org = $res['org'] ?? null;
        if ($res['status'] === 'ok' && $org !== null) {
            $chat->inn = $inn;
            $chat->stateSet('org_candidate', $org->id);
            $this->setAwaiting($chat, 'org_confirm');
            $this->say($chat, 'Организация: <b>'.e((string) $org->name).'</b>, ИНН '.e($inn).'. Верно?', [[
                ['text' => '✅ Да', 'data' => 'org_yes'],
                ['text' => '✏️ Нет', 'data' => 'org_no'],
            ]]);

            return;
        }
        if ($res['status'] === 'unavailable') {
            // Реестр не ответил — не держим клиента: ИНН запомнили, название спросим.
            $chat->inn = $inn;
            $chat->save();
            $this->askCompany($chat, 'Реестр сейчас не отвечает. Напишите название организации — проверим позже.');

            return;
        }
        $this->say($chat, 'Не нашли такой ИНН в реестре. Проверьте и пришлите ещё раз, или нажмите «У меня нет ИНН».', [[['text' => 'У меня нет ИНН', 'data' => 'no_inn']]]);
    }

    private function confirmOrganization(MessengerChat $chat): void
    {
        $org = Organization::find((int) $chat->stateGet('org_candidate', 0));
        if ($org === null) {
            $this->askInn($chat);

            return;
        }
        $chat->company_name = (string) $org->name;
        $chat->stateForget('org_candidate', 'awaiting');

        $status = $this->linkGuard->link($org, (string) $chat->email, [
            'source' => OrganizationLinkRequest::SOURCE_BOT,
            'self_declared' => true,
        ])['status'];
        if (in_array($status, ['linked', 'exists'], true)) {
            $chat->organization_id = $org->id;
        }
        $chat->save();

        $this->say($chat, in_array($status, ['linked', 'exists'], true)
            ? '✅ Готово, вы зарегистрированы. Цены — для '.e((string) $org->name).'.'
            : '✅ Вы зарегистрированы. Привязку к '.e((string) $org->name).' подтвердит менеджер — до этого показываем розничные цены.');
        $this->nextStep($chat);
    }

    private function askCompany(MessengerChat $chat, ?string $text = null): void
    {
        $this->setAwaiting($chat, 'company');
        $this->say($chat, $text ?? 'Напишите название организации (или «частное лицо»).');
    }

    private function onCompany(MessengerChat $chat, string $text): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 300) {
            $this->say($chat, 'Напишите название организации.');

            return;
        }
        $chat->company_name = $name;
        $chat->stateForget('awaiting');
        $chat->save();
        $this->nextStep($chat, '✅ Вы зарегистрированы.');
    }

    /* ───────────────────────────── Цены ───────────────────────────── */

    private function askPrice(MessengerChat $chat): void
    {
        // Из режима черновика выходим: иначе артикулы для цены легли бы в заявку.
        $chat->stateForget('awaiting');
        $chat->save();
        $this->say($chat, 'Пришлите артикулы вида <b>M12345</b> — можно несколько в одном сообщении.');
    }

    /** @param  list<string>  $skus */
    private function showPrices(MessengerChat $chat, array $skus): void
    {
        foreach (array_slice($skus, 0, BotPriceService::MAX_PER_MESSAGE) as $sku) {
            $q = $this->prices->quote($chat, (string) $sku);
            $this->say($chat, BotPriceService::format($q), $q['found'] ? [[['text' => '➕ В заявку', 'data' => 'add:'.$sku]]] : []);
        }
    }

    /* ─────────────────────────── Черновик заявки ─────────────────────────── */

    private function startDraft(MessengerChat $chat): void
    {
        $this->setAwaiting($chat, 'draft');
        $draft = (array) $chat->stateGet('draft', []);
        $this->say($chat, ($draft === [] ? '' : $this->draftSummary($chat)."\n\n")
            .'Пришлите позиции: артикулы M… с количеством («M12345 2») или описание детали, марку лифта, фото шильдика словами. '
            .'Можно несколькими сообщениями. Когда всё — нажмите «Отправить заявку».', $this->draftButtons($chat));
    }

    private function appendToDraft(MessengerChat $chat, string $text): void
    {
        $draft = (array) $chat->stateGet('draft', []);
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            // «M12345 2, M23456 1» в одной строке — две позиции.
            $parts = count(BotPriceService::skusIn($line)) > 1 ? (preg_split('/[,;]+/u', $line) ?: [$line]) : [$line];
            foreach ($parts as $part) {
                if (trim($part) !== '') {
                    $draft = $this->putLine($draft, BotRequestService::parseLine($part));
                }
            }
        }
        $chat->stateSet('draft', array_slice($draft, 0, self::DRAFT_MAX_LINES));
        $chat->save();
        $this->say($chat, $this->draftSummary($chat), $this->draftButtons($chat));
    }

    private function addToDraft(MessengerChat $chat, string $sku): void
    {
        if (BotPriceService::skusIn($sku) === []) {
            return;
        }
        $draft = $this->putLine((array) $chat->stateGet('draft', []), ['sku' => $sku, 'qty' => 1.0, 'text' => $sku.' 1']);
        $chat->stateSet('draft', $draft);
        $this->setAwaiting($chat, 'draft');
        $this->say($chat, 'Добавили <b>'.e($sku).'</b> (1 шт.). Количество можно поправить: пришлите «'.e($sku).' 5».'
            ."\n\n".$this->draftSummary($chat), $this->draftButtons($chat));
    }

    /** Строка с артикулом, который уже есть в черновике, заменяет прежнюю (поправка количества). */
    private function putLine(array $draft, array $line): array
    {
        if ($line['sku'] !== null) {
            foreach ($draft as $i => $existing) {
                if (($existing['sku'] ?? null) === $line['sku']) {
                    $draft[$i] = $line;

                    return $draft;
                }
            }
        }
        $draft[] = $line;

        return $draft;
    }

    private function offerDraftFromText(MessengerChat $chat, string $text): void
    {
        $chat->stateSet('last_text', mb_substr($text, 0, 4000));
        $chat->save();
        $this->say($chat, 'Оформить заявку с этим текстом?', [[
            ['text' => '📝 Да, в заявку', 'data' => 'draft_text'],
            ['text' => 'Меню', 'data' => 'menu'],
        ]]);
    }

    private function draftFromSavedText(MessengerChat $chat): void
    {
        $text = (string) $chat->stateGet('last_text', '');
        $chat->stateForget('last_text');
        $this->setAwaiting($chat, 'draft');
        $text !== '' ? $this->appendToDraft($chat, $text) : $this->startDraft($chat);
    }

    private function clearDraft(MessengerChat $chat): void
    {
        $chat->stateForget('draft');
        $this->setAwaiting($chat, 'draft');
        $this->say($chat, 'Черновик очищен. Пришлите позиции заново.');
    }

    private function submitDraft(MessengerChat $chat): void
    {
        if ((array) $chat->stateGet('draft', []) === []) {
            $this->startDraft($chat);

            return;
        }
        $request = $this->requests->submit($chat);
        $chat->stateForget('draft', 'awaiting');
        $chat->save();
        $this->say($chat, '✅ Заявка <b>'.e((string) $request->internal_code).'</b> принята. '
            .'Менеджер подготовит предложение и пришлёт его на '.e((string) $chat->email).'.', self::MENU);
    }

    private function draftSummary(MessengerChat $chat): string
    {
        $draft = (array) $chat->stateGet('draft', []);
        if ($draft === []) {
            return 'Черновик заявки пуст.';
        }
        $lines = array_map(fn ($l, $i) => ($i + 1).'. '.e(($l['sku'] ?? null) !== null
            ? $l['sku'].' — '.rtrim(rtrim(number_format((float) $l['qty'], 2, ',', ' '), '0'), ',').' шт.'
            : mb_strimwidth((string) $l['text'], 0, 120, '…')), $draft, array_keys($draft));

        return "<b>Черновик заявки:</b>\n".implode("\n", array_slice($lines, 0, 30))
            .(count($lines) > 30 ? "\n… и ещё ".(count($lines) - 30) : '');
    }

    private function draftButtons(MessengerChat $chat): array
    {
        return (array) $chat->stateGet('draft', []) === []
            ? [[['text' => 'Меню', 'data' => 'menu']]]
            : [[['text' => '✅ Отправить заявку', 'data' => 'send']], [['text' => '🗑 Очистить', 'data' => 'clear'], ['text' => 'Меню', 'data' => 'menu']]];
    }

    /* ───────────────────────────── Прочее ───────────────────────────── */

    private function menu(MessengerChat $chat): void
    {
        $chat->stateForget('awaiting');
        $chat->save();
        $this->say($chat, 'Что сделать? Можно просто прислать артикул M… — покажу цену.', self::MENU);
    }

    private function profile(MessengerChat $chat): void
    {
        $org = $chat->organization?->name ?: $chat->company_name;
        $this->say($chat, "<b>Ваши данные</b>\n"
            .'E-mail: '.e((string) $chat->email)."\n"
            .'Имя: '.e((string) $chat->contact_name)."\n"
            .'Телефон: '.e((string) $chat->phone)."\n"
            .'Организация: '.e((string) $org).($chat->inn ? ', ИНН '.e((string) $chat->inn) : '')
            .($chat->organization_id === null && $org ? "\n<i>Цены пока розничные — привязку к организации подтверждает менеджер.</i>" : ''),
            [[['text' => '✉️ Сменить e-mail', 'data' => 'email_change'], ['text' => '🏢 Сменить организацию', 'data' => 'org_change']], [['text' => 'Меню', 'data' => 'menu']]]);
    }

    private function setAwaiting(MessengerChat $chat, string $step): void
    {
        $chat->stateSet('awaiting', $step);
        $chat->save();
    }

    private function overLimit(MessengerChat $chat): bool
    {
        $base = MessengerMessage::query()->where('messenger_chat_id', $chat->id)->where('direction', 'in');
        $tenMin = (clone $base)->where('created_at', '>=', now()->subMinutes(10))->count();
        $day = (clone $base)->where('created_at', '>=', now()->subDay())->count();
        if ($tenMin <= self::LIMIT_PER_10_MIN && $day <= self::LIMIT_PER_DAY) {
            return false;
        }
        // Предупреждаем один раз на порог, дальше молчим.
        if ($tenMin === self::LIMIT_PER_10_MIN + 1 || $day === self::LIMIT_PER_DAY + 1) {
            $this->say($chat, 'Слишком много сообщений подряд. Попробуйте немного позже.');
        }

        return true;
    }

    /** @param  list<list<array<string, mixed>>>  $buttons */
    private function say(MessengerChat $chat, string $html, array $buttons = []): void
    {
        $ok = $this->messenger?->sendText((string) $chat->chat_id, $html, $buttons) ?? false;
        MessengerMessage::create([
            'messenger_chat_id' => $chat->id,
            'direction' => 'out',
            'kind' => 'bot',
            'text' => $html,
            'meta' => $ok ? null : ['send_failed' => true],
        ]);
    }
}
