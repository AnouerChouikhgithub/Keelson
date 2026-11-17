<?php

declare(strict_types=1);

namespace App\Guide;

/**
 * One knowledge-base article, as written in `resources/guide/<locale>/*.md`.
 *
 * The front matter carries the stable `id` the client maps to a label, the localized
 * `title` shown as a source, and the `roles` allowed to see the article. An empty role
 * list means "every role": most of the guide is product documentation, not policy.
 */
final class GuideArticle
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $roles,
        public readonly string $body,
    ) {
    }

    public function isVisibleTo(string $role): bool
    {
        return [] === $this->roles || in_array($role, $this->roles, true);
    }
}
