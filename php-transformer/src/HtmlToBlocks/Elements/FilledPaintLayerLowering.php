<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Closure;
use DOMElement;

/**
 * An empty colour fill that covers an unpainted layer is the layer's paint.
 *
 * Site builders emit a section background as `<div class="background-layer">
 * <div id="…" class="background"></div></div>`: the layer is positioned to
 * cover its section and paints nothing, and its only child stretches to the
 * same box to paint one solid colour. To a reader that is one coloured box.
 * Kept as authored, it becomes a Group whose only content is another, empty,
 * Group: a wrapper an editor has to click through without it meaning anything.
 *
 * On a box-tree evidence route, when the capture proves the fill's border box
 * is the layer's border box at every viewport, the fill paints only a solid
 * background colour (no image, border, shadow, effect, or painting pseudo
 * element), and the layer paints nothing itself, the layer takes the fill's
 * captured colour and the fill is dropped. The layer keeps its own classes,
 * style and position, so its box is unchanged. Anything else the fill carries
 * (content, interaction, references to its id, a varying colour) keeps both.
 */
final class FilledPaintLayerLowering
{
    private const LAYER_TAGS = array('div', 'span');

    private const TRANSPARENT = array('transparent', 'rgba(0, 0, 0, 0)', 'rgba(0,0,0,0)', 'color(srgb 0 0 0 / 0)');

    /**
     * @param Closure(DOMElement): array<string, array<string, mixed>> $evidence
     *        Captured observations of a source element, keyed by viewport.
     * @param Closure(DOMElement): list<string> $selectors
     *        Author selectors whose rules may apply to a source element.
     */
    public function __construct(
        private readonly Closure $evidence,
        private readonly Closure $selectors
    ) {
    }

    /**
     * @return array<string, array<string, array<string, mixed>>> The layers'
     *         restated viewport observations by node id: each now paints its
     *         fill's captured colour.
     */
    public function lower(DOMElement $root): array
    {
        $observations = array();
        $fills = array();
        foreach ($root->getElementsByTagName('*') as $element) {
            if ($element instanceof DOMElement && null !== $this->fillColor($element)) {
                $fills[] = $element;
            }
        }
        foreach ($fills as $fill) {
            $layer = $fill->parentNode;
            $color = $this->fillColor($fill);
            if (!$layer instanceof DOMElement || null === $color) {
                continue;
            }
            // The capture proved the layer paints nothing of its own, so its
            // observed paint becomes the fill's colour at every viewport.
            $restated = array();
            foreach (($this->evidence)($layer) as $viewport => $observation) {
                $observation['used_styles']['background-color'] = $color;
                $restated[$viewport] = $observation;
            }
            if ($layer->hasAttribute(BoxTree::NODE_ATTRIBUTE)) {
                $observations[$layer->getAttribute(BoxTree::NODE_ATTRIBUTE)] = $restated;
            }
            $layer->removeChild($fill);
        }

        return $observations;
    }

    /** The solid colour this empty fill paints over its layer's whole box. */
    private function fillColor(DOMElement $fill): ?string
    {
        $layer = $fill->parentNode;
        if (!$layer instanceof DOMElement || !in_array(strtolower($layer->tagName), self::LAYER_TAGS, true)
            || !in_array(strtolower($fill->tagName), self::LAYER_TAGS, true)
            || $fill->hasChildNodes()) {
            return null;
        }
        foreach ($layer->childNodes as $child) {
            if ($child instanceof DOMElement ? $child !== $fill : (XML_TEXT_NODE === $child->nodeType && '' !== trim((string) $child->textContent))) {
                return null;
            }
        }
        foreach (array($layer, $fill) as $element) {
            foreach (BoxTree::authoredAttributes($element) as $attribute) {
                $name = strtolower($attribute->name);
                if (!in_array($name, array('class', 'style', 'id'), true) && !str_starts_with($name, 'data-')) {
                    return null;
                }
            }
        }
        if ($layer->hasAttribute('id') || !$this->unreferenced($fill) || !$this->selectorsSurvive($layer)) {
            return null;
        }

        $layerObservations = ($this->evidence)($layer);
        $fillObservations = ($this->evidence)($fill);
        if (array() === $layerObservations || array_keys($layerObservations) !== array_keys($fillObservations)) {
            return null;
        }
        $colors = array();
        foreach ($layerObservations as $viewport => $observation) {
            $own = $fillObservations[$viewport];
            $layerStyle = $observation['used_styles'] ?? array();
            $style = $own['used_styles'] ?? array();
            if (!empty($observation['pseudo']) || !$this->pseudoPaintsNothing($own['pseudo'] ?? array())
                || !$this->paintsNothing($layerStyle) || !$this->neutralEffects($style)
                || 'visible' !== ($style['visibility'] ?? 'visible') || 'auto' !== ($style['z-index'] ?? 'auto')
                || in_array($style['display'] ?? 'none', array('none', 'contents'), true)
                || 'none' !== ($style['background-image'] ?? 'none')) {
                return null;
            }
            foreach (array('x', 'y', 'width', 'height') as $axis) {
                if (!isset($own['border_box'][$axis], $observation['border_box'][$axis])
                    || abs((float) $own['border_box'][$axis] - (float) $observation['border_box'][$axis]) > 0.01) {
                    return null;
                }
            }
            $color = $this->solidColor((string) ($style['background-color'] ?? ''));
            if (null === $color) {
                return null;
            }
            $colors[$color] = true;
        }

        return 1 === count($colors) ? (string) array_key_first($colors) : null;
    }

    /** @param array<string, mixed> $style */
    private function paintsNothing(array $style): bool
    {
        return in_array($style['background-color'] ?? 'rgba(0, 0, 0, 0)', self::TRANSPARENT, true)
            && 'none' === ($style['background-image'] ?? 'none')
            && $this->neutralEffects($style);
    }

    /** No border, shadow, outline, rounding or effect that would move or change the paint. @param array<string, mixed> $style */
    private function neutralEffects(array $style): bool
    {
        foreach (array('border-top', 'border-right', 'border-bottom', 'border-left') as $border) {
            if (!str_starts_with((string) ($style[$border] ?? '0px'), '0px')) {
                return false;
            }
        }
        if (!str_starts_with((string) ($style['outline'] ?? '0px'), '0px') && !str_contains((string) ($style['outline'] ?? ''), 'none')) {
            return false;
        }
        foreach (array('border-radius' => '0px', 'box-shadow' => 'none', 'filter' => 'none', 'backdrop-filter' => 'none', 'opacity' => '1', 'mix-blend-mode' => 'normal', 'transform' => 'none', 'clip-path' => 'none', 'mask-image' => 'none', 'animation-name' => 'none') as $property => $neutral) {
            if ($neutral !== ($style[$property] ?? $neutral)) {
                return false;
            }
        }

        return true;
    }

    /** @param mixed $pseudo */
    private function pseudoPaintsNothing(mixed $pseudo): bool
    {
        if (!is_array($pseudo)) {
            return false;
        }
        foreach ($pseudo as $box) {
            if (!is_array($box) || !$this->paintsNothing(is_array($box['used_styles'] ?? null) ? $box['used_styles'] : array('background-color' => 'unknown'))
                || '' !== trim((string) ($box['content'] ?? ''), " \"'")) {
                return false;
            }
        }

        return true;
    }

    /** A captured opaque or translucent solid colour as an `rgb()`/`rgba()` value. */
    private function solidColor(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (1 === preg_match('/^rgba?\(\s*\d{1,3},\s*\d{1,3},\s*\d{1,3}(?:,\s*(?:0?\.\d+|1|0))?\s*\)$/', $value)) {
            return in_array($value, self::TRANSPARENT, true) || 1 === preg_match('/,\s*0\s*\)$/', $value) ? null : $value;
        }
        if (1 !== preg_match('/^color\(srgb\s+([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)(?:\s*\/\s*([0-9.]+))?\s*\)$/', $value, $parts)) {
            return null;
        }
        $channels = array();
        foreach (array(1, 2, 3) as $index) {
            $channel = (float) $parts[$index];
            if ($channel < 0 || $channel > 1) {
                return null;
            }
            $channels[] = (int) round($channel * 255);
        }
        $alpha = isset($parts[4]) && '' !== $parts[4] ? (float) $parts[4] : 1.0;
        if ($alpha <= 0 || $alpha > 1) {
            return null;
        }

        return $alpha < 1
            ? sprintf('rgba(%d, %d, %d, %s)', $channels[0], $channels[1], $channels[2], rtrim(rtrim(sprintf('%.3F', $alpha), '0'), '.'))
            : sprintf('rgb(%d, %d, %d)', ...$channels);
    }

    /** Nothing in the document points at the fill by its id. */
    private function unreferenced(DOMElement $fill): bool
    {
        $id = trim((string) $fill->getAttribute('id'));
        if ('' === $id) {
            return true;
        }
        $document = $fill->ownerDocument;
        if (null === $document) {
            return false;
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes ?? array() as $attribute) {
                $name = strtolower($attribute->name);
                if ('id' === $name || ('class' === $name)) {
                    continue;
                }
                $value = (string) $attribute->value;
                if (str_contains($value, '#' . $id) || (in_array($name, array('for', 'aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns', 'list', 'form', 'headers'), true) && in_array($id, preg_split('/\s+/', $value) ?: array(), true))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Dropping the fill leaves the layer empty: a selector that depends on
     * the layer's contents would select differently once the fill is gone.
     * The fill has no siblings or descendants, so no other selector reaches
     * an element through it, and its own rules paint only the captured colour.
     */
    private function selectorsSurvive(DOMElement $layer): bool
    {
        foreach (($this->selectors)($layer) as $selector) {
            if (1 === preg_match('/:(?:empty|blank|has\()/i', $selector)) {
                return false;
            }
        }

        return true;
    }
}
