<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see QuoteElementConverter}. */
final class QuoteElementContext
{
    public function __construct(private readonly Closure $recognizeQuote)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function recognizeQuote(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->recognizeQuote)($element, $fallbacks);
    }
}
