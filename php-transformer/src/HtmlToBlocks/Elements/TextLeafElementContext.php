<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CodePresentation;
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
     */
    public function __construct(
        private readonly CodePresentation $codePresentation,
        private readonly PatternTreeConverter $tree
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function codePresentationAttributes(DOMElement $pre, DOMElement $code): array
    {
        return $this->codePresentation->attributes($pre, $code);
    }

    public function codeContent(DOMElement $code): string
    {
        return $this->codePresentation->content($code);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    public function convertChildren(DOMElement $element, array &$fallbacks, bool $captureUnsupported = false): array
    {
        return $this->tree->children($element, $fallbacks, $captureUnsupported);
    }
}
