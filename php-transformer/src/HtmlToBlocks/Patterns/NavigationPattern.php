<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class NavigationPattern implements PatternRecognizerInterface
{
    use PatternDomHelpersTrait;

    private const BLOCK_LEVEL_LABEL_TAGS = 'address|article|aside|blockquote|div|dl|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|main|nav|ol|p|pre|section|table|ul';

    /**
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, PatternContext $context): ?array
    {
        $presentationAttributes = $context->presentationAttributesCallback();
        $innerHtml = $context->innerHtmlCallback();
        $createBlock = $context->createBlockCallback();
        $isRuntimeDomTarget = $context->isRuntimeDomTargetCallback();
        $navigationUnderlineColor = $context->navigationUnderlineColorCallback();
        $resolvedStyle = $context->resolvedStyleCallback();

        if ( 'nav' !== strtolower($element->tagName) && ! $this->hasNavigationSignal($element) && ! $this->hasDirectListNavigationSignal($element) ) {
            return null;
        }

        if ( $this->hasNavigationChrome($element) ) {
            return null;
        }

        // A row of button-styled links (e.g. `<div class="stream-links"><a
        // class="stream-btn">…</a>…</div>`) is a call-to-action button group, not
        // site navigation. It matched here only because a container token like
        // `links` looks navigational, but its anchors carry button signals and
        // belong to the buttons pattern, which preserves their pill geometry and
        // styling. Defer so navigation does not flatten them into menu items.
        if ( 'nav' !== strtolower($element->tagName) && ! $this->hasDirectListNavigationSignal($element) && $this->hasButtonStyledLinkChildren($element, $resolvedStyle) ) {
            return null;
        }

        if ( $this->hasDirectBrandingAnchorBesideListNavigation($element, $innerHtml) ) {
            return null;
        }

        $links = $this->navigationBlocks($element, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, false, $navigationUnderlineColor);

        if ( array() === $links ) {
            return null;
        }

        $label = $this->directSectionLabel($element);
        $navigationAttrs = $label instanceof DOMElement
            ? $this->nestedLabeledNavigationAttributes($element, $presentationAttributes)
            : $this->navigationContainerAttributes($element, $presentationAttributes);
        $navigationAttrs['overlayMenu'] = 'mobile';
        if ( $label instanceof DOMElement ) {
            $navigationAttrs['layout'] = array( 'type' => 'flex', 'orientation' => 'vertical' );
        }

        // Declare responsive-overlay intent explicitly so the saved block carries
        // its interactive behavior in the content itself rather than relying on
        // WordPress applying the block.json `overlayMenu` default at render time.
        // `mobile` matches the core default: WP renders the responsive overlay
        // container and enqueues the `navigation/view` Interactivity module so the
        // hamburger menu functions on the rendered site (#native-interactivity).
        $commonTextAttrs = $this->commonNavigationLinkTextAttributes($links);
        if ( $this->isListNavigationSource($element) ) {
            unset($commonTextAttrs['style']['typography']);
        }
        $navigationAttrs = array_replace_recursive(
			$navigationAttrs,
            $commonTextAttrs
        );

        $navigation = $createBlock('core/navigation', $navigationAttrs, $links, $element);

        if ( ! $label instanceof DOMElement ) {
            return $navigation;
        }

        $labelTag = strtolower($label->tagName);
        $labelBlock = preg_match('/^h([1-6])$/', $labelTag, $matches)
            ? $createBlock('core/heading', array_merge($presentationAttributes($label), array(
                'content' => $innerHtml($label),
                'level' => (int) $matches[1],
            )), array(), $label)
            : $createBlock('core/paragraph', array_merge($presentationAttributes($label), array(
                'content' => $innerHtml($label),
            )), array(), $label);

        return $createBlock('core/group', array_merge($presentationAttributes($element), array( 'tagName' => 'div' )), array( $labelBlock, $navigation ), $element);
    }

    private function directSectionLabel(DOMElement $element): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->isSectionLabelElement($child) ) {
                return $child;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function nestedLabeledNavigationAttributes(DOMElement $element, callable $presentationAttributes): array
    {
        $attrs = $this->navigationContainerAttributes($element, $presentationAttributes);
        $blockGap = (string) ($attrs['style']['spacing']['blockGap'] ?? '');
        return '' === $blockGap ? array() : array(
            'style' => array( 'spacing' => array( 'blockGap' => $blockGap ) ),
        );
    }

    /**
     * Core navigation links render text styles from their parent block context.
     * Promote only values shared by every link so mixed menus retain their own
     * companion CSS rather than receiving an incorrect uniform native style.
     *
     * @param array<int, array<string, mixed>> $links
     * @return array<string, mixed>
     */
    private function commonNavigationLinkTextAttributes(array $links): array
    {
        $first = $links[0]['attrs'] ?? array();
        if ( ! is_array($first) ) {
            return array();
        }

        $attrs = array();
        foreach ( array( 'textColor' ) as $name ) {
            $value = $first[ $name ] ?? null;
            if ( null !== $value && $this->allNavigationLinksShare($links, static fn (array $linkAttrs): mixed => $linkAttrs[ $name ] ?? null, $value) ) {
                $attrs[ $name ] = $value;
            }
        }

        $customTextColor = $first['style']['color']['text'] ?? null;
        if ( is_string($customTextColor) && '' !== trim($customTextColor) && $this->allNavigationLinksShare($links, static fn (array $linkAttrs): mixed => $linkAttrs['style']['color']['text'] ?? null, $customTextColor) ) {
            $attrs['customTextColor'] = trim($customTextColor);
        }

        $typography = is_array($first['style']['typography'] ?? null) ? $first['style']['typography'] : array();
        foreach ( $typography as $name => $value ) {
            if ( $this->allNavigationLinksShare($links, static fn (array $linkAttrs): mixed => $linkAttrs['style']['typography'][ $name ] ?? null, $value) ) {
                $attrs['style']['typography'][ $name ] = $value;
            }
        }

        return $attrs;
    }

    /** @param array<int, array<string, mixed>> $links */
    private function allNavigationLinksShare(array $links, callable $value, mixed $expected): bool
    {
        foreach ( $links as $link ) {
            $linkAttrs = is_array($link['attrs'] ?? null) ? $link['attrs'] : array();
            if ( $value($linkAttrs) !== $expected ) {
                return false;
            }
        }

        return true;
    }

    private function safeNavigationUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    private function hasDirectBrandingAnchorBesideListNavigation(DOMElement $element, callable $innerHtml): bool
    {
        if ( 'nav' !== strtolower($element->tagName) && ! $this->hasNavigationSignal($element) ) {
            return false;
        }

        $hasDirectAnchor = false;
        $hasListNavigation = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'a' === $tagName && $this->hasBrandAnchorSignal($child) && '' !== $this->anchorLabel($child, $innerHtml) && ! preg_match('/<(?:' . self::BLOCK_LEVEL_LABEL_TAGS . ')\b/i', $innerHtml($child)) ) {
                $hasDirectAnchor = true;
                continue;
            }

            if ( in_array($tagName, array( 'ul', 'ol' ), true) && array() !== $this->navigationBlocksFromList($child, static fn (): array => array(), $innerHtml, static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
                'blockName'   => $name,
                'attrs'       => $attrs,
                'innerBlocks' => $innerBlocks,
            )) ) {
                $hasListNavigation = true;
            }
        }

        return $hasDirectAnchor && $hasListNavigation;
    }

    private function hasBrandAnchorSignal(DOMElement $anchor): bool
    {
        $haystack = strtolower(trim($this->attr($anchor, 'class') . ' ' . $this->attr($anchor, 'id') . ' ' . $this->attr($anchor, 'aria-label') . ' ' . $this->attr($anchor, 'title')));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:brand|branding|logo|site-title|site-name|home-link|home-logo)(?:[^a-z0-9]|$)/', $haystack);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function navigationBlocks(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?callable $isRuntimeDomTarget = null, bool $allowsDescriptiveChrome = false, ?callable $navigationUnderlineColor = null): array
    {
        $blocks = array();
        $allowsDescriptiveChrome = $allowsDescriptiveChrome || $this->hasSubmenuSignal($element);
        $allowsDirectItems = $allowsDescriptiveChrome || 'nav' === strtolower($element->tagName) || $this->hasNavigationSignal($element) || $this->hasSubmenuSignal($element) || in_array(strtolower($element->tagName), array( 'ul', 'ol' ), true);
        if ( in_array(strtolower($element->tagName), array( 'ul', 'ol' ), true) ) {
            return $this->navigationBlocksFromList($element, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, $navigationUnderlineColor);
        }

        foreach ( $element->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( $child instanceof DOMElement && $this->isSectionLabelElement($child) ) {
                continue;
            }

            if ( $child instanceof DOMElement && $this->isNavigationChromeElement($child) ) {
                if ( null !== $isRuntimeDomTarget && $isRuntimeDomTarget($child) ) {
                    return array();
                }
                continue;
            }

            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== $this->anchorLabel($child, $innerHtml) ) {
                if ( ! $allowsDirectItems ) {
                    return array();
                }
                $blocks[] = $this->navigationLinkBlock($child, $presentationAttributes, $innerHtml, $createBlock, $child, $navigationUnderlineColor);
                continue;
            }

            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                $listBlocks = $this->navigationBlocksFromList($child, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, $navigationUnderlineColor);
                if ( array() === $listBlocks ) {
                    return array();
                }
                $blocks = array_merge($blocks, $listBlocks);
                continue;
            }

            if ( $child instanceof DOMElement ) {
                if ( ! $allowsDirectItems ) {
                    return array();
                }

                $block = $this->navigationBlockFromItem($child, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, $navigationUnderlineColor);
                if ( null !== $block ) {
                    $blocks[] = $block;
                    continue;
                }

                if ( $this->isNavigationWrapperElement($child) ) {
                    $wrappedBlocks = $this->navigationBlocks($child, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, $allowsDescriptiveChrome, $navigationUnderlineColor);
                    if ( array() !== $wrappedBlocks ) {
                        $blocks = array_merge($blocks, $wrappedBlocks);
                        continue;
                    }
                }

                if ( $allowsDescriptiveChrome && ! $this->containsNavigationAnchor($child, $innerHtml) ) {
                    continue;
                }
            }

            return array();
        }

        return $blocks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function navigationBlocksFromList(DOMElement $list, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?callable $isRuntimeDomTarget = null, ?callable $navigationUnderlineColor = null): array
    {
        $blocks = array();
        foreach ( $list->childNodes as $item ) {
            if ( XML_COMMENT_NODE === $item->nodeType ) {
                continue;
            }

            if ( XML_TEXT_NODE === $item->nodeType && '' === trim($item->textContent ?? '') ) {
                continue;
            }

            if ( $item instanceof DOMElement && $this->isNavigationChromeElement($item) ) {
                continue;
            }

            if ( ! $item instanceof DOMElement || 'li' !== strtolower($item->tagName) ) {
                return array();
            }

            $block = $this->navigationBlockFromItem($item, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, $navigationUnderlineColor);
            if ( null === $block ) {
                return array();
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    private function navigationBlockFromItem(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?callable $isRuntimeDomTarget = null, ?callable $navigationUnderlineColor = null): ?array
    {
        $anchor = $this->primaryNavigationAnchor($element);
        if ( ! $anchor instanceof DOMElement || '' === $this->anchorLabel($anchor, $innerHtml) ) {
            return null;
        }

        $submenuBlocks = array();
        foreach ( $this->submenuContainers($element, $anchor) as $submenuContainer ) {
            foreach ( $this->navigationBlocks($submenuContainer, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, true) as $submenuBlock ) {
                $submenuBlocks[] = $submenuBlock;
            }
        }

        if ( array() !== $submenuBlocks ) {
            if ( 1 !== count($this->anchorsExcludingSubmenus($element, $anchor)) ) {
                return null;
            }

            $submenuAttrs = array(
                'label' => $this->anchorLabel($anchor, $innerHtml),
                'url'   => $this->safeNavigationUrl($anchor->hasAttribute('href') ? $anchor->getAttribute('href') : ''),
                'kind'  => 'custom',
            );
            $submenuContainer = $this->submenuContainers($element, $anchor)[0] ?? null;
            return $createBlock('core/navigation-submenu', $this->navigationItemAttributes($element, $anchor, $submenuContainer, $submenuAttrs, $presentationAttributes, $navigationUnderlineColor), $submenuBlocks, $element);
        }

        if ( 1 !== count($this->anchorsExcludingSubmenus($element, $anchor)) ) {
            return null;
        }

        return $this->navigationLinkBlock($anchor, $presentationAttributes, $innerHtml, $createBlock, $element, $navigationUnderlineColor);
    }

    private function navigationLinkBlock(DOMElement $anchor, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?DOMElement $item = null, ?callable $navigationUnderlineColor = null): array
    {
        return $createBlock('core/navigation-link', $this->navigationItemAttributes($item ?? $anchor, $anchor, null, array(
            'label' => $this->anchorLabel($anchor, $innerHtml),
            'url'   => $this->safeNavigationUrl($anchor->hasAttribute('href') ? $anchor->getAttribute('href') : ''),
            'kind'  => 'custom',
        ), $presentationAttributes, $navigationUnderlineColor), array(), $anchor);
    }

    private function anchorLabel(DOMElement $anchor, callable $innerHtml): string
    {
        $label = $this->navigationLabel($innerHtml($anchor));
        if ( '' !== $label ) {
            return $label;
        }

        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $fallback = trim($this->attr($anchor, $attribute));
            if ( '' !== $fallback ) {
                return htmlspecialchars($fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $image = $anchor->getElementsByTagName('img')->item(0);
        if ( $image instanceof DOMElement ) {
            $alt = trim($this->attr($image, 'alt'));
            if ( '' !== $alt ) {
                return htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return '';
    }

    private function navigationLabel(string $html): string
    {
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html;
        $html = preg_replace('/<span\b[^>]*>\s*<\/span>/i', '', $html) ?? $html;
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>\s*<\/\1>/i', '', $html) ?? $html;
        $html = preg_replace('/<\/?(?:' . self::BLOCK_LEVEL_LABEL_TAGS . ')\b[^>]*>/i', '', $html) ?? $html;
        return trim($html);
    }

    /**
     * @param array<string, mixed> $baseAttrs
     * @return array<string, mixed>
     */
    private function navigationItemAttributes(DOMElement $item, DOMElement $anchor, ?DOMElement $submenuContainer, array $baseAttrs, callable $presentationAttributes, ?callable $navigationUnderlineColor = null): array
    {
        $itemAttrs = $item->isSameNode($anchor) ? array() : $this->withoutCoreNavigationClasses($presentationAttributes($item));
        $anchorAttrs = $this->withoutCoreNavigationClasses($presentationAttributes($anchor));
        $submenuAttrs = $submenuContainer instanceof DOMElement ? $this->withoutCoreNavigationClasses($presentationAttributes($submenuContainer)) : array();
        if ( '' === (string) ($itemAttrs['className'] ?? '') && '' !== (string) ($anchorAttrs['className'] ?? '') ) {
            $itemAttrs['className'] = $anchorAttrs['className'];
        }

        $itemAttrs = array_replace_recursive($itemAttrs, $this->navigationAnchorTextAttributes($anchorAttrs, 'a' === strtolower($item?->tagName ?? 'a')));

        if ( $this->hasCurrentNavigationSignal($item) || $this->hasCurrentNavigationSignal($anchor) ) {
            $itemAttrs['className'] = trim((string) ($itemAttrs['className'] ?? '') . ' blocks-engine-current-navigation-item');
            $decorationColor = null !== $navigationUnderlineColor ? trim((string) $navigationUnderlineColor($item, $anchor)) : '';
            $sourceDecoration = strtolower(trim((string) ($anchorAttrs['style']['typography']['textDecoration'] ?? $itemAttrs['style']['typography']['textDecoration'] ?? '')));
            if ( 'underline' === $sourceDecoration || '' !== $decorationColor ) {
                $itemAttrs['className'] .= ' blocks-engine-current-navigation-underline';
                $baseAttrs['style']['typography']['textDecoration'] = 'underline';
            }
            if ( '' === $decorationColor && 'underline' === $sourceDecoration ) {
                $decorationColor = $this->activeNavigationUnderlineColor($anchorAttrs, $itemAttrs);
            }
            if ( '' !== $decorationColor ) {
                $baseAttrs['style']['typography']['textDecorationColor'] = $decorationColor;
            }
        }

        // The anchor/submenu CSS rides on the preserved classNames + companion CSS;
        // a raw inline `style` string on the navigation-link/submenu inner markup
        // would diverge from the block save() output, so it is not emitted (#261).
        return array_filter(array_replace_recursive($itemAttrs, $baseAttrs, array(
            'anchorClassName'  => $anchorAttrs['className'] ?? '',
            'submenuClassName' => $submenuAttrs['className'] ?? '',
        )), static fn ($value): bool => '' !== $value);
    }

    /**
     * Carry inheritable anchor paint and typography through core's dynamic link.
     * Box styles remain owned by the source classes and companion stylesheet.
     *
     * @param array<string, mixed> $anchorAttrs
     * @return array<string, mixed>
     */
    private function navigationAnchorTextAttributes(array $anchorAttrs, bool $includeTypography = true): array
    {
        $attrs = array();
        if ( isset($anchorAttrs['textColor']) ) {
            $attrs['textColor'] = $anchorAttrs['textColor'];
        }

        $style = is_array($anchorAttrs['style'] ?? null) ? $anchorAttrs['style'] : array();
        $typography = is_array($style['typography'] ?? null) ? $style['typography'] : array();
        $textColor = $style['color']['text'] ?? null;
        if ( $includeTypography && array() !== $typography ) {
            $attrs['style']['typography'] = $typography;
        }
        if ( is_string($textColor) && '' !== trim($textColor) ) {
            $attrs['style']['color']['text'] = trim($textColor);
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $anchorAttrs
     * @param array<string, mixed> $itemAttrs
     */
    private function activeNavigationUnderlineColor(array $anchorAttrs, array $itemAttrs): string
    {
        foreach ( array( $anchorAttrs, $itemAttrs ) as $attrs ) {
            $textColor = $attrs['style']['color']['text'] ?? null;
            if ( is_string($textColor) && '' !== trim($textColor) ) {
                return trim($textColor);
            }
        }

        foreach ( array( $anchorAttrs, $itemAttrs ) as $attrs ) {
            $style = $attrs['style'] ?? null;
            if ( ! is_array($style) ) {
                continue;
            }
            $serialized = $this->serializedStyleColor($style);
            if ( '' !== $serialized ) {
                return $serialized;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $style
     */
    private function serializedStyleColor(array $style): string
    {
        $serialized = (string) json_encode($style);
        if ( preg_match('/"(?:textDecorationColor|borderColor|color)"\s*:\s*"((?:\\\\.|[^"\\\\])+)"/', $serialized, $match) ) {
            $decoded = json_decode('"' . $match[1] . '"');
            return is_string($decoded) ? trim($decoded) : '';
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function navigationContainerAttributes(DOMElement $element, callable $presentationAttributes): array
    {
        $attrs = $this->withoutCoreNavigationClasses($presentationAttributes($element));
        if ( in_array(strtolower($element->tagName), array( 'ul', 'ol' ), true) ) {
            $attrs['className'] = $this->navigationListProjectionClasses((string) ($attrs['className'] ?? ''));
        }
        if ( $this->isListNavigationSource($element) ) {
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' blocks-engine-list-navigation');
        }
        if ( '' !== (string) ($attrs['style']['spacing']['blockGap'] ?? '') ) {
            return $attrs;
        }

        $list = $this->navigationSourceList($element);
        if ( $list instanceof DOMElement ) {
            $listAttrs = $this->withoutCoreNavigationClasses($presentationAttributes($list));
            // A source list is replaced by core/navigation's inner list. Its
            // classes must not leak onto the outer navigation flex item.
            $listClasses = $this->navigationListProjectionClasses((string) ($listAttrs['className'] ?? ''));
            if ( '' !== $listClasses ) {
                $attrs['className'] = implode(' ', array_values(array_unique(array_filter(preg_split('/\s+/', trim((string) ($attrs['className'] ?? '') . ' ' . $listClasses)) ?: array()))));
            }

            $listGap = (string) ($listAttrs['style']['spacing']['blockGap'] ?? '');
            if ( '' !== $listGap ) {
                $attrs['style']['spacing']['blockGap'] = $listGap;
            }
        }

        if ( $list instanceof DOMElement && '' === (string) ($attrs['style']['spacing']['blockGap'] ?? '') ) {
            // Core navigation adds its own default gap; source lists do not.
            $attrs['style']['spacing']['blockGap'] = '0px';
        }

        return $attrs;
    }

    private function navigationListProjectionClasses(string $className): string
    {
        $classes = array();
        foreach ( preg_split('/\s+/', trim($className)) ?: array() as $class ) {
            if ( '' === $class ) {
                continue;
            }
            if ( str_starts_with($class, 'blocks-engine-source-ul-') || str_starts_with($class, 'blocks-engine-source-ol-') ) {
                $classes[] = $class;
                continue;
            }
            if ( ! str_starts_with($class, 'blocks-engine-') ) {
                $classes[] = self::navigationListProjectionClass($class);
            }
        }

        return implode(' ', array_values(array_unique($classes)));
    }

    private static function navigationListProjectionClass(string $sourceClass): string
    {
        return 'blocks-engine-navigation-list-' . substr(hash('sha256', $sourceClass), 0, 12);
    }

    private function isListNavigationSource(DOMElement $element): bool
    {
        return $this->navigationSourceList($element) instanceof DOMElement;
    }

    private function navigationSourceList(DOMElement $element): ?DOMElement
    {
        if ( in_array(strtolower($element->tagName), array( 'ul', 'ol' ), true) ) {
            return $element;
        }

        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                return $child;
            }

            if ( $this->isNavigationWrapperElement($child) ) {
                $list = $this->navigationSourceList($child);
                if ( $list instanceof DOMElement ) {
                    return $list;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withoutCoreNavigationClasses(array $attrs): array
    {
        if ( empty($attrs['className']) || ! is_string($attrs['className']) ) {
            return $attrs;
        }

        $classNames = array_values(array_filter(preg_split('/\s+/', trim($attrs['className'])) ?: array(), static function (string $className): bool {
            return ! in_array($className, array(
                'wp-block-navigation',
                'wp-block-navigation-item',
                'wp-block-navigation-link',
                'wp-block-navigation-submenu',
                'wp-block-navigation__container',
                'wp-block-navigation__submenu-container',
            ), true);
        }));

        if ( array() === $classNames ) {
            unset($attrs['className']);
            return $attrs;
        }

        $attrs['className'] = implode(' ', $classNames);
        return $attrs;
    }

    private function hasCurrentNavigationSignal(DOMElement $element): bool
    {
        if ( '' !== trim($this->attr($element, 'aria-current')) ) {
            return true;
        }

        foreach ( preg_split('/[^a-z0-9]+/', strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'id'))) ?: array() as $token ) {
            if ( in_array($token, array( 'active', 'current', 'current-menu-item', 'current-page-item', 'current_page_item', 'is-active', 'selected' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function primaryNavigationAnchor(DOMElement $element): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( 'a' === strtolower($child->tagName) ) {
                return $child;
            }

            if ( in_array(strtolower($child->tagName), array( 'span', 'div', 'p' ), true) ) {
                $anchor = $this->primaryNavigationAnchor($child);
                if ( $anchor instanceof DOMElement ) {
                    return $anchor;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function submenuContainers(DOMElement $element, DOMElement $primaryAnchor): array
    {
        $containers = array();
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || $child->isSameNode($primaryAnchor) ) {
                continue;
            }

            if ( $this->isNavigationChromeElement($child) ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'nav', 'ul', 'ol' ), true) || $this->hasSubmenuSignal($child) ) {
                $containers[] = $child;
            }
        }

        return $containers;
    }

    private function hasSubmenuSignal(DOMElement $element): bool
    {
        if ( 'menu' === strtolower($this->attr($element, 'role')) ) {
            return true;
        }

        foreach ( array( 'class', 'id', 'role' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, array( 'dropdown', 'mega', 'megamenu', 'submenu', 'subnav', 'flyout' ), true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isMenuToggleControl(DOMElement $element): bool
    {
        if ( 'button' !== strtolower($element->tagName) ) {
            return false;
        }

        if ( $element->hasAttribute('aria-controls') || $element->hasAttribute('aria-expanded') ) {
            return true;
        }

        foreach ( preg_split('/[^a-z0-9]+/', strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'aria-label'))) ?: array() as $token ) {
            if ( in_array($token, array( 'hamburger', 'menu', 'toggle' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function isNavigationChromeElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( $this->isMenuToggleControl($element) ) {
            return true;
        }

        if ( in_array(strtolower($this->attr($element, 'role')), array( 'separator', 'presentation', 'none' ), true) ) {
            return true;
        }

        if ( in_array($tagName, array( 'hr', 'svg' ), true) ) {
            return true;
        }

        if ( 'a' === $tagName && '' === trim($element->textContent ?? '') && '' === trim($this->attr($element, 'aria-label') . $this->attr($element, 'title')) ) {
            return true;
        }

        $tokens = strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'id'));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:separator|divider|toggle|hamburger|menu-button|menu-toggle)(?:[^a-z0-9]|$)/', $tokens);
    }

    private function isNavigationWrapperElement(DOMElement $element): bool
    {
        if ( ! in_array(strtolower($element->tagName), array( 'div', 'span', 'section' ), true) ) {
            return false;
        }

        $hasNavigationChild = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( $this->isSectionLabelElement($child) || $this->isDescriptiveNavigationChromeElement($child) ) {
                continue;
            }

            if ( 'a' !== $tagName && 0 === $child->getElementsByTagName('a')->length ) {
                continue;
            }

            if ( in_array($tagName, array( 'a', 'ul', 'ol' ), true) || $this->hasNavigationSignal($child) || $this->isNavigationChromeElement($child) ) {
                $hasNavigationChild = true;
                continue;
            }

            if ( ! $this->isNavigationWrapperElement($child) ) {
                return false;
            }

            $hasNavigationChild = true;
        }

        return $hasNavigationChild;
    }

    private function containsNavigationAnchor(DOMElement $element, callable $innerHtml): bool
    {
        if ( 'a' === strtolower($element->tagName) && '' !== $this->anchorLabel($element, $innerHtml) ) {
            return true;
        }

        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement && '' !== $this->anchorLabel($anchor, $innerHtml) ) {
                return true;
            }
        }

        return false;
    }

    private function isDescriptiveNavigationChromeElement(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'p', 'small' ), true);
    }

    private function isSectionLabelElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( preg_match('/^h[1-6]$/', $tagName) ) {
            return true;
        }

        if ( ! in_array($tagName, array( 'span', 'p', 'strong', 'b' ), true) ) {
            return false;
        }

        $tokens = strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'id'));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:label|heading|title)(?:[^a-z0-9]|$)/', $tokens);
    }

    private function hasNavigationChrome(DOMElement $element): bool
    {
        $hasToggle = false;
        foreach ( $element->getElementsByTagName('button') as $button ) {
            if ( $button instanceof DOMElement && $this->isMenuToggleControl($button) ) {
                $hasToggle = true;
                break;
            }
        }

        if ( ! $hasToggle ) {
            return false;
        }

        $hasList = false;
        foreach ( $element->getElementsByTagName('ul') as $list ) {
            if ( $list instanceof DOMElement && $this->hasNavigationSignal($list) ) {
                $hasList = true;
                break;
            }
        }

        if ( ! $hasList ) {
            return false;
        }

        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement && ! $this->hasListAncestor($anchor, $element) ) {
                return true;
            }
        }

        return false;
    }

    private function hasListAncestor(DOMElement $element, DOMElement $boundary): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement && ! $node->isSameNode($boundary); $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), array( 'ul', 'ol' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function anchorsExcludingSubmenus(DOMElement $element, DOMElement $primaryAnchor): array
    {
        $anchors = array();
        $submenuContainers = $this->submenuContainers($element, $primaryAnchor);
        $this->collectAnchorsExcluding($element, $anchors, $submenuContainers);
        return $anchors;
    }

    /**
     * @param array<int, DOMElement> $anchors
     * @param array<int, DOMElement> $excluded
     */
    private function collectAnchorsExcluding(DOMElement $element, array &$anchors, array $excluded): void
    {
        foreach ( $excluded as $excludedElement ) {
            if ( $element->isSameNode($excludedElement) ) {
                return;
            }
        }

        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( 'a' === strtolower($child->tagName) ) {
                $anchors[] = $child;
                continue;
            }

            if ( in_array(strtolower($child->tagName), array( 'span', 'div', 'p' ), true) || $this->hasSubmenuSignal($child) ) {
                $this->collectAnchorsExcluding($child, $anchors, $excluded);
            }
        }
    }

    /**
     * Whether the container's direct link children are button-styled call-to-
     * action anchors rather than navigation links. Requires every direct anchor
     * to carry a button signal so a genuine nav menu with one incidental
     * button-classed link is not misclassified.
     */
    /** @param callable(DOMElement): string|null $resolvedStyle */
    private function hasButtonStyledLinkChildren(DOMElement $element, ?callable $resolvedStyle): bool
    {
        $classifier = new ButtonSignalClassifier();
        $anchors = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) ) {
                $anchors[] = $child;
            }
        }
        if ( 2 > count($anchors) ) {
            return false;
        }

        foreach ( $anchors as $anchor ) {
            if ( ! $classifier->hasTransformSignal($anchor, null !== $resolvedStyle ? $resolvedStyle($anchor) : '') ) {
                return false;
            }
        }

        return true;
    }

    private function hasNavigationSignal(DOMElement $element): bool
    {
        if ( 'navigation' === strtolower($element->hasAttribute('role') ? $element->getAttribute('role') : '') ) {
            return true;
        }

        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, array( 'nav', 'navbar', 'navigation', 'menu' ), true) ) {
                    return true;
                }
                if ( 'links' === $token && ! $this->isContactLinkCluster($element) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isContactLinkCluster(DOMElement $element): bool
    {
        $anchors = array();
        $this->collectAnchorsExcluding($element, $anchors, array());
        if ( array() === $anchors ) {
            return false;
        }

        foreach ( $anchors as $anchor ) {
            $href = strtolower(trim($anchor->hasAttribute('href') ? $anchor->getAttribute('href') : ''));
            if ( ! preg_match('/^(?:tel|mailto|sms):/', $href) ) {
                return false;
            }
        }

        return true;
    }

    private function hasDirectListNavigationSignal(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) && $this->hasNavigationSignal($child) ) {
                return true;
            }
        }

        return false;
    }
}
