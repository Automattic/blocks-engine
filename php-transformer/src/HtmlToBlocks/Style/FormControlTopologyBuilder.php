<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use DOMElement;

/** Builds the bounded source wrapper topology for form fallback metadata. */
final class FormControlTopologyBuilder
{
    private const MAX_DEPTH = 16;

    private const MAX_NODES = 128;

    private const MAX_CLASSES = 8;

    /** @var array<int, string> */
    private const WRAPPER_TAGS = array(
        'article', 'aside', 'dd', 'div', 'dl', 'dt', 'fieldset', 'footer', 'header',
        'label', 'li', 'main', 'nav', 'ol', 'p', 'section', 'span', 'table', 'tbody',
        'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    );

    /** @return array<string, mixed> */
    public function build(DOMElement $form): array
    {
        $controlIndexes = array();
        $relevantElements = array();
        foreach ( $this->controls($form) as $index => $control ) {
            $controlIndexes[$control->getNodePath()] = $index;
            for ( $ancestor = $control->parentNode; $ancestor instanceof DOMElement && $ancestor !== $form; $ancestor = $ancestor->parentNode ) {
                $relevantElements[$ancestor->getNodePath()] = true;
            }
        }

        $nodes = array();
        $wrapperIndex = 0;
        $truncated = false;
        $order = 0;
        foreach ( $form->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) continue;
            if ( $this->appendNode($child, null, $order, 0, $controlIndexes, $relevantElements, $nodes, $wrapperIndex, $truncated) ) ++$order;
        }

        return array(
            'schema'    => 'generic/form-control-topology/v1',
            'max_depth' => self::MAX_DEPTH,
            'max_nodes' => self::MAX_NODES,
            'nodes'     => $nodes,
            'truncated' => $truncated,
        );
    }

    /**
     * @param array<string, int> $controlIndexes
     * @param array<string, bool> $relevantElements
     * @param array<int, array<string, mixed>> $nodes
     */
    private function appendNode(DOMElement $element, ?string $parent, int $order, int $depth, array $controlIndexes, array $relevantElements, array &$nodes, int &$wrapperIndex, bool &$truncated): bool
    {
        $nodePath = $element->getNodePath();
        if ( ! isset($controlIndexes[$nodePath]) && ! isset($relevantElements[$nodePath]) ) return false;
        if ( $depth > self::MAX_DEPTH || count($nodes) >= self::MAX_NODES ) {
            $truncated = true;
            return false;
        }

        if ( isset($controlIndexes[$nodePath]) ) {
            $controlIndex = $controlIndexes[$nodePath];
            $nodes[] = array_filter(array(
                'id'      => 'control-' . $controlIndex,
                'kind'    => 'control',
                'parent'  => $parent,
                'order'   => $order,
                'depth'   => $depth,
                'control' => $controlIndex,
            ), static fn (mixed $value): bool => null !== $value);
            return true;
        }

        $id = 'wrapper-' . $wrapperIndex++;
        $nodes[] = array_filter(array_merge(array(
            'id'     => $id,
            'kind'   => 'wrapper',
            'parent' => $parent,
            'order'  => $order,
            'depth'  => $depth,
        ), $this->presentation($element)), static fn (mixed $value): bool => null !== $value && '' !== $value);

        $childOrder = 0;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) continue;
            if ( $this->appendNode($child, $id, $childOrder, $depth + 1, $controlIndexes, $relevantElements, $nodes, $wrapperIndex, $truncated) ) ++$childOrder;
        }

        return true;
    }

    /** @return array<string, string> */
    private function presentation(DOMElement $element): array
    {
        $tag = strtolower($element->tagName);
        $presentation = array();
        if ( in_array($tag, self::WRAPPER_TAGS, true) ) $presentation['tag'] = $tag;

        $id = trim($element->hasAttribute('id') ? $element->getAttribute('id') : '');
        if ( 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $id) ) $presentation['source_id'] = $id;

        $classes = array();
        $classAttribute = $element->hasAttribute('class') ? $element->getAttribute('class') : '';
        foreach ( preg_split('/\s+/', trim($classAttribute)) ?: array() as $class ) {
            if ( count($classes) >= self::MAX_CLASSES ) break;
            if ( 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class) ) $classes[] = $class;
        }
        if ( array() !== $classes ) $presentation['class'] = implode(' ', $classes);

        if ( 'fieldset' === $tag ) {
            $semantics = 'plain_group';
            foreach ( $element->childNodes as $child ) {
                if ( $child instanceof DOMElement && 'legend' === strtolower($child->tagName) ) {
                    $semantics = 'labelled_group';
                    break;
                }
            }
            if ( $element->hasAttribute('disabled') ) {
                $semantics = 'disabled_group';
            } elseif ( '' !== trim($element->getAttribute('name')) || '' !== trim($element->getAttribute('form')) ) {
                $semantics = 'attributed_group';
            }
            $presentation['fieldset_semantics'] = $semantics;
        }

        return $presentation;
    }

    /** @return array<int, DOMElement> */
    private function controls(DOMElement $form): array
    {
        $controls = array();
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( $control instanceof DOMElement && FormControlClassifier::isControlElement($control) ) {
                $controls[] = $control;
            }
        }

        return $controls;
    }
}
