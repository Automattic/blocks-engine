<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see DetailsElementConverter}. */
final class DetailsElementContext
{
    public function __construct(
        private readonly Closure $capturedDisclosureDialog,
        private readonly Closure $capturedDialogBlock,
        private readonly PatternTreeConverter $tree
    ) {
    }

    public function capturedDisclosureDialog(DOMElement $element): ?DOMElement
    {
        return ($this->capturedDisclosureDialog)($element);
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed> */
    public function capturedDialogBlock(DOMElement $element, array &$fallbacks): array
    {
        return ($this->capturedDialogBlock)($element, $fallbacks);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, class-string> $patterns
     * @return array<string, mixed>|null
     */
    public function recognizePatterns(DOMElement $element, array &$fallbacks, array $patterns): ?array
    {
        return $this->tree->patterns($element, $fallbacks, $patterns);
    }
}
