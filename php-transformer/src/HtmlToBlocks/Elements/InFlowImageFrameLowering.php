<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * A block box that an in-flow image fills exactly is the image's figure.
 *
 * Card media emit `<div class="aspect-[16/9] overflow-hidden bg-secondary">
 * <img class="h-full w-full hover:scale-105"></div>`: the frame sizes, clips
 * and backs the image, and the image fills it. core/image already models that
 * pair as `figure > img`, the figure owning the box and the image its own
 * rules. Lowered as a Group around an Image instead, the frame becomes a
 * wrapper an editor has to click through without it meaning anything.
 *
 * On a box-tree evidence route, when the capture proves the frame and its
 * lone in-flow image share one border box at every viewport, the frame lowers
 * as the image's figure, exactly as a source `<figure>` does: the figure takes
 * the frame's classes, style and identity, and the image keeps its own rules
 * through the image wrapper projection. A frame with identity or behavior of
 * its own, a link or button around it, or an image inset within it keeps both
 * boxes.
 */
final class InFlowImageFrameLowering
{
    private const FRAME_TAGS = array('div');

    private const IMAGE_ATTRIBUTES = array(
        'src', 'srcset', 'sizes', 'alt', 'title', 'loading', 'decoding', 'fetchpriority',
        'width', 'height', 'class', 'style', 'crossorigin', 'referrerpolicy',
    );

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
        $block = $this->imageLowering->convertImage($image, $frame);

        return null !== $block && 'core/image' === ($block['blockName'] ?? null) ? $block : null;
    }

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
            if ('' !== trim((string) $child->textContent)) {
                return null;
            }
        }
        if (!$image instanceof DOMElement || $image->hasChildNodes()) {
            return null;
        }
        foreach (BoxTree::authoredAttributes($image) as $attribute) {
            if (!in_array(strtolower($attribute->name), self::IMAGE_ATTRIBUTES, true)) {
                return null;
            }
        }
        // A link or control owns its own lowering of the media it wraps.
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

        return $this->capturedBoxIsImageBox($frame, $image) ? $image : null;
    }

    /**
     * The capture observed the frame as one block box and the in-flow image
     * with the frame's border box at every viewport, with no generated
     * content on either.
     */
    private function capturedBoxIsImageBox(DOMElement $frame, DOMElement $image): bool
    {
        $frameObservations = $this->session->boxTreeEvidence($frame);
        $imageObservations = $this->session->boxTreeEvidence($image);
        if (array() === $frameObservations || array_keys($frameObservations) !== array_keys($imageObservations)) {
            return false;
        }
        foreach ($frameObservations as $viewport => $observation) {
            $own = $imageObservations[$viewport];
            if (!empty($observation['pseudo']) || !empty($own['pseudo'])
                || 'block' !== strtolower(trim((string) ($observation['used_styles']['display'] ?? '')))
                || !in_array(strtolower(trim((string) ($own['used_styles']['position'] ?? 'static'))), array('static', 'relative'), true)) {
                return false;
            }
            foreach (array('x', 'y', 'width', 'height') as $axis) {
                $frameBox = $observation['border_box'][$axis] ?? null;
                $imageBox = $own['border_box'][$axis] ?? null;
                if (!is_numeric($frameBox) || !is_numeric($imageBox) || abs((float) $frameBox - (float) $imageBox) >= 1.0) {
                    return false;
                }
            }
        }

        return true;
    }

    /** An object-position crop core/image cannot carry on its own. */
    private function hasCropFocus(DOMElement $image): bool
    {
        $declarations = $this->styleResolver->imageShapeDeclarations($image);
        $scale = strtolower(CssValueInspector::withoutImportant((string) ($declarations['object-fit']['value'] ?? '')));
        if (!in_array($scale, array('cover', 'contain'), true)) {
            return false;
        }
        $position = strtolower(trim(CssValueInspector::withoutImportant((string) ($declarations['object-position']['value'] ?? ''))));

        return '' !== $position && !in_array($position, array('center', 'center center', '50% 50%', 'unset', 'initial', 'revert', 'revert-layer'), true);
    }
}
