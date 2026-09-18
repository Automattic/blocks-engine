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
     * @param Closure(DOMElement): bool $sourceElementStartsHidden
     * @param Closure(DOMElement): bool $sourceElementOrAncestorStartsHidden
     */
    public function __construct(
        private readonly HtmlTransformerSession $session,
        private readonly Closure $sourceElementStartsHidden,
        private readonly Closure $sourceElementOrAncestorStartsHidden,
        private readonly PatternRecognizerRegistry $patternRecognizers,
        private readonly PatternContext $probePatternContext
    ) {
    }

    public function sourceElementStartsHidden(DOMElement $element): bool
    {
        return ($this->sourceElementStartsHidden)($element);
    }

    /**
     * Whether the element (or an ancestor) is hidden once its authored
     * cascade — inline, static, AND media/feature-conditional rules that
     * apply at the desktop reference viewport — is fully resolved. Unlike
     * {@see self::sourceElementStartsHidden()}, a `hidden md:flex` element
     * correctly reads as visible here, because the conditional `md:flex`
     * rule wins at the reference viewport.
     */
    public function sourceElementOrAncestorStartsHidden(DOMElement $element): bool
    {
        return ($this->sourceElementOrAncestorStartsHidden)($element);
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
        return $this->patternRecognizers;
    }

    public function probePatternContext(): PatternContext
    {
        return $this->probePatternContext;
    }
}
