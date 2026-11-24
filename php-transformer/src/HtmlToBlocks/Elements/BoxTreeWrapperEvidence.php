<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use DOMDocument;
use DOMElement;
use DOMText;

/**
 * Reads one route's box-tree evidence (`box-tree/<route>.json`, schema
 * `blocks-engine/box-tree/v2` or compact v3, read through
 * {@see BoxTree::read()}) and answers, for a source wrapper, whether
 * its box is geometry-neutral at every captured viewport (ADR 0006, slice 1).
 *
 * Source elements map to evidence nodes by `data-blocks-engine-node-id`. An
 * element whose id is missing, unknown, invalid or duplicated in the document
 * has no evidence and is `load_bearing`. The identity attribute is read here
 * only; it never reaches compiled output.
 *
 * A wrapper is `neutral` when at every viewport it is visible, paints nothing,
 * clips nothing, is static (or relative with inert offsets and no out-of-flow
 * descendant whose containing block it is), does not float, stack or animate,
 * and its single element child's border box equals its own. Offsets are read
 * as `getComputedStyle` reports them, resolved to px, so `auto` and `0px` are
 * both inert; a static wrapper's offsets apply to nothing and are ignored.
 * A relative wrapper with a non-zero offset moves its box and is
 * `load_bearing`. Each answer carries the first failing reason.
 */
final class BoxTreeWrapperEvidence
{
    public const NEUTRAL = 'neutral';
    public const LOAD_BEARING = 'load_bearing';

    /** Border boxes within this many CSS pixels are equal. */
    private const BOX_TOLERANCE = 0.5;

    /** @var list<string> */
    private array $viewports = array();

    /** @var array<string, array<string, mixed>> */
    private array $nodes = array();

    /** @var \WeakMap<DOMDocument, array<string, int>>|null */
    private ?\WeakMap $idCounts = null;

    /** @param array<string, mixed> $tree A decoded box-tree sidecar, or an empty array. */
    public function __construct(array $tree = array())
    {
        $tree = BoxTree::read($tree);
        if (null === $tree || ! is_array($tree['viewports'] ?? null) || ! is_array($tree['nodes'] ?? null)) return;
        $viewports = array();
        foreach ($tree['viewports'] as $name => $viewport) {
            if (! is_string($name) || '' === $name || ! is_array($viewport)) return;
            $viewports[] = $name;
        }
        if (array() === $viewports) return;
        $nodes = array();
        foreach ($tree['nodes'] as $id => $node) {
            if (! BoxTree::validId($id) || ! is_array($node) || ! is_array($node['viewports'] ?? null)) continue;
            $nodes[(string) $id] = $node;
        }
        $this->viewports = $viewports;
        $this->nodes = $nodes;
    }

    /** Whether this route carries any usable evidence. */
    public function hasEvidence(): bool
    {
        return array() !== $this->nodes;
    }

    public function isNeutral(DOMElement $wrapper): bool
    {
        return self::NEUTRAL === $this->classify($wrapper)['state'];
    }

    /**
     * @return array{state: string, reason: string}
     */
    public function classify(DOMElement $wrapper): array
    {
        $node = $this->node($wrapper);
        if (null === $node) return self::answer(self::LOAD_BEARING, 'no_evidence');
        $child = null;
        foreach ($wrapper->childNodes as $childNode) {
            if ($childNode instanceof DOMText && '' !== trim($childNode->data)) return self::answer(self::LOAD_BEARING, 'text_content');
            if (! $childNode instanceof DOMElement) continue;
            if (null !== $child) return self::answer(self::LOAD_BEARING, 'not_single_child');
            $child = $childNode;
        }
        if (null === $child) return self::answer(self::LOAD_BEARING, 'not_single_child');
        $childNode = $this->node($child);
        if (null === $childNode) return self::answer(self::LOAD_BEARING, 'child_no_evidence');

        foreach ($this->viewports as $viewport) {
            $observation = self::observation($node, $viewport);
            $childObservation = self::observation($childNode, $viewport);
            if (null === $observation || null === $childObservation) return self::answer(self::LOAD_BEARING, 'missing_viewport:' . $viewport);
            $styles = $observation['used_styles'];
            $box = $observation['border_box'];
            if ('none' === self::style($styles, 'display') || $box['width'] <= 0.0) return self::answer(self::LOAD_BEARING, 'hidden:' . $viewport);
            $paint = self::paintReason($styles);
            if (null !== $paint) return self::answer(self::LOAD_BEARING, 'paint:' . $paint);
            if ('none' !== self::style($styles, 'float')) return self::answer(self::LOAD_BEARING, 'float');
            if ('auto' !== self::style($styles, 'z-index')) return self::answer(self::LOAD_BEARING, 'z_index');
            $animation = $styles['animation-name'] ?? 'none';
            if (! is_string($animation) || 'none' !== self::comparable($animation)) return self::answer(self::LOAD_BEARING, 'animation');
            $position = self::style($styles, 'position');
            if ('relative' === $position) {
                foreach (array('top', 'right', 'bottom', 'left') as $side) {
                    $offset = self::style($styles, $side);
                    if ('auto' !== $offset && ! self::zeroLength($offset)) return self::answer(self::LOAD_BEARING, 'inset:' . $side);
                }
                if ($this->containsOutOfFlowFor($wrapper, $viewport)) return self::answer(self::LOAD_BEARING, 'containing_block');
            } elseif ('static' !== $position) {
                return self::answer(self::LOAD_BEARING, 'position:' . $position);
            }
            if (! self::sameBox($box, $childObservation['border_box'])) return self::answer(self::LOAD_BEARING, 'child_box_differs:' . $viewport);
        }
        return self::answer(self::NEUTRAL, 'neutral');
    }

    /**
     * Whether `$leaf` resolves a moved size declaration to the border box it
     * already has: it sizes its border box, or has no padding or border at any
     * captured viewport.
     */
    public function sizeTransfersTo(DOMElement $leaf): bool
    {
        $node = $this->node($leaf);
        if (null === $node) return false;
        foreach ($this->viewports as $viewport) {
            $observation = self::observation($node, $viewport);
            if (null === $observation) return false;
            $styles = $observation['used_styles'];
            if ('border-box' === self::style($styles, 'box-sizing')) continue;
            foreach (array('padding-top', 'padding-right', 'padding-bottom', 'padding-left') as $property) {
                if (! self::zeroLength(self::style($styles, $property))) return false;
            }
            foreach (array('border-top', 'border-right', 'border-bottom', 'border-left') as $property) {
                if (! self::noBorder(self::style($styles, $property))) return false;
            }
        }
        return true;
    }

    /**
     * The element's used value of `$property` at every captured viewport,
     * normalized for comparison, or null when the element (or any viewport
     * of it) has no evidence.
     *
     * @return array<string, string>|null
     */
    public function usedValues(DOMElement $element, string $property): ?array
    {
        $node = $this->node($element);
        if (null === $node) return null;
        $values = array();
        foreach ($this->viewports as $viewport) {
            $observation = self::observation($node, $viewport);
            if (null === $observation || ! array_key_exists($property, $observation['used_styles'])) return null;
            $values[$viewport] = self::style($observation['used_styles'], $property);
        }
        return $values;
    }

    /**
     * Whether an observed descendant is absolutely positioned against this
     * wrapper at the viewport. An unobserved descendant cannot be proven
     * otherwise, so it counts.
     */
    private function containsOutOfFlowFor(DOMElement $wrapper, string $viewport): bool
    {
        foreach ($wrapper->getElementsByTagName('*') as $descendant) {
            $node = $this->node($descendant);
            if (null === $node) return true;
            $observation = self::observation($node, $viewport);
            if (null === $observation) return true;
            if ('absolute' !== self::style($observation['used_styles'], 'position')) continue;
            // The containing block is the nearest positioned ancestor.
            for ($ancestor = $descendant->parentNode; $ancestor instanceof DOMElement && $ancestor !== $wrapper; $ancestor = $ancestor->parentNode) {
                $ancestorNode = $this->node($ancestor);
                $ancestorObservation = null === $ancestorNode ? null : self::observation($ancestorNode, $viewport);
                if (null === $ancestorObservation) return true;
                if ('static' !== self::style($ancestorObservation['used_styles'], 'position')) continue 2;
            }
            return true;
        }
        return false;
    }

    /** The first property that makes the wrapper paint or clip, if any. */
    private static function paintReason(array $styles): ?string
    {
        $color = self::style($styles, 'background-color');
        if (! in_array($color, array('transparent', 'rgba(0,0,0,0)'), true)) return 'background-color';
        foreach (array('background-image', 'box-shadow', 'transform', 'filter', 'mask-image', 'clip-path', 'backdrop-filter') as $property) {
            if ('none' !== self::style($styles, $property)) return $property;
        }
        foreach (array('border-top', 'border-right', 'border-bottom', 'border-left') as $property) {
            if (! self::noBorder(self::style($styles, $property))) return $property;
        }
        if (! preg_match('/(?:^|\s)none(?:\s|$)/', self::style($styles, 'outline'))) return 'outline';
        if (! in_array(self::style($styles, 'opacity'), array('1', '1.0'), true)) return 'opacity';
        foreach (array('overflow-x', 'overflow-y') as $property) {
            if ('visible' !== self::style($styles, $property)) return $property;
        }
        return null;
    }

    private static function noBorder(string $value): bool
    {
        if ('' === $value) return false;
        if ('none' === $value || preg_match('/(?:^|\s)(?:none|hidden)(?:\s|$)/', $value)) return true;
        foreach (preg_split('/\s+/', $value) ?: array() as $part) {
            if (preg_match('/^-?[0-9.]+(?:px)?$/', $part)) return self::zeroLength($part);
        }
        return false;
    }

    private static function zeroLength(string $value): bool
    {
        return 1 === preg_match('/^-?(?:0+(?:\.0+)?|\.0+)(?:px)?$/', $value);
    }

    /** @param array{x: float, y: float, width: float, height: float} $a @param array{x: float, y: float, width: float, height: float} $b */
    private static function sameBox(array $a, array $b): bool
    {
        foreach (array('x', 'y', 'width', 'height') as $axis) {
            if (abs($a[$axis] - $b[$axis]) > self::BOX_TOLERANCE) return false;
        }
        return true;
    }

    /** @param array<string, mixed> $styles */
    private static function style(array $styles, string $property): string
    {
        $value = $styles[$property] ?? null;
        return is_string($value) || is_int($value) || is_float($value) ? self::comparable((string) $value) : '';
    }

    private static function comparable(string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s*,\s*/', ',', trim($value))));
    }

    /**
     * @param array<string, mixed> $node
     * @return array{border_box: array{x: float, y: float, width: float, height: float}, used_styles: array<string, mixed>}|null
     */
    private static function observation(array $node, string $viewport): ?array
    {
        $observation = $node['viewports'][$viewport] ?? null;
        if (! is_array($observation) || ! is_array($observation['used_styles'] ?? null) || ! is_array($observation['border_box'] ?? null)) return null;
        $box = array();
        foreach (array('x', 'y', 'width', 'height') as $axis) {
            $value = $observation['border_box'][$axis] ?? null;
            if (! is_int($value) && ! is_float($value)) return null;
            $box[$axis] = (float) $value;
        }
        return array('border_box' => $box, 'used_styles' => $observation['used_styles']);
    }

    /** @return array<string, mixed>|null */
    private function node(DOMElement $element): ?array
    {
        if (array() === $this->nodes) return null;
        $id = $element->getAttribute(BoxTree::NODE_ATTRIBUTE);
        if ('' === $id || ! isset($this->nodes[$id])) return null;
        $document = $element->ownerDocument;
        if (! $document instanceof DOMDocument) return null;
        $this->idCounts ??= new \WeakMap();
        if (! isset($this->idCounts[$document])) {
            $counts = array();
            foreach ($document->getElementsByTagName('*') as $candidate) {
                $value = $candidate->getAttribute(BoxTree::NODE_ATTRIBUTE);
                if ('' !== $value) $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
            $this->idCounts[$document] = $counts;
        }
        // A duplicated id identifies no single source element.
        return 1 === ($this->idCounts[$document][$id] ?? 0) ? $this->nodes[$id] : null;
    }

    /** @return array{state: string, reason: string} */
    private static function answer(string $state, string $reason): array
    {
        return array('state' => $state, 'reason' => $reason);
    }
}
