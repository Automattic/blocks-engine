<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\NavigationProjectionState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\RuntimeSelectorState;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see NavigationToggleSuppressor}.
 *
 * Per-transform state is read from the compilation's typed session. Closures
 * remain only for transformer-owned operations.
 */
final class NavigationToggleSuppressionContext
{
    /**
     * @param Closure(DOMElement): bool            $sourceElementStartsHidden
     * @param Closure(): PatternRecognizerRegistry $patternRecognizers
     * @param Closure(): PatternContext            $probePatternContext
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $sourceElementStartsHidden,
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
        return $this->session->runtimeSelectorState();
    }

    public function navigationProjection(): NavigationProjectionState
    {
        return $this->session->navigationProjectionState();
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
