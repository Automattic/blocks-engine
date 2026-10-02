<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CollectionFilterBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Closure;
use DOMElement;
use LogicException;

/** Editable controls over native content, admitted by the evidence projector. */
final class CapturedCollectionConverter implements ElementConverter
{
    public function __construct(private readonly HtmlTransformerSession $session, private readonly Closure $convertChildren, private readonly Closure $convertElement) {}

    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ($element->hasAttribute('data-blocks-engine-collection-target')) {
            // A hidden empty-state sibling must not prevent recognition of the
            // canonical native accordion/list. Convert it separately while the
            // source children retain their original ownership and ordering.
            $empty = null;
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement && $child->hasAttribute('data-blocks-engine-collection-empty')) $empty = $child;
            }
            if ($empty) $element->removeChild($empty);
            $identity = $element->getAttribute('data-blocks-engine-collection-target');
            $element->removeAttribute('data-blocks-engine-collection-target');
            try {
                $block = ($this->convertElement)($element, $fallbacks);
            } finally {
                $element->setAttribute('data-blocks-engine-collection-target', $identity);
                if ($empty) $element->appendChild($empty);
            }
            if ($empty && is_array($block)) {
                $emptyBlock = ($this->convertElement)($empty, $fallbacks);
                if ($emptyBlock) {
                    $block['innerBlocks'][] = $emptyBlock;
                    array_splice($block['innerContent'], -1, 0, array(null));
                }
            }
            return ConversionOutcome::handled($block);
        }
        $local = null;
        if ($element->hasAttribute('data-blocks-engine-collection-root')) $local = CollectionFilterBlockGenerator::ROOT;
        elseif ($element->hasAttribute('data-blocks-engine-collection-field')) $local = CollectionFilterBlockGenerator::FIELD;
        elseif ($element->hasAttribute('data-blocks-engine-collection-choice')) $local = CollectionFilterBlockGenerator::CHOICE;
        elseif ($element->hasAttribute('data-blocks-engine-collection-choices')) $local = CollectionFilterBlockGenerator::CHOICES;
        elseif ($element->hasAttribute('data-blocks-engine-collection-empty')) $local = CollectionFilterBlockGenerator::EMPTY;
        if (null === $local) return ConversionOutcome::unhandled();
        $config = null;
        if (CollectionFilterBlockGenerator::ROOT === $local) {
            $config = json_decode($element->getAttribute('data-blocks-engine-collection-root'), true);
            if (!is_array($config) || !is_array($config['items'] ?? null) || !is_int($config['initialCategory'] ?? null)) return ConversionOutcome::unhandled();
        } elseif (CollectionFilterBlockGenerator::CHOICE === $local) {
            $config = json_decode($element->getAttribute('data-blocks-engine-collection-choice'), true);
            if ('button' !== $tagName || !is_array($config) || !is_int($config['index'] ?? null) || !is_array($config['active'] ?? null) || !is_array($config['inactive'] ?? null)) return ConversionOutcome::unhandled();
        } elseif (CollectionFilterBlockGenerator::FIELD === $local && 'input' !== $tagName) {
            return ConversionOutcome::unhandled();
        }
        $generator = new CollectionFilterBlockGenerator();
        $registry = $this->session->generatedBlockRegistry() ?? throw new LogicException('Generated block registry has not been prepared.');
        $registry->register(CollectionFilterBlockGenerator::class . '/' . $local, $generator->definition($registry->namespace(), $local));
        $attrs = array('className' => $element->getAttribute('class'), 'anchor' => $element->getAttribute('id'));
        $style = $generator->sourceStyle($element->getAttribute('style'));
        if ($style) $attrs['sourceStyle'] = $style;
        $children = array();
        if (CollectionFilterBlockGenerator::ROOT === $local) {
            $attrs += array('tagName' => $tagName, 'items' => $config['items'], 'initialCategory' => $config['initialCategory']);
            foreach (array('mode' => 'category-and-query', 'order' => array(), 'categoryOrders' => array()) as $key => $default) {
                $attrs[$key] = $config[$key] ?? $default;
            }
            if (!in_array($attrs['mode'], array('category-and-query', 'category-or-global-search'), true) || !is_array($attrs['order']) || !is_array($attrs['categoryOrders'])) return ConversionOutcome::unhandled();
            $children = ($this->convertChildren)($element, $fallbacks);
        } elseif (CollectionFilterBlockGenerator::CHOICES === $local) {
            $attrs['tagName'] = in_array($tagName, array('div', 'nav', 'section'), true) ? $tagName : 'div';
            $children = ($this->convertChildren)($element, $fallbacks);
        } elseif (CollectionFilterBlockGenerator::FIELD === $local) {
            $attrs += array('inputType' => $element->getAttribute('type') ?: 'text', 'placeholder' => $element->getAttribute('placeholder'), 'ariaLabel' => $element->getAttribute('aria-label'), 'value' => $element->getAttribute('value'));
        } elseif (CollectionFilterBlockGenerator::CHOICE === $local) {
            foreach (array('active', 'inactive') as $state) $config[$state]['style'] = $generator->sourceStyle($config[$state]['style'] ?? '');
            $attrs += array('label' => htmlspecialchars(trim($element->textContent ?? ''), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'ariaLabel' => $element->getAttribute('aria-label')) + $config;
        } else {
            $children = ($this->convertChildren)($element, $fallbacks);
        }
        $opening = $generator->opening($attrs, $local, $registry->namespace());
        if (CollectionFilterBlockGenerator::FIELD === $local) {
            $html = $opening;
            $content = array($html);
        } elseif (CollectionFilterBlockGenerator::CHOICE === $local) {
            $html = $opening . $attrs['label'] . '</button>';
            $content = array($html);
        } else {
            $closingTag = CollectionFilterBlockGenerator::ROOT === $local ? $tagName : ($attrs['tagName'] ?? 'div');
            $closing = '</' . $closingTag . '>';
            $html = $opening . $closing;
            $content = array_merge(array($opening), array_fill(0, count($children), null), array($closing));
        }
        return ConversionOutcome::handled(array('blockName' => $registry->blockName($local), 'attrs' => $attrs, 'innerBlocks' => $children, 'innerHTML' => $html, 'innerContent' => $content));
    }
}
