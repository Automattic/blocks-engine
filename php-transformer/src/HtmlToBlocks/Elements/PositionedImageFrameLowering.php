<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * A ratio frame its lone image fills from the inside is that image's figure.
 *
 * Image components commonly render `<span class="relative aspect-square
 * w-full"><img class="absolute inset-0 w-full h-full object-cover"></span>`:
 * the frame owns the box (its ratio sizes it) and the image is taken out of
 * flow to paint exactly that box. core/image models the pair as `figure >
 * img`, so the frame lowers as the image's figure: the figure takes the
 * frame's classes and identity while the image keeps its own rules, and the
 * same absolutely positioned image still fills its positioned figure. Lowered
 * as a Group around an Image instead, the frame is a wrapper an editor has to
 * click through without it meaning anything.
 *
 * Only the author cascade decides: the frame must be a positioned box whose
 * ratio is its only sizing, with no padding or border between it and the
 * image, and the image must be absolutely positioned at all four zero insets
 * with a 100% width and height. Frames with identity, behavior, selectors
 * bound to them, a link, button or picture around them, or a crop focus core
 * cannot carry keep both boxes.
 */
final class PositionedImageFrameLowering
{
    private const FRAME_TAGS = array('div', 'span');

    private const FRAME_ATTRIBUTES = array('class', 'style');

    private const IMAGE_ATTRIBUTES = array(
        'src', 'srcset', 'sizes', 'alt', 'title', 'loading', 'decoding', 'fetchpriority',
        'width', 'height', 'class', 'style', 'crossorigin', 'referrerpolicy',
    );

    private const ZERO = array('0', '0px', '0%');

    public function __construct(
        private readonly ImageLowering $imageLowering,
        private readonly HtmlTransformerSession $session,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly SourceElementDescription $sourceElementDescription,
        private readonly StyleResolver $styleResolver
    ) {
    }

    /** @return array<string, mixed>|null The core/image whose figure is the frame. */
    public function block(DOMElement $frame): ?array
    {
        $image = $this->filledFrameImage($frame);
        if (null === $image) {
            return null;
        }
        // The frame's ratio sizes this figure: the image inside is out of
        // flow, so the ratio cannot move down onto it as it does for an image
        // that carried the ratio class itself.
        $block = $this->imageLowering->convertImage($image, $frame, null, null, false, array(SourceBlockAttributeProjector::RATIO_FRAME_IMAGE_FIGURE_CLASS));

        return null !== $block && 'core/image' === ($block['blockName'] ?? null) ? $block : null;
    }

    private function filledFrameImage(DOMElement $frame): ?DOMElement
    {
        if (!in_array(strtolower($frame->tagName), self::FRAME_TAGS, true)
            || !$this->hasOnlyAttributes($frame, self::FRAME_ATTRIBUTES)) {
            return null;
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
        if (!$image instanceof DOMElement || $image->hasChildNodes()
            || !$this->hasOnlyAttributes($image, self::IMAGE_ATTRIBUTES)) {
            return null;
        }
        // A link, control or picture owns its own lowering of the media it wraps.
        for ($ancestor = $frame->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            if (in_array(strtolower($ancestor->tagName), array('a', 'button', 'picture'), true)) {
                return null;
            }
        }
        foreach (array($frame, $image) as $element) {
            if ($this->runtimeIslands->isRuntimeDomTarget($element)
                || array() !== SourceDom::eventMetadata($element)
                || array() !== $this->sourceElementDescription->interactiveAttributes($element)) {
                return null;
            }
        }
        if (array() !== $this->session->authorSelectorProjectionState()->semanticMarkersForElement($frame)
            || $this->hasCropFocus($image)) {
            return null;
        }

        return $this->frameIsImageBox($frame, $image) ? $image : null;
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
     * The frame is a positioned ratio box with no padding or border, and the
     * image is pinned to all four of its edges at its full size.
     */
    private function frameIsImageBox(DOMElement $frame, DOMElement $image): bool
    {
        $value = fn (DOMElement $element, string $property): string => $this->styleResolver->resolvedDeclaration($element, $property);
        if (!in_array($value($frame, 'position'), array('relative', 'absolute'), true)
            || 1 !== preg_match('#^[0-9.]+\s*(?:/\s*[0-9.]+)?$#', $value($frame, 'aspect-ratio'))
            || 'absolute' !== $value($image, 'position')) {
            return false;
        }
        // The ratio alone must size the frame's block axis.
        foreach (array('height' => array('', 'auto'), 'min-height' => array('', 'auto', '0', '0px'), 'max-height' => array('', 'none')) as $property => $unset) {
            if (!in_array($value($frame, $property), $unset, true)) {
                return false;
            }
        }
        foreach (array(array($frame, 'padding'), array($frame, 'border'), array($frame, 'border-width'), array($image, 'margin')) as [$element, $shorthand]) {
            if (!$this->statesNoLength($value($element, $shorthand))) {
                return false;
            }
        }
        $inset = $value($image, 'inset');
        foreach (array('top', 'right', 'bottom', 'left') as $side) {
            foreach (array(array($frame, 'padding-' . $side), array($frame, 'border-' . $side), array($frame, 'border-' . $side . '-width'), array($image, 'margin-' . $side)) as [$element, $property]) {
                if (!$this->statesNoLength($value($element, $property))) {
                    return false;
                }
            }
            $edge = $value($image, $side);
            if ('' === $edge) {
                $edge = $inset;
            }
            if (!in_array($edge, self::ZERO, true)) {
                return false;
            }
        }
        foreach (array('width', 'height') as $axis) {
            if ('100%' !== $value($image, $axis)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a box-edge value (padding, margin, border or its width) adds no
     * length: every length it states is zero. `border:0 solid #e5e7eb` (a
     * reset that only names a style and color) adds none.
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
