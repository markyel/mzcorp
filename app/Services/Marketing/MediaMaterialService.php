<?php

namespace App\Services\Marketing;

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
    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly MediaDataService $data,
    ) {}

    /**
     * @return array{ok: bool, publication: ?MediaPublication, message: string}
     */
    public function draft(MediaTopic $topic, MediaChannel $channel, ?User $author, ?string $note = null): array
    {
        $note = trim((string) $note);

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
        ['key' => $subjectKey, 'facts' => $data] = $this->data->factsWithKey($topic);

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
        $body = trim((string) $parsed['body']);
        if ($channel->isPostable()) {
            $body = MediaPublisherService::forFeed($body);
        }

        $publication = MediaPublication::create([
            'media_topic_id' => $topic->id,
            'media_channel_id' => $channel->id,
            'title' => mb_substr(trim((string) ($parsed['title'] ?? $topic->title)), 0, 300),
            'body' => $body,
            'subject_key' => $subjectKey,
            // Фото позиций — для ассортиментных тем. Редактор увидит их в
            // карточке и сможет убрать лишние до публикации.
            'image_urls' => $this->data->photosFor($topic) ?: null,
            'status' => 'draft',
            'planned_for' => $topic->next_due_on ?? now()->toDateString(),
            'model' => $model,
            'created_by_user_id' => $author?->id,
        ]);

        $notes = trim((string) ($parsed['notes'] ?? ''));

        return [
            'ok' => true,
            'publication' => $publication,
            'message' => 'Черновик готов.'.($notes !== '' ? ' Редактору: '.$notes : ''),
        ];
    }
}
