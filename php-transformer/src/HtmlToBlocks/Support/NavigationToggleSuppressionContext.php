<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\NavigationProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see NavigationToggleSuppressor}.
 *
 * Per-transform state (runtime selectors, navigation projection) and the probe
 * pattern context are resolved through closures because the suppressor is constructed once with the
 * transformer but must see the state belonging to the transform currently
 * running.
 */
final class NavigationToggleSuppressionContext
{
    /**
     * @param Closure(DOMElement): bool            $sourceElementStartsHidden
     * @param Closure(): RuntimeSelectorState      $runtimeSelectors
     * @param Closure(): NavigationProjectionState $navigationProjection
     * @param Closure(): PatternRecognizerRegistry $patternRecognizers
     * @param Closure(): PatternContext            $probePatternContext
     */
    public function __construct(
        private readonly Closure $sourceElementStartsHidden,
        private readonly Closure $runtimeSelectors,
        private readonly Closure $navigationProjection,
        private readonly Closure $patternRecognizers,
        private readonly Closure $probePatternContext
    ) {
    }

    public function sourceElementStartsHidden(DOMElement $element): bool
    {
        return ($this->sourceElementStartsHidden)($element);
    }

    public function runtimeSelectors(): RuntimeSelectorState
    {
        return ($this->runtimeSelectors)();
    }

    public function navigationProjection(): NavigationProjectionState
    {
        return ($this->navigationProjection)();
    }

    public function patternRecognizers(): PatternRecognizerRegistry
    {
        return ($this->patternRecognizers)();
    }

    public function probePatternContext(): PatternContext
    {
        return ($this->probePatternContext)();
    }
}
