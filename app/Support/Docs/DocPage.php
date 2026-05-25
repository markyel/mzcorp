<?php

namespace App\Support\Docs;

/**
 * DTO одной страницы документации. Конструируется DocsService'ом
 * по markdown-файлу из resources/docs/{section}/{slug}.md.
 */
final class DocPage
{
    /**
     * @param array<int, string> $roles Доступные роли (значения enum Role). Пусто = доступно всем authed.
     */
    public function __construct(
        public readonly string $section,   // 'manager' / 'rop' / 'secretary' / 'director' / 'common'
        public readonly string $slug,      // 'request-lifecycle'
        public readonly string $title,     // 'Работа с заявкой'
        public readonly int $order,        // позиция в sidebar
        public readonly string $excerpt,   // короткое описание для overview
        public readonly array $roles,
        public readonly string $body,      // raw markdown без frontmatter
    ) {
    }

    /**
     * Доступна ли страница пользователю с указанными ролями.
     * Админ всегда видит всё. Пустой $this->roles = публично для authed.
     *
     * @param array<int, string> $userRoles
     */
    public function isVisibleTo(array $userRoles): bool
    {
        if (in_array('admin', $userRoles, true)) {
            return true;
        }
        if ($this->roles === []) {
            return true;
        }
        return array_intersect($this->roles, $userRoles) !== [];
    }
}
