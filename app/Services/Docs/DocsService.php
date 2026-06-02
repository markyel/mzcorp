<?php

namespace App\Services\Docs;

use App\Models\User;
use App\Support\Docs\DocPage;
use App\Support\Docs\DocSection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Environment\Environment;

/**
 * Загружает гайды из resources/docs/{section}/{slug}.md, парсит YAML-frontmatter
 * и рендерит markdown в HTML.
 *
 * Кеш — по mtime директории resources/docs (key зависит от latest mtime), чтобы
 * не пересобирать дерево на каждый запрос, но и не подгружать через `view:clear`
 * после правки markdown.
 *
 * Frontmatter формат:
 *   ---
 *   title: Работа с заявкой
 *   order: 30
 *   excerpt: Жизненный цикл, переходы статусов.
 *   roles: [manager, head_of_sales]
 *   ---
 *
 *   # H1 если нужен, контент…
 */
class DocsService
{
    /** Меттаданные секций (порядок и человеческий title). */
    private const SECTIONS = [
        'common'    => ['title' => 'Общее',     'order' => 0],
        'manager'   => ['title' => 'Менеджер',  'order' => 10],
        'rop'       => ['title' => 'РОП',       'order' => 20],
        'secretary' => ['title' => 'Секретарь', 'order' => 30],
        'director'  => ['title' => 'Директорат', 'order' => 40],
    ];

    public function rootPath(): string
    {
        return resource_path('docs');
    }

    /**
     * Все секции с страницами, видимыми пользователю. Пустые секции отброшены.
     *
     * @return array<int, DocSection>
     */
    public function visibleSections(User $user): array
    {
        $roles = $user->getRoleNames()->all();

        $sections = [];
        foreach ($this->allSections() as $section) {
            $visible = array_values(array_filter(
                $section->pages,
                fn (DocPage $p) => $p->isVisibleTo($roles),
            ));
            if ($visible === []) {
                continue;
            }
            $sections[] = new DocSection($section->key, $section->title, $section->order, $visible);
        }
        return $sections;
    }

    /**
     * Найти страницу по ключу секции и slug'у. Возвращает null только если
     * файла нет.
     *
     * Гайды внутренние и плотно перелинкованы между ролями: `common/*`
     * (видны всем) ссылаются на `manager/*` и `rop/*`, разделы ссылаются друг
     * на друга. Поэтому ОТКРЫТЬ страницу по прямой ссылке может любой authed-
     * пользователь — иначе кросс-ссылки давали 404 у ролей без доступа к
     * целевой секции. Ролевой фильтр остаётся в `visibleSections()`: он
     * формирует боковое меню (навигацию по умолчанию), но не блокирует
     * переход по ссылке.
     */
    public function findPage(User $user, string $section, string $slug): ?DocPage
    {
        foreach ($this->allSections() as $s) {
            if ($s->key !== $section) {
                continue;
            }
            foreach ($s->pages as $p) {
                if ($p->slug === $slug) {
                    return $p;
                }
            }
        }
        return null;
    }

    /**
     * Рендер markdown-тела страницы в HTML. Frontmatter уже снят при загрузке.
     */
    public function renderHtml(DocPage $page): string
    {
        return $this->converter()->convert($page->body)->getContent();
    }

    /**
     * Все секции без фильтрации по роли. Кешируются по mtime директории.
     *
     * @return array<int, DocSection>
     */
    private function allSections(): array
    {
        $root = $this->rootPath();
        if (! File::isDirectory($root)) {
            return [];
        }

        $cacheKey = 'docs:tree:' . $this->treeFingerprint($root);

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($root) {
            $out = [];
            foreach (self::SECTIONS as $key => $meta) {
                $dir = $root . DIRECTORY_SEPARATOR . $key;
                if (! File::isDirectory($dir)) {
                    continue;
                }
                $pages = [];
                foreach (File::files($dir) as $file) {
                    if ($file->getExtension() !== 'md') {
                        continue;
                    }
                    $slug = $file->getFilenameWithoutExtension();
                    if (str_starts_with($slug, '_')) {
                        // _index.md и т.п. — служебные.
                        continue;
                    }
                    $page = $this->loadPage($key, $slug, $file->getPathname());
                    if ($page !== null) {
                        $pages[] = $page;
                    }
                }
                usort($pages, fn (DocPage $a, DocPage $b) => $a->order <=> $b->order ?: strcmp($a->title, $b->title));
                $out[] = new DocSection($key, $meta['title'], $meta['order'], $pages);
            }
            usort($out, fn (DocSection $a, DocSection $b) => $a->order <=> $b->order);
            return $out;
        });
    }

    /**
     * Хеш по mtime всех md-файлов под docs/. Меняется когда правят контент,
     * без необходимости вручную сбрасывать кеш.
     */
    private function treeFingerprint(string $root): string
    {
        $latest = 0;
        $count = 0;
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }
            $latest = max($latest, $file->getMTime());
            $count++;
        }
        return $count . '-' . $latest;
    }

    private function loadPage(string $section, string $slug, string $path): ?DocPage
    {
        $raw = File::get($path);
        [$frontmatter, $body] = $this->splitFrontmatter($raw);

        $title = (string) ($frontmatter['title'] ?? $slug);
        $order = (int) ($frontmatter['order'] ?? 100);
        $excerpt = (string) ($frontmatter['excerpt'] ?? '');
        $roles = array_map('strval', (array) ($frontmatter['roles'] ?? []));

        return new DocPage($section, $slug, $title, $order, $excerpt, $roles, $body);
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function splitFrontmatter(string $raw): array
    {
        $raw = ltrim($raw, "\xEF\xBB\xBF"); // BOM
        if (! str_starts_with($raw, "---\n") && ! str_starts_with($raw, "---\r\n")) {
            return [[], $raw];
        }
        // Найти закрывающий --- (на отдельной строке).
        $lines = preg_split('/\r\n|\n/', $raw);
        $endIdx = null;
        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            if (trim($lines[$i]) === '---') {
                $endIdx = $i;
                break;
            }
        }
        if ($endIdx === null) {
            return [[], $raw];
        }
        $yamlBlock = implode("\n", array_slice($lines, 1, $endIdx - 1));
        $body = implode("\n", array_slice($lines, $endIdx + 1));

        return [$this->parseFrontmatter($yamlBlock), ltrim($body, "\n")];
    }

    /**
     * Минимальный парсер frontmatter'а гайдов. Намеренно НЕ зависит от
     * symfony/yaml: тот пакет dev-only и на проде (composer install --no-dev)
     * отсутствует — раньше Yaml::parse() молча падал в catch, и title слетал
     * на slug. Формат у нас плоский и контролируемый:
     *   key: скалярная строка
     *   key: [a, b, c]   — инлайн-массив (roles)
     *
     * @return array<string, mixed>
     */
    private function parseFrontmatter(string $block): array
    {
        $data = [];
        foreach (preg_split('/\r\n|\n/', $block) ?: [] as $line) {
            if (trim($line) === '' || ! preg_match('/^([A-Za-z_][\w-]*):\s?(.*)$/', $line, $m)) {
                continue;
            }
            $key = $m[1];
            $value = trim($m[2]);

            if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $inner = trim(substr($value, 1, -1));
                $data[$key] = $inner === ''
                    ? []
                    : array_values(array_filter(array_map(
                        fn (string $v) => $this->stripQuotes(trim($v)),
                        explode(',', $inner),
                    ), fn (string $v) => $v !== ''));
                continue;
            }

            $data[$key] = $this->stripQuotes($value);
        }

        return $data;
    }

    private function stripQuotes(string $v): string
    {
        if (strlen($v) >= 2
            && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            return substr($v, 1, -1);
        }

        return $v;
    }

    private function converter(): MarkdownConverter
    {
        $env = new Environment([
            'heading_permalink' => [
                'symbol' => '#',
                'html_class' => 'doc-anchor',
                'insert' => 'after',
                'fragment_prefix' => '',
                'id_prefix' => '',
            ],
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new FrontMatterExtension());
        $env->addExtension(new TableExtension());
        $env->addExtension(new AutolinkExtension());
        $env->addExtension(new HeadingPermalinkExtension());
        return new MarkdownConverter($env);
    }
}
