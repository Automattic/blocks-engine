<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use DOMElement;

/**
 * A box whose only content is an in-flow image filling it is that image's
 * figure.
 *
 * Card media are authored as `<div class="aspect-[16/9] overflow-hidden
 * bg-muted"><img class="h-full w-full …"></div>`: the box sets the ratio,
 * clips and paints, and the image fills it. core/image saves exactly that
 * shape, a `<figure>` around an `<img>`, so the box becomes the figure (with
 * its classes, paint, clipping and capture identity) and the image keeps its
 * own rules beneath it, as {@see ImageLowering::convertImage()} already does
 * for a source `<figure>` and for a linked sized box. Kept as a Group, the
 * box is a wrapper an editor has to click through around the Image block.
 *
 * Only on a box-tree evidence route, and only when the capture proves the
 * image's border box is the box's at every viewport. Any identity, runtime
 * handle, interaction, out-of-flow image, or rule naming the box by its tag
 * keeps the Group.
 */
final class CapturedImageFrameLowering
{
    private const IMAGE_ATTRIBUTES = array(
        'src', 'srcset', 'sizes', 'alt', 'title', 'loading', 'decoding', 'fetchpriority',
        'width', 'height', 'class', 'style', 'crossorigin', 'referrerpolicy',
    );

    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly ImageLowering $imageLowering
    ) {
    }

    /** @return array<string, mixed>|null The Image block whose figure is the box, or null to keep the box. */
    public function blockFromElement(DOMElement $frame): ?array
    {
        $image = $this->filledImage($frame);
        if (null === $image) {
            return null;
        }
        $block = $this->imageLowering->convertImage($image, $frame);
        return null !== $block && 'core/image' === ($block['blockName'] ?? null) ? $block : null;
    }

    private function filledImage(DOMElement $frame): ?DOMElement
    {
        if (array() === $this->session->boxTreeViewports() || 'div' !== strtolower($frame->tagName)) {
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
            if (XML_TEXT_NODE !== $child->nodeType && XML_COMMENT_NODE !== $child->nodeType) {
                return null;
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
        if ($this->runtimeIslands->isRuntimeDomTarget($frame) || $this->runtimeIslands->isRuntimeDomTarget($image)
            || $this->insideInteractiveContent($frame) || !$this->selectorsSurvive($frame)) {
            return null;
        }
        $frameObservations = $this->session->boxTreeEvidence($frame);
        $imageObservations = $this->session->boxTreeEvidence($image);
        if (array() === $frameObservations || array_keys($frameObservations) !== array_keys($imageObservations)) {
            return null;
        }
        foreach ($frameObservations as $viewport => $observation) {
            $own = $imageObservations[$viewport];
            $frameStyle = $observation['used_styles'] ?? array();
            $style = $own['used_styles'] ?? array();
            if (!empty($observation['interaction_states']) || !empty($observation['pseudo']) || !empty($own['pseudo'])
                || 'block' !== ($frameStyle['display'] ?? null)
                || !in_array($style['display'] ?? null, array('block', 'inline', 'inline-block'), true)
                || !in_array($style['position'] ?? 'static', array('static', 'relative'), true)
                || !in_array($frameStyle['position'] ?? 'static', array('static', 'relative'), true)) {
                return null;
            }
            foreach (array('x', 'y', 'width', 'height') as $axis) {
                if (!isset($own['border_box'][$axis], $observation['border_box'][$axis])
                    || abs((float) $own['border_box'][$axis] - (float) $observation['border_box'][$axis]) > 0.5) {
                    return null;
                }
            }
        }

        return $image;
    }

    /**
     * Inside a link or button the image lowers as interactive content (a
     * linked or inline figure), which the link carrier owns; the box stays.
     */
    private function insideInteractiveContent(DOMElement $frame): bool
    {
        for ($node = $frame->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (in_array(strtolower($node->tagName), array('a', 'button', 'label', 'summary'), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The box turns into a `figure`: a rule that names it by its `div` type
     * would stop selecting it.
     */
    private function selectorsSurvive(DOMElement $frame): bool
    {
        foreach ($this->session->sourceStyleResolutionState()->matchingRules($frame, 'static-conditional') as $rule) {
            $selector = (string) ($rule['selector'] ?? '');
            foreach (preg_split('/\s*,\s*/', $selector) ?: array() as $part) {
                $compounds = preg_split('/\s*[\s>+~]\s*/', trim($part)) ?: array();
                $subject = (string) end($compounds);
                if (1 === preg_match('/^div(?![\w-])/i', $subject)) {
                    return false;
                }
            }
        }

        return true;
    }
}
