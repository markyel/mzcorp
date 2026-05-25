<?php

namespace App\Http\Controllers;

use App\Services\Docs\DocsService;
use Illuminate\Http\Request;

class DocsController extends Controller
{
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
