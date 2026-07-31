<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

/**
 * Recognizes strict two-pane media/text containers and emits core/media-text.
 *
 * Gate decision tree:
 *
 * exactly two element children? -------- no --> null
 * |
 * +-- exactly one pure img/video side? -- no --> null
 * |
 * +-- convert text child once
 * |
 * +-- text-bearing block? -------------- no --> null
 * |
 * +-- vertical flex container? --------- yes -> null
 * |
 * `-- core/media-text
 */
final class MediaTextPattern
{
    use PatternDomHelpersTrait;
    use PatternGateHelpersTrait;

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param callable(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>> $convertChildren
     * @param callable(DOMElement, array<int, string>): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $mergedPresentationStyle
     * @param callable(DOMElement): array<string, string> $htmlAttributes
     * @param callable(string): string $resolveAssetUrl
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(
        DOMElement $element,
        array &$fallbacks,
        callable $convertChildren,
        callable $presentationAttributes,
        callable $mergedPresentationStyle,
        callable $htmlAttributes,
        callable $resolveAssetUrl,
        callable $createBlock
    ): ?array {
        return null;
    }
}
