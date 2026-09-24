<?php

namespace App\Services\Marketing;

use App\Models\MediaChannel;
use App\Models\MediaPublication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Размещение материала на площадке.
 *
 * Сейчас умеем два канала, у которых есть честный API записи:
 *   ВКонтакте  — wall.post от имени сообщества (токен сообщества, права wall);
 *   Telegram   — sendMessage ботом-администратором канала.
 *
 * У Дзена открытого API публикаций нет: туда материалы уезжают либо руками,
 * либо импортом по RSS. Поэтому канал «Дзен» остаётся с ручной отметкой о
 * публикации, и это не недоделка, а ограничение площадки.
 *
 * Публикация необратима. Поэтому:
 *   — вызов всегда явный: кнопка человека либо канал с включённой автопубликацией;
 *   — повторно уже опубликованный материал не отправляем;
 *   — любая ошибка площадки пишется в канал (last_error) и видна в разделе.
 */
class MediaPublisherService
{
    /** Версия API ВК: фиксируем, чтобы ответы не менялись под нами. */
    public const VK_API_VERSION = '5.199';

    private const TIMEOUT = 20;

    /** Столько картинок вмещает альбом Telegram; ВК ограничивает стену десятью вложениями. */
    private const MAX_PHOTOS = 10;

    /** Telegram принимает картинку по ссылке до 5 МБ — больше не отправляем. */
    private const MAX_PHOTO_BYTES = 5 * 1024 * 1024;

    /**
     * @return array{ok: bool, message: string, url: ?string}
     */
    public function publish(MediaPublication $publication): array
    {
        $channel = $publication->channel;
        if ($channel === null) {
            return ['ok' => false, 'message' => 'У материала не указан канал.', 'url' => null];
        }
        if ($publication->isPublished()) {
            return ['ok' => false, 'message' => 'Материал уже опубликован.', 'url' => $publication->url];
        }
        if (trim((string) $publication->body) === '') {
            return ['ok' => false, 'message' => 'Пустой материал публиковать нечего.', 'url' => null];
        }
        if (! $channel->isPostable()) {
            return [
                'ok' => false,
                'message' => 'В «'.$channel->kindLabel().'» система публиковать не умеет — разместите руками и отметьте ссылкой.',
                'url' => null,
            ];
        }
        if (! $channel->isConnected()) {
            return ['ok' => false, 'message' => 'У канала не заполнен доступ: нужен токен и адрес места публикации.', 'url' => null];
        }

        // Артикулы в тексте превращаем в ссылки на карточки товара: читателю
        // из ленты идти больше некуда, а по артикулу он искать не станет.
        ['text' => $text, 'html' => $html] = app(MediaLinkService::class)
            ->linkify($this->text($publication), (string) $channel->kind);

        $images = $this->usableImages($publication->images());

        $res = match ($channel->kind) {
            'vk' => $this->postToVk($channel, $text, $images),
            'telegram' => $this->postToTelegram($channel, $text, $images, $html),
            default => ['ok' => false, 'message' => 'Канал не поддержан.', 'url' => null, 'external_id' => null],
        };

        if (! $res['ok']) {
            $channel->forceFill(['last_error' => mb_substr($res['message'], 0, 500)])->save();
            Log::warning('MediaPublisherService: publish failed', [
                'publication_id' => $publication->id,
                'channel_id' => $channel->id,
                'kind' => $channel->kind,
                'error' => $res['message'],
            ]);

            return ['ok' => false, 'message' => $res['message'], 'url' => null];
        }

        $publication->forceFill([
            'status' => 'published',
            'published_at' => now(),
            'url' => $res['url'] ?? $publication->url,
            'external_id' => $res['external_id'] ?? null,
        ])->save();

        $channel->forceFill(['last_posted_at' => now(), 'last_error' => null])->save();
        $this->recordMirrors($publication, $channel);

        Log::info('MediaPublisherService: published', [
            'publication_id' => $publication->id,
            'channel_id' => $channel->id,
            'kind' => $channel->kind,
            'url' => $res['url'] ?? null,
        ]);

        return ['ok' => true, 'message' => 'Опубликовано.', 'url' => $res['url'] ?? null];
    }

    /**
     * Проверка связи без публикации: отвечает ли площадка на наш токен.
     *
     * @return array{ok: bool, message: string}
     */
    public function check(MediaChannel $channel): array
    {
        if (! $channel->isPostable()) {
            return ['ok' => false, 'message' => 'Для этого канала автопубликации нет — проверять нечего.'];
        }
        if (! $channel->isConnected()) {
            return ['ok' => false, 'message' => 'Заполните токен и адрес места публикации.'];
        }

        try {
            if ($channel->kind === 'vk') {
                $groupId = ltrim((string) $channel->secret('owner_id'), '-');
                $r = Http::timeout(self::TIMEOUT)->asForm()->post('https://api.vk.com/method/groups.getById', [
                    'group_id' => $groupId,
                    'access_token' => $channel->secret('access_token'),
                    'v' => self::VK_API_VERSION,
                ])->json();

                if (isset($r['error'])) {
                    return ['ok' => false, 'message' => 'ВК: '.($r['error']['error_msg'] ?? 'ошибка')];
                }
                $name = $r['response']['groups'][0]['name'] ?? $r['response'][0]['name'] ?? 'сообщество';

                return ['ok' => true, 'message' => 'ВК отвечает: '.$name.'.'];
            }

            $r = Http::timeout(self::TIMEOUT)
                ->get('https://api.telegram.org/bot'.$channel->secret('bot_token').'/getChat', [
                    'chat_id' => $channel->secret('chat_id'),
                ])->json();

            if (! ($r['ok'] ?? false)) {
                return ['ok' => false, 'message' => 'Telegram: '.($r['description'] ?? 'ошибка')];
            }

            return ['ok' => true, 'message' => 'Telegram отвечает: '.($r['result']['title'] ?? 'канал').'.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Площадка не ответила: '.$e->getMessage()];
        }
    }

    /**
     * Отметить публикацию на площадках-зеркалах.
     *
     * Дзен, привязанный к телеграм-каналу, забирает пост сам — своей кнопки
     * «опубликовать» у него нет и быть не может. Но в учёте публикация там
     * состоялась, иначе канал вечно выглядит молчащим. Ссылку не выдумываем:
     * её проставит человек, когда пост появится.
     */
    private function recordMirrors(MediaPublication $source, MediaChannel $channel): void
    {
        foreach ($channel->mirrors()->where('is_active', true)->get() as $mirror) {
            MediaPublication::create([
                'media_topic_id' => $source->media_topic_id,
                'media_channel_id' => $mirror->id,
                'title' => $source->title,
                'body' => $source->body,
                'status' => 'published',
                'planned_for' => $source->planned_for,
                'published_at' => now(),
                'subject_key' => $source->subject_key,
                'model' => $source->model,
                'created_by_user_id' => $source->created_by_user_id,
            ]);

            $mirror->forceFill(['last_posted_at' => now()])->save();
        }
    }

    /**
     * Числовой id сообщества из того, что человек ввёл.
     *
     * Руками id найти неудобно: у сообщества с коротким адресом его вообще не
     * видно. Поэтому принимаем всё, что есть под рукой — ссылку, короткое имя,
     * club123456 или сам номер, — и добираем недостающее у ВК.
     *
     * @return array{ok: bool, id: ?string, message: string}
     */
    public function resolveVkOwnerId(string $token, string $input): array
    {
        $raw = trim($input);
        if ($raw === '') {
            return ['ok' => false, 'id' => null, 'message' => 'Пустой адрес сообщества.'];
        }

        // vk.com/club123 · https://vk.com/myzip · @myzip — берём последний кусок.
        $slug = preg_replace('~^https?://~i', '', $raw) ?? $raw;
        $slug = preg_replace('~^(m\.)?vk\.(com|ru)/~i', '', $slug) ?? $slug;
        $slug = ltrim(trim(explode('?', $slug)[0], "/ \t\n\r"), '@');

        if (preg_match('~^-?\d+$~', $slug) === 1) {
            return ['ok' => true, 'id' => ltrim($slug, '-'), 'message' => 'id принят как есть.'];
        }
        if (preg_match('~^(club|public|event)(\d+)$~i', $slug, $m) === 1) {
            return ['ok' => true, 'id' => $m[2], 'message' => 'id взят из адреса.'];
        }

        try {
            $r = Http::timeout(self::TIMEOUT)->asForm()->post('https://api.vk.com/method/groups.getById', [
                'group_id' => $slug,
                'access_token' => $token,
                'v' => self::VK_API_VERSION,
            ])->json();
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'message' => 'ВК не ответил: '.$e->getMessage()];
        }

        if (isset($r['error'])) {
            return ['ok' => false, 'id' => null, 'message' => 'ВК: '.($r['error']['error_msg'] ?? 'ошибка')];
        }

        $group = $r['response']['groups'][0] ?? $r['response'][0] ?? null;
        $id = $group['id'] ?? null;
        if (! $id) {
            return ['ok' => false, 'id' => null, 'message' => 'ВК не вернул id по этому адресу.'];
        }

        return ['ok' => true, 'id' => (string) $id, 'message' => 'Сообщество «'.($group['name'] ?? '').'», id '.$id.'.'];
    }

    /** Заголовок отдельной строкой: у постов в ленте своей шапки нет. */
    private function text(MediaPublication $publication): string
    {
        $title = trim((string) $publication->title);
        $body = trim((string) $publication->body);

        $text = $title !== '' && ! str_starts_with($body, $title)
            ? $title."\n\n".$body
            : $body;

        return self::forFeed($text);
    }

    /**
     * Текст для ленты соцсети.
     *
     * Ни ВК, ни Telegram (в режиме plain text) markdown не разбирают: звёздочки
     * и решётки читатель видит как есть — первая же публикация вышла с «**Винт**».
     * Промпт это запрещает, но запрет модели — не гарантия, поэтому чистим перед
     * отправкой: разметка снимается, маркеры списка приводятся к «•», пустые
     * строки не громоздятся.
     */
    public static function forFeed(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // **жирный** / __жирный__ / *курсив* / `код` — оставляем содержимое.
        $text = preg_replace('~\*\*(.+?)\*\*~us', '$1', $text) ?? $text;
        $text = preg_replace('~__(.+?)__~us', '$1', $text) ?? $text;
        $text = preg_replace('~(?<!\S)\*(\S.*?\S|\S)\*(?!\S)~us', '$1', $text) ?? $text;
        $text = preg_replace('~`{1,3}(.+?)`{1,3}~us', '$1', $text) ?? $text;

        // Заголовки ### и цитаты > в ленте выглядят мусором.
        $text = preg_replace('~^\s{0,3}#{1,6}\s*~um', '', $text) ?? $text;
        $text = preg_replace('~^\s{0,3}>\s?~um', '', $text) ?? $text;

        // Маркеры списка к единому виду, нумерованные не трогаем.
        $text = preg_replace('~^\s*[-–—*·]\s+~um', '• ', $text) ?? $text;

        // Markdown-ссылки [текст](url) → «текст — url».
        $text = preg_replace('~\[([^\]]+)\]\((https?://[^)\s]+)\)~u', '$1 — $2', $text) ?? $text;

        $text = preg_replace('~\n{3,}~u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array{ok: bool, message: string, url: ?string, external_id: ?string}
     */
    private function postToVk(MediaChannel $channel, string $text, array $images = []): array
    {
        // owner_id сообщества отрицательный: -123456. Принимаем и с минусом, и без.
        $ownerId = '-'.ltrim((string) $channel->secret('owner_id'), '-');
        $attachments = $images !== [] ? $this->uploadVkPhotos($channel, $ownerId, $images) : [];

        try {
            $r = Http::timeout(self::TIMEOUT)->asForm()->post('https://api.vk.com/method/wall.post', [
                'owner_id' => $ownerId,
                'from_group' => 1,
                'message' => $text,
                'attachments' => $attachments !== [] ? implode(',', $attachments) : null,
                'access_token' => $channel->secret('access_token'),
                'v' => self::VK_API_VERSION,
            ])->json();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'ВК не ответил: '.$e->getMessage(), 'url' => null, 'external_id' => null];
        }

        if (isset($r['error'])) {
            return [
                'ok' => false,
                'message' => 'ВК: '.($r['error']['error_msg'] ?? 'ошибка').' (код '.($r['error']['error_code'] ?? '?').')',
                'url' => null,
                'external_id' => null,
            ];
        }

        $postId = (string) ($r['response']['post_id'] ?? '');
        if ($postId === '') {
            return ['ok' => false, 'message' => 'ВК ответил без номера записи.', 'url' => null, 'external_id' => null];
        }

        return [
            'ok' => true,
            'message' => 'Опубликовано.',
            'url' => 'https://vk.com/wall'.$ownerId.'_'.$postId,
            'external_id' => $postId,
        ];
    }

    /**
     * Отсеять картинки, на которых площадка споткнётся.
     *
     * Альбом Telegram атомарен: одна нерабочая ссылка — и не уходит весь пост.
     * Поэтому перед отправкой спрашиваем у каждой картинки заголовки: это
     * вообще изображение и влезает ли оно в лимит приёма по ссылке (5 МБ).
     * Наши каталожные фото отдаются уже сжатыми (1024×900, 40–100 КБ), так что
     * проверка почти всегда проходит — она про битые ссылки, а не про вес.
     *
     * @param  list<string>  $images
     * @return list<string>
     */
    private function usableImages(array $images): array
    {
        $out = [];

        foreach (array_slice($images, 0, self::MAX_PHOTOS) as $url) {
            try {
                $head = Http::timeout(8)->head($url);
                $type = (string) $head->header('Content-Type');
                $size = (int) $head->header('Content-Length');

                if (! $head->successful() || ! str_starts_with($type, 'image/')) {
                    Log::info('MediaPublisherService: image skipped, not an image', ['url' => $url, 'type' => $type]);

                    continue;
                }
                if ($size > self::MAX_PHOTO_BYTES) {
                    Log::info('MediaPublisherService: image skipped, too heavy', ['url' => $url, 'bytes' => $size]);

                    continue;
                }

                $out[] = $url;
            } catch (\Throwable $e) {
                Log::info('MediaPublisherService: image skipped, unreachable', ['url' => $url, 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /**
     * Фотографии для стены ВК: скачать с нашего сайта и залить на их сервер.
     *
     * Ссылку на картинку ВК не принимает — только загруженное фото. Порядок
     * жёсткий: получить адрес сервера, отправить файл, сохранить, получить
     * attachment вида photo-123_456. Одна неудачная картинка не должна ронять
     * публикацию, поэтому каждую ведём отдельно и молча пропускаем сбойные.
     *
     * @param  list<string>  $images
     * @return list<string>
     */
    private function uploadVkPhotos(MediaChannel $channel, string $ownerId, array $images): array
    {
        $token = (string) $channel->secret('access_token');
        $groupId = ltrim($ownerId, '-');
        $out = [];

        foreach (array_slice($images, 0, self::MAX_PHOTOS) as $url) {
            try {
                $server = Http::timeout(self::TIMEOUT)->asForm()
                    ->post('https://api.vk.com/method/photos.getWallUploadServer', [
                        'group_id' => $groupId,
                        'access_token' => $token,
                        'v' => self::VK_API_VERSION,
                    ])->json();

                $uploadUrl = $server['response']['upload_url'] ?? null;
                if ($uploadUrl === null) {
                    continue;
                }

                $file = Http::timeout(self::TIMEOUT)->get($url);
                if (! $file->successful() || $file->body() === '') {
                    continue;
                }

                $uploaded = Http::timeout(self::TIMEOUT)
                    ->attach('photo', $file->body(), 'photo.jpg')
                    ->post($uploadUrl)
                    ->json();

                $saved = Http::timeout(self::TIMEOUT)->asForm()
                    ->post('https://api.vk.com/method/photos.saveWallPhoto', [
                        'group_id' => $groupId,
                        'photo' => $uploaded['photo'] ?? '',
                        'server' => $uploaded['server'] ?? '',
                        'hash' => $uploaded['hash'] ?? '',
                        'access_token' => $token,
                        'v' => self::VK_API_VERSION,
                    ])->json();

                $photo = $saved['response'][0] ?? null;
                if ($photo === null) {
                    continue;
                }
                $out[] = 'photo'.$photo['owner_id'].'_'.$photo['id'];
            } catch (\Throwable $e) {
                Log::warning('MediaPublisherService: vk photo upload failed (non-fatal)', [
                    'channel_id' => $channel->id,
                    'photo_url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $images
     * @return array{ok: bool, message: string, url: ?string, external_id: ?string}
     */
    private function postToTelegram(MediaChannel $channel, string $text, array $images = [], bool $html = false): array
    {
        $api = 'https://api.telegram.org/bot'.$channel->secret('bot_token').'/';
        $chatId = $channel->secret('chat_id');
        $mode = $html ? 'HTML' : null;

        try {
            // С картинками пост уходит альбомом. Подпись у альбома всего 1024
            // знака — если текст длиннее, шлём альбом без подписи, а текст
            // отдельным сообщением следом: обрезать материал ради формата нельзя.
            // Считаем по видимому тексту: html-разметка ссылок в лимит не идёт.
            if ($images !== []) {
                $fits = mb_strlen($html ? strip_tags($text) : $text) <= 1024;
                $media = [];
                $request = Http::timeout(self::TIMEOUT * 3);

                // Картинки грузим файлами, а не ссылками. По ссылке Telegram
                // качает их сам со своей стороны и на седьмой картинке ответил
                // WEBPAGE_CURL_FAILED — весь альбом отвалился, пост ушёл голым.
                // Своей загрузкой мы не зависим ни от редиректов нашего сайта,
                // ни от того, пустит ли он телеграмовский робот.
                foreach (array_slice($images, 0, self::MAX_PHOTOS) as $i => $url) {
                    $file = Http::timeout(self::TIMEOUT)->get($url);
                    if (! $file->successful() || $file->body() === '') {
                        continue;
                    }

                    $name = 'photo'.$i;
                    $request = $request->attach($name, $file->body(), $name.'.jpg');
                    $media[] = array_filter([
                        'type' => 'photo',
                        'media' => 'attach://'.$name,
                        'caption' => $media === [] && $fits ? $text : null,
                        'parse_mode' => $media === [] && $fits ? $mode : null,
                    ]);
                }

                $album = $media === []
                    ? ['ok' => false, 'description' => 'ни одна картинка не скачалась']
                    : $request->post($api.'sendMediaGroup', [
                        'chat_id' => $chatId,
                        'media' => json_encode($media, JSON_UNESCAPED_UNICODE),
                    ])->json();

                if (! ($album['ok'] ?? false)) {
                    Log::warning('MediaPublisherService: telegram album failed, falling back to text', [
                        'channel_id' => $channel->id,
                        'error' => $album['description'] ?? 'unknown',
                    ]);
                } elseif ($fits) {
                    $first = $album['result'][0]['message_id'] ?? null;

                    return $this->telegramResult($channel, $first);
                }
            }

            $r = Http::timeout(self::TIMEOUT)
                ->post($api.'sendMessage', array_filter([
                    'chat_id' => $chatId,
                    'text' => mb_substr($text, 0, 4096),
                    'parse_mode' => $mode,
                    'disable_web_page_preview' => true,
                ]))->json();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Telegram не ответил: '.$e->getMessage(), 'url' => null, 'external_id' => null];
        }

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'Telegram: '.($r['description'] ?? 'ошибка'), 'url' => null, 'external_id' => null];
        }

        return $this->telegramResult($channel, $r['result']['message_id'] ?? null);
    }

    /**
     * Ссылка на пост канала собирается из короткого имени и номера сообщения;
     * у приватного канала её нет — тогда остаётся только id.
     *
     * @return array{ok: bool, message: string, url: ?string, external_id: ?string}
     */
    private function telegramResult(MediaChannel $channel, int|string|null $messageId): array
    {
        $messageId = (string) ($messageId ?? '');
        $chat = (string) $channel->secret('chat_id');
        $url = str_starts_with($chat, '@') && $messageId !== ''
            ? 'https://t.me/'.ltrim($chat, '@').'/'.$messageId
            : null;

        return ['ok' => true, 'message' => 'Опубликовано.', 'url' => $url, 'external_id' => $messageId ?: null];
    }
}
