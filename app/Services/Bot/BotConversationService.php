<?php

namespace App\Services\Bot;

use App\Models\MessengerChat;
use App\Models\MessengerMessage;
use App\Models\Organization;
use App\Models\OrganizationLinkRequest;
use App\Services\Clients\OrganizationLinkGuard;
use App\Services\Clients\OrganizationRegistryService;
use Illuminate\Support\Facades\Log;

/**
 * Бот заявок MyZip в Telegram и MAX — без модели, кнопками и командами.
 *
 * Решение заказчика (12.10.2026): лишнего не спрашиваем. Для цены по
 * M-артикулу достаточно подтверждённого e-mail (код из письма). Анкета — имя,
 * телефон, организация — только при отправке ПЕРВОЙ заявки, каждый вопрос
 * можно пропустить («Отправить без анкеты» — все сразу); пропущенное больше
 * не спрашиваем, дополнить можно в «Моих данных».
 *
 * state.awaiting — чего ждём: email | code | name | phone | inn | org_confirm |
 * company | draft. state.flow — зачем спрашиваем данные: submit (анкета перед
 * заявкой) | profile (правка из «Моих данных»).
 */
class BotConversationService
{
    /** Ограничение от злоупотреблений, сообщений клиента. */
    public const LIMIT_PER_10_MIN = 30;

    public const LIMIT_PER_DAY = 200;

    /** Строк в черновике заявки. */
    public const DRAFT_MAX_LINES = 100;

    /** Вопросы анкеты по порядку. */
    private const QUESTIONS = ['name', 'phone', 'org'];

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
        private readonly BotThreadService $thread,
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
            $chat->stateForget('awaiting', 'flow');
            $chat->save();
            $this->say($chat, 'Здравствуйте! Это бот MyZip — запчасти для лифтов и эскалаторов. '
                .'Здесь можно узнать цену и наличие по артикулу M… и оформить заявку.');
            $chat->isVerified() ? $this->menu($chat) : $this->askEmail($chat);

            return;
        }
        if (in_array($lower, ['/cancel', 'отмена', 'отменить'], true)) {
            $chat->stateForget('awaiting', 'flow');
            $chat->save();
            $this->say($chat, 'Хорошо, отменили.');
            $chat->isVerified() ? $this->menu($chat) : $this->askEmail($chat);

            return;
        }

        $awaiting = (string) $chat->stateGet('awaiting', '');

        // Кнопки анкеты в Telegram при «Отправить номер» — обычная клавиатура:
        // нажатие приходит текстом.
        if (in_array($awaiting, ['name', 'phone', 'inn', 'company'], true)) {
            if ($lower === 'пропустить') {
                $this->skip($chat);

                return;
            }
            if ($lower === 'отправить без анкеты') {
                $this->skipAll($chat);

                return;
            }
        }
        if ($u->contactPhone !== null && $awaiting === 'phone') {
            $this->savePhone($chat, $u->contactPhone);

            return;
        }

        // Ждём e-mail или код, а прислали артикул — запоминаем, цена после подтверждения.
        if (in_array($awaiting, ['email', 'code'], true) && BotPriceService::skusIn($text) !== []) {
            $chat->stateSet('after_verify', BotPriceService::skusIn($text));
            $chat->save();
            $this->say($chat, 'Цену покажу сразу после подтверждения e-mail. '
                .($awaiting === 'code' ? 'Пришлите код из письма.' : 'Пришлите ваш e-mail.'));

            return;
        }

        if ($awaiting !== '' && ! str_starts_with($text, '/')) {
            $this->onAwaiting($chat, $awaiting, $text);

            return;
        }

        if (! $chat->isVerified()) {
            $skus = BotPriceService::skusIn($text);
            if ($skus !== []) {
                $chat->stateSet('after_verify', $skus);
                $chat->save();
            }
            $this->say($chat, 'Цены и заявки доступны после подтверждения e-mail — это одно письмо с кодом.');
            $this->askEmail($chat);

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

        // До подтверждения e-mail — только кнопки самого подтверждения.
        if (! $chat->isVerified() && ! in_array($action, ['code_resend', 'email_change'], true)) {
            $this->askEmail($chat);

            return;
        }

        match ($action) {
            'menu' => $this->menu($chat),
            'price' => $this->askPrice($chat),
            'req' => $this->startDraft($chat),
            'add' => $this->addToDraft($chat, (string) $arg),
            'draft_text' => $this->draftFromSavedText($chat),
            'send' => $this->send($chat),
            'clear' => $this->clearDraft($chat),
            'profile' => $this->profile($chat),
            'edit' => $this->editField($chat, (string) $arg),
            'email_change' => $this->askEmail($chat),
            'code_resend' => $this->resendCode($chat),
            'skip' => $this->skip($chat),
            'skip_all' => $this->skipAll($chat),
            'org_yes' => $this->confirmOrganization($chat),
            'org_no' => $this->ask($chat, 'org', 'Хорошо, проверьте ИНН. '),
            'no_inn' => $this->askCompany($chat),
            'reply' => $this->startReply($chat, (int) $arg),
            'tomgr' => $this->savedTextToManager($chat, (int) $arg),
            default => $this->menu($chat),
        };
    }

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
            'reply' => $this->onReply($chat, $text),
            default => $this->menu($chat),
        };
    }

    /* ───────────────────────── Подтверждение e-mail ───────────────────────── */

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
        $this->say($chat, '✅ E-mail подтверждён.');

        // Сменили адрес из «Моих данных» — вернуться туда.
        if ($chat->stateGet('flow') === 'profile') {
            $chat->stateForget('flow');
            $chat->save();
            $this->profile($chat);

            return;
        }
        $skus = (array) $chat->stateGet('after_verify', []);
        $chat->stateForget('after_verify');
        $chat->save();
        $skus !== [] ? $this->showPrices($chat, $skus) : $this->menu($chat);
    }

    /* ───────────────────────────── Анкета ───────────────────────────── */

    /** Есть ли у клиента ответ на вопрос анкеты. */
    private function answered(MessengerChat $chat, string $question): bool
    {
        return match ($question) {
            'name' => trim((string) $chat->contact_name) !== '',
            'phone' => trim((string) $chat->phone) !== '',
            'org' => $chat->organization_id !== null || trim((string) $chat->company_name) !== '',
            default => true,
        };
    }

    /** Следующий незаданный вопрос анкеты перед заявкой; false — вопросов нет. */
    private function askNextQuestion(MessengerChat $chat): bool
    {
        $skipped = (array) $chat->stateGet('skipped', []);
        foreach (self::QUESTIONS as $q) {
            if (! $this->answered($chat, $q) && ! in_array($q, $skipped, true)) {
                $this->ask($chat, $q);

                return true;
            }
        }

        return false;
    }

    private function ask(MessengerChat $chat, string $question, ?string $prefix = null): void
    {
        $inSubmit = $chat->stateGet('flow') === 'submit';
        $skip = $inSubmit
            ? [['text' => 'Пропустить', 'data' => 'skip'], ['text' => 'Отправить без анкеты', 'data' => 'skip_all']]
            : [['text' => 'Отмена', 'data' => 'profile']];

        match ($question) {
            'name' => $this->askWith($chat, 'name', ($prefix ?? '').'Как к вам обращаться?', [$skip]),
            'phone' => $this->askWith($chat, 'phone', ($prefix ?? '').'Телефон для связи — кнопкой или текстом.',
                [[['text' => '📱 Отправить номер', 'contact' => true]], $skip]),
            default => $this->askWith($chat, 'inn', ($prefix ?? '').'ИНН вашей организации — найдём её в реестре.',
                [[['text' => 'У меня нет ИНН', 'data' => 'no_inn']], $skip]),
        };
    }

    private function askWith(MessengerChat $chat, string $awaiting, string $text, array $buttons): void
    {
        $this->setAwaiting($chat, $awaiting);
        $this->say($chat, $text, $buttons);
    }

    /** Ответ записан или вопрос пропущен — дальше по сценарию. */
    private function continueFlow(MessengerChat $chat): void
    {
        $chat->stateForget('awaiting');
        $chat->save();
        $flow = (string) $chat->stateGet('flow', '');

        if ($flow === 'submit') {
            if (! $this->askNextQuestion($chat)) {
                $this->submit($chat);
            }

            return;
        }
        $chat->stateForget('flow');
        $chat->save();
        $flow === 'profile' ? $this->profile($chat) : $this->menu($chat);
    }

    private function skip(MessengerChat $chat): void
    {
        $question = match ((string) $chat->stateGet('awaiting', '')) {
            'name' => 'name',
            'phone' => 'phone',
            'inn', 'company', 'org_confirm' => 'org',
            default => null,
        };
        if ($question !== null) {
            $skipped = (array) $chat->stateGet('skipped', []);
            $skipped[] = $question;
            $chat->stateSet('skipped', array_values(array_unique($skipped)));
        }
        $this->continueFlow($chat);
    }

    private function skipAll(MessengerChat $chat): void
    {
        $chat->stateSet('skipped', self::QUESTIONS);
        $chat->stateForget('awaiting');
        $chat->save();
        $chat->stateGet('flow') === 'submit' ? $this->submit($chat) : $this->continueFlow($chat);
    }

    private function onName(MessengerChat $chat, string $text): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 200) {
            $this->say($chat, 'Напишите, как к вам обращаться, или нажмите «Пропустить».');

            return;
        }
        $chat->contact_name = $name;
        $chat->save();
        $this->continueFlow($chat);
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
        if (strlen($digits) < 11 || strlen($digits) > 15) {
            $this->say($chat, 'Не похоже на номер телефона. Пришлите номер вида +7 900 123-45-67 или нажмите «Пропустить».');

            return;
        }
        $chat->phone = '+'.$digits;
        $chat->save();
        $this->continueFlow($chat);
    }

    private function onInn(MessengerChat $chat, string $text): void
    {
        $inn = preg_replace('/\D+/', '', $text) ?? '';
        if (! in_array(strlen($inn), [9, 10, 12], true)) {
            $this->say($chat, 'ИНН — 10 цифр у организации, 12 у ИП. Пришлите ещё раз, нажмите «У меня нет ИНН» или «Пропустить».');

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
        $this->say($chat, 'Не нашли такой ИНН в реестре. Проверьте и пришлите ещё раз, или нажмите «У меня нет ИНН».');
    }

    private function confirmOrganization(MessengerChat $chat): void
    {
        $org = Organization::find((int) $chat->stateGet('org_candidate', 0));
        if ($org === null) {
            $this->ask($chat, 'org');

            return;
        }
        $chat->company_name = (string) $org->name;
        $chat->stateForget('org_candidate');

        $status = $this->linkGuard->link($org, (string) $chat->email, [
            'source' => OrganizationLinkRequest::SOURCE_BOT,
            'self_declared' => true,
        ])['status'];
        $linked = in_array($status, ['linked', 'exists'], true);
        $chat->organization_id = $linked ? $org->id : null;
        $chat->save();

        $this->say($chat, $linked
            ? 'Цены — для '.e((string) $org->name).'.'
            : 'Привязку к '.e((string) $org->name).' подтвердит менеджер — до этого показываем розничные цены.');
        $this->continueFlow($chat);
    }

    private function askCompany(MessengerChat $chat, ?string $text = null): void
    {
        $skip = $chat->stateGet('flow') === 'submit'
            ? [[['text' => 'Пропустить', 'data' => 'skip']]]
            : [[['text' => 'Отмена', 'data' => 'profile']]];
        $this->askWith($chat, 'company', $text ?? 'Напишите название организации.', $skip);
    }

    private function onCompany(MessengerChat $chat, string $text): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 300) {
            $this->say($chat, 'Напишите название организации или нажмите «Пропустить».');

            return;
        }
        $chat->company_name = $name;
        $chat->save();
        $this->continueFlow($chat);
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
            .'Пришлите позиции: артикулы M… с количеством («M12345 2») или описание детали и марку лифта. '
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

    /**
     * Свободный текст: есть открытые заявки — предлагаем передать его
     * менеджеру (последней обсуждаемой первой), иначе — новую заявку.
     */
    private function offerDraftFromText(MessengerChat $chat, string $text): void
    {
        $chat->stateSet('last_text', mb_substr($text, 0, 4000));
        $chat->save();

        $open = BotThreadService::openRequests($chat);
        $activeId = (int) $chat->stateGet('active_request_id', 0);
        $open = $open->sortByDesc(fn ($r) => (int) $r->id === $activeId ? PHP_INT_MAX : $r->id)->take(3);
        $buttons = $open->map(fn ($r) => [['text' => '💬 Менеджеру по '.$r->internal_code, 'data' => 'tomgr:'.$r->id]])->values()->all();
        $buttons[] = [['text' => '📝 Новая заявка с этим текстом', 'data' => 'draft_text'], ['text' => 'Меню', 'data' => 'menu']];

        $this->say($chat, $open->isEmpty() ? 'Оформить заявку с этим текстом?' : 'Куда отправить сообщение?', $buttons);
    }

    /* ─────────────────────── Переписка по заявке ─────────────────────── */

    private function ownRequest(MessengerChat $chat, int $requestId): ?\App\Models\Request
    {
        return \App\Models\Request::query()->where('id', $requestId)->where('messenger_chat_id', $chat->id)->first();
    }

    private function startReply(MessengerChat $chat, int $requestId): void
    {
        $request = $this->ownRequest($chat, $requestId);
        if ($request === null) {
            $this->menu($chat);

            return;
        }
        $chat->stateSet('reply_request_id', $request->id);
        $this->setAwaiting($chat, 'reply');
        $this->say($chat, 'Напишите сообщение по заявке <b>'.e((string) $request->internal_code).'</b> — передадим менеджеру.',
            [[['text' => 'Отмена', 'data' => 'menu']]]);
    }

    private function onReply(MessengerChat $chat, string $text): void
    {
        $request = $this->ownRequest($chat, (int) $chat->stateGet('reply_request_id', 0));
        $chat->stateForget('awaiting', 'reply_request_id');
        $chat->save();
        if ($request === null || trim($text) === '') {
            $this->menu($chat);

            return;
        }
        $this->passToManager($chat, $request, $text);
    }

    private function savedTextToManager(MessengerChat $chat, int $requestId): void
    {
        $request = $this->ownRequest($chat, $requestId);
        $text = (string) $chat->stateGet('last_text', '');
        $chat->stateForget('last_text');
        $chat->save();
        if ($request === null || trim($text) === '') {
            $this->menu($chat);

            return;
        }
        $this->passToManager($chat, $request, $text);
    }

    private function passToManager(MessengerChat $chat, \App\Models\Request $request, string $text): void
    {
        $this->thread->fromClient($chat, $request, $text);
        $chat->stateSet('active_request_id', $request->id);
        $chat->save();
        $this->say($chat, '✅ Передали менеджеру по заявке <b>'.e((string) $request->internal_code).'</b>. Ответ придёт сюда или на почту.');
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

    /** «Отправить заявку»: перед первой заявкой — анкета (всё можно пропустить). */
    private function send(MessengerChat $chat): void
    {
        if ((array) $chat->stateGet('draft', []) === []) {
            $this->startDraft($chat);

            return;
        }
        if (! $chat->stateGet('profile_asked')) {
            $chat->stateSet('flow', 'submit');
            $chat->stateSet('profile_asked', true);
            $chat->save();
            if ($this->askNextQuestion($chat)) {
                return;
            }
        }
        $this->submit($chat);
    }

    private function submit(MessengerChat $chat): void
    {
        $chat->stateForget('flow', 'awaiting');
        $chat->save();
        if ((array) $chat->stateGet('draft', []) === []) {
            $this->menu($chat);

            return;
        }
        $request = $this->requests->submit($chat);
        $chat->stateForget('draft');
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

    /* ─────────────────────────── Мои данные ─────────────────────────── */

    private function profile(MessengerChat $chat): void
    {
        $chat->stateForget('awaiting', 'flow');
        $chat->save();
        $org = $chat->organization?->name ?: $chat->company_name;
        $dash = '—';
        $this->say($chat, "<b>Ваши данные</b>\n"
            .'E-mail: '.e((string) $chat->email)."\n"
            .'Имя: '.e((string) ($chat->contact_name ?: $dash))."\n"
            .'Телефон: '.e((string) ($chat->phone ?: $dash))."\n"
            .'Организация: '.e((string) ($org ?: $dash)).($chat->inn ? ', ИНН '.e((string) $chat->inn) : '')
            .($chat->organization_id === null && $org ? "\n<i>Цены пока розничные — привязку к организации подтверждает менеджер.</i>" : ''),
            [
                [['text' => '✏️ Имя', 'data' => 'edit:name'], ['text' => '✏️ Телефон', 'data' => 'edit:phone'], ['text' => '✏️ Организация', 'data' => 'edit:org']],
                [['text' => '✉️ Сменить e-mail', 'data' => 'edit:email'], ['text' => 'Меню', 'data' => 'menu']],
            ]);
    }

    private function editField(MessengerChat $chat, string $field): void
    {
        $chat->stateSet('flow', 'profile');
        $chat->save();
        $field === 'email' ? $this->askEmail($chat) : $this->ask($chat, in_array($field, self::QUESTIONS, true) ? $field : 'name');
    }

    /* ───────────────────────────── Прочее ───────────────────────────── */

    private function menu(MessengerChat $chat): void
    {
        $chat->stateForget('awaiting', 'flow');
        $chat->save();
        $this->say($chat, 'Пришлите артикул M… — покажу цену и наличие. Или оформите заявку.', self::MENU);
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
