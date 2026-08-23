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

    /**
     * @param callable(DOMElement): bool|null $runtimeDomTarget
     * @param callable(DOMElement, DOMElement): string $underlineColor
     * @param callable(DOMElement): string $resolvedStyle
     * @param callable(DOMElement): list<string>|null $colorInteractionStates
     * @param callable(DOMElement): string|null $overlayMenu
     */
    public function __construct(
        ?callable $runtimeDomTarget,
        callable $underlineColor,
        callable $resolvedStyle,
        ?callable $colorInteractionStates = null,
        ?callable $overlayMenu = null
    ) {
        $this->runtimeDomTarget       = null === $runtimeDomTarget ? null : Closure::fromCallable($runtimeDomTarget);
        $this->underlineColor         = Closure::fromCallable($underlineColor);
        $this->resolvedStyle          = Closure::fromCallable($resolvedStyle);
        $this->colorInteractionStates = null === $colorInteractionStates ? null : Closure::fromCallable($colorInteractionStates);
        $this->overlayMenu            = null === $overlayMenu ? null : Closure::fromCallable($overlayMenu);
    }

    public function runtimeDomTargetCallback(): ?Closure { return $this->runtimeDomTarget; }
    public function underlineColorCallback(): Closure { return $this->underlineColor; }
    public function resolvedStyleCallback(): Closure { return $this->resolvedStyle; }
    public function colorInteractionStatesCallback(): ?Closure { return $this->colorInteractionStates; }
    public function overlayMenuCallback(): ?Closure { return $this->overlayMenu; }
}
