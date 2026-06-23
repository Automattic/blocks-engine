<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class ButtonsPattern
{
    /**
     * @param callable(DOMElement): array<string, mixed>|null $fileBlockFromAnchor
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function matchAnchor(DOMElement $anchor, callable $fileBlockFromAnchor, callable $presentationAttributes, callable $innerHtml, callable $attr, callable $createBlock): ?array
    {
        $fileBlock = $fileBlockFromAnchor($anchor);
        if ( null !== $fileBlock ) {
            return $fileBlock;
        }

        return $createBlock('core/buttons', array(), array( $this->buttonBlockFromAnchor($anchor, $presentationAttributes, $innerHtml, $attr, $createBlock) ), $anchor);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>
     */
    public function matchButton(DOMElement $button, callable $presentationAttributes, callable $innerHtml, callable $createBlock): array
    {
        return $createBlock('core/buttons', array(), array(
            $createBlock('core/button', array_merge($presentationAttributes($button), array( 'text' => $innerHtml($button) )), array(), $button),
        ), $button);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function matchContainer(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $attr, callable $createBlock): ?array
    {
        $buttons = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== trim($child->textContent ?? '') ) {
                $buttons[] = $this->buttonBlockFromAnchor($child, $presentationAttributes, $innerHtml, $attr, $createBlock);
            }
        }

        if ( count($buttons) <= 1 ) {
            return null;
        }

        return $createBlock('core/buttons', $presentationAttributes($element), $buttons, $element);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>
     */
    private function buttonBlockFromAnchor(DOMElement $anchor, callable $presentationAttributes, callable $innerHtml, callable $attr, callable $createBlock): array
    {
        return $createBlock('core/button', array_filter(array_merge($presentationAttributes($anchor), array(
            'text' => $innerHtml($anchor),
            'url'  => $attr($anchor, 'href'),
        )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== $value), array(), $anchor);
    }
}
