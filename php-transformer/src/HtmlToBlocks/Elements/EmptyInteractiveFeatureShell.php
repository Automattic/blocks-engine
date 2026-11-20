<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EmptyElementFootprint;
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
        private readonly WrapperCoalescer $wrapperCoalescer,
        private readonly EmptyElementFootprint $emptyElementFootprint
    ) {
    }

    /**
     * Empty search and cart shells are dead platform chrome, not authored layout.
     * Content, controls, media, links, and runtime bindings keep their existing
     * native or capability-owned conversion path. A shell whose cascaded
     * declarations reserve a layout slot (a sized table cell or flex item) is
     * authored layout whatever its name: dropping it hands its share of the
     * row to its siblings.
     */
    public function isEmptyInteractiveFeatureShell(DOMElement $element): bool
    {
        return $this->disposition($element)->isDrop();
    }

    /**
     * Whether, and why, the shell is dropped: {@see WrapperDecision::emptyInteractiveFeatureShell()}
     * owns the guard order (#7471).
     */
    public function disposition(DOMElement $element): WrapperDisposition
    {
        return WrapperDecision::emptyInteractiveFeatureShell($this, $element);
    }

    /** The class, id or role names a search or cart feature. */
    public function namesSearchOrCartFeature(DOMElement $element): bool
    {
        $identity = strtolower(trim(SourceDom::attr($element, 'class') . ' ' . SourceDom::attr($element, 'id') . ' ' . SourceDom::attr($element, 'role')));
        return 1 === preg_match('/(?:^|[^a-z0-9])(?:search|cart)(?:[^a-z0-9]|$)/', $identity);
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return $this->runtimeIslands->isRuntimeDomTarget($element);
    }

    public function isDirectChildOfStructuralLayout(DOMElement $element): bool
    {
        return $this->wrapperCoalescer->isDirectChildOfStructuralLayout($element);
    }

    public function reservesLayoutSlot(DOMElement $element): bool
    {
        return $this->emptyElementFootprint->reservesLayoutSlot($element);
    }

    /** Content, controls, media and links keep their own conversion path. */
    public function hasContentOrControlDescendant(DOMElement $element): bool
    {
        foreach ( array( 'a', 'audio', 'button', 'canvas', 'iframe', 'img', 'input', 'object', 'picture', 'select', 'svg', 'textarea', 'video' ) as $tagName ) {
            if ( 0 < $element->getElementsByTagName($tagName)->length ) {
                return true;
            }
        }

        return false;
    }

    public function hasRuntimeTargetDescendant(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && $this->runtimeIslands->isRuntimeDomTarget($descendant) ) {
                return true;
            }
        }

        return false;
    }

    public function hasAuthorInlineAlignment(DOMElement $element): bool
    {
        $declarations = $this->wrapperCoalescer->matchingAuthorDeclarations($element);
        $display = CssValueInspector::declared($declarations, 'display');
        $verticalAlign = strtolower(trim((string) ($declarations['vertical-align'] ?? '')));
        return in_array($display, array( 'inline', 'inline-block', 'inline-flex', 'inline-grid', 'inline-table' ), true)
            && ! in_array($verticalAlign, array( '', 'baseline', 'inherit', 'initial', 'revert', 'revert-layer', 'unset' ), true);
    }
}
