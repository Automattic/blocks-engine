<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Runs element converters in declaration order and stops at the first handled outcome. */
final class OrderedElementConverterRegistry implements ElementConverter
{
    /** @param list<ElementConverter> $converters */
    public function __construct(private readonly array $converters)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        foreach ( $this->converters as $converter ) {
            $outcome = $converter->convert($element, $tagName, $fallbacks);
            if ( $outcome->handled ) {
                return $outcome;
            }
        }

        return ConversionOutcome::unhandled();
    }
}
