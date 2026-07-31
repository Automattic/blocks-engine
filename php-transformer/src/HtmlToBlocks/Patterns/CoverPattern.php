<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\BackgroundImageExtractor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;
use DOMElement;
use Throwable;

/**
 * Recognizes background-image hero containers and emits core/cover blocks.
 *
 * Gate decision tree:
 *
 * style = mergedPresentationStyle(element)
 * |
 * +-- background URL? -------- no --> null
 * |
 * +-- hero sized? ------------ no --> null
 * |
 * +-- repeating background? -- yes -> null
 * |
 * +-- convert children once
 * |
 * +-- text-bearing block? ---- no --> null
 * |
 * `-- core/cover
 */
final class CoverPattern
{
    private CoverStyleResolver $styleResolver;
    private BackgroundImageExtractor $backgroundImageExtractor;

    public function __construct()
    {
        $this->styleResolver = new CoverStyleResolver();
        $this->backgroundImageExtractor = new BackgroundImageExtractor();
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param callable(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>> $convertChildren
     * @param callable(DOMElement, array<int, string>): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $mergedPresentationStyle
     * @param callable(DOMElement): array<string, string> $htmlAttributes
     * @param callable(string): string $resolveAssetUrl
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(
        DOMElement $element,
        array &$fallbacks,
        callable $convertChildren,
        callable $presentationAttributes,
        callable $mergedPresentationStyle,
        callable $htmlAttributes,
        callable $resolveAssetUrl,
        callable $createBlock
    ): ?array {
        try {
            $style = $mergedPresentationStyle($element);
        } catch ( Throwable ) {
            return null;
        }

        $backgroundUrl = '';
        try {
            if ( null !== $this->styleRejectionGate($style, $backgroundUrl) ) {
                return null;
            }
            if ( null !== $this->columnsRejectionGate($element, $style) ) {
                return null;
            }
        } catch ( Throwable ) {
            return null;
        }

        $localFallbacks = array();
        try {
            $children = $convertChildren($element, $localFallbacks, true);
        } catch ( Throwable ) {
            return null;
        }

        if ( null !== $this->navigationRejectionGate($children) ) {
            return null;
        }

        if ( null !== $this->textRejectionGate(array() !== $children && $this->containsTextBearingBlock($children)) ) {
            return null;
        }

        $excludedProperties = array( 'background', 'background-image', 'background-size', 'background-position', 'background-repeat', 'min-height', 'height' );
        try {
            $attrs = $presentationAttributes($element, $excludedProperties);
        } catch ( Throwable ) {
            $attrs = array();
        }
        unset($attrs['layout']);
        $this->removeConsumedDimensions($attrs);

        try {
            $resolvedUrl = $resolveAssetUrl($backgroundUrl);
            if ( '' !== $resolvedUrl ) {
                $attrs['url'] = $resolvedUrl;
            }
        } catch ( Throwable ) {
            // Keep independently derivable attributes.
        }

        $attrs['alt'] = '';
        try {
            $attrs['alt'] = $this->backgroundImageExtractor->altFromAttributes($htmlAttributes($element));
        } catch ( Throwable ) {
            // Empty alt is safe default.
        }

        $dim = array(
            'dimRatio'           => 0,
            'customOverlayColor' => '',
        );
        try {
            $dim = $this->styleResolver->dimFromStyle($style);
        } catch ( Throwable ) {
            // Keep no-overlay defaults.
        }
        $attrs['dimRatio'] = (int) ($dim['dimRatio'] ?? 0);

        $overlayColor = (string) ($dim['customOverlayColor'] ?? '');
        if ( preg_match('/^#[0-9a-f]{6}$/', $overlayColor) ) {
            $attrs['customOverlayColor'] = $overlayColor;
            $this->removeConsumedGradient($attrs);
        }
        $this->removeBackgroundUrlLayersFromGradient($attrs);

        try {
            $minHeight = $this->styleResolver->minHeightFromStyle($style);
            if ( null !== $minHeight ) {
                $attrs['minHeight'] = $minHeight['minHeight'];
                $attrs['minHeightUnit'] = $minHeight['minHeightUnit'];
            }
        } catch ( Throwable ) {
            // Omit underivable height attributes.
        }

        try {
            $focalPoint = $this->styleResolver->focalPointFromStyle($style);
            if ( null !== $focalPoint ) {
                $attrs['focalPoint'] = $focalPoint;
            }
        } catch ( Throwable ) {
            // Omit underivable focal-point attributes.
        }

        try {
            $block = $createBlock('core/cover', $attrs, $children, $element);
        } catch ( Throwable ) {
            return null;
        }

        array_push($fallbacks, ...$localFallbacks);

        return $block;
    }

    public function rejectionGate(string $style, bool $hasTextBearingChildren): ?string
    {
        $backgroundUrl = '';
        try {
            $styleGate = $this->styleRejectionGate($style, $backgroundUrl);
            if ( null !== $styleGate ) {
                return $styleGate;
            }

            return $this->textRejectionGate($hasTextBearingChildren);
        } catch ( Throwable ) {
            return 'no_background_url';
        }
    }

    private function styleRejectionGate(string $style, string &$backgroundUrl): ?string
    {
        $backgroundUrl = $this->styleResolver->backgroundUrlFromStyle($style);
        if ( '' === $backgroundUrl ) {
            return 'no_background_url';
        }

        if ( $this->hasMultipleBackgroundUrlLayers($style) ) {
            return 'multi_layer_background';
        }

        if ( ! $this->styleResolver->meetsHeroSizeGate($style) ) {
            return 'not_hero_sized';
        }

        if ( $this->styleResolver->hasRepeatingBackground($style) ) {
            return 'repeating_background';
        }

        return null;
    }

    private function columnsRejectionGate(DOMElement $element, string $style): ?string
    {
        if ( $this->directElementChildCount($element) < 2 ) {
            return null;
        }

        $declarations = $this->styleResolver->declarations($style);
        $display      = strtolower(trim((string) ($declarations['display'] ?? '')));
        if ( 'grid' === $display ) {
            return 'columns_layout';
        }
        if ( 'flex' !== $display ) {
            return null;
        }

        $direction = strtolower(trim((string) ($declarations['flex-direction'] ?? 'row')));
        return in_array($direction, array( 'column', 'column-reverse' ), true) ? null : 'columns_layout';
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function navigationRejectionGate(array $blocks): ?string
    {
        return $this->containsBlockNamed($blocks, 'core/navigation') ? 'nav_shell' : null;
    }

    private function textRejectionGate(bool $hasTextBearingChildren): ?string
    {
        return $hasTextBearingChildren ? null : 'no_text_content';
    }

    private function directElementChildCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function containsTextBearingBlock(array $blocks): bool
    {
        $textBearingNames = array( 'core/heading', 'core/paragraph', 'core/list', 'core/buttons', 'core/quote' );

        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( in_array($block['blockName'] ?? null, $textBearingNames, true) ) {
                return true;
            }

            $innerBlocks = $block['innerBlocks'] ?? array();
            if ( is_array($innerBlocks) && $this->containsTextBearingBlock($innerBlocks) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function containsBlockNamed(array $blocks, string $blockName): bool
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( $blockName === ($block['blockName'] ?? null) ) {
                return true;
            }

            $innerBlocks = $block['innerBlocks'] ?? array();
            if ( is_array($innerBlocks) && $this->containsBlockNamed($innerBlocks, $blockName) ) {
                return true;
            }
        }

        return false;
    }

    private function hasMultipleBackgroundUrlLayers(string $style): bool
    {
        $value = $this->winningBackgroundValue($style);
        if ( '' === $value ) {
            return false;
        }

        $urlLayers = 0;
        foreach ( $this->splitTopLevel($value, array( ',' )) as $layer ) {
            if ( preg_match('/\burl\s*\(/i', $layer) && 2 <= ++$urlLayers ) {
                return true;
            }
        }

        return false;
    }

    private function winningBackgroundValue(string $style): string
    {
        $declarations = $this->styleResolver->declarations($style);
        foreach ( array_reverse(array_keys($declarations)) as $name ) {
            if ( in_array($name, array( 'background', 'background-image' ), true) ) {
                return (string) $declarations[ $name ];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function removeConsumedDimensions(array &$attrs): void
    {
        if ( ! isset($attrs['style']) || ! is_array($attrs['style']) ) {
            return;
        }
        if ( isset($attrs['style']['dimensions']) && is_array($attrs['style']['dimensions']) ) {
            unset($attrs['style']['dimensions']['minHeight']);
        }
        $this->pruneEmptySupportStyle($attrs);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function removeConsumedGradient(array &$attrs): void
    {
        if ( ! isset($attrs['style']) || ! is_array($attrs['style']) ) {
            return;
        }
        if ( isset($attrs['style']['color']) && is_array($attrs['style']['color']) ) {
            unset($attrs['style']['color']['gradient']);
        }
        $this->pruneEmptySupportStyle($attrs);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function removeBackgroundUrlLayersFromGradient(array &$attrs): void
    {
        $gradient = $attrs['style']['color']['gradient'] ?? null;
        if ( ! is_string($gradient) ) {
            return;
        }

        $layers = $this->splitTopLevel($gradient, array( ',' ));
        $kept   = array_values(array_filter(
            $layers,
            static fn (string $layer): bool => ! preg_match('/\burl\s*\(/i', $layer)
        ));
        if ( count($kept) === count($layers) ) {
            return;
        }

        if ( array() === $kept ) {
            unset($attrs['style']['color']['gradient']);
        } else {
            $attrs['style']['color']['gradient'] = implode(',', $kept);
        }
        $this->pruneEmptySupportStyle($attrs);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function pruneEmptySupportStyle(array &$attrs): void
    {
        if ( isset($attrs['style']['dimensions']) && array() === $attrs['style']['dimensions'] ) {
            unset($attrs['style']['dimensions']);
        }
        if ( isset($attrs['style']['color']) && array() === $attrs['style']['color'] ) {
            unset($attrs['style']['color']);
        }
        if ( isset($attrs['style']) && array() === $attrs['style'] ) {
            unset($attrs['style']);
        }
    }

    /**
     * @param array<int, string> $delimiters
     * @return array<int, string>
     */
    private function splitTopLevel(string $input, array $delimiters): array
    {
        $masked = $this->maskQuotedAndEscapedCharacters($input);
        $parts  = CssValueSplitter::splitTopLevel($masked, $delimiters);

        return $this->restoreSplitParts($input, $masked, $parts);
    }

    private function maskQuotedAndEscapedCharacters(string $input): string
    {
        $masked = '';
        $quote  = null;
        $length = strlen($input);

        for ( $index = 0; $index < $length; ++$index ) {
            $character = $input[ $index ];
            if ( '\\' === $character ) {
                $masked .= 'x';
                if ( $index + 1 < $length ) {
                    $masked .= 'x';
                    ++$index;
                }
                continue;
            }

            if ( null !== $quote ) {
                if ( $quote === $character ) {
                    $masked .= $character;
                    $quote   = null;
                } else {
                    $masked .= 'x';
                }
                continue;
            }

            if ( '"' === $character || "'" === $character ) {
                $quote  = $character;
                $masked .= $character;
                continue;
            }

            $masked .= $character;
        }

        return $masked;
    }

    /**
     * @param array<int, string> $maskedParts
     * @return array<int, string>
     */
    private function restoreSplitParts(string $input, string $masked, array $maskedParts): array
    {
        $parts  = array();
        $offset = 0;

        foreach ( $maskedParts as $maskedPart ) {
            $start = strpos($masked, $maskedPart, $offset);
            if ( false === $start ) {
                return array();
            }

            $parts[] = substr($input, $start, strlen($maskedPart));
            $offset  = $start + strlen($maskedPart);
        }

        return $parts;
    }
}
