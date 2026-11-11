<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

abstract class SourceBlockCreator
{
    protected ?HtmlTransformerSession $sourceBlockSession = null;

    public function __construct(?HtmlTransformerSession $session = null)
    {
        $this->sourceBlockSession = $session;
    }

    /** @return array<string, mixed> */
    public function htmlPreservationBlock(DOMElement $element): array
    {
        return $this->createBlock('core/html', array( 'content' => SourceDom::safeFallbackHtml($element, $this->sourceBlockSession?->authorSelectorProjectionState()->tagMarkers() ?? array()) ), array(), $element);
    }

    /** @return array<string, mixed>|null */
    public function percentageSizedFormButton(DOMElement $button): ?array
    {
        return null;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    abstract public function createBlock(string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array;
}
