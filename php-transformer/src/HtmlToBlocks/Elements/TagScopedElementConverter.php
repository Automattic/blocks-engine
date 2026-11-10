<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Offers only the listed tags to a converter that also handles other tags elsewhere in dispatch. */
final class TagScopedElementConverter implements ElementConverter
{
    /** @param list<string> $tags */
    public function __construct(private readonly array $tags, private readonly ElementConverter $converter)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        return in_array($tagName, $this->tags, true)
            ? $this->converter->convert($element, $tagName, $fallbacks, $captureUnsupported)
            : ConversionOutcome::unhandled();
    }
}
