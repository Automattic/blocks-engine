<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Closure;

/**
 * Explicit collaborator surface for {@see StyleResolver}.
 *
 * Per-transform state is read from the compilation's typed session. Closures
 * remain only for transformer-owned operations.
 */
final class StyleResolutionContext
{
    /**
     * @param Closure(string): string                  $cssComparableValue
     * @param Closure(string): array<string, mixed>    $parsedCssSelector
     * @param Closure(string): string                  $promotedClassName
     * @param Closure(string): string                  $resolvedAssetImageUrl
     */
    public function __construct(
        private readonly Closure $cssComparableValue,
        private readonly Closure $parsedCssSelector,
        private readonly Closure $promotedClassName,
        private readonly Closure $resolvedAssetImageUrl
    ) {
    }

    public function cssComparableValue(string $value): string
    {
        return ($this->cssComparableValue)($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function parsedCssSelector(string $selector): array
    {
        return ($this->parsedCssSelector)($selector);
    }

    public function promotedClassName(string $className): string
    {
        return ($this->promotedClassName)($className);
    }

    public function resolvedAssetImageUrl(string $url): string
    {
        return ($this->resolvedAssetImageUrl)($url);
    }

}
