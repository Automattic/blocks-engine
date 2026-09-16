<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjectionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NavigationToggleSuppressor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/** Promotes a menu toggle onto its projected navigation, or suppresses the control. */
final class ProjectedNavigationConverter implements ElementConverter
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, array<int, class-string>): ?array<string, mixed> $recognizePatterns
     */
    public function __construct(
        private readonly NavigationToggleSuppressor $navigationToggleSuppressor,
        private readonly StyleResolver $styleResolver,
        private readonly SourceBlockAttributeProjector $sourceBlockAttributeProjector,
        private readonly HtmlTransformerSession $session,
        private readonly Closure $recognizePatterns
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        $projectedNavigation = $this->navigationToggleSuppressor->projectedNavigationTargetForControl($element);
        if ( $projectedNavigation instanceof DOMElement ) {
            $block = ($this->recognizePatterns)($projectedNavigation, $fallbacks, array(NavigationPattern::class));
            if ( null !== $block ) {
                $controlAttrs = $this->styleResolver->presentationAttributes($element);
                $nativeClassNames = 'blocks-engine-list-navigation blocks-engine-native-responsive-navigation';
                if ( $this->navigationToggleSuppressor->isImplicitDialogNavigationControl($element) ) {
                    $nativeClassNames .= ' blocks-engine-projected-dialog-navigation';
                }
                $block['attrs']['className'] = SourceDom::mergeClassNames(
                    $nativeClassNames,
                    (string) ($block['attrs']['className'] ?? ''),
                    (string) ($controlAttrs['className'] ?? ''),
                    $this->responsiveNavigationToggleMarker($projectedNavigation),
                    $this->sourceBlockAttributeProjector->sourceProjectionClassName($element, $this->sourceBlockAttributeProjectionContext())
                );
                $block['attrs']['overlayMenu'] = $this->navigationToggleSuppressor->isHashAnchorMenuProjection($element)
                    ? 'always'
                    : 'mobile';
                return ConversionOutcome::handled($block);
            }
        }

        if ( $this->navigationToggleSuppressor->isProjectedNavigationSuppressed($element) || $this->navigationToggleSuppressor->isRedundantMenuToggleControl($element) ) {
            return ConversionOutcome::handled(null);
        }

        return ConversionOutcome::unhandled();
    }

    public function responsiveNavigationToggleMarker(DOMElement $navigation): string
    {
        $toggle = $this->navigationToggleSuppressor->navigationToggleControl($navigation);
        if ( ! $toggle instanceof DOMElement ) {
            return '';
        }

        $resolved = $this->styleResolver->resolveCssVariablesInValue(
            $this->styleResolver->specificityResolvedPresentationStyle($toggle)
        );
        $sourceDeclarations = $this->styleResolver->cssDeclarations($resolved);
        $declarations = array();
        $hasUsableWidth = false;
        $hasUsableHeight = false;
        foreach ( array(
            'box-sizing',
            'width',
            'height',
            'min-width',
            'min-height',
            'padding',
            'padding-top',
            'padding-right',
            'padding-bottom',
            'padding-left',
            'border-top-left-radius',
            'border-top-right-radius',
            'border-bottom-right-radius',
            'border-bottom-left-radius',
            'background-color',
            'color',
        ) as $property ) {
            $value = trim((string) ($sourceDeclarations[$property] ?? ''));
            if ( '' !== $value && ! preg_match('/[{}<>;]/', $value) ) {
                $comparable = CssValueInspector::withoutImportant($value);
                if ( in_array($property, array( 'width', 'min-width' ), true) && in_array($comparable, array( 'auto', 'fit-content', 'max-content', 'min-content' ), true) ) {
                    continue;
                }
                if ( in_array($property, array( 'height', 'min-height' ), true) && in_array($comparable, array( 'auto', 'fit-content', 'max-content', 'min-content' ), true) ) {
                    continue;
                }
                $hasUsableWidth = $hasUsableWidth || ( in_array($property, array( 'width', 'min-width' ), true) && $this->nativeNavigationToggleDimensionIsUsable($comparable) );
                $hasUsableHeight = $hasUsableHeight || ( in_array($property, array( 'height', 'min-height' ), true) && $this->nativeNavigationToggleDimensionIsUsable($comparable) );
                $declarations[] = $property . ':' . $comparable . '!important';
            }
        }
        if ( ! $hasUsableWidth ) {
            $declarations[] = 'min-width:44px!important';
        }
        if ( ! $hasUsableHeight ) {
            $declarations[] = 'min-height:44px!important';
        }
        if ( array() === $declarations ) {
            return '';
        }

        $marker = 'blocks-engine-native-navigation-toggle-' . substr(hash('sha256', implode(';', $declarations)), 0, 12);
        $host = '.wp-block-navigation.blocks-engine-native-responsive-navigation.' . $marker;
        $rule = '@media(max-width:599px){' . $host . '{box-sizing:border-box!important;width:fit-content!important;height:fit-content!important;min-width:0!important;min-height:0!important;padding:0!important}'
            . $host . '>.wp-block-navigation__responsive-container-open{' . implode(';', $declarations) . '}}';
        $this->session->generatedSupportStylesheetState()->registerNativeNavigationToggle($marker, $rule);
        return $marker;
    }

    private function nativeNavigationToggleDimensionIsUsable(string $value): bool
    {
        if ( 1 !== preg_match('/^(\d+(?:\.\d+)?)px$/', $value, $matches) ) {
            return false;
        }

        return (float) $matches[1] >= 24;
    }

    private function sourceBlockAttributeProjectionContext(): SourceBlockAttributeProjectionContext
    {
        return new SourceBlockAttributeProjectionContext(
            $this->session->authorStyleAnalysis(),
            $this->session->authorSelectorProjectionState(),
            $this->session->generatedSupportStylesheetState()
        );
    }
}
