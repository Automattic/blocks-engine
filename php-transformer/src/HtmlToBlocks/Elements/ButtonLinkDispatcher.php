<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonAnchorPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\LogoPattern;
use DOMElement;

/**
 * Routes `a` and `button` elements to their native block, a preserved runtime
 * island, or a paragraph host for the saved link.
 *
 * Previously `ButtonLinkDispatchTrait`. As a single-consumer trait its methods
 * still resolved against the transformer's `$this`; here they run against an
 * explicit {@see ButtonLinkDispatchContext}.
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
        if ( $this->context->isRuntimeDomTarget($element) ) {
            return $this->context->htmlPreservationBlock($element);
        }

        $linkedLogo = $this->context->linkedSvgLogoBlockFromAnchor($element, $fallbacks);
        if ( null !== $linkedLogo ) {
            return $linkedLogo;
        }

        $button = $this->context->recognizePatterns($element, $fallbacks, array(ButtonAnchorPattern::class));
        if ( null !== $button ) {
            return $button;
        }

        $logo = $this->context->recognizePatterns($element, $fallbacks, array(LogoPattern::class));
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

        if ( $this->context->hasBlockContentChildren($element) ) {
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
     * @return array<string, mixed>|null
     */
    public function convertButton(DOMElement $element): ?array
    {
        if ( $this->context->isRuntimeDomTarget($element) ) {
            $this->context->recordRuntimeControlIsland($element);
            return $this->context->htmlPreservationBlock($element);
        }

        $fallbacks = array();

        return $this->context->recognizePatterns($element, $fallbacks, array(ButtonPattern::class));
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
