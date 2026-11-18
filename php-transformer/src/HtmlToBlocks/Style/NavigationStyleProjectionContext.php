<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Closure;

/**
 * Explicit collaborator surface for {@see NavigationStyleProjector}.
 *
 * Per-transform state is read from the compilation's typed session. Closures
 * remain only for transformer-owned operations.
 *
 * Stylesheet asset materialization is shared by navigation, engine-support and
 * author styles. The projector reaches that stage through this surface.
 */
final class NavigationStyleProjectionContext
{
    /**
     * @param Closure(string): array<string, mixed>                       $parsedCssSelector
     * @param Closure(array<int, string>, string, string, string, string): void $materializeStylesheetAsset
     */
    public function __construct(
        private readonly Closure $parsedCssSelector,
        private readonly Closure $materializeStylesheetAsset
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function parsedCssSelector(string $selector): array
    {
        return ($this->parsedCssSelector)($selector);
    }

    /**
     * @param array<int, string> $cssParts
     */
    public function materializeStylesheetAsset(
        array $cssParts,
        string $source,
        string $placement,
        string $pathPrefix,
        string $target = 'both'
    ): void {
        ($this->materializeStylesheetAsset)($cssParts, $source, $placement, $pathPrefix, $target);
    }
}
