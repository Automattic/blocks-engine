<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\BackgroundImageExtractor;
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
        } catch ( Throwable ) {
            return null;
        }

        $localFallbacks = array();
        try {
            $children = $convertChildren($element, $localFallbacks, true);
        } catch ( Throwable ) {
            return null;
        }

        if ( null !== $this->textRejectionGate(array() !== $children && $this->containsTextBearingBlock($children)) ) {
            return null;
        }

        $excludedProperties = array( 'background', 'background-image', 'background-size', 'background-position', 'background-repeat' );
        try {
            $attrs = $presentationAttributes($element, $excludedProperties);
        } catch ( Throwable ) {
            $attrs = array();
        }
        unset($attrs['layout']);

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
        $backgroundUrl = $this->backgroundImageExtractor->urlFromStyle($style);
        if ( '' === $backgroundUrl ) {
            return 'no_background_url';
        }

        if ( ! $this->styleResolver->meetsHeroSizeGate($style) ) {
            return 'not_hero_sized';
        }

        if ( $this->styleResolver->hasRepeatingBackground($style) ) {
            return 'repeating_background';
        }

        return null;
    }

    private function textRejectionGate(bool $hasTextBearingChildren): ?string
    {
        return $hasTextBearingChildren ? null : 'no_text_content';
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
     * @param array<string, mixed> $attrs
     */
    private function removeConsumedGradient(array &$attrs): void
    {
        if ( ! isset($attrs['style']) || ! is_array($attrs['style']) ) {
            return;
        }
        if ( isset($attrs['style']['color']) && is_array($attrs['style']['color']) ) {
            unset($attrs['style']['color']['gradient']);
            if ( array() === $attrs['style']['color'] ) {
                unset($attrs['style']['color']);
            }
        }
        if ( array() === $attrs['style'] ) {
            unset($attrs['style']);
        }
    }
}
