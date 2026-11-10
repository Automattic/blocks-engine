<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Common contract for ordered element conversion strategies. */
interface ElementConverter
{
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param bool $captureUnsupported Whether an element no converter can lower
     *        is recorded as an unsupported-element fallback rather than dropped.
     */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome;
}
