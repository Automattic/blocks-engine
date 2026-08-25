<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class ButtonPattern implements PatternRecognizerInterface
{
    public function __construct(private readonly ButtonsPattern $buttons) {}

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $buttonContext = $context->buttonContext();
        return null === $buttonContext ? null : new PatternRecognitionResult($this->buttons->matchButton($element, $context, $buttonContext));
    }
}
