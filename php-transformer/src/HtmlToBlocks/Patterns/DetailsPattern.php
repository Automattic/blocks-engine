<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class DetailsPattern
{
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param callable(DOMElement, array<int, array<string, mixed>>&, array<int, string>): array<int, array<string, mixed>> $convertChildrenWithoutTags
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, array &$fallbacks, callable $convertChildrenWithoutTags, callable $presentationAttributes, callable $innerHtml, callable $createBlock): ?array
    {
        $summary = $this->firstChildElement($element, 'summary');
        $children = $convertChildrenWithoutTags($element, $fallbacks, array( 'summary' ));
        if ( null === $summary && array() === $children ) {
            return null;
        }

        return $createBlock('core/details', array_filter(array_merge($presentationAttributes($element), array(
            'summary' => $summary instanceof DOMElement ? $innerHtml($summary) : '',
        )), static fn ($value): bool => '' !== $value), $children, $element);
    }

    private function firstChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && strtolower($child->tagName) === $tagName ) {
                return $child;
            }
        }

        return null;
    }
}
