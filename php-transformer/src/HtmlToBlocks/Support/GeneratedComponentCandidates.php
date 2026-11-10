<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use DOMElement;

/**
 * Marks the deep repeated-content and custom-element hosts that become
 * generated reusable component candidates before conversion.
 */
final class GeneratedComponentCandidates
{
    private const GENERATED_COMPONENT_MIN_SOURCE_DEPTH = 14;

    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly SourceElementClassifier $sourceElementClassifier
    ) {
    }

    public function collectGeneratedComponentCandidates(DOMElement $element, int $depth = 0): void
    {
        if (self::GENERATED_COMPONENT_MIN_SOURCE_DEPTH <= $depth
            && ('div' === strtolower($element->tagName) || str_contains(strtolower($element->tagName), '-'))
            && ($this->sourceElementClassifier->hasRepeatedDirectChildTags($element) || str_contains(strtolower($element->tagName), '-'))
            && ($this->session->fallbackEmitter()->isRepeatableContentComponent($element) || $this->session->fallbackEmitter()->isSafeCustomElementHost($element))
            && ! $this->session->fallbackEmitter()->containsProductGrid($element)
        ) {
            $this->session->reusableComponentState()->markGeneratedCandidate((string) $element->getNodePath());
            return;
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->collectGeneratedComponentCandidates($child, $depth + 1);
            }
        }
    }
}
