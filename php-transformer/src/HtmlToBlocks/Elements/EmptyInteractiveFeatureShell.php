<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Recognizes an empty search or cart shell: dead platform chrome rather than
 * authored layout.
 */
final class EmptyInteractiveFeatureShell
{
    public function __construct(
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly WrapperCoalescer $wrapperCoalescer
    ) {
    }

    /**
     * Empty search and cart shells are dead platform chrome, not authored layout.
     * Content, controls, media, links, and runtime bindings keep their existing
     * native or capability-owned conversion path.
     */
    public function isEmptyInteractiveFeatureShell(DOMElement $element): bool
    {
        $identity = strtolower(trim(SourceDom::attr($element, 'class') . ' ' . SourceDom::attr($element, 'id') . ' ' . SourceDom::attr($element, 'role')));
        if ( ! preg_match('/(?:^|[^a-z0-9])(?:search|cart)(?:[^a-z0-9]|$)/', $identity)
            || '' !== SourceDom::renderedTextContent($element)
            || $this->runtimeIslands->isRuntimeDomTarget($element)
            || $this->wrapperCoalescer->isDirectChildOfStructuralLayout($element)
            || $this->hasAuthorInlineAlignment($element)
        ) {
            return false;
        }

        foreach ( array( 'a', 'audio', 'button', 'canvas', 'iframe', 'img', 'input', 'object', 'picture', 'select', 'svg', 'textarea', 'video' ) as $tagName ) {
            if ( 0 < $element->getElementsByTagName($tagName)->length ) {
                return false;
            }
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && $this->runtimeIslands->isRuntimeDomTarget($descendant) ) {
                return false;
            }
        }

        return true;
    }

    private function hasAuthorInlineAlignment(DOMElement $element): bool
    {
        $declarations = $this->wrapperCoalescer->matchingAuthorDeclarations($element);
        $display = CssValueInspector::declared($declarations, 'display');
        $verticalAlign = strtolower(trim((string) ($declarations['vertical-align'] ?? '')));
        return in_array($display, array( 'inline', 'inline-block', 'inline-flex', 'inline-grid', 'inline-table' ), true)
            && ! in_array($verticalAlign, array( '', 'baseline', 'inherit', 'initial', 'revert', 'revert-layer', 'unset' ), true);
    }
}
