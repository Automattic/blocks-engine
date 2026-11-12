<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\HtmlDimensionAttribute;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\MonochromeGlyphColor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgPathCoverage;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\MenuVocabulary;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SocialSourceStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use DOMElement;

/** Lowers explicit social-profile clusters to the core social-links family. */
final class SocialLinksPattern implements PatternRecognizerInterface
{
    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock
    ) {
    }

    use PatternDomHelpersTrait;

    /** @var array<string,string> */
    private const HOST_SERVICES = array(
        'behance.net' => 'behance', 'bluesky.app' => 'bluesky', 'discord.com' => 'discord',
        'discord.gg' => 'discord', 'dribbble.com' => 'dribbble', 'facebook.com' => 'facebook',
        'github.com' => 'github', 'gitlab.com' => 'gitlab', 'instagram.com' => 'instagram',
        'linkedin.com' => 'linkedin', 'mastodon.social' => 'mastodon', 'pinterest.com' => 'pinterest',
        'reddit.com' => 'reddit', 'soundcloud.com' => 'soundcloud', 'spotify.com' => 'spotify',
        'telegram.me' => 'telegram', 'telegram.org' => 'telegram', 'threads.net' => 'threads',
        'tiktok.com' => 'tiktok', 'tumblr.com' => 'tumblr', 'twitch.tv' => 'twitch',
        'twitter.com' => 'twitter', 'vimeo.com' => 'vimeo', 'whatsapp.com' => 'whatsapp',
        'x.com' => 'x', 'youtube.com' => 'youtube', 'youtu.be' => 'youtube',
    );

    /** @var array<string,string> */
    private const LABEL_SERVICES = array(
        'behance' => 'behance', 'bluesky' => 'bluesky', 'discord' => 'discord',
        'dribbble' => 'dribbble', 'facebook' => 'facebook', 'github' => 'github',
        'gitlab' => 'gitlab', 'instagram' => 'instagram', 'linkedin' => 'linkedin',
        'mastodon' => 'mastodon', 'pinterest' => 'pinterest', 'reddit' => 'reddit',
        'soundcloud' => 'soundcloud', 'spotify' => 'spotify', 'telegram' => 'telegram',
        'threads' => 'threads', 'tiktok' => 'tiktok', 'tumblr' => 'tumblr',
        'twitch' => 'twitch', 'twitter' => 'twitter', 'vimeo' => 'vimeo',
        'whatsapp' => 'whatsapp', 'x' => 'x', 'x twitter' => 'x',
        'youtube' => 'youtube', 'email' => 'mail', 'mail' => 'mail',
    );

    /** @var array<string,string> */
    private array $destinationGlyphColor = array();

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        return $this->recognizeCluster($element, $context, self::hasSocialIntent($element));
    }

    private function recognizeCluster(DOMElement $element, PatternContext $context, bool $explicit): ?PatternRecognitionResult
    {
        // A source navigation landmark carries menu semantics that core/social-links
        // cannot retain; NavigationPattern owns that dynamic landmark contract.
        if ( self::hasOtherSemanticOwner($element) ) {
            return null;
        }
        $anchors = $this->anchors($element);
        $explicit = $explicit || self::isExplicitSocialCluster($element);
        if ( array() === $anchors || (! $explicit && ! $this->isSocialCluster($element, $anchors)) ) {
            return null;
        }

        // A named social region can wrap the actual icon row. Its width or
        // alignment belongs to the outer box, while responsive gap and margins
        // belong to the inner row; flattening them loses the row's CSS hooks.
        $children = $this->directChildElements($element);
        if ( in_array(strtolower($element->tagName), array('div', 'section', 'span'), true)
            && 1 === count($children)
            && in_array(strtolower($children[0]->tagName), array('div', 'span'), true)
            && $this->hasOnlyChild($element, $children[0])
        ) {
            $row = $this->recognizeCluster($children[0], $context, $explicit);
            if ( null !== $row ) {
                return new PatternRecognitionResult($this->createBlock->createBlock(
                    'core/group',
                    $context->presentationAttributes($element),
                    array($row->block()),
                    $element
                ));
            }
        }

        // Descendant URLs identify social intent, not subtree ownership. A row
        // owns only its direct links (or single-link list items). Mixed wrappers
        // decline so the normal converter retains every editable sibling and
        // the ancestors that own responsive layout.
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMText && '' !== trim(str_replace("\u{00a0}", ' ', $child->textContent))) return null;
            if (!$child instanceof DOMElement) continue;
            if ('a' === strtolower($child->tagName) && in_array($child, $anchors, true)) continue;
            if (in_array(strtolower($child->tagName), array('li', 'div', 'span'), true)) {
                $itemAnchors = $this->anchors($child);
                if (1 === count($itemAnchors) && $this->isSingleLinkWrapper($child, $itemAnchors[0])) continue;
            }
            return null;
        }

        // Core's filled service glyph cannot reproduce arbitrary source artwork.
        // Keep SVG live (rather than freezing currentColor) and images intact
        // unless monochrome glyph evidence and a known service permit replacement.
        $sourceArtwork = false;
        $capturedPaint = array();
        foreach ($anchors as $anchor) {
            $service = self::serviceForUrl($anchor->getAttribute('href')) ?? $this->serviceFromLabel($anchor->getAttribute('aria-label') ?: $anchor->getAttribute('title'));
            foreach ($anchor->getElementsByTagName('img') as $image) {
                if (!$this->isUsableSocialUrl(LinkUrlSanitizer::sanitize($anchor->getAttribute('href')))
                    && !($explicit && null !== $service && $this->isLocalPlaceholderUrl($anchor->getAttribute('href')))) {
                    return null;
                }
                if (null === $service || '' === ($this->glyphColor($image, $context) ?: $this->sameDestinationGlyphColor($anchor, $image, $context))) {
                    $sourceArtwork = true;
                }
            }
            foreach ($anchor->getElementsByTagName('svg') as $svg) {
                $hasArtwork = false;
                foreach ($svg->childNodes as $child) {
                    if ($child instanceof DOMElement && !in_array(strtolower($child->tagName), array('title', 'desc'), true)) $hasArtwork = true;
                }
                if (!$hasArtwork) continue;
                $color = $context->capturedGlyphColor($svg);
                if (!$this->matchesCoreArtwork($svg, $service)) {
                    $sourceArtwork = true;
                }
                // Never freeze authored currentColor: it must keep responding
                // to hover/focus. Capture paint is only a missing-paint fallback.
                if ('' !== $color && $color !== $this->concreteColor($context->authoredIconColor($anchor)) && !preg_match('/currentcolor/i', $svg->ownerDocument->saveHTML($svg) ?: '')) {
                    $capturedPaint[] = array($svg, $color);
                }
            }
        }
        // A filled, single-colour glyph on a bare link needs no markup of its
        // own: the native block paints it, so the owner gets Social Icons.
        $nativeArtwork = $sourceArtwork && $this->canPaintArtworkOnNativeLinks($anchors, $context);
        if ($sourceArtwork && !$nativeArtwork && !$this->hasNavigationAncestor($element) && null !== $context->markupContext() && null !== $context->buttonContext()) {
            // The existing editable link companion retains inline artwork and
            // its source selectors without a Custom HTML escape hatch.
            foreach ($capturedPaint as [$svg, $color]) {
                $svg->setAttribute('style', rtrim($svg->getAttribute('style'), "; \t\n\r") . ';fill:' . $color);
            }
            $artworkLinks = array();
            foreach ($anchors as $anchor) {
                $content = '';
                foreach ($anchor->childNodes as $child) {
                    if ($child instanceof DOMElement) {
                        $content .= $context->markupContext()->safeFallbackHtml($child);
                    } elseif ($child instanceof \DOMText) {
                        $content .= htmlspecialchars($child->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    }
                }
                $safeDocument = HtmlSerialization::parseDocument($context->markupContext()->safeFallbackHtml($anchor));
                $safeAnchor = $safeDocument->getElementsByTagName('a')->item(0);
                if (!$safeAnchor instanceof DOMElement) continue;
                $link = $context->buttonContext()->accessibleNameCompanion($safeAnchor, $content, null, true, $anchor)->block();
                $item = $this->structuralItem($anchor, $element);
                if (!$item->isSameNode($anchor)) {
                    $itemAttrs = $context->presentationAttributes($item);
                    if ('li' === strtolower($item->tagName)) $itemAttrs['tagName'] = 'li';
                    $link = $this->createBlock->createBlock('core/group', $itemAttrs, array($link), $item);
                }
                $artworkLinks[] = $link;
            }
            $attrs = $context->presentationAttributes($element);
            // A source list keeps its tag so element-type rules and its inline
            // formatting context carry over; only other containers need an
            // invented flex row.
            if (in_array(strtolower($element->tagName), array('ul', 'ol'), true)) {
                $attrs['tagName'] = strtolower($element->tagName);
            } else {
                $attrs['layout'] ??= array('type' => 'flex', 'flexWrap' => 'wrap');
            }
            return new PatternRecognitionResult($this->createBlock->createBlock('core/group', $attrs, $artworkLinks, $element));
        }

        $links = array();
        $linkAnchors = array();
        $showLabels = false;
        $iconOnly = true;
        $structuralItems = true;
        $sourceRow = !in_array(strtolower($element->tagName), array('ul', 'ol'), true) && $this->hasMixedParent($element);
        foreach ( $anchors as $anchor ) {
            $url = LinkUrlSanitizer::sanitize(SourceDom::attr($anchor, 'href'));
            $label = trim(SourceDom::attr($anchor, 'aria-label'));
            if ( '' === $label ) {
                $label = trim(SourceDom::attr($anchor, 'title'));
            }
            $text = $this->visibleText($anchor, $context);
            if ( '' === $label ) {
                // Clipped text still supplies the accessible name, even
                // though it must not enable Core's visible-label layout.
                $label = $text ?: trim($anchor->textContent);
                if (preg_match('/^[\s\x{E000}-\x{F8FF}]+$/u', $label)) $label = '';
            }
            $service = self::serviceForUrl($url) ?? $this->serviceFromLabel($label);
            $labeledPlaceholder = $explicit
                && $this->isLocalPlaceholderUrl($url)
                && null !== $service;
            if ( '' === $url || (! $this->isUsableSocialUrl($url) && ! $labeledPlaceholder) ) {
                return null;
            }
            $showLabels = $showLabels || '' !== $text;
            $iconOnly = $iconOnly && '' === $text && $this->hasIcon($anchor);
            $sourceElement = $this->structuralItem($anchor, $element);
            $structuralItems = $structuralItems && ! $sourceElement->isSameNode($anchor);
            $linkAnchors[] = $anchor;
            $linkAttrs = $context->presentationAttributes($sourceElement);
            if ($sourceRow) $linkAttrs['className'] = trim((string) ($linkAttrs['className'] ?? '') . ' blocks-engine-social-source-item');
            if ($nativeArtwork) $linkAttrs['className'] = trim((string) ($linkAttrs['className'] ?? '') . ' ' . SocialSourceStyleProjector::ARTWORK_CLASS);
            $links[] = $this->createBlock->createBlock('core/social-link', array_merge(
                $linkAttrs,
                array_filter(array(
                'url' => $url,
                'service' => $service ?? 'chain',
                'label' => $label,
                ), static fn(string $value): bool => '' !== $value)
            ), array(), $sourceElement);
        }

        if ( array() === $links ) {
            return null;
        }

        $attrs = $context->presentationAttributes($element);
        for ( $carrier = $element; $carrier instanceof DOMElement && 'body' !== strtolower($carrier->tagName); $carrier = $carrier->parentNode ) {
            if ( preg_match('/(?:^|;)\s*text-align\s*:\s*(left|center|right)\b/i', SourceDom::attr($carrier, 'style'), $alignment) ) {
                $attrs['justifyContent'] = strtolower($alignment[1]);
                break;
            }
        }
        $rowStyle = $context->socialPresentationDeclarations($element);
        $justification = trim((string) preg_replace('/\s*!important\s*$/i', '', $rowStyle['justify-content'] ?? ''));
        if ( in_array($justification, array('flex-start', 'start', 'left', 'center', 'flex-end', 'end', 'right', 'space-between'), true) ) {
            $attrs['layout']['type'] = 'flex';
            $attrs['layout']['justifyContent'] = match ($justification) {
                'flex-start', 'start' => 'left',
                'flex-end', 'end' => 'right',
                default => $justification,
            };
            $attrs['justifyContent'] = $attrs['layout']['justifyContent'];
        } elseif ( isset($attrs['justifyContent']) ) {
            $attrs['layout']['type'] = 'flex';
            $attrs['layout']['justifyContent'] = $attrs['justifyContent'];
        }
        if ( $showLabels ) {
            $attrs['showLabels'] = true;
        }
        if ( $iconOnly ) {
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' is-style-logos-only');
            // Painted artwork carries its exact source size until a preset is chosen.
            if ( ! $nativeArtwork ) {
                $attrs['size'] = $this->iconSize($anchors, $element, $context) ?? 'small';
            }
            $glyphSizes = array();
            foreach ($anchors as $anchor) {
                foreach ($anchor->getElementsByTagName('*') as $glyph) {
                    if (!$this->isFontGlyph($glyph)) continue;
                    $size = $context->socialPresentationDeclarations($glyph)['font-size'] ?? '';
                    if (preg_match('/^\d+(?:\.\d+)?px$/', $size)) $glyphSizes[$size] = true;
                }
            }
            if (1 === count($glyphSizes)) $attrs['inlineGeometryStyle'] = 'font-size:' . array_key_first($glyphSizes);
            $iconColor = $this->uniformIconColor($linkAnchors, $context);
            if ( '' !== $iconColor ) {
                $attrs['iconColorValue'] = $iconColor;
                $attrs['customIconColor'] = $iconColor;
            }
        }
        // Boxed links need the same paint on core's li as on the source anchor.
        // Do not replace logos-only paint or collapse per-service brand colors.
        if ( ! $showLabels && ! $iconOnly ) {
            $attrs = array_merge($attrs, $this->uniformAnchorColors($linkAnchors, $context));
        }
        if ( $structuralItems ) {
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' blocks-engine-source-social-item-spacing');
        }
        if ($sourceRow) {
            // The row's source box is a div/span, not a list. Retain that box
            // as an editable Group; the inserted native list/items contribute
            // no extra boxes or browser list defaults to its authored layout.
            $nativeAttrs = array_intersect_key($attrs, array_flip(array('layout', 'inlineGeometryStyle', 'showLabels', 'size', 'iconColor', 'iconColorValue', 'customIconColor', 'iconBackgroundColor', 'iconBackgroundColorValue', 'customIconBackgroundColor')));
            $nativeAttrs['className'] = 'blocks-engine-social-source-row' . ($iconOnly ? ' is-style-logos-only' : '');
            $native = $this->createBlock->createBlock('core/social-links', $nativeAttrs, $links);
            return new PatternRecognitionResult($this->createBlock->createBlock('core/group', $context->presentationAttributes($element), array($native), $element));
        }
        if (!in_array(strtolower($element->tagName), array('ul', 'ol'), true)) {
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' blocks-engine-social-source-non-list');
        }
        return new PatternRecognitionResult($this->createBlock->createBlock('core/social-links', $attrs, $links, $element));
    }

    /**
     * Navigation and captured panels require editable native descendants. Keep
     * their existing social-link conversion rather than introducing Custom HTML
     * into a navigation tree, including mixed-content dropdown panels.
     */
    private function hasNavigationAncestor(DOMElement $element): bool
    {
        for ($ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            if ('nav' === strtolower($ancestor->tagName)
                || 'navigation' === strtolower($ancestor->getAttribute('role'))
                || $ancestor->hasAttribute(CaptureEvidence::DIALOG_PANEL)
                || in_array(CaptureEvidence::DROPDOWN_CLASS, preg_split('/\s+/', $ancestor->getAttribute('class')) ?: array(), true)
                || in_array(CaptureEvidence::DIALOG_CLASS, preg_split('/\s+/', $ancestor->getAttribute('class')) ?: array(), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether every link is a known service with one captured, filled,
     * single-colour glyph and no box of its own. Anything else (several paints,
     * strokes, gradients, images, painted tiles, visible text) cannot be
     * expressed through the block's icon colour, so it keeps its source markup.
     *
     * @param array<int,DOMElement> $anchors
     */
    private function canPaintArtworkOnNativeLinks(array $anchors, PatternContext $context): bool
    {
        foreach ($anchors as $anchor) {
            $service = self::serviceForUrl($anchor->getAttribute('href')) ?? $this->serviceFromLabel($anchor->getAttribute('aria-label'));
            $svgs = $anchor->getElementsByTagName('svg');
            $svg = $svgs->item(0);
            if (null === $service || 1 !== $svgs->length || !$svg instanceof DOMElement
                || '' === ($svg->getAttribute('viewBox') ?: $svg->getAttribute('viewbox'))
                || '' !== $this->visibleText($anchor, $context)
                || 0 < $anchor->getElementsByTagName('img')->length
                || '' === $context->capturedGlyphColor($svg)
                || !SocialSourceStyleProjector::isSingleColourArtwork($svg)
                || $this->hasOwnBox($anchor, $context)) {
                return false;
            }
        }
        return array() !== $anchors;
    }

    private function hasOwnBox(DOMElement $anchor, PatternContext $context): bool
    {
        foreach ($context->socialPresentationDeclarations($anchor) as $property => $value) {
            $value = strtolower(trim((string) preg_replace('/\s*!important\s*$/i', '', $value)));
            if (preg_match('/^background(-color|-image)?$/', $property) && !in_array($value, array('', 'none', 'transparent', 'initial', 'unset'), true)) return true;
            if (preg_match('/^(padding|border)(-(top|right|bottom|left))?(-width)?$/', $property) && 1 !== preg_match('/^(0|0px|none)(\s+(0|0px|none))*$/', $value)) return true;
        }
        return false;
    }

    /** Core's icon snapshot is evidence of geometry parity, not URL identity. */
    private function matchesCoreArtwork(DOMElement $svg, ?string $service): bool
    {
        static $icons = null;
        $icons ??= json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/resources/wordpress-social-link-icons.json'), true);
        if (null === $service || !isset($icons[$service])) return false;
        $core = new \DOMDocument();
        if (!@$core->loadXML($icons[$service]) || !$core->documentElement instanceof DOMElement) return false;
        $signature = static function (DOMElement $element): string {
            $parts = array();
            foreach ($element->getElementsByTagName('*') as $child) {
                if (in_array(strtolower($child->tagName), array('title', 'desc'), true)) continue;
                $attrs = array();
                foreach ($child->attributes as $attr) {
                    $attrs[$attr->name] = preg_replace('/[\s,]+/', '', $attr->value);
                }
                ksort($attrs);
                $parts[] = array(strtolower($child->tagName), $attrs);
            }
            return json_encode($parts, JSON_THROW_ON_ERROR);
        };
        return 'none' !== strtolower($svg->getAttribute('fill'))
            && '' === $svg->getAttribute('stroke')
            && ($svg->getAttribute('viewBox') ?: $svg->getAttribute('viewbox')) === $core->documentElement->getAttribute('viewBox')
            && ($signature($svg) === $signature($core->documentElement) || $this->paintsSameArea($svg, $core->documentElement));
    }

    /**
     * Builders re-export the same service glyph at a different precision or
     * from a different start point. Compare the area each single filled path
     * paints; any edge moving more than half a sample cell keeps source artwork.
     */
    private function paintsSameArea(DOMElement $svg, DOMElement $core): bool
    {
        $path = static function (DOMElement $root): ?DOMElement {
            $artwork = array();
            foreach ($root->getElementsByTagName('*') as $child) {
                if (!in_array(strtolower($child->tagName), array('title', 'desc'), true)) $artwork[] = $child;
            }
            if (1 !== count($artwork) || 'path' !== strtolower($artwork[0]->tagName)) return null;
            foreach (array('transform', 'stroke', 'opacity', 'fill-opacity', 'clip-path', 'mask', 'filter') as $name) {
                if ($artwork[0]->hasAttribute($name)) return null;
            }
            return 'none' === strtolower($artwork[0]->getAttribute('fill')) ? null : $artwork[0];
        };
        $source = $path($svg);
        $target = $path($core);
        $viewBox = array_map('floatval', preg_split('/[\s,]+/', trim($core->getAttribute('viewBox'))) ?: array());
        if (null === $source || null === $target || 4 !== count($viewBox)) return false;
        $rule = static fn (DOMElement $node, DOMElement $root): string => strtolower($node->getAttribute('fill-rule') ?: $root->getAttribute('fill-rule') ?: 'nonzero');
        $difference = SvgPathCoverage::maxCellDifference($source->getAttribute('d'), $rule($source, $svg), $target->getAttribute('d'), $rule($target, $core), $viewBox);

        return null !== $difference && $difference <= 0.5;
    }

    /**
     * The anchor text a reader sees. An SVG's `<title>` and a visually clipped
     * label name the link for assistive technology only; core/social-link
     * keeps that name as its `label` and prints it the same hidden way.
     */
    private function visibleText(DOMElement $element, PatternContext $context): string
    {
        $text = '';
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof \DOMText ) {
                $text .= $child->textContent;
            } elseif ( $child instanceof DOMElement
                && ! in_array(strtolower($child->tagName), array('svg', 'script', 'style', 'template', 'noscript'), true)
                && ! $this->isFontGlyph($child)
                && ! $context->sourceElementStartsHidden($child)
                && ! $context->sourceElementVisuallyClipped($child) ) {
                $text .= $this->visibleText($child, $context);
            }
        }
        return trim(str_replace("\u{00a0}", ' ', $text));
    }

    private function hasMixedParent(DOMElement $element): bool
    {
        $parent = $element->parentNode;
        if (!$parent instanceof DOMElement || in_array(strtolower($parent->tagName), array('body', 'html'), true)) return false;
        foreach ($parent->childNodes as $sibling) {
            if ($sibling instanceof DOMElement && !$sibling->isSameNode($element) && !in_array(strtolower($sibling->tagName), array('a', 'script', 'style'), true)) return true;
            if ($sibling instanceof \DOMText && '' !== trim($sibling->textContent)) return true;
        }
        return false;
    }

    private static function hasOtherSemanticOwner(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array('nav', 'form', 'label', 'button', 'fieldset'), true)
            || in_array(SourceDom::role($element), array('navigation', 'form', 'button', 'menu', 'menubar', 'listbox'), true)
            || MenuVocabulary::containsUnconditionalMenuToken($element->getAttribute('class') . ' ' . $element->getAttribute('id'));
    }

    /**
     * Two or more icon-only links to known services, and nothing else, are their
     * own evidence of a social row even with no class naming it. Their icons are
     * the row's only media, so the broad-container media guard has nothing to
     * protect. Text links to a social host stay ordinary links or calls to action.
     */
    private static function isOnlyServiceLinks(DOMElement $element): bool
    {
        if (self::hasOtherSemanticOwner($element)) return false;
        $links = 0;
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMText && '' !== trim(str_replace("\u{00a0}", ' ', $child->textContent))) return false;
            if (!$child instanceof DOMElement) continue;
            if ('a' !== strtolower($child->tagName) || null === self::serviceForUrl(LinkUrlSanitizer::sanitize($child->getAttribute('href')))) return false;
            if (1 !== $child->getElementsByTagName('svg')->length || '' !== trim(str_replace("\u{00a0}", ' ', $child->textContent))) return false;
            $links++;
        }
        return 2 <= $links;
    }

    /** Social intent declared by the element or an enclosing region it is not owned away from. */
    private static function hasSocialIntent(DOMElement $element): bool
    {
        for ($ancestor = $element; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            if (self::hasOtherSemanticOwner($ancestor)) return false;
            if (self::isExplicitSocialCluster($ancestor)) return true;
        }
        return false;
    }

    /** Only neutral, single-child wrapper chains may join a social row. */
    private function isSingleLinkWrapper(DOMElement $wrapper, DOMElement $anchor): bool
    {
        if ($wrapper->isSameNode($anchor)) return true;
        if (self::hasOtherSemanticOwner($wrapper)) return false;
        $children = $this->directChildElements($wrapper);
        return 1 === count($children)
            && $this->hasOnlyChild($wrapper, $children[0])
            && (in_array(strtolower($children[0]->tagName), array('div', 'span'), true) || $children[0]->isSameNode($anchor))
            && $this->isSingleLinkWrapper($children[0], $anchor);
    }

    /** Private-use font characters are artwork, not visible link labels. */
    private function isFontGlyph(DOMElement $element): bool
    {
        return 1 === preg_match('/^[\s\x{E000}-\x{F8FF}]+$/u', $element->textContent)
            && 1 === preg_match('/icon|glyph/i', $element->getAttribute('class'));
    }

    private function hasOnlyChild(DOMElement $element, DOMElement $child): bool
    {
        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement && !$node->isSameNode($child)) return false;
            if ($node instanceof \DOMText && '' !== trim(str_replace("\u{00a0}", ' ', $node->textContent))) return false;
        }
        return $child->parentNode?->isSameNode($element) ?? false;
    }

    /** @param array<int,DOMElement> $anchors */
    private function isSocialCluster(DOMElement $element, array $anchors): bool
    {
        if ( self::isExplicitSocialCluster($element) ) {
            return true;
        }

        if ( count($anchors) < 2 ) {
            return false;
        }
        foreach ( $anchors as $anchor ) {
            if ( null === self::serviceForUrl(LinkUrlSanitizer::sanitize(SourceDom::attr($anchor, 'href'))) ) {
                return false;
            }
        }
        return true;
    }

    public static function isExplicitSocialCluster(DOMElement $element): bool
    {
        $identity = strtolower($element->getAttribute('class') . ' ' . $element->getAttribute('aria-label') . ' ' . $element->getAttribute('role'));
        return 1 === preg_match('/(?:^|[^a-z])socials?(?:[^a-z]|$)/', $identity);
    }

    /**
     * Race semantic row recognition before CSS-owned layout lowering only for
     * declared social intent; links that merely point at a social host (for
     * example a CTA row linking to a repository) keep their layout lowering.
     */
    public static function hasSocialRowCandidate(DOMElement $element): bool
    {
        if (self::isExplicitSocialCluster($element)) return true;
        if (self::isOnlyServiceLinks($element)) return true;
        if (!self::hasSocialIntent($element)) return false;
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), array('a', 'li'), true)) return true;
        }
        return false;
    }

    /** @return array<int,DOMElement> */
    private function anchors(DOMElement $element): array
    {
        $anchors = array();
        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( ! $anchor instanceof DOMElement || ! $anchor->hasAttribute('href') ) {
                continue;
            }
            $ancestor = $anchor->parentNode;
            $nested = false;
            while ( $ancestor instanceof DOMElement && ! $ancestor->isSameNode($element) ) {
                if ( 'a' === strtolower($ancestor->tagName) ) {
                    $nested = true;
                    break;
                }
                $ancestor = $ancestor->parentNode;
            }
            if ( ! $nested ) {
                $anchors[] = $anchor;
            }
        }
        return $anchors;
    }

    private function isUsableSocialUrl(string $url): bool
    {
        if ( str_starts_with(strtolower($url), 'mailto:') ) {
            return true;
        }

        $resolved = str_contains($url, '://') ? $url : (str_starts_with($url, '//') ? 'https:' . $url : 'https://' . $url);
        return '' !== strtolower((string) parse_url($resolved, PHP_URL_HOST));
    }

    /** The shared social destination inventory, including icon-only menu links. */
    public static function serviceForUrl(string $url): ?string
    {
        if ( str_starts_with(strtolower($url), 'mailto:') ) {
            return 'mail';
        }

        $host = strtolower((string) parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST));
        foreach ( self::HOST_SERVICES as $domain => $service ) {
            if ( $host === $domain || str_ends_with($host, '.' . $domain) ) {
                return $service;
            }
        }
        return null;
    }

    private function serviceFromLabel(string $label): ?string
    {
        $normalized = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $label)));
        $normalized = preg_replace('/^(?:follow us on|follow|visit)\s+/', '', $normalized) ?? $normalized;
        if ( isset(self::LABEL_SERVICES[$normalized]) ) {
            return self::LABEL_SERVICES[$normalized];
        }

        $aliases = array_keys(self::LABEL_SERVICES);
        usort($aliases, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ( $aliases as $alias ) {
            if ( 1 === preg_match('/(?:^|\s)' . preg_quote($alias, '/') . '(?:\s|$)/', $normalized) ) {
                return self::LABEL_SERVICES[$alias];
            }
        }
        return null;
    }

    private function isLocalPlaceholderUrl(string $url): bool
    {
        return str_starts_with(ltrim(trim($url), '/'), '#');
    }

    private function structuralItem(DOMElement $anchor, DOMElement $cluster): DOMElement
    {
        for ($parent = $anchor->parentNode; $parent instanceof DOMElement && !$parent->isSameNode($cluster); $parent = $parent->parentNode) {
            if ($parent->parentNode?->isSameNode($cluster) && $this->isSingleLinkWrapper($parent, $anchor)) return $parent;
        }
        return $anchor;
    }

    private function hasIcon(DOMElement $anchor): bool
    {
        if ( 0 < $anchor->getElementsByTagName('img')->length || 0 < $anchor->getElementsByTagName('svg')->length ) {
            return true;
        }

        foreach ($anchor->getElementsByTagName('*') as $glyph) {
            if ($this->isFontGlyph($glyph)) return true;
        }
        return '' === trim((string) $anchor->textContent) && 0 < $anchor->getElementsByTagName('span')->length;
    }

    /** @param array<int,DOMElement> $anchors */
    private function iconSize(array $anchors, DOMElement $cluster, PatternContext $context): ?string
    {
        $dimensions = array();
        foreach ( $anchors as $anchor ) {
            // Font glyphs retain their exact authored size through the source
            // glyph projection; choose the native fallback from that size too.
            foreach ($anchor->getElementsByTagName('*') as $glyph) {
                if (!$this->isFontGlyph($glyph)) continue;
                $size = $context->socialPresentationDeclarations($glyph)['font-size'] ?? '';
                if (preg_match('/^(\d+(?:\.\d+)?)px$/', $size, $match)) {
                    $dimensions[] = (float) $match[1];
                    continue 2;
                }
            }
            // The clickable item is the rendered social icon, not necessarily
            // the smaller glyph inside it (or an SVG's intrinsic viewport).
            foreach ( array($this->structuralItem($anchor, $cluster), $anchor) as $item ) {
                $size = $this->cssBoxSize($item, $context);
                if ( null !== $size ) {
                    $dimensions[] = $size;
                    continue 2;
                }
            }
            foreach ( array( 'img', 'svg' ) as $tagName ) {
                $icon = $anchor->getElementsByTagName($tagName)->item(0);
                if ( ! $icon instanceof DOMElement ) {
                    continue;
                }
                $size = $this->cssBoxSize($icon, $context);
                if ( null !== $size ) {
                    $dimensions[] = $size;
                    break;
                }
                $width = HtmlDimensionAttribute::pixels(SourceDom::attr($icon, 'width')) ?? 0.0;
                $height = HtmlDimensionAttribute::pixels(SourceDom::attr($icon, 'height')) ?? 0.0;
                if ( 0 < $width && 0 < $height ) {
                    $dimensions[] = min($width, $height);
                }
                break;
            }
        }
        if ( array() === $dimensions ) {
            return null;
        }

        sort($dimensions, SORT_NUMERIC);
        $sourceSize = $dimensions[(int) floor((count($dimensions) - 1) / 2)];
        $presets = array( 'small' => 16.0, 'normal' => 24.0, 'large' => 36.0, 'huge' => 48.0 );
        $closest = 'normal';
        foreach ( $presets as $preset => $pixels ) {
            if ( abs($sourceSize - $pixels) < abs($sourceSize - $presets[ $closest ]) ) {
                $closest = $preset;
            }
        }
        return $closest;
    }

    private function cssBoxSize(DOMElement $element, PatternContext $context): ?float
    {
        $style = $context->socialPresentationDeclarations($element);
        $dimensions = array();
        foreach ( array('width', 'height') as $property ) {
            $value = trim((string) preg_replace('/\s*!important\s*$/i', '', $style[$property] ?? ''));
            if ( 1 !== preg_match('/^(\d+(?:\.\d+)?)px$/i', $value, $match) || (float) $match[1] <= 0 ) {
                return null;
            }
            $dimensions[] = (float) $match[1];
        }
        return min($dimensions);
    }

    /** @param array<int,DOMElement> $anchors
     * @return array<string, string>
     */
    private function uniformAnchorColors(array $anchors, PatternContext $context): array
    {
        $shared = null;
        foreach ( $anchors as $anchor ) {
            $paint = $context->socialAnchorColors($anchor);
            $pair = array();
            foreach ( array('text' => 'iconColor', 'background' => 'iconBackgroundColor') as $source => $target ) {
                $preset = (string) ($paint['text' === $source ? 'textColor' : 'backgroundColor'] ?? '');
                $value = '' !== $preset ? 'var(--wp--preset--color--' . $preset . ')' : $this->concreteColor((string) ($paint['style']['color'][$source] ?? ''));
                if ( '' === $value ) return array();
                $pair[$target . 'Value'] = $value;
                $pair['' !== $preset ? $target : 'custom' . ucfirst($target)] = '' !== $preset ? $preset : $value;
            }
            if ( null !== $shared && $shared !== $pair ) return array();
            $shared = $pair;
        }
        return $shared ?? array();
    }

    /** @param array<int,DOMElement> $anchors */
    private function uniformIconColor(array $anchors, PatternContext $context): string
    {
        $colors = array();
        foreach ( $anchors as $anchor ) {
            $color = $this->iconColor($anchor, $context);
            if ( '' === $color ) {
                return '';
            }
            $colors[$color] = true;
        }

        return 1 === count($colors) ? (string) array_key_first($colors) : '';
    }

    private function iconColor(DOMElement $anchor, PatternContext $context): string
    {
        $captured = $this->capturedIconColor($anchor, $context);
        if ( '' !== $captured ) {
            return $captured;
        }

        $image = $anchor->getElementsByTagName('img')->item(0);
        if ( $image instanceof DOMElement ) {
            $glyph = $this->glyphColor($image, $context);
            if ( '' !== $glyph ) {
                return $glyph;
            }
            $paint = $this->ownPaintColor($image) ?: $this->ownPaintColor($anchor);
            if ( '' !== $paint ) {
                return $paint;
            }

            return $this->sameDestinationGlyphColor($anchor, $image, $context);
        }

        $svg = $anchor->getElementsByTagName('svg')->item(0);
        if ( $svg instanceof DOMElement ) {
            $fill = $this->concreteColor(SourceDom::attr($svg, 'fill'));
            if ( '' !== $fill ) {
                return $fill;
            }
            $styled = $this->ownPaintColor($svg);
            if ( '' !== $styled ) {
                return $styled;
            }
        }

        foreach ($anchor->getElementsByTagName('*') as $glyph) {
            if (!$this->isFontGlyph($glyph)) continue;
            $color = $this->concreteColor($context->authoredIconColor($glyph));
            if ('' !== $color) return $color;
        }

        $own = $this->ownPaintColor($anchor);
        if ( '' !== $own ) {
            return $own;
        }

        return $this->concreteColor($context->authoredIconColor($anchor));
    }

    /** Rendered colour capture recorded on the anchor's glyph: an img, an inline svg, or an empty background-image element. */
    private function capturedIconColor(DOMElement $anchor, PatternContext $context): string
    {
        foreach ( $anchor->getElementsByTagName('*') as $glyph ) {
            $color = $context->capturedGlyphColor($glyph);
            if ( '' !== $color ) {
                return $color;
            }
        }

        return '';
    }

    /** A captured rendered colour wins; the image bytes are decoded only without one. */
    private function glyphColor(DOMElement $image, PatternContext $context): string
    {
        $captured = $context->capturedGlyphColor($image);
        if ( '' !== $captured ) {
            return $captured;
        }

        $url = trim(SourceDom::attr($image, 'src'));
        if ( '' === $url ) {
            return '';
        }
        if ( str_starts_with(strtolower($url), 'data:image/') ) {
            $comma = strpos($url, ',');
            if ( false === $comma ) {
                return '';
            }
            $meta = substr($url, 0, $comma);
            $payload = substr($url, $comma + 1);
            $bytes = str_contains($meta, ';base64') ? base64_decode($payload, true) : rawurldecode($payload);
            if ( ! is_string($bytes) ) {
                return '';
            }
            $glyph = MonochromeGlyphColor::inspect($bytes);
            if ( $glyph['undecodable'] ) {
                $mimeType = strtolower(trim(explode(';', substr($meta, 5))[0]));
                $context->glyphColorUndecodable($mimeType, 'data:' . $mimeType);
            }

            return $glyph['color'];
        }

        $asset = $context->assetMetadata($url);
        if ( ! empty($asset['glyph_color_undecodable']) ) {
            $context->glyphColorUndecodable((string) ($asset['mime_type'] ?? ''), (string) ($asset['path'] ?? $url));
        }

        return $this->concreteColor((string) ($asset['glyph_color'] ?? ''));
    }

    private function artworkIdentity(DOMElement $image, PatternContext $context): string
    {
        $src = trim(SourceDom::attr($image, 'src'));
        if ( '' === $src || str_starts_with(strtolower($src), 'data:') ) {
            return '';
        }

        return (string) ($context->assetMetadata($src)['path'] ?? $src);
    }

    private function sameDestinationGlyphColor(DOMElement $anchor, DOMElement $image, PatternContext $context): string
    {
        $href = LinkUrlSanitizer::sanitize(SourceDom::attr($anchor, 'href'));
        if ( '' === $href ) {
            return '';
        }
        // Inline data images are lazy-load stubs with no artwork identity of their own;
        // real artwork may only borrow evidence from the same asset.
        $asset = $this->artworkIdentity($image, $context);
        $key = $href . "\n" . $asset;
        if ( array_key_exists($key, $this->destinationGlyphColor) ) {
            return $this->destinationGlyphColor[$key];
        }

        $color = '';
        $document = $anchor->ownerDocument;
        if ( null !== $document ) {
            foreach ( $document->getElementsByTagName('a') as $other ) {
                if ( ! $other instanceof DOMElement || $other->isSameNode($anchor) ) {
                    continue;
                }
                if ( LinkUrlSanitizer::sanitize(SourceDom::attr($other, 'href')) !== $href ) {
                    continue;
                }
                $otherImage = $other->getElementsByTagName('img')->item(0);
                if ( ! $otherImage instanceof DOMElement ) {
                    continue;
                }
                // A shared destination says nothing about different artwork.
                if ( '' !== $asset && $this->artworkIdentity($otherImage, $context) !== $asset ) {
                    continue;
                }
                $color = $this->glyphColor($otherImage, $context);
                if ( '' !== $color ) {
                    break;
                }
            }
        }
        $this->destinationGlyphColor[$key] = $color;

        return $color;
    }

    private function ownPaintColor(DOMElement $element): string
    {
        $style = SourceDom::attr($element, 'style');
        if ( '' === $style ) {
            return '';
        }
        foreach ( array( 'fill', 'color' ) as $property ) {
            if ( 1 === preg_match('/(?:^|;)\s*' . $property . '\s*:\s*([^;]+)/i', $style, $match) ) {
                $color = $this->concreteColor($match[1]);
                if ( '' !== $color ) {
                    return $color;
                }
            }
        }

        return '';
    }

    private function concreteColor(string $value): string
    {
        $value = strtolower(trim(preg_replace('/\s*!important\s*$/i', '', trim($value)) ?? ''));
        if ( '' === $value || in_array($value, array( 'currentcolor', 'transparent', 'none', 'inherit', 'initial', 'unset', 'revert', 'revert-layer', 'auto' ), true) ) {
            return '';
        }
        if ( 'white' === $value ) {
            return '#ffffff';
        }
        if ( 'black' === $value ) {
            return '#000000';
        }
        if ( 1 === preg_match('/^#([0-9a-f]{3})$/', $value, $short) ) {
            return '#' . $short[1][0] . $short[1][0] . $short[1][1] . $short[1][1] . $short[1][2] . $short[1][2];
        }
        if ( 1 === preg_match('/^#([0-9a-f]{6})$/', $value, $hex) ) {
            return '#' . $hex[1];
        }
        if ( 1 === preg_match('/^rgba?\(\s*(\d{1,3})(?:\s*,\s*|\s+)(\d{1,3})(?:\s*,\s*|\s+)(\d{1,3})(?:\s*[,\/]\s*(\d*\.?\d+)(%?))?/', $value, $rgb) ) {
            $channels = array( (int) $rgb[1], (int) $rgb[2], (int) $rgb[3] );
            foreach ( $channels as $channel ) {
                if ( $channel < 0 || $channel > 255 ) {
                    return '';
                }
            }

            $hex = sprintf('#%02x%02x%02x', $channels[0], $channels[1], $channels[2]);
            // Keep authored alpha as #rrggbbaa: core renders the icon color
            // through safecss_filter_attr(), which rejects rgba() but keeps hex.
            if ( isset($rgb[4]) && '' !== $rgb[4] ) {
                $alpha = max(0.0, min(1.0, (float) $rgb[4] / ('%' === ($rgb[5] ?? '') ? 100 : 1)));
                if ( $alpha < 1.0 ) $hex .= sprintf('%02x', (int) round($alpha * 255));
            }
            return $hex;
        }

        return '';
    }
}
