<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

/** Adapts established semantic matchers to the ordered recognizer registry. */
final class CallbackPatternRecognizer implements PatternRecognizerInterface
{
    /** @param callable(DOMElement, PatternContext): PatternRecognitionResult|null $recognize */
    public function __construct(private readonly string $name, private readonly mixed $recognize)
    {
    }

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        return ($this->recognize)($element, $context);
    }

    public function name(): string
    {
        return $this->name;
    }
}
