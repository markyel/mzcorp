<?php

namespace App\Services\Marketing;

use App\Jobs\Marketing\MakeMediaCoverJob;
use App\Models\MediaChannel;
use App\Models\MediaProfileEntry;
use App\Models\MediaPublication;
use App\Models\MediaTopic;
use App\Models\User;
use App\Prompts\Marketing\WriteMaterialPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Черновик публикации по теме медиаплана.
 *
 * Материал пишется из двух источников: медиапрофиль (кто мы и как говорим) и
 * данные темы. Для тем, у которых источник — наши же данные (новинки каталога,
 * снижение цен, поступления, частые уточнения по заявкам), факты подставляет
 * MediaDataService: модель их только излагает и не имеет права дополнять.
 *
 * Результат — черновик со статусом draft. Публикация не происходит: её делает
 * человек, а автопостинг — отдельный шаг, у которого свои ключи и своя цена
 * ошибки.
 */
class MediaMaterialService
{
    /** Посты об ассортименте: в конце — как заказать, одинаково в каждом. */
    public const ORDER_FOOTER_SOURCES = ['catalog_new', 'catalog_price', 'stock_arrivals'];

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly MediaDataService $data,
        private readonly MediaCoverService $covers,
        private readonly \App\Services\Bot\BotSettings $bots,
    ) {}

    /**
     * Концовка поста об ассортименте: как заказать — сайт, телефон и боты
     * заявок. Раньше её писала модель, и выходило по-разному («свяжитесь через
     * сайт или по телефону», «заказывайте прямо сейчас!»); заказчик (14.10.2026)
     * попросил добавить ботов Telegram и MAX — теперь концовка одна, из
     * настроек, а модель свою не пишет (WriteMaterialPrompt::genreHint).
     * Не подключённый бот в концовку не попадает.
     */
    public function orderFooter(): string
    {
        $phone = trim((string) config('services.company.phone', ''));
        $bots = array_filter([
            ($u = $this->bots->publicUrl(\App\Enums\MessengerChannel::Telegram)) ? 'Telegram '.$u : null,
            ($u = $this->bots->publicUrl(\App\Enums\MessengerChannel::Max)) ? 'MAX '.$u : null,
        ]);

        $ways = 'через сайт'.($phone !== '' ? ', по телефону '.$phone : '');
        if ($bots === []) {
            return 'Для заказа свяжитесь с нами '.$ways.'.';
        }

        return 'Для заказа свяжитесь с нами '.$ways.' или через бота заявок: '.implode(', ', $bots).'. '
            .'В боте можно узнать цену по M-артикулу и отправить заявку текстом, фото или файлом.';
    }

    /**
     * Та же концовка, свёрстанная под площадку: отдельными строками со значками, в Telegram и MAX
     * ссылки — словами (без длинных адресов), во ВКонтакте — адресами (разметки на стене нет).
     */
    public function orderFooterFor(string $kind, bool $html): string
    {
        $phone = trim((string) config('services.company.phone', ''));
        $tg = $this->bots->publicUrl(\App\Enums\MessengerChannel::Telegram);
        $max = $this->bots->publicUrl(\App\Enums\MessengerChannel::Max);
        $site = rtrim((string) config('services.marketing.news.site_url', ''), '/');
        $siteUrl = $site !== '' ? $site.'/?'.http_build_query(['utm_source' => $kind, 'utm_medium' => 'social', 'utm_campaign' => 'media_plan']) : '';
        $siteName = preg_replace('~^https?://(www\.)?~i', '', $site);
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $a = fn (string $url, string $label) => $html ? '<a href="'.$e($url).'">'.$e($label).'</a>' : $label.': '.$url;
        $what = 'цена по M-артикулу, заявка текстом, фото или файлом';

        $lines = [$html ? '🛒 <b>Как заказать</b>' : '🛒 Как заказать'];
        if ($phone !== '') {
            $lines[] = '📞 '.($html ? $e($phone) : $phone);
        }
        $bots = array_values(array_filter([$tg ? $a($tg, 'Telegram') : null, $max ? $a($max, 'MAX') : null]));
        if ($bots !== []) {
            $lines[] = $html
                ? '🤖 Бот заявок: '.implode(' · ', $bots).' — '.$what
                : '🤖 Бот заявок — '.$what.":\n".implode("\n", $bots);
        }
        if ($site !== '') {
            $lines[] = '🌐 '.($html ? $a($siteUrl, (string) $siteName) : $site);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{ok: bool, publication: ?MediaPublication, message: string}
     */
    public function draft(MediaTopic $topic, MediaChannel $channel, ?User $author, ?string $note = null, bool $coverNow = true): array
    {
        $note = trim((string) $note);

        // Обзор недели — статья со структурой (разделы, позиции с фото и
        // ценами из каталога), а не пост: у неё свой промпт и своя сборка.
        if ($topic->source === 'weekly_roundup') {
            return app(WeeklyRoundupService::class)->draft($topic, $channel, $author, $coverNow);
        }

        // Новость без повода — это выдумка. Бриф темы говорит, КАК писать
        // новости, но не ЧТО произошло: про выставку, склад или изменение в
        // работе система знать не может, и сочинять ей тут нечего.
        if ($topic->source === 'news' && $note === '') {
            return [
                'ok' => false,
                'publication' => null,
                'message' => 'Напишите в поле рядом, что произошло: событие, дата, место, участники. '
                    .'Без повода новость получится выдуманной.',
            ];
        }

        $profile = MediaProfileEntry::asBrief();
        if ($profile === '') {
            return ['ok' => false, 'publication' => null, 'message' => 'Медиапрофиль пуст — писать не от чего.'];
        }

        // Факты для тем «из наших данных». Пусто — тема пишется по брифу.
        // Ключ выпуска есть у серийных тем: по нему в следующий раз берётся
        // следующая категория, а не та же самая.
        $facts = $this->data->factsWithKey($topic);
        ['key' => $subjectKey, 'facts' => $data] = $facts;
        $links = $facts['links'] ?? [];

        // То, что редактор написал руками, идёт первым и с пометкой: это факты
        // сегодняшнего повода, и они важнее любых посчитанных.
        if ($note !== '') {
            $data = "ЧТО ПРОИЗОШЛО (со слов редактора, это главные факты материала):\n".$note
                .($data !== '' ? "\n\n".$data : '');
        }

        if ($data === '' && $this->data->isDataDriven($topic)) {
            return [
                'ok' => false,
                'publication' => null,
                'message' => 'По этой теме сейчас нет данных для материала — публиковать нечего. '
                    .$this->data->sourceNote((string) $topic->source),
            ];
        }

        $model = (string) config('services.openai.media_profile_model', 'gpt-4o');

        try {
            $response = $this->openai->chat(
                [
                    ['role' => 'system', 'content' => WriteMaterialPrompt::systemMessage()],
                    ['role' => 'user', 'content' => WriteMaterialPrompt::userMessage(
                        $profile,
                        $channel->name.' ('.$channel->kindLabel().')',
                        (string) $channel->kind,
                        (string) $topic->title,
                        $topic->brief,
                        $data,
                        WriteMaterialPrompt::genreHint((string) $topic->source),
                    )],
                ],
                $model,
                ['response_format' => ['type' => 'json_object'], 'temperature' => 0.4],
            );
        } catch (\Throwable $e) {
            Log::error('MediaMaterialService: модель не ответила', [
                'topic_id' => $topic->id,
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'publication' => null, 'message' => 'Не удалось написать материал: '.$e->getMessage()];
        }

        $parsed = json_decode((string) ($response['content'] ?? ''), true);
        if (! is_array($parsed) || trim((string) ($parsed['body'] ?? '')) === '') {
            return ['ok' => false, 'publication' => null, 'message' => 'Модель ответила не по форме — попробуйте ещё раз.'];
        }

        // Для ленты соцсети сразу приводим текст к тому виду, в каком он уйдёт
        // на площадку: редактор должен показывать пост, а не его исходник с
        // разметкой, которую ВК и Telegram покажут как есть.
        $body = $this->data->resolveLinks(trim((string) $parsed['body']), $links);
        if ($channel->isPostable()) {
            $body = MediaPublisherService::forFeed($body);
            if (in_array($topic->source, self::ORDER_FOOTER_SOURCES, true)) {
                $body = rtrim($body)."\n\n".$this->orderFooter();
            }
        }

        $title = mb_substr(trim((string) ($parsed['title'] ?? $topic->title)), 0, 300);

        // Пост в площадку, куда публикуем сами, — одна анонсовая обложка по
        // содержанию вместо фото позиций (те чаще всего — снимки клиентских
        // деталей из заявок). Не вышло — пост без картинки: чужие фото хуже,
        // чем никаких.
        $coverNote = '';
        $coverLater = false;
        if (MediaCoverService::enabled() && $channel->isPostable() && ! $coverNow) {
            // Из медиаплана: обложка из деталей рисуется 2–4 минуты — в фоне.
            $images = [];
            $coverLater = true;
            $coverNote = ' Обложка готовится, 2–4 минуты — появится в карточке сама.';
        } elseif (MediaCoverService::enabled() && $channel->isPostable()) {
            $cover = $this->covers->make($title, $body, (string) $topic->source, $subjectKey);
            $images = $cover['ok'] ? [$cover['url']] : [];
            $coverNote = $cover['ok'] ? '' : ' Обложку сделать не удалось ('.$cover['message'].') — пост без картинки, можно повторить кнопкой «Новая обложка».';
        } else {
            // Фото позиций — редактор увидит их в карточке и уберёт лишние.
            $images = $this->data->photosFor($topic, $subjectKey, $body);
        }

        $publication = MediaPublication::create([
            'media_topic_id' => $topic->id,
            'media_channel_id' => $channel->id,
            'title' => $title,
            'body' => $body,
            'subject_key' => $subjectKey,
            'image_urls' => $images ?: null,
            'status' => 'draft',
            'planned_for' => $topic->next_due_on ?? now()->toDateString(),
            'model' => $model,
            'created_by_user_id' => $author?->id,
        ]);
        if ($coverLater) {
            MakeMediaCoverJob::queueFor($publication);
        }

        $notes = trim((string) ($parsed['notes'] ?? ''));

        return [
            'ok' => true,
            'publication' => $publication,
            'message' => 'Черновик готов.'.$coverNote.($notes !== '' ? ' Редактору: '.$notes : ''),
        ];
    }
}
