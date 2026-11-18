<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Closure;
use DOMElement;

/**
 * A sizing frame around an image that fills it is the image's own box.
 *
 * Image placeholders emit `<span class="aspect-square w-full …"><img
 * class="absolute inset-0 h-full w-full object-cover"></span>`: the frame
 * carries the image's authored size and motion, and the image is positioned
 * absolutely to cover it exactly. To a reader that is one image. Kept as
 * authored, the frame becomes a Group around an Image block, a wrapper an
 * editor has to click through without it meaning anything.
 *
 * On a box-tree evidence route, when an explicit aspect ratio sizes the frame
 * and the capture proves the image's border box is the frame's border box
 * at every viewport, the image takes the
 * frame's place: it keeps its own source and description, and carries the
 * frame's classes, inline style and capture identity, so the frame's box
 * geometry and selector identity reach the image block. Any authored
 * difference the frame cannot carry (identity, interaction, paint, borders,
 * fit, structural or type-scoped selectors) keeps both boxes.
 */
final class FilledImageFrameLowering
{
    private const FRAME_TAGS = array('span', 'div');

    private const IMAGE_ATTRIBUTES = array(
        'src', 'srcset', 'sizes', 'alt', 'title', 'loading', 'decoding', 'fetchpriority',
        'width', 'height', 'class', 'style', 'crossorigin', 'referrerpolicy',
    );

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

    public function lower(DOMElement $root): void
    {
        $frames = array();
        foreach ($root->getElementsByTagName('img') as $image) {
            $frame = $image->parentNode;
            if ($frame instanceof DOMElement && null !== $this->filledFrameImage($frame)) {
                $frames[] = $frame;
            }
        }
        foreach ($frames as $frame) {
            $image = $this->filledFrameImage($frame);
            if (null === $image) {
                continue;
            }
            $display = $this->frameDisplay($frame);
            foreach (array('class', 'style', BoxTree::NODE_ATTRIBUTE) as $name) {
                $image->removeAttribute($name);
                if ($frame->hasAttribute($name)) {
                    $image->setAttribute($name, $frame->getAttribute($name));
                }
            }
            // The frame's box is block-level by its tag's default (a `div`) or
            // a rule the image now carries. An `<img>` is inline by default,
            // so a frame whose display came from its tag would become an
            // inline image sitting on a line box, gaining a baseline strut
            // below it the frame never had. The capture proves the frame's
            // display is the same at every viewport, so stating it on the
            // image keeps the frame's box; the frame's own inline style still
            // wins over it.
            if (!in_array($display, array('inline', 'inline-block'), true)) {
                $style = trim((string) $image->getAttribute('style'));
                $image->setAttribute('style', 'display:' . $display . ('' === $style ? '' : ';' . $style));
            }
            $frame->parentNode?->replaceChild($image, $frame);
        }
    }

    /** The image filling this frame, when the capture proves the frame is its box. */
    private function filledFrameImage(DOMElement $frame): ?DOMElement
    {
        if (!in_array(strtolower($frame->tagName), self::FRAME_TAGS, true)) {
            return null;
        }
        foreach (BoxTree::authoredAttributes($frame) as $attribute) {
            if (!in_array(strtolower($attribute->name), array('class', 'style'), true)) {
                return null;
            }
        }
        $image = null;
        foreach ($frame->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if (null !== $image || 'img' !== strtolower($child->tagName)) {
                    return null;
                }
                $image = $child;
                continue;
            }
            if (XML_TEXT_NODE === $child->nodeType && '' !== trim((string) $child->textContent)) {
                return null;
            }
        }
        if (null === $image || $image->hasChildNodes()) {
            return null;
        }
        foreach (BoxTree::authoredAttributes($image) as $attribute) {
            if (!in_array(strtolower($attribute->name), self::IMAGE_ATTRIBUTES, true)) {
                return null;
            }
        }
        if (!$this->selectorsSurvive($frame, $image)) {
            return null;
        }

        $frameObservations = ($this->evidence)($frame);
        $imageObservations = ($this->evidence)($image);
        if (array() === $frameObservations || array_keys($frameObservations) !== array_keys($imageObservations)) {
            return null;
        }
        // The image restates one display for the frame's box, so the frame
        // must have that one box at every viewport.
        if (null === $this->frameDisplay($frame)) {
            return null;
        }
        foreach ($frameObservations as $viewport => $observation) {
            $own = $imageObservations[$viewport];
            $frameStyle = $observation['used_styles'] ?? array();
            $style = $own['used_styles'] ?? array();
            if (!empty($observation['pseudo']) || !empty($own['pseudo'])
                || 'absolute' !== ($style['position'] ?? null)
                || 'static' === ($frameStyle['position'] ?? 'static')
                || !$this->hasAspectRatio($frameStyle)) {
                return null;
            }
            // The frame carries no paint of its own the image block would gain
            // or lose: its background would sit under the image, and a border,
            // shadow or outline would move off the frame's box.
            foreach (array('background-color' => 'rgba(0, 0, 0, 0)', 'background-image' => 'none', 'box-shadow' => 'none') as $property => $neutral) {
                if ($neutral !== ($frameStyle[$property] ?? $neutral)) {
                    return null;
                }
            }
            // The image paints itself into the frame's box: no border, offset,
            // rounding, transform or effect of its own.
            foreach (array('border-top', 'border-right', 'border-bottom', 'border-left') as $border) {
                foreach (array($frameStyle, $style) as $styles) {
                    if (!str_starts_with((string) ($styles[$border] ?? '0px'), '0px')) {
                        return null;
                    }
                }
            }
            foreach (array('border-radius' => '0px', 'transform' => 'none', 'filter' => 'none', 'opacity' => '1', 'box-shadow' => 'none', 'clip-path' => 'none', 'mix-blend-mode' => 'normal') as $property => $neutral) {
                if ($neutral !== ($style[$property] ?? $neutral)) {
                    return null;
                }
            }
            // The image fits its content into the frame's box the way the frame
            // declares it, so carrying the frame's classes keeps the fit.
            foreach (array('object-fit', 'object-position') as $property) {
                if (($style[$property] ?? null) !== ($frameStyle[$property] ?? null)) {
                    return null;
                }
            }
            foreach (array('x', 'y', 'width', 'height') as $axis) {
                if (!isset($own['border_box'][$axis], $observation['border_box'][$axis])
                    || abs((float) $own['border_box'][$axis] - (float) $observation['border_box'][$axis]) > 0.01) {
                    return null;
                }
            }
        }

        return $image;
    }

    /**
     * The frame's captured display when it is one box-generating value at
     * every viewport; null when it varies, is absent, or generates no box.
     */
    private function frameDisplay(DOMElement $frame): ?string
    {
        $displays = array();
        foreach (($this->evidence)($frame) as $observation) {
            $displays[] = strtolower(trim((string) ($observation['used_styles']['display'] ?? '')));
        }
        $displays = array_values(array_unique($displays));
        if (1 !== count($displays) || 1 !== preg_match('/^[a-z-]+(?: [a-z-]+)*$/', $displays[0])
            || in_array($displays[0], array('none', 'contents'), true)) {
            return null;
        }

        return $displays[0];
    }

    /**
     * Coincident boxes do not prove equivalent sizing: a percentage-height
     * container depends on its parent, whereas an image has an intrinsic ratio.
     * Require an explicit ratio before replacing that container with an image.
     *
     * @param array<string, mixed> $style
     */
    private function hasAspectRatio(array $style): bool
    {
        $ratio = (string) ($style['aspect-ratio'] ?? '');
        return 1 === preg_match('/^([0-9]*\.?[0-9]+)\s*\/\s*([0-9]*\.?[0-9]+)$/', $ratio, $parts)
            && (float) $parts[1] > 0
            && (float) $parts[2] > 0;
    }

    /**
     * The merged image matches the frame's class and pseudo-class selectors.
     * A selector that names the frame's type, or reaches either box through a
     * combinator, would select differently once the frame is gone.
     */
    private function selectorsSurvive(DOMElement $frame, DOMElement $image): bool
    {
        foreach (array($frame, $image) as $element) {
            foreach (($this->selectors)($element) as $selector) {
                $subject = trim((string) preg_replace('/::?[a-z-]+(?:\([^)]*\))?/i', '', $selector));
                if ('' === $subject || '*' === $subject) {
                    continue;
                }
                if (preg_match('/[\s>+~]/', $subject)) {
                    return false;
                }
                if ($element === $frame && preg_match('/^(?:span|div)\b/i', $subject)) {
                    return false;
                }
            }
        }

        return true;
    }
}
