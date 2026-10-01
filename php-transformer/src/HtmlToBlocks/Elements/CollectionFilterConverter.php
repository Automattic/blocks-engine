<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CollectionFilterBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;

/** Keeps controls local and authoring content native, without category snapshots. */
final class CollectionFilterConverter implements ElementConverter
{
    public function __construct(private readonly HtmlTransformerSession $session, private readonly StyleResolver $styleResolver, private readonly Closure $convertChildren, private readonly Closure $convertElement) {}

    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        $targetBlock = null;
        if ('true' === $element->getAttribute('data-blocks-engine-collection-target')) {
            $element->removeAttribute('data-blocks-engine-collection-target');
            $element->removeAttribute('data-dla-collection');
            $element->setAttribute('class', trim($element->getAttribute('class') . ' blocks-engine-collection-target'));
            $targetBlock = ($this->convertElement)($element, $fallbacks);
            $element->setAttribute('data-blocks-engine-collection-target', 'true');
        }
        $kind = null !== $targetBlock ? 'target' : ($element->hasAttribute('data-blocks-engine-collection') ? 'root' : $element->getAttribute('data-blocks-engine-collection-control'));
        if (!in_array($kind, array('root', 'field', 'category', 'empty', 'target'), true)) return ConversionOutcome::unhandled();
        $root = $element;
        while (!$root->hasAttribute('data-blocks-engine-collection') && $root->parentNode instanceof DOMElement) $root = $root->parentNode;
        $config = json_decode($root->getAttribute('data-blocks-engine-collection'), true);
        if (!is_array($config)) return ConversionOutcome::unhandled();
        if ('target' === $kind && (!in_array($targetBlock['blockName'], array('core/accordion', 'core/group'), true) || count($targetBlock['innerBlocks'] ?? array()) !== count($config['memberships']))) {
            $fallbacks[] = array('type' => 'unsupported_element', 'reason' => 'collection_item_mapping_unproven', 'diagnostic_code' => 'html_collection_item_mapping_unproven', 'source_format' => 'html', 'tag' => $tagName, 'selector' => SourceDom::elementSelector($element), 'html' => SourceDom::outerHtml($element));
        }
        $generator = new CollectionFilterBlockGenerator();
        $registry = $this->session->generatedBlockRegistry();
        $registry->register(CollectionFilterBlockGenerator::class, $generator->definition($registry->namespace()));
        $name = $registry->blockName(CollectionFilterBlockGenerator::LOCAL_NAME);
        $presentation = $this->styleResolver->presentationAttributes($element);
        $attributes = array('kind' => $kind, 'tag' => 'field' === $kind ? 'input' : ('category' === $kind ? 'button' : (in_array($tagName, array('div','section','main'), true) ? $tagName : 'div')), 'className' => trim(($presentation['className'] ?? '') . ' ' . $this->styleResolver->inlineGeometryClassName($element, array())), 'label' => 'field' === $kind ? $element->getAttribute('aria-label') : trim($element->textContent), 'placeholder' => $element->getAttribute('placeholder'), 'index' => (int) $element->getAttribute('data-dla-collection-index'), 'config' => $config);
        if ('field' === $kind) $attributes['inputType'] = $element->getAttribute('type') ?: 'text';
        if ('target' === $kind) $attributes['className'] = trim($attributes['className'] . ' blocks-engine-collection-target');
        $opening = $generator->opening($attributes, $name);
        if (!in_array($kind, array('category', 'field'), true)) $attributes['label'] = '';
        $children = 'target' === $kind ? array($targetBlock) : (in_array($kind, array('root','empty'), true) ? ($this->convertChildren)($element, $fallbacks) : array());
        $closing = 'field' === $kind ? '' : ('category' === $kind ? htmlspecialchars($attributes['label'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') : '') . '</' . $attributes['tag'] . '>';
        return ConversionOutcome::handled(array('blockName' => $name, 'attrs' => $attributes, 'innerBlocks' => $children, 'innerHTML' => $opening . $closing, 'innerContent' => array_merge(array($opening), array_fill(0, count($children), null), array($closing))));
    }
}
