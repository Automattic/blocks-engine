<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

/** Navigation-only evidence and policy dependencies. */
final class NavigationPatternContext
{
    private readonly ?Closure $runtimeDomTarget;
    private readonly Closure $underlineColor;
    private readonly Closure $resolvedStyle;
    private readonly ?Closure $colorInteractionStates;
    private readonly ?Closure $overlayMenu;
    private readonly ?Closure $responsiveToggleMarker;
    private readonly ?Closure $linkIconMarker;

    /**
     * @param callable(DOMElement): bool|null $runtimeDomTarget
     * @param callable(DOMElement, DOMElement): string $underlineColor
     * @param callable(DOMElement): string $resolvedStyle
     * @param callable(DOMElement): list<string>|null $colorInteractionStates
     * @param callable(DOMElement): string|null $overlayMenu
     * @param callable(DOMElement): string|null $responsiveToggleMarker
     * @param callable(DOMElement): string|null $linkIconMarker
     */
    public function __construct(
        ?callable $runtimeDomTarget,
        callable $underlineColor,
        callable $resolvedStyle,
        ?callable $colorInteractionStates = null,
        ?callable $overlayMenu = null,
        ?callable $responsiveToggleMarker = null,
        ?callable $linkIconMarker = null
    ) {
        $this->linkIconMarker         = null === $linkIconMarker ? null : Closure::fromCallable($linkIconMarker);
        $this->runtimeDomTarget       = null === $runtimeDomTarget ? null : Closure::fromCallable($runtimeDomTarget);
        $this->underlineColor         = Closure::fromCallable($underlineColor);
        $this->resolvedStyle          = Closure::fromCallable($resolvedStyle);
        $this->colorInteractionStates = null === $colorInteractionStates ? null : Closure::fromCallable($colorInteractionStates);
        $this->overlayMenu            = null === $overlayMenu ? null : Closure::fromCallable($overlayMenu);
        $this->responsiveToggleMarker = null === $responsiveToggleMarker ? null : Closure::fromCallable($responsiveToggleMarker);
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return null !== $this->runtimeDomTarget && ($this->runtimeDomTarget)($element);
    }

    public function underlineColor(DOMElement $item, DOMElement $anchor): string
    {
        return ($this->underlineColor)($item, $anchor);
    }

    public function resolvedStyle(DOMElement $element): string
    {
        return ($this->resolvedStyle)($element);
    }

    /** @return list<string> */
    public function colorInteractionStates(DOMElement $element): array
    {
        return null === $this->colorInteractionStates ? array() : ($this->colorInteractionStates)($element);
    }

    public function overlayMenu(DOMElement $element): string
    {
        return null === $this->overlayMenu ? 'never' : ($this->overlayMenu)($element);
    }

    public function responsiveToggleMarker(DOMElement $element): string
    {
        return null === $this->responsiveToggleMarker ? '' : ($this->responsiveToggleMarker)($element);
    }

    /**
     * Marker for an icon-only navigation anchor whose artwork core cannot save.
     *
     * core/navigation-link stores only a label and URL, so a source anchor whose
     * visible content is an inline SVG loses that artwork. The owning transformer
     * registers the recovered presentation and returns an opaque marker class.
     */
    public function linkIconMarker(DOMElement $element): string
    {
        return null === $this->linkIconMarker ? '' : ($this->linkIconMarker)($element);
    }
}
