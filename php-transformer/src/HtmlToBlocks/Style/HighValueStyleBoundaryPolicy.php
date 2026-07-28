<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;

final class HighValueStyleBoundaryPolicy
{
    /**
     * Elements whose source CSS is worth resolving from static stylesheet rules.
     */
    public function matches(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'button', 'header', 'footer', 'main', 'nav', 'article', 'aside', 'section', 'svg' ), true) ) {
            return true;
        }

        if ( 'li' === $tagName && $this->hasMultipleStyledInlineChildren($element) ) {
            return true;
        }

        if ( '' === trim($element->textContent ?? '') && in_array($tagName, array( 'span', 'i', 'b' ), true) && $element->parentNode instanceof DOMElement ) {
            $parentTokens = strtolower($this->attr($element->parentNode, 'class') . ' ' . $this->attr($element->parentNode, 'id'));
            if ( preg_match('/(?:^|[^a-z0-9])(?:dots?|icons?|badges?|chips?|pills?|indicators?|markers?|orbs?)(?:[^a-z0-9]|$)/', $parentTokens) ) {
                return true;
            }
        }

        $tokens = strtolower(trim(implode(' ', array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
            $this->attr($element, 'role'),
        ))));

        if ( preg_match('/(?:^|[^a-z0-9])(?:btn|button|cta|action|nav|menu|logo|brand|branding|cards?|tile|panel|pricing|price|product|grid|columns|layout|stack|cluster|row|wrap|hero|masthead|banner|badge|chip|pill|status|indicator|marker|dot|orb|media|image|photo|gallery|cover|thumb|thumbnail|art|artwork|illustration)(?:[^a-z0-9]|$)/', $tokens) ) {
            return true;
        }

        // Anchor surface styles determine whether an anchor is a control or
        // ordinary text, so classifier evidence must not depend on its name.
        if ( 'a' === $tagName ) {
            return true;
        }

        return false;
    }

    private function hasMultipleStyledInlineChildren(DOMElement $element): bool
    {
        $styledInlineChildren = 0;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'br' !== $tagName && ! $this->isInlineContentElement($tagName) ) {
                continue;
            }

            if ( '' !== trim($this->attr($child, 'class')) || '' !== trim($this->attr($child, 'style')) ) {
                ++$styledInlineChildren;
            }
        }

        return $styledInlineChildren >= 2;
    }

    private function isInlineContentElement(string $tagName): bool
    {
        return in_array($tagName, array( 'abbr', 'b', 'cite', 'code', 'em', 'font', 'i', 'kbd', 'mark', 'rp', 'rt', 'ruby', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'var' ), true);
    }

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }
}
