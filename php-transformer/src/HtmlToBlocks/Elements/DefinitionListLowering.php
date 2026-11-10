<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Lowers a definition list's dt/dd pairs into core/list-item blocks, each
 * description prefixed by its bolded term.
 */
final class DefinitionListLowering
{
    public function __construct(
        private readonly SourceBlockCreator $blocks,
        private readonly Runtime $runtime,
        private readonly StyleResolver $styleResolver
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitionListItems(DOMElement $list): array
    {
        $items = array();
        $term = '';

        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'dt' === $tagName ) {
                $term = $this->styleResolver->innerHtml($child);
                continue;
            }

            if ( 'dd' === $tagName ) {
                $description = $this->styleResolver->innerHtml($child);
                if ( '' === trim($this->runtime->stripAllTags($term . $description)) ) {
                    continue;
                }

                $prefix = '' !== trim($term) ? '<strong>' . $term . '</strong>' : '';
                $items[] = $this->blocks->createBlock('core/list-item', array_merge($this->styleResolver->presentationAttributes($child), array(
                    'content' => trim($prefix . ( '' !== $prefix && '' !== trim($description) ? ' ' : '' ) . $description),
                )), array(), $child);
            }
        }

        return $items;
    }
}
