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

        if ( ! $this->hasButtonSignal($anchor) ) {
            return null;
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
        $containerHasButtonSignal = $this->hasContainerButtonSignal($element);
        $buttons = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== trim($child->textContent ?? '') && ( $containerHasButtonSignal || $this->hasButtonSignal($child) ) ) {
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

    private function hasButtonSignal(DOMElement $anchor): bool
    {
        if ( 'button' === strtolower($anchor->hasAttribute('role') ? $anchor->getAttribute('role') : '') ) {
            return true;
        }

        return $this->hasAnyToken($anchor, array( 'button', 'btn', 'cta', 'action' )) || $this->hasPhrase($anchor, array( 'call-to-action', 'primary-action', 'secondary-action' ));
    }

    private function hasContainerButtonSignal(DOMElement $element): bool
    {
        return $this->hasAnyToken($element, array( 'buttons', 'button', 'btns', 'cta', 'actions' )) || $this->hasPhrase($element, array( 'button-group', 'button-row', 'cta-group', 'call-to-action' ));
    }

    /**
     * @param array<int, string> $tokens
     */
    private function hasAnyToken(DOMElement $element, array $tokens): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, $tokens, true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $phrases
     */
    private function hasPhrase(DOMElement $element, array $phrases): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = strtolower($element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '');
            foreach ( $phrases as $phrase ) {
                if ( str_contains($value, $phrase) ) {
                    return true;
                }
            }
        }

        return false;
    }
}
