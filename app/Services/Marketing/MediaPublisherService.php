<?php

namespace App\Services\Marketing;

use App\Models\MediaChannel;
use App\Models\MediaPublication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Размещение материала на площадке.
 *
 * Умеем каналы, у которых есть честный API записи:
 *   ВКонтакте  — wall.post от имени сообщества (токен сообщества, права wall);
 *   Telegram   — sendMessage ботом-администратором канала;
 *   MAX        — POST /messages ботом-администратором канала (Bot API, config services.max).
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

    /** Попыток при сбое соединения с площадкой и пауза между ними. */
    private const CONNECT_ATTEMPTS = 3;

    private const CONNECT_RETRY_PAUSE_MS = 3000;

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

        // Своя лента: «опубликовать» — значит выставить страницу /news и пункт
        // RSS. Ни внешнего вызова, ни ключей; адрес публикации — страница статьи.
        if ($channel->kind === 'rss') {
            $publication->forceFill(['status' => 'published', 'published_at' => now()])->save();
            $publication->forceFill(['url' => $publication->newsUrl(), 'external_id' => (string) $publication->id])->save();
            $channel->forceFill(['last_posted_at' => now(), 'last_error' => null])->save();
            $publication->topic?->advanceAfter($publication);

            return ['ok' => true, 'message' => 'Опубликовано в ленте.', 'url' => $publication->url];
        }

        // Артикулы в тексте превращаем в ссылки на карточки товара: читателю
        // из ленты идти больше некуда, а по артикулу он искать не станет.
        ['text' => $text, 'html' => $html] = app(MediaLinkService::class)
            ->linkify($this->text($publication), (string) $channel->kind);

        $images = $this->usableImages($publication->images());

        $res = match ($channel->kind) {
            'vk' => $this->postToVk($channel, $text, $images),
            'telegram' => $this->postToTelegram($channel, $text, $images, $html),
            'max' => $this->postToMax($channel, $text, $images, $html),
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

        // Срок темы двигаем здесь, а не в вызывающем коде: кнопка «опубликовать»
        // срок не двигала, и тема после выпуска висела «просроченной».
        $publication->topic?->advanceAfter($publication);

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
        if ($channel->kind === 'max' && ! $channel->isConnected() && (string) $channel->secret('bot_token') !== '') {
            $found = $this->discoverMaxChat($channel);
            if (! $found['ok']) {
                return $found;
            }
        }
        if (! $channel->isConnected()) {
            return ['ok' => false, 'message' => 'Заполните токен и адрес места публикации.'];
        }

        if ($channel->kind === 'max') {
            return $this->checkMax($channel);
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
            return ['ok' => false, 'message' => 'Площадка не ответила: '.self::redactSecrets($e->getMessage())];
        }
    }

    /**
     * Ошибка HTTP-клиента несёт адрес запроса, а у Telegram токен бота — в
     * адресе (/bot<token>/…). В last_error и лог он попадать не должен.
     */
    public static function redactSecrets(string $message): string
    {
        return (string) preg_replace('~/bot\d+:[A-Za-z0-9_-]+~', '/bot***', $message);
    }

    /**
     * Повтор запроса к площадке, если соединение не установилось. Связь с
     * Telegram с сервера временами пропадает на минуты (09.10: три попытки из
     * пяти без соединения) — одна неудача роняла публикацию по расписанию.
     */
    private function retryConnect(\Illuminate\Http\Client\PendingRequest $request): \Illuminate\Http\Client\PendingRequest
    {
        return $request->retry(self::CONNECT_ATTEMPTS, self::CONNECT_RETRY_PAUSE_MS, fn ($e) => self::isConnectFailure($e), throw: false);
    }

    /**
     * Сбой до отправки запроса: адрес не разрешился, TCP или TLS не
     * установились. Только такой повторять безопасно — обрыв после отправки
     * мог уже опубликовать пост, повтор дал бы дубль.
     */
    public static function isConnectFailure(\Throwable $e): bool
    {
        return $e instanceof \Illuminate\Http\Client\ConnectionException
            && (bool) preg_match('~cURL error (6|7|35):|Failed to connect|Connection timed out|SSL connection timeout~i', $e->getMessage());
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

        $post = fn (array $attachments) => Http::timeout(self::TIMEOUT)->asForm()->post('https://api.vk.com/method/wall.post', [
            'owner_id' => $ownerId,
            'from_group' => 1,
            'message' => $text,
            'attachments' => $attachments !== [] ? implode(',', $attachments) : null,
            'access_token' => $channel->secret('access_token'),
            'v' => self::VK_API_VERSION,
        ])->json();

        try {
            $r = $post($attachments);
            // Не принял вложения — текст важнее картинки: неудачный wall.post
            // записи не создаёт, поэтому повтор без фото не задвоит пост.
            if (isset($r['error']) && $attachments !== []) {
                Log::warning('MediaPublisherService: vk rejected photos, posting text only', [
                    'channel_id' => $channel->id,
                    'error' => $r['error']['error_msg'] ?? 'unknown',
                    'code' => $r['error']['error_code'] ?? null,
                ]);
                $r = $post([]);
            }
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

        // Ключ СООБЩЕСТВА загрузку на стену не пускает: photos.getWallUploadServer
        // отвечает кодом 27 «method is unavailable with group auth», и все посты
        // ВК уходили без картинок (даже «Поступления» с шестью фото в базе).
        // Тем же ключом фото грузится через сервер сообщений — владельцем
        // становится сообщество, и такое фото прикладывается к записи с
        // access_key. Ключ пользователя (если когда-нибудь будет) пойдёт
        // обычным путём через стену.
        $server = Http::timeout(self::TIMEOUT)->asForm()
            ->post('https://api.vk.com/method/photos.getWallUploadServer', [
                'group_id' => $groupId,
                'access_token' => $token,
                'v' => self::VK_API_VERSION,
            ])->json();
        $viaMessages = ($server['error']['error_code'] ?? null) === 27;
        if ($viaMessages) {
            $server = Http::timeout(self::TIMEOUT)->asForm()
                ->post('https://api.vk.com/method/photos.getMessagesUploadServer', [
                    'peer_id' => 0,
                    'access_token' => $token,
                    'v' => self::VK_API_VERSION,
                ])->json();
        }
        $uploadUrl = $server['response']['upload_url'] ?? null;
        if ($uploadUrl === null) {
            Log::warning('MediaPublisherService: vk upload server unavailable', [
                'channel_id' => $channel->id,
                'error' => $server['error']['error_msg'] ?? 'no upload_url',
            ]);

            return [];
        }

        foreach (array_slice($images, 0, self::MAX_PHOTOS) as $url) {
            try {
                $file = Http::timeout(self::TIMEOUT)->get($url);
                if (! $file->successful() || $file->body() === '') {
                    continue;
                }

                $uploaded = Http::timeout(self::TIMEOUT)
                    ->attach('photo', $file->body(), 'photo.jpg')
                    ->post($uploadUrl)
                    ->json();

                $saved = Http::timeout(self::TIMEOUT)->asForm()
                    ->post('https://api.vk.com/method/'.($viaMessages ? 'photos.saveMessagesPhoto' : 'photos.saveWallPhoto'), array_filter([
                        'group_id' => $viaMessages ? null : $groupId,
                        'photo' => $uploaded['photo'] ?? '',
                        'server' => $uploaded['server'] ?? '',
                        'hash' => $uploaded['hash'] ?? '',
                        'access_token' => $token,
                        'v' => self::VK_API_VERSION,
                    ], fn ($v) => $v !== null))->json();

                $photo = $saved['response'][0] ?? null;
                if ($photo === null) {
                    Log::warning('MediaPublisherService: vk photo not saved', [
                        'channel_id' => $channel->id,
                        'photo_url' => $url,
                        'error' => $saved['error']['error_msg'] ?? 'unknown',
                    ]);

                    continue;
                }
                $out[] = 'photo'.$photo['owner_id'].'_'.$photo['id']
                    .(! empty($photo['access_key']) ? '_'.$photo['access_key'] : '');
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
                $request = $this->retryConnect(Http::timeout(self::TIMEOUT * 3));

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

            $r = $this->retryConnect(Http::timeout(self::TIMEOUT))
                ->post($api.'sendMessage', array_filter([
                    'chat_id' => $chatId,
                    'text' => mb_substr($text, 0, 4096),
                    'parse_mode' => $mode,
                    'disable_web_page_preview' => true,
                ]))->json();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Telegram не ответил: '.self::redactSecrets($e->getMessage()), 'url' => null, 'external_id' => null];
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
    /* ───────────────────────────── MAX ───────────────────────────── */

    /** Лимит текста одного сообщения MAX (POST /messages). */
    private const MAX_TEXT_LIMIT = 4000;

    private function maxApi(): string
    {
        return rtrim((string) config('services.max.api_base', 'https://platform-api2.max.ru'), '/');
    }

    private function maxHttp(MediaChannel $channel, int $timeout = self::TIMEOUT): \Illuminate\Http\Client\PendingRequest
    {
        return $this->retryConnect(Http::timeout($timeout))->acceptJson()
            ->withOptions(['verify' => self::maxVerify()])
            ->withHeaders(['Authorization' => (string) $channel->secret('bot_token')]);
    }

    /**
     * Проверка TLS для MAX. Сервера MAX подписаны сертификатом Минцифры
     * («Russian Trusted Root CA»), которого нет в системном хранилище. Доверяем
     * ему только в запросах к MAX: системные корни + этот сертификат собираются
     * в отдельный файл в storage. Нет файла сертификата — обычная проверка.
     */
    public static function maxVerify(): string|bool
    {
        $extra = (string) config('services.max.extra_ca', '');
        if ($extra === '' || ! is_readable($extra)) {
            return true;
        }

        $bundle = storage_path('app/certs/max-ca-bundle.pem');
        if (is_readable($bundle) && filemtime($bundle) >= filemtime($extra)) {
            return $bundle;
        }

        try {
            $system = (string) (openssl_get_cert_locations()['default_cert_file'] ?? '');
            $roots = $system !== '' && is_readable($system) ? (string) file_get_contents($system) : '';
            if (! is_dir(dirname($bundle))) {
                mkdir(dirname($bundle), 0755, true);
            }
            file_put_contents($bundle, rtrim($roots)."\n".file_get_contents($extra), LOCK_EX);

            return $bundle;
        } catch (\Throwable $e) {
            Log::warning('MediaPublisherService: MAX CA bundle build failed', ['error' => $e->getMessage()]);

            return $extra;
        }
    }

    /** Текст ошибки MAX: {"code": "...", "message": "..."}. */
    private function maxError(\Illuminate\Http\Client\Response $r): string
    {
        $j = (array) ($r->json() ?? []);
        $text = is_string($j['message'] ?? null) ? $j['message'] : (is_string($j['code'] ?? null) ? $j['code'] : 'HTTP '.$r->status());

        return 'MAX: '.trim($text);
    }

    /**
     * Пост в канал MAX ботом-администратором.
     *
     * Картинки грузим файлами (POST /uploads → загрузка в выданный адрес → токен),
     * а не ссылками: опыт Telegram — площадка сама наш сайт качает ненадёжно.
     * После загрузки MAX ещё обрабатывает файл и на отправку отвечает
     * «attachment.not.ready» — повторяем с паузой. Не вышло с картинками —
     * пост уходит текстом, как в ВК: материал важнее альбома.
     * Текст длиннее лимита делим по абзацам на несколько сообщений.
     *
     * @param  list<string>  $images
     * @return array{ok: bool, message: string, url: ?string, external_id: ?string}
     */
    private function postToMax(MediaChannel $channel, string $text, array $images = [], bool $html = false): array
    {
        $chunks = self::splitForMax($text);

        try {
            $attachments = [];
            foreach (array_slice($images, 0, self::MAX_PHOTOS) as $url) {
                $token = $this->uploadMaxImage($channel, $url);
                if ($token !== null) {
                    $attachments[] = ['type' => 'image', 'payload' => ['token' => $token]];
                }
            }

            $first = null;
            if ($attachments !== []) {
                $first = $this->sendMax($channel, $chunks[0], $html, $attachments);
                if (! $first['ok']) {
                    Log::warning('MediaPublisherService: MAX post with images failed, falling back to text', [
                        'channel_id' => $channel->id,
                        'error' => $first['message'],
                    ]);
                    $first = null;
                }
            }
            $first ??= $this->sendMax($channel, $chunks[0], $html, []);
            if (! $first['ok']) {
                return ['ok' => false, 'message' => $first['message'], 'url' => null, 'external_id' => null];
            }

            // Продолжение длинного материала — следующими сообщениями. Ошибка на
            // хвосте пост не отменяет (он уже вышел), но видна в канале.
            foreach (array_slice($chunks, 1) as $chunk) {
                $next = $this->sendMax($channel, $chunk, $html, []);
                if (! $next['ok']) {
                    $channel->forceFill(['last_error' => mb_substr('Продолжение поста не ушло: '.$next['message'], 0, 500)])->save();
                    break;
                }
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'MAX не ответил: '.$e->getMessage(), 'url' => null, 'external_id' => null];
        }

        return ['ok' => true, 'message' => 'Опубликовано.', 'url' => $first['url'], 'external_id' => $first['external_id']];
    }

    /**
     * Одно сообщение в канал. «attachment.not.ready» — файл ещё обрабатывается,
     * повторяем с нарастающей паузой.
     *
     * @param  list<array<string, mixed>>  $attachments
     * @return array{ok: bool, message: string, url: ?string, external_id: ?string}
     */
    private function sendMax(MediaChannel $channel, string $text, bool $html, array $attachments): array
    {
        $body = array_filter([
            'text' => $text,
            'format' => $html ? 'html' : null,
            'attachments' => $attachments ?: null,
        ], fn ($v) => $v !== null);
        $query = http_build_query(['chat_id' => (string) $channel->secret('chat_id'), 'disable_link_preview' => 'true']);

        $r = null;
        foreach ([0, 2, 4, 6] as $pause) {
            if ($pause > 0) {
                sleep($pause);
            }
            $r = $this->maxHttp($channel, self::TIMEOUT * 2)->post($this->maxApi().'/messages?'.$query, $body);
            // В успешном ответе «message» — сам пост (массив), в ошибке — строка.
            $error = $r->successful() ? '' : (string) $r->json('code').' '.(is_string($r->json('message')) ? $r->json('message') : '');
            if ($attachments === [] || ! str_contains($error, 'attachment.not.ready')) {
                break;
            }
        }

        if (! $r->successful()) {
            return ['ok' => false, 'message' => $this->maxError($r), 'url' => null, 'external_id' => null];
        }

        $message = (array) ($r->json('message') ?? []);
        $mid = (string) ($message['body']['mid'] ?? '');

        return [
            'ok' => true,
            'message' => 'Опубликовано.',
            // Публичная ссылка на пост канала; у закрытого канала её нет.
            'url' => is_string($message['url'] ?? null) && $message['url'] !== '' ? $message['url'] : null,
            'external_id' => $mid !== '' ? mb_substr($mid, 0, 64) : null,
        ];
    }

    /**
     * Загрузить картинку в MAX: POST /uploads?type=image → адрес загрузки →
     * multipart с полем «data» → токен (в ответе загрузки photos.{id}.token,
     * у части ответов — token сразу). null — картинку пропускаем.
     */
    private function uploadMaxImage(MediaChannel $channel, string $imageUrl): ?string
    {
        try {
            $file = Http::timeout(self::TIMEOUT)->get($imageUrl);
            if (! $file->successful() || $file->body() === '') {
                return null;
            }

            $slot = $this->maxHttp($channel)->post($this->maxApi().'/uploads?type=image');
            $uploadUrl = (string) ($slot->json('url') ?? '');
            if (! $slot->successful() || $uploadUrl === '') {
                Log::warning('MediaPublisherService: MAX upload slot failed', ['channel_id' => $channel->id, 'error' => $this->maxError($slot)]);

                return null;
            }

            $name = basename((string) parse_url($imageUrl, PHP_URL_PATH)) ?: 'photo.jpg';
            $up = $this->retryConnect(Http::timeout(self::TIMEOUT * 3))
                ->withOptions(['verify' => self::maxVerify()])
                ->withHeaders(['Authorization' => (string) $channel->secret('bot_token')])
                ->attach('data', $file->body(), $name)
                ->post($uploadUrl);
            if (! $up->successful()) {
                Log::warning('MediaPublisherService: MAX image upload failed', ['channel_id' => $channel->id, 'status' => $up->status()]);

                return null;
            }

            return self::maxUploadToken((array) ($up->json() ?? []), (array) ($slot->json() ?? []));
        } catch (\Throwable $e) {
            Log::warning('MediaPublisherService: MAX image upload error', ['channel_id' => $channel->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Токен картинки из ответа загрузки MAX: {"photos": {"<id>": {"token": "…"}}}
     * или {"token": "…"}; запасной — токен, выданный вместе с адресом загрузки.
     *
     * @param  array<string, mixed>  $upload
     * @param  array<string, mixed>  $slot
     */
    public static function maxUploadToken(array $upload, array $slot = []): ?string
    {
        $photos = $upload['photos'] ?? null;
        if (is_array($photos) && $photos !== []) {
            $firstPhoto = reset($photos);
            if (is_array($firstPhoto) && ! empty($firstPhoto['token'])) {
                return (string) $firstPhoto['token'];
            }
        }
        $token = (string) ($upload['token'] ?? $slot['token'] ?? '');

        return $token !== '' ? $token : null;
    }

    /**
     * Текст на части до лимита сообщения MAX — по абзацам, длинный абзац по строкам.
     * Режем только по переводам строк: HTML-ссылки живут внутри строки и не рвутся.
     *
     * @return list<string>
     */
    public static function splitForMax(string $text, int $limit = self::MAX_TEXT_LIMIT): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $current = '';
        foreach (preg_split('/(?<=\n)/u', $text) ?: [] as $line) {
            if ($current !== '' && mb_strlen($current.$line) > $limit) {
                $chunks[] = rtrim($current);
                $current = '';
            }
            $current .= $line;
        }
        if (trim($current) !== '') {
            $chunks[] = rtrim($current);
        }

        // Строка длиннее лимита целиком (без переводов строк) — режем по длине.
        $out = [];
        foreach ($chunks as $chunk) {
            foreach (mb_str_split($chunk, $limit) as $part) {
                if (trim($part) !== '') {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }

    /**
     * Проверка связи с MAX: бот отвечает (GET /me) и видит канал (GET /chats/{id}).
     * Не видит — перечисляем каналы, где бот состоит, с их id: найти id канала
     * руками в MAX неудобно.
     *
     * @return array{ok: bool, message: string}
     */
    private function checkMax(MediaChannel $channel): array
    {
        try {
            $me = $this->maxHttp($channel)->get($this->maxApi().'/me');
            if (! $me->successful()) {
                return ['ok' => false, 'message' => $this->maxError($me).' (проверьте токен бота)'];
            }
            $bot = trim((string) ($me->json('name') ?? $me->json('username') ?? 'бот'));

            $chatId = (string) $channel->secret('chat_id');
            $chat = $this->maxHttp($channel)->get($this->maxApi().'/chats/'.rawurlencode($chatId));
            if ($chat->successful()) {
                $title = trim((string) ($chat->json('title') ?? 'канал'));

                return ['ok' => true, 'message' => 'MAX отвечает: бот «'.$bot.'», канал «'.$title.'».'];
            }

            $list = collect($this->maxChats($channel))
                ->map(fn ($c) => '«'.$c['title'].'» — '.$c['id'])
                ->implode('; ');

            return ['ok' => false, 'message' => 'Бот «'.$bot.'» не видит канал '.$chatId.'. '
                .($list !== '' ? 'Доступные боту: '.$list.'.' : 'Добавьте бота администратором канала.')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'MAX не ответил: '.$e->getMessage()];
        }
    }

    /**
     * Канал не указан: берём его из событий «бота добавили». Один канал —
     * сохраняем его id в доступ канала, несколько — просим выбрать.
     *
     * @return array{ok: bool, message: string}
     */
    private function discoverMaxChat(MediaChannel $channel): array
    {
        try {
            $chats = collect($this->maxChats($channel));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'MAX не ответил: '.$e->getMessage()];
        }
        $channels = $chats->where('type', 'channel')->values();
        $pick = $channels->count() === 1 ? $channels->first() : ($chats->count() === 1 ? $chats->first() : null);

        if ($pick === null) {
            $list = $chats->map(fn ($c) => '«'.$c['title'].'» — '.$c['id'])->implode('; ');

            return ['ok' => false, 'message' => $list !== ''
                ? 'Бот состоит в нескольких местах: '.$list.'. Впишите нужный id в «Доступ».'
                : 'Бот пока не добавлен ни в один канал MAX. Добавьте его администратором канала и нажмите «Проверить связь» ещё раз.'];
        }

        $channel->writeSecrets(['chat_id' => $pick['id']] + $channel->secrets());
        $channel->save();

        return ['ok' => true, 'message' => ''];
    }

    /**
     * Чаты и каналы, где состоит бот. Списка у MAX больше нет (GET /chats
     * отключён), id приходит только событием bot_added (GET /updates). Лента
     * событий отдаёт каждое один раз и недолго хранит — найденные id копим
     * в кэше по боту, детали канала берём из GET /chats/{id}.
     *
     * @return list<array{id: string, title: string, link: ?string, type: ?string}>
     */
    public function maxChats(MediaChannel $channel): array
    {
        $key = 'media:max:chats:'.sha1((string) $channel->secret('bot_token'));
        $ids = (array) Cache::get($key, []);

        $r = $this->maxHttp($channel)->get($this->maxApi().'/updates', ['types' => 'bot_added', 'limit' => 100, 'timeout' => 0]);
        if ($r->successful()) {
            foreach ((array) ($r->json('updates') ?? []) as $u) {
                $id = (string) ($u['chat_id'] ?? '');
                if ($id !== '' && ($u['update_type'] ?? 'bot_added') === 'bot_added') {
                    $ids[$id] = true;
                }
            }
            Cache::forever($key, $ids);
        }

        $chats = [];
        foreach (array_keys($ids) as $id) {
            $c = $this->maxHttp($channel)->get($this->maxApi().'/chats/'.rawurlencode((string) $id));
            if (! $c->successful()) {
                continue;
            }
            $chats[] = [
                'id' => (string) ($c->json('chat_id') ?? $id),
                'title' => trim((string) ($c->json('title') ?? '')),
                'link' => $c->json('link') !== null ? (string) $c->json('link') : null,
                'type' => $c->json('type') !== null ? (string) $c->json('type') : null,
            ];
        }

        return $chats;
    }

    /**
     * id канала MAX по тому, что вставил человек: число — как есть; ссылка или
     * название — ищем среди каналов бота.
     *
     * @return array{ok: bool, id: ?string, message: string}
     */
    public function resolveMaxChatId(MediaChannel $channel, string $target): array
    {
        $target = trim($target);
        if (preg_match('/^-?\d+$/', $target)) {
            return ['ok' => true, 'id' => $target, 'message' => ''];
        }

        $needle = mb_strtolower(rtrim($target, '/'));
        $tail = mb_strtolower(basename((string) parse_url($needle, PHP_URL_PATH)));
        $chats = $this->maxChats($channel);
        foreach ($chats as $c) {
            $link = mb_strtolower(rtrim((string) $c['link'], '/'));
            $byLink = $link !== '' && ($link === $needle || ($tail !== '' && str_ends_with($link, '/'.$tail)));
            if ($byLink || mb_strtolower($c['title']) === $needle) {
                return ['ok' => true, 'id' => $c['id'], 'message' => 'Канал «'.$c['title'].'», id '.$c['id'].'.'];
            }
        }

        $list = collect($chats)->map(fn ($c) => '«'.$c['title'].'» — '.$c['id'])->implode('; ');

        return ['ok' => false, 'id' => null, 'message' => 'бот не состоит в таком канале.'
            .($list !== '' ? ' Доступные боту: '.$list.'.' : ' Сначала добавьте бота администратором канала.')];
    }
}
