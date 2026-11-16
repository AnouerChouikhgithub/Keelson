<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ApiException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decodes a JSON request body into an associative array.
 *
 * Every API write goes through here so "the body was not JSON" has one status
 * and one code (`400 invalid_json`) instead of the 422 each controller used to
 * invent. A JSON list or scalar is rejected too: the API takes objects.
 */
final class JsonBody
{
    /** @return array<string, mixed> */
    public static function decode(Request $request): array
    {
        $content = (string) $request->getContent();
        if ('' === trim($content)) {
            throw ApiException::invalidJson();
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::invalidJson();
        }

        if (!\is_array($data) || array_is_list($data)) {
            throw ApiException::invalidJson();
        }

        return $data;
    }
}
