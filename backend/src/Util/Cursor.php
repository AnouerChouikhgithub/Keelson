<?php

declare(strict_types=1);

namespace App\Util;

use App\Exception\ApiException;

/**
 * Opaque keyset-pagination cursor: base64url of `{"id": uuid, "createdAt": iso}`.
 *
 * Keyset pagination is used instead of OFFSET because offsets get slower and
 * skip or repeat rows when new records land; the pair (created_at, id) is unique
 * per row, so a page can resume exactly after the last row that was sent.
 */
final class Cursor
{
    /**
     * @return array{id: string, createdAt: \DateTimeImmutable}
     *
     * @throws ApiException 400 invalid_cursor
     */
    public static function decode(?string $encoded, string $field = 'cursor'): ?array
    {
        if (null === $encoded || '' === $encoded) {
            return null;
        }
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (false === $raw) {
            throw ApiException::invalidCursor($field);
        }
        try {
            $cursor = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::invalidCursor($field);
        }
        if (!is_array($cursor) || !is_string($cursor['id'] ?? null) || !is_string($cursor['createdAt'] ?? null) || !Uuid::isValid($cursor['id'])) {
            throw ApiException::invalidCursor($field);
        }
        try {
            $createdAt = new \DateTimeImmutable($cursor['createdAt']);
        } catch (\Exception) {
            throw ApiException::invalidCursor($field);
        }

        return ['id' => $cursor['id'], 'createdAt' => $createdAt];
    }

    public static function encode(\DateTimeImmutable $createdAt, string $id): string
    {
        $json = json_encode(['id' => $id, 'createdAt' => $createdAt->format(DATE_ATOM)], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
