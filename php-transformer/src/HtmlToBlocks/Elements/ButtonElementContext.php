<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see ButtonElementConverter}. */
final class ButtonElementContext
{
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly Closure $isReplacedSearchClusterControl,
        private readonly Closure $convertChildren,
        private readonly ElementPresentationResolver $presentationResolver,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $convertButton
    ) {
    }

    public function isReplacedSearchClusterControl(DOMElement $element): bool
    {
        return ($this->isReplacedSearchClusterControl)($element);
    }

    public function isImageCarrierButton(DOMElement $element): bool
    {
        return $this->sourceElementClassifier->isImageCarrierButton($element);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<int, array<string, mixed>> */
    public function convertChildren(DOMElement $element, array &$fallbacks, bool $captureUnsupported): array
    {
        return ($this->convertChildren)($element, $fallbacks, $captureUnsupported);
    }

    /** @return array<string, mixed> */
    public function presentationAttributes(DOMElement $element): array
    {
        return $this->presentationResolver->presentationAttributes($element);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attributes, array $innerBlocks, DOMElement $element): array
    {
        return $this->createBlock->createBlock($name, $attributes, $innerBlocks, $element);
    }

    /** @return array<string, mixed>|null */
    public function convertButton(DOMElement $element): ?array
    {
        return ($this->convertButton)($element);
    }
}
