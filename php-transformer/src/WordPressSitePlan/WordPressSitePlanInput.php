<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\StaticSite\MaterializationPlanBuilder;

/**
 * The compiler-only input consumed by the WordPress site-plan projection.
 *
 * It keeps derived route, navigation, and font facts out of the public result
 * envelope while preserving the exact v2 plan projection.
 */
final class WordPressSitePlanInput
{
    /**
     * @param array<string,mixed> $compiledSite
     * @param array<int,array<string,mixed>> $routes
     * @param array<int,array<string,mixed>> $navigationLinks
     * @param array<int,array<string,mixed>> $menus
     * @param array<string,mixed> $fontMaterialization
     */
    private function __construct(
        public readonly array $compiledSite,
        public readonly array $routes,
        public readonly array $navigationLinks,
        public readonly array $menus,
        public readonly array $fontMaterialization
    ) {
    }

    /** @param array<string,mixed> $compiledSite */
    public static function fromCompiledSite(array $compiledSite): self
    {
        $derived = (new MaterializationPlanBuilder())->fromCompiledSite($compiledSite);
        $theme = is_array($derived['theme'] ?? null) ? $derived['theme'] : array();

        return new self(
            $compiledSite,
            is_array($derived['routes'] ?? null) ? $derived['routes'] : array(),
            is_array($derived['navigation_links'] ?? null) ? $derived['navigation_links'] : array(),
            is_array($derived['menus'] ?? null) ? $derived['menus'] : array(),
            is_array($theme['font_materialization'] ?? null) ? $theme['font_materialization'] : array()
        );
    }
}
