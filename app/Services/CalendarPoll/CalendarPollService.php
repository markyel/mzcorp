<?php

namespace App\Services\CalendarPoll;

use App\Jobs\CalendarPoll\MakeCalendarPollPhotoVariantsJob;
use App\Mail\CalendarPollInviteMail;
use App\Models\CalendarPoll;
use App\Models\CalendarPollPhoto;
use App\Models\CalendarPollVote;
use App\Models\CalendarPollVoter;
use App\Models\User;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Опрос «Фото для календаря» (17.11.2026): организатор загружает фото и список сотрудников, рассылает
 * личные ссылки; каждый расставляет picks фото по местам (1-е — picks баллов, последнее — 1); по сумме
 * баллов — рейтинг, берём winners лучших. Сотрудникам вне MyLift — одноразовая ссылка входа: в базе
 * только sha256 токена, после входа устройство помнит зашифрованная cookie.
 */
class CalendarPollService
{
    public const DISK = 'local';

    public function __construct(
        private readonly PollImageVariants $variants,
        private readonly VoterListParser $parser,
        private readonly SystemNotificationMailer $mailer,
    ) {
    }

    // ───────── Фото ─────────

    /** Оригинал — как загрузили; копии для галереи сделает фоновая задача. */
    public function addPhoto(CalendarPoll $poll, UploadedFile $file): CalendarPollPhoto
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $path = $file->storeAs('calendar-poll/'.$poll->id.'/orig', Str::random(16).'.'.preg_replace('/[^a-z0-9]/', '', $ext), self::DISK);
        if ($path === false) {
            throw new RuntimeException('Не удалось сохранить файл '.$file->getClientOriginalName().'.');
        }
        $photo = $poll->photos()->create([
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'path' => $path,
            'sort' => (int) CalendarPollPhoto::query()->where('calendar_poll_id', $poll->id)->max('sort') + 1,
        ]);
        MakeCalendarPollPhotoVariantsJob::dispatch($photo->id);

        return $photo;
    }

    /** Миниатюра и просмотр. Ошибка (не картинка, HEIC) — в поле error, видна организатору. */
    public function makeVariants(CalendarPollPhoto $photo): void
    {
        $disk = Storage::disk(self::DISK);
        try {
            $made = $this->variants->make((string) $disk->get($photo->path));
            $base = 'calendar-poll/'.$photo->calendar_poll_id.'/'.$photo->id;
            $disk->put($base.'-thumb.jpg', $made['thumb']);
            $disk->put($base.'-preview.jpg', $made['preview']);
            $photo->forceFill(['thumb_path' => $base.'-thumb.jpg', 'preview_path' => $base.'-preview.jpg',
                'width' => $made['width'], 'height' => $made['height'], 'error' => null])->save();
        } catch (\Throwable $e) {
            $photo->forceFill(['error' => mb_substr($e->getMessage(), 0, 250)])->save();
            Log::warning('CalendarPoll: photo variants failed', ['photo_id' => $photo->id, 'error' => $e->getMessage()]);
        }
    }

    public function deletePhoto(CalendarPollPhoto $photo): void
    {
        Storage::disk(self::DISK)->delete(array_filter([$photo->path, $photo->thumb_path, $photo->preview_path]));
        $photo->delete();
    }

    // ───────── Участники и ссылки ─────────

    /**
     * Добавить участников из вставленного списка. Уже есть в опросе — имя не трогаем.
     *
     * @return array{added: int, existing: int, invalid: list<string>, duplicates: list<string>}
     */
    public function addVoters(CalendarPoll $poll, string $text): array
    {
        $parsed = $this->parser->parse($text);
        $added = 0;
        $existing = 0;
        foreach ($parsed['valid'] as $row) {
            $voter = CalendarPollVoter::query()->firstOrNew(['calendar_poll_id' => $poll->id, 'email' => $row['email']]);
            if ($voter->exists) {
                $existing++;

                continue;
            }
            $voter->name = $row['name'];
            // Сотрудник MyLift с тем же адресом войдёт и без ссылки — как в систему.
            $voter->user_id = User::query()->whereRaw('lower(email) = ?', [$row['email']])->value('id');
            $voter->save();
            $added++;
        }

        return ['added' => $added, 'existing' => $existing, 'invalid' => $parsed['invalid'], 'duplicates' => $parsed['duplicates']];
    }

    /** Новая личная ссылка; прежняя перестаёт работать. */
    public function issueLink(CalendarPollVoter $voter): string
    {
        $token = Str::random(48);
        $voter->forceFill([
            'token_hash' => self::hash($token),
            'token_expires_at' => now()->addDays(max(1, (int) config('services.calendar_poll.link_ttl_days', 14))),
            'token_used_at' => null,
        ])->save();

        return route('calendar-poll.enter', ['token' => $token]);
    }

    /** Приглашение со ссылкой с mail@ (рассылку запускает организатор кнопкой). */
    public function invite(CalendarPollVoter $voter): bool
    {
        $poll = $voter->poll;
        $link = $this->issueLink($voter);
        $sent = $this->mailer->sendMailable($voter->email, new CalendarPollInviteMail(
            name: $voter->name,
            pollTitle: $poll->title,
            description: (string) $poll->description,
            link: $link,
            picks: $poll->picks,
            closesAt: $poll->closes_at,
            validDays: (int) config('services.calendar_poll.link_ttl_days', 14),
        ), (string) config('services.calendar_poll.from_name', 'Мой ЗиП'));
        if ($sent) {
            $voter->forceFill(['invited_at' => now()])->save();
        }

        return $sent;
    }

    /**
     * Вход по ссылке. Ссылка одноразовая: второй раз пускает только то же устройство (его помнит cookie,
     * это проверяет контроллер до вызова) — иначе «пришлём новую».
     *
     * @return array{voter: ?CalendarPollVoter, reason: ?string}  reason: unknown | expired | used | closed
     */
    public function enter(string $token): array
    {
        $voter = CalendarPollVoter::query()->with('poll')->where('token_hash', self::hash($token))->first();
        if ($voter === null) {
            return ['voter' => null, 'reason' => 'unknown'];
        }
        if ($voter->poll->status === CalendarPoll::STATUS_DRAFT) {
            return ['voter' => $voter, 'reason' => 'closed'];
        }
        if ($voter->token_used_at !== null) {
            return ['voter' => $voter, 'reason' => 'used'];
        }
        if ($voter->token_expires_at !== null && $voter->token_expires_at->isPast()) {
            return ['voter' => $voter, 'reason' => 'expired'];
        }
        $voter->forceFill(['token_used_at' => now(), 'last_seen_at' => now()])->save();

        return ['voter' => $voter, 'reason' => null];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    // ───────── Выбор ─────────

    /**
     * Сохранить выбор: ровно picks разных фото этого опроса, по порядку мест. Пересохранение заменяет прежний.
     *
     * @param  list<int>  $photoIds  1-е место первым
     *
     * @throws RuntimeException причина — текстом для участника
     */
    public function saveChoice(CalendarPollVoter $voter, array $photoIds): void
    {
        $poll = $voter->poll;
        if (! $poll->acceptsVotes()) {
            throw new RuntimeException('Голосование уже закончилось.');
        }
        $ids = array_values(array_unique(array_map('intval', $photoIds)));
        if (count($ids) !== $poll->picks || count($photoIds) !== $poll->picks) {
            throw new RuntimeException('Нужно выбрать ровно '.$poll->picks.' разных фото.');
        }
        $known = CalendarPollPhoto::query()->where('calendar_poll_id', $poll->id)->whereIn('id', $ids)->count();
        if ($known !== count($ids)) {
            throw new RuntimeException('Часть фото больше нет в опросе — обновите страницу.');
        }

        DB::transaction(function () use ($voter, $ids) {
            CalendarPollVote::query()->where('calendar_poll_voter_id', $voter->id)->delete();
            foreach ($ids as $i => $photoId) {
                CalendarPollVote::query()->create(['calendar_poll_voter_id' => $voter->id, 'calendar_poll_photo_id' => $photoId, 'rank' => $i + 1]);
            }
            $voter->forceFill(['voted_at' => now(), 'last_seen_at' => now()])->save();
        });
    }

    // ───────── Рейтинг ─────────

    /**
     * Рейтинг фото опроса: баллы (1-е место — picks, последнее — 1), голоса, первые места.
     *
     * @return Collection<int, array{photo: CalendarPollPhoto, points: int, votes: int, firsts: int, place: int}>
     */
    public function ranking(CalendarPoll $poll): Collection
    {
        $photos = $poll->photos()->get()->keyBy('id');
        $ranks = CalendarPollVote::query()
            ->whereIn('calendar_poll_photo_id', $photos->keys())
            ->get(['calendar_poll_photo_id', 'rank'])
            ->groupBy('calendar_poll_photo_id')
            ->map(fn ($g) => $g->pluck('rank')->map(fn ($r) => (int) $r)->all())
            ->all();

        return collect(self::score($photos->keys()->all(), $ranks, $poll->picks))
            ->map(fn ($row) => $row + ['photo' => $photos[$row['id']]]);
    }

    /**
     * Чистый подсчёт: баллы = Σ(picks + 1 − место); при равенстве — больше голосов, затем больше первых мест,
     * затем раньше загружено. place — место в рейтинге (1..).
     *
     * @param  list<int>  $photoIds  в порядке загрузки
     * @param  array<int, list<int>>  $ranks  photo_id => места, которые ему дали
     * @return list<array{id: int, points: int, votes: int, firsts: int, place: int}>
     */
    public static function score(array $photoIds, array $ranks, int $picks): array
    {
        $rows = [];
        foreach (array_values($photoIds) as $order => $id) {
            $r = $ranks[$id] ?? [];
            $rows[] = ['id' => (int) $id, 'points' => array_sum(array_map(fn ($x) => max(0, $picks + 1 - $x), $r)),
                'votes' => count($r), 'firsts' => count(array_filter($r, fn ($x) => $x === 1)), 'order' => $order];
        }
        usort($rows, fn ($a, $b) => [$b['points'], $b['votes'], $b['firsts'], $a['order']] <=> [$a['points'], $a['votes'], $a['firsts'], $b['order']]);

        return array_map(function ($row, $i) {
            unset($row['order']);

            return $row + ['place' => $i + 1];
        }, $rows, array_keys($rows));
    }
}
