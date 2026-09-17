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
                $controlClassName = (string) ($controlAttrs['className'] ?? '');
                if ( $this->navigationToggleSuppressor->isHashAnchorMenuProjection($element) ) {
                    $controlClassName = '';
                }
                $block['attrs']['className'] = SourceDom::mergeClassNames(
                    $nativeClassNames,
                    (string) ($block['attrs']['className'] ?? ''),
                    $controlClassName,
                    $this->responsiveNavigationToggleMarker($projectedNavigation),
                    $this->sourceBlockAttributeProjector->sourceProjectionClassName($element, $this->sourceBlockAttributeProjectionContext())
                );
                $block['attrs']['overlayMenu'] = $this->navigationToggleSuppressor->projectedOverlayMenu($element);
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

        $sourceDeclarations = $this->styleResolver->resolvedPresentationDeclarations($toggle);
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

        $always = 'always' === $this->navigationToggleSuppressor->projectedOverlayMenu($toggle);
        $display = strtolower(CssValueInspector::withoutImportant(trim((string) ($sourceDeclarations['display'] ?? ''))));
        $extra = '';
        $openDeclarations = $declarations;
        if ( $always ) {
            if ( 'table-cell' === $display && ! $hasUsableHeight ) {
                $openDeclarations[] = 'min-height:60px!important';
            }
            foreach ( array( 'border', 'border-right', 'font-family', 'font-size', 'font-weight', 'letter-spacing', 'text-align' ) as $property ) {
                $value = trim((string) ($sourceDeclarations[$property] ?? ''));
                if ( '' !== $value && ! preg_match('/[{}<>;]/', $value) ) {
                    $openDeclarations[] = $property . ':' . CssValueInspector::withoutImportant($value) . '!important';
                }
            }
            $openDeclarations[] = 'display:flex!important';
            $openDeclarations[] = 'align-items:center!important';
            $openDeclarations[] = 'justify-content:center!important';
            $pseudo = $this->nativeNavigationToggleGeneratedContent($toggle);
            if ( array() !== $pseudo ) {
                $afterParts = array();
                foreach ( $pseudo as $property => $value ) {
                    $afterParts[] = $property . ':' . $value . '!important';
                }
                $extra .= 'SVG_HIDE';
                $extra .= 'AFTER:' . implode(';', $afterParts);
            }
        }

        $marker = 'blocks-engine-native-navigation-toggle-' . substr(hash('sha256', implode(';', $openDeclarations) . $extra), 0, 12);
        $host = '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation.' . $marker;
        $hostRule = $host . '{' . implode(';', $this->nativeNavigationToggleHostDeclarations($always, $display, $sourceDeclarations)) . '}';
        $openRule = $host . '>.wp-block-navigation__responsive-container-open{' . implode(';', $openDeclarations) . '}';
        $extraRules = '';
        if ( str_contains($extra, 'SVG_HIDE') ) {
            $extraRules .= $host . '>.wp-block-navigation__responsive-container-open svg{display:none!important}';
        }
        if ( str_contains($extra, 'AFTER:') ) {
            $afterBody = substr($extra, strpos($extra, 'AFTER:') + 6);
            $extraRules .= $host . '>.wp-block-navigation__responsive-container-open::after{' . $afterBody . '}';
        }
        if ( $always ) {
            $extraRules .= $this->nativeNavigationToggleDropdownCss($host, $navigation);
            $extraRules .= $this->nativeNavigationToggleOpenControlCss($host);
        }
        $rule = $always
            ? $hostRule . $openRule . $extraRules
            : '@media(max-width:599px){' . $hostRule . $openRule . '}';
        $this->session->generatedSupportStylesheetState()->registerNativeNavigationToggle($marker, $rule);
        return $marker;
    }

    private function nativeNavigationToggleDropdownCss(string $host, DOMElement $navigation): string
    {
        $panel = $navigation->parentNode instanceof DOMElement ? $navigation->parentNode : $navigation;
        $declarations = $this->styleResolver->resolvedPresentationDeclarations($panel);
        $background = '#fff';
        $maxHeight = CssValueInspector::withoutImportant(trim((string) ($declarations['max-height'] ?? '')));
        if ( '' === $maxHeight || 'none' === strtolower($maxHeight) || '0' === $maxHeight || '0px' === $maxHeight ) {
            $maxHeight = '200px';
        }
        $topOffset = $this->nativeNavigationToggleHeaderOffset($navigation);
        $open = $host . ' .wp-block-navigation__responsive-container.is-menu-open';
        return $open . '{box-sizing:border-box!important;position:fixed!important;inset:auto!important;top:' . $topOffset . '!important;left:0!important;right:0!important;width:100%!important;height:auto!important;min-height:60px!important;max-height:' . $maxHeight . '!important;background:' . $background . '!important;display:flex!important;justify-content:flex-start!important;align-items:center!important;overflow:visible!important;z-index:6!important;padding:0 20px!important;box-shadow:0 5px 10px 0 rgba(0,0,0,0.2)!important}'
            . 'body.admin-bar ' . $open . '{top:calc(' . $topOffset . ' + var(--wp-admin--admin-bar--height,32px))!important}'
            . $open . ' .wp-block-navigation__responsive-container-content{flex-direction:row!important;align-items:center!important;justify-content:flex-start!important;width:100%!important;height:60px!important;margin:0!important;padding:0!important}'
            // The source menu bar lays its items out inline, so the gap between
            // two labels is the collapsed whitespace text node the source had
            // between list items, advanced in the list's own font. Core's
            // navigation save() emits no whitespace between items, so a flex
            // bar loses that gap entirely. Keep the bar an inline formatting
            // context and restore the separator as generated content, which
            // lets the browser measure it in the same inherited font instead
            // of pinning a guessed pixel gap.
            // The bar's own line box has to span the bar, or vertical-align
            // centers each item against a short strut parked at the top.
            . $open . ' .wp-block-navigation__container{display:block!important;white-space:nowrap!important;width:auto!important;height:60px!important;line-height:60px!important;margin:0!important;padding:0!important;list-style:none!important}'
            . $this->nativeNavigationToggleItemCss($open, $navigation)
            . $open . ' .wp-block-navigation-item span::after{content:none!important}'
            . $open . ' .wp-block-navigation__responsive-container-close{display:flex!important;position:fixed!important;top:calc(0px - ' . $topOffset . ')!important;left:0!important;width:100px!important;height:60px!important;opacity:0!important;z-index:9!important;padding:0!important;margin:0!important;border:0!important;background:transparent!important;cursor:pointer!important}'
            . $open . ' .wp-block-navigation__responsive-container-close svg{display:none!important}'
            . 'html.has-modal-open:has(' . $open . '){overflow:visible!important}'
            . 'body:has(' . $open . '){overflow:visible!important}';
    }

    /**
     * @param array<string, string> $sourceDeclarations
     * @return array<int, string>
     */
    private function nativeNavigationToggleHostDeclarations(bool $always, string $display, array $sourceDeclarations): array
    {
        $host = array(
            'box-sizing:border-box!important',
            'padding:0!important',
            'position:relative!important',
            'overflow:visible!important',
        );
        if ( $always && 'table-cell' === $display ) {
            $host[] = 'display:table-cell!important';
            $host[] = 'vertical-align:middle!important';
            $width = CssValueInspector::withoutImportant(trim((string) ($sourceDeclarations['width'] ?? '')));
            if ( $this->nativeNavigationToggleDimensionIsUsable($width) ) {
                $host[] = 'width:' . $width . '!important';
            }

            return $host;
        }

        $host[] = 'width:fit-content!important';
        $host[] = 'height:fit-content!important';
        $host[] = 'min-width:0!important';
        $host[] = 'min-height:0!important';

        return $host;
    }

    private function nativeNavigationToggleOpenControlCss(string $host): string
    {
        $open = $host . '>.wp-block-navigation__responsive-container-open';
        $whenOpen = $host . ':has(.wp-block-navigation__responsive-container.is-menu-open)>.wp-block-navigation__responsive-container-open';
        return $whenOpen . '{background:#fff!important}'
            . $whenOpen . '::after{color:#444!important}'
            . $open . ':hover{background:#fff!important}'
            . $open . ':hover::after{color:#444!important}';
    }

    private function nativeNavigationToggleItemCss(string $open, DOMElement $navigation): string
    {
        $anchor = null;
        foreach ( $navigation->getElementsByTagName('a') as $candidate ) {
            if ( $candidate instanceof DOMElement && '' !== trim($candidate->textContent ?? '') ) {
                $href = trim(SourceDom::attr($candidate, 'href'));
                if ( '' !== $href && ! str_starts_with($href, '#') ) {
                    $anchor = $candidate;
                    break;
                }
            }
        }
        $item = array(
            'font-size' => '14px',
            'font-weight' => '700',
            'text-transform' => 'uppercase',
            'letter-spacing' => '0.04em',
            'color' => '#444',
            'padding' => '0 10px',
            'line-height' => 'normal',
        );
        if ( $anchor instanceof DOMElement ) {
            $declarations = $this->styleResolver->resolvedPresentationDeclarations($anchor);
            foreach ( array( 'font-size', 'font-weight', 'text-transform', 'letter-spacing', 'color', 'line-height' ) as $property ) {
                $value = CssValueInspector::withoutImportant(trim((string) ($declarations[$property] ?? '')));
                if ( '' !== $value && ! preg_match('/[{}<>]/', $value) ) {
                    $item[$property] = $value;
                }
            }
            $parent = $anchor->parentNode instanceof DOMElement ? $anchor->parentNode : null;
            if ( $parent instanceof DOMElement ) {
                $parentResolved = $this->styleResolver->resolveCssVariablesInValue(
                    $this->styleResolver->specificityResolvedPresentationStyle($parent)
                );
                $padding = CssValueInspector::withoutImportant(trim((string) ($this->styleResolver->cssDeclarations($parentResolved)['padding'] ?? '')));
                if ( $this->nativeNavigationTogglePaddingIsUsable($padding) ) {
                    $item['padding'] = $padding;
                }
            }
        }
        $content = array();
        $padding = $item['padding'];
        unset($item['padding']);
        foreach ( $item as $property => $value ) {
            $content[] = $property . ':' . $value . '!important';
        }

        $color = $item['color'] ?? '#444';
        $withoutColor = array();
        foreach ( $content as $declaration ) {
            if ( ! str_starts_with($declaration, 'color:') ) {
                $withoutColor[] = $declaration;
            }
        }

        return $open . ' .wp-block-navigation-item,'
            . $open . ' .wp-block-navigation-item.wp-block-navigation-link{display:inline-block!important;vertical-align:middle!important;height:auto!important;line-height:normal!important;padding:' . $padding . '!important;margin:0!important;list-style:none!important;box-sizing:border-box!important}'
            . $open . ' .wp-block-navigation-item::after{content:" "!important;white-space:pre!important;display:inline!important}'
            . $open . ' .wp-block-navigation-item:last-child::after{content:none!important}'
            . $open . ' .wp-block-navigation-item__content{display:inline!important;white-space:nowrap!important;padding:0!important;' . implode(';', $withoutColor) . '}'
            . $open . ' .wp-block-navigation-item:not(.blocks-engine-current-navigation-item):not(.current-menu-item) .wp-block-navigation-item__content{color:' . $color . '!important}';
    }

    private function nativeNavigationToggleHeaderOffset(DOMElement $navigation): string
    {
        $toggle = $this->navigationToggleSuppressor->navigationToggleControl($navigation);
        if ( ! $toggle instanceof DOMElement ) {
            return '60px';
        }
        $bar = $this->absolutelyPinnedHeaderBar($toggle);
        $target = $bar instanceof DOMElement ? $bar : $toggle;
        $resolved = $this->styleResolver->resolveCssVariablesInValue(
            $this->styleResolver->specificityResolvedPresentationStyle($target)
        );
        $height = CssValueInspector::withoutImportant(trim((string) ($this->styleResolver->cssDeclarations($resolved)['height'] ?? '')));
        if ( 1 === preg_match('/^\d+(?:\.\d+)?px$/', $height) ) {
            return $height;
        }
        if ( $bar instanceof DOMElement && $bar->parentNode instanceof DOMElement ) {
            $parentResolved = $this->styleResolver->resolveCssVariablesInValue(
                $this->styleResolver->specificityResolvedPresentationStyle($bar->parentNode)
            );
            $parentHeight = CssValueInspector::withoutImportant(trim((string) ($this->styleResolver->cssDeclarations($parentResolved)['height'] ?? '')));
            if ( 1 === preg_match('/^\d+(?:\.\d+)?px$/', $parentHeight) ) {
                return $parentHeight;
            }
        }

        return '60px';
    }

    private function absolutelyPinnedHeaderBar(DOMElement $toggle): ?DOMElement
    {
        $node = $toggle->parentNode;
        while ( $node instanceof DOMElement ) {
            $declarations = $this->styleResolver->resolvedPresentationDeclarations($node);
            if ( array() === $declarations ) {
                $declarations = $this->styleResolver->structuralPresentationDeclarations($node);
            }
            $position = strtolower(CssValueInspector::withoutImportant(trim((string) ($declarations['position'] ?? ''))));
            $top = strtolower(CssValueInspector::withoutImportant(trim((string) ($declarations['top'] ?? ''))));
            if ( in_array($position, array( 'absolute', 'fixed' ), true) && in_array($top, array( '', 'auto', '0', '0px' ), true) ) {
                return $node;
            }
            $node = $node->parentNode;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function nativeNavigationToggleGeneratedContent(DOMElement $toggle): array
    {
        $targets = array($toggle);
        foreach ( $toggle->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $targets[] = $child;
            }
        }
        foreach ( $this->session->sourceStyleResolutionState()->pseudoElementRules() as $rule ) {
            if ( 'after' !== ($rule['pseudo'] ?? '') && ':after' !== ($rule['pseudo'] ?? '') ) {
                continue;
            }
            $selector = (string) ($rule['selector'] ?? '');
            $matched = false;
            foreach ( $targets as $target ) {
                if ( $this->styleResolver->matchesCssSelector($target, $selector) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) {
                foreach ( preg_split('/\s+/', trim(SourceDom::attr($toggle, 'class'))) ?: array() as $className ) {
                    if ( '' !== $className && 1 === preg_match('/\.' . preg_quote($className, '/') . '(?:$|[.\s\[:#>+~])/', $selector) ) {
                        $matched = true;
                        break;
                    }
                }
            }
            if ( ! $matched ) {
                continue;
            }
            $picked = array();
            foreach ( array( 'content', 'color', 'font-family', 'font-size', 'font-weight', 'letter-spacing', 'line-height', 'display', 'text-align' ) as $property ) {
                $value = trim((string) (($rule['declarations'][$property] ?? '')));
                if ( '' === $value || preg_match('/[{}<>]/', $value) ) {
                    continue;
                }
                $picked[$property] = CssValueInspector::withoutImportant($value);
            }
            if ( isset($picked['content']) && ! in_array(strtolower($picked['content']), array( 'none', 'normal', '""', "''" ), true) ) {
                $picked['content'] = $this->nativeNavigationToggleContentValue($picked['content']);
                return $picked;
            }
        }

        return array();
    }

    private function nativeNavigationToggleContentValue(string $value): string
    {
        $trimmed = trim($value);
        $unquoted = ltrim(trim($trimmed, "\"'"), '\\');
        if ( 1 === preg_match('/^[A-Za-z][A-Za-z0-9 -]*$/', $unquoted) ) {
            return '"' . $unquoted . '"';
        }

        return $trimmed;
    }

    private function nativeNavigationTogglePaddingIsUsable(string $value): bool
    {
        if ( '' === $value || preg_match('/[{}<>]/', $value) ) {
            return false;
        }

        return 1 !== preg_match('/^0(?:px|em|rem)?(?:\s+0(?:px|em|rem)?){0,3}$/', strtolower(trim($value)));
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
