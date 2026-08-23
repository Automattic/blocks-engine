<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

/** Recursively converts pattern content while keeping its diagnostics transactional. */
final class PatternRecursiveConverter
{
    private readonly Closure $convertChildren;
    private readonly Closure $convertElement;
    private readonly Closure $convertChildrenWithoutTags;

    /**
     * @param callable(DOMElement, bool): PatternConversionResult $convertChildren
     * @param callable(DOMElement, bool): PatternConversionResult $convertElement
     * @param callable(DOMElement, list<string>): PatternConversionResult $convertChildrenWithoutTags
     */
    public function __construct(callable $convertChildren, callable $convertElement, callable $convertChildrenWithoutTags)
    {
        $this->convertChildren            = Closure::fromCallable($convertChildren);
        $this->convertElement             = Closure::fromCallable($convertElement);
        $this->convertChildrenWithoutTags = Closure::fromCallable($convertChildrenWithoutTags);
    }

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @return list<array<string, mixed>>
     */
    public function children(DOMElement $element, array &$fallbacks, bool $captureUnsupported): array
    {
        $result    = ($this->convertChildren)($element, $captureUnsupported);
        $fallbacks = array_merge($fallbacks, $result->fallbacks());

        return $result->blocks();
    }

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function element(DOMElement $element, array &$fallbacks, bool $captureUnsupported): ?array
    {
        $result    = ($this->convertElement)($element, $captureUnsupported);
        $fallbacks = array_merge($fallbacks, $result->fallbacks());

        return $result->firstBlock();
    }

    /**
     * @param list<array<string, mixed>> $fallbacks
     * @param list<string> $excludedTags
     * @return list<array<string, mixed>>
     */
    public function childrenWithoutTags(DOMElement $element, array &$fallbacks, array $excludedTags): array
    {
        $result    = ($this->convertChildrenWithoutTags)($element, $excludedTags);
        $fallbacks = array_merge($fallbacks, $result->fallbacks());

        return $result->blocks();
    }
}
