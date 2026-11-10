<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Closure;
use DOMElement;

/** Pattern tree whose pattern recognition is a test closure; subtree conversion yields nothing. */
final class PatternTreeFixture implements PatternTreeConverter
{
    /** @param Closure(DOMElement, array<int, array<string, mixed>>&, array<int, class-string>): ?array<string, mixed> $patterns */
    public function __construct(private readonly Closure $patterns) {}

    public function children(DOMElement $element, array &$fallbacks, bool $captureUnsupported, bool $preserveLinkContainers = false): array
    {
        return array();
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
        return ($this->patterns)($element, $fallbacks, $allowed);
    }
}
