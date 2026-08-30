<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see PatternElementConverter}. */
final class PatternElementContext
{
    public function __construct(private readonly Closure $recognizePatterns)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, class-string> $patterns
     * @return array<string, mixed>|null
     */
    public function recognizePatterns(DOMElement $element, array &$fallbacks, array $patterns): ?array
    {
        return ($this->recognizePatterns)($element, $fallbacks, $patterns);
    }
}
