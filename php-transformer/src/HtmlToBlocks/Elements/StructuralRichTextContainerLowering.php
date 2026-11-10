<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

/**
 * A paragraph, heading or list item whose RichText content holds structural
 * HTML cannot store it; its source element becomes a group of its converted
 * children instead, keeping a decorated heading's sole text run editable as
 * a heading.
 */
final class StructuralRichTextContainerLowering
{
    public function __construct(
        private readonly PatternTreeConverter $tree,
        private readonly SourceBlockCreator $blocks,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly StyleResolver $styleResolver
    ) {
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>|null The container group, or null when the block keeps its RichText.
     */
    public function containerBlock(string $name, array $attrs, DOMElement $sourceElement, ?DOMElement $logicalSourceElement): ?array
    {
        if ( in_array($name, array( 'core/paragraph', 'core/heading', 'core/list-item' ), true)
            && $this->richTextMaterializer->hasStructuralHtml((string) ($attrs['content'] ?? ''))
        ) {
            $structuralFallbacks = array();
            $children = $this->tree->children($sourceElement, $structuralFallbacks, true);
            if ( array() !== $children ) {
                // Core headings cannot store a block-level decoration in RichText.
                // Keep the decorated wrapper but retain its one text child as an
                // editable heading rather than silently turning it into a paragraph.
                // Authored paragraphs are flow content, not a decorated
                // heading's synthetic text run; keep them as paragraphs.
                if ( 'core/heading' === $name && 0 === $sourceElement->getElementsByTagName('p')->length ) {
                    $textChildren = array_keys(array_filter($children, static fn (array $child): bool =>
                        'core/paragraph' === ($child['blockName'] ?? null)
                        && '' !== trim(strip_tags((string) ($child['attrs']['content'] ?? '')))
                    ));
                    if ( 1 === count($textChildren) ) {
                        $index = $textChildren[0];
                        $headingText = html_entity_decode(strip_tags((string) $children[$index]['attrs']['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $normalizeText = static fn (string $text): string => trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
                        if ( $normalizeText($headingText) === $normalizeText((string) $sourceElement->textContent) ) {
                            $children[$index] = $this->blocks->createBlock('core/heading', array(
                                'content' => $children[$index]['attrs']['content'],
                                'level' => $attrs['level'] ?? 2,
                                // The decorated wrapper keeps the source heading's
                                // classes, so its typography is the source's. The
                                // inner heading inherits all of it instead of adding
                                // the destination's heading defaults (bold, size).
                                'style' => array(
                                    'typography' => array_fill_keys(array( 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform', 'fontStyle' ), 'inherit'),
                                    'spacing' => array('margin' => array('top' => '0', 'bottom' => '0')),
               ),
                            ));
                        }
                    }
                }
                $containerAttrs = $this->styleResolver->presentationAttributes($sourceElement);
                if ('core/heading' === $name) {
                    // Keep baked/responsive heading typography, not just the
                    // generic presentation, when RichText becomes a container.
                    $containerAttrs = $attrs;
                    unset($containerAttrs['content'], $containerAttrs['level']);
                    $containerAttrs = $this->styleResolver->headingContainerAttributes($sourceElement, $containerAttrs);
                }
                return $this->blocks->createBlock('core/group', $containerAttrs, $children, $sourceElement, $logicalSourceElement);
            }
        }

        return null;
    }
}
