<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/**
 * Extracts the usable fallback from complete legacy frameset documents before
 * DOM parsing can retain frame navigation as page content.
 */
final class DocumentNormalizationPolicy
{
    /**
     * @return array{html: string, fallback: array<string, mixed>|null}
     */
    public function normalize(string $html): array
    {
        if (
            ! preg_match('/<frameset\b[^>]*>/i', $html)
            || ! preg_match('/<\/frameset\s*>/i', $html)
            || ! preg_match('/<noframes\b[^>]*>(.*?)<\/noframes\s*>/is', $html, $matches)
        ) {
            return array(
                'html'     => $html,
                'fallback' => null,
            );
        }

        $fallbackHtml = preg_replace('/<\/?frameset\b[^>]*>/i', '', $matches[1]) ?? $matches[1];
        $fallbackHtml = preg_replace('/<frame\b[^>]*>/i', '', $fallbackHtml) ?? $fallbackHtml;

        return array(
            'html' => $fallbackHtml,
            'fallback' => FallbackDiagnostic::build(array(
                'type'            => 'legacy_frameset_navigation',
                'reason'          => 'unsupported_legacy_frameset_navigation',
                'diagnostic_code' => 'html_legacy_frameset_navigation',
                'message'         => 'Legacy frameset navigation was removed and its noframes fallback content was converted.',
                'tag'             => 'frameset',
            )),
        );
    }
}
