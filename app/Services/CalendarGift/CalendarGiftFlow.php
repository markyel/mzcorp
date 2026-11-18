<?php

namespace App\Services\CalendarGift;

use App\Models\CalendarGiftRecipient;
use App\Models\MessengerChat;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Сценарий бота «Календарь в подарок» (ссылка ?start=calendar): подписаться на канал → «Я подписался» (бот канала
 * проверяет подписку) → согласие на обработку данных → имя, компания, телефон, адрес → запись в список получателей
 * (CalendarGiftRecipient). E-mail не спрашиваем — подтверждение почты здесь лишнее. Лимита на организацию нет.
 *
 * Кнопки — «cal_…», шаги анкеты — awaiting «cal_…»; BotConversationService передаёт их сюда до общих правил
 * (в т.ч. до проверки e-mail). $say — его отправка с журналом: fn (string $html, array $buttons = []).
 */
class CalendarGiftFlow
{
    /** Шаги анкеты по порядку: поле черновика => вопрос. */
    private const STEPS = [
        'cal_name' => ['contact_name', 'Как к вам обращаться? Напишите имя и фамилию получателя.'],
        'cal_company' => ['company_name', 'Компания, в которую отправить календарь?'],
        'cal_phone' => ['phone', 'Телефон получателя — для курьера.'],
        'cal_address' => ['address', 'Адрес доставки: город, улица, дом, офис. Если нужен индекс — добавьте его.'],
    ];

    public function __construct(private readonly ChannelSubscriptionChecker $checker) {}

    public static function isStartPayload(string $payload): bool
    {
        return str_starts_with(mb_strtolower(trim($payload)), 'calendar');
    }

    public function campaign(): string
    {
        return (string) config('services.calendar_gift.campaign');
    }

    public function isOpen(): bool
    {
        $until = config('services.calendar_gift.until');

        return $until === null || now()->lte(Carbon::parse((string) $until)->endOfDay());
    }

    public function start(MessengerChat $chat, Closure $say): void
    {
        $chat->stateForget('awaiting', 'cal');
        $chat->save();
        if (! $this->isOpen()) {
            $say('Запись на календарь '.$this->campaign().' закончилась. Спасибо, что вы с нами!', [[['text' => 'Меню', 'data' => 'menu']]]);

            return;
        }
        if (($mine = $this->recipient($chat)) !== null) {
            $say('Вы уже в списке получателей календаря '.$this->campaign().".\n\n".$this->summary($mine->only(['contact_name', 'company_name', 'phone', 'address'])),
                [[['text' => '✏️ Изменить данные', 'data' => 'cal_edit']], [['text' => 'Меню', 'data' => 'menu']]]);

            return;
        }

        $buttons = [];
        if ($url = $this->checker->channelUrl($chat->channel)) {
            $buttons[] = [['text' => '📣 Подписаться на канал', 'url' => $url]];
        }
        $buttons[] = [['text' => '✅ Я подписался', 'data' => 'cal_check']];
        $say('<b>Фирменный календарь MyZip '.$this->campaign().' в подарок</b> 🎁'."\n\n"
            .'Отправим календарь подписчикам нашего канала в '.$chat->channel->label().". Два шага:\n"
            ."1. Подпишитесь на канал — кнопка ниже.\n"
            .'2. Нажмите «Я подписался» и укажите, куда отправить календарь.', $buttons);
    }

    public function onButton(MessengerChat $chat, string $action, Closure $say): void
    {
        match ($action) {
            'cal_start' => $this->start($chat, $say),
            'cal_check' => $this->check($chat, $say),
            'cal_consent' => $this->askStep($chat, 'cal_name', $say),
            'cal_keep' => $this->keep($chat, $say),
            'cal_ok' => $this->save($chat, $say),
            'cal_edit' => $this->edit($chat, $say),
            default => $this->start($chat, $say),
        };
    }

    /** Ответ на шаг анкеты (awaiting «cal_…»). */
    public function onAnswer(MessengerChat $chat, string $step, string $text, Closure $say): void
    {
        $text = trim($text);
        $error = match ($step) {
            'cal_name' => mb_strlen($text) < 2 ? 'Напишите, пожалуйста, имя получателя.' : null,
            'cal_company' => mb_strlen($text) < 2 ? 'Напишите название компании.' : null,
            'cal_phone' => strlen((string) preg_replace('/\D+/', '', $text)) < 10 ? 'Похоже, в номере не хватает цифр. Пример: +7 900 123-45-67.' : null,
            'cal_address' => mb_strlen($text) < 10 ? 'Напишите адрес подробнее: город, улица, дом.' : null,
            default => null,
        };
        if (! isset(self::STEPS[$step])) {
            $this->start($chat, $say);

            return;
        }
        if ($error !== null) {
            $say($error);

            return;
        }
        $draft = (array) $chat->stateGet('cal', []);
        $draft[self::STEPS[$step][0]] = mb_substr($text, 0, $step === 'cal_address' ? 1000 : 255);
        $chat->stateSet('cal', $draft);
        $chat->save();
        $this->next($chat, $step, $say);
    }

    private function check(MessengerChat $chat, Closure $say): void
    {
        if (! $this->isOpen()) {
            $this->start($chat, $say);

            return;
        }
        $subscribed = $this->checker->isSubscribed($chat->channel, (string) $chat->external_user_id);
        if ($subscribed === null) {
            $say('Не получилось проверить подписку — попробуйте ещё раз через минуту.', [[['text' => '✅ Я подписался', 'data' => 'cal_check']]]);

            return;
        }
        if (! $subscribed) {
            $buttons = [];
            if ($url = $this->checker->channelUrl($chat->channel)) {
                $buttons[] = [['text' => '📣 Подписаться на канал', 'url' => $url]];
            }
            $buttons[] = [['text' => '✅ Я подписался', 'data' => 'cal_check']];
            $say('Пока не вижу вашей подписки на канал. Подпишитесь и нажмите «Я подписался» ещё раз.', $buttons);

            return;
        }

        $chat->stateSet('cal', ['subscribed_at' => now()->toIso8601String()]);
        $chat->save();
        $say("Спасибо за подписку! Чтобы отправить календарь, нам нужны имя, компания, телефон и адрес получателя.\n\n"
            .'Нажимая «Согласен», вы соглашаетесь на обработку этих данных для отправки календаря.',
            [[['text' => '✅ Согласен', 'data' => 'cal_consent']], [['text' => 'Отмена', 'data' => 'menu']]]);
    }

    private function askStep(MessengerChat $chat, string $step, Closure $say): void
    {
        $draft = (array) $chat->stateGet('cal', []);
        if ($step === 'cal_name' && empty($draft['consent_at'])) {
            if (empty($draft['subscribed_at'])) {
                $this->start($chat, $say);

                return;
            }
            $draft['consent_at'] = now()->toIso8601String();
            $chat->stateSet('cal', $draft);
        }
        $chat->stateSet('awaiting', $step);
        $chat->save();

        // Уже знаем из профиля бота или прошлой записи — можно оставить одной кнопкой.
        $known = $this->known($chat, $step);
        $buttons = $known !== null ? [[['text' => 'Оставить: '.mb_strimwidth($known, 0, 40, '…'), 'data' => 'cal_keep']]] : [];
        if ($step === 'cal_phone') {
            array_unshift($buttons, [['text' => '📱 Отправить номер', 'contact' => true]]);
        }
        $say(self::STEPS[$step][1], $buttons);
    }

    private function keep(MessengerChat $chat, Closure $say): void
    {
        $step = (string) $chat->stateGet('awaiting', '');
        $known = isset(self::STEPS[$step]) ? $this->known($chat, $step) : null;
        if ($known === null) {
            $this->start($chat, $say);

            return;
        }
        $this->onAnswer($chat, $step, $known, $say);
    }

    private function next(MessengerChat $chat, string $step, Closure $say): void
    {
        $steps = array_keys(self::STEPS);
        $i = array_search($step, $steps, true);
        if ($i !== false && isset($steps[$i + 1])) {
            $this->askStep($chat, $steps[$i + 1], $say);

            return;
        }
        $chat->stateForget('awaiting');
        $chat->save();
        $say("Проверьте, всё ли верно:\n\n".$this->summary((array) $chat->stateGet('cal', [])),
            [[['text' => '✅ Всё верно', 'data' => 'cal_ok']], [['text' => '✏️ Заполнить заново', 'data' => 'cal_edit']]]);
    }

    private function save(MessengerChat $chat, Closure $say): void
    {
        $draft = (array) $chat->stateGet('cal', []);
        if (empty($draft['contact_name']) || empty($draft['address']) || empty($draft['subscribed_at'])) {
            $this->start($chat, $say);

            return;
        }
        CalendarGiftRecipient::query()->updateOrCreate(
            ['campaign' => $this->campaign(), 'channel' => $chat->channel->value, 'external_user_id' => (string) $chat->external_user_id],
            [
                'messenger_chat_id' => $chat->id,
                'contact_name' => $draft['contact_name'],
                'company_name' => $draft['company_name'] ?? null,
                'phone' => $draft['phone'] ?? null,
                'address' => $draft['address'],
                'organization_id' => $chat->organization_id,
                'status' => CalendarGiftRecipient::STATUS_NEW,
                'consent_at' => $draft['consent_at'] ?? now(),
                'subscribed' => true,
                'subscription_checked_at' => $draft['subscribed_at'],
            ],
        );
        $chat->stateForget('awaiting', 'cal');
        $chat->save();
        $say('Готово! Вы в списке получателей календаря '.$this->campaign().'. Отправим его по указанному адресу — '
            .'когда календарь будет в пути, напишем здесь. Оставайтесь с нами в канале 🙂', [[['text' => 'Меню', 'data' => 'menu']]]);
    }

    /** «Изменить данные» — анкета заново; подписку проверяем снова (могла пропасть). */
    private function edit(MessengerChat $chat, Closure $say): void
    {
        $this->check($chat, $say);
    }

    private function recipient(MessengerChat $chat): ?CalendarGiftRecipient
    {
        return CalendarGiftRecipient::query()->where('campaign', $this->campaign())
            ->where('channel', $chat->channel->value)->where('external_user_id', (string) $chat->external_user_id)
            ->where('status', '!=', CalendarGiftRecipient::STATUS_CANCELLED)->first();
    }

    /** Значение шага из прошлой записи или профиля бота (имя, телефон, компания). */
    private function known(MessengerChat $chat, string $step): ?string
    {
        $field = self::STEPS[$step][0];
        $value = $this->recipient($chat)?->{$field} ?? match ($field) {
            'contact_name' => $chat->contact_name,
            'company_name' => $chat->company_name ?: $chat->organization?->name,
            'phone' => $chat->phone,
            default => null,
        };
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /** @param  array<string, mixed>  $d */
    private function summary(array $d): string
    {
        $e = fn ($v) => e((string) ($v ?? '—'));

        return '👤 '.$e($d['contact_name'] ?? null)."\n🏢 ".$e($d['company_name'] ?? null)."\n📞 ".$e($d['phone'] ?? null)."\n📍 ".$e($d['address'] ?? null);
    }
}
