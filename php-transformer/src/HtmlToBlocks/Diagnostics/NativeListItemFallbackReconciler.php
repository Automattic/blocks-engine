<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

/** Reconciles list-item findings against native blocks after captured-dialog projection. */
final class NativeListItemFallbackReconciler
{
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, string> $nativeListItemMarkup
     */
    public static function reconcile(array &$fallbacks, array $nativeListItemMarkup): void
    {
        $nativeSignatures = array();
        foreach ($nativeListItemMarkup as $markup) {
            $signature = self::linkSignature($markup);
            if (null !== $signature) {
                $nativeSignatures[$signature] = ($nativeSignatures[$signature] ?? 0) + 1;
            }
        }

        foreach ($fallbacks as &$fallback) {
            if (
                'html_unsupported_element' !== ($fallback['diagnostic_code'] ?? '')
                || 'li' !== strtolower((string) ($fallback['tag'] ?? ''))
            ) {
                continue;
            }

            $signature = self::linkSignature((string) ($fallback['html'] ?? ''));
            if (null === $signature || empty($nativeSignatures[$signature])) {
                continue;
            }

            --$nativeSignatures[$signature];
            $fallback['conversion_classification'] = 'native_conversion';
            $fallback['loss_class'] = 'native_conversion';
            $fallback['diagnostic_class'] = 'native_conversion';
            $fallback['reconciliation'] = 'matching_link_semantics_emitted_as_core_list_item';
        }
        unset($fallback);
    }

    /**
     * A captured dialog is flattened before it is passed through conversion, so
     * its unsupported-finding selector can differ from the original source
     * selector retained by the emitted block. Match only a single-link list
     * item by stable link semantics; presentation attributes and wrapper depth
     * are intentionally excluded.
     */
    private static function linkSignature(string $html): ?string
    {
        if ('' === trim($html)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body><div>' . $html . '</div></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return null;
        }

        $container = $document->getElementsByTagName('div')->item(0);
        $links = $document->getElementsByTagName('a');
        if (! $container instanceof \DOMElement) {
            return null;
        }
        if (1 !== $links->length) {
            return null;
        }
        $link = $links->item(0);
        if (! $link instanceof \DOMElement || '' === trim($link->getAttribute('href'))) {
            return null;
        }

        $text = preg_replace('/\s+/u', ' ', trim($link->textContent ?? ''));
        if (! is_string($text) || '' === $text) {
            return null;
        }
        $containerText = preg_replace('/\s+/u', ' ', trim($container->textContent ?? ''));
        if (! is_string($containerText) || $text !== $containerText) {
            return null;
        }

        $allowedTags = array('li', 'a', 'span', 'br', 'em', 'strong', 'b', 'i', 'mark', 'small', 'sub', 'sup');
        foreach ($container->getElementsByTagName('*') as $element) {
            if (! $element instanceof \DOMElement || ! in_array(strtolower($element->tagName), $allowedTags, true)) {
                return null;
            }
        }

        $identity = array('href' => $link->getAttribute('href'), 'text' => $text);
        foreach (array('aria-label', 'title', 'target', 'rel', 'download') as $attribute) {
            if ($link->hasAttribute($attribute)) {
                $identity[$attribute] = $link->getAttribute($attribute);
            }
        }

        return hash('sha256', (string) json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
