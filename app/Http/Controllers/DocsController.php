<?php

namespace App\Http\Controllers;

use App\Services\Docs\DocsService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SfResponse;

class DocsController extends Controller
{
    /**
     * Карта дружелюбных имён → файлы мокапов в design/ui_kits/crm/.
     * Используется в `preview()` для встраивания через iframe в гайды.
     */
    private const PREVIEW_MAP = [
        'pool' => '01-pool',
        'dashboard' => '02-dashboard',
        'requests' => '03-requests',
        'request-detail' => '04-request-detail',
        'request-positions' => '04b-request-positions',
        'request-answered' => '04c-request-answered',
    ];

    public function __construct(private readonly DocsService $docs)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $sections = $this->docs->visibleSections($user);

        // Если у пользователя есть «своя» секция по роли — открываем её первую страницу
        // как «домашнюю». Иначе — просто landing с обзором всех доступных разделов.
        $homePage = null;
        $preferredSectionKey = $this->preferredSectionForUser($user);
        if ($preferredSectionKey) {
            foreach ($sections as $s) {
                if ($s->key === $preferredSectionKey && ! $s->isEmpty()) {
                    $homePage = $s->pages[0];
                    break;
                }
            }
        }

        if ($homePage) {
            return redirect()->route('docs.show', ['section' => $homePage->section, 'slug' => $homePage->slug]);
        }

        return view('docs.index', [
            'sections' => $sections,
        ]);
    }

    public function show(Request $request, string $section, string $slug)
    {
        $user = $request->user();
        $page = $this->docs->findPage($user, $section, $slug);
        abort_unless($page, 404);

        return view('docs.show', [
            'sections' => $this->docs->visibleSections($user),
            'page' => $page,
            'html' => $this->docs->renderHtml($page),
        ]);
    }

    /**
     * Отдать HTML-мокап UI-экрана из `design/ui_kits/crm/` для встраивания
     * в гайды через iframe. Доступ — только авторизованным (route защищён
     * `auth` middleware). Контент-Type text/html, inline; X-Frame-Options
     * SAMEORIGIN для безопасности iframe'ов на нашем же домене.
     */
    public function preview(string $name): SfResponse
    {
        if (! isset(self::PREVIEW_MAP[$name])) {
            abort(404);
        }
        $file = self::PREVIEW_MAP[$name];
        $path = base_path("design/ui_kits/crm/{$file}.html");
        if (! file_exists($path) || ! is_readable($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * По первой подходящей роли пользователя возвращаем ключ секции,
     * с которой логично начать просмотр документации.
     */
    private function preferredSectionForUser($user): ?string
    {
        $roles = $user->getRoleNames()->all();
        $map = [
            'manager' => 'manager',
            'head_of_sales' => 'rop',
            'secretary' => 'secretary',
            'director' => 'director',
        ];
        foreach ($map as $role => $section) {
            if (in_array($role, $roles, true)) {
                return $section;
            }
        }
        // Админ без специфической бизнес-роли — стартуем с общего обзора.
        return null;
    }
}
