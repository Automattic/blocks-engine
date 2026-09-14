<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

final class ButtonAnchorPattern implements PatternRecognizerInterface
{
    public function __construct(private readonly ButtonsPattern $buttons) {}

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        if ( $this->buttons->requiresWrappedButtonPreservation($element) ) {
            return new PatternRecognitionResult($context->createBlock('core/html', array( 'content' => SourceDom::outerHtml($element) ), array(), $element));
        }

        $buttonContext = $context->buttonContext();
        return null === $buttonContext ? null : $this->buttons->matchAnchor($element, $context, $buttonContext);
    }
}
