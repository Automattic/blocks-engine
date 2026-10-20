<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;
use WeakMap;

/** Per-transform memoized presentation results. */
final class PresentationResolutionCache
{
    /** @var WeakMap<DOMElement, string> */
    private WeakMap $elementKeys;

    /** @var array<string, array<string, mixed>> */
    public array $attributes = array();

    /** @var array<string, array<string, string>> */
    public array $declarations = array();

    /** @var array<string, string> */
    public array $mergedStyles = array();

    /** @var array<string, string> */
    public array $mediaTextStyles = array();

    public function __construct()
    {
        $this->elementKeys = new WeakMap();
    }

    private int $nextElementKey = 0;

    /**
     * PHP reuses spl_object_id() as soon as a DOM wrapper is released, so an
     * id-based key handed a temporary clone at the same path (a captured
     * state swapped into the source tree) the results of the node it
     * replaced. Each wrapper gets a token that is never reissued.
     */
    public function elementKey(DOMElement $element): string
    {
        return $this->elementKeys[$element]
            ??= ++$this->nextElementKey . ':' . $element->getNodePath();
    }
}
