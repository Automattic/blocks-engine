<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

/** Lowers WordPress post navigation links, a dynamic post primitive rather than a menu, to core/post-navigation-link. */
final class PostNavigationLinkConverter implements ElementConverter
{
    public function __construct(private readonly StyleResolver $styleResolver, private readonly SourceBlockCreator $createBlock)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array();
        if (in_array('wp-block-post-navigation-link', $classes, true)) {
            foreach ($element->getElementsByTagName('a') as $link) {
                if (!in_array($link->getAttribute('rel'), array('prev', 'next'), true)) continue;
                $title = null;
                $label = '';
                foreach ($link->childNodes as $child) {
                    $class = $child instanceof DOMElement ? $child->getAttribute('class') : '';
                    if (str_contains($class, 'post-navigation-link__title')) { $title = $child; continue; }
                    if (str_contains($class, 'post-navigation-link__arrow')) continue;
                    $label .= $child->textContent;
                }
                // The arrow modifier sits on the arrow span; older markup carries it on the wrapper.
                $arrow = 'none';
                foreach (array_merge(array($element), iterator_to_array($element->getElementsByTagName('span'))) as $node) {
                    $nodeClasses = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: array();
                    if ($node !== $element && array() === preg_grep('/^wp-block-post-navigation-link__arrow-/', $nodeClasses)) continue;
                    if (in_array('is-arrow-arrow', $nodeClasses, true)) $arrow = 'arrow';
                    elseif (in_array('is-arrow-chevron', $nodeClasses, true)) $arrow = 'chevron';
                }
                // A captured label sits inside the link (core's linkLabel); that
                // is also the only label placement core keeps alongside an arrow.
                // Keep the wrapper's own presentation (inline typography, source
                // classes) so authored rules still reach the native block.
                $attrs = $this->styleResolver->presentationAttributes($element);
                $type = 'prev' === $link->getAttribute('rel') ? 'previous' : 'next';
                $ownClasses = array_diff(preg_split('/\s+/', trim(($attrs['className'] ?? '') . ' ' . implode(' ', $classes))) ?: array(), array('', 'wp-block-post-navigation-link', 'post-navigation-link-' . $type, 'is-arrow-arrow', 'is-arrow-chevron'));
                unset($attrs['className']);
                if (array() !== $ownClasses) $attrs['className'] = implode(' ', array_unique($ownClasses));
                return ConversionOutcome::handled($this->createBlock->createBlock('core/post-navigation-link', array_merge($attrs, array('type' => $type, 'showTitle' => null !== $title, 'arrow' => $arrow, 'label' => trim($label)), null !== $title && '' !== trim($label) ? array('linkLabel' => true) : array()), array(), $element));
            }
        }

        return ConversionOutcome::unhandled();
    }
}
