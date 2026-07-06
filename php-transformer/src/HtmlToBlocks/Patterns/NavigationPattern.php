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

        if ( 'nav' !== strtolower($element->tagName) && ! $this->hasNavigationSignal($element) && ! $this->hasDirectListNavigationSignal($element) ) {
            return null;
        }

        if ( $this->hasNavigationChrome($element) ) {
            return null;
        }

        if ( $this->hasDirectBrandingAnchorBesideListNavigation($element, $innerHtml) ) {
            return null;
        }

        $links = $this->navigationBlocks($element, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget);

        if ( array() === $links ) {
            return null;
        }

        // Declare responsive-overlay intent explicitly so the saved block carries
        // its interactive behavior in the content itself rather than relying on
        // WordPress applying the block.json `overlayMenu` default at render time.
        // `mobile` matches the core default: WP renders the responsive overlay
        // container and enqueues the `navigation/view` Interactivity module so the
        // hamburger menu functions on the rendered site (#native-interactivity).
        return $createBlock('core/navigation', array_merge($this->navigationContainerAttributes($element, $presentationAttributes), array(
            'overlayMenu' => 'mobile',
        )), $links, $element);
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
    private function navigationBlocks(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?callable $isRuntimeDomTarget = null, bool $allowsDescriptiveChrome = false): array
    {
        $blocks = array();
        $allowsDescriptiveChrome = $allowsDescriptiveChrome || $this->hasSubmenuSignal($element);
        $allowsDirectItems = $allowsDescriptiveChrome || 'nav' === strtolower($element->tagName) || $this->hasNavigationSignal($element) || $this->hasSubmenuSignal($element) || in_array(strtolower($element->tagName), array( 'ul', 'ol' ), true);
        if ( in_array(strtolower($element->tagName), array( 'ul', 'ol' ), true) ) {
            return $this->navigationBlocksFromList($element, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget);
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
                $blocks[] = $this->navigationLinkBlock($child, $presentationAttributes, $innerHtml, $createBlock, $child);
                continue;
            }

            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                $listBlocks = $this->navigationBlocksFromList($child, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget);
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

                $block = $this->navigationBlockFromItem($child, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget);
                if ( null !== $block ) {
                    $blocks[] = $block;
                    continue;
                }

                if ( $this->isNavigationWrapperElement($child) ) {
                    $wrappedBlocks = $this->navigationBlocks($child, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, $allowsDescriptiveChrome);
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
    private function navigationBlocksFromList(DOMElement $list, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?callable $isRuntimeDomTarget = null): array
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

            $block = $this->navigationBlockFromItem($item, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget);
            if ( null === $block ) {
                return array();
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    private function navigationBlockFromItem(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?callable $isRuntimeDomTarget = null): ?array
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
            return $createBlock('core/navigation-submenu', $this->navigationItemAttributes($element, $anchor, $submenuContainer, $submenuAttrs, $presentationAttributes), $submenuBlocks, $element);
        }

        if ( 1 !== count($this->anchorsExcludingSubmenus($element, $anchor)) ) {
            return null;
        }

        return $this->navigationLinkBlock($anchor, $presentationAttributes, $innerHtml, $createBlock, $element);
    }

    private function navigationLinkBlock(DOMElement $anchor, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?DOMElement $item = null): array
    {
        return $createBlock('core/navigation-link', $this->navigationItemAttributes($item ?? $anchor, $anchor, null, array(
            'label' => $this->anchorLabel($anchor, $innerHtml),
            'url'   => $this->safeNavigationUrl($anchor->hasAttribute('href') ? $anchor->getAttribute('href') : ''),
            'kind'  => 'custom',
        ), $presentationAttributes), array(), $anchor);
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
    private function navigationItemAttributes(DOMElement $item, DOMElement $anchor, ?DOMElement $submenuContainer, array $baseAttrs, callable $presentationAttributes): array
    {
        $itemAttrs = $item->isSameNode($anchor) ? array() : $this->withoutCoreNavigationClasses($presentationAttributes($item));
        $anchorAttrs = $this->withoutCoreNavigationClasses($presentationAttributes($anchor));
        $submenuAttrs = $submenuContainer instanceof DOMElement ? $this->withoutCoreNavigationClasses($presentationAttributes($submenuContainer)) : array();
        if ( '' === (string) ($itemAttrs['className'] ?? '') && '' !== (string) ($anchorAttrs['className'] ?? '') ) {
            $itemAttrs['className'] = $anchorAttrs['className'];
        }

        // The anchor/submenu CSS rides on the preserved classNames + companion CSS;
        // a raw inline `style` string on the navigation-link/submenu inner markup
        // would diverge from the block save() output, so it is not emitted (#261).
        return array_filter(array_merge($itemAttrs, $baseAttrs, array(
            'anchorClassName'  => $anchorAttrs['className'] ?? '',
            'submenuClassName' => $submenuAttrs['className'] ?? '',
        )), static fn ($value): bool => '' !== $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function navigationContainerAttributes(DOMElement $element, callable $presentationAttributes): array
    {
        return $this->withoutCoreNavigationClasses($presentationAttributes($element));
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
