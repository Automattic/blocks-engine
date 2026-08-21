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

    /**
     * @return PatternResult|null
     */
    public function firstMatch(DOMElement $element, PatternContext $context): ?PatternResult
    {
        foreach ( $this->recognizers as $recognizer ) {
            $scope = $context->matchScope();
            try {
                $block = $recognizer->match($element, $scope);
            } catch ( \Throwable $exception ) {
                $scope->discardMutations();
                throw $exception;
            }
            if ( null !== $block ) {
                $scope->commitMutations();
                return new PatternResult($block, array(), $scope->fallbacks(), array($element->getNodePath() ?: ''));
            }
            $scope->discardMutations();
        }

        return null;
    }
}
