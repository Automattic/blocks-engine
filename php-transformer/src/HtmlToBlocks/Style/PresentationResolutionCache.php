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

    public function elementKey(DOMElement $element): string
    {
        return $this->elementKeys[$element]
            ??= spl_object_id($element) . ':' . $element->getNodePath();
    }
}
