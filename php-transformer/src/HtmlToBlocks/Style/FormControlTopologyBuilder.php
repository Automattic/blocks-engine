<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Builds the bounded source wrapper topology for form fallback metadata. */
final class FormControlTopologyBuilder
{
    private const MAX_DEPTH = 16;

    private const MAX_NODES = 128;

    private const MAX_CLASSES = 8;

    private const MAX_LEGEND_BYTES = 200;

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
     * Preserve only directly adjacent label/control pairs. A provider that wraps
     * controls can then transpose its parent's proven source gap inside the field.
     *
     * @return array<string, mixed>
     */
    public function directLabelControlPairs(DOMElement $form): array
    {
        $indexes = array();
        foreach ( $this->controls($form) as $index => $control ) {
            $indexes[$control->getNodePath()] = $index;
        }
        $pairs = array();
        $truncated = false;
        $previousWasLabel = false;
        foreach ( $form->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $path = $child->getNodePath();
            if ( $previousWasLabel && isset($indexes[$path]) ) {
                if ( count($pairs) >= self::MAX_NODES ) {
                    $truncated = true;
                } else {
                    $pairs[] = array( 'control' => $indexes[$path] );
                }
            }
            $previousWasLabel = 'label' === strtolower($child->tagName);
        }

        return array(
            'schema'    => 'generic/form-sibling-relations/v1',
            'max_pairs' => self::MAX_NODES,
            'truncated' => $truncated,
            'pairs'     => $pairs,
        );
    }

    /**
     * Return each control's nearest-to-farthest wrapper ancestors that contain no
     * other form controls. Labels and other non-controls intentionally do not
     * make a wrapper shared.
     *
     * @return array<int, list<DOMElement>>
     */
    public function exclusiveWrapperAncestors(DOMElement $form): array
    {
        $result = array();
        $controls = $this->controls($form);
        $owners = array();
        foreach ( $controls as $control ) {
            $depth = 0;
            for ( $ancestor = $control->parentNode; $ancestor instanceof DOMElement && ! $ancestor->isSameNode($form) && $depth < self::MAX_DEPTH; $ancestor = $ancestor->parentNode, ++$depth ) {
                $path = $ancestor->getNodePath();
                $owners[$path] = ($owners[$path] ?? 0) + 1;
            }
        }
        foreach ( $controls as $index => $control ) {
            $ancestors = array();
            $depth = 0;
            for ( $ancestor = $control->parentNode; $ancestor instanceof DOMElement && ! $ancestor->isSameNode($form) && $depth < self::MAX_DEPTH; $ancestor = $ancestor->parentNode, ++$depth ) {
                if ( 1 === ($owners[$ancestor->getNodePath()] ?? 0) ) {
                    $ancestors[] = $ancestor;
                }
            }
            $result[$index] = $ancestors;
        }
        return $result;
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

        $classes = SourceDom::boundedClassTokens($element->hasAttribute('class') ? $element->getAttribute('class') : '', self::MAX_CLASSES);
        if ( array() !== $classes ) $presentation['class'] = implode(' ', $classes);

        if ( 'fieldset' === $tag ) {
            $semantics = 'plain_group';
            $legend = '';
            foreach ( $element->childNodes as $child ) {
                if ( $child instanceof DOMElement && 'legend' === strtolower($child->tagName) ) {
                    $semantics = 'labelled_group';
                    $legend = $this->legendCaption($child);
                    break;
                }
            }
            if ( $element->hasAttribute('disabled') ) {
                $semantics = 'disabled_group';
            } elseif ( '' !== trim($element->getAttribute('name')) || '' !== trim($element->getAttribute('form')) ) {
                $semantics = 'attributed_group';
            }
            $presentation['fieldset_semantics'] = $semantics;
            // The caption is the only authored name the group has. Reporting that a
            // fieldset is captioned without reporting the caption leaves a consumer
            // nothing to label the group with, so it can only flatten the group into
            // anonymous controls. Carry the text whenever the fieldset is captioned.
            if ( 'labelled_group' === $semantics && '' !== $legend ) {
                $presentation['legend'] = $legend;
            }
        }

        return $presentation;
    }

    /**
     * Read a fieldset caption as bounded single-line text.
     *
     * Builders nest the caption inside presentational elements, so the element's
     * text content is the caption. An oversized caption is dropped rather than
     * truncated: a group labelled with half a sentence reads worse than a group
     * a consumer declined to name.
     */
    private function legendCaption(DOMElement $legend): string
    {
        $caption = preg_replace('/\s+/', ' ', trim($legend->textContent ?? ''));

        return is_string($caption) && '' !== $caption && self::MAX_LEGEND_BYTES >= strlen($caption) ? $caption : '';
    }

    /** @return array<int, DOMElement> */
    private function controls(DOMElement $form): array
    {
        return FormControlClassifier::controlElements($form);
    }
}
