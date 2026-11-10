<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceElementVisibility;
use DOMElement;

/**
 * Explicit collaborator surface for {@see NavigationToggleSuppressor}.

 */
final class NavigationToggleSuppressionContext
{
    public function __construct(
        private readonly SourceElementVisibility $sourceVisibility,
        private readonly PatternRecognizerRegistry $patternRecognizers,
        private readonly PatternContext $probePatternContext
    ) {
    }

    public function sourceElementStartsHidden(DOMElement $element): bool
    {
        return $this->sourceVisibility->startsHidden($element);
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
        return $this->sourceVisibility->orAncestorStartsHidden($element);
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
