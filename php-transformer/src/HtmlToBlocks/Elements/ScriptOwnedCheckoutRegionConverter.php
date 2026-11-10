<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use DOMElement;

/**
 * A checkout view region the commerce recognizer claims is script-owned
 * commerce rendering the engine cannot convert. Preserve its source markup
 * whole so the checkout view binding anchors on the block a shop provider
 * replaces.
 */
final class ScriptOwnedCheckoutRegionConverter implements ElementConverter
{
    public function __construct(
        private readonly CommerceStructureRecognizer $commercePattern,
        private readonly SourceBlockCreator $createBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( ! $this->isScriptOwnedCheckoutRegion($element) ) {
            return ConversionOutcome::unhandled();
        }

        $block = $this->createBlock->htmlPreservationBlock($element);
        $block['_editability_runtime_owned'] = true;

        return ConversionOutcome::handled($block);
    }

    /**
     * Whether `$element` is the script-owned region a checkout view binding
     * anchors on: a custom element (script-defined by name) holding the
     * checkout view's customer, address, payment and summary roles, and
     * passing the same preservation safety bar as a proven custom element.
     * Native checkout regions (a `form` or `div` root) convert natively and
     * never reach the custom-element disposition, so they need no preservation.
     */
    private function isScriptOwnedCheckoutRegion(DOMElement $element): bool
    {
        if ( ! CustomElementRuntimeDependency::isCustomElementTag(strtolower($element->tagName)) || ! ( new CustomElementRuntimeDependency() )->isSafe($element) ) {
            return false;
        }

        return $this->commercePattern->isCheckoutRegion($element);
    }
}
