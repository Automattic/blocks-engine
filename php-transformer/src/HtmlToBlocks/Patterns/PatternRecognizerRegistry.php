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

    /** @param list<class-string<PatternRecognizerInterface>|string> $allowed */
    public function firstMatch(DOMElement $element, PatternContext $context, array $allowed = array()): ?PatternRecognitionResult
    {
        foreach ( $this->recognizers as $recognizer ) {
            $name = $recognizer instanceof CallbackPatternRecognizer ? $recognizer->name() : $recognizer::class;
            if ( array() !== $allowed && ! in_array($name, $allowed, true) ) {
                continue;
            }
            $result = $recognizer->recognize($element, $context);
            if ( null !== $result ) {
                return $result;
            }
        }

        return null;
    }
}
