<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see ButtonElementConverter}. */
final class ButtonElementContext
{
    public function __construct(
        private readonly Closure $isReplacedSearchClusterControl,
        private readonly PatternTreeConverter $tree,
        private readonly Closure $convertButton,
        private readonly ?Closure $isRuntimeDomTarget = null,
        private readonly ?Closure $runtimeButton = null,
        private readonly ?Closure $convertButtonWrapperGroup = null,
        private readonly ?Closure $imageMinimumPixelSize = null
    ) {
    }

    public function isReplacedSearchClusterControl(DOMElement $element): bool
    {
        return ($this->isReplacedSearchClusterControl)($element);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function convertButtonWrapperGroup(DOMElement $element, array &$fallbacks): ?array
    {
        return $this->convertButtonWrapperGroup instanceof Closure ? ($this->convertButtonWrapperGroup)($element, $fallbacks) : null;
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<int, array<string, mixed>> */
    public function convertChildren(DOMElement $element, array &$fallbacks, bool $captureUnsupported): array
    {
        return $this->tree->children($element, $fallbacks, $captureUnsupported);
    }

    /** @return array<string, mixed>|null */
    public function convertButton(DOMElement $element): ?array
    {
        return ($this->convertButton)($element);
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return $this->isRuntimeDomTarget instanceof Closure && ($this->isRuntimeDomTarget)($element);
    }

    /** @return array<string, mixed>|null */
    public function runtimeButton(DOMElement $element): ?array
    {
        return $this->runtimeButton instanceof Closure ? ($this->runtimeButton)($element) : null;
    }

    /**
     * The image's guaranteed minimum used size in CSS pixels per bounded axis,
     * or null when the transformer supplies no style resolution.
     *
     * @return array{width: ?float, height: ?float}|null
     */
    public function imageMinimumPixelSize(DOMElement $image): ?array
    {
        return $this->imageMinimumPixelSize instanceof Closure ? ($this->imageMinimumPixelSize)($image) : null;
    }
}
