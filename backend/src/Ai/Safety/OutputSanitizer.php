<?php

declare(strict_types=1);

namespace App\Ai\Safety;

/**
 * Turns a provider's answer into plain text a SPA can render without trusting it.
 *
 * A model can echo HTML, markdown images and links; rendering any of them would let
 * tenant-supplied content produce markup, load a third-party image (a tracking pixel)
 * or navigate a user somewhere unexpected. Markup is stripped, links are reduced to
 * their label, and the result is length-capped before it reaches a response.
 *
 * This is defence in depth, not a licence to render the output as HTML: the client
 * must still show it as text.
 */
final class OutputSanitizer
{
    public const DEFAULT_MAX_LENGTH = 4000;

    public function sanitize(string $output, int $maxLength = self::DEFAULT_MAX_LENGTH): string
    {
        if ($maxLength < 1) {
            throw new \InvalidArgumentException('The length cap must be positive.');
        }

        // Decode first so `&lt;script&gt;` cannot survive as visible pseudo-markup.
        $text = html_entity_decode($output, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $text) ?? '';
        $text = strip_tags($text);
        // Entities uncovered by stripping tags get one more pass.
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);

        // Images first: a markdown image is a remote fetch, not text.
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $text) ?? '';
        // A markdown link keeps its label, never its target.
        $text = preg_replace('/\[([^\]]*)\]\(\s*(?:[a-z][a-z0-9+.-]*:)?\/\/[^)]*\)/iu', '$1', $text) ?? '';
        // Bare URLs, with or without a scheme.
        $text = preg_replace('~\b(?:https?://|www\.)[^\s<>()\[\]]+~iu', '[link removed]', $text) ?? '';

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $text) ?? '';

        // Markdown decoration is not plain text either.
        $text = preg_replace('/^[ \t]*#{1,6}[ \t]+/m', '', $text) ?? '';
        $text = str_replace(['**', '__', '`'], '', $text);

        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? '';
        $text = trim($text);

        if (mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, max(0, $maxLength - 1))).'…';
        }

        return $text;
    }
}
