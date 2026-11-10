<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Closure;
use DOMElement;

/** Pattern tree whose pattern recognition and child conversion are test closures; unset ones yield nothing. */
final class PatternTreeFixture implements PatternTreeConverter
{
    /**
     * @param (Closure(DOMElement, array<int, array<string, mixed>>&, array<int, class-string>): ?array<string, mixed>)|null $patterns
     * @param (Closure(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>>)|null $children
     */
    public function __construct(private readonly ?Closure $patterns = null, private readonly ?Closure $children = null) {}

    public function children(DOMElement $element, array &$fallbacks, bool $captureUnsupported, bool $preserveLinkContainers = false): array
    {
        return null === $this->children ? array() : ($this->children)($element, $fallbacks, $captureUnsupported);
    }

    public function element(DOMElement $element, array &$fallbacks, bool $captureUnsupported): ?array
    {
        return null;
    }

    public function childrenWithoutTags(DOMElement $element, array &$fallbacks, array $excludedTags): array
    {
        return array();
    }

    public function patterns(DOMElement $element, array &$fallbacks, array $allowed): ?array
    {
        return null === $this->patterns ? null : ($this->patterns)($element, $fallbacks, $allowed);
    }
}
