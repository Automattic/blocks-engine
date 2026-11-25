<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatchCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * A paint-free fill layer around a fill image and its overlays is that
 * image's figure followed by the overlays.
 *
 * Hero sections commonly render `<section class="relative"><div
 * class="absolute inset-0"><img class="h-full w-full object-cover"><div
 * class="absolute inset-0 bg-gradient-to-r ..."></div></div>...</section>`:
 * the layer only pins a box to its containing block, the image fills that box
 * in flow and each overlay is pinned to the same box. The layer paints
 * nothing, so its box is the box the image's figure can own: the figure takes
 * the layer's classes and identity, and each overlay, pinned to the layer's
 * box, is pinned to the same containing block from beside the figure. Paint
 * order is unchanged: the layer, a positioned box with `z-index:auto`, paints
 * the image at its place in tree order and its positioned overlays after it,
 * exactly where the figure and the overlays now paint. Lowered as a Group, the
 * layer is a wrapper an editor has to click through without it meaning
 * anything.
 *
 * Only the author cascade decides: at every viewport the layer is absolutely
 * positioned at all four zero insets with no box, paint, stacking or sizing
 * of its own; the image is in flow at 100% width and height with no margin;
 * every overlay is absolutely positioned at all four zero insets. Every rule
 * that styled the layer or the image must still style the merged figure and
 * no other, and every overlay must keep exactly the rules it had.
 */
final class FillLayerImageLowering
{
    private const LAYER_TAGS = array('div', 'span');

    private const OVERLAY_TAGS = array('div', 'span');

    private const LAYER_ATTRIBUTES = array('class');

    private const IMAGE_ATTRIBUTES = array(
        'src', 'srcset', 'sizes', 'alt', 'title', 'loading', 'decoding', 'fetchpriority',
        'width', 'height', 'class', 'style', 'crossorigin', 'referrerpolicy',
    );

    private const ZERO = array('0', '0px', '0%', '0rem', '0em');

    private const INSETS = array('inset', 'top', 'right', 'bottom', 'left', 'inset-block', 'inset-inline', 'inset-block-start', 'inset-block-end', 'inset-inline-start', 'inset-inline-end');

    public function __construct(
        private readonly ImageLowering $imageLowering,
        private readonly HtmlTransformerSession $session,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly SourceElementDescription $sourceElementDescription,
        private readonly StyleResolver $styleResolver
    ) {
    }

    /**
     * The blocks the layer lowers to: the image's figure, then each overlay.
     *
     * @param callable(DOMElement): (array<string, mixed>|null) $convertElement
     * @return list<array<string, mixed>>|null Null when the element is not such a layer.
     */
    public function blocks(DOMElement $layer, callable $convertElement): ?array
    {
        $parts = $this->layerParts($layer);
        if (null === $parts) {
            return null;
        }
        [$image, $overlays] = $parts;
        // The image keeps the figure it has as a fill image, so its rules
        // reach it as they always do; the figure also takes the layer's
        // classes, which state nothing but the pinned box.
        $figure = $this->imageLowering->convertImage($image, null, null, null, false, array_merge(
            array(SourceBlockAttributeProjector::SYNTHETIC_FILL_IMAGE_FIGURE_CLASS, SourceBlockAttributeProjector::FILL_LAYER_IMAGE_FIGURE_CLASS),
            SourceDom::tokens($layer->getAttribute('class'))
        ));
        if (null === $figure || 'core/image' !== ($figure['blockName'] ?? null)) {
            return null;
        }
        $blocks = array($figure);
        foreach ($overlays as $overlay) {
            $block = $convertElement($overlay);
            if (null !== $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /** @return array{0: DOMElement, 1: list<DOMElement>}|null */
    private function layerParts(DOMElement $layer): ?array
    {
        if (!in_array(strtolower($layer->tagName), self::LAYER_TAGS, true)
            || !$this->hasOnlyAttributes($layer, self::LAYER_ATTRIBUTES)
            || !$layer->parentNode instanceof DOMElement) {
            return null;
        }
        $image = null;
        $overlays = array();
        foreach ($layer->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if (null === $image) {
                    if ('img' !== strtolower($child->tagName)) {
                        return null;
                    }
                    $image = $child;
                    continue;
                }
                if (!in_array(strtolower($child->tagName), self::OVERLAY_TAGS, true)) {
                    return null;
                }
                $overlays[] = $child;
                continue;
            }
            if (XML_TEXT_NODE === $child->nodeType && '' !== trim((string) $child->textContent)) {
                return null;
            }
        }
        if (!$image instanceof DOMElement || $image->hasChildNodes()
            || !$this->hasOnlyAttributes($image, self::IMAGE_ATTRIBUTES)) {
            return null;
        }
        // A link, control or picture owns its own lowering of the media it wraps.
        for ($ancestor = $layer->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            if (in_array(strtolower($ancestor->tagName), array('a', 'button', 'picture', 'label'), true)) {
                return null;
            }
        }
        foreach (array_merge(array($layer, $image), $overlays) as $element) {
            if ($this->runtimeIslands->isRuntimeDomTarget($element)
                || array() !== SourceDom::eventMetadata($element)
                || array() !== $this->sourceElementDescription->interactiveAttributes($element)
                || '' !== SourceDom::attr($element, 'id')) {
                return null;
            }
        }
        if (array() !== $this->session->authorSelectorProjectionState()->semanticMarkersForElement($layer)
            || $this->hasCropFocus($image)
            || !$this->isPaintFreeFillLayer($layer)
            || !$this->fillsInFlow($image)) {
            return null;
        }
        foreach ($overlays as $overlay) {
            if (!$this->isPinnedToAllEdges($overlay)) {
                return null;
            }
        }

        return $this->selectorMatchingSurvivesSplice($layer, $image, $overlays) ? array($image, $overlays) : null;
    }

    /** @param array<int, string> $allowed */
    private function hasOnlyAttributes(DOMElement $element, array $allowed): bool
    {
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            // Engine bookkeeping on the source node is not authored identity.
            if (!in_array($name, $allowed, true) && !str_starts_with($name, 'data-blocks-engine-')) {
                return false;
            }
        }

        return true;
    }

    /**
     * At every viewport the layer states only its absolute position at four
     * zero insets, plus resets that give it no box of its own.
     */
    private function isPaintFreeFillLayer(DOMElement $layer): bool
    {
        if (!$this->isPinnedToAllEdges($layer)) {
            return false;
        }
        $parent = $layer->parentNode;
        $parentCustom = $parent instanceof DOMElement ? $this->styleResolver->authorDeclaredValuesAtAnyViewport($parent, null) : array();
        foreach ($this->styleResolver->authorDeclaredValuesAtAnyViewport($layer, null) as $property => $values) {
            if (str_starts_with($property, '--')) {
                // A custom property the layer restates as its parent does
                // reaches the overlays unchanged from the parent.
                if (($parentCustom[$property] ?? null) !== $values) {
                    return false;
                }
                continue;
            }
            if ('position' === $property || in_array($property, self::INSETS, true)
                || in_array($property, array('box-sizing', 'border-style', 'border-color', 'outline-color'), true)) {
                continue;
            }
            foreach ($values as $value) {
                if (!$this->isNeutral($property, CssValueInspector::comparable(CssValueInspector::withoutImportant($value)))) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isNeutral(string $property, string $value): bool
    {
        if (preg_match('/^(?:margin|padding)(?:-(?:top|right|bottom|left|block|inline|block-start|block-end|inline-start|inline-end))?$/', $property)) {
            return $this->statesNoLength($value);
        }
        if (preg_match('/^border(?:-(?:top|right|bottom|left))?(?:-width)?$/', $property)) {
            return $this->statesNoLength($value);
        }
        return match ($property) {
            'display' => 'block' === $value,
            'width', 'height' => in_array($value, array('auto', '100%'), true),
            'z-index' => 'auto' === $value,
            'opacity' => in_array($value, array('1', '100%'), true),
            'overflow', 'overflow-x', 'overflow-y' => 'visible' === $value,
            'transform', 'filter', 'backdrop-filter', 'box-shadow', 'clip-path', 'mask', 'mask-image', 'background-image' => 'none' === $value,
            'background-color' => in_array($value, array('transparent', 'rgba(0,0,0,0)', 'rgba(0, 0, 0, 0)'), true),
            default => false,
        };
    }

    /** At every viewport the element is absolutely positioned at four zero insets. */
    private function isPinnedToAllEdges(DOMElement $element): bool
    {
        $declared = $this->styleResolver->authorDeclaredValuesAtAnyViewport($element, array_merge(array('position'), self::INSETS));
        foreach ($declared['position'] ?? array() as $value) {
            if ('absolute' !== CssValueInspector::comparable(CssValueInspector::withoutImportant($value))) {
                return false;
            }
        }
        foreach (self::INSETS as $property) {
            foreach ($declared[$property] ?? array() as $value) {
                foreach (preg_split('/\s+/', CssValueInspector::comparable(CssValueInspector::withoutImportant($value))) ?: array() as $token) {
                    if (!in_array($token, self::ZERO, true)) {
                        return false;
                    }
                }
            }
        }
        $value = fn (string $property): string => $this->styleResolver->resolvedDeclaration($element, $property);
        if ('absolute' !== $value('position')) {
            return false;
        }
        $inset = $value('inset');
        foreach (array('top', 'right', 'bottom', 'left') as $side) {
            $edge = $value($side);
            if ('' === $edge) {
                $edge = $inset;
            }
            if (!in_array($edge, self::ZERO, true)) {
                return false;
            }
        }

        return true;
    }

    /** The image is in flow, at its full size, with no margin, at every viewport. */
    private function fillsInFlow(DOMElement $image): bool
    {
        $declared = $this->styleResolver->authorDeclaredValuesAtAnyViewport($image, array('position', 'float', 'width', 'height', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'max-height', 'min-height'));
        foreach ($declared['position'] ?? array() as $value) {
            if (!in_array(CssValueInspector::comparable(CssValueInspector::withoutImportant($value)), array('static', 'relative'), true)) {
                return false;
            }
        }
        foreach ($declared['float'] ?? array() as $value) {
            if ('none' !== CssValueInspector::comparable(CssValueInspector::withoutImportant($value))) {
                return false;
            }
        }
        foreach (array('width', 'height') as $axis) {
            if ('100%' !== $this->styleResolver->resolvedDeclaration($image, $axis)) {
                return false;
            }
            foreach ($declared[$axis] ?? array() as $value) {
                if (!in_array(CssValueInspector::comparable(CssValueInspector::withoutImportant($value)), array('100%', 'auto'), true)) {
                    return false;
                }
            }
        }
        foreach (array('margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left') as $property) {
            foreach ($declared[$property] ?? array() as $value) {
                if (!$this->statesNoLength(CssValueInspector::comparable(CssValueInspector::withoutImportant($value)))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Rehearse the splice on the source: the image takes the layer's place and
     * classes and the overlays follow it. The merged image must match exactly
     * the rules the layer or the image matched, and each overlay and its
     * descendants exactly the rules they matched.
     *
     * @param list<DOMElement> $overlays
     */
    private function selectorMatchingSurvivesSplice(DOMElement $layer, DOMElement $image, array $overlays): bool
    {
        $parent = $layer->parentNode;
        if (!$parent instanceof DOMElement) {
            return false;
        }
        $cache = $this->session->sourceStyleResolutionState()->selectorMatchCache;
        $tracked = array();
        foreach ($overlays as $overlay) {
            $tracked[] = $overlay;
            foreach (SourceDom::descendantElements($overlay) as $descendant) {
                $tracked[] = $descendant;
            }
        }
        $candidates = array();
        foreach (array_merge(array($layer, $image), $tracked) as $node) {
            foreach ($this->session->authorStyleRuleCandidates($node) as $rule) {
                $candidates[$rule['key']] = $rule;
            }
        }
        $matches = static fn (CssSelectorMatchCache $cache, DOMElement $node, array $rule): bool => (bool) ($rule['parsed']['supported'] ?? false)
            && $cache->matches($node, $rule['selector'], $rule['parsed'], true)['matches'];
        $before = array();
        foreach ($candidates as $key => $rule) {
            $before['image'][$key] = $matches($cache, $layer, $rule) || $matches($cache, $image, $rule);
            foreach ($tracked as $index => $node) {
                $before[$index][$key] = $matches($cache, $node, $rule);
            }
        }

        $imageClass = $image->getAttribute('class');
        $hadClass = $image->hasAttribute('class');
        $moved = array_merge(array($image), $overlays);
        $restore = array_map(static fn (DOMElement $node): ?\DOMNode => $node->nextSibling, $moved);
        $parent->insertBefore($image, $layer);
        foreach ($overlays as $overlay) {
            $parent->insertBefore($overlay, $layer);
        }
        $parent->removeChild($layer);
        $image->setAttribute('class', SourceDom::mergeClassNames($layer->getAttribute('class'), $imageClass));

        $after = new CssSelectorMatchCache();
        foreach (array_merge(array($image), $tracked) as $node) {
            foreach ($this->session->authorStyleRuleCandidates($node, $after) as $rule) {
                $candidates[$rule['key']] = $rule;
            }
        }
        $survives = true;
        foreach ($candidates as $key => $rule) {
            if (($before['image'][$key] ?? false) !== $matches($after, $image, $rule)) {
                $survives = false;
                break;
            }
            foreach ($tracked as $index => $node) {
                if (($before[$index][$key] ?? false) !== $matches($after, $node, $rule)) {
                    $survives = false;
                    break 2;
                }
            }
        }

        $parent->insertBefore($layer, $image);
        for ($index = count($moved) - 1; $index >= 0; $index--) {
            $layer->insertBefore($moved[$index], $restore[$index]);
        }
        if ($hadClass) {
            $image->setAttribute('class', $imageClass);
        } else {
            $image->removeAttribute('class');
        }

        return $survives;
    }

    /**
     * Whether a box-edge value (padding, margin, border or its width) adds no
     * length: every length it states is zero.
     */
    private function statesNoLength(string $value): bool
    {
        if ('' === $value) {
            return true;
        }
        foreach (preg_split('/\s+/', $value) ?: array() as $token) {
            if (1 === preg_match('/^-?(?:\d+|\d*\.\d+)(?:[a-z%]+)?$/', $token) && 0.0 !== (float) $token) {
                return false;
            }
            if (in_array($token, array('thin', 'medium', 'thick'), true) || str_contains($token, '(')) {
                return false;
            }
        }

        return true;
    }

    /** An object-position crop core/image cannot carry on its own. */
    private function hasCropFocus(DOMElement $image): bool
    {
        $declarations = $this->styleResolver->imageShapeDeclarations($image);
        $position = strtolower(trim(CssValueInspector::withoutImportant((string) ($declarations['object-position']['value'] ?? ''))));

        return '' !== $position && !in_array($position, array('center', 'center center', '50% 50%', 'unset', 'initial', 'revert', 'revert-layer'), true);
    }
}
