<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

/** Removes unsupported-element findings only when that exact source list item was emitted natively. */
final class NativeListItemFallbackReconciler
{
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<string, true> $nativeListItemSelectors
     */
    public static function reconcile(array &$fallbacks, array $nativeListItemSelectors): void
    {
        foreach ($fallbacks as &$fallback) {
            if (
                'html_unsupported_element' !== ($fallback['diagnostic_code'] ?? '')
                || 'li' !== strtolower((string) ($fallback['tag'] ?? ''))
                || ! isset($nativeListItemSelectors[(string) ($fallback['selector'] ?? '')])
            ) {
                continue;
            }

            $fallback['conversion_classification'] = 'native_conversion';
            $fallback['loss_class'] = 'native_conversion';
            $fallback['diagnostic_class'] = 'native_conversion';
            $fallback['reconciliation'] = 'source_list_item_emitted_as_core_list_item';
        }
        unset($fallback);
    }
}
