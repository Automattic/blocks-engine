<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use DOMElement;

/**
 * Detects a wrapper whose source classes still own author CSS, either on
 * the wrapper itself or as an ancestor boundary in a descendant rule.
 */
final class StyledSourceClassBoundary
{
    public function __construct(
        private readonly HtmlTransformerSession $session
    ) {
    }

    /**
     * Presentation attributes omit class-owned CSS. A wrapper can therefore
     * look empty while still owning inherited declarations or the ancestor
     * boundary needed by a descendant rule.
     */
    public function hasStyledSourceClassBoundary(DOMElement $element): bool
    {
        $classes = SourceDom::classNames($element);
        if (array() === $classes) return false;

        $nodes = array($element);
        foreach ($element->getElementsByTagName('*') as $descendant) {
            $nodes[] = $descendant;
        }
        foreach ($nodes as $node) {
            foreach ($this->session->authorStyleRuleCandidates($node) as $candidate) {
                if (array() === $candidate['declarations'] || ! ($candidate['parsed']['supported'] ?? false)) continue;
                $compounds = $candidate['parsed']['compounds'];
                // On descendants the source class must be an ancestor, not
                // merely another class on the selector's subject.
                if ($node !== $element) array_pop($compounds);
                $namesClass = false;
                foreach ($compounds as $compound) {
                    if (array() !== array_intersect($classes, $compound['classes'])) {
                        $namesClass = true;
                        break;
                    }
                }
                if ($namesClass && $this->session->sourceStyleResolutionState()->selectorMatchCache->matches($node, $candidate['selector'], $candidate['parsed'])['matches']) return true;
            }
        }
        return false;
    }
}
