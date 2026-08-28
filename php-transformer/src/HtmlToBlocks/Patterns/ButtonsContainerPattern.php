<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class ButtonsContainerPattern implements PatternRecognizerInterface
{
    public function __construct(private readonly ButtonsPattern $buttons) {}

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $buttonContext = $context->buttonContext();
        if ( null === $buttonContext ) return null;
        $block = $this->buttons->matchContainer($element, $context, $buttonContext);
        return null === $block ? null : new PatternRecognitionResult($block);
    }
}
