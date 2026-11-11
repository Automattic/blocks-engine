<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
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
        $theme = ArrayField::array($derived, 'theme');

        return new self(
            $compiledSite,
            ArrayField::array($derived, 'routes'),
            ArrayField::array($derived, 'navigation_links'),
            ArrayField::array($derived, 'menus'),
            ArrayField::array($theme, 'font_materialization'),
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
        $reports = ArrayField::array($result, 'source_reports');
        $compiledSite = ArrayField::array($reports, 'compiled_site');

        return self::fromCompiledSite(
            $compiledSite,
            ArrayField::array($reports, 'editability_policy'),
            ArrayField::array($reports, 'runtime_island_package'),
            $coreHtmlFallbackEvidence
        );
    }
}
