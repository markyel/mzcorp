<?php

namespace App\Services\Marketing;

use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Анонсовая обложка поста — одна сгенерированная картинка, своя для каждого
 * поста и по его содержанию.
 *
 * Фото новинок в каталоге — чаще всего снимки клиентских деталей из заявок:
 * грязные, на оборудовании, с монтажной пеной. Заказчик (12.10.2026): к постам
 * — обложка, сначала к ассортиментным, затем ко всем типам. Пост с артикулами
 * — на обложке эти детали, обезличенные: тип узнаваем, но ни модели, ни
 * маркировки, обложка ничего не обещает о внешнем виде позиции. Пост без
 * артикулов (совет, новость, дайджест) — сцена по смыслу текста.
 * Текст на картинку модель не пишет (кириллицу она путает) — заголовок
 * накладываем сами шрифтом PT Sans.
 */
class MediaCoverService
{
    /** Ассортиментные подборки: обложка из деталей поста. */
    public const SOURCES = ['catalog_new', 'catalog_price', 'stock_arrivals', 'weekly_roundup'];

    private const DIR = 'media-covers';

    /** Сколько деталей просим нарисовать: больше — каша на картинке. */
    private const MAX_PARTS = 5;

    private const SKU_RE = '/\bM\d{4,6}\b/u';

    public function __construct(private readonly OpenAIChatService $openai)
    {
    }

    /** Обложки включены: ко всем постам площадок, куда публикуем сами. */
    public static function enabled(): bool
    {
        return (bool) config('services.openai.media_cover.enabled', true);
    }

    /**
     * @return array{ok: bool, url: ?string, message: string}
     */
    public function make(string $title, string $body, ?string $source): array
    {
        $names = in_array($source, self::SOURCES, true) ? $this->itemNames($body) : [];
        if ($names === [] && trim($title.$body) === '') {
            return ['ok' => false, 'url' => null, 'message' => 'пустой пост — не из чего собрать обложку'];
        }

        try {
            $prompt = $names !== []
                ? $this->partsPrompt($this->genericParts($names), $source)
                : $this->scenePrompt($this->scene($title, $body, $source));
            $image = $this->generate($prompt);
            $jpeg = $this->compose($image, $title);
        } catch (\Throwable $e) {
            Log::warning('MediaCoverService: cover failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'url' => null, 'message' => $e->getMessage()];
        }

        $name = Str::uuid()->toString().'.jpg';
        Storage::disk('public')->put(self::DIR.'/'.$name, $jpeg);

        return ['ok' => true, 'url' => route('media.cover', ['name' => $name]), 'message' => ''];
    }

    /** Удалить файл нашей обложки (при замене в черновике). Чужие адреса не трогаем. */
    public function forget(string $url): void
    {
        $prefix = rtrim(route('media.cover', ['name' => 'x']), 'x');
        if (str_starts_with($url, $prefix)) {
            $name = basename(substr($url, strlen($prefix)));
            if (self::validName($name)) {
                Storage::disk('public')->delete(self::DIR.'/'.$name);
            }
        }
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-f0-9-]{36}\.jpg$/', $name);
    }

    public static function path(string $name): string
    {
        return Storage::disk('public')->path(self::DIR.'/'.$name);
    }

    /**
     * Названия позиций из текста поста, в порядке упоминания.
     *
     * @return list<string>
     */
    private function itemNames(string $body): array
    {
        if (! preg_match_all(self::SKU_RE, $body, $m)) {
            return [];
        }
        $skus = array_values(array_unique($m[0]));
        $rows = DB::table('catalog_items')->whereIn('sku', $skus)->get(['sku', 'name', 'part_type'])->keyBy('sku');

        return collect($skus)
            ->map(function ($sku) use ($rows) {
                $r = $rows[$sku] ?? null;

                return $r === null ? null : trim(($r->part_type ? $r->part_type.': ' : '').$r->name);
            })
            ->filter()
            ->take(self::MAX_PARTS)
            ->values()
            ->all();
    }

    /**
     * Обезличенные описания деталей на английском: без брендов, моделей и
     * номеров — иначе модель рисует маркировку и «конкретную» деталь.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function genericParts(array $names): array
    {
        $res = $this->openai->chat([
            ['role' => 'system', 'content' => 'You turn Russian names of elevator and escalator spare parts into short generic English visual descriptions for an illustration. '
                .'Describe only the kind of object and its typical look (shape, material, colour). Drop brands, model names, part numbers, voltages and any text. '
                .'Answer JSON {"parts": ["...", ...]}, one item per input line, at most 12 words each.'],
            ['role' => 'user', 'content' => implode("\n", $names)],
        ], (string) config('services.openai.media_cover.scene_model', 'gpt-4o-mini'), ['response_format' => ['type' => 'json_object'], 'temperature' => 0.2]);

        $parts = array_values(array_filter(array_map(
            fn ($p) => is_string($p) ? trim($p) : '',
            (array) (json_decode((string) ($res['content'] ?? ''), true)['parts'] ?? []),
        )));
        if ($parts === []) {
            throw new \RuntimeException('не удалось описать детали для обложки');
        }

        return array_slice($parts, 0, self::MAX_PARTS);
    }

    /** @param  list<string>  $parts */
    private function partsPrompt(array $parts, ?string $source): string
    {
        $occasion = match ($source) {
            'catalog_price' => 'a price reduction announcement',
            'stock_arrivals' => 'a new stock arrival announcement, with a hint of a tidy warehouse shelf in the soft-focus background',
            'weekly_roundup' => 'a weekly review of new items, price drops and stock arrivals',
            default => 'an announcement of new catalog items',
        };

        return 'Editorial product composition for '.$occasion.' of an elevator and escalator spare parts supplier. '
            .'Objects, generic and unbranded: '.implode('; ', $parts).'. '
            .'Arrange them neatly on a clean light-grey studio surface, soft diffused light, gentle shadows, shallow depth of field, '
            .'photorealistic, crisp detail, premium catalog look, new and clean parts. '
            .self::RESTRICTIONS;
    }

    private function scenePrompt(string $scene): string
    {
        return 'Editorial illustration for a social media post of an elevator and escalator spare parts supplier. '
            .'Scene: '.$scene.' '
            .'Photorealistic, natural soft light, clean modern look, calm colours, shallow depth of field. '
            .'People only if the scene needs them: seen from behind or at a distance, no identifiable faces. '
            .self::RESTRICTIONS;
    }

    private const RESTRICTIONS = 'Strictly no text, letters, digits, logos, brand names, labels, stickers, signs, QR codes or markings anywhere in the image. '
        .'Keep the bottom third of the frame calm and uncluttered for a caption.';

    /**
     * Сцена по смыслу поста без артикулов: совет — деталь из совета и рабочее
     * место, новость отрасли — лифты, эскалаторы, здания. Модель описывает
     * картинку по-английски, без брендов и надписей.
     */
    private function scene(string $title, string $body, ?string $source): string
    {
        $kind = match ($source) {
            'request_tips' => 'a practical tip for customers on how to request a spare part',
            'industry_digest' => 'a digest of elevator industry news',
            'weekly_roundup' => 'a weekly review of new catalog items, price drops and stock arrivals',
            'news' => 'company news',
            default => 'a post',
        };

        $res = $this->openai->chat([
            ['role' => 'system', 'content' => 'You design a cover picture for a Russian social media post of an elevator and escalator spare parts supplier. '
                .'Read the post and describe ONE concrete photorealistic scene that matches its main subject (2 sentences, English). '
                .'If the post is about a specific kind of part, show that kind of part, generic and unbranded. '
                .'Industry news: elevators, escalators, lift shafts, machine rooms, modern buildings. '
                .'No text, signs, logos, brands, screens with text, documents with text, recognisable landmarks or identifiable faces. '
                .'Answer JSON {"scene": "..."}.'],
            ['role' => 'user', 'content' => 'Type: '.$kind."\nTitle: ".$title."\n\n".mb_substr($body, 0, 3000)],
        ], (string) config('services.openai.media_cover.scene_model', 'gpt-4o-mini'), ['response_format' => ['type' => 'json_object'], 'temperature' => 0.4]);

        $scene = trim((string) (json_decode((string) ($res['content'] ?? ''), true)['scene'] ?? ''));
        if ($scene === '') {
            throw new \RuntimeException('не удалось придумать сцену для обложки');
        }

        return $scene;
    }

    /** Картинка от модели (байты). */
    private function generate(string $prompt): string
    {
        $headers = ['Authorization' => 'Bearer '.config('services.openai.api_key')];
        if ($pk = (string) config('services.openai.proxy_key')) {
            $headers['X-Proxy-Key'] = $pk;
        }

        $r = Http::withHeaders($headers)
            ->timeout((int) config('services.openai.media_cover.timeout', 150))
            ->post(rtrim((string) config('services.openai.base_url'), '/').'/v1/images/generations', [
                'model' => (string) config('services.openai.media_cover.model', 'gpt-image-1'),
                'prompt' => $prompt,
                'size' => '1536x1024',
                'quality' => (string) config('services.openai.media_cover.quality', 'medium'),
                'output_format' => 'jpeg',
                'n' => 1,
            ]);

        $b64 = (string) ($r->json('data.0.b64_json') ?? '');
        if (! $r->successful() || $b64 === '') {
            throw new \RuntimeException('генерация картинки: '.($r->json('error.message') ?? 'HTTP '.$r->status()));
        }

        return (string) base64_decode($b64);
    }

    /**
     * Заголовок поверх картинки: затемнение снизу, красная плашка-акцент,
     * заголовок PT Sans Bold, подпись myzip.ru.
     */
    public function compose(string $image, string $title): string
    {
        $img = @imagecreatefromstring($image);
        if ($img === false) {
            throw new \RuntimeException('картинка не читается');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        // Эмодзи в PT Sans нет — вместо них были бы квадраты.
        $title = trim((string) preg_replace(['/[\p{So}\p{Sk}\p{Cs}\x{FE0F}\x{200D}]+/u', '/\s+/u'], ['', ' '], $title));

        // Затемнение нижних 45% — от прозрачного к ~70% чёрного.
        $from = (int) round($h * 0.55);
        for ($y = $from; $y < $h; $y++) {
            $t = ($y - $from) / max(1, $h - $from);
            $alpha = (int) round(127 - 90 * $t);
            imagefilledrectangle($img, 0, $y, $w, $y, imagecolorallocatealpha($img, 10, 12, 16, $alpha));
        }

        $bold = resource_path('fonts/PTSans-Bold.ttf');
        $regular = resource_path('fonts/PTSans-Regular.ttf');
        $white = imagecolorallocate($img, 255, 255, 255);
        $soft = imagecolorallocatealpha($img, 255, 255, 255, 30);
        $red = imagecolorallocate($img, 0xD3, 0x20, 0x27);

        $margin = (int) round($w * 0.06);
        $maxWidth = $w - 2 * $margin - 24;
        $size = $h * 0.075;
        $lines = $this->wrap($title, $bold, $size, $maxWidth);
        while (count($lines) > 2 && $size > $h * 0.045) {
            $size *= 0.9;
            $lines = $this->wrap($title, $bold, $size, $maxWidth);
        }
        $lines = array_slice($lines, 0, 2);

        $lineH = (int) round($size * 1.25);
        $bottom = $h - (int) round($h * 0.11);
        $top = $bottom - $lineH * count($lines);

        imagefilledrectangle($img, $margin, $top + (int) round($size * 0.15), $margin + 9, $bottom - (int) round($size * 0.2), $red);
        foreach ($lines as $i => $line) {
            imagettftext($img, $size, 0, $margin + 24, $top + $lineH * ($i + 1) - (int) round($size * 0.25), $white, $bold, $line);
        }
        imagettftext($img, $h * 0.03, 0, $margin + 24, $h - (int) round($h * 0.05), $soft, $regular, 'myzip.ru · запчасти для лифтов и эскалаторов');

        ob_start();
        imagejpeg($img, null, 88);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, float $size, int $maxWidth): array
    {
        $lines = [];
        $line = '';
        // Короткое слово («на», «за», «и») не оставляем в конце строки — клеим
        // к следующему, как неразрывный пробел в вёрстке.
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $glued = [];
        foreach ($words as $word) {
            $prev = end($glued);
            if ($prev !== false && mb_strlen($prev) <= 2 && preg_match('/^\p{L}+$/u', $prev) && ! str_contains($prev, ' ')) {
                $glued[count($glued) - 1] = $prev.' '.$word;
            } else {
                $glued[] = $word;
            }
        }
        foreach ($glued as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            $box = imagettfbbox($size, 0, $font, $try);
            if ($line !== '' && ($box[2] - $box[0]) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }
}
