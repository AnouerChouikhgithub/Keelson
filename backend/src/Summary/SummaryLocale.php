<?php

declare(strict_types=1);

namespace App\Summary;

use App\Exception\ApiException;
use App\Http\JsonBody;
use Symfony\Component\HttpFoundation\Request;

/**
 * The language the summary is written in.
 *
 * The API has no user profile field for this, so the caller states it: an
 * optional `{"locale": "fr"}` body wins, then the browser's `Accept-Language`,
 * then English. Only the three languages the product ships are accepted — the
 * grounding check and the fallback templates both depend on knowing which
 * number notation to expect.
 */
final class SummaryLocale
{
    public const DEFAULT = 'en';

    /** @var list<string> */
    public const SUPPORTED = ['en', 'fr', 'ar'];

    public static function fromRequest(Request $request): string
    {
        $content = (string) $request->getContent();
        // `{}` is what a POST with no fields looks like, and it decodes to an empty
        // array, which the shared decoder treats as a JSON list. It means "no
        // preference", so the header decides.
        $emptyObject = '{}' === (string) preg_replace('/\s+/', '', $content);
        if ('' !== trim($content) && !$emptyObject) {
            $body = JsonBody::decode($request);
            if (array_key_exists('locale', $body)) {
                $locale = $body['locale'];
                if (!is_string($locale) || !in_array($locale, self::SUPPORTED, true)) {
                    throw ApiException::validationFailed('The locale must be one of: en, fr, ar.', ['locale' => ['Unsupported locale.']]);
                }

                return $locale;
            }
        }

        return self::fromHeader((string) $request->headers->get('Accept-Language', ''));
    }

    private static function fromHeader(string $header): string
    {
        foreach (explode(',', $header) as $entry) {
            $tag = strtolower(trim(explode(';', $entry)[0]));
            $primary = explode('-', $tag)[0];
            if (in_array($primary, self::SUPPORTED, true)) {
                return $primary;
            }
        }

        return self::DEFAULT;
    }
}
