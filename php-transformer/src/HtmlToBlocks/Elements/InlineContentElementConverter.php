<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SocialLinksPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/** Converts phrasing-content elements to editable text or structural carriers. */
final class InlineContentElementConverter
{
    public function __construct(
        private readonly InlineContentElementContext $context,
        private readonly StyleResolver $styleResolver,
        private readonly Runtime $runtime
    ) {
    }

    public function handles(string $tagName): bool
    {
        return self::handlesTag($tagName);
    }

    public static function handlesTag(string $tagName): bool
    {
        return in_array($tagName, array( 'abbr', 'b', 'cite', 'code', 'em', 'font', 'i', 'kbd', 'mark', 'rp', 'rt', 'ruby', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'var' ), true);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( ! $this->handles($tagName) ) {
            return ConversionOutcome::unhandled();
        }

        $socialLinks = $this->context->recognizePatterns($element, $fallbacks, array( SocialLinksPattern::class ));
        if ( null !== $socialLinks ) {
            return ConversionOutcome::handled($socialLinks);
        }

        if ( $this->context->isRuntimeDomTarget($element) ) {
            return ConversionOutcome::handled($this->context->htmlPreservationBlock($element));
        }

        $inlineSvgTextGroup = $this->context->inlineSvgTextGroupBlock($element);
        if ( null !== $inlineSvgTextGroup ) {
            return ConversionOutcome::handled($inlineSvgTextGroup);
        }

        if ( $this->context->ownsPositioningGeometry($element) ) {
            $carrier = $this->context->positionedInlineCarrierBlock($element, $fallbacks);
            if ( null !== $carrier ) {
                return ConversionOutcome::handled($carrier);
            }
        }

        if ( $this->context->hasAuthorSemanticMarker($element) ) {
            $content = $this->context->innerHtml($element);
            if ( '' !== trim($this->runtime->stripAllTags($content)) ) {
                if ( $this->context->richTextContentHasStructuralHtml($content) ) {
                    $children = $this->context->convertChildren($element, $fallbacks);
                    if ( array() !== $children ) {
                        return ConversionOutcome::handled($this->group($element, $children));
                    }
                }
                return ConversionOutcome::handled($this->group($element, array(
                    $this->context->createBlock('core/paragraph', array( 'content' => $content )),
                )));
            }
        }

        $richTextMarker = $this->context->richTextMarker($element);
        if ( '' !== $richTextMarker ) {
            if ( $this->context->hasBlockContentChildren($element) || $this->context->richTextContentHasStructuralHtml($this->context->innerHtml($element)) ) {
                $children = $this->context->convertChildren($element, $fallbacks);
                if ( array() !== $children ) {
                    return ConversionOutcome::handled($this->group($element, $children));
                }
            }
            $content = $this->context->innerHtml($element);
            if ( '' !== trim($this->runtime->stripAllTags($content)) ) {
                $declarations = $this->context->richTextInlineVisualDeclarations($element);
                if ( 'transparent' === strtolower((string) ($declarations['-webkit-text-fill-color'] ?? '')) ) {
                    $declarations['color'] = 'transparent';
                }
                $declarations['--blocks-engine-richtext-marker'] = $richTextMarker;
                return ConversionOutcome::handled($this->context->createBlock('core/paragraph', array(
                    'content' => '<mark style="' . htmlspecialchars($this->styleResolver->cssDeclarationString($declarations), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $content . '</mark>',
                ), array(), $element));
            }
        }

        $dynamicText = $this->context->dynamicTextContent($element);
        if ( null !== $dynamicText ) {
            return ConversionOutcome::handled($this->context->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($element), array( 'content' => $this->runtime->escapeHtml($dynamicText) )), array(), $element));
        }

        $content = $this->context->outerHtml($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            $children = $this->context->convertChildren($element, $fallbacks);
            if ( 1 === count($children) && array() === $this->styleResolver->presentationAttributes($element) ) {
                return ConversionOutcome::handled($children[0]);
            }
            if ( array() !== $children ) {
                return ConversionOutcome::handled($this->group($element, $children));
            }
            if ( $this->context->shouldPreserveEmptyVisualElement($element) ) {
                return ConversionOutcome::handled($this->context->emptyVisualSpacerBlock($element));
            }
            return ConversionOutcome::handled(null);
        }

        $listItem = $this->context->ancestorElement($element, 'li');
        $sourceElement = $this->context->richTextContentHasStructuralHtml($content)
            || ($listItem instanceof DOMElement && $this->context->isStructuralListItem($listItem))
            ? $element
            : null;
        return ConversionOutcome::handled($this->context->createBlock('core/paragraph', array( 'content' => $content ), array(), $sourceElement));
    }

    /** @param array<int, array<string, mixed>> $children @return array<string, mixed> */
    private function group(DOMElement $element, array $children): array
    {
        return $this->context->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
    }
}
