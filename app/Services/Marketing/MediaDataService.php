<?php

namespace App\Services\Marketing;

use App\Models\MediaPublication;
use App\Models\MediaTopic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Факты для регулярных публикаций — из наших же данных.
 *
 * Смысл в том, чтобы модель ничего не придумывала: она получает готовый список
 * позиций или посчитанную статистику и только излагает их. Поэтому здесь SQL,
 * а не рассуждения, и поэтому пустой ответ — нормальный ответ: если за неделю
 * нечего показать, публикацию не делаем.
 */
class MediaDataService
{
    /** Сколько позиций отдаём в один материал: длинные списки никто не читает. */
    public const MAX_ITEMS = 20;

    /** Окно по умолчанию, если у темы не задана регулярность. */
    public const DEFAULT_WINDOW_DAYS = 7;

    /** Ниже этого числа уточнений по категории инструкция не окупается. */
    public const MIN_CATEGORY_ITEMS = 25;

    /** Ключ выпуска серии советов: тип детали из базы знаний опознания. */
    public const TIPS_KEY_PREFIX = 'kb:';

    /** @var list<string> */
    public const DATA_SOURCES = ['catalog_new', 'catalog_price', 'stock_arrivals', 'request_tips', 'industry_digest', 'weekly_roundup'];

    /** Меньше новостей за неделю — дайджеста нет: из двух строк сводки не выходит. */
    public const MIN_DIGEST_ITEMS = 3;

    public function isDataDriven(MediaTopic $topic): bool
    {
        return in_array($topic->source, self::DATA_SOURCES, true);
    }

    /** Человеку — почему по теме может не быть данных. */
    public function sourceNote(string $source): string
    {
        return match ($source) {
            'catalog_new' => 'Берём позиции, появившиеся в каталоге за окно темы.',
            'catalog_price' => 'Берём позиции, у которых цена за окно темы снизилась.',
            'stock_arrivals' => 'Поступление — позиция, которая с прошлого выпуска появилась в наличии (остаток был нулевым). Нет таких — нет и поста.',
            'request_tips' => 'Серия: каждый выпуск — как оформить заявку на один тип детали из базы знаний опознания '
                .'(кнопка, отводка, ролик…), о котором ещё не рассказывали. Советы — из того, по чему мы эту деталь '
                .'опознаём, и из вопросов, которые менеджеры задавали клиентам.',
            'industry_digest' => 'Берём новости отрасли из ленты '.config('services.marketing.news_digest_feed')
                .' за окно темы и сводим их в один обзор недели по направлениям, со ссылкой на ленту.',
            'weekly_roundup' => 'Одна статья в неделю для RSS-ленты и страницы mzcorp.ru/news: новинки, снижение цен и '
                .'поступления на склад — по 2–4 позиции с фото, ценами и ссылками на карточки. Позиции из прошлых '
                .'выпусков не повторяются; меньше двух разделов с позициями — выпуска нет.',
            default => 'Материал пишется по брифу темы.',
        };
    }

    public function factsFor(MediaTopic $topic): string
    {
        return $this->factsWithKey($topic)['facts'];
    }

    /**
     * Факты и — для серийных тем — чему посвящён этот выпуск.
     *
     * «Советы по оформлению заявок» — не один пост со сводной статистикой, а
     * серия коротких инструкций: в каждом выпуске одна категория товара, ещё не
     * разобранная. Ключ выпуска возвращаем наружу, чтобы следующий раз взять
     * следующую категорию, а не ту же самую.
     *
     * У дайджеста есть ещё links: модель ставит метку [3], адрес подставляет
     * resolveLinks() — длинный адрес модель переписала бы с опечаткой.
     *
     * @return array{key: ?string, facts: string, links?: array<int, string>}
     */
    public function factsWithKey(MediaTopic $topic): array
    {
        $days = max(1, (int) ($topic->cadence_days ?: self::DEFAULT_WINDOW_DAYS));

        return match ($topic->source) {
            'catalog_new' => ['key' => null, 'facts' => $this->newItems($this->since($topic, $days))],
            'catalog_price' => ['key' => null, 'facts' => $this->priceDrops($this->since($topic, $days))],
            'stock_arrivals' => ['key' => null, 'facts' => $this->arrivals($this->since($topic, $days))],
            'request_tips' => $this->tipsForNextCategory($topic),
            'industry_digest' => $this->newsDigest($days),
            default => ['key' => null, 'facts' => ''],
        };
    }

    /**
     * Новости отрасли за ровную неделю до сегодняшнего дня — пронумерованным
     * списком. [0] — сама лента.
     *
     * Период — окно выпуска, а не даты первой и последней новости: иначе
     * «неделя» выходила 23.09–27.09 (лента держит только 30 новостей).
     *
     * @return array{key: ?string, facts: string, links: array<int, string>}
     */
    private function newsDigest(int $days): array
    {
        [$from, $to] = IndustryNewsFeed::window(now(), $days);
        ['home' => $home, 'items' => $items] = app(IndustryNewsFeed::class)->between($from, $to);
        if (count($items) < self::MIN_DIGEST_ITEMS) {
            return ['key' => null, 'facts' => '', 'links' => []];
        }

        $links = $home !== null ? [0 => $home] : [];
        $lines = [
            'ПЕРИОД ОБЗОРА: '.self::periodLabel($from, $to).' ('.$days.' дн.). Период пиши именно так, '
                .'а не по датам первой и последней новости.',
            'Новостей за период: '.count($items).'.'.($home !== null ? ' [0] — вся лента новостей.' : ''),
        ];
        foreach ($items as $i => $item) {
            $n = $i + 1;
            $links[$n] = $item['link'];
            $lines[] = '['.$n.'] '.$item['published_at']->format('d.m').' · '.$item['title']
                .($item['description'] !== '' ? "\n    ".$item['description'] : '');
        }

        return [
            'key' => 'digest:'.$from->toDateString().'..'.$to->toDateString(),
            'facts' => implode("\n", $lines),
            'links' => $links,
        ];
    }

    private const MONTHS_GEN = [
        1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
        'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
    ];

    /** «21–27 сентября 2026», «25 сентября – 1 октября 2026». */
    public static function periodLabel(\DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $f = [(int) $from->format('j'), (int) $from->format('n'), (int) $from->format('Y')];
        $t = [(int) $to->format('j'), (int) $to->format('n'), (int) $to->format('Y')];

        if ($f[1] === $t[1] && $f[2] === $t[2]) {
            return $f[0].'–'.$t[0].' '.self::MONTHS_GEN[$t[1]].' '.$t[2];
        }

        return $f[0].' '.self::MONTHS_GEN[$f[1]].($f[2] !== $t[2] ? ' '.$f[2] : '')
            .' – '.$t[0].' '.self::MONTHS_GEN[$t[1]].' '.$t[2];
    }

    /**
     * Метки [n] из текста модели → адреса. Неизвестная метка убирается: пустая
     * ссылка лучше, чем ссылка не туда.
     *
     * @param  array<int, string>  $links
     */
    public function resolveLinks(string $text, array $links): string
    {
        if ($links === []) {
            return $text;
        }

        // Точку после метки в конце строки съедаем: «…лифтов https://…/kmz.» —
        // площадка может принять точку за часть адреса.
        $text = preg_replace_callback('~\s?\[(\d{1,2})\](?:\.(?=\s*$))?~um', function (array $m) use ($links) {
            $url = $links[(int) $m[1]] ?? null;

            return $url !== null ? ' '.$url : '';
        }, $text) ?? $text;

        return preg_replace('~[ \t]+$~um', '', $text) ?? $text;
    }

    /**
     * Столько же картинок, сколько позиций в подборке (см. жанры в
     * WriteMaterialPrompt): альбом из десяти фотографий под текстом о шести
     * позициях выглядит как чужая нарезка. Порядок совпадает — и данные, и
     * фото идут по убыванию выгоды.
     */
    public const MAX_PHOTOS = 6;

    /**
     * Фотографии позиций для ассортиментного поста.
     *
     * Список деталей без картинок читается как прайс-лист: «поручень резиновый
     * Schindler SDS» ничего не говорит человеку, который ищет деталь глазами.
     * Берём фото ровно тех позиций, о которых пост, в том же порядке.
     *
     * Модель пишет не обо всех позициях из фактов, а у части позиций фото нет.
     * Поэтому при готовом тексте фото берём по артикулам из него: 12.10 пост о
     * пяти новинках ушёл с фото четырёх других кнопок — выборка «свежие с фото»
     * дотягивала до шести чужими позициями.
     *
     * @return list<string>
     */
    public function photosFor(MediaTopic $topic, ?string $subjectKey = null, ?string $body = null): array
    {
        if ($topic->source === 'request_tips') {
            return $this->categoryPhoto($subjectKey);
        }

        // Без артикулов в тексте фото не подбираем вовсе: выборка «по окну»
        // не знает, о каких позициях пост, и тянула чужие.
        $skus = $body !== null && preg_match_all('/\bM\d{4,6}\b/u', $body, $m) ? array_values(array_unique($m[0])) : [];
        if ($skus === [] || ! in_array($topic->source, ['catalog_new', 'catalog_price', 'stock_arrivals'], true)) {
            return [];
        }

        $photos = DB::table('catalog_items')
            ->whereIn('sku', $skus)
            ->whereNotNull('photo_url')
            ->where('photo_url', '!=', '')
            ->orderByDesc('is_active')
            ->get(['sku', 'photo_url'])
            ->unique('sku')
            ->pluck('photo_url', 'sku');

        return collect($skus)
            ->map(fn ($sku) => $photos[$sku] ?? null)
            ->filter()
            ->unique()
            ->take(self::MAX_PHOTOS)
            ->values()
            ->all();
    }

    /** Фото детали к совету (для обложки из нашей детали) или null. */
    public function tipPhotoUrl(?string $subjectKey): ?string
    {
        return $this->categoryPhoto($subjectKey)[0] ?? null;
    }

    /**
     * Одна фотография к совету по категории детали («Кнопка лифтовая»): пост
     * без картинки в ленте ВК пролистывают, а деталь на фото сразу объясняет,
     * о чём речь. Берём позицию этой категории, которую клиенты спрашивают
     * чаще всего за 90 дней (при равенстве — ту, что есть на складе), — самую
     * узнаваемую. Нет категории в ключе выпуска или фото у её позиций — пост
     * уходит без картинки, как раньше.
     *
     * @return list<string>
     */
    private function categoryPhoto(?string $subjectKey): array
    {
        if ($subjectKey === null || ! str_starts_with($subjectKey, self::TIPS_KEY_PREFIX)) {
            return [];
        }
        $category = DB::table('equipment_categories')
            ->where('slug', substr($subjectKey, strlen(self::TIPS_KEY_PREFIX)))
            ->first(['id', 'name']);
        if ($category === null) {
            return [];
        }

        // Категория широкая: самой спрашиваемой в «Кнопке лифтовой» оказался
        // держатель кнопочного элемента, в «Приводе дверей» — пружина. Поэтому
        // сначала позиции, чьё название начинается с самого типа детали
        // (первое слово категории без окончания: «Кнопк», «Приво», «Отводк»).
        $head = (string) (preg_split('/[\s(\/,]+/u', trim((string) $category->name))[0] ?? '');
        $stem = mb_substr($head, 0, max(4, mb_strlen($head) - 1));

        $asked = DB::table('request_items')
            ->where('created_at', '>=', now()->subDays(90))
            ->whereNotNull('catalog_item_id')
            ->groupBy('catalog_item_id')
            ->selectRaw('catalog_item_id, COUNT(*) AS c');

        $url = DB::table('catalog_items as ci')
            ->leftJoinSub($asked, 'a', 'a.catalog_item_id', '=', 'ci.id')
            ->where('ci.equipment_category_id', $category->id)
            ->where('ci.is_active', true)
            ->whereNotNull('ci.photo_url')
            ->where('ci.photo_url', '!=', '')
            // «(ЗАМЕНЕНО НА M00171) Плата…» — снятая позиция, показывать её нельзя.
            ->where('ci.name', 'not ilike', '%замен%на m%')
            ->orderByRaw('CASE WHEN ci.name ILIKE ? THEN 0 ELSE 1 END', [$stem.'%'])
            ->orderByRaw('COALESCE(a.c, 0) DESC')
            ->orderByRaw('CASE WHEN ci.stock_available > 0 THEN 0 ELSE 1 END')
            ->orderBy('ci.id')
            ->value('ci.photo_url');

        return $url !== null ? [(string) $url] : [];
    }

    /**
     * Короткое имя позиции для ленты.
     *
     * Каталожное название несёт всю техническую хвостовую часть («DIN 3062
     * (EN 12385) грузолюдской 8x19S-FC 1570(1370/1770) Н/мм2 44,6 кН»), и в
     * посте она превращается в нечитаемую строку. Обрезаем по границе слова:
     * опознать позицию всё равно позволяет артикул рядом.
     */
    private function short(?string $name, int $limit = 60): string
    {
        $name = trim((string) $name);
        if (mb_strlen($name) <= $limit) {
            return $name;
        }

        $cut = mb_substr($name, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $limit / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,;(').'…';
    }

    /**
     * Начало периода выпуска: прошлый выпуск темы, но не раньше окна темы.
     *
     * Окно «последние N дней от сегодня» пересекалось с прошлым выпуском,
     * когда тот выходил с опозданием, — позиция попадала в два поста подряд.
     * Граница — момент, когда писался прошлый вышедший материал: его факты
     * собраны тогда, всё, что появилось позже, — уже новое.
     *
     * Прошлый выпуск — вышедший до сегодняшнего дня: сегодняшний пост этой же темы в другой
     * канал — тот же выпуск. Иначе второй канал прогона видел пустой период (21.10: пост
     * «Поступления на склад» вышел во ВКонтакте, Telegram следом — «данных нет»).
     */
    public function since(MediaTopic $topic, int $days): Carbon
    {
        $floor = now()->subDays($days);
        $last = $topic->id
            ? MediaPublication::query()->where('media_topic_id', $topic->id)->where('status', 'published')
                ->where('created_at', '<', now()->startOfDay())->max('created_at')
            : null;

        return $last !== null && Carbon::parse($last)->gt($floor) ? Carbon::parse($last) : $floor;
    }

    /** Новые позиции каталога за период. Цены не даём: их место — в карточке товара. */
    private function newItems(Carbon $since): string
    {
        $rows = DB::table('catalog_items')
            ->where('is_active', true)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(self::MAX_ITEMS)
            ->get(['sku', 'name', 'brand', 'part_type', 'stock_available']);

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Новых позиций в каталоге с '.$since->format('d.m').': '.$rows->count().'.'];
        foreach ($rows as $r) {
            $lines[] = '— '.$this->short($r->name)
                .($r->brand ? ' · '.$r->brand : '')
                .' · артикул '.$r->sku
                .((float) $r->stock_available > 0 ? ' · есть на складе' : ' · под заказ');
        }

        return implode("\n", $lines);
    }

    /**
     * Позиции, у которых цена снизилась за период. Показываем обе цены — в этом
     * суть темы.
     *
     * Считаем по позиции, а не по строкам журнала: цена на начало периода
     * (старая цена первого изменения в нём) против текущей. Строками журнала
     * позиция с двумя снижениями за неделю шла в факты дважды, а снижение,
     * которое следующий импорт отменил, всё равно попадало в пост (за 60 дн.
     * до 12.10.2026 — 55 случаев двух снижений за неделю, 27 откатов цены).
     */
    private function priceDrops(Carbon $since): string
    {
        $first = DB::table('catalog_price_changes')
            ->where('created_at', '>=', $since)
            ->whereNotNull('old_price')
            ->selectRaw('catalog_item_id, old_price, row_number() over (partition by catalog_item_id order by created_at, id) as rn');

        $rows = DB::query()->fromSub($first, 'f')
            ->join('catalog_items as ci', 'ci.id', '=', 'f.catalog_item_id')
            ->where('f.rn', 1)
            ->where('ci.is_active', true)
            ->whereNotNull('ci.price')
            ->whereColumn('ci.price', '<', 'f.old_price')
            ->orderByRaw('(f.old_price - ci.price) / NULLIF(f.old_price, 0) DESC')
            ->limit(self::MAX_ITEMS)
            ->get(['ci.name', 'ci.brand', 'ci.sku', 'f.old_price', 'ci.price as new_price', 'ci.stock_available']);

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Позиций со снизившейся ценой с '.$since->format('d.m').': '.$rows->count().'.'];
        foreach ($rows as $r) {
            $pct = (float) $r->old_price > 0
                ? round(((float) $r->old_price - (float) $r->new_price) * 100 / (float) $r->old_price)
                : 0;
            $lines[] = '— '.$this->short($r->name)
                .($r->brand ? ' · '.$r->brand : '')
                .' · артикул '.$r->sku
                .' · было '.number_format((float) $r->old_price, 0, ',', ' ')
                .' ₽, стало '.number_format((float) $r->new_price, 0, ',', ' ').' ₽'
                .($pct > 0 ? ' (−'.$pct.'%)' : '')
                .((float) $r->stock_available > 0 ? ' · есть на складе' : '');
        }

        return implode("\n", $lines);
    }

    /**
     * Поступления: позиции, которые за период появились в наличии (остаток был
     * нулевым и стал больше нуля — метка in_stock_since ставится импортом).
     * Новые позиции сюда не берём — это тема «Новинки».
     *
     * Раньше брали «в наличии и тронуты последним импортом»: импорт трогает
     * всё, и каждую неделю выходили одни и те же позиции с самым большим
     * остатком (M25447, M11299, M17415… 24.09, 30.09 и 07.10). Нет настоящих
     * поступлений — нет и поста.
     */
    private function arrivals(Carbon $since): string
    {
        $rows = DB::table('catalog_items')
            ->where('is_active', true)
            ->where('stock_available', '>', 0)
            ->where('in_stock_since', '>=', $since)
            ->where('created_at', '<', $since)
            ->orderByDesc('in_stock_since')
            ->orderByDesc('stock_available')
            ->limit(self::MAX_ITEMS)
            ->get(['sku', 'name', 'brand', 'stock_available']);

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Поступили на склад с '.$since->format('d.m').':'];
        foreach ($rows as $r) {
            $lines[] = '— '.$this->short($r->name)
                .($r->brand ? ' · '.$r->brand : '')
                .' · артикул '.$r->sku
                .' · на складе '.rtrim(rtrim(number_format((float) $r->stock_available, 2, ',', ' '), '0'), ',');
        }

        return implode("\n", $lines);
    }

    /**
     * Инструкция по одной категории товара: чему посвятить следующий выпуск и
     * что о ней известно.
     *
     * Серия устроена так: берём категорию, по которой мы чаще всего пишем
     * уточнения и о которой ещё не рассказывали. Если рассказали обо всех —
     * возвращаемся к той, что разбирали дольше всех: за квартал состав заявок
     * успевает поменяться.
     *
     * Модель получает три вещи: чего не хватало именно в этой категории,
     * как клиенты формулируют такие позиции у себя в заявках и как те же
     * позиции называются в нашем каталоге. Последнее и есть источник
     * конкретики: по каталожным названиям видно, чем позиции различаются
     * между собой — серия, символ, подсветка, разъём, размер, — а значит,
     * что именно клиенту нужно указать, чтобы выбор был однозначным.
     *
     * @return array{key: ?string, facts: string}
     */
    private function tipsForNextCategory(MediaTopic $topic): array
    {
        $since = now()->subDays(90);

        $requestIds = DB::table('request_state_changes')
            ->where('to_status', 'awaiting_client_clarification')
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('request_id');

        if ($requestIds->isEmpty()) {
            return ['key' => null, 'facts' => ''];
        }

        // Серия идёт по типам деталей из базы знаний опознания («Кнопка
        // лифтовая», «Отводка дверная»), а не по крупным группам: группа
        // «Прочее» дала пост ни о чём (29.09), а общие советы «артикул, бренд,
        // фото» повторялись из выпуска в выпуск с разными процентами.
        $categories = DB::table('request_items as ri')
            ->join('equipment_categories as ec', 'ec.id', '=', 'ri.identification_category_id')
            ->whereIn('ri.request_id', $requestIds)
            ->where('ri.is_active', true)
            ->where('ec.is_active', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('identification_rules as ir')
                ->whereColumn('ir.category_id', 'ec.id')->where('ir.is_active', true))
            ->selectRaw('ec.slug, COUNT(*) AS c')
            ->groupBy('ec.slug')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_CATEGORY_ITEMS])
            ->orderByDesc('c')
            ->pluck('c', 'ec.slug');

        if ($categories->isEmpty()) {
            return ['key' => null, 'facts' => ''];
        }

        $keys = $categories->keys()->map(fn ($slug) => self::TIPS_KEY_PREFIX.$slug)->all();
        $key = $this->nextCategory($topic, $keys, $this->coarseGroups($categories->keys()->all()));
        if ($key === null) {
            return ['key' => null, 'facts' => ''];
        }
        $category = DB::table('equipment_categories')->where('slug', substr($key, strlen(self::TIPS_KEY_PREFIX)))->first();
        if ($category === null) {
            return ['key' => null, 'facts' => ''];
        }

        $items = DB::table('request_items')
            ->whereIn('request_id', $requestIds)
            ->where('is_active', true)
            ->where('identification_category_id', $category->id);
        $asked = (int) (clone $items)->distinct()->count('request_id');

        $lines = [
            'ТЕМА ВЫПУСКА: как оформить заявку на «'.$category->name.'».',
            'Что это: '.trim((string) $category->description),
            'За 90 дней по заявкам с такой деталью нам пришлось переспрашивать клиента '.$asked.' раз.',
            '',
            'КАК МЫ ОПОЗНАЁМ ТАКУЮ ДЕТАЛЬ (база знаний; достаточно ОДНОГО из путей, они перечислены по удобству):',
        ];
        $lines = array_merge($lines, $this->identificationPaths((int) $category->id));

        $questions = $this->managerQuestions((int) $category->id, $since);
        if ($questions !== []) {
            $lines[] = '';
            $lines[] = 'ВОПРОСЫ, КОТОРЫЕ МЕНЕДЖЕРЫ РЕАЛЬНО ЗАДАВАЛИ КЛИЕНТАМ по таким заявкам '
                .'(часть может касаться других позиций заявки или доставки — бери только то, что про эту деталь):';
            foreach ($questions as $q) {
                $lines[] = '— '.$q;
            }
        }

        // Как клиенты пишут такие позиции — короткие строки без артикула
        // показательнее всего: именно из-за них и начинается переписка.
        $written = (clone $items)
            ->whereNotNull('parsed_name')
            ->orderByRaw('length(parsed_name)')
            ->limit(8)
            ->pluck('parsed_name')
            ->map(fn ($n) => $this->short($n, 80))
            ->unique()
            ->values();
        if ($written->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'ТАК ЭТИ ПОЗИЦИИ ВЫГЛЯДЯТ В ЗАЯВКАХ (по ним и приходится переспрашивать):';
            foreach ($written as $name) {
                $lines[] = '— '.$name;
            }
        }

        // Чем позиции различаются в каталоге — по этим названиям видно,
        // какие признаки делают выбор однозначным, и из них строится пример.
        $catalog = DB::table('request_items as ri')
            ->join('catalog_items as ci', 'ci.id', '=', 'ri.catalog_item_id')
            ->whereIn('ri.request_id', $requestIds)
            ->where('ri.identification_category_id', $category->id)
            ->distinct()
            ->limit(10)
            ->pluck('ci.name')
            // Служебные пометки каталога («(ЗАМЕНЕНО НА M17280)», наши M-артикулы) — не для клиента:
            // модель брала такую строку примером заявки (пост 22.10).
            ->map(fn ($n) => trim((string) preg_replace(['/\((?:ЗАМЕНЕНО|ЗАМЕНЁН|ЗАМЕНА)[^)]*\)/iu', '/\bM\d{5}\b/u', '/\s{2,}/u'], ['', '', ' '], (string) $n)))
            ->filter()
            ->map(fn ($n) => $this->short($n, 90))
            ->unique()
            ->values();
        if ($catalog->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'ТАК ОНИ НАЗЫВАЮТСЯ В НАШЕМ КАТАЛОГЕ (чем отличаются друг от друга):';
            foreach ($catalog as $name) {
                $lines[] = '— '.$name;
            }
        }

        $lines[] = '';
        $lines[] = 'УЖЕ БЫЛО В ПРОШЛЫХ ВЫПУСКАХ, НЕ ПОВТОРЯТЬ: общие советы «укажите артикул, бренд, '
            .'количество, приложите фото» и проценты заявок без них.';

        return ['key' => $key, 'facts' => implode("\n", $lines)];
    }

    /**
     * Пути опознания типа детали из базы знаний: для каждого правила —
     * альтернативы с параметрами, их вариантами и подсказками.
     *
     * @return list<string>
     */
    private function identificationPaths(int $categoryId): array
    {
        $params = DB::table('identification_parameters')->where('is_active', true)->get()->keyBy('id');
        $lines = [];
        foreach (DB::table('identification_rules')->where('category_id', $categoryId)->where('is_active', true)->orderBy('priority')->get() as $rule) {
            // В правиле — id марок (manufacturer_brands), не названия: в пост уходило «Для марок 1:».
            $brandIds = json_decode((string) $rule->applies_to_brands, true);
            $brands = is_array($brandIds) && $brandIds !== []
                ? \App\Models\Kb\ManufacturerBrand::query()->whereIn('id', $brandIds)->orderBy('name')->pluck('name')->all()
                : [];
            $lines[] = $brands !== []
                ? 'Для марок '.implode(', ', $brands).':'
                : 'В общем случае:';
            foreach (DB::table('identification_rule_alternatives')->where('rule_id', $rule->id)->orderBy('preference_order')->get() as $alt) {
                $parts = [];
                foreach (json_decode((string) $alt->required_parameter_ids, true) ?: [] as $pid) {
                    $p = $params[$pid] ?? null;
                    if ($p === null) {
                        continue;
                    }
                    $values = collect(json_decode((string) $p->allowed_values, true) ?: [])
                        ->map(fn ($v) => is_array($v) ? ($v['label'] ?? $v['value'] ?? null) : $v)->filter()->take(8)->implode(', ');
                    $parts[] = $p->name
                        .($p->unit ? ', '.$p->unit : '')
                        .($values !== '' ? ' (варианты: '.$values.')' : '')
                        .(trim((string) $p->description) !== '' ? ' — '.$this->short($p->description, 140) : '');
                }
                if ($parts !== []) {
                    $lines[] = '  • '.$alt->label.': '.implode('; ', $parts);
                }
            }
        }

        return $lines;
    }

    /**
     * Вопросы, с которыми менеджеры переводили заявки с этой деталью в «Жду
     * клиента»: наше письмо за два часа до перехода, без подписи.
     *
     * @return list<string>
     */
    private function managerQuestions(int $categoryId, \DateTimeInterface $since): array
    {
        $rows = DB::select(
            "select distinct on (sc.request_id) sc.request_id, sc.created_at
               from request_state_changes sc
               join request_items ri on ri.request_id = sc.request_id and ri.is_active and ri.identification_category_id = ?
              where sc.to_status = 'awaiting_client_clarification' and sc.created_at >= ?
              order by sc.request_id, sc.created_at desc
              limit 80",
            [$categoryId, $since],
        );

        $out = [];
        foreach ($rows as $row) {
            $at = \Illuminate\Support\Carbon::parse($row->created_at);
            $body = DB::table('email_messages')
                ->where('related_request_id', $row->request_id)
                ->where('direction', 'outbound')
                ->whereBetween('sent_at', [$at->copy()->subHours(2), $at->copy()->addMinutes(10)])
                ->orderByDesc('sent_at')
                ->value('body_plain');
            $text = self::questionText((string) $body);
            if ($text !== null && ! in_array($text, $out, true)) {
                $out[] = $text;
            }
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    /** Текст вопроса менеджера без подписи и цитаты; null — вопроса нет. */
    public static function questionText(string $body): ?string
    {
        $text = str_replace("\r", '', $body);
        foreach (["\n-- ", "\n--\n", 'С уважением', 'With best regards', 'Best regards', "\n>", "\nОт:", "\nFrom:", '-----', '_____', 'Идентификатор участника ЭДО', 'ЭДО ('] as $marker) {
            $pos = mb_stripos($text, $marker);
            if ($pos !== false) {
                $text = mb_substr($text, 0, $pos);
            }
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        // Хвост цитаты «06.07.2026 13:25, Игорь Тюренков пишет:» / «wrote:».
        $text = trim(preg_replace('~\s*\d{1,2}\.\d{1,2}\.\d{2,4},?\s+(в\s+)?\d{1,2}:\d{2}.*$~u', '', $text) ?? $text);
        $text = trim(preg_replace('~\s*[^.?!]*\b(пишет|написал\(а\)|wrote):?\s*$~ui', '', $text) ?? $text);
        if ($text === '' || preg_match('~успешно получено|уточняющие вопросы по заявке~u', $text)) {
            return null;
        }
        // Нужен вопрос или просьба прислать/указать — иначе это не уточнение.
        if (! preg_match('~\?|прошу|пришлите|укажите|уточните|необходим|нужн|фото~ui', $text)) {
            return null;
        }

        return mb_strimwidth($text, 0, 220, '…');
    }


    /** Столько дней после выпуска группа деталей считается недавней темой. */
    private const GROUP_COOLDOWN_DAYS = 21;

    private const OTHER_GROUP = 'Прочее';

    /**
     * Крупная группа («Кнопки и индикация») для каждой категории базы знаний —
     * та, в которую разбор заявок чаще всего относит её позиции.
     *
     * Нужна, чтобы узнавать похожие темы: до 29.09 серия шла по этим группам,
     * после — по категориям, и «Кнопка лифтовая» (01.10) вышла через два дня
     * после «Кнопки и индикация» (29.09) — ключи разные, проверка повтора
     * их не связала. Так же рядом встали бы «Кнопка» и «Табло» одной группы.
     *
     * @param  list<string>  $slugs
     * @return array<string, string>  ключ выпуска (kb:slug) → группа
     */
    private function coarseGroups(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $rows = DB::table('request_items as ri')
            ->join('equipment_categories as ec', 'ec.id', '=', 'ri.identification_category_id')
            ->whereIn('ec.slug', $slugs)
            ->whereNotNull('ri.category')
            // «Прочее» — остаток, а не тема: похожими по нему ничего не считаем.
            ->whereNotIn('ri.category', ['', self::OTHER_GROUP])
            ->where('ri.created_at', '>=', now()->subDays(180))
            ->selectRaw('ec.slug, ri.category, COUNT(*) AS c')
            ->groupBy('ec.slug', 'ri.category')
            ->orderByDesc('c')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[self::TIPS_KEY_PREFIX.$r->slug] ??= (string) $r->category;
        }

        return $out;
    }

    /**
     * Следующая категория серии: первая неразобранная, иначе разобранная
     * раньше всех. Категорию, чья группа деталей была темой последние
     * GROUP_COOLDOWN_DAYS дней, откладываем, пока есть другие.
     *
     * @param  list<string>  $ordered  категории по убыванию числа уточнений
     * @param  array<string, string>  $groupOf  категория → крупная группа
     */
    private function nextCategory(MediaTopic $topic, array $ordered, array $groupOf = []): ?string
    {
        // Один выпуск — одна категория во всех каналах. Каналы пишутся по
        // очереди, и второй брал «следующую неразобранную»: 29.09 ВКонтакте
        // получил кнопки, а Telegram — «Прочее».
        $sameRelease = MediaPublication::query()
            ->where('media_topic_id', $topic->id)
            ->whereNotNull('subject_key')
            ->where('created_at', '>=', now()->subHours(12))
            ->orderByDesc('id')
            ->value('subject_key');
        if ($sameRelease !== null && in_array($sameRelease, $ordered, true)) {
            return $sameRelease;
        }

        $covered = MediaPublication::query()
            ->where('media_topic_id', $topic->id)
            ->whereNotNull('subject_key')
            ->orderByDesc('id')
            ->pluck('subject_key')
            ->all();

        // Группы недавних выпусков. Старые ключи серии — сами группы
        // («Кнопки и индикация»), новые — категории, их группу берём из карты.
        $recentGroups = MediaPublication::query()
            ->where('media_topic_id', $topic->id)
            ->whereNotNull('subject_key')
            ->where('created_at', '>=', now()->subDays(self::GROUP_COOLDOWN_DAYS))
            ->pluck('subject_key')
            ->map(fn ($k) => str_starts_with((string) $k, self::TIPS_KEY_PREFIX) ? ($groupOf[$k] ?? null) : $k)
            ->filter(fn ($g) => $g !== null && $g !== self::OTHER_GROUP)
            ->unique()
            ->all();

        $fresh = array_values(array_filter($ordered, fn ($c) => ! in_array($c, $covered, true)));
        foreach ($fresh as $category) {
            if (! in_array($groupOf[$category] ?? null, $recentGroups, true)) {
                return $category;
            }
        }
        if ($fresh !== []) {
            return $fresh[0];
        }

        // Все разобраны — берём ту, что разбирали дольше всех.
        $oldest = MediaPublication::query()
            ->where('media_topic_id', $topic->id)
            ->whereNotNull('subject_key')
            ->whereIn('subject_key', $ordered)
            ->orderBy('id')
            ->value('subject_key');

        return $oldest ?: ($ordered[0] ?? null);
    }
}
