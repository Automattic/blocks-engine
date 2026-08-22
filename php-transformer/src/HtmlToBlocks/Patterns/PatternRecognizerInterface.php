<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

interface PatternRecognizerInterface
{
    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult;
}
