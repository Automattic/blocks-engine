<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LinkedResponsiveContentBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\ImageUrlResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Lowers anchors: image-only and linked-image anchors, responsive linked
 * content, and link wrappers around block content, including the mixed-media
 * link groups a paragraph unwraps into.
 */
final class AnchorLowering implements ButtonLinkLeftovers
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly PatternTreeConverter $tree,
        private readonly StyleResolver $styleResolver,
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly SourceElementDescription $sourceElementDescription,
        private readonly ImageLowering $imageLowering,
        private readonly ImageUrlResolver $imageUrls,
        private readonly LayoutShellLowering $layoutShells,
        private readonly LinkWrapperPropagator $linkWrapperPropagator,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly CustomImageHostLowering $customImageHosts
    ) {
    }

    public function imageBlockFromAnchor(DOMElement $anchor): ?array
    {
        $href = LinkUrlSanitizer::sanitize(SourceDom::attr($anchor, 'href'));
        if ( ! $this->imageLowering->isImageOnlyAnchor($anchor) ) {
            return null;
        }
        if ( '' === $href ) {
            $picture = SourceDom::firstChildElement($anchor, 'picture');
            if ( $picture instanceof DOMElement ) {
                $image = SourceDom::firstChildElement($picture, 'img');
                return $image instanceof DOMElement ? $this->imageLowering->convertImage($image, null, $picture, $anchor) : null;
            }
            $image = SourceDom::firstChildElement($anchor, 'img');
            return $image instanceof DOMElement ? $this->imageLowering->convertImage($image, null, null, $anchor) : null;
        }
        if ( $this->runtimeIslands->isRuntimeDomTarget($anchor)
            || array() !== SourceDom::eventMetadata($anchor)
            || ( array() !== $this->sourceElementDescription->interactiveAttributes($anchor) && ! $this->isUnfocusableImageLink($anchor) )
            || SourceDom::hasRouteBearingDataAttributes($anchor)
        ) {
            return $this->imageLowering->responsiveMediaBlock($anchor);
        }
        $picture = SourceDom::firstChildElement($anchor, 'picture');
        $image = $picture instanceof DOMElement
            ? SourceDom::firstChildElement($picture, 'img')
            : SourceDom::firstChildElement($anchor, 'img');
        if ( $image instanceof DOMElement && SourceDom::hasRouteBearingDataAttributes($image) ) {
            return $this->imageLowering->responsiveMediaBlock($anchor);
        }
        if ( $picture instanceof DOMElement ) {
            return $image instanceof DOMElement ? $this->imageLowering->convertImage($image, null, $picture, $anchor) : null;
        }
        if ( ! $image instanceof DOMElement && 0 < $anchor->getElementsByTagName('img')->length ) {
            $host = SourceDom::elementElementChildren($anchor);
            if (1 === count($host) && ($customImage = SourceDom::imageOnlyCustomElement($host[0])) instanceof DOMElement) {
                // A single retained host does not need a separate link
                // editing surface. Let core/image own the destination while
                // the host continues to own its captured presentation box.
                $media = $this->customImageHosts->nativeImageIn($host[0], $customImage, $anchor);
                if (null !== $media) {
                    return $media;
                }
            }
            // Flatten a single image nested in inert presentation boxes just as
            // a directly linked image, while retaining the outer box's styling
            // on the replacement core/image figure.
            if ( 1 === $anchor->getElementsByTagName('img')->length ) {
                $carrier = null;
                foreach ( $anchor->childNodes as $child ) {
                    if ( $child instanceof DOMElement && SourceDom::imageOnlyCarrierElement($child) instanceof DOMElement ) {
                        $carrier = $child;
                        break;
                    }
                }
                if ( $carrier instanceof DOMElement ) {
                    $image = SourceDom::imageOnlyCarrierElement($carrier);
                    $safe = true;
                    for ( $node = $image?->parentNode; $node instanceof DOMElement && ! $node->isSameNode($anchor); $node = $node->parentNode ) {
                        if ( 'picture' === strtolower($node->tagName) ) {
                            if ( $this->sourceElementClassifier->hasPictureSourceSelection($node) ) {
                                $safe = false;
                                break;
                            }
                        }
                        $customHost = ! in_array(strtolower($node->tagName), array('div', 'span', 'picture'), true);
                        if ( $customHost && ( null === SourceDom::imageOnlyCustomElement($node)
                            || null === $this->customImageHosts->nativeImageIn($node, $image) ) ) {
                            $safe = false;
                            break;
                        }
                        if ( $this->runtimeIslands->isRuntimeDomTarget($node)
                            || array() !== SourceDom::eventMetadata($node)
                            || array() !== $this->sourceElementDescription->interactiveAttributes($node)
                            || SourceDom::hasRouteBearingDataAttributes($node) ) {
                            $safe = false;
                            break;
                        }
                    }
                    if ( $safe && $image instanceof DOMElement ) {
                        $host = $image->parentNode;
                        if ( $host instanceof DOMElement && 'picture' === strtolower($host->tagName) ) {
                            $host = $host->parentNode;
                        }
                        if ( $host instanceof DOMElement && null !== SourceDom::imageOnlyCustomElement($host) ) {
                            $media = $this->customImageHosts->nativeImageIn($host, $image);
                            if ( null !== $media ) {
                                // The custom-host block already retains the host and
                                // picture. Keep every outer presentation box as well,
                                // in source order, rather than flattening the chain.
                                $wrappers = array();
                                for ( $node = $host->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
                                    array_unshift($wrappers, $node);
                                    if ( $node->isSameNode($anchor) ) {
                                        break;
                                    }
                                }
                                return $this->layoutShells->blockForElements($wrappers, array($media), $anchor);
                            }
                        }
                        if ( '' !== $href && $this->linkIdentityScopesImageBox($anchor, $carrier) ) {
                            return $this->linkedSizedImageBoxBlock($anchor, $image, $carrier, $href);
                        }
                        return $this->imageLowering->convertImage($image, $carrier, null, $anchor);
                    }
                }
            }
            return $this->imageLowering->responsiveMediaBlock($anchor);
        }
        return $image instanceof DOMElement ? $this->imageLowering->convertImage($image, null, null, $anchor) : null;
    }

    /** @return array<string, mixed>|null */
    public function linkedResponsiveContentBlockFromAnchor(DOMElement $anchor): ?array
    {
        $attrs = (new LinkedResponsiveContentProjector(
            $this->styleResolver,
            $this->imageUrls->sourceUrl(...),
            $this->imageUrls->resolvedAssetUrl(...),
            fn (string $url): int => (int) ($this->imageLowering->assetMetadataImageAttributes($url)['id'] ?? 0),
            $this->runtimeIslands,
            fn (DOMElement $element): bool => array() !== SourceDom::eventMetadata($element),
            fn (DOMElement $element): bool => array() !== $this->sourceElementDescription->interactiveAttributes($element),
            SourceDom::hasRouteBearingDataAttributes(...),
        ))->project($anchor);
        if ( null === $attrs ) {
            return null;
        }

        $generator = new LinkedResponsiveContentBlockGenerator();
        $this->session->requireGeneratedBlockRegistry()->register(LinkedResponsiveContentBlockGenerator::class, $generator->definition($this->session->requireGeneratedBlockRegistry()->namespace()));
        $markup = $generator->markup($attrs);

        return array(
            'blockName' => $this->session->requireGeneratedBlockRegistry()->blockName(LinkedResponsiveContentBlockGenerator::LOCAL_NAME),
            'attrs' => $generator->commentAttributes($attrs),
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    /**
     * A paragraph that only wraps one anchor mixing an image with text is not
     * RichText — RichText rejects images — but it is not an unsupported
     * fragment either. The container conversion path turns the same anchor
     * shape into a link-wrapper group: an image block plus text blocks with
     * the anchor propagated onto them. Offer the paragraph that same lowering
     * before its RichText gate rejects the image as a core/html island.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function mixedMediaLinkGroupFromParagraph(DOMElement $paragraph, array &$fallbacks): ?array
    {
        $anchor = null;
        foreach ( $paragraph->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( $anchor instanceof DOMElement || 'a' !== strtolower($child->tagName) ) {
                    return null;
                }
                $anchor = $child;
                continue;
            }
            if ( '' !== trim($child->textContent ?? '') ) {
                return null;
            }
        }

        if ( ! $anchor instanceof DOMElement
            || $this->imageLowering->isImageOnlyAnchor($anchor)
            || '' === trim($anchor->textContent ?? '')
            || 0 === $anchor->getElementsByTagName('img')->length
        ) {
            return null;
        }

        $linkedContent = $this->linkedResponsiveContentBlockFromAnchor($anchor);
        if ( null !== $linkedContent ) {
            if ( '' === trim(SourceDom::attr($paragraph, 'class')) && '' === trim(SourceDom::attr($paragraph, 'id')) && '' === trim(SourceDom::attr($paragraph, 'style')) ) {
                return $linkedContent;
            }

            return $this->blocks->createBlock('core/group', $this->styleResolver->presentationAttributes($paragraph), array( $linkedContent ), $paragraph);
        }

        $group = $this->convertLinkWrapperGroup($anchor, $fallbacks);
        if ( null === $group ) {
            return null;
        }

        // The paragraph is the box this group replaces. Its presentation leads
        // so source selectors addressing the paragraph keep a styled host; the
        // unwrapped anchor's own presentation fills the attributes it alone set.
        $attrs         = (array) ($group['attrs'] ?? array());
        $paragraphAttrs = $this->styleResolver->presentationAttributes($paragraph);
        if ( isset($attrs['style'], $paragraphAttrs['style']) && is_array($attrs['style']) && is_array($paragraphAttrs['style']) ) {
            $attrs['style'] = array_merge($attrs['style'], $paragraphAttrs['style']);
            unset($paragraphAttrs['style']);
        }
        $group['attrs'] = array_merge($attrs, $paragraphAttrs);

        return $group;
    }

    /**
     * Card thumbnails mark their link tabindex="-1" so keyboard users meet only
     * the title link to the same URL. core/image cannot store a link tabindex,
     * so an image-only link that opts out of focus lowers natively and drops it.
     * A link also hidden from assistive technology stays retained: lowering it
     * would expose a hidden duplicate link, not just add a tab stop.
     */
    private function isUnfocusableImageLink(DOMElement $anchor): bool
    {
        return 'a' === strtolower($anchor->tagName)
            && '-1' === trim(SourceDom::attr($anchor, 'tabindex'))
            && 'true' !== strtolower(trim(SourceDom::attr($anchor, 'aria-hidden')))
            && 1 === $anchor->getElementsByTagName('img')->length;
    }

    /**
     * Whether a link with its own identity (class or id) wraps an image-only
     * box that sets its own size. Authored rules can then size that box
     * through the link (`.card .art{height}`), so it must keep sitting inside
     * the link rather than becoming the figure around it.
     */
    private function linkIdentityScopesImageBox(DOMElement $anchor, DOMElement $box): bool
    {
        if ( '' === trim(SourceDom::attr($anchor, 'class') . SourceDom::attr($anchor, 'id')) ) {
            return false;
        }
        $declarations = $this->styleResolver->presentationDeclarations($box);
        foreach ( array( 'width', 'height', 'max-width', 'max-height', 'min-width', 'min-height', 'aspect-ratio' ) as $property ) {
            $value = CssValueInspector::comparable((string) ($declarations[$property] ?? ''));
            if ( '' !== $value && ! in_array($value, array( 'auto', 'none', 'initial', 'unset', 'inherit' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower `a > sized box > img` in source order: the anchor stays the outer
     * link (a layout-shell wrapper, as for flow-content anchors) around the
     * core/image whose figure is the sized box. Folding the link into the
     * image instead yields `figure.box > a > img`, inverting the ancestry
     * that descendant selectors written against the source rely on.
     *
     * @return array<string, mixed>|null
     */
    private function linkedSizedImageBoxBlock(DOMElement $anchor, DOMElement $image, DOMElement $box, string $href): ?array
    {
        $imageBlock = $this->imageLowering->convertImage($image, $box);
        if ( null === $imageBlock ) {
            return null;
        }
        $anchor->setAttribute('href', $href);

        return $this->layoutShells->blockForElements(array( $anchor ), array( $imageBlock ), $anchor);
    }

    /**
     * @return array<string, string>
     */
    private function cardLinkAttributes(DOMElement $anchor): array
    {
        $href = LinkUrlSanitizer::sanitize(SourceDom::attr($anchor, 'href'));
        if ( '' === $href ) {
            return array();
        }

        return array_filter(array(
            'href'      => $href,
            'target'    => SourceDom::attr($anchor, 'target'),
            'rel'       => SourceDom::attr($anchor, 'rel'),
            'ariaLabel' => SourceDom::attr($anchor, 'aria-label'),
        ), static fn (string $value): bool => '' !== trim($value));
    }

    /**
     * Record a content-wrapping anchor whose link could not be preserved on any
     * native link-bearing inner block, because the resulting core/group exposes
     * no native link attribute of its own (#260). The link details (selector +
     * href) are captured for diagnostics so the navigation loss is detectable
     * and a downstream repair loop can act on it, rather than the link being
     * silently dropped.
     */
    private function recordDroppedLinkWrapper(DOMElement $anchor): void
    {
        $link = $this->cardLinkAttributes($anchor);
        if ( array() === $link ) {
            return;
        }

        $this->session->transformationEvidenceState()->recordDroppedLinkWrapper(array_merge(
            array(
                'kind'     => 'source link wrapper dropped / content no longer navigable',
                'tag'      => strtolower($anchor->tagName),
                'selector' => SourceDom::elementSelector($anchor),
            ),
            $link
        ));
    }

    /**
     * Preserve a flow-content link as an existing layout shell around editable
     * inner blocks. Bare divs keep their line-box boundaries rather than being
     * coalesced across media. Phrasing-only buttons are handled upstream.
     * Media-only wrappers retain native image-link/lightbox handling; if no
     * inner block can carry that link, emit the existing structured finding.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function convertLinkWrapperGroup(DOMElement $anchor, array &$fallbacks): ?array
    {
        $children = $this->tree->children($anchor, $fallbacks, true, $this->sourceElementClassifier->isFlowContentAnchor($anchor));
        if ( array() === $children ) {
            return null;
        }

        $linkAttrs = $this->linkWrapperPropagator->linkPropagationAttributes($anchor);
        // A gallery thumbnail wraps its image, and often a caption overlay, in
        // a lightbox trigger. That link is the viewer, not a destination, so
        // core's native lightbox replaces it rather than propagating an href
        // that would navigate away from the page.
        if ( $this->imageLowering->imageLinkOpensNativeLightbox($anchor) && $this->imageLowering->enableNativeLightboxOnImages($children) ) {
            return $this->blocks->createBlock('core/group', $this->styleResolver->presentationAttributes($anchor), $children, $anchor);
        }
        // Keep a flow-content anchor as one clickable container. The existing
        // layout shell saves exact wrapper attributes around editable blocks;
        // unlike link propagation it also retains ancestor selectors and hitbox.
        if ( $this->sourceElementClassifier->isFlowContentAnchor($anchor) ) {
            $href = LinkUrlSanitizer::sanitize(SourceDom::attr($anchor, 'href'));
            if ( '' === $href ) {
                $anchor->removeAttribute('href');
            } else {
                $anchor->setAttribute('href', $href);
            }
            return $this->layoutShells->blockForElements(array($anchor), $children, $anchor);
        }
        if ( array() !== $linkAttrs && ! $this->linkWrapperPropagator->propagateLinkWrapper($children, $linkAttrs) ) {
            $this->recordDroppedLinkWrapper($anchor);
        }
        // The link now lives on the children, so the group is the anchor's box.
        // An author rule that selects the anchor (a card link laid out as a
        // flex column) must follow it onto the group via a semantic marker.
        $attrs = $this->styleResolver->presentationAttributes($anchor);
        $path = $anchor->getNodePath() ?? '';
        $projections = $this->session->authorSelectorProjectionState();
        if ( '' !== $path && $projections->isControlPath($path) ) {
            $attrs['className'] = SourceDom::mergeClassNames((string) ($attrs['className'] ?? ''), ...$projections->semanticCarrierClasses($projections->ensureSemanticMarker($path), $path));
        }

        return $this->blocks->createBlock('core/group', $attrs, $children, $anchor);
    }
}
