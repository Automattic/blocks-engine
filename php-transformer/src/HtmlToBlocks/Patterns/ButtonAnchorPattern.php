<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class ButtonAnchorPattern implements PatternRecognizerInterface
{
    public function __construct(private readonly ButtonsPattern $buttons) {}

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $buttonContext = $context->buttonContext();
        return null === $buttonContext ? null : $this->buttons->matchAnchor($element, $context, $buttonContext);
    }
}
