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
     * @param array<string,mixed> $editabilityPolicy
     * @param array<string,mixed> $runtimeIslandPackage
     * @param array<string,mixed> $coreHtmlFallbackEvidence
     */
    private function __construct(
        public readonly array $compiledSite,
        public readonly array $routes,
        public readonly array $navigationLinks,
        public readonly array $menus,
        public readonly array $fontMaterialization,
        public readonly array $editabilityPolicy,
        public readonly array $runtimeIslandPackage,
        public readonly array $coreHtmlFallbackEvidence
    ) {
    }

    /**
     * @param array<string,mixed> $compiledSite
     * @param array<string,mixed> $editabilityPolicy
     * @param array<string,mixed> $runtimeIslandPackage
     * @param array<string,mixed> $coreHtmlFallbackEvidence
     */
    public static function fromCompiledSite(array $compiledSite, array $editabilityPolicy, array $runtimeIslandPackage, array $coreHtmlFallbackEvidence): self
    {
        $derived = (new MaterializationPlanBuilder())->fromCompiledSite($compiledSite);
        $theme = is_array($derived['theme'] ?? null) ? $derived['theme'] : array();

        return new self(
            $compiledSite,
            is_array($derived['routes'] ?? null) ? $derived['routes'] : array(),
            is_array($derived['navigation_links'] ?? null) ? $derived['navigation_links'] : array(),
            is_array($derived['menus'] ?? null) ? $derived['menus'] : array(),
            is_array($theme['font_materialization'] ?? null) ? $theme['font_materialization'] : array(),
            $editabilityPolicy,
            $runtimeIslandPackage,
            $coreHtmlFallbackEvidence
        );
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $coreHtmlFallbackEvidence
     */
    public static function fromCompilerResult(array $result, array $coreHtmlFallbackEvidence): self
    {
        $reports = is_array($result['source_reports'] ?? null) ? $result['source_reports'] : array();
        $compiledSite = is_array($reports['compiled_site'] ?? null) ? $reports['compiled_site'] : array();

        return self::fromCompiledSite(
            $compiledSite,
            is_array($reports['editability_policy'] ?? null) ? $reports['editability_policy'] : array(),
            is_array($reports['runtime_island_package'] ?? null) ? $reports['runtime_island_package'] : array(),
            $coreHtmlFallbackEvidence
        );
    }
}
