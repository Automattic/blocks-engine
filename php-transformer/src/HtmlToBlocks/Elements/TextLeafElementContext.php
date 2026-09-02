<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
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
     * @param Closure(DOMElement, array<int, string>): string                                  $richTextContent
     * @param Closure(DOMElement, DOMElement): array<string, mixed>                            $codePresentationAttributes
     * @param Closure(DOMElement): string                                                      $codeContent
     * @param Closure(DOMElement, array<int, array<string, mixed>>, bool): array<int, array<string, mixed>> $convertChildren
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly ElementPresentationResolver $presentationResolver,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $richTextContent,
        private readonly Runtime $runtime,
        private readonly Closure $codePresentationAttributes,
        private readonly Closure $codeContent,
        private readonly Closure $convertChildren
    ) {
    }

    /**
     * @param array<int, string> $excludedProperties
     * @param array<int, string> $excludedGeometryProperties
     * @return array<string, mixed>
     */
    public function presentationAttributes(DOMElement $element, array $excludedProperties = array(), array $excludedGeometryProperties = array()): array
    {
        return $this->presentationResolver->presentationAttributes($element, $excludedProperties, $excludedGeometryProperties);
    }

    /**
     * @param array<string, mixed>                  $attributes
     * @param array<int, array<string, mixed>>      $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attributes = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array
    {
        return $this->createBlock->createBlock($name, $attributes, $innerBlocks, $sourceElement);
    }

    /**
     * @param array<int, string> $excludedTags
     */
    public function richTextContent(DOMElement $element, array $excludedTags = array()): string
    {
        return ($this->richTextContent)($element, $excludedTags);
    }

    public function stripAllTags(string $html): string
    {
        return $this->runtime->stripAllTags($html);
    }

    public function escapeHtml(string $text): string
    {
        return $this->runtime->escapeHtml($text);
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

    public function hasBlockContentChildren(DOMElement $element): bool
    {
        return $this->sourceElementClassifier->hasBlockContentChildren($element);
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
