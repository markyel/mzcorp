<?php

namespace App\Support\Docs;

/**
 * Группа разделов sidebar'а — соответствует роли (manager/rop/secretary/director)
 * или 'common'. Содержит упорядоченный список DocPage.
 */
final class DocSection
{
    /**
     * @param array<int, DocPage> $pages
     */
    public function __construct(
        public readonly string $key,       // 'manager'
        public readonly string $title,     // 'Менеджер'
        public readonly int $order,
        public readonly array $pages,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->pages === [];
    }
}
