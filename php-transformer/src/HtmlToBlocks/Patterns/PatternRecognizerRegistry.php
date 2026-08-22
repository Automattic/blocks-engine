<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class PatternRecognizerRegistry
{
    /**
     * @param array<int, PatternRecognizerInterface> $recognizers
     */
    public function __construct(private readonly array $recognizers)
    {
    }

    public function firstMatch(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        foreach ( $this->recognizers as $recognizer ) {
            $result = $recognizer->recognize($element, $context);
            if ( null !== $result ) {
                return $result;
            }
        }

        return null;
    }
}
