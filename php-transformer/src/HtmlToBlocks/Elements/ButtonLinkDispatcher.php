<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\InlineStackingClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonAnchorPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\LogoPattern;
use DOMElement;

/**
 * Routes `a` and `button` elements to their native block, a preserved runtime
 * island, or a paragraph host for the saved link.
 *
 * Button-shaped controls go through {@see ButtonAnchorPattern} /
 * {@see ButtonPattern} first. Remaining branches are non-button leftovers.
 */
final class ButtonLinkDispatcher
{
    /**
     * Marks the paragraph host that carries geometry for an absolutely
     * positioned fragment link, whose own positioning cannot ride the saved
     * anchor. Read back out of serialized output by the transformer.
     */
    public const POSITIONED_FRAGMENT_LINK_CARRIER_CLASS = 'blocks-engine-positioned-fragment-link-carrier';

    public function __construct(private readonly ButtonLinkDispatchContext $context)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function convertAnchor(DOMElement $element, array &$fallbacks): ?array
    {
        $button = $this->context->recognizePatterns($element, $fallbacks, array( ButtonAnchorPattern::class ));
        if ( null !== $button ) {
            return $button;
        }

        if ( $this->context->isRuntimeDomTarget($element) ) {
            return $this->context->htmlPreservationBlock($element);
        }

        $linkedLogo = $this->context->linkedSvgLogoBlockFromAnchor($element, $fallbacks);
        if ( null !== $linkedLogo ) {
            return $linkedLogo;
        }

        $logo = $this->context->recognizePatterns($element, $fallbacks, array( LogoPattern::class ));
        if ( null !== $logo ) {
            return $logo;
        }

        $linkedImage = $this->context->imageBlockFromAnchor($element);
        if ( null !== $linkedImage ) {
            return $linkedImage;
        }

        // An icon-only link still carries an accessible name, so it must survive
        // even though it has no text content.
        if ( '' === trim($element->textContent ?? '') && '' !== $this->context->safeLinkUrl(SourceDom::attr($element, 'href')) && '' !== trim(SourceDom::attr($element, 'aria-label')) ) {
            return $this->paragraphHost($element);
        }

        if ( '' === trim($element->textContent ?? '') ) {
            return null;
        }

        // Tag-wise inline children can still stack: a linked brand lockup whose
        // spans render as block boxes keeps two authored lines that a paragraph
        // host would merge and de-link. Convert it like a link wrapper.
        if ( $this->context->hasBlockContentChildren($element) || $this->stacksLinkedInlineChildren($element) ) {
            $linkWrapper = $this->context->convertLinkWrapperGroup($element, $fallbacks);
            if ( null !== $linkWrapper ) {
                return $linkWrapper;
            }
        }

        // A non-button anchor has no native width support. Promote its source
        // presentation to the paragraph wrapper so generated geometry remains
        // attached to the rendered block rather than being silently discarded.
        // Its id remains on the inner link, the node that source selectors and
        // fragment navigation actually address.
        return $this->paragraphHost($element);
    }

    /**
     * Whether a linked anchor's tag-wise inline children render as stacked
     * block boxes.
     *
     * Only linked anchors qualify: an anchor without a navigable href has no
     * link to propagate onto inner blocks, and its inline runs flatten
     * harmlessly on one line.
     */
    private function stacksLinkedInlineChildren(DOMElement $anchor): bool
    {
        if ( '' === $this->context->safeLinkUrl(SourceDom::attr($anchor, 'href')) ) {
            return false;
        }

        return InlineStackingClassifier::stacksInlineChildren($anchor, $this->context->structuralPresentationDeclarations(...));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function convertButton(DOMElement $element): ?array
    {
        $fallbacks = array();
        $button = $this->context->recognizePatterns($element, $fallbacks, array( ButtonPattern::class ));
        if ( null !== $button ) {
            return $button;
        }

        if ( $this->context->isRuntimeDomTarget($element) ) {
            $this->context->recordRuntimeControlIsland($element);
            return $this->context->htmlPreservationBlock($element);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraphHost(DOMElement $element): array
    {
        return $this->context->createBlock(
            'core/paragraph',
            array_merge($this->nonButtonAnchorWrapperAttributes($element), array( 'content' => SourceDom::outerHtml($element) )),
            array(),
            $element
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function nonButtonAnchorWrapperAttributes(DOMElement $anchor): array
    {
        $attrs = $this->context->presentationAttributes($anchor);
        unset($attrs['anchor']);

        if ( $this->isPositionedFragmentLink($anchor) ) {
            $attrs['className'] = SourceDom::mergeClassNames(
                (string) ($attrs['className'] ?? ''),
                self::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS
            );
        }

        // Source class identity belongs exclusively to the saved link. Keep only
        // generated geometry classes and mapped presentation on its paragraph host.
        $sourceClasses = preg_split('/\s+/', trim(SourceDom::attr($anchor, 'class'))) ?: array();
        $classes = array_values(array_filter(
            preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array(),
            static fn (string $class): bool => ! in_array($class, $sourceClasses, true)
        ));
        if ( array() === $classes ) {
            unset($attrs['className']);
        } else {
            $attrs['className'] = implode(' ', $classes);
        }

        // The same reasoning covers the presentation those classes resolve to.
        // The host saves the anchor verbatim, so a margin the author wrote on
        // the anchor still arrives with it; restating that margin as a block
        // attribute applies it a second time, once on the host box and once on
        // the link inside it. Spacing the author never wrote on the anchor has
        // no source to arrive from, so only the duplicated half is dropped.
        if ( '' !== trim(SourceDom::attr($anchor, 'class')) && is_array($attrs['style']['spacing'] ?? null) ) {
            unset($attrs['style']['spacing']);
            if ( array() === $attrs['style'] ) {
                unset($attrs['style']);
            }
        }

        return $attrs;
    }

    private function isPositionedFragmentLink(DOMElement $anchor): bool
    {
        $href = trim(SourceDom::attr($anchor, 'href'));
        if ( ! str_starts_with($href, '#') || '#' === $href || 'button' === strtolower(SourceDom::attr($anchor, 'role')) ) {
            return false;
        }

        $position = strtolower(trim((string) ($this->context->structuralPresentationDeclarations($anchor)['position'] ?? '')));

        return in_array($position, array( 'absolute', 'fixed' ), true);
    }
}
