<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

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
                'native_conversion' === ($fallback['conversion_classification'] ?? '')
                || 'html_unsupported_element' !== ($fallback['diagnostic_code'] ?? '')
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
     * Reconcile against final compiled documents as well as the immediate
     * transform tree. Captured-dialog projection can emit list blocks only after
     * the original finding has been recorded.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, string> $documents
     */
    public static function reconcileBlockDocuments(array &$fallbacks, array $documents): void
    {
        $nativeListItemMarkup = array();
        foreach ($documents as $document) {
            self::collectListItemMarkup($document, $nativeListItemMarkup);
        }
        self::reconcile($fallbacks, $nativeListItemMarkup);
    }

    /** @param array<int, string> $nativeListItemMarkup */
    private static function collectListItemMarkup(string $document, array &$nativeListItemMarkup): void
    {
        if (! preg_match_all('/<!--\s*(\/?)wp:([a-z0-9-]+)(?:\s+.*?)?-->/s', $document, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $stack = array();
        foreach ($matches[0] as $index => $match) {
            $token = $match[0];
            $offset = $match[1];
            $isClosing = '/' === $matches[1][$index][0];
            $name = $matches[2][$index][0];
            if ($isClosing) {
                for ($stackIndex = count($stack) - 1; $stackIndex >= 0; --$stackIndex) {
                    if ($name !== $stack[$stackIndex]['name']) {
                        continue;
                    }
                    $open = $stack[$stackIndex];
                    if ('list-item' === $name) {
                        $nativeListItemMarkup[] = substr($document, $open['content_offset'], $offset - $open['content_offset']);
                    }
                    $stack = array_slice($stack, 0, $stackIndex);
                    break;
                }
                continue;
            }
            if (str_ends_with(rtrim($token), '/-->')) {
                continue;
            }
            $stack[] = array('name' => $name, 'content_offset' => $offset + strlen($token));
        }
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
