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
     * Роли с полным доступом ко всей документации (видят любые разделы,
     * независимо от frontmatter `roles`): админ + директорат + РОП.
     */
    private const FULL_ACCESS_ROLES = ['admin', 'director', 'head_of_sales'];

    /**
     * Доступна ли страница пользователю с указанными ролями.
     * Админ / директорат / РОП всегда видят всё. Пустой $this->roles =
     * публично для authed.
     *
     * @param array<int, string> $userRoles
     */
    public function isVisibleTo(array $userRoles): bool
    {
        if (array_intersect(self::FULL_ACCESS_ROLES, $userRoles) !== []) {
            return true;
        }
        if ($this->roles === []) {
            return true;
        }
        return array_intersect($this->roles, $userRoles) !== [];
    }
}
