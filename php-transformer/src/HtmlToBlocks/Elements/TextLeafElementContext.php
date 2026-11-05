<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see TextLeafElementConverter}.
 *
 * Mirrors {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext}:
 * the transformer hands over exactly the operations a converter needs, so the
 * converter has no `$this` access to transformer state and its inputs are
 * enumerable at the call site.
 */
final class TextLeafElementContext
{
    /**
     * @param Closure(DOMElement, DOMElement): array<string, mixed>                            $codePresentationAttributes
     * @param Closure(DOMElement): string                                                      $codeContent
     * @param Closure(DOMElement, array<int, array<string, mixed>>, bool): array<int, array<string, mixed>> $convertChildren
     */
    public function __construct(
        private readonly Closure $codePresentationAttributes,
        private readonly Closure $codeContent,
        private readonly Closure $convertChildren
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function codePresentationAttributes(DOMElement $pre, DOMElement $code): array
    {
        return ($this->codePresentationAttributes)($pre, $code);
    }

    public function codeContent(DOMElement $code): string
    {
        return ($this->codeContent)($code);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    public function convertChildren(DOMElement $element, array &$fallbacks, bool $captureUnsupported = false): array
    {
        return ($this->convertChildren)($element, $fallbacks, $captureUnsupported);
    }
}
